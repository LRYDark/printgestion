<?php
/**
 * Récupération de l'installeur GLPI Agent par un jeton temporaire — la seule page du plugin joignable sans être
 * connecté (route déclarée dans plugin_printgestion_boot()).
 *
 * Le PC d'un client n'a aucun compte GLPI : le fichier unique d'installation vient donc chercher ici le MSI officiel
 * avec le jeton écrit dedans. Ce que le jeton ouvre : ce MSI, déjà vérifié, et rien d'autre — aucune donnée de GLPI,
 * aucune écriture, aucune session. Il est valable une seule fois et vingt-quatre heures
 * (PluginPrintgestionAgenttoken).
 *
 * Toute demande refusée répond la même chose, un 404 sans explication : un jeton inconnu, expiré ou déjà servi ne se
 * distingue pas de l'extérieur, et chaque passage — servi ou refusé — laisse une ligne dans le journal du plugin.
 */
include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

/** Adresse du demandeur, pour le journal seulement : jamais un critère d'autorisation. */
$from = (string) ($_SERVER['REMOTE_ADDR'] ?? '?');

$token = (string) ($_GET['t'] ?? '');
$entry = $token === '' ? null : PluginPrintgestionAgenttoken::consume($token);
if ($entry === null) {
    // Jeton inconnu, expiré ou déjà servi : rien ne le distingue dans la réponse, tout le distingue dans le journal.
    PluginPrintgestionLogger::warning('agentpull', sprintf('Jeton refusé (inconnu, expiré ou déjà utilisé) depuis %s.', $from));
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// Quel fichier officiel : celui demandé, s'il est bien dans la liste que cette clé ouvre. Une clé Windows ne sert
// pas le paquet macOS. Quand elle n'en ouvre qu'un, l'URL n'a pas à le nommer.
$assets = isset($entry['assets']) && is_array($entry['assets']) ? $entry['assets'] : [];
$asset  = (string) ($_GET['a'] ?? '');
if ($asset === '' && count($assets) === 1) {
    $asset = (string) array_key_first($assets);
}
if (!isset($assets[$asset]) || !is_array($assets[$asset])) {
    PluginPrintgestionLogger::warning('agentpull', sprintf(
        'Jeton valide mais fichier « %1$s » hors de ce qu\'il ouvre : rien servi à %2$s.',
        $asset,
        $from
    ));
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// Le fichier est revérifié ici, empreinte recalculée : la clé ouvre la version qu'elle nommait, pas ce qui se trouve
// dans le cache. Si l'installeur servi a changé depuis, le fichier unique ne saurait pas quoi vérifier.
$installer = PluginPrintgestionAgentdeploy::getCachedInstaller(true, $asset);
if ($installer === null
    || (string) $installer['version'] !== (string) $assets[$asset]['version']
    || !hash_equals((string) $assets[$asset]['sha256'], (string) $installer['sha256'])) {
    PluginPrintgestionLogger::warning('agentpull', sprintf(
        'Jeton valide mais installeur %1$s (%2$s) indisponible ou différent de celui promis : rien servi à %3$s.',
        (string) $assets[$asset]['version'],
        $asset,
        $from
    ));
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

PluginPrintgestionLogger::info('agentpull', sprintf(
    'Installeur GLPI Agent %1$s (%5$s) servi à %2$s (jeton de l\'entité %3$d, créé le %4$s).',
    (string) $installer['version'],
    $from,
    (int) $entry['entities_id'],
    date('Y-m-d H:i:s', (int) $entry['created_at']),
    $asset
));

// Trace dans l'historique de l'entité, là où l'administrateur regarde : la sonde est bien venue chercher l'agent,
// à telle heure. Sans auteur — personne n'était connecté, c'est la clé qui a ouvert. Le journal du plugin garde
// l'adresse ; l'historique, lui, reste lisible par le gestionnaire.
try {
    Log::history((int) $entry['entities_id'], Entity::class, [0, '', sprintf(
        __('Installeur GLPI Agent %1$s récupéré par le fichier unique (clé à usage unique, créée le %2$s).', 'printgestion'),
        (string) $installer['version'],
        Html::convDateTime(date('Y-m-d H:i:s', (int) $entry['created_at']))
    )], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
} catch (Throwable $e) {
    // Une trace manquante ne prive pas le technicien de son installeur.
    PluginPrintgestionLogger::warning('agentpull', 'Historique de l\'entité non écrit pour une récupération par clé.', $e);
}

$path = (string) $installer['path'];

return new \Symfony\Component\HttpFoundation\StreamedResponse(
    static function () use ($path): void {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            PluginPrintgestionLogger::error('agentpull', sprintf('Installeur %s illisible au moment de l\'envoi.', $path));
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
        'Content-Type'        => 'application/octet-stream',
        'Content-Length'      => (string) filesize($path),
        'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
        'Cache-Control'       => 'private, no-store',
    ]
);
