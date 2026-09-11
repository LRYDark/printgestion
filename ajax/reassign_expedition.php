<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_expedition', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    Html::displayNotFoundError();
}

// Validation CSRF faite par CheckCsrfListener (kernel Symfony) avant ce fichier.

$expedition_id    = (int)($_POST['expedition_id']    ?? 0);
$new_printers_id  = (int)($_POST['new_printers_id']  ?? 0);

if ($expedition_id <= 0 || $new_printers_id <= 0) {
    Session::addMessageAfterRedirect(__('Paramètres invalides', 'printgestion'), true, ERROR);
    Html::back();
}

// Cloisonnement client : l'expédition ET l'imprimante cible dans le périmètre.
if (PluginPrintgestionSecurity::getAccessibleExpedition($expedition_id) === null
    || !PluginPrintgestionSecurity::canAccessPrinter($new_printers_id)) {
    throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
}

if (PluginPrintgestionExpedition::reassignToPrinter($expedition_id, $new_printers_id)) {
    Session::addMessageAfterRedirect(
        __('Expédition réassignée et alerte résolue', 'printgestion'),
        true,
        INFO
    );
} else {
    Session::addMessageAfterRedirect(
        __('Erreur lors de la réassignation', 'printgestion'),
        true,
        ERROR
    );
}

Html::redirect(PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_alerts.php');
