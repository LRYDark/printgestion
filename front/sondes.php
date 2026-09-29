<?php
/**
 * Sondes (module Collecte SNMP / Déploiement Agent) : les sondes dans la liste native des Agents de GLPI (type
 * dédié, colonnes du plugin), tuiles, fiche d'une sonde. Lecture : droit Déploiement ; recalcul et fiche d'une sonde
 * (POST) : Déploiement en modification. Les actions massives sur les agents se font dans Administration > Agents,
 * qui porte les mêmes colonnes. Les imprimantes collectées ont leur propre onglet (collect.php).
 */
include('../../../inc/includes.php');

use Glpi\Exception\Http\NotFoundHttpException;

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_deploiement', READ);

$is_post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$source  = $is_post ? $_POST : $_GET;
$agent   = null;
if (!empty($source['id'])) {
    $agent = new Agent();
    if (!$agent->getFromDB((int) $source['id']) || !Session::haveAccessToEntity((int) $agent->fields['entities_id'])) {
        throw new NotFoundHttpException();
    }
}

if ($is_post) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    Session::checkRight('plugin_printgestion_deploiement', UPDATE);
    if (isset($_POST['recompute_views'])) {
        PluginPrintgestionCollectview::processRecompute(PluginPrintgestionSonde::getSearchURL());
    }
    // Une seule action de sonde reste ici : marquer le PC. Le reste est devenu un fichier à lancer sur le PC.
    if ($agent === null || !isset($_POST['mark_probe_host'])) {
        throw new NotFoundHttpException();
    }
    $result = PluginPrintgestionAgentsetting::markProbeHost($agent);
    Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
    Html::back();
}

Html::header(
    PluginPrintgestionSonde::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'dp_probes'
);
echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('dp_probes');
if ($agent !== null) {
    PluginPrintgestionAgentsetting::showDetail($agent);
} else {
    // Vues fraîches (quinze minutes au plus), puis l'onglet : tuiles, liste native des sondes.
    PluginPrintgestionCollectview::rebuildIfStale();
    PluginPrintgestionCollectview::showPage('sondes');
}
echo "</div>";
Html::footer();
