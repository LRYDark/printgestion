<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

// GET : pas de CSRF (lecture seule)
header('Content-Type: application/json; charset=utf-8');

// Lecture réservée aux profils ayant accès aux alertes ou aux expéditions.
if (!Session::haveRight('plugin_printgestion_dashboard', READ)
    && !Session::haveRight('plugin_printgestion_expedition', READ)) {
    PluginPrintgestionSecurity::denyJson();
}

$printers_id = (int)($_GET['printers_id'] ?? 0);
$property    = trim((string)($_GET['property'] ?? ''));

if ($printers_id <= 0 || $property === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Cloisonnement client : imprimante dans le périmètre de l'utilisateur.
if (!PluginPrintgestionSecurity::canAccessPrinter($printers_id)) {
    PluginPrintgestionSecurity::denyJson();
}

global $DB;

$cartridgeitems_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
$cartridge_name = '';
$location = '';

if ($cartridgeitems_id > 0) {
    $row = $DB->request([
        'SELECT'    => ['ci.name', 'l.completename AS location_name'],
        'FROM'      => 'glpi_cartridgeitems AS ci',
        'LEFT JOIN' => [
            'glpi_locations AS l' => [
                'ON' => ['ci' => 'locations_id', 'l' => 'id'],
            ],
        ],
        'WHERE' => ['ci.id' => $cartridgeitems_id],
        'LIMIT' => 1,
    ])->current();
    if (is_array($row)) {
        $cartridge_name = (string)($row['name'] ?? '');
        $location       = (string)($row['location_name'] ?? '');
    }
}

$stock = PluginPrintgestionExpedition::getCartridgeStock($cartridgeitems_id);

echo json_encode([
    'ok'                => true,
    'cartridge_name'    => $cartridge_name,
    'cartridgeitems_id' => $cartridgeitems_id,
    'stock'             => $stock,
    'location'          => $location,
]);
