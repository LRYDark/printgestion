<?php
/**
 * Installeur GLPI Agent servi par le plugin (module Collecte SNMP / Déploiement Agent).
 * Lecture : droit Déploiement ; récupération, vérification et réglages : droit de configuration.
 */
include('../../../inc/includes.php');

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

if (isset($_POST['fetch_github']) || isset($_POST['verify_deposit']) || isset($_POST['save_settings'])
    || isset($_POST['check_latest']) || isset($_POST['save_update_defaults'])) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    Session::checkRight('plugin_printgestion_config', UPDATE);

    if (isset($_POST['check_latest'])) {
        $result = PluginPrintgestionAgentsetting::checkLatestFromGitHub();
        Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
    } elseif (isset($_POST['save_update_defaults'])) {
        $errors = PluginPrintgestionAgentsetting::saveDefaults($_POST);
        Session::addMessageAfterRedirect(
            empty($errors)
                ? __('Dernière version et mise à jour automatique des nouveaux paquets enregistrées.', 'printgestion')
                : implode('<br>', array_map(static fn(string $error) => htmlspecialchars($error, ENT_QUOTES, 'UTF-8'), $errors)),
            false,
            empty($errors) ? INFO : ERROR
        );
    } elseif (isset($_POST['save_settings'])) {
        $errors = PluginPrintgestionAgentdeploy::saveSettings($_POST);
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
