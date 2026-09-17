<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
// Droit natif sur les contrats ET droit du plugin (« Contrats » : créer print / éditer tarifs).
Session::checkRight('contract', UPDATE);
Session::checkRight('plugin_printgestion_contrats', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('contrats')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// Validation CSRF faite par CheckCsrfListener (kernel Symfony) avant ce fichier.

global $DB;

$contracts_id = (int)($_POST['contracts_id'] ?? 0);
if ($contracts_id <= 0) {
    Html::back();
}

$contract = new Contract();
if (!$contract->getFromDB($contracts_id) || !PluginPrintgestionContractrate::canEditRates($contract)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if (isset($_POST['add_rate'])) {
    $allowed = ['nb', 'color', 'both'];
    $type    = in_array($_POST['type_cout'] ?? '', $allowed, true) ? $_POST['type_cout'] : 'both';
    $rate    = (float)str_replace(',', '.', (string)($_POST['rate'] ?? '0'));
    $rate    = max(0.0, min(9999.999999, $rate));
    $actif   = ((int)($_POST['actif'] ?? 1) === 1) ? 1 : 0;

    $DB->insert('glpi_plugin_printgestion_contractrates', PluginPrintgestionEntityscope::forContract($contracts_id) + [
        'contracts_id'  => $contracts_id,
        'type_cout'     => $type,
        'rate'          => $rate,
        'actif'         => $actif,
        'date_creation' => date('Y-m-d H:i:s'),
    ]);
    Session::addMessageAfterRedirect(__('Tarif ajouté', 'printgestion'), true, INFO);
}

if (isset($_POST['delete_rate'])) {
    $rate_id = (int)$_POST['delete_rate'];
    if ($rate_id > 0) {
        $DB->delete('glpi_plugin_printgestion_contractrates', [
            'id'           => $rate_id,
            'contracts_id' => $contracts_id,
        ]);
        // Tarif déjà supprimé ou d'un autre contrat : rien n'a été supprimé, ne pas l'annoncer.
        if ($DB->affectedRows() > 0) {
            Session::addMessageAfterRedirect(__('Tarif supprimé', 'printgestion'), true, INFO);
        } else {
            Session::addMessageAfterRedirect(__('Tarif introuvable sur ce contrat : rien n\'a été supprimé.', 'printgestion'), true, WARNING);
        }
    }
}

Html::redirect(Contract::getFormURLWithID($contracts_id) . '&forcetab=' . urlencode('PluginPrintgestionContractrate$1'));
