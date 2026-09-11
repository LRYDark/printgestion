<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_expedition', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    Html::displayNotFoundError();
}

// Validation CSRF faite par CheckCsrfListener (kernel Symfony) avant ce fichier.

$expedition_id = (int)($_POST['id'] ?? 0);
$action        = (string)($_POST['action'] ?? '');

if ($expedition_id <= 0) {
    Session::addMessageAfterRedirect(__('ID expédition invalide', 'printgestion'), true, ERROR);
    Html::back();
}

if ($action === 'ship') {
    $carrier  = (string)($_POST['carrier']  ?? 'other');
    $tracking = trim((string)($_POST['tracking'] ?? ''));
    $bl_id    = (int)($_POST['bl_surveys_id'] ?? 0) ?: null;

    if ($tracking === '') {
        Session::addMessageAfterRedirect(__('Numéro de suivi requis', 'printgestion'), true, ERROR);
        Html::back();
    }

    if (PluginPrintgestionExpedition::markShipped($expedition_id, $carrier, $tracking, $bl_id)) {
        Session::addMessageAfterRedirect(__('Expédition marquée comme expédiée', 'printgestion'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Erreur mise à jour', 'printgestion'), true, ERROR);
    }
} elseif ($action === 'cancel') {
    global $DB;
    $DB->delete('glpi_plugin_printgestion_expeditions', ['id' => $expedition_id]);
    Session::addMessageAfterRedirect(__('Expédition annulée', 'printgestion'), true, INFO);
} else {
    Session::addMessageAfterRedirect(__('Action inconnue', 'printgestion'), true, ERROR);
}

Html::redirect(PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_alerts.php');
