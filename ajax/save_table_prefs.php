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

global $GLPI_CACHE;

$users_id = (int)(Session::getLoginUserID() ?: 0);
$src = $_POST ?: $_GET;
$table_id = trim((string)($src['table_id'] ?? ''));
$prefs    = (string)($src['prefs'] ?? '');

if ($users_id <= 0 || $table_id === '' || strlen($table_id) > 64) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

$decoded = json_decode($prefs, true);
if (!is_array($decoded)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

if (!isset($GLPI_CACHE)) {
    echo json_encode(['ok' => false, 'error' => 'Cache unavailable']);
    exit;
}

// Stockage dans $GLPI_CACHE : clé = 'plugin_printgestion_table_prefs_<users_id>'
// Valeur = map { table_id => { widths: {...} } }. TTL = 1 an (renouvelé à chaque
// modification), donc persiste tant que le cache GLPI n'est pas vidé.
$key = 'plugin_printgestion_table_prefs_' . $users_id;
$all = $GLPI_CACHE->get($key);
if (!is_array($all)) {
    $all = [];
}
$all[$table_id] = $decoded;
$GLPI_CACHE->set($key, $all, 365 * 86400);

echo json_encode(['ok' => true]);
