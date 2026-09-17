<?php
/**
 * Fréquence des relevés d'imprimantes d'une entité (onglet « Déploiement Agent »). POST seulement : droit Déploiement
 * en modification et accès à l'entité ; retour sur l'onglet de l'entité.
 */
include('../../../inc/includes.php');

use Glpi\Exception\Http\NotFoundHttpException;

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new NotFoundHttpException();
}
// Décision commerciale (pas une question de site) : réglée par l'administrateur du plugin seulement.
Session::checkRight('plugin_printgestion_deploiement', UPDATE);
Session::checkRight('plugin_printgestion_config', UPDATE);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['save_frequency'])) {
    throw new NotFoundHttpException();
}

// Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
$entities_id = (int) ($_POST['entities_id'] ?? -1);
$entity      = new Entity();
if ($entities_id < 0 || !Session::haveAccessToEntity($entities_id) || !$entity->getFromDB($entities_id)) {
    throw new NotFoundHttpException();
}

$result = PluginPrintgestionCollectfrequency::saveForEntity($entity, $_POST);
Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
Html::redirect(Entity::getFormURLWithID($entities_id) . '&forcetab=' . urlencode('PluginPrintgestionAgentdeploy$1'));
