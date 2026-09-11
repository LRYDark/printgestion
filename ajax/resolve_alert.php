<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    Html::displayNotFoundError();
}

if (!Session::haveRight('plugin_printgestion_dashboard', UPDATE)
    && !Session::haveRight('plugin_printgestion_expedition', UPDATE)) {
    Html::displayRightError();
}

// Validation CSRF faite par CheckCsrfListener (kernel Symfony) avant ce fichier.

$alert_id = (int)($_POST['alert_id'] ?? 0);
if ($alert_id <= 0) {
    Session::addMessageAfterRedirect(__('ID alerte invalide', 'printgestion'), true, ERROR);
    Html::back();
}

if (PluginPrintgestionAlert::resolveAlert($alert_id)) {
    Session::addMessageAfterRedirect(__('Alerte marquée comme résolue', 'printgestion'), true, INFO);
} else {
    Session::addMessageAfterRedirect(__('Erreur lors de la résolution', 'printgestion'), true, ERROR);
}

Html::redirect(PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_alerts.php');
