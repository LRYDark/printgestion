<?php
/**
 * État du recalcul des alertes toner (lecture seule, JSON), interrogé par l'écran des alertes pendant qu'un recalcul
 * demandé tourne dans la tâche PrintgestionRebuildAlerts : l'écran se recharge quand `pending` repasse à null.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (!Session::haveRight('plugin_printgestion_dashboard', READ)) {
    PluginPrintgestionSecurity::denyJson();
}

echo json_encode(['ok' => true, 'pending' => PluginPrintgestionAlertview::getPendingRequest()]);
