<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_expedition', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// Validation CSRF faite par CheckCsrfListener (kernel Symfony) avant ce fichier.

$expedition_id = (int)($_POST['id'] ?? 0);
$action        = (string)($_POST['action'] ?? '');

if ($expedition_id <= 0) {
    Session::addMessageAfterRedirect(__('ID expédition invalide', 'printgestion'), true, ERROR);
    Html::back();
}

// Cloisonnement client : l'expédition doit viser une imprimante du périmètre.
$exp = PluginPrintgestionSecurity::getAccessibleExpedition($expedition_id);
if ($exp === null) {
    throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
}

if ($action === 'ship') {
    $carrier  = (string)($_POST['carrier']  ?? 'other');
    $tracking = trim((string)($_POST['tracking'] ?? ''));
    $bl_id    = (int)($_POST['bl_surveys_id'] ?? 0) ?: null;

    if ($tracking === '') {
        Session::addMessageAfterRedirect(__('Numéro de suivi requis', 'printgestion'), true, ERROR);
        Html::back();
    }

    // BL choisi : existant et du même client (entité de l'imprimante ou parente, dans le périmètre).
    if ($bl_id !== null && PluginPrintgestionSecurity::getBlForExpedition($bl_id, $exp) === null) {
        Session::addMessageAfterRedirect(__('BL introuvable ou rattaché à un autre client : expédition non modifiée.', 'printgestion'), true, ERROR);
        Html::back();
    }

    if (PluginPrintgestionExpedition::markShipped($expedition_id, $carrier, $tracking, $bl_id)) {
        Session::addMessageAfterRedirect(__('Expédition marquée comme expédiée', 'printgestion'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(
            __('Expédition non modifiée : seul un envoi en attente peut être marqué expédié.', 'printgestion'),
            true,
            ERROR
        );
    }
} elseif ($action === 'cancel') {
    // Suppression définitive désactivée : aucune expédition ne doit disparaître sans
    // trace. L'annulation deviendra un statut avec l'objet « Demande d'envoi » (lot 3).
    PluginPrintgestionLogger::warning(
        'update_expedition',
        sprintf('Annulation par suppression refusée pour l\'expédition %d.', $expedition_id)
    );
    Session::addMessageAfterRedirect(
        __('Annulation indisponible : une expédition ne peut pas être supprimée.', 'printgestion'),
        true,
        ERROR
    );
} else {
    Session::addMessageAfterRedirect(__('Action inconnue', 'printgestion'), true, ERROR);
}

// Retour sur la fiche de l'expédition, d'où part le formulaire.
Html::redirect(PluginPrintgestionExpedition::getFormURLWithID($expedition_id));
