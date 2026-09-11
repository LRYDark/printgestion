<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_expedition', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

// Validation CSRF faite par CheckCsrfListener avant ce fichier.

header('Content-Type: application/json; charset=utf-8');

global $DB;

$expedition_id = (int)($_POST['expedition_id'] ?? 0);
if ($expedition_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid expedition id']);
    exit;
}

$allowed_statuts  = ['pending', 'shipped', 'transit', 'delivered', 'stock_empty'];
$allowed_carriers = ['', 'ups', 'gls', 'chronopost', 'other'];

$statut   = in_array($_POST['statut']  ?? '', $allowed_statuts, true)  ? $_POST['statut']  : null;
$carrier  = in_array($_POST['carrier'] ?? '', $allowed_carriers, true) ? $_POST['carrier'] : null;
$tracking = trim((string)($_POST['tracking'] ?? ''));

if ($statut === null) {
    echo json_encode(['ok' => false, 'error' => 'Invalid status']);
    exit;
}

$data = [
    'statut'            => $statut,
    'transport_carrier' => ($carrier === '' || $carrier === null) ? null : $carrier,
    'transport_number'  => $tracking !== '' ? $tracking : null,
];

// Complétion automatique des dates selon le statut
if ($statut === 'shipped' && empty($DB->request([
    'SELECT' => ['date_shipped'],
    'FROM'   => 'glpi_plugin_printgestion_expeditions',
    'WHERE'  => ['id' => $expedition_id],
    'LIMIT'  => 1,
])->current()['date_shipped'] ?? null)) {
    $data['date_shipped'] = date('Y-m-d H:i:s');
}
if ($statut === 'delivered') {
    $data['date_delivered'] = date('Y-m-d H:i:s');
}

$ok = $DB->update('glpi_plugin_printgestion_expeditions', $data, ['id' => $expedition_id]);

echo json_encode([
    'ok'      => (bool)$ok,
    'updated' => (int)$ok,
]);
