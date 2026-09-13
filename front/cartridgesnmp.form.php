<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('cartridge', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// CSRF géré par CheckCsrfListener (kernel Symfony) avant ce fichier.

global $DB;

$cartridgeitems_id = (int)($_POST['cartridgeitems_id'] ?? 0);
if ($cartridgeitems_id <= 0) {
    Html::back();
}

// Vérifie que la cartouche existe et que l'utilisateur peut l'éditer
$item = new CartridgeItem();
if (!$item->getFromDB($cartridgeitems_id) || !$item->can($cartridgeitems_id, UPDATE)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if (isset($_POST['save_bindings'])) {
    $bound = $_POST['bound'] ?? [];
    if (!is_array($bound)) {
        $bound = [];
    }

    // Valide et déduplique les propriétés soumises
    $available = PluginPrintgestionCartridgesnmp::getAvailableSnmpProperties($cartridgeitems_id);
    $valid = [];
    foreach ($bound as $prop) {
        $prop = trim((string)$prop);
        if ($prop !== '' && in_array($prop, $available, true)) {
            $valid[] = $prop;
        }
    }
    $valid = array_values(array_unique($valid));

    // Stratégie : purge puis réinsert. Simple et idempotent.
    $DB->delete('glpi_plugin_printgestion_cartridge_snmp', ['cartridgeitems_id' => $cartridgeitems_id]);
    foreach ($valid as $prop) {
        $DB->insert('glpi_plugin_printgestion_cartridge_snmp', [
            'cartridgeitems_id' => $cartridgeitems_id,
            'snmp_property'     => $prop,
        ]);
    }

    Session::addMessageAfterRedirect(
        sprintf(__('%d propriété(s) SNMP liée(s) à cette cartouche', 'printgestion'), count($valid)),
        true, INFO
    );
}

Html::redirect(CartridgeItem::getFormURLWithID($cartridgeitems_id)
    . '&forcetab=' . urlencode('PluginPrintgestionCartridgesnmp$1'));
