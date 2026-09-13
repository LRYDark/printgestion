<?php
/**
 * Gestion Print — Sous-onglet 1 : Dashboard.
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

// Charge la lib de graphiques (ECharts) bundlée par GLPI, pour les camemberts/barres.
Html::requireJs('charts');

Html::header(
    PluginPrintgestionMenu::getTypeName(),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('ct_dash');
PluginPrintgestionDashboard::showCards();
PluginPrintgestionDashboard::showCharts();
echo "</div>";

Html::footer();
