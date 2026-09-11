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

if (!Session::haveRight('plugin_printgestion_billing', READ)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// Traite 0, '', '-1' et absent comme "pas de filtre entité"
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
    'start'       => (string)($_GET['start'] ?? ''),
    'end'         => (string)($_GET['end']   ?? ''),
    'entities_id' => $ent_clean,
    'view'        => (string)($_GET['view'] ?? 'printer'),
];

$result = PluginPrintgestionBilling::listPagedCached($params);

echo json_encode([
    'ok'          => true,
    'rows'        => $result['rows'],
    'total'       => $result['total'],
    'metrics'     => [
        'total_nb'    => $result['total_nb'],
        'total_color' => $result['total_color'],
        'total_cost'  => $result['total_cost'],
        'nb_clients'  => $result['nb_clients'],
        'nb_printers' => $result['nb_printers'],
    ],
]);
