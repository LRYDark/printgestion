<?php
/**
 * Snooze groupé : applique un snooze sur plusieurs cartouches d'une même imprimante.
 *
 * POST attendu :
 *   - printers_id   : int
 *   - properties    : JSON array de strings (noms de propriétés SNMP)
 *   - days          : int (1..365)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

if (!Session::haveRight('plugin_printgestion_dashboard', UPDATE)
    && !Session::haveRight('plugin_printgestion_expedition', UPDATE)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// CSRF validé par CheckCsrfListener.
header('Content-Type: application/json; charset=utf-8');

$printers_id    = (int)($_POST['printers_id'] ?? 0);
$days           = (int)($_POST['days'] ?? 0);
$properties_raw = (string)($_POST['properties'] ?? '');

if ($printers_id <= 0 || $days <= 0 || $days > 365 || $properties_raw === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Cloisonnement client : imprimante dans le périmètre de l'utilisateur.
if (!PluginPrintgestionSecurity::canAccessPrinter($printers_id)) {
    PluginPrintgestionSecurity::denyJson();
}

$properties = json_decode($properties_raw, true);
if (!is_array($properties) || empty($properties)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid properties']);
    exit;
}

$ok_count = 0;
$fail     = 0;
foreach ($properties as $p) {
    $prop = trim((string)$p);
    if ($prop === '') {
        continue;
    }
    if (PluginPrintgestionAlert::snooze($printers_id, $prop, $days)) {
        $ok_count++;
    } else {
        $fail++;
    }
}

if (class_exists('PluginPrintgestionAlert')) {
    PluginPrintgestionAlert::invalidateCache();
}

echo json_encode([
    'ok'         => $ok_count > 0,
    'ok_count'   => $ok_count,
    'fail_count' => $fail,
    'days'       => $days,
]);
