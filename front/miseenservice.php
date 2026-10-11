<?php
/**
 * Module « Mise en service » : mettre une imprimante sous contrat (contrat nouveau ou existant × imprimante nouvelle
 * ou existante), par les classes natives de GLPI.
 * Accès : module « Mise en service » allumé, droit plugin_printgestion_miseenservice (CREATE), vérifié à l'affichage
 * ET au traitement ; les droits natifs de GLPI sur le contrat et l'imprimante sont contrôlés en plus par
 * PluginPrintgestionPrint::processForm(). L'ancienne adresse front/print.form.php charge cette page.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
if (!PluginPrintgestionConfig::isFeatureEnabled('miseenservice')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// ── Traitement (POST) ────────────────────────────────────────────────────────
// Droit revérifié côté serveur, sans faire confiance à l'interface. CSRF validé centralement par GLPI.
if (isset($_POST['add'])) {
    Session::checkRight(PluginPrintgestionPrint::$rightname, CREATE);

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
Session::checkRight(PluginPrintgestionPrint::$rightname, CREATE);

Html::header(
    PluginPrintgestionMenu::getTypeName(),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('ms_new');
PluginPrintgestionPrint::showForm();
echo "</div>";

Html::footer();
