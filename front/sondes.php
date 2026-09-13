<?php
/**
 * Sondes GLPI Agent (module Collecte SNMP / Déploiement Agent) : sondes muettes par entité, conformité de
 * version, réglages de mise à jour et imprimantes collectées. Lecture : droit Déploiement ; réglages d'une
 * sonde (POST) : Déploiement en modification. La sonde doit être dans les entités de l'utilisateur.
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

$is_post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$source  = $is_post ? $_POST : $_GET;
$agent   = null;
if (!empty($source['id'])) {
    $agent = new Agent();
    if (!$agent->getFromDB((int) $source['id']) || !Session::haveAccessToEntity((int) $agent->fields['entities_id'])) {
        throw new NotFoundHttpException();
    }
}

if ($is_post) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    Session::checkRight('plugin_printgestion_deploiement', UPDATE);
    if ($agent === null || (!isset($_POST['save_agent_settings']) && !isset($_POST['mark_probe_host']))) {
        throw new NotFoundHttpException();
    }
    $result = isset($_POST['mark_probe_host'])
        ? PluginPrintgestionAgentsetting::markProbeHost($agent)
        : PluginPrintgestionAgentsetting::saveForAgent($agent, $_POST);
    Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
    Html::back();
}

Html::header(
    PluginPrintgestionAgentsetting::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'dp_probes'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('dp_probes');
if ($agent !== null) {
    PluginPrintgestionAgentsetting::showDetail($agent);
} else {
    PluginPrintgestionAgentsetting::showList();
}
echo "</div>";

Html::footer();
