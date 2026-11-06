<?php
/**
 * Graphique des cartouches d'une imprimante (PluginPrintgestionTonerchart), en lecture seule : widget natif du tableau
 * de bord (HTML et son script) pour la période et la distribution demandées, couleurs des toners et changements de
 * cartouche détectés.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=utf-8');

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

// Droit et cloisonnement natifs de l'imprimante : on ne trace que ce que l'utilisateur peut voir.
$printer = new Printer();
if (!$printer->getFromDB((int) ($_GET['printers_id'] ?? 0)) || !PluginPrintgestionTonerchart::canView($printer)) {
    PluginPrintgestionSecurity::denyJson();
}

$period = (string) ($_GET['period'] ?? 'P1Y');
if (!array_key_exists($period, PluginPrintgestionTonerchart::getPeriods())) {
    $period = 'P1Y';
}
$format = (string) ($_GET['format'] ?? 'dynamic');
if (!array_key_exists($format, PluginPrintgestionTonerchart::getFormats())) {
    $format = 'dynamic';
}

echo json_encode(['ok' => true] + PluginPrintgestionTonerchart::getData($printer, $period, $format), JSON_UNESCAPED_UNICODE);
