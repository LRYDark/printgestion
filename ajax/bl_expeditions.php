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
global $DB;

$bl_surveys_id = (int)($_GET['bl_surveys_id'] ?? 0);
if ($bl_surveys_id <= 0) {
    echo json_encode(['ok' => false, 'expeditions' => []]);
    exit;
}

$statut_labels = [
    'pending'     => __('En attente', 'printgestion'),
    'shipped'     => __('Expédiée', 'printgestion'),
    'transit'     => __('En transit', 'printgestion'),
    'delivered'   => __('Livrée', 'printgestion'),
    'stock_empty' => __('Stock vide', 'printgestion'),
];

$expeditions = [];
foreach ($DB->request([
    'SELECT'    => [
        'e.id',
        'e.toner_property',
        'e.statut',
        'e.printers_id',
        'p.name AS printer_name',
    ],
    'FROM'      => 'glpi_plugin_printgestion_expeditions AS e',
    'LEFT JOIN' => [
        'glpi_printers AS p' => [
            'ON' => ['e' => 'printers_id', 'p' => 'id'],
        ],
    ],
    'WHERE'     => ['e.bl_surveys_id' => $bl_surveys_id],
    'ORDER'     => ['e.id DESC'],
]) as $row) {
    $expeditions[] = [
        'id'             => (int)$row['id'],
        'printers_id'    => (int)$row['printers_id'],
        'printer_name'   => (string)($row['printer_name'] ?? '?'),
        'toner_property' => (string)$row['toner_property'],
        'statut'         => (string)$row['statut'],
        'statut_label'   => $statut_labels[$row['statut']] ?? (string)$row['statut'],
    ];
}

echo json_encode([
    'ok'          => true,
    'expeditions' => $expeditions,
]);
