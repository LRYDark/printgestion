<?php
/**
 * Gestion Print — Onglet « Liste » : tableau de recherche natif des contrats
 * d'impression (itemtype dédié PluginPrintgestionContract).
 * Accès conditionné au bit READ du droit plugin_printgestion_contrats.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_contrats', READ);
if (!PluginPrintgestionConfig::isFeatureEnabled('contrats')) { throw new \Glpi\Exception\Http\NotFoundHttpException(); }

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Html::header(
    PluginPrintgestionMenu::getTypeName(),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('ct_list');
PluginPrintgestionDashboard::showList();
echo "</div>";

Html::footer();
