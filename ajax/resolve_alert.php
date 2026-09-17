<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

if (!Session::haveRight('plugin_printgestion_dashboard', UPDATE)
    && !Session::haveRight('plugin_printgestion_expedition', UPDATE)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

// Validation CSRF faite par CheckCsrfListener (kernel Symfony) avant ce fichier.

$alert_id = (int)($_POST['alert_id'] ?? 0);
if ($alert_id <= 0) {
    Session::addMessageAfterRedirect(__('ID alerte invalide', 'printgestion'), true, ERROR);
    Html::back();
}

// Cloisonnement client : l'alerte doit concerner une imprimante du périmètre.
if (PluginPrintgestionSecurity::getAccessibleAlert($alert_id) === null) {
    throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
}

if (PluginPrintgestionAlert::resolveAlert($alert_id)) {
    Session::addMessageAfterRedirect(__('Alerte marquée comme résolue', 'printgestion'), true, INFO);
} else {
    Session::addMessageAfterRedirect(__('Erreur lors de la résolution', 'printgestion'), true, ERROR);
}

// Retour sur l'écran Expéditions, où sont listées les alertes prioritaires.
Html::redirect(PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_expeditions.php');
