<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Prix et coûts : module Coût à la page et droit Facturation, jamais le seul droit sur l'imprimante.
if (!PluginPrintgestionConfig::isFeatureEnabled('cout')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Module disabled']);
    exit;
}
if (!Session::haveRight('plugin_printgestion_billing', READ)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$printers_id = (int)($_GET['printers_id'] ?? 0);
$start       = (string)($_GET['start'] ?? '');
$end         = (string)($_GET['end']   ?? '');

if ($printers_id <= 0
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Vérif droit de lecture sur l'imprimante
$printer = new Printer();
if (!$printer->getFromDB($printers_id) || !$printer->canViewItem()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$contracts_id = PluginPrintgestionContractrate::getContractIdForPrinter($printers_id);
$rates        = PluginPrintgestionContractrate::getRatesForContract($contracts_id);
$counters     = PluginPrintgestionPrinterCostsTab::getCountersForPeriod($printers_id, $start, $end);

$delta_nb    = max(0, $counters['end_nb']    - $counters['start_nb']);
$delta_color = max(0, $counters['end_color'] - $counters['start_color']);
$cost        = $delta_nb * $rates['nb'] + $delta_color * $rates['color'];

$contract_name = '—';
if ($contracts_id > 0) {
    $c = new Contract();
    if ($c->getFromDB($contracts_id)) {
        $contract_name = (string)$c->fields['name'];
    }
}

echo json_encode([
    'ok'            => true,
    'contract_name' => $contract_name,
    'rate_nb'       => (float)$rates['nb'],
    'rate_color'    => (float)$rates['color'],
    'start_nb'      => (int)$counters['start_nb'],
    'end_nb'        => (int)$counters['end_nb'],
    'start_color'   => (int)$counters['start_color'],
    'end_color'     => (int)$counters['end_color'],
    'delta_nb'      => (int)$delta_nb,
    'delta_color'   => (int)$delta_color,
    'cost'          => (float)$cost,
]);
