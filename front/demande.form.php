<?php
/**
 * Fiche d'une demande d'envoi : en-tête, lignes et contrôles, historique natif.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    Html::displayNotFoundError();
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    Html::redirect(PluginPrintgestionDemande::getSearchURL());
}

// Demande inexistante OU hors des entités de l'utilisateur : même réponse (pas de
// divulgation de l'existence d'une demande d'un autre client).
$demande = new PluginPrintgestionDemande();
if (!$demande->getFromDB($id) || !$demande->can($id, READ)) {
    Html::displayNotFoundError();
}

Html::header(
    PluginPrintgestionDemande::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'tn_dem'
);

$demande->display(['id' => $id]);

Html::footer();
