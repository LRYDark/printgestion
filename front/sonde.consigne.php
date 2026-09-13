<?php
/**
 * Paquet de consigne de mise à jour d'une sonde (Windows) : applique sur le PC le réglage « Mise à jour
 * automatique » de GLPI (pose, change ou retire la tâche planifiée). Aucun identifiant ni secret.
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

$package = PluginPrintgestionAgentsetting::buildConsignePackage($agent, $os);
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
