<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_expedition', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// Validation CSRF faite par CheckCsrfListener (kernel Symfony) avant ce fichier.

$expedition_id = (int)($_POST['id'] ?? 0);
$action        = (string)($_POST['action'] ?? '');

if ($expedition_id <= 0) {
    Session::addMessageAfterRedirect(__('ID expédition invalide', 'printgestion'), true, ERROR);
    Html::back();
}

// Cloisonnement client : l'expédition doit être dans le périmètre (son entité, figée à sa création).
$exp = PluginPrintgestionSecurity::getAccessibleExpedition($expedition_id);
if ($exp === null) {
    throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
}

// Tableau d'où l'on est venu sur la fiche (alertes ou expéditions), validé.
$return_url = PluginPrintgestionExpedition::getReturnURL((string)($_POST['_return'] ?? ''));
// Fiche rouverte après une saisie refusée, sans perdre ce tableau de retour.
$form_url   = PluginPrintgestionExpedition::getFormURLWithID($expedition_id) . '&return=' . rawurlencode($return_url);

if ($action === 'ship') {
    $carrier  = (string)($_POST['carrier']  ?? '');
    $tracking = trim((string)($_POST['tracking'] ?? ''));
    $bl_id    = (int)($_POST['bl_surveys_id'] ?? 0) ?: null;

    if (!in_array($carrier, PluginPrintgestionExpedition::CARRIERS_OFFERED, true)) {
        // Jamais « Autre » par défaut : un transporteur non choisi n'est pas un transporteur.
        Session::addMessageAfterRedirect(__('Choisissez le transporteur.', 'printgestion'), true, ERROR);
        Html::redirect($form_url);
    }
    if ($tracking === '') {
        Session::addMessageAfterRedirect(__('Numéro de suivi requis', 'printgestion'), true, ERROR);
        Html::redirect($form_url);
    }

    // BL choisi : existant et du même client (entité de l'expédition ou parente, dans le périmètre).
    if ($bl_id !== null && PluginPrintgestionSecurity::getBlForExpedition($bl_id, $exp) === null) {
        Session::addMessageAfterRedirect(__('BL introuvable ou rattaché à un autre client : expédition non modifiée.', 'printgestion'), true, ERROR);
        Html::redirect($form_url);
    }

    if (PluginPrintgestionExpedition::markShipped($expedition_id, $carrier, $tracking, $bl_id)) {
        PluginPrintgestionDemande::syncForExpedition($expedition_id);
        Session::addMessageAfterRedirect(__('Expédition marquée comme expédiée', 'printgestion'), true, INFO);
    } else {
        // Le plus souvent, l'envoi a déjà été confirmé (double clic, second onglet) : dire où il en est.
        $now = PluginPrintgestionSecurity::getAccessibleExpedition($expedition_id);
        Session::addMessageAfterRedirect(
            sprintf(
                __('Expédition non modifiée : elle n\'est plus en attente (statut actuel : %s).', 'printgestion'),
                PluginPrintgestionExpedition::getStatusLabel((string)($now['statut'] ?? ''))
            ),
            true,
            WARNING
        );
    }
    // Retour au tableau : la fiche, elle, n'aurait plus qu'un formulaire vide à montrer.
    Html::redirect($return_url);
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

// Autres actions : retour sur la fiche de l'expédition, d'où part le formulaire.
Html::redirect($form_url);
