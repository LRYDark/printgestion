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

// Nécessite le plugin Gestion actif + activation dans la config Print Gestion
$config = PluginPrintgestionConfig::getInstance();
if ((int)($config->fields['plugin_gestion_enabled'] ?? 0) !== 1
    || !$plugin->isInstalled('gestion')
    || !$plugin->isActivated('gestion')) {
    echo json_encode(['ok' => false, 'error' => 'Gestion integration not enabled']);
    exit;
}

if (!$DB->tableExists('glpi_plugin_gestion_surveys')) {
    echo json_encode(['ok' => false, 'error' => 'Table gestion surveys not found']);
    exit;
}

$printers_id = (int)($_GET['printers_id'] ?? 0);
if ($printers_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid printer']);
    exit;
}

// Récupère l'entité de l'imprimante pour filtrer les BL du bon client
$printerRow = $DB->request([
    'SELECT' => ['entities_id'],
    'FROM'   => 'glpi_printers',
    'WHERE'  => ['id' => $printers_id],
    'LIMIT'  => 1,
])->current();
if (!is_array($printerRow)) {
    echo json_encode(['ok' => false, 'error' => 'Printer not found']);
    exit;
}
$entities_id = (int)$printerRow['entities_id'];

// Liste les BL non signés de ce client, les plus récents d'abord
$bls = [];
foreach ($DB->request([
    'SELECT' => ['id', 'bl', 'date_creation'],
    'FROM'   => 'glpi_plugin_gestion_surveys',
    'WHERE'  => [
        'signed'      => 0,
        'entities_id' => $entities_id,
    ],
    'ORDER'  => ['id DESC'],
    'LIMIT'  => 200,
]) as $row) {
    $bl_num = trim((string)($row['bl'] ?? ''));
    if ($bl_num === '') {
        continue;
    }
    $date = !empty($row['date_creation']) ? substr((string)$row['date_creation'], 0, 10) : '';
    $label = $bl_num . ($date !== '' ? ' (' . $date . ')' : '');
    $bls[] = [
        'id'    => (int)$row['id'],
        'label' => $label,
    ];
}

echo json_encode([
    'ok'  => true,
    'bls' => $bls,
]);
