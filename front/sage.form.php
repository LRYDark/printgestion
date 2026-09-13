<?php
/**
 * Onglet « Print Gestion — Sage » de l'entité : enregistrement de la correspondance
 * entité ↔ client Sage (droit de configuration du plugin, entité accessible).
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_config', UPDATE);

if (isset($_POST['save_entity_client'])) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    $entities_id = (int) ($_POST['entities_id'] ?? -1);
    if ($entities_id < 0 || !Session::haveAccessToEntity($entities_id)) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }

    $error = PluginPrintgestionSage::setEntityClient(
        $entities_id,
        (int) ($_POST['plugin_printgestion_sageclients_id'] ?? 0)
    );
    if ($error !== '') {
        Session::addMessageAfterRedirect(htmlspecialchars($error, ENT_QUOTES, 'UTF-8'), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(__('Correspondance avec le client Sage enregistrée.', 'printgestion'), false, INFO);
    }
}

Html::back();
