<?php
/**
 * Onglet « Alertes » du module toner : commandes non transmises aux Achats et alertes prioritaires (mauvaise
 * imprimante, livraison en retard, cartouche toujours pas changée), chacune encadrée de rouge
 * (PluginPrintgestionPriorityalert).
 */
include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('plugin_printgestion_expedition', READ) && !Session::haveRight('plugin_printgestion_validation', READ)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
if (!PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Html::header(
    __('Print Gestion — Alertes', 'printgestion'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

// Livraisons constatées depuis le dernier passage reprises maintenant : une alerte de retard déjà livrée disparaît.
PluginPrintgestionTracking::syncOnDisplay();

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('tn_prio');

if (!PluginPrintgestionPriorityalert::show()) {
    echo PluginPrintgestionUi::emptyState(
        __('Aucune alerte : aucune commande en attente de transmission aux Achats, aucune cartouche partie vers une autre imprimante, aucune livraison ni pose en retard.', 'printgestion'),
        [
            __('Expéditions', 'printgestion')   => PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_expeditions.php',
            __('Alertes toner', 'printgestion') => PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_alerts.php',
        ]
    );
}

echo "</div>";
Html::footer();
