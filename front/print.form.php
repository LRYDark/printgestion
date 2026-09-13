<?php
/**
 * Gestion Print — Sous-onglet 2 : Créer Print.
 * Accès conditionné au bit CREATE du droit plugin_printgestion_contrats
 * (vérifié côté AFFICHAGE et côté TRAITEMENT).
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
if (!PluginPrintgestionConfig::isFeatureEnabled('contrats')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// ── Traitement (POST) ────────────────────────────────────────────────────────
// La création est portée par le bit CREATE du plugin : on revérifie le droit
// côté serveur, sans faire confiance à l'UI (toggle JS).
// CSRF validé centralement par GLPI (csrf_compliant => true dans setup.php).
if (isset($_POST['add'])) {
    Session::checkRight('plugin_printgestion_contrats', UPDATE);

    // Transaction + créations natives + liaison Contract_Item + messages.
    $redirect = PluginPrintgestionPrint::processForm($_POST);

    if (is_string($redirect) && $redirect !== '') {
        // Succès → fiche du contrat créé/associé (relecture : onglet Éléments).
        Html::redirect($redirect);
    }
    // Échec → retour au formulaire (messages d'erreur affichés).
    Html::back();
}

// ── Affichage (GET) ──────────────────────────────────────────────────────────
Session::checkRight('plugin_printgestion_contrats', UPDATE);

Html::header(
    PluginPrintgestionMenu::getTypeName(),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('ct_new');
PluginPrintgestionPrint::showForm();
echo "</div>";

Html::footer();
