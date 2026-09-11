<?php
/**
 * Envoi groupé de plusieurs cartouches pour une même imprimante.
 *
 * POST attendu :
 *   - printers_id : int
 *   - items_json  : JSON array de [{property, level, days}, ...]
 *
 * Crée N expéditions partageant un group_id (UUID), gère le stock (vide →
 * alerte achat), et envoie 1 mail planif groupé + 1 mail achat groupé si besoin.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

if (!Session::haveRight('plugin_printgestion_expedition', UPDATE)
    && !Session::haveRight('plugin_printgestion_dashboard', UPDATE)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// CSRF déjà validé par CheckCsrfListener.
header('Content-Type: application/json; charset=utf-8');

$printers_id = (int)($_POST['printers_id'] ?? 0);
$items_json  = (string)($_POST['items_json'] ?? '');

if ($printers_id <= 0 || $items_json === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

$items = json_decode($items_json, true);
if (!is_array($items) || empty($items)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid items']);
    exit;
}

// Normalisation + garde-fou : chaque item doit au moins avoir une property string
$clean = [];
foreach ($items as $it) {
    if (!is_array($it)) {
        continue;
    }
    $property = trim((string)($it['property'] ?? ''));
    if ($property === '') {
        continue;
    }
    $clean[] = [
        'property' => $property,
        'level'    => (int)($it['level'] ?? 0),
        'days'     => isset($it['days']) ? (int)$it['days'] : null,
    ];
}

if (empty($clean)) {
    echo json_encode(['ok' => false, 'error' => 'No valid items']);
    exit;
}

$result = PluginPrintgestionExpedition::createGroup($printers_id, $clean);

// Invalide le cache alertes pour que le dashboard reflète immédiatement les nouvelles expéditions
if (class_exists('PluginPrintgestionAlert')) {
    PluginPrintgestionAlert::invalidateCache();
}

echo json_encode([
    'ok'            => true,
    'group_id'      => $result['group_id'],
    'ok_count'      => $result['ok_count'],
    'empty_count'   => $result['empty_count'],
    'skipped_count' => $result['skipped_count'],
    'expeditions'   => $result['expeditions'],
]);
