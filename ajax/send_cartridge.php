<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    Html::displayNotFoundError();
}

if (!Session::haveRight('plugin_printgestion_expedition', UPDATE)
    && !Session::haveRight('plugin_printgestion_dashboard', UPDATE)) {
    Html::displayRightError();
}

// NB: la validation CSRF est déjà effectuée par
// Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener AVANT le chargement
// de ce fichier (il valide _glpi_csrf_token avec preserve_token=false et
// consomme le token). Un second checkCSRF ici lèverait une exception car le
// token serait déjà consommé.

$printers_id = (int)($_POST['printers_id'] ?? 0);
$property    = trim((string)($_POST['property']    ?? ''));
$level       = (int)($_POST['level']       ?? 0);
$days        = (int)($_POST['days']        ?? 0);

if ($printers_id <= 0 || $property === '') {
    Session::addMessageAfterRedirect(__('Paramètres invalides', 'printgestion'), true, ERROR);
    Html::back();
}

// Cloisonnement client : imprimante dans le périmètre de l'utilisateur.
if (!PluginPrintgestionSecurity::canAccessPrinter($printers_id)) {
    throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
}

// Vérifier le stock via le résolveur intelligent (binding direct OU type GLPI)
$cartridgeitems_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
$stock = PluginPrintgestionExpedition::getCartridgeStock($cartridgeitems_id);

$reason = $stock > 0 ? 'normal' : 'stock_empty';

$expedition_id = PluginPrintgestionExpedition::createFromAlert(
    $printers_id,
    $property,
    $level,
    $days > 0 ? $days : null,
    $reason
);

if ($expedition_id > 0) {
    if ($reason === 'stock_empty') {
        Session::addMessageAfterRedirect(
            __('Stock vide : alerte achats envoyée', 'printgestion'),
            true,
            WARNING
        );
    } else {
        Session::addMessageAfterRedirect(
            __('Expédition créée et notifications envoyées', 'printgestion'),
            true,
            INFO
        );
    }
} else {
    Session::addMessageAfterRedirect(__('Erreur création expédition', 'printgestion'), true, ERROR);
}

Html::back();
