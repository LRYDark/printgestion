<?php
/**
 * Fichier de consigne d'une sonde, à lancer sur le PC : il pose (« maj=1 ») ou retire (« maj=0 ») la tâche planifiée
 * de mise à jour automatique, ou met l'agent à jour tout de suite sans toucher à la tâche (« maj=now »).
 * L'intention est dans l'URL, pas dans un réglage stocké : le bouton dit ce qu'il fabrique. Aucun identifiant ni
 * secret.
 * Droit Déploiement en lecture ; sonde dans les entités de l'utilisateur. ZIP généré à la demande, supprimé
 * en fin de requête.
 */
include('../../../inc/includes.php');

use Glpi\Exception\Http\NotFoundHttpException;
use Symfony\Component\HttpFoundation\StreamedResponse;

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_deploiement', READ);

$agent = new Agent();
if (!$agent->getFromDB((int) ($_GET['agents_id'] ?? 0)) || !Session::haveAccessToEntity((int) $agent->fields['entities_id'])) {
    throw new NotFoundHttpException();
}
$os = (string) ($_GET['os'] ?? 'windows');
if (!in_array($os, ['windows', 'linux'], true)) {
    throw new NotFoundHttpException();
}

// Sans « maj », on ne devine pas : poser une tâche planifiée par défaut serait agir sans qu'on l'ait demandé.
$action = (string) ($_GET['maj'] ?? '');
if (!PluginPrintgestionAgentsetting::isConsigneAction($action)) {
    throw new NotFoundHttpException();
}
$package = PluginPrintgestionAgentsetting::buildConsignePackage($agent, $os, $action);
if (!$package['ok']) {
    Session::addMessageAfterRedirect(htmlspecialchars(implode(' ', $package['errors']), ENT_QUOTES, 'UTF-8'), false, ERROR);
    Html::redirect(PluginPrintgestionAgentsetting::getPageURL((int) $agent->getID()));
}

$path = $package['path'];
register_shutdown_function(static function () use ($path): void {
    if (is_file($path)) {
        unlink($path);
    }
});

return new StreamedResponse(static function () use ($path): void {
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        PluginPrintgestionLogger::error('agentsetting', sprintf('Paquet de consigne %s illisible à l\'envoi.', $path));
        return;
    }
    while (!feof($handle)) {
        echo fread($handle, 65536);
        flush();
    }
    fclose($handle);
}, 200, [
    'Content-Type'        => $package['content_type'],
    'Content-Length'      => (string) filesize($path),
    'Content-Disposition' => 'attachment; filename="' . $package['filename'] . '"',
    'Cache-Control'       => 'private, no-store',
]);
