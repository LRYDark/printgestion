<?php
/**
 * Paquet d'installation GLPI Agent d'une entité (onglet « Déploiement Agent ») : Windows (ZIP), Linux (.tar.gz),
 * macOS (ZIP). Droit Déploiement en lecture et accès à l'entité. Le paquet ne contient que l'URL du serveur GLPI et
 * le TAG de l'entité ; chaque téléchargement est tracé dans l'historique de l'entité.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_deploiement', READ);

$entities_id = (int) ($_GET['entities_id'] ?? -1);
$entity      = new Entity();
if ($entities_id < 0 || !Session::haveAccessToEntity($entities_id) || !$entity->getFromDB($entities_id)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$builders = [
    'windows' => [PluginPrintgestionAgentdeploy::class, 'buildWindowsPackage'],
    'linux'   => [PluginPrintgestionAgentdeploy::class, 'buildLinuxPackage'],
    'macos'   => [PluginPrintgestionAgentdeploy::class, 'buildMacosPackage'],
];
$os = (string) ($_GET['os'] ?? '');
if (!isset($builders[$os])) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$package = call_user_func($builders[$os], $entity);
if (!$package['ok']) {
    Session::addMessageAfterRedirect(implode('<br>', array_map(
        static fn(string $error) => htmlspecialchars($error, ENT_QUOTES, 'UTF-8'),
        $package['errors']
    )), false, ERROR);
    Html::redirect(Entity::getFormURLWithID($entities_id) . '&forcetab=' . urlencode('PluginPrintgestionAgentdeploy$1'));
}

Log::history($entities_id, Entity::class, [0, '', sprintf(
    __('Installeur GLPI Agent %1$s pour %2$s téléchargé (TAG « %3$s »).', 'printgestion'),
    $package['version'],
    PluginPrintgestionAgentdeploy::getPlatforms()[$os],
    $package['tag']
)], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);

// Fichier temporaire supprimé en fin de requête, même si le téléchargement est interrompu.
$path = $package['path'];
register_shutdown_function(static function () use ($path): void {
    if (is_file($path)) {
        unlink($path);
    }
});

return new \Symfony\Component\HttpFoundation\StreamedResponse(
    static function () use ($path): void {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            PluginPrintgestionLogger::error('agentdeploy', sprintf('Paquet %s illisible au moment de l\'envoi.', $path));
            return;
        }
        while (!feof($handle)) {
            echo fread($handle, 65536);
            flush();
        }
        fclose($handle);
    },
    200,
    [
        'Content-Type'        => $os === 'linux' ? 'application/gzip' : 'application/zip',
        'Content-Length'      => (string) filesize($path),
        'Content-Disposition' => 'attachment; filename="' . $package['filename'] . '"',
        'Cache-Control'       => 'private, no-store',
    ]
);
