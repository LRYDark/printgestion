<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_expedition', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
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

// Réattribution manuelle d'un envoi parti, vers l'imprimante où une alerte « mauvaise
// imprimante » en cours a détecté la pose ; jamais vers une autre machine.
$refusal = PluginPrintgestionExpedition::getReassignRefusal($expedition_id, $new_printers_id);
if ($refusal !== '') {
    Session::addMessageAfterRedirect(htmlspecialchars($refusal, ENT_QUOTES, 'UTF-8'), true, ERROR);
    Html::back();
}

$property = (string) (PluginPrintgestionSecurity::getAccessibleExpedition($expedition_id)['toner_property'] ?? '');
if (PluginPrintgestionExpedition::reassignToPrinter($expedition_id, $new_printers_id)) {
    PluginPrintgestionDemande::syncForExpedition($expedition_id);
    // Cartouche native créée seulement si la référence de l'emplacement est résolue : sinon, le dire.
    if (PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($new_printers_id, $property) <= 0) {
        Session::addMessageAfterRedirect(
            __('Cartouche GLPI non créée sur la nouvelle imprimante : référence de cartouche non résolue pour ce toner (liaison SNMP ou modèle à renseigner).', 'printgestion'),
            true,
            WARNING
        );
    }
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

// Retour sur l'écran Expéditions, où sont listées les alertes prioritaires.
Html::redirect(PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_expeditions.php');
