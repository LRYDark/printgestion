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
    // Propriétés connues : remontées par une imprimante compatible, ou déjà liées.
    $current = PluginPrintgestionCartridgesnmp::getBoundProperties($cartridgeitems_id);
    $known   = array_merge(PluginPrintgestionCartridgesnmp::getAvailableSnmpProperties($cartridgeitems_id), $current);
    $clean   = static function ($values) use ($known): array {
        $out = [];
        foreach (is_array($values) ? $values : [] as $prop) {
            $prop = trim((string)$prop);
            if ($prop !== '' && in_array($prop, $known, true)) {
                $out[$prop] = $prop;
            }
        }
        return array_values($out);
    };
    $checked = $clean($_POST['bound'] ?? []);
    $shown   = $clean($_POST['shown'] ?? []);

    // Seules les propriétés affichées dans le formulaire sont modifiées : une liaison
    // absente de l'écran n'est jamais supprimée (plus de purge puis réinsertion, qui
    // perdait les liaisons des propriétés non listées).
    $removed = array_values(array_diff(array_intersect($current, $shown), $checked));
    $added   = array_values(array_diff($checked, $current));
    if (!empty($removed)) {
        $DB->delete('glpi_plugin_printgestion_cartridge_snmp', [
            'cartridgeitems_id' => $cartridgeitems_id,
            'snmp_property'     => $removed,
        ]);
    }
    foreach ($added as $prop) {
        $DB->insert('glpi_plugin_printgestion_cartridge_snmp', [
            'cartridgeitems_id' => $cartridgeitems_id,
            'snmp_property'     => $prop,
        ]);
    }

    Session::addMessageAfterRedirect(
        sprintf(__('%d propriété(s) SNMP liée(s) à cette cartouche', 'printgestion'), count($current) - count($removed) + count($added)),
        true, INFO
    );
}

Html::redirect(CartridgeItem::getFormURLWithID($cartridgeitems_id)
    . '&forcetab=' . urlencode('PluginPrintgestionCartridgesnmp$1'));
