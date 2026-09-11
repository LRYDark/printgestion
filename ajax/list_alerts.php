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

if (!Session::haveRight('plugin_printgestion_dashboard', READ)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$raw_ent = $_GET['entities_id'] ?? '';
$ent_clean = '';
if ($raw_ent !== '' && $raw_ent !== '0' && (int)$raw_ent > 0) {
    $ent_clean = (int)$raw_ent;
}

$params = [
    'page'        => (int)($_GET['page'] ?? 1),
    'per_page'    => (int)($_GET['per_page'] ?? 25),
    'search'      => (string)($_GET['search'] ?? ''),
    'sort_col'    => (string)($_GET['sort_col'] ?? ''),
    'sort_dir'    => (string)($_GET['sort_dir'] ?? 'asc'),
    'status'      => (string)($_GET['status'] ?? 'critical_watch'),
    'entities_id' => $ent_clean,
    'grouped'     => (int)($_GET['grouped'] ?? 1),
];

$result = PluginPrintgestionAlert::listPagedCached($params);

// Métriques globales (indépendantes du filtre statut/search — calculées sur tout)
$entities_id_int = ($ent_clean !== '') ? (int)$ent_clean : null;
$all_rows = PluginPrintgestionAlert::listAllCached($entities_id_int);
$nb_critical = 0; $nb_watch = 0; $nb_active_exp = 0; $nb_stock_empty = 0;
foreach ($all_rows as $r) {
    if ($r['status'] === PluginPrintgestionAlert::STATUS_CRITICAL) $nb_critical++;
    if ($r['status'] === PluginPrintgestionAlert::STATUS_WATCH)    $nb_watch++;
    if ($r['expedition'] !== null) {
        if (in_array($r['expedition']['statut'], ['pending','shipped','transit','delivered'], true)) $nb_active_exp++;
        if ($r['expedition']['statut'] === 'stock_empty') $nb_stock_empty++;
    }
}

echo json_encode([
    'ok'     => true,
    'rows'   => $result['rows'],
    'total'  => $result['total'],
    'metrics'=> [
        'critical'      => $nb_critical,
        'watch'         => $nb_watch,
        'active_exp'    => $nb_active_exp,
        'stock_empty'   => $nb_stock_empty,
    ],
]);
