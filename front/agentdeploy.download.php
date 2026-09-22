<?php
/**
 * Paquet d'installation GLPI Agent d'une entité (onglet « Déploiement Agent ») : Windows (ZIP), Linux (.tar.gz),
 * macOS (ZIP), et le fichier unique Windows. Droit Déploiement en lecture et accès à l'entité. Chaque téléchargement
 * est tracé dans l'historique de l'entité.
 *
 * Les paquets ne contiennent que l'URL du serveur GLPI et le TAG de l'entité. Le fichier unique, lui, porte en plus
 * une clé de téléchargement à usage unique : l'historique le dit, avec sa date de péremption.
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
// Les trois systèmes servent un fichier unique ; les archives complètes gardent leurs propres clés (recours quand
// l'antivirus d'un client refuse les scripts).
$builders = [
    'windows'     => [PluginPrintgestionAgentdeploy::class, 'buildWindowsSingleFile'],
    'linux'       => [PluginPrintgestionAgentdeploy::class, 'buildLinuxSingleFile'],
    'macos'       => [PluginPrintgestionAgentdeploy::class, 'buildMacosSingleFile'],
    'windows-retrait' => [PluginPrintgestionAgentdeploy::class, 'buildWindowsRemoval'],
    'linux-retrait'   => [PluginPrintgestionAgentdeploy::class, 'buildLinuxRemoval'],
    'macos-retrait'   => [PluginPrintgestionAgentdeploy::class, 'buildMacosRemoval'],
    'windows-zip' => [PluginPrintgestionAgentdeploy::class, 'buildWindowsPackage'],
    'linux-targz' => [PluginPrintgestionAgentdeploy::class, 'buildLinuxPackage'],
    'macos-zip'   => [PluginPrintgestionAgentdeploy::class, 'buildMacosPackage'],
];
$labels = PluginPrintgestionAgentdeploy::getPlatforms();
foreach (PluginPrintgestionAgentdeploy::ARCHIVE_OS as $platform => $os) {
    $labels[$os] = sprintf(__('%s (archive)', 'printgestion'), $labels[$platform]);
}
foreach (PluginPrintgestionAgentdeploy::REMOVE_OS as $platform => $os) {
    $labels[$os] = sprintf(__('%s (retrait de l\'agent)', 'printgestion'), $labels[$platform]);
}
$os = (string) ($_GET['os'] ?? '');
if (!isset($builders[$os])) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$package = call_user_func($builders[$os], $entity);
if (!$package['ok']) {
    // Rattachement incomplet (TAG, règle d'affectation absente ou désactivée) : déploiement bloqué, même par l'URL.
    Session::addMessageAfterRedirect(implode('<br>', array_map(
        static fn(string $error) => htmlspecialchars($error, ENT_QUOTES, 'UTF-8'),
        empty(PluginPrintgestionAgentdeploy::getDeployBlockers($entity))
            ? $package['errors']
            : [__('Configuration incomplète — le déploiement est bloqué. Les imprimantes seraient rattachées au mauvais client, sans correction possible ensuite. Contactez l\'administrateur.', 'printgestion')]
    )), false, ERROR);
    Html::redirect(Entity::getFormURLWithID($entities_id) . '&forcetab=' . urlencode('PluginPrintgestionAgentdeploy$1'));
}

Log::history($entities_id, Entity::class, [0, '', isset($package['expires'])
    ? sprintf(
        __('Installeur GLPI Agent %1$s pour %2$s téléchargé (TAG « %3$s ») — clé de récupération à usage unique, valable jusqu\'au %4$s.', 'printgestion'),
        $package['version'],
        $labels[$os],
        $package['tag'],
        Html::convDateTime((string) $package['expires'])
    )
    : sprintf(
        __('Installeur GLPI Agent %1$s pour %2$s téléchargé (TAG « %3$s »).', 'printgestion'),
        $package['version'],
        $labels[$os],
        $package['tag']
    )], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);

// Fichier unique : quelques kilo-octets de texte, rendus sans passer par un fichier temporaire.
if (isset($package['content'])) {
    return new \Symfony\Component\HttpFoundation\Response(
        (string) $package['content'],
        200,
        [
            'Content-Type'        => 'application/octet-stream',
            'Content-Length'      => (string) strlen((string) $package['content']),
            'Content-Disposition' => 'attachment; filename="' . $package['filename'] . '"',
            'Cache-Control'       => 'private, no-store',
        ]
    );
}

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
        'Content-Type'        => $os === 'linux-targz' ? 'application/gzip' : 'application/zip',
        'Content-Length'      => (string) filesize($path),
        'Content-Disposition' => 'attachment; filename="' . $package['filename'] . '"',
        'Cache-Control'       => 'private, no-store',
    ]
);
