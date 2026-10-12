<?php
/**
 * PluginPrintgestionAgentcompat — une version de GLPI Agent change-t-elle ce dont dépendent les installeurs ?
 *
 * Pourquoi. Les installeurs du plugin (Windows, Linux, macOS) et les scripts posés sur les sondes s'appuient sur des
 * points précis de l'agent : les noms des fichiers publiés, les propriétés du MSI, les options de l'installeur Linux,
 * le paquet macOS, l'interface locale (/now, /status), la ToolBox, les clés de configuration, la sortie de
 * « glpi-agent --version ». Si une version de l'agent en change un, l'installation ne casse pas bruyamment : elle
 * échoue sur le poste du client, loin d'ici. Ce contrôle le dit AVANT de servir la version : il compare, sur GitHub,
 * la version visée à la dernière version validée, fichier par fichier, et rend un verdict par point.
 *
 * Ce qu'il vérifie :
 *   - les quatre fichiers officiels servis par le plugin, publiés sous le nom attendu, avec leur empreinte SHA-256 ;
 *   - la présence de la version dans winget (mise à jour automatique des sondes Windows) — jamais bloquant ;
 *   - les fichiers sources de chaque point (comparaison GitHub entre la version validée et la version visée).
 *
 * Verdict par point : inchangé (vert) ; dépendances seules (vert : versions d'OpenSSL ou de zlib dans un script de
 * construction) ; à surveiller (bleu : communication avec GLPI et relevé SNMP, qui se jugent sur le terrain, ou winget
 * pas encore à jour) ;
 * modifié (orange : à vérifier avant de servir) ; non vérifiable (orange : GitHub injoignable, comparaison trop
 * large) ; absent (rouge : la version ne peut pas être servie).
 *
 * Version validée : celle avec laquelle les installeurs ont été éprouvés (DEFAULT_VERSION du plugin), puis toute
 * version qu'une sonde a installée et avec laquelle elle a réussi un relevé (PluginPrintgestionAgentversion).
 *
 * Ce qu'il ne fait pas : lire le code de l'agent pour en deviner l'effet. Un fichier modifié est signalé, avec le lien
 * vers la différence ; c'est à l'administrateur de juger, puis à une première sonde de le prouver. Rien ici ne touche
 * aux scripts d'installation : ils restent ce qu'ils sont, seule la version servie est retenue ou non.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAgentcompat {

    /** Mémos dans la configuration GLPI du plugin (pas de colonne, donc pas de schéma), effacés à la désinstallation. */
    const CONTEXT       = 'plugin:printgestion';
    const KEY_VALIDATED = 'agent_validated_versions';
    const KEY_REPORTS   = 'agent_compat_reports';

    /** Rapports gardés : de quoi comparer quelques versions, pas un historique. */
    const REPORTS_KEPT = 6;

    /** Durée de validité d'un rapport ; « Vérifier » et l'enregistrement d'une nouvelle version le refont toujours. */
    const REPORT_TTL = DAY_TIMESTAMP;

    /** GitHub ne liste pas plus de 300 fichiers dans une comparaison : au-delà, le contrôle fichier par fichier ment. */
    const COMPARE_MAX_FILES = 300;

    /** Manifestes winget de GLPI Agent (dépôt officiel de winget). */
    const WINGET_REPOSITORY = 'microsoft/winget-pkgs';
    const WINGET_PATH       = 'manifests/g/GLPI-Project/GLPI-Agent';

    /** Version dont on vient de demander le contrôle depuis l'écran : la carte la montre en premier. */
    const SESSION_CANDIDATE = 'plugin_printgestion_compat_candidate';

    const STATE_OK      = 'ok';
    const STATE_DEPS    = 'deps';
    const STATE_WATCH   = 'watch';
    const STATE_CHANGED = 'changed';
    const STATE_UNKNOWN = 'unknown';
    const STATE_MISSING = 'missing';

    const VERDICT_OK      = 'ok';
    const VERDICT_WARNING = 'warning';
    const VERDICT_ERROR   = 'error';

    /** Transport HTTP remplaçable (bancs d'essai) : fn(string $url): array{status: int, body: string}. */
    public static ?Closure $transport = null;

    // ── Points surveillés ─────────────────────────────────────────────────────

    /**
     * Les points de l'agent dont dépendent les installeurs, avec les fichiers sources qui les définissent dans le
     * dépôt glpi-project/glpi-agent. 'files' : chemins exacts ; 'prefixes' : dossiers ou débuts de chemin ;
     * 'deps' : un changement limité aux versions de dépendances est reconnu ; 'watch' : signalé, jamais bloquant.
     * Les fichiers « Version.pm » (numéro de version, changé à chaque release) ne comptent jamais.
     */
    public static function points(): array {
        return [
            'msi' => [
                'label' => __('Installation Windows : propriétés du MSI', 'printgestion'),
                'sert'  => __('SERVER, TAG, ADDLOCAL (feat_AGENT, feat_NETINV), HTTPD_TRUST, SNMP_RETRIES, RUNNOW, EXECMODE et QUICKINSTALL, passés à msiexec par l\'installeur Windows ; ADDLOCAL aussi par la mise à jour winget.', 'printgestion'),
                'files' => [
                    'contrib/windows/packaging/MSI_main-v2.wxs.tt',
                    'contrib/windows/packaging/Variables-v2.wxi.tt',
                    'contrib/windows/packaging/MSI_strings.wxl.tt',
                    'contrib/windows/packaging/CustomActionDllBuildJob.pm',
                    'contrib/windows/glpi-agent-packaging.pl',
                ],
            ],
            'linux' => [
                'label'    => __('Installation Linux : installeur officiel et service', 'printgestion'),
                'sert'     => __('Options --install, --silent, --type=network, --server, --tag, --httpd-trust, --runnow et --force de l\'installeur Perl, et service glpi-agent relancé après la pose de conf.d.', 'printgestion'),
                'prefixes' => ['contrib/unix/installer/'],
                'files'    => ['contrib/unix/glpi-agent-linux-installer.pl', 'contrib/unix/make-linux-installer.sh', 'contrib/unix/glpi-agent.service'],
            ],
            'macos' => [
                'label'    => __('Installation macOS : paquet, dossiers et service', 'printgestion'),
                'sert'     => __('Paquets Apple Silicon et Intel, dossier /Applications/GLPI-Agent/etc/conf.d et service com.teclib.glpi-agent relancé par launchctl.', 'printgestion'),
                'prefixes' => ['contrib/macosx/'],
                'deps'     => true,
            ],
            'httpd' => [
                'label' => __('Interface locale de l\'agent', 'printgestion'),
                'sert'  => __('Adresses /now (réveil) et /status (attente, avant une mise à jour), port 62354 et httpd-trust : la fenêtre d\'installation et les scripts de mise à jour s\'en servent.', 'printgestion'),
                'files' => ['lib/GLPI/Agent/HTTP/Server.pm', 'lib/GLPI/Agent/HTTP/Server/Plugin.pm'],
            ],
            'toolbox' => [
                'label'    => __('ToolBox (scan local des imprimantes)', 'printgestion'),
                'sert'     => __('Format de toolbox.yaml, activation par toolbox-plugin.local et bouton « Run task » : scan des imprimantes quand GLPI Inventory manque au serveur.', 'printgestion'),
                'prefixes' => ['lib/GLPI/Agent/HTTP/Server/ToolBox'],
                'files'    => ['etc/toolbox-plugin.cfg'],
            ],
            'config' => [
                'label' => __('Configuration de l\'agent', 'printgestion'),
                'sert'  => __('Clés server, tag, tasks, httpd-trust, snmp-retries, logger, logfile et logfile-maxsize, et lecture de conf.d : réglages posés par les installeurs Linux et macOS.', 'printgestion'),
                'files' => ['lib/GLPI/Agent/Config.pm', 'etc/agent.cfg'],
            ],
            'version' => [
                'label' => __('Version affichée par l\'agent', 'printgestion'),
                'sert'  => __('Sortie de « glpi-agent --version », lue par les mises à jour Linux et macOS pour savoir s\'il y a quelque chose à faire.', 'printgestion'),
                'files' => ['bin/glpi-agent'],
            ],
            'comm' => [
                'label'    => __('Communication avec GLPI', 'printgestion'),
                'sert'     => __('Client HTTP, protocole, cible serveur et service qui planifie les contacts : c\'est par là que la sonde contacte GLPI, reçoit ses tâches et lui remet ses relevés. Un changement ici ne casse pas l\'installation ; il se juge sur le terrain (contact et premier relevé d\'une sonde mise à jour).', 'printgestion'),
                'prefixes' => [
                    'lib/GLPI/Agent/HTTP/Client',
                    'lib/GLPI/Agent/HTTP/Protocol/',
                    'lib/GLPI/Agent/Protocol/',
                    'lib/GLPI/Agent/Target',
                    'lib/GLPI/Agent/Daemon',
                ],
                'files'    => ['lib/GLPI/Agent.pm', 'lib/GLPI/Agent/HTTP/Session.pm'],
                'watch'    => true,
            ],
            'snmp' => [
                'label'    => __('Relevé SNMP des imprimantes', 'printgestion'),
                'sert'     => __('Découverte et inventaire réseau : ce que GLPI reçoit des imprimantes (adresses, consommables, compteurs). Un changement ici ne casse pas l\'installation ; il se juge sur le terrain, après la mise à jour d\'une première sonde.', 'printgestion'),
                'prefixes' => [
                    'lib/GLPI/Agent/Task/NetDiscovery',
                    'lib/GLPI/Agent/Task/NetInventory',
                    'lib/GLPI/Agent/SNMP',
                    'lib/GLPI/Agent/Tools/Hardware',
                    'lib/GLPI/Agent/Tools/SNMP',
                ],
                'watch'    => true,
            ],
        ];
    }

    /** Libellés de tous les points d'un rapport, ceux des fichiers publiés et de winget compris. */
    public static function labels(): array {
        $labels = [
            'assets' => __('Fichiers officiels servis par le plugin', 'printgestion'),
            'winget' => __('Mise à jour automatique Windows (winget)', 'printgestion'),
        ];
        foreach (self::points() as $key => $point) {
            $labels[$key] = $point['label'];
        }
        return $labels;
    }

    // ── Versions validées ─────────────────────────────────────────────────────

    /** Versions validées, de la plus ancienne à la plus récente ; jamais vide (version connue du plugin). */
    public static function getValidatedVersions(): array {
        $raw  = Config::getConfigurationValue(self::CONTEXT, self::KEY_VALIDATED);
        $list = json_decode((string) $raw, true);
        $list = is_array($list) ? array_values(array_filter($list, static fn($v) => is_string($v) && PluginPrintgestionAgentsetting::isValidVersion($v))) : [];
        $list[] = PluginPrintgestionAgentdeploy::DEFAULT_VERSION;
        $list   = array_values(array_unique($list));
        usort($list, 'version_compare');
        return $list;
    }

    public static function isValidated(string $version): bool {
        return in_array($version, self::getValidatedVersions(), true);
    }

    /** Ajoute une version aux versions validées ($how : ce qui la valide, pour le journal). Vrai si elle est nouvelle. */
    public static function markValidated(string $version, string $how): bool {
        if (!PluginPrintgestionAgentsetting::isValidVersion($version) || self::isValidated($version)) {
            return false;
        }
        $list   = self::getValidatedVersions();
        $list[] = $version;
        usort($list, 'version_compare');
        Config::setConfigurationValues(self::CONTEXT, [self::KEY_VALIDATED => json_encode(array_values($list))]);
        PluginPrintgestionLogger::info('agentcompat', sprintf('GLPI Agent %1$s validée : %2$s.', $version, $how));
        return true;
    }

    /** Version validée à laquelle comparer : la plus récente qui ne dépasse pas la version visée, sinon la plus proche au-dessus. */
    public static function getReference(string $version): string {
        $validated = self::getValidatedVersions();
        $below     = array_values(array_filter($validated, static fn(string $v) => version_compare($v, $version, '<=')));
        return $below !== [] ? end($below) : $validated[0];
    }

    // ── Rapports ──────────────────────────────────────────────────────────────

    /** @return array version => rapport */
    public static function getReports(): array {
        $reports = json_decode((string) Config::getConfigurationValue(self::CONTEXT, self::KEY_REPORTS), true);
        return is_array($reports) ? array_filter($reports, static fn($r) => is_array($r) && isset($r['version'], $r['verdict'], $r['points'])) : [];
    }

    public static function getReport(string $version): ?array {
        return self::getReports()[$version] ?? null;
    }

    /** Le rapport qu'on vient de faire est toujours gardé ; des autres, les plus récents. */
    private static function storeReport(array $report): void {
        $reports = self::getReports();
        unset($reports[$report['version']]);
        uasort($reports, static fn(array $a, array $b) => strcmp((string) $b['checked_at'], (string) $a['checked_at']));
        $reports = [$report['version'] => $report] + array_slice($reports, 0, self::REPORTS_KEPT - 1, true);
        Config::setConfigurationValues(self::CONTEXT, [
            self::KEY_REPORTS => json_encode($reports, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    // ── Contrôle ──────────────────────────────────────────────────────────────

    /**
     * Contrôle d'une version, gardé un jour ($refresh : refait quoi qu'il arrive). Ne lève jamais d'exception : un
     * GitHub injoignable rend des points « non vérifiables », pas une page d'erreur.
     *
     * @return array ['version', 'reference', 'checked_at', 'verdict', 'compare_url', 'points' => [clé => ['state', 'files', 'note']]]
     */
    public static function check(string $version, bool $refresh = false): array {
        $version   = trim($version);
        $reference = PluginPrintgestionAgentsetting::isValidVersion($version) ? self::getReference($version) : '';
        if (!$refresh) {
            $cached = self::getReport($version);
            // Rapport gardé : même référence, du jour, et tous les points d'aujourd'hui (un point ajouté depuis le
            // rapport le rend incomplet : il est refait).
            if ($cached !== null && ($cached['reference'] ?? '') === $reference
                && strtotime((string) $cached['checked_at']) > time() - self::REPORT_TTL
                && array_diff(array_keys(self::points()), array_keys((array) $cached['points'])) === []) {
                return $cached;
            }
        }

        $report = [
            'version'     => $version,
            'reference'   => $reference,
            'checked_at'  => date('Y-m-d H:i:s'),
            'verdict'     => self::VERDICT_ERROR,
            'compare_url' => '',
            'points'      => [],
        ];
        if ($reference === '') {
            $report['points']['assets'] = ['state' => self::STATE_MISSING, 'files' => [], 'note' => sprintf(__('Version « %s » invalide.', 'printgestion'), $version)];
            return $report;
        }

        $report['points']['assets'] = self::checkAssets($version);
        $report['points']['winget'] = self::checkWinget($version);
        foreach (self::checkCode($version, $reference, $report['compare_url']) as $key => $point) {
            $report['points'][$key] = $point;
        }
        $report['verdict'] = self::verdict($report['points']);

        try {
            self::storeReport($report);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('agentcompat', sprintf('Rapport de compatibilité de GLPI Agent %s non enregistré.', $version), $e);
        }
        $detail = [];
        foreach ($report['points'] as $key => $point) {
            $detail[] = $key . '=' . $point['state'] . (empty($point['files']) ? '' : ' (' . implode(', ', array_slice($point['files'], 0, 5)) . ')');
        }
        $line = self::summary($report) . ' — ' . implode(' ; ', $detail);
        if ($report['verdict'] === self::VERDICT_OK) {
            PluginPrintgestionLogger::info('agentcompat', $line);
        } else {
            PluginPrintgestionLogger::warning('agentcompat', $line);
        }
        return $report;
    }

    /** Les quatre fichiers officiels du plugin, sous le nom attendu et avec leur empreinte SHA-256 publiée. */
    private static function checkAssets(string $version): array {
        $url = sprintf('https://api.github.com/repos/%s/releases/tags/%s', PluginPrintgestionAgentdeploy::REPOSITORY, rawurlencode($version));
        try {
            $response = self::get($url);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::warning('agentcompat', sprintf('Release GLPI Agent %s non lue sur GitHub.', $version), $e);
            return ['state' => self::STATE_UNKNOWN, 'files' => [], 'note' => __('GitHub injoignable depuis le serveur : fichiers publiés non vérifiés.', 'printgestion')];
        }
        if ($response['status'] === 404) {
            return ['state' => self::STATE_MISSING, 'files' => [], 'note' => sprintf(__('Aucune release %s sur GitHub.', 'printgestion'), $version)];
        }
        $release = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($release)) {
            return ['state' => self::STATE_UNKNOWN, 'files' => [], 'note' => sprintf(__('Réponse inattendue de GitHub (HTTP %d) : fichiers publiés non vérifiés.', 'printgestion'), $response['status'])];
        }
        if (!empty($release['draft']) || !empty($release['prerelease'])) {
            return ['state' => self::STATE_CHANGED, 'files' => [], 'note' => __('Préversion ou brouillon : pas une version publiée.', 'printgestion')];
        }
        $published = [];
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (is_array($asset) && isset($asset['name'])) {
                $published[(string) $asset['name']] = (string) ($asset['digest'] ?? '');
            }
        }
        $missing = [];
        foreach (PluginPrintgestionAgentdeploy::getAssets($version) as $spec) {
            if (!isset($published[$spec['file']])) {
                $missing[] = $spec['file'];
            } elseif (!preg_match('/^sha256:[0-9a-f]{64}$/', $published[$spec['file']])) {
                $missing[] = sprintf(__('%s (sans empreinte SHA-256)', 'printgestion'), $spec['file']);
            }
        }
        if ($missing !== []) {
            return ['state' => self::STATE_MISSING, 'files' => $missing, 'note' => __('Fichiers absents de la release ou publiés sans empreinte : le paquet de l\'entité ne peut pas être construit.', 'printgestion')];
        }
        return ['state' => self::STATE_OK, 'files' => [], 'note' => __('Les quatre fichiers sont publiés sous le nom attendu, avec leur empreinte SHA-256.', 'printgestion')];
    }

    /** La version dans winget : sans elle, la mise à jour automatique Windows vers cette version attend (jamais bloquant). */
    private static function checkWinget(string $version): array {
        $url = sprintf('https://api.github.com/repos/%s/contents/%s/%s', self::WINGET_REPOSITORY, self::WINGET_PATH, rawurlencode($version));
        try {
            $response = self::get($url);
        } catch (Throwable $e) {
            return ['state' => self::STATE_WATCH, 'files' => [], 'note' => __('Non vérifié (GitHub injoignable). Seule la mise à jour automatique des sondes Windows en dépend, pas l\'installation.', 'printgestion')];
        }
        if ($response['status'] === 200) {
            return ['state' => self::STATE_OK, 'files' => [], 'note' => __('Disponible dans winget.', 'printgestion')];
        }
        return ['state' => self::STATE_WATCH, 'files' => [], 'note' => __('Pas encore dans winget : la mise à jour automatique des sondes Windows vers cette version attendra son arrivée. L\'installation, elle, n\'en dépend pas.', 'printgestion')];
    }

    /**
     * Fichiers sources de chaque point, comparés entre la version validée et la version visée.
     *
     * @param string $compare_url rempli avec l'adresse de la comparaison sur GitHub
     * @return array clé => ['state', 'files', 'note']
     */
    private static function checkCode(string $version, string $reference, string &$compare_url): array {
        $points = self::points();
        $out    = [];
        if ($version === $reference) {
            foreach (array_keys($points) as $key) {
                $out[$key] = ['state' => self::STATE_OK, 'files' => [], 'note' => __('Version validée elle-même.', 'printgestion')];
            }
            return $out;
        }

        [$low, $high] = version_compare($reference, $version, '<') ? [$reference, $version] : [$version, $reference];
        $compare_url  = sprintf('https://github.com/%s/compare/%s...%s', PluginPrintgestionAgentdeploy::REPOSITORY, $low, $high);
        $unknown      = static function (string $note) use ($points): array {
            return array_map(static fn() => ['state' => self::STATE_UNKNOWN, 'files' => [], 'note' => $note], $points);
        };
        try {
            $response = self::get(sprintf('https://api.github.com/repos/%s/compare/%s...%s', PluginPrintgestionAgentdeploy::REPOSITORY, rawurlencode($low), rawurlencode($high)));
        } catch (Throwable $e) {
            PluginPrintgestionLogger::warning('agentcompat', sprintf('Comparaison GLPI Agent %1$s…%2$s non lue sur GitHub.', $low, $high), $e);
            return $unknown(__('GitHub injoignable depuis le serveur : code de l\'agent non comparé.', 'printgestion'));
        }
        $compare = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($compare) || !isset($compare['files']) || !is_array($compare['files'])) {
            return $unknown(sprintf(__('Réponse inattendue de GitHub (HTTP %d) : code de l\'agent non comparé.', 'printgestion'), $response['status']));
        }
        if (count($compare['files']) >= self::COMPARE_MAX_FILES) {
            return $unknown(sprintf(__('Plus de %d fichiers modifiés : trop pour un contrôle fichier par fichier. Tester sur une sonde avant de servir.', 'printgestion'), self::COMPARE_MAX_FILES));
        }

        $changed = [];
        foreach ($compare['files'] as $file) {
            if (is_array($file) && isset($file['filename'])) {
                $changed[(string) $file['filename']] = isset($file['patch']) ? (string) $file['patch'] : null;
            }
        }
        // Fichiers gardés par point dans le rapport : le compte suffit au-delà, le lien mène à la différence complète.
        $kept = 50;
        foreach ($points as $key => $point) {
            $matched = [];
            foreach ($changed as $filename => $patch) {
                if (str_ends_with($filename, '/Version.pm')) {
                    continue;
                }
                $hit = in_array($filename, $point['files'] ?? [], true);
                foreach ($point['prefixes'] ?? [] as $prefix) {
                    $hit = $hit || str_starts_with($filename, $prefix);
                }
                if ($hit) {
                    $matched[$filename] = $patch;
                }
            }
            $files = array_slice(array_keys($matched), 0, $kept);
            if ($matched === []) {
                $out[$key] = ['state' => self::STATE_OK, 'files' => [], 'note' => sprintf(__('Inchangé depuis %s.', 'printgestion'), $reference)];
            } elseif (!empty($point['watch'])) {
                $out[$key] = ['state' => self::STATE_WATCH, 'files' => $files, 'note' => __('Modifié : sans effet sur l\'installation, à confirmer par le relevé d\'une première sonde mise à jour.', 'printgestion')];
            } elseif (!empty($point['deps']) && array_filter($matched, static fn(?string $patch) => !self::isDependencyOnlyPatch($patch)) === []) {
                $out[$key] = ['state' => self::STATE_DEPS, 'files' => $files, 'note' => __('Seules des versions de dépendances (OpenSSL, zlib…) ont changé.', 'printgestion')];
            } else {
                $out[$key] = ['state' => self::STATE_CHANGED, 'files' => $files, 'note' => __('Modifié : lire la différence avant de servir cette version.', 'printgestion')];
            }
        }
        return $out;
    }

    /**
     * Différence limitée à des versions de dépendances dans un script de construction, de la forme
     * « : ${OPENSSL_VERSION:=3.5.9} » ou « ZLIB_SHA256=… ». Différence absente (fichier binaire, trop grand) : non.
     */
    public static function isDependencyOnlyPatch(?string $patch): bool {
        if ($patch === null || trim($patch) === '') {
            return false;
        }
        $seen = false;
        foreach (preg_split('/\R/', $patch) as $line) {
            if ($line === '' || ($line[0] !== '+' && $line[0] !== '-') || str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }
            $code = trim(substr($line, 1));
            if ($code === '' || str_starts_with($code, '#')) {
                continue;
            }
            if (!preg_match('/^:\s*\$\{[A-Z0-9_]*(VERSION|SHA256)[A-Z0-9_]*:?=[^}]*\}$/', $code)
                && !preg_match('/^[A-Z0-9_]*(VERSION|SHA256)[A-Z0-9_]*=\S+$/', $code)) {
                return false;
            }
            $seen = true;
        }
        return $seen;
    }

    private static function verdict(array $points): string {
        $states = array_column($points, 'state');
        if (in_array(self::STATE_MISSING, $states, true)) {
            return self::VERDICT_ERROR;
        }
        if (in_array(self::STATE_CHANGED, $states, true) || in_array(self::STATE_UNKNOWN, $states, true)) {
            return self::VERDICT_WARNING;
        }
        return self::VERDICT_OK;
    }

    /** Une phrase pour le rapport : celle du message, du journal et de la carte de santé. */
    public static function summary(array $report): string {
        $labels  = self::labels();
        $pick    = static function (array $states) use ($report, $labels): array {
            $out = [];
            foreach ($report['points'] as $key => $point) {
                if (in_array($point['state'], $states, true)) {
                    $out[] = $labels[$key] ?? $key;
                }
            }
            return $out;
        };
        $version = (string) $report['version'];
        if ($report['verdict'] === self::VERDICT_ERROR) {
            return sprintf(__('GLPI Agent %1$s ne peut pas être servie : %2$s.', 'printgestion'), $version, implode(', ', $pick([self::STATE_MISSING])));
        }
        if ($report['verdict'] === self::VERDICT_WARNING) {
            $points = $pick([self::STATE_CHANGED, self::STATE_UNKNOWN]);
            return sprintf(
                _n('GLPI Agent %1$s : %2$d point à vérifier avant de la servir (%3$s), comparée à %4$s.', 'GLPI Agent %1$s : %2$d points à vérifier avant de la servir (%3$s), comparée à %4$s.', count($points), 'printgestion'),
                $version,
                count($points),
                implode(', ', $points),
                (string) $report['reference']
            );
        }
        $watch = $pick([self::STATE_WATCH]);
        return sprintf(__('GLPI Agent %1$s : compatible avec les installeurs, aucun point critique modifié depuis %2$s.', 'printgestion'), $version, (string) $report['reference'])
            . ($watch === [] ? '' : ' ' . sprintf(__('À surveiller après mise à jour : %s.', 'printgestion'), implode(', ', $watch)));
    }

    // ── Décisions de l'écran « Installeur » ───────────────────────────────────

    /**
     * Avant de servir une autre version : contrôle frais, puis décision. Une version validée passe sans contrôle.
     *
     * @return array ['allowed' => bool, 'message' => string, 'level' => INFO|WARNING|ERROR]
     */
    public static function gate(string $version): array {
        if (self::isValidated($version)) {
            return ['allowed' => true, 'message' => '', 'level' => INFO];
        }
        $_SESSION[self::SESSION_CANDIDATE] = $version;
        $report = self::check($version, true);
        $where  = __('Détail dans la carte « Compatibilité de GLPI Agent avec les installeurs » de cette page.', 'printgestion');
        if ($report['verdict'] === self::VERDICT_ERROR) {
            return ['allowed' => false, 'level' => ERROR, 'message' => self::summary($report) . ' ' . __('Version servie inchangée.', 'printgestion') . ' ' . $where];
        }
        if ($report['verdict'] === self::VERDICT_WARNING) {
            return ['allowed' => false, 'level' => WARNING, 'message' => self::summary($report) . ' '
                . sprintf(__('Version servie inchangée : après lecture des différences, « Servir GLPI Agent %s quand même » dans la carte de compatibilité.', 'printgestion'), $version) . ' ' . $where];
        }
        return ['allowed' => true, 'level' => INFO, 'message' => self::summary($report)];
    }

    /**
     * « Servir quand même » : version à points à vérifier, servie sur décision de l'administrateur. Jamais une
     * version à fichier absent : son paquet ne pourrait pas être construit.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function serveAnyway(string $version): array {
        $version = trim($version);
        if (!PluginPrintgestionAgentsetting::isValidVersion($version)) {
            return ['ok' => false, 'message' => __('Version invalide (exemple : 1.20).', 'printgestion')];
        }
        $report = self::check($version);
        if ($report['verdict'] === self::VERDICT_ERROR) {
            return ['ok' => false, 'message' => self::summary($report)];
        }
        $config = PluginPrintgestionConfig::getInstance();
        if (!$config->update(['id' => (int) $config->getID(), 'agent_version' => $version])) {
            return ['ok' => false, 'message' => __('Paramètres de l\'installeur non enregistrés.', 'printgestion')];
        }
        PluginPrintgestionLogger::warning('agentcompat', sprintf(
            'GLPI Agent %1$s servie sans validation par l\'utilisateur %2$d : %3$s',
            $version,
            (int) Session::getLoginUserID(),
            self::summary($report)
        ));
        return ['ok' => true, 'message' => sprintf(
            __('GLPI Agent %s servie sur votre décision. Récupérez ses fichiers ci-dessus, puis installez-la d\'abord sur une seule sonde : sa remontée est suivie dans « Sur le terrain » de la carte de compatibilité.', 'printgestion'),
            $version
        )];
    }

    /**
     * Dernière version publiée, plus récente que la version servie et pas encore validée : son contrôle (gardé un
     * jour, sauf $refresh). Chaîne vide s'il n'y a rien à contrôler.
     */
    public static function checkLatest(bool $refresh = false): string {
        $latest = (string) PluginPrintgestionAgentsetting::getLatestVersion()['version'];
        if (!PluginPrintgestionAgentsetting::isValidVersion($latest) || self::isValidated($latest)
            || version_compare($latest, PluginPrintgestionAgentdeploy::getServedVersion(), '<=')) {
            return '';
        }
        return self::summary(self::check($latest, $refresh));
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    private static function stateBadge(string $state): string {
        [$class, $label] = match ($state) {
            self::STATE_OK      => ['bg-green text-green-fg', __('Inchangé', 'printgestion')],
            self::STATE_DEPS    => ['bg-green text-green-fg', __('Dépendances seules', 'printgestion')],
            self::STATE_WATCH   => ['bg-blue text-blue-fg', __('À surveiller', 'printgestion')],
            self::STATE_CHANGED => ['bg-orange text-orange-fg', __('Modifié — à vérifier', 'printgestion')],
            self::STATE_UNKNOWN => ['bg-orange text-orange-fg', __('Non vérifiable', 'printgestion')],
            default             => ['bg-red text-red-fg', __('Absent', 'printgestion')],
        };
        return "<span class='badge {$class}'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</span>";
    }

    /** Tableau natif des points d'un rapport. */
    private static function renderReport(array $report): string {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $labels  = self::labels();
        $points  = self::points();
        $entries = [];
        foreach ($report['points'] as $key => $point) {
            $files = array_map(static fn(string $f) => '<code>' . $esc($f) . '</code>', array_slice((array) $point['files'], 0, 12));
            if (count((array) $point['files']) > 12) {
                $files[] = $esc(sprintf(__('et %d autres', 'printgestion'), count((array) $point['files']) - 12));
            }
            $entries[] = [
                'point'  => $labels[$key] ?? $key,
                'state'  => self::stateBadge((string) $point['state']),
                'sert'   => $points[$key]['sert'] ?? '',
                'detail' => $esc($point['note']) . ($files === [] ? '' : '<br>' . implode(', ', $files)),
            ];
        }
        $html = PluginPrintgestionUi::datatable([
            'point'  => __('Point', 'printgestion'),
            'state'  => __('État', 'printgestion'),
            'sert'   => __('Ce qui en dépend', 'printgestion'),
            'detail' => __('Détail', 'printgestion'),
        ], $entries, ['state' => 'raw_html', 'detail' => 'raw_html']);
        $html .= "<p class='small text-muted mb-0'>" . $esc(sprintf(
            __('Contrôlée le %1$s, comparée à GLPI Agent %2$s (version validée).', 'printgestion'),
            Html::convDateTime((string) $report['checked_at']),
            (string) $report['reference']
        ));
        if (!empty($report['compare_url'])) {
            $html .= " <a href='" . $esc($report['compare_url']) . "' target='_blank' rel='noopener noreferrer'>" . $esc(__('Différences sur GitHub', 'printgestion')) . "</a>";
        }
        return $html . "</p>";
    }

    private static function button(string $name, string $version, string $label, string $icon, string $class): string {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        return "<form method='post' action='" . $esc(PluginPrintgestionAgentdeploy::getPageURL()) . "' class='d-inline me-2'>"
            . "<button type='submit' data-pg-submit-once='1' name='" . $esc($name) . "' value='" . $esc($version) . "' class='btn btn-sm {$class}'>"
            . "<i class='ti {$icon} me-1'></i>" . $esc($label) . "</button>"
            . Html::closeForm(false);
    }

    /**
     * Contenu de la carte « Compatibilité de GLPI Agent avec les installeurs » de la page « Installeur ». Ne joint
     * jamais GitHub : rapports gardés seulement (les boutons, la tâche hebdomadaire et l'enregistrement les font).
     * Une erreur ici n'empêche jamais la page de s'afficher : la carte le dit, le journal donne le détail.
     */
    public static function renderCard(string $served, string $latest, bool $can_edit): string {
        try {
            return self::buildCard($served, $latest, $can_edit);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('agentcompat', 'Carte de compatibilité de GLPI Agent non affichée.', $e);
            return PluginPrintgestionUi::statusLine('error', __('Compatibilité de GLPI Agent non affichée (détail dans le journal printgestion).', 'printgestion'));
        }
    }

    private static function buildCard(string $served, string $latest, bool $can_edit): string {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = "<p class='small text-muted'>" . $esc(__('Avant de servir une autre version de GLPI Agent, le plugin compare sur GitHub ce dont dépendent ses installeurs (fichiers publiés, propriétés du MSI, installeur Linux, paquet macOS, interface locale, ToolBox, configuration) à la dernière version validée. Une version validée est celle avec laquelle les installeurs ont été éprouvés, ou qu\'une sonde a installée avant de réussir un relevé. Les installeurs eux-mêmes ne changent jamais.', 'printgestion')) . "</p>";
        $html .= "<p>" . $esc(sprintf(__('Versions validées : %s', 'printgestion'), implode(', ', self::getValidatedVersions()))) . "</p>";

        $candidate = (string) ($_SESSION[self::SESSION_CANDIDATE] ?? '');
        $versions  = [];
        foreach ([$candidate, $served, $latest] as $version) {
            if (PluginPrintgestionAgentsetting::isValidVersion($version) && !self::isValidated($version) && !in_array($version, $versions, true)) {
                if ($version === $latest && $version !== $candidate && $version !== $served && version_compare($latest, $served, '<=')) {
                    continue;
                }
                $versions[] = $version;
            }
        }
        if ($versions === []) {
            $html .= PluginPrintgestionUi::statusLine('ok', sprintf(__('GLPI Agent %s servie : version validée.', 'printgestion'), $served));
        }
        foreach ($versions as $version) {
            $report = self::getReport($version);
            if ($report === null) {
                $html .= PluginPrintgestionUi::statusLine('info', sprintf(__('GLPI Agent %s : compatibilité pas encore vérifiée.', 'printgestion'), $version));
            } else {
                $level = match ($report['verdict']) {
                    self::VERDICT_OK      => 'ok',
                    self::VERDICT_WARNING => 'warning',
                    default               => 'error',
                };
                $html .= PluginPrintgestionUi::statusLine($level, self::summary($report)
                    . ($version === $served ? ' ' . __('(version servie)', 'printgestion') : ''), self::renderReport($report));
            }
            if ($can_edit) {
                $html .= "<div class='mb-3'>" . self::button('compat_check', $version, sprintf(__('Vérifier la compatibilité de GLPI Agent %s', 'printgestion'), $version), 'ti-shield-check', 'btn-outline-primary');
                if ($report !== null && $report['verdict'] === self::VERDICT_WARNING && $version !== $served) {
                    $html .= self::button('compat_serve', $version, sprintf(__('Servir GLPI Agent %s quand même', 'printgestion'), $version), 'ti-alert-triangle', 'btn-outline-warning');
                }
                $html .= "</div>";
            }
        }

        $html .= "<h4 class='mt-3'>" . $esc(__('Sur le terrain', 'printgestion')) . "</h4>";
        $html .= PluginPrintgestionAgentversion::renderField();
        return $html;
    }

    /**
     * Ligne de la carte « Santé de la configuration » : sondes muettes depuis leur mise à jour, version servie non
     * validée, nouvelle version publiée et son contrôle. Null sans le module Déploiement. Ne joint jamais GitHub.
     */
    public static function getHealthCheck(): ?array {
        if (!PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
            return null;
        }
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $served = PluginPrintgestionAgentdeploy::getServedVersion();
        $latest = (string) PluginPrintgestionAgentsetting::getLatestVersion()['version'];
        $silent = PluginPrintgestionAgentversion::getChanges('silent');
        $lines  = [];
        foreach ($silent as $row) {
            $lines[] = $esc(PluginPrintgestionAgentversion::describe($row));
        }

        if ($silent !== []) {
            $state  = PluginPrintgestionConfighealth::STATE_ERROR;
            $status = sprintf(
                _n('%d sonde ne relève plus depuis son passage à une autre version de GLPI Agent.', '%d sondes ne relèvent plus depuis leur passage à une autre version de GLPI Agent.', count($silent), 'printgestion'),
                count($silent)
            );
        } elseif (!self::isValidated($served)) {
            $state  = PluginPrintgestionConfighealth::STATE_PENDING;
            $status = sprintf(__('GLPI Agent %s servie sans être validée : en attente du relevé réussi d\'une sonde dans cette version.', 'printgestion'), $served);
        } elseif (PluginPrintgestionAgentsetting::isValidVersion($latest) && version_compare($latest, $served, '>') && !self::isValidated($latest)) {
            $report = self::getReport($latest);
            $state  = PluginPrintgestionConfighealth::STATE_INFO;
            $status = $report === null
                ? sprintf(__('GLPI Agent %s publiée : compatibilité pas encore vérifiée (bouton dans la page « Installeur »).', 'printgestion'), $latest)
                : self::summary($report);
        } else {
            $state  = PluginPrintgestionConfighealth::STATE_OK;
            $status = sprintf(__('GLPI Agent %s servie : version validée.', 'printgestion'), $served);
        }
        return [
            'key'    => 'agent_compat',
            'group'  => 'recommended',
            'label'  => __('Versions de GLPI Agent : compatibilité et sondes mises à jour', 'printgestion'),
            'state'  => $state,
            'status' => $status,
            'breaks' => __('Une version d\'agent qui change ce dont dépendent les installeurs ferait échouer l\'installation ou la mise à jour des sondes sur le poste du client ; une sonde qui ne relève plus après sa mise à jour laisse ses imprimantes sans alerte toner.', 'printgestion'),
            'fix'    => __('Collecte SNMP / Déploiement Agent → Installeur : carte « Compatibilité de GLPI Agent avec les installeurs »', 'printgestion'),
            'url'    => PluginPrintgestionAgentdeploy::getPageURL(),
            'detail' => implode('<br>', $lines),
        ];
    }

    // ── Transport ─────────────────────────────────────────────────────────────

    /** GET sur l'API de GitHub, proxy de GLPI compris ; les codes d'erreur HTTP sont rendus, pas levés. */
    private static function get(string $url): array {
        if (self::$transport !== null) {
            return (self::$transport)($url);
        }
        $client   = PluginPrintgestionHttp::guzzle(['timeout' => 60, 'connect_timeout' => 10]);
        $response = $client->request('GET', $url, [
            'headers'     => ['User-Agent' => 'GLPI-printgestion', 'Accept' => 'application/vnd.github+json'],
            'http_errors' => false,
        ]);
        return ['status' => (int) $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }
}
