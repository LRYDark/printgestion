<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

// Snooze accessible aux users ayant UPDATE sur dashboard OU expedition
if (!Session::haveRight('plugin_printgestion_dashboard', UPDATE)
    && !Session::haveRight('plugin_printgestion_expedition', UPDATE)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// Validation CSRF faite par CheckCsrfListener avant ce fichier.

header('Content-Type: application/json; charset=utf-8');

$printers_id = (int)($_POST['printers_id'] ?? 0);
$property    = trim((string)($_POST['property'] ?? ''));
$days        = (int)($_POST['days'] ?? 0);

if ($printers_id <= 0 || $property === '' || $days <= 0 || $days > 365) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

$ok = PluginPrintgestionAlert::snooze($printers_id, $property, $days);

echo json_encode([
    'ok'           => (bool)$ok,
    'snooze_days'  => $days,
]);
