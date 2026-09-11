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

if (!Session::haveRight('plugin_printgestion_expedition', READ)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

global $GLPI_CACHE;

$raw_ent = $_GET['entities_id'] ?? '';
$ent_clean = '';
if ($raw_ent !== '' && $raw_ent !== '0' && (int)$raw_ent > 0) {
    $ent_clean = (int)$raw_ent;
}

$params = [
    'page'        => (int)($_GET['page'] ?? 1),
    'per_page'    => (int)($_GET['per_page'] ?? 25),
    'search'      => (string)($_GET['search'] ?? ''),
    'sort_col'    => (string)($_GET['sort_col'] ?? 'date_alert'),
    'sort_dir'    => (string)($_GET['sort_dir'] ?? 'desc'),
    'start'       => (string)($_GET['start'] ?? ''),
    'end'         => (string)($_GET['end']   ?? ''),
    'entities_id' => $ent_clean,
    'statut'      => (string)($_GET['statut'] ?? 'all'),
];

// Cache court (60s) pour cohérence avec les 2 autres dashboards. La source est
// une vraie table indexée donc le gain est limité, mais évite les recalculs
// sur clics rapides de pagination.
$cache_key = 'plugin_printgestion_expeditions_' . md5(serialize($params));
$result = null;
if (isset($GLPI_CACHE) && $GLPI_CACHE->has($cache_key)) {
    $cached = $GLPI_CACHE->get($cache_key);
    if (is_array($cached)) {
        $result = $cached;
    }
}
if ($result === null) {
    $result = PluginPrintgestionExpedition::listPaged($params);
    if (isset($GLPI_CACHE)) {
        $GLPI_CACHE->set($cache_key, $result, 60);
    }
}

// Métriques globales (sans filtre recherche/statut)
$entities_id_int = ($ent_clean !== '') ? (int)$ent_clean : null;
global $DB;
$metrics_where = [];
if ($entities_id_int !== null) {
    $metrics_where['p.entities_id'] = $entities_id_int;
}
$metrics = ['pending' => 0, 'shipped' => 0, 'transit' => 0, 'delivered' => 0, 'stock_empty' => 0];
foreach ($DB->request([
    'SELECT'    => ['e.statut', new \QueryExpression('COUNT(*) AS cnt')],
    'FROM'      => 'glpi_plugin_printgestion_expeditions AS e',
    'LEFT JOIN' => [
        'glpi_printers AS p' => ['ON' => ['e' => 'printers_id', 'p' => 'id']],
    ],
    'WHERE'     => $metrics_where,
    'GROUPBY'   => ['e.statut'],
]) as $m) {
    $metrics[(string)$m['statut']] = (int)$m['cnt'];
}

echo json_encode([
    'ok'      => true,
    'rows'    => $result['rows'],
    'total'   => $result['total'],
    'metrics' => $metrics,
]);
