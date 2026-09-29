<?php
/**
 * Imprimantes collectées (module Collecte SNMP / Déploiement Agent) : ce que l'inventaire GLPI reçoit réellement
 * des imprimantes — l'état de la collecte dans la liste native des imprimantes (type dédié), tuiles par état,
 * prérequis, analyses de réglage pour l'administrateur. Lecture : droit Déploiement ; recalcul (POST) :
 * Déploiement en modification.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_deploiement', READ);

// Ancien lien « ?state=… » (tuiles des alertes, favoris) : le filtre natif équivalent.
$state = (string) ($_GET['state'] ?? '');
if ($state !== '' && isset(PluginPrintgestionCollect::getStateLabels()[$state])) {
    Html::redirect(PluginPrintgestionCollectview::getStateURL($state));
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    PluginPrintgestionCollectview::processRecompute(PluginPrintgestionPrintercollect::getSearchURL());
}

Html::header(
    __('Imprimantes collectées', 'printgestion'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'dp_collect'
);
echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('dp_collect');
// Vues fraîches (quinze minutes au plus), puis l'onglet : tuiles, prérequis, liste native, analyses.
PluginPrintgestionCollectview::rebuildIfStale();
PluginPrintgestionCollectview::showPage('imprimantes');
echo "</div>";
Html::footer();
