<?php
/**
 * Installeur GLPI Agent servi par le plugin (module Collecte SNMP / Déploiement Agent).
 * Lecture : droit Déploiement ; récupération, vérification et réglages : droit de configuration.
 */

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_deploiement', READ);

$page = PluginPrintgestionAgentdeploy::getPageURL();

if (isset($_POST['create_tag_rule']) || isset($_POST['activate_tag_rule'])) {
    // Règle générique d'affectation par TAG : administrateur (droits vérifiés par createTagRule() et
    // activateTagRule()), clic explicite.
    $result = isset($_POST['activate_tag_rule'])
        ? PluginPrintgestionAgentdeploy::activateTagRule()
        : PluginPrintgestionAgentdeploy::createTagRule();
    Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : WARNING);
    $entities_id = (int) ($_POST['entities_id'] ?? -1);
    Html::redirect($entities_id >= 0
        ? Entity::getFormURLWithID($entities_id) . '&forcetab=' . urlencode('PluginPrintgestionAgentdeploy$1')
        : $page);
}

if (isset($_POST['create_tag'])) {
    // « Créer le TAG » de l'onglet de l'entité : droit Déploiement (ci-dessus) et droit natif de modifier l'entité
    // (vérifié par createTag()). Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    $entities_id = (int) ($_POST['entities_id'] ?? 0);
    $result      = PluginPrintgestionAgentdeploy::createTag($entities_id, (string) ($_POST['tag'] ?? ''));
    Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
    Html::redirect(Entity::getFormURLWithID($entities_id) . '&forcetab=' . urlencode('PluginPrintgestionAgentdeploy$1'));
}

// Statut GLPI des PC sondes : réglage global, droit de configuration du plugin comme les autres de cette page.
if (isset($_POST['save_probe_state'])) {
    Session::checkRight('plugin_printgestion_config', UPDATE);
    $errors = PluginPrintgestionAgentsetting::saveProbeState($_POST);
    Session::addMessageAfterRedirect(
        empty($errors)
            ? __('Statut des PC sondes enregistré.', 'printgestion')
            : implode('<br>', array_map(static fn(string $error) => htmlspecialchars($error, ENT_QUOTES, 'UTF-8'), $errors)),
        false,
        empty($errors) ? INFO : ERROR
    );
    Html::redirect($page);
}

// Compatibilité de GLPI Agent avec les installeurs : un bouton par action, droit de configuration du plugin.
if (isset($_POST['compat_check']) || isset($_POST['compat_serve'])) {
    Session::checkRight('plugin_printgestion_config', UPDATE);
    if (isset($_POST['compat_check'])) {
        $version = trim((string) $_POST['compat_check']);
        if (PluginPrintgestionAgentsetting::isValidVersion($version)) {
            $_SESSION[PluginPrintgestionAgentcompat::SESSION_CANDIDATE] = $version;
            $report = PluginPrintgestionAgentcompat::check($version, true);
            Session::addMessageAfterRedirect(
                htmlspecialchars(PluginPrintgestionAgentcompat::summary($report), ENT_QUOTES, 'UTF-8'),
                false,
                match ($report['verdict']) {
                    PluginPrintgestionAgentcompat::VERDICT_OK      => INFO,
                    PluginPrintgestionAgentcompat::VERDICT_WARNING => WARNING,
                    default                                        => ERROR,
                }
            );
        } else {
            Session::addMessageAfterRedirect(__('Version invalide (exemple : 1.20).', 'printgestion'), false, ERROR);
        }
    } else {
        $result = PluginPrintgestionAgentcompat::serveAnyway((string) $_POST['compat_serve']);
        Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? WARNING : ERROR);
    }
    Html::redirect($page);
}

if (isset($_POST['fetch_github']) || isset($_POST['verify_deposit']) || isset($_POST['save_settings'])
    || isset($_POST['check_latest'])) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    Session::checkRight('plugin_printgestion_config', UPDATE);

    if (isset($_POST['check_latest'])) {
        $result = PluginPrintgestionAgentsetting::checkLatestFromGitHub();
        Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
        // La dernière version, si elle est nouvelle pour ce serveur : son contrôle de compatibilité, refait.
        $compat = $result['ok'] ? PluginPrintgestionAgentcompat::checkLatest(true) : '';
        if ($compat !== '') {
            Session::addMessageAfterRedirect(htmlspecialchars($compat, ENT_QUOTES, 'UTF-8'), false, INFO);
        }
    } elseif (isset($_POST['save_settings'])) {
        // Une autre version que celle servie, pas encore validée : contrôlée avant d'être servie. Points à vérifier
        // ou fichier absent : rien n'est enregistré, la carte de compatibilité dit pourquoi.
        $wanted = trim((string) ($_POST['agent_version'] ?? ''));
        $wanted = $wanted !== '' ? $wanted : PluginPrintgestionAgentdeploy::DEFAULT_VERSION;
        $gate   = ['allowed' => true, 'message' => '', 'level' => INFO];
        if (PluginPrintgestionAgentsetting::isValidVersion($wanted) && $wanted !== PluginPrintgestionAgentdeploy::getServedVersion()) {
            $gate = PluginPrintgestionAgentcompat::gate($wanted);
        }
        if (!$gate['allowed']) {
            Session::addMessageAfterRedirect(htmlspecialchars($gate['message'], ENT_QUOTES, 'UTF-8'), false, $gate['level']);
            Html::redirect($page);
        }
        $errors = PluginPrintgestionAgentdeploy::saveSettings($_POST);
        if (empty($errors) && $gate['message'] !== '') {
            Session::addMessageAfterRedirect(htmlspecialchars($gate['message'], ENT_QUOTES, 'UTF-8'), false, INFO);
        }
        if (empty($errors)) {
            Session::addMessageAfterRedirect(__('Paramètres de l\'installeur enregistrés.', 'printgestion'), false, INFO);
        } else {
            Session::addMessageAfterRedirect(implode('<br>', array_map(
                static fn(string $error) => htmlspecialchars($error, ENT_QUOTES, 'UTF-8'),
                $errors
            )), false, ERROR);
        }
    } else {
        // Fichier officiel visé : valeur du bouton « Récupérer » ou liste du dépôt (MSI par défaut).
        $asset  = isset($_POST['fetch_github']) ? (string) $_POST['fetch_github'] : (string) ($_POST['asset'] ?? 'windows');
        $asset  = $asset === '1' ? 'windows' : $asset;
        $result = isset($_POST['fetch_github'])
            ? PluginPrintgestionAgentdeploy::fetchFromGitHub($asset)
            : PluginPrintgestionAgentdeploy::verifyDeposited((string) ($_POST['sha256'] ?? ''), $asset);
        Session::addMessageAfterRedirect(
            htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'),
            false,
            $result['ok'] ? INFO : ERROR
        );
        // Ces deux boutons sont dans le détail « Installeurs servis » : il reste déplié au retour, pour enchaîner.
        $_SESSION[PluginPrintgestionAgentdeploy::SESSION_INSTALLERS_OPEN] = true;
    }
    Html::redirect($page);
}

Html::header(
    __('Installeur GLPI Agent', 'printgestion'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'dp_agent'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('dp_agent');
PluginPrintgestionAgentdeploy::showPage();
echo "</div>";

Html::footer();
