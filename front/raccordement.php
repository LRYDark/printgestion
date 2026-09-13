<?php
/**
 * Assistant de raccordement des imprimantes (module Collecte SNMP / Déploiement Agent).
 * Lecture : droit Déploiement ; actions (POST) : droit Déploiement en modification. L'entité du
 * raccordement, ou de la sonde choisie, est revérifiée à chaque requête.
 */
include('../../../inc/includes.php');

use Glpi\Exception\Http\NotFoundHttpException;

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_deploiement', READ);

// Raccordement demandé (id), ou entité d'un nouveau raccordement (entities_id) : accès revérifié.
$is_post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$source  = $is_post ? $_POST : $_GET;
$racc    = null;
$entity  = null;
if (!empty($source['id'])) {
    $racc = new PluginPrintgestionRaccordement();
    if (!$racc->getFromDB((int) $source['id']) || !Session::haveAccessToEntity((int) $racc->fields['entities_id'])) {
        throw new NotFoundHttpException();
    }
    $entity = new Entity();
    if (!$entity->getFromDB((int) $racc->fields['entities_id'])) {
        throw new NotFoundHttpException();
    }
} elseif (isset($source['entities_id']) && $source['entities_id'] !== '') {
    $entity = new Entity();
    if (!$entity->getFromDB((int) $source['entities_id']) || !Session::haveAccessToEntity((int) $source['entities_id'])) {
        throw new NotFoundHttpException();
    }
}

if ($is_post) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    Session::checkRight('plugin_printgestion_deploiement', UPDATE);
    if ($entity === null) {
        throw new NotFoundHttpException();
    }
    Html::redirect(PluginPrintgestionRaccordement::processAction($_POST, $entity, $racc));
}

Html::header(
    PluginPrintgestionRaccordement::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'dp_raccord'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('dp_raccord');
if ($entity !== null) {
    PluginPrintgestionRaccordement::showWizard($entity, $racc);
} else {
    PluginPrintgestionRaccordement::showList();
}
echo "</div>";

Html::footer();
