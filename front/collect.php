<?php
/**
 * Collecte SNMP : imprimantes muettes, jamais remontées ou sans niveau lisible, et agents
 * d'inventaire qui ne remontent plus (lecture des alertes toner).
 */
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_dashboard', READ);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
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
    'tn_collect'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('tn_collect');
PluginPrintgestionCollect::showPage($state);
echo "</div>";

Html::footer();
