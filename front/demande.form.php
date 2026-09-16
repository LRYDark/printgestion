<?php
/**
 * Fiche d'une demande d'envoi : en-tête, lignes et contrôles, historique natif.
 *
 * POST, avec le droit de validation sur l'entité de la demande :
 *   - update   : enregistre les modifications d'une demande proposée ;
 *   - validate : enregistre puis valide (validation en un clic) ;
 *   - cancel   : annule une demande proposée ou validée (motif obligatoire) ;
 *   - sync_statuses : reporte l'avancement des expéditions (expédiée, livrée, posée) sur la demande.
 * GET : affichage seul, aucune écriture.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$demande = new PluginPrintgestionDemande();

if (isset($_POST['sync_statuses'])) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0 || !$demande->getFromDB($id) || !$demande->can($id, UPDATE)) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $updated = PluginPrintgestionDemande::syncFromExpeditions([$id]);
    Session::addMessageAfterRedirect(sprintf(__('Statuts actualisés d\'après les expéditions : %d ligne(s).', 'printgestion'), $updated), false, INFO);
    Html::redirect(PluginPrintgestionDemande::getFormURLWithID($id));
}

if (isset($_POST['update']) || isset($_POST['validate']) || isset($_POST['cancel'])) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    $id = (int) ($_POST['id'] ?? 0);
    // Droit de validation ET entité de la demande ; sinon même réponse qu'une demande
    // inexistante.
    if ($id <= 0 || !$demande->getFromDB($id) || !$demande->can($id, UPDATE)) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }

    // Messages en texte brut (noms d'imprimantes issus de l'inventaire) : échappés.
    $flash = static function (array $messages, int $type): void {
        if (empty($messages)) {
            return;
        }
        Session::addMessageAfterRedirect(
            implode('<br>', array_map(
                static fn($message) => htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8'),
                $messages
            )),
            false,
            $type
        );
    };

    if (isset($_POST['cancel'])) {
        $result = $demande->cancelDemande((string) ($_POST['cancel_reason'] ?? ''));
        if ($result['ok']) {
            $flash([sprintf(__('Demande #%d annulée.', 'printgestion'), $id)], INFO);
        } else {
            $flash($result['errors'], ERROR);
        }
    } else {
        $saved = $demande->saveProposal($_POST);
        if (!empty($saved['errors'])) {
            $flash(array_merge([__('Rien n\'a été enregistré :', 'printgestion')], $saved['errors']), ERROR);
        } elseif (isset($_POST['validate'])) {
            $result = $demande->validateDemande();
            if ($result['ok']) {
                $flash([sprintf(__('Demande #%d validée.', 'printgestion'), $id)], INFO);
            } else {
                $flash(array_merge(
                    [sprintf(__('Demande #%d non validée (modifications enregistrées) :', 'printgestion'), $id)],
                    $result['errors']
                ), ERROR);
            }
            $flash($result['warnings'], WARNING);
        } else {
            $flash([__('Modifications enregistrées.', 'printgestion')], INFO);
        }
    }

    Html::redirect(PluginPrintgestionDemande::getFormURLWithID($id));
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    Html::redirect(PluginPrintgestionDemande::getSearchURL());
}

// Demande inexistante OU hors des entités de l'utilisateur : même réponse (pas de
// divulgation de l'existence d'une demande d'un autre client).
if (!$demande->getFromDB($id) || !$demande->can($id, READ)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Html::header(
    PluginPrintgestionDemande::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'tn_dem'
);

// Avancement des expéditions pas encore reporté (tâche automatique à venir) : signalé, reporté sur POST.
$behind = PluginPrintgestionDemande::countLinesBehindExpeditions([$id]);
if ($behind > 0) {
    echo "<div class='alert alert-info d-flex align-items-center gap-3 mt-3'><div>" . htmlspecialchars(sprintf(
        __('%d ligne(s) en retard sur l\'avancement de leur expédition : la tâche automatique les mettra à jour.', 'printgestion'),
        $behind
    ), ENT_QUOTES, 'UTF-8') . "</div>";
    if ($demande->can($id, UPDATE)) {
        echo "<form method='post' action='" . htmlspecialchars(PluginPrintgestionDemande::getFormURL(), ENT_QUOTES, 'UTF-8') . "' class='ms-auto'>"
            . Html::hidden('id', ['value' => $id])
            . "<button type='submit' name='sync_statuses' value='1' class='btn btn-sm btn-primary'>"
            . htmlspecialchars(__('Actualiser les statuts', 'printgestion'), ENT_QUOTES, 'UTF-8') . "</button>";
        Html::closeForm();
    }
    echo "</div>";
}

$demande->display(['id' => $id]);

Html::footer();
