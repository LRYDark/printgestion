<?php
/**
 * Contrôle de la remontée : ce que l'inventaire GLPI reçoit réellement des imprimantes
 * (prérequis, états de collecte, agents, valeurs de consommables, compteurs, doublons).
 * Lecture seule.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// Module Collecte SNMP / Déploiement Agent (techniciens), ou module toner : l'écran des
// alertes renvoie ici pour les imprimantes muettes.
$access = array_filter(
    ['deploiement' => 'plugin_printgestion_deploiement', 'toner' => 'plugin_printgestion_dashboard'],
    static fn(string $feature) => PluginPrintgestionConfig::isFeatureEnabled($feature),
    ARRAY_FILTER_USE_KEY
);
if (empty($access)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
if (empty(array_filter($access, static fn(string $right) => Session::haveRight($right, READ)))) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

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
