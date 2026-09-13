<?php
/**
 * Contrôle de la remontée : ce que l'inventaire GLPI reçoit réellement des imprimantes
 * (prérequis, états de collecte, agents, valeurs de consommables, compteurs, doublons).
 * Lecture seule.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
// Module Collecte SNMP / Déploiement Agent : droit distinct de celui de la gestion toner.
Session::checkRight('plugin_printgestion_deploiement', READ);

$state = (string) ($_GET['state'] ?? '');
if ($state !== '' && !isset(PluginPrintgestionCollect::getStateLabels()[$state])) {
    $state = '';
}

Html::header(
    PluginPrintgestionCollect::getTypeName(),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'dp_collect'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('dp_collect');
PluginPrintgestionCollect::showPage($state);
echo "</div>";

Html::footer();
