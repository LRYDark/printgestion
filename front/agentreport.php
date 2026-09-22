<?php
/**
 * Compte rendu d'installation d'une sonde : le fichier unique dit ici, en dernier geste, ce qu'il a fait sur le PC —
 * tâche de mise à jour posée, ou non.
 *
 * Deuxième et dernière page du plugin joignable sans être connecté (route déclarée dans plugin_printgestion_boot()),
 * pour la même raison que front/agentpull.php : le PC d'un client n'a aucun compte GLPI. La clé est différente de
 * celle du téléchargement, vaut une seule fois et vingt-quatre heures, et n'ouvre rien — elle ne permet que d'écrire
 * cette ligne-là.
 *
 * Ce qu'on accepte d'elle : un nom de PC et des oui/non. Une seule décision peut en sortir : supprimer la sonde de
 * ce PC, après son retrait (« gl=1 »). Elle n'est prise que si celui qui a généré le fichier en avait le droit — noté
 * dans la clé pendant sa session (Agenttoken::createReport()) — et ne touche que la sonde de ce PC dans cette entité.
 */
include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

/** Adresse du demandeur, pour le journal seulement : jamais un critère d'autorisation. */
$from = (string) ($_SERVER['REMOTE_ADDR'] ?? '?');

$token = (string) ($_GET['t'] ?? '');
$entry = $token === '' ? null : PluginPrintgestionAgenttoken::consume($token, PluginPrintgestionAgenttoken::USAGE_REPORT);
if ($entry === null) {
    PluginPrintgestionLogger::warning('agentreport', sprintf('Compte rendu refusé (clé inconnue, expirée ou déjà utilisée) depuis %s.', $from));
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$computer  = (string) ($_GET['pc'] ?? '');
$scheduled = (string) ($_GET['maj'] ?? '') === '1';
// « off=1 » : le fichier de retrait, qui vient de désinstaller l'agent de ce poste.
$removed   = (string) ($_GET['off'] ?? '') === '1';
// « gl=1 » : la case « retirer aussi la sonde de GLPI » était cochée dans la fenêtre de retrait.
$purge_asked = $removed && (string) ($_GET['gl'] ?? '') === '1';

// Adresses des imprimantes et communauté SNMP saisies dans la fenêtre d'installation, sur le PC. Bornées ici : ce
// qui vient d'un poste client n'entre pas sans mesure, même avec une clé valable.
$ips       = substr(trim((string) ($_GET['ips'] ?? '')), 0, 500);
// Fréquence des relevés choisie sur le PC : un code d'une liste fermée, refusé s'il n'en fait pas partie.
$rythme    = PluginPrintgestionCollectfrequency::parseInstallerChoice((string) ($_GET['freq'] ?? ''));
$community = substr(trim((string) ($_GET['snmp'] ?? '')), 0, 255);
if ($community !== '' && preg_match('/^[\\x21-\\x7E]{1,255}$/', $community) !== 1) {
    $community = '';
}

// Deux chemins, et un seul à la fois. Avec GLPI Inventory : c'est GLPI qui pilotera le scan, on crée donc le
// raccordement (plage IP, identifiants SNMP, tâches). Sans lui : personne ne pilotera, c'est le PC qui scannera, et
// on ne tente même pas le raccordement — l'assistant refuse de travailler sans le plugin voisin, et l'essayer
// n'écrirait qu'une erreur dans le journal pour une situation parfaitement normale.
// La fréquence d'abord : les tâches créées juste après naissent alors directement à la bonne cadence, et le seuil
// « cette imprimante ne remonte plus » suit du même coup (Collectfrequency::getSilentDaysForEntity()).
if (!$removed && $rythme !== null) {
    $entity = new Entity();
    if ($entity->getFromDB((int) $entry['entities_id'])) {
        $retour = PluginPrintgestionCollectfrequency::saveForEntity($entity, [
            'frequency' => $rythme['frequency'],
            'modifier'  => $rythme['modifier'],
        ]);
        PluginPrintgestionLogger::info('agentreport', sprintf(
            'Sonde %1$s : fréquence des relevés réglée depuis le PC sur « %2$s » — %3$s',
            $computer,
            $rythme['label'],
            (string) ($retour['message'] ?? '')
        ));
    }
}

// Les modules de la sonde, avant tout le reste et indépendamment des adresses : sans « Découverte réseau » et
// « Inventaire réseau », GLPI Inventory n'enverrait aucune tâche réseau à cette sonde, raccordement ou pas.
$sonde = 0;
if (!$removed && PluginPrintgestionCollectsetup::isAvailable()) {
    $sonde = PluginPrintgestionRaccordement::findAgentByComputer((int) $entry['entities_id'], $computer);
    if ($sonde > 0) {
        $profil = PluginPrintgestionCollectsetup::applyPrinterProbeProfile($sonde);
        PluginPrintgestionLogger::info('agentreport', sprintf(
            'Sonde %1$s (agent %2$d) : modules pour les imprimantes — %3$s',
            $computer,
            $sonde,
            implode(' ', array_column($profil['events'], 1))
        ));
    } else {
        PluginPrintgestionLogger::warning('agentreport', sprintf(
            'Sonde %s : pas encore connue de GLPI au moment du compte rendu, modules non réglés.',
            $computer
        ));
    }
}

$racc = 0;
$scan = false;
// « Le serveur a armé la découverte » : le PC peut alors réveiller son agent lui-même, sans rien attendre.
$relancer = false;
if (!$removed && $ips !== '') {
    if (PluginPrintgestionCollectsetup::isAvailable()) {
        $creation = PluginPrintgestionRaccordement::createFromInstaller((int) $entry['entities_id'], $computer, $ips, $community);
        $racc     = (int) $creation['id'];
        $relancer = !empty($creation['triggered']);
        PluginPrintgestionLogger::info('agentreport', sprintf(
            'Sonde %1$s : adresses déclarées depuis le PC (%2$s) — %3$s',
            $computer,
            $ips,
            implode(' ', $creation['messages'])
        ));
    } else {
        $scan = true;
    }
}

if (!PluginPrintgestionAgentreport::record((int) $entry['entities_id'], $computer, (string) ($entry['platform'] ?? ''), $scheduled, $removed, $ips, $racc, $scan)) {
    // Nom de PC vide ou configuration non écrite : rien à dire au PC, tout est déjà dans le journal du plugin.
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

PluginPrintgestionLogger::info('agentreport', $removed
    ? sprintf(
        'Sonde %1$s (%2$s, entité %3$d) : agent retiré du poste, déclaré depuis %4$s.',
        $computer,
        (string) ($entry['platform'] ?? ''),
        (int) $entry['entities_id'],
        $from
    )
    : sprintf(
        'Sonde %1$s (%2$s, entité %3$d) : mise à jour automatique %4$s à l\'installation, déclaré depuis %5$s.',
        $computer,
        (string) ($entry['platform'] ?? ''),
        (int) $entry['entities_id'],
        $scheduled ? 'posée' : 'non posée',
        $from
    ));

// Trace dans l'historique de l'entité, là où le gestionnaire regarde : la sonde a été installée, et voilà ce
// qu'elle a reçu. Sans auteur — personne n'était connecté, c'est la clé qui a ouvert.
try {
    Log::history((int) $entry['entities_id'], Entity::class, [0, '', $removed
        ? sprintf(
            __('Sonde %1$s : GLPI Agent retiré du poste (TAG « %2$s »).', 'printgestion'),
            $computer,
            (string) ($entry['tag'] ?? '')
        )
        : sprintf(
            __('Sonde %1$s installée (TAG « %2$s ») : mise à jour automatique %3$s sur le PC.', 'printgestion'),
            $computer,
            (string) ($entry['tag'] ?? ''),
            $scheduled ? __('posée', 'printgestion') : __('non posée', 'printgestion')
        )], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
} catch (Throwable $e) {
    PluginPrintgestionLogger::warning('agentreport', 'Historique de l\'entité non écrit pour un compte rendu d\'installation.', $e);
}

// La sonde supprimée de GLPI, si le fichier de retrait l'a demandé et que la clé le permet. Le PC reçoit l'issue et
// l'affiche : « supprimée », « introuvable » ou « refusée ».
$reponse = '';
if ($purge_asked) {
    $issue = empty($entry['purge']) ? 'REFUSE' : PluginPrintgestionAgentreport::purgeProbe((int) $entry['entities_id'], $computer);
    $reponse = 'PURGE ' . $issue;
    $auteur  = getUserName((int) ($entry['users_id'] ?? 0));
    PluginPrintgestionLogger::info('agentreport', sprintf(
        'Sonde %1$s (entité %2$d) : suppression dans GLPI demandée par le fichier de retrait de %3$s — %4$s.',
        $computer,
        (int) $entry['entities_id'],
        $auteur,
        empty($entry['purge']) ? 'refusée, ce fichier n\'en avait pas le droit' : $issue
    ));
    if ($issue === 'OK') {
        try {
            Log::history((int) $entry['entities_id'], Entity::class, [0, '', sprintf(
                __('Sonde %1$s supprimée de GLPI par le fichier de retrait généré par %2$s.', 'printgestion'),
                $computer,
                $auteur
            )], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::warning('agentreport', 'Historique de l\'entité non écrit pour une sonde supprimée.', $e);
        }
    }
}

// Le PC scanne lui-même : on lui rend la plage à balayer, calculée ici par l'analyseur d'adresses du plugin —
// pas par un bout d'arithmétique en batch.
if ($scan) {
    $adresses = PluginPrintgestionRaccordement::parseIps($ips)['ips'];
    if (!empty($adresses)) {
        $bornes  = array_keys($adresses);
        // La cadence part avec la plage, dans le format de la ToolBox de l'agent : le même sur les trois systèmes.
        $rythme  ??= PluginPrintgestionCollectfrequency::parseInstallerChoice(PluginPrintgestionCollectfrequency::INSTALLER_DEFAULT);
        $reponse  = sprintf(
            'SCAN %1$s %2$s %3$s',
            long2ip((int) min($bornes)),
            long2ip((int) max($bornes)),
            $rythme['toolbox']
        );
        PluginPrintgestionLogger::info('agentreport', sprintf(
            'Sonde %1$s : GLPI Inventory absent, scan confié à la ToolBox de l\'agent sur %2$s.',
            $computer,
            substr($reponse, 5)
        ));
    }
} elseif ($relancer) {
    // La découverte est armée côté serveur : le PC n'a plus qu'à faire rappeler GLPI par son agent, depuis sa
    // propre interface locale. C'est ce qui remplace l'attente d'un intervalle d'inventaire.
    $reponse = 'RUN';
    PluginPrintgestionLogger::info('agentreport', sprintf(
        'Sonde %1$s : découverte armée, réveil local demandé au PC (raccordement %2$d).',
        $computer,
        $racc
    ));
}

// GLPI ne connaît pas encore la sonde : ni ses modules ni ses imprimantes n'ont pu être réglés. Le PC doit le
// dire au lieu d'annoncer une fin normale. Un ancien fichier, qui ne connaît pas cette réponse, l'ignore.
if ($reponse === '' && !$removed && PluginPrintgestionCollectsetup::isAvailable() && $sonde === 0) {
    $reponse = 'NOAGENT';
}

// Le PC n'attend rien d'autre : une page d'erreur ne doit pas passer pour un succès.
return new \Symfony\Component\HttpFoundation\Response($reponse, $reponse === '' ? 204 : 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
