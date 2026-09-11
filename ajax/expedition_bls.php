<?php
/**
 * Liste les BL (glpi_plugin_gestion_surveys) associés à une expédition.
 *
 * GET attendu : expedition_id
 *
 * Retourne : { ok: true, bls: [{ id, bl, signed, date_creation, entity_name }] }
 * Inclut la relation N:N via glpi_plugin_printgestion_expedition_bls ET
 * le bl_surveys_id "principal" stocké sur expeditions (rétro-compat).
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

if (!Session::haveRight('plugin_printgestion_expedition', READ)
    && !Session::haveRight('plugin_printgestion_dashboard', READ)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
global $DB;

$expedition_id = (int)($_GET['expedition_id'] ?? 0);
if ($expedition_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Cloisonnement client : l'expédition doit viser une imprimante du périmètre.
if (PluginPrintgestionSecurity::getAccessibleExpedition($expedition_id) === null) {
    PluginPrintgestionSecurity::denyJson();
}

if (!$DB->tableExists('glpi_plugin_gestion_surveys')) {
    echo json_encode(['ok' => true, 'bls' => []]);
    exit;
}

// Collecte des bl_surveys_id liés (N:N + rétro-compat bl_surveys_id)
$linked_ids = [];

if ($DB->tableExists('glpi_plugin_printgestion_expedition_bls')) {
    foreach ($DB->request([
        'SELECT' => ['bl_surveys_id'],
        'FROM'   => 'glpi_plugin_printgestion_expedition_bls',
        'WHERE'  => ['expeditions_id' => $expedition_id],
    ]) as $r) {
        $linked_ids[(int)$r['bl_surveys_id']] = true;
    }
}

$exp = $DB->request([
    'SELECT' => ['bl_surveys_id'],
    'FROM'   => 'glpi_plugin_printgestion_expeditions',
    'WHERE'  => ['id' => $expedition_id],
    'LIMIT'  => 1,
])->current();
if (is_array($exp) && (int)($exp['bl_surveys_id'] ?? 0) > 0) {
    $linked_ids[(int)$exp['bl_surveys_id']] = true;
}

if (empty($linked_ids)) {
    echo json_encode(['ok' => true, 'bls' => []]);
    exit;
}

$bls = [];
foreach ($DB->request([
    'SELECT'    => ['s.id', 's.bl', 's.signed', 's.date_creation', 's.save', 'e.completename AS entity_name'],
    'FROM'      => 'glpi_plugin_gestion_surveys AS s',
    'LEFT JOIN' => [
        'glpi_entities AS e' => [
            'ON' => ['s' => 'entities_id', 'e' => 'id'],
        ],
    ],
    'WHERE'     => ['s.id' => array_keys($linked_ids)],
    'ORDER'     => ['s.id DESC'],
]) as $row) {
    $bls[] = [
        'id'            => (int)$row['id'],
        'bl'            => (string)($row['bl'] ?? ''),
        'signed'        => (int)($row['signed'] ?? 0),
        'date_creation' => (string)($row['date_creation'] ?? ''),
        'save'          => (string)($row['save'] ?? ''),
        'entity_name'   => (string)($row['entity_name'] ?? ''),
    ];
}

echo json_encode(['ok' => true, 'bls' => $bls]);
