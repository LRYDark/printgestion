<?php
/**
 * Sondes & remontée (module Collecte SNMP / Déploiement Agent) : un écran de supervision, deux listes natives —
 * les sondes (Agents de GLPI, colonnes du plugin) et les imprimantes collectées (état de la collecte) —, tuiles,
 * prérequis, analyses de réglage pour l'administrateur. Lecture : droit Déploiement ; recalcul et fiche d'une sonde
 * (POST) : Déploiement en modification. Les actions massives sur les agents et les imprimantes se font dans leurs
 * listes natives (Administration > Agents, Parc > Imprimantes), qui portent les mêmes colonnes.
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
$vue     = (string) ($source['vue'] ?? '') === 'imprimantes' ? 'imprimantes' : 'sondes';
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
    if (isset($_POST['recompute_views'])) {
        $printers = PluginPrintgestionCollectview::rebuild();
        Session::addMessageAfterRedirect(
            htmlspecialchars(sprintf(__('État de la collecte recalculé : %d imprimante(s), sondes mises à jour.', 'printgestion'), $printers), ENT_QUOTES, 'UTF-8'),
            false,
            INFO
        );
        Html::redirect($vue === 'imprimantes' ? PluginPrintgestionPrintercollect::getSearchURL() : PluginPrintgestionSonde::getSearchURL());
    }
    // Une seule action de sonde reste ici : marquer le PC. Le reste est devenu un fichier à lancer sur le PC.
    if ($agent === null || !isset($_POST['mark_probe_host'])) {
        throw new NotFoundHttpException();
    }
    $result = PluginPrintgestionAgentsetting::markProbeHost($agent);
    Session::addMessageAfterRedirect(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), false, $result['ok'] ? INFO : ERROR);
    Html::back();
}

Html::header(
    __('Sondes & remontée', 'printgestion'),
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
    // Vues fraîches (quinze minutes au plus), puis l'écran : tuiles, prérequis, la liste demandée.
    PluginPrintgestionCollectview::rebuildIfStale();
    PluginPrintgestionCollectview::showPage($vue);
}
echo "</div>";
Html::footer();
