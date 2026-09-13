<?php
/**
 * PluginPrintgestionAgentsetting — sondes GLPI Agent : conformité de version, mise à jour automatique,
 * imprimantes collectées et statut du PC sonde (module Collecte SNMP / Déploiement Agent, phase 5).
 *
 * - Dernière version connue de GLPI Agent : vérifiée chaque semaine sur GitHub (tâche automatique) quand le
 *   serveur y accède, ou saisie à la main ; à défaut, version de l'installeur servi par le plugin.
 * - Réglages par sonde : « Mise à jour automatique » (cochée par défaut) et « Version cible » (vide : dernière
 *   connue ; une valeur : épinglage, qui couvre le retour arrière).
 * - Le plugin ne pousse aucune mise à jour. La tâche qui la fait (Windows : tâche planifiée winget, compte
 *   SYSTEM ; Linux : tâche cron, installeur officiel vérifié) est posée sur le PC par le paquet d'installation
 *   selon les réglages par défaut, puis changée par la consigne de la sonde, lancée sur le PC. Sans la tâche Deploy
 *   (exclue) ni jeton (exclu), GLPI ne peut pas la changer à distance : décocher la case ne retire pas une tâche
 *   déjà posée. macOS : mise à jour manuelle, aucun mécanisme officiel.
 * - Statut GLPI du PC sonde, choisi par l'administrateur, pour distinguer la sonde du parc du client.
 * - Onglet sur la fiche Agent native (seulement ce qui y manque) et page « Sondes » du module, pour les
 *   techniciens qui n'ont pas le droit Agent.
 * Lecture : droit Déploiement ; réglages d'une sonde et statut de son PC : Déploiement en modification ; dernière
 * version, réglages par défaut des paquets et statut « PC sonde » : droit de configuration du plugin.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAgentsetting extends CommonDBTM {

    static $rightname = 'plugin_printgestion_deploiement';

    /** Identifiant winget de GLPI Agent (manifeste officiel de winget-pkgs). */
    const WINGET_ID = 'GLPI-Project.GLPI-Agent';

    /** Tâche planifiée posée sur le PC sonde. */
    const TASK_NAME = 'GLPI Agent - mise a jour (Print Gestion)';

    /** Script de mise à jour posé sur le PC sonde, dans %ProgramData%\PrintGestion. */
    const UPDATE_SCRIPT = 'glpi-agent-update.cmd';

    /** Tâche cron mensuelle posée sur le PC sonde Linux, et son journal. */
    const LINUX_CRON = '/etc/cron.monthly/glpi-agent-printgestion';
    const LINUX_LOG  = '/var/log/glpi-agent-printgestion-update.log';

    static function getTypeName($nb = 0) {
        return _n('Sonde', 'Sondes', $nb, 'printgestion');
    }

    public static function getPageURL(?int $agents_id = null): string {
        return PLUGIN_PRINTGESTION_WEBDIR . '/front/sondes.php' . ($agents_id !== null ? '?id=' . $agents_id : '');
    }

    public static function getConsigneURL(int $agents_id, string $os = 'windows'): string {
        return PLUGIN_PRINTGESTION_WEBDIR . '/front/sonde.consigne.php?agents_id=' . $agents_id . '&os=' . rawurlencode($os);
    }

    private static function now(): string {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    // ── Dernière version connue ───────────────────────────────────────────────

    /**
     * Dernière version connue : vérifiée sur GitHub ou saisie à la main ; à défaut, version de l'installeur servi.
     *
     * @return array ['version' => string, 'source' => github|manual|installer, 'checked' => ?string]
     */
    public static function getLatestVersion(): array {
        $fields  = PluginPrintgestionConfig::getInstance()->fields;
        $version = trim((string) ($fields['agent_latest_version'] ?? ''));
        $source  = trim((string) ($fields['agent_latest_source'] ?? ''));
        if ($version === '' || !in_array($source, ['github', 'manual'], true)) {
            return ['version' => PluginPrintgestionAgentdeploy::getServedVersion(), 'source' => 'installer', 'checked' => $fields['agent_latest_checked'] ?? null];
        }
        return ['version' => $version, 'source' => $source, 'checked' => $fields['agent_latest_checked'] ?? null];
    }

    private static function getLatestSourceLabel(array $latest): string {
        return match ($latest['source']) {
            'manual' => __('saisie à la main', 'printgestion'),
            'github' => sprintf(__('vérifiée sur GitHub le %s', 'printgestion'), Html::convDateTime((string) $latest['checked'])),
            default  => __('version de l\'installeur servi, faute de vérification sur GitHub ou de saisie', 'printgestion'),
        };
    }

    public static function isValidVersion(string $version): bool {
        return (bool) preg_match('/^\d+\.\d+(\.\d+)?$/', $version);
    }

    /**
     * Dernière release publiée de GLPI Agent sur GitHub (ni brouillon ni préversion). Enregistrée, sauf si une
     * version a été saisie à la main : elle est alors seulement signalée.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function checkLatestFromGitHub(): array {
        try {
            $client   = Toolbox::getGuzzleClient(['timeout' => 30, 'connect_timeout' => 10]);
            $response = $client->request('GET', sprintf('https://api.github.com/repos/%s/releases/latest', PluginPrintgestionAgentdeploy::REPOSITORY), [
                'headers' => ['User-Agent' => 'GLPI-printgestion', 'Accept' => 'application/vnd.github+json'],
            ]);
            $release  = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('agentsetting', 'Dernière version de GLPI Agent non lue sur GitHub.', $e);
            return ['ok' => false, 'message' => __('GitHub injoignable depuis le serveur GLPI (détail dans le journal printgestion) : saisissez la dernière version à la main.', 'printgestion')];
        }
        $version = ltrim(trim((string) ($release['tag_name'] ?? '')), 'v');
        if (!self::isValidVersion($version) || !empty($release['prerelease']) || !empty($release['draft'])) {
            return ['ok' => false, 'message' => sprintf(__('Réponse inattendue de GitHub (version « %s ») : rien n\'est enregistré.', 'printgestion'), $version)];
        }

        $latest = self::getLatestVersion();
        $config = PluginPrintgestionConfig::getInstance();
        $input  = ['id' => (int) $config->getID(), 'agent_latest_checked' => self::now()];
        if ($latest['source'] !== 'manual') {
            $input['agent_latest_version'] = $version;
            $input['agent_latest_source']  = 'github';
        }
        if (!$config->update($input)) {
            return ['ok' => false, 'message' => __('Dernière version non enregistrée.', 'printgestion')];
        }
        return ['ok' => true, 'message' => $latest['source'] === 'manual'
            ? sprintf(__('Dernière version publiée sur GitHub : %1$s. La version saisie à la main (%2$s) est gardée.', 'printgestion'), $version, $latest['version'])
            : sprintf(__('Dernière version de GLPI Agent : %s (GitHub).', 'printgestion'), $version)];
    }

    /**
     * Réglages de la page « Installeur » : dernière version saisie à la main (vide : GitHub), mise à jour
     * automatique et version cible des nouveaux paquets, statut GLPI des PC sondes.
     *
     * @return string[] erreurs ; vide si enregistré
     */
    public static function saveDefaults(array $input): array {
        $manual = trim((string) ($input['agent_latest_version'] ?? ''));
        $target = trim((string) ($input['agent_update_target'] ?? ''));
        $state  = max(0, (int) ($input['agent_probe_states_id'] ?? 0));
        $errors = [];
        if ($manual !== '' && !self::isValidVersion($manual)) {
            $errors[] = __('Dernière version invalide (exemple : 1.19).', 'printgestion');
        }
        if ($target !== '' && !self::isValidVersion($target)) {
            $errors[] = __('Version cible invalide (exemple : 1.18).', 'printgestion');
        }
        if ($state > 0 && countElementsInTable(State::getTable(), ['id' => $state]) === 0) {
            $errors[] = __('Statut des PC sondes introuvable.', 'printgestion');
        }
        if (!empty($errors)) {
            return $errors;
        }
        $latest = self::getLatestVersion();
        $config = PluginPrintgestionConfig::getInstance();
        $update = [
            'id'                    => (int) $config->getID(),
            'agent_update_default'  => empty($input['agent_update_default']) ? 0 : 1,
            'agent_update_target'   => $target,
            'agent_probe_states_id' => $state,
        ];
        if ($manual !== '') {
            $update['agent_latest_version'] = $manual;
            $update['agent_latest_source']  = 'manual';
        } elseif ($latest['source'] === 'manual') {
            // Saisie effacée : la vérification sur GitHub reprend la main.
            $update['agent_latest_version'] = '';
            $update['agent_latest_source']  = '';
        }
        return $config->update($update) ? [] : [__('Réglages non enregistrés.', 'printgestion')];
    }

    public static function cronInfo($name) {
        return ['description' => __('Print Gestion : dernière version publiée de GLPI Agent (GitHub)', 'printgestion')];
    }

    /** Tâche automatique hebdomadaire : dernière version de GLPI Agent sur GitHub. */
    public static function cronPrintgestionCheckAgentVersion($task = null) {
        if (!PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
            return 0;
        }
        $result = self::checkLatestFromGitHub();
        if ($task instanceof CronTask) {
            $task->log($result['message']);
        }
        return $result['ok'] ? 1 : -1;
    }

    // ── Réglages et conformité par sonde ──────────────────────────────────────

    /** Réglages d'une sonde jamais réglée. */
    public static function getDefaultSettings(): array {
        return ['exists' => false, 'auto_update' => true, 'target_version' => '', 'date_mod' => null, 'users_id' => 0];
    }

    private static function settingsFromRow(array $row): array {
        return [
            'exists'         => true,
            'auto_update'    => (int) $row['auto_update'] === 1,
            'target_version' => trim((string) ($row['target_version'] ?? '')),
            'date_mod'       => $row['date_mod'],
            'users_id'       => (int) $row['users_id'],
        ];
    }

    public static function getSettings(int $agents_id): array {
        return self::getSettingsFor([$agents_id])[$agents_id];
    }

    /** @return array agents_id => réglages (ceux par défaut pour une sonde jamais réglée) */
    public static function getSettingsFor(array $agents_ids): array {
        global $DB;

        $out = array_fill_keys(array_map('intval', $agents_ids), self::getDefaultSettings());
        if (!empty($out)) {
            foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['agents_id' => array_keys($out)]]) as $row) {
                $out[(int) $row['agents_id']] = self::settingsFromRow($row);
            }
        }
        return $out;
    }

    /** @return array ['ok' => bool, 'message' => string] */
    public static function saveForAgent(Agent $agent, array $input): array {
        $target = trim((string) ($input['target_version'] ?? ''));
        if ($target !== '' && !self::isValidVersion($target)) {
            return ['ok' => false, 'message' => __('Version cible invalide (exemple : 1.18) : rien n\'est enregistré.', 'printgestion')];
        }
        $values  = [
            'auto_update'    => empty($input['auto_update']) ? 0 : 1,
            'target_version' => $target === '' ? null : $target,
            'users_id'       => (int) Session::getLoginUserID(),
        ];
        $setting = new self();
        $ok      = $setting->getFromDBByCrit(['agents_id' => (int) $agent->getID()])
            ? $setting->update(['id' => (int) $setting->getID()] + $values)
            : $setting->add(['agents_id' => (int) $agent->getID()] + $values) > 0;
        if (!$ok) {
            return ['ok' => false, 'message' => __('Réglages de la sonde non enregistrés.', 'printgestion')];
        }
        return ['ok' => true, 'message' => sprintf(
            __('Réglages de la sonde « %s » enregistrés. Rien ne change sur le PC tant que son paquet de consigne n\'y a pas été lancé : la tâche planifiée déjà posée reste telle quelle.', 'printgestion'),
            $agent->fields['name']
        )];
    }

    /** Version d'un agent (texte simple ou versions par module), ramenée à « 1.19 ». */
    public static function getAgentVersion(array $agent): string {
        return self::normalizeVersion((string) ($agent['version'] ?? ''));
    }

    public static function normalizeVersion(string $raw): string {
        $modules = importArrayFromDB($raw);
        $version = is_array($modules) && !empty($modules) ? (string) reset($modules) : trim($raw);
        return preg_match('/(\d+(?:\.\d+)+)/', $version, $matches) ? $matches[1] : $version;
    }

    /**
     * Conformité : version installée comparée à la version cible de la sonde, sinon à la dernière connue.
     *
     * @return array ['state' => ok|update|pinned|unknown|no_latest, 'target' => string, 'label' => string, 'class' => string]
     */
    public static function getCompliance(string $installed, array $settings, array $latest): array {
        $pinned = $settings['target_version'] !== '';
        $target = $pinned ? $settings['target_version'] : (string) ($latest['version'] ?? '');
        if (!preg_match('/^\d+(\.\d+)+$/', $installed)) {
            return ['state' => 'unknown', 'target' => $target, 'label' => __('Version installée inconnue', 'printgestion'), 'class' => 'bg-secondary text-secondary-fg'];
        }
        if ($target === '') {
            return ['state' => 'no_latest', 'target' => '', 'label' => __('Dernière version inconnue', 'printgestion'), 'class' => 'bg-secondary text-secondary-fg'];
        }
        $cmp = version_compare($installed, $target);
        if ($pinned) {
            return $cmp === 0
                ? ['state' => 'pinned', 'target' => $target, 'label' => sprintf(__('Épinglée sur %s', 'printgestion'), $target), 'class' => 'bg-blue text-blue-fg']
                : ['state' => 'update', 'target' => $target, 'label' => sprintf(__('Version cible %s non atteinte', 'printgestion'), $target), 'class' => 'bg-orange text-orange-fg'];
        }
        return $cmp >= 0
            ? ['state' => 'ok', 'target' => $target, 'label' => __('À jour', 'printgestion'), 'class' => 'bg-green text-green-fg']
            : ['state' => 'update', 'target' => $target, 'label' => sprintf(__('À mettre à jour (%s)', 'printgestion'), $target), 'class' => 'bg-orange text-orange-fg'];
    }

    // ── PC sonde ──────────────────────────────────────────────────────────────

    public static function getHostName(array $agent): string {
        $itemtype = (string) ($agent['itemtype'] ?? '');
        $items_id = (int) ($agent['items_id'] ?? 0);
        return is_a($itemtype, CommonDBTM::class, true) && $items_id > 0 ? (string) $itemtype::getFriendlyNameById($items_id) : '';
    }

    /** Système du PC de la sonde, lu dans son inventaire : windows, linux ou macos ; null si inconnu. */
    public static function getHostPlatform(array $agent): ?string {
        global $DB;

        if ((string) ($agent['itemtype'] ?? '') !== Computer::class || (int) ($agent['items_id'] ?? 0) <= 0) {
            return null;
        }
        $row  = $DB->request([
            'SELECT'     => ['os.name'],
            'FROM'       => 'glpi_items_operatingsystems AS io',
            'INNER JOIN' => ['glpi_operatingsystems AS os' => ['ON' => ['io' => 'operatingsystems_id', 'os' => 'id']]],
            'WHERE'      => ['io.itemtype' => Computer::class, 'io.items_id' => (int) $agent['items_id'], 'io.is_deleted' => 0],
            'ORDER'      => ['io.date_mod DESC', 'io.id DESC'],
            'LIMIT'      => 1,
        ])->current();
        $name = strtolower(trim((string) ($row['name'] ?? '')));
        if ($name === '') {
            return null;
        }
        if (str_contains($name, 'windows')) {
            return 'windows';
        }
        return str_contains($name, 'mac') || str_contains($name, 'darwin') ? 'macos' : 'linux';
    }

    /** Statut GLPI donné aux PC sondes (0 : aucun choisi). */
    public static function getProbeStateId(): int {
        return (int) (PluginPrintgestionConfig::getInstance()->fields['agent_probe_states_id'] ?? 0);
    }

    /** Ordinateurs qui portent ces agents : computers_id => ['id', 'name', 'states_id', 'entities_id']. */
    public static function getHosts(array $agents): array {
        global $DB;

        $ids = [];
        foreach ($agents as $agent) {
            if ((string) $agent['itemtype'] === Computer::class && (int) $agent['items_id'] > 0) {
                $ids[(int) $agent['items_id']] = true;
            }
        }
        $out = [];
        if (!empty($ids)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'states_id', 'entities_id'],
                'FROM'   => Computer::getTable(),
                'WHERE'  => ['id' => array_keys($ids)],
            ]) as $row) {
                $out[(int) $row['id']] = $row;
            }
        }
        return $out;
    }

    /** PC de la sonde, en lien si l'utilisateur voit les ordinateurs, avec son marquage « PC sonde » (HTML échappé). */
    private static function getHostHtml(array $agent, array $hosts, int $probe_state): string {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $host = (string) $agent['itemtype'] === Computer::class ? ($hosts[(int) $agent['items_id']] ?? null) : null;
        if ($host === null) {
            return '—';
        }
        $html = Computer::canView()
            ? "<a href='" . $esc(Computer::getFormURLWithID((int) $host['id'])) . "'>" . $esc($host['name']) . "</a>"
            : $esc($host['name']);
        if ($probe_state > 0) {
            $html .= (int) $host['states_id'] === $probe_state
                ? " <span class='badge bg-blue-lt'>" . $esc(__('PC sonde', 'printgestion')) . "</span>"
                : " <span class='badge bg-orange-lt'>" . $esc(__('Non marqué PC sonde', 'printgestion')) . "</span>";
        }
        return $html;
    }

    /**
     * Donne au PC de la sonde le statut « PC sonde » choisi par l'administrateur. Modification ordinaire de
     * l'ordinateur : sur un ordinateur inventorié, GLPI verrouille alors le champ contre les inventaires suivants.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function markProbeHost(Agent $agent): array {
        $states_id = self::getProbeStateId();
        if ($states_id <= 0) {
            return ['ok' => false, 'message' => __('Aucun statut « PC sonde » choisi : page « Installeur GLPI Agent » (droit de configuration du plugin).', 'printgestion')];
        }
        $computer = new Computer();
        if ((string) $agent->fields['itemtype'] !== Computer::class || !$computer->getFromDB((int) $agent->fields['items_id'])) {
            return ['ok' => false, 'message' => __('Cette sonde n\'est rattachée à aucun ordinateur : attendez l\'inventaire de son PC.', 'printgestion')];
        }
        if (!Session::haveAccessToEntity((int) $computer->fields['entities_id'])) {
            return ['ok' => false, 'message' => __('Ordinateur de la sonde hors de vos entités.', 'printgestion')];
        }
        $state = Dropdown::getDropdownName(State::getTable(), $states_id);
        if ((int) $computer->fields['states_id'] === $states_id) {
            return ['ok' => true, 'message' => sprintf(__('Le PC « %1$s » porte déjà le statut « %2$s ».', 'printgestion'), $computer->fields['name'], $state)];
        }
        if (!$computer->update(['id' => (int) $computer->getID(), 'states_id' => $states_id])) {
            return ['ok' => false, 'message' => __('Statut du PC non modifié.', 'printgestion')];
        }
        return ['ok' => true, 'message' => sprintf(
            $computer->isDynamic()
                ? __('PC « %1$s » marqué comme sonde (statut « %2$s »). Comme après une modification à la main, GLPI verrouille ce champ contre les inventaires suivants.', 'printgestion')
                : __('PC « %1$s » marqué comme sonde (statut « %2$s »).', 'printgestion'),
            $computer->fields['name'],
            $state
        )];
    }

    // ── Imprimantes collectées ────────────────────────────────────────────────

    /**
     * Imprimantes collectées par chaque sonde : celles que visent ses jobs GLPI Inventory (plage contenant une
     * adresse IPv4 de l'imprimante, l'imprimante étant dans l'entité de la plage ou une de ses sous-entités ;
     * ou imprimante ciblée), et celles dont elle a fait le dernier inventaire réseau.
     *
     * @return array agents_id => [printers_id => true]
     */
    public static function getCoverage(): array {
        global $DB;

        $printers = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id'],
            'FROM'   => Printer::getTable(),
            'WHERE'  => ['is_deleted' => 0, 'is_template' => 0],
        ]) as $row) {
            $printers[(int) $row['id']] = (int) $row['entities_id'];
        }
        if (empty($printers)) {
            return [];
        }

        $coverage = [];
        if (PluginPrintgestionCollectsetup::isAvailable()) {
            $range_agents = [];
            foreach (array_keys(PluginPrintgestionCollectsetup::METHODS) as $method) {
                foreach (PluginPrintgestionCollectsetup::getJobs($method) as $job) {
                    $agents = array_map('intval', $job['agent_ids']);
                    if (!empty($job['computer_ids'])) {
                        foreach ($DB->request([
                            'SELECT' => ['id'],
                            'FROM'   => Agent::getTable(),
                            'WHERE'  => ['itemtype' => Computer::class, 'items_id' => $job['computer_ids']],
                        ]) as $row) {
                            $agents[] = (int) $row['id'];
                        }
                    }
                    foreach ($agents as $agents_id) {
                        foreach ($job['range_ids'] as $range_id) {
                            $range_agents[(int) $range_id][$agents_id] = true;
                        }
                        foreach ($job['printer_ids'] as $printers_id) {
                            if (isset($printers[(int) $printers_id])) {
                                $coverage[$agents_id][(int) $printers_id] = true;
                            }
                        }
                    }
                }
            }
            $ranges = [];
            if (!empty($range_agents)) {
                foreach ($DB->request([
                    'SELECT' => ['id', 'entities_id', 'ip_start', 'ip_end'],
                    'FROM'   => PluginGlpiinventoryIPRange::getTable(),
                    'WHERE'  => ['id' => array_keys($range_agents)],
                ]) as $row) {
                    $start = ip2long(trim((string) $row['ip_start']));
                    $end   = ip2long(trim((string) $row['ip_end']));
                    if ($start === false || $end === false) {
                        continue;
                    }
                    $ranges[(int) $row['id']] = [
                        'start'    => min($start, $end),
                        'end'      => max($start, $end),
                        'entities' => array_flip(array_map('intval', getSonsOf(Entity::getTable(), (int) $row['entities_id']))),
                    ];
                }
            }
            if (!empty($ranges)) {
                foreach ($DB->request([
                    'SELECT'   => ['name', 'mainitems_id'],
                    'DISTINCT' => true,
                    'FROM'     => 'glpi_ipaddresses',
                    'WHERE'    => ['mainitemtype' => Printer::class, 'version' => 4, 'is_deleted' => 0],
                ]) as $row) {
                    $printers_id = (int) $row['mainitems_id'];
                    $long        = ip2long(trim((string) $row['name']));
                    if (!isset($printers[$printers_id]) || $long === false) {
                        continue;
                    }
                    foreach ($ranges as $range_id => $range) {
                        if ($range['start'] <= $long && $long <= $range['end'] && isset($range['entities'][$printers[$printers_id]])) {
                            foreach (array_keys($range_agents[$range_id]) as $agents_id) {
                                $coverage[$agents_id][$printers_id] = true;
                            }
                        }
                    }
                }
            }
        }
        foreach (PluginPrintgestionCollect::getImportDates(array_keys($printers)) as $printers_id => $dates) {
            if ((int) $dates['agents_id'] > 0 && !empty($dates['snmp'])) {
                $coverage[(int) $dates['agents_id']][$printers_id] = true;
            }
        }
        return $coverage;
    }

    // ── Scripts Windows posés sur le PC sonde ─────────────────────────────────

    /** Contrôle administrateur en tête des .bat (sans PowerShell). */
    public static function buildAdminCheckLines(): array {
        return [
            'fsutil dirty query %SystemDrive% >nul 2>&1',
            'if errorlevel 1 (',
            '  echo A lancer en administrateur : clic droit sur ce fichier, Executer en tant qu administrateur.',
            '  pause',
            '  exit /b 1',
            ')',
        ];
    }

    /**
     * Script de mise à jour lancé par la tâche planifiée (ASCII, CRLF) : rien si l'agent n'est pas en attente
     * (une mise à jour pendant une tâche la tue) ; winget appelé par son chemin, le compte SYSTEM n'ayant pas
     * winget dans son PATH ; dernière version publiée, ou version cible (installation forcée, retour arrière).
     * Les fonctionnalités MSI de la sonde (inventaire réseau) sont redemandées à chaque passage.
     */
    public static function buildUpdateScript(string $target): string {
        $options = '--exact --silent --scope machine --accept-package-agreements --accept-source-agreements --disable-interactivity'
            . ' --custom "ADDLOCAL=' . PluginPrintgestionAgentdeploy::ADDLOCAL . '"';
        $winget  = $target !== ''
            ? '"%WINGET_DIR%\\winget.exe" install --id ' . self::WINGET_ID . ' --version ' . $target . ' --force ' . $options . ' >> "%LOG%" 2>&1'
            : '"%WINGET_DIR%\\winget.exe" upgrade --id ' . self::WINGET_ID . ' ' . $options . ' >> "%LOG%" 2>&1';
        return implode("\r\n", [
            '@echo off',
            'rem Mise a jour de GLPI Agent posee par Print Gestion : tache planifiee mensuelle, compte SYSTEM.',
            'rem ' . ($target !== '' ? 'Version cible : ' . $target . '.' : 'Derniere version publiee.') . ' Aucun identifiant ni secret.',
            'setlocal',
            'set "LOG=%ProgramData%\\PrintGestion\\glpi-agent-update.log"',
            'echo [%DATE% %TIME%] Debut>> "%LOG%"',
            'rem Pas de mise a jour pendant une tache de l agent : il doit etre en attente.',
            'curl.exe -s --max-time 10 http://127.0.0.1:' . Agent::DEFAULT_PORT . '/status | findstr /c:"waiting" >nul',
            'if errorlevel 1 (',
            '  echo [%DATE% %TIME%] Agent occupe ou injoignable : mise a jour reportee>> "%LOG%"',
            '  exit /b 0',
            ')',
            'set "WINGET_DIR="',
            'for /d %%D in ("%ProgramFiles%\\WindowsApps\\Microsoft.DesktopAppInstaller_*__8wekyb3d8bbwe") do if exist "%%D\\winget.exe" set "WINGET_DIR=%%D"',
            'if not defined WINGET_DIR (',
            '  echo [%DATE% %TIME%] winget introuvable : mise a jour impossible>> "%LOG%"',
            '  exit /b 1',
            ')',
            'pushd "%WINGET_DIR%"',
            $winget,
            'set "RC=%ERRORLEVEL%"',
            'popd',
            'echo [%DATE% %TIME%] Fin, code %RC%>> "%LOG%"',
            'exit /b 0',
            '',
        ]);
    }

    /** Lignes .bat qui posent la tâche planifiée mensuelle (script copié depuis le dossier du ZIP) ou la retirent. */
    public static function buildScheduleLines(bool $enabled): array {
        $task   = '"' . self::TASK_NAME . '"';
        $script = '%ProgramData%\\PrintGestion\\' . self::UPDATE_SCRIPT;
        if (!$enabled) {
            return [
                'schtasks /Delete /TN ' . $task . ' /F >nul 2>&1',
                'if exist "' . $script . '" del /F /Q "' . $script . '"',
            ];
        }
        return [
            'if not exist "%ProgramData%\\PrintGestion" mkdir "%ProgramData%\\PrintGestion"',
            'copy /Y "%~dp0' . self::UPDATE_SCRIPT . '" "' . $script . '" >nul',
            'schtasks /Create /TN ' . $task . ' /TR "\\"' . $script . '\\"" /SC MONTHLY /D 1 /ST 03:00 /RU SYSTEM /RL HIGHEST /F >nul',
            'if errorlevel 1 (',
            '  echo Tache planifiee de mise a jour non posee.',
            '  pause',
            '  exit /b 1',
            ')',
        ];
    }

    /**
     * Script de la tâche cron mensuelle Linux (LF, root) : rien si l'agent n'est pas en attente ; release publiée
     * (dernière ou version cible) lue sur GitHub, installeur Perl officiel téléchargé et vérifié contre l'empreinte
     * SHA-256 publiée, puis relancé sans option de configuration, ce qui garde /etc/glpi-agent/conf.d.
     */
    public static function buildLinuxUpdateScript(string $target): string {
        $repository = PluginPrintgestionAgentdeploy::REPOSITORY;
        return implode("\n", [
            '#!/bin/sh',
            '# Mise a jour de GLPI Agent posee par Print Gestion : tache cron mensuelle, compte root.',
            '# ' . ($target !== '' ? 'Version cible : ' . $target . '.' : 'Derniere version publiee.') . ' Aucun identifiant ni secret.',
            'TARGET="' . $target . '"',
            'exec >>' . self::LINUX_LOG . ' 2>&1',
            'echo "[$(date "+%Y-%m-%d %H:%M:%S")] Debut"',
            '# Pas de mise a jour pendant une tache de l agent : il doit etre en attente.',
            'if ! curl -s --max-time 10 http://127.0.0.1:' . Agent::DEFAULT_PORT . '/status | grep -q waiting; then',
            '  echo "Agent occupe ou injoignable : mise a jour reportee"',
            '  exit 0',
            'fi',
            'WORK=$(mktemp -d) || exit 1',
            'trap \'rm -rf "$WORK"\' EXIT',
            'API="https://api.github.com/repos/' . $repository . '/releases"',
            'if [ -n "$TARGET" ]; then URL="$API/tags/$TARGET"; else URL="$API/latest"; fi',
            'if ! curl -fsSL --max-time 60 -H "Accept: application/vnd.github+json" -o "$WORK/release.json" "$URL"; then',
            '  echo "GitHub injoignable ou version inconnue : mise a jour reportee"',
            '  exit 1',
            'fi',
            '# Version, adresse et empreinte SHA-256 de l installeur Linux officiel, publiees par GitHub.',
            'set -- $(perl -MJSON::PP -e \'local $/; my $r = decode_json(<STDIN>); for my $a (@{$r->{assets}}) { next unless $a->{name} =~ /^glpi-agent-([0-9.]+)-linux-installer[.]pl$/; my $v = $1; my ($d) = ($a->{digest} // "") =~ /^sha256:([0-9a-f]{64})$/; print "$v $a->{browser_download_url} ", ($d // ""), "\\n"; last }\' < "$WORK/release.json")',
            'VERSION="$1"; ASSET="$2"; SHA="$3"',
            'if [ -z "$VERSION" ] || [ -z "$SHA" ]; then',
            '  echo "Installeur Linux ou empreinte absents de la release : rien n est fait"',
            '  exit 1',
            'fi',
            'INSTALLED=$(glpi-agent --version 2>/dev/null | head -n 1 | sed -n "s/.*(\\([0-9.]*\\)).*/\\1/p")',
            'if [ "$INSTALLED" = "$VERSION" ]; then',
            '  echo "Deja en version $VERSION"',
            '  exit 0',
            'fi',
            'case "$ASSET" in',
            '  https://github.com/' . $repository . '/releases/download/*) ;;',
            '  *) echo "Adresse de telechargement inattendue : $ASSET"; exit 1 ;;',
            'esac',
            'if ! curl -fsSL --max-time 900 -o "$WORK/installer.pl" "$ASSET"; then',
            '  echo "Telechargement interrompu"',
            '  exit 1',
            'fi',
            'if ! echo "$SHA  $WORK/installer.pl" | sha256sum -c - >/dev/null 2>&1; then',
            '  echo "Empreinte differente de celle publiee : installeur refuse"',
            '  exit 1',
            'fi',
            '# Sans option de configuration, l installeur garde /etc/glpi-agent/conf.d (00-install.cfg compris).',
            'perl "$WORK/installer.pl" --install --type=network --silent' . ($target !== '' ? ' --force' : ''),
            'echo "[$(date "+%Y-%m-%d %H:%M:%S")] Fin, version $VERSION, code $?"',
            'exit 0',
            '',
        ]);
    }

    /** Lignes sh qui posent la tâche cron mensuelle Linux (curl nécessaire) ou la retirent. */
    public static function buildLinuxScheduleLines(bool $enabled, string $target): array {
        if (!$enabled) {
            return ['rm -f ' . self::LINUX_CRON];
        }
        return array_merge(
            [
                'if command -v curl >/dev/null 2>&1; then',
                "  cat > " . self::LINUX_CRON . " <<'PRINTGESTION_CRON'",
            ],
            explode("\n", rtrim(self::buildLinuxUpdateScript($target), "\n")),
            [
                'PRINTGESTION_CRON',
                '  chmod 755 ' . self::LINUX_CRON,
                '  echo "Mise a jour automatique mensuelle posee : ' . ($target !== '' ? 'version cible ' . $target : 'derniere version publiee') . ' (' . self::LINUX_CRON . ')."',
                'else',
                '  echo "curl absent : mise a jour automatique non posee (installer curl puis relancer ce script)."',
                'fi',
            ]
        );
    }

    /**
     * Consigne de mise à jour d'une sonde : applique sur le PC le réglage « Mise à jour automatique » de GLPI (pose,
     * change ou retire la tâche). Windows : ZIP (lanceur, script de la tâche planifiée, note) ; Linux : script sh
     * seul. Fichier temporaire que l'appelant supprime.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'path', 'filename', 'content_type']
     */
    public static function buildConsignePackage(Agent $agent, string $os = 'windows'): array {
        $settings = self::getSettings((int) $agent->getID());
        $target   = $settings['target_version'];
        $name     = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $agent->fields['name']), '-');
        $name     = $name !== '' ? $name : 'sonde-' . (int) $agent->getID();

        if ($os === 'linux') {
            $filename = 'consigne-glpi-agent-' . $name . '.sh';
            $script   = implode("\n", array_merge(
                [
                    '#!/bin/sh',
                    '# Consigne de mise a jour de GLPI Agent pour la sonde ' . $name . ' (Print Gestion), generee le ' . date('Y-m-d H:i') . '.',
                    '# Aucun identifiant ni secret. A lancer sur le PC sonde : sudo sh ' . $filename,
                    'if [ "$(id -u)" -ne 0 ]; then',
                    '  echo "A lancer en root : sudo sh ' . $filename . '"',
                    '  exit 1',
                    'fi',
                ],
                self::buildLinuxScheduleLines($settings['auto_update'], $target),
                [$settings['auto_update'] ? '' : 'echo "Consigne appliquee : mise a jour automatique retiree de ce PC."', '']
            ));
            $path = GLPI_TMP_DIR . '/printgestion-consigne-' . bin2hex(random_bytes(8)) . '.sh';
            if (file_put_contents($path, $script) === false) {
                PluginPrintgestionLogger::error('agentsetting', sprintf('Script de consigne %s non écrit.', $path));
                return ['ok' => false, 'errors' => [__('Paquet de consigne non généré (détail dans le journal printgestion).', 'printgestion')]];
            }
            return ['ok' => true, 'errors' => [], 'path' => $path, 'filename' => $filename, 'content_type' => 'text/x-shellscript'];
        }
        if ($os !== 'windows') {
            return ['ok' => false, 'errors' => [__('Système inconnu : paquet de consigne non généré.', 'printgestion')]];
        }

        $bat = implode("\r\n", array_merge(
            [
                '@echo off',
                'rem Consigne de mise a jour de GLPI Agent pour la sonde ' . $name . ' (Print Gestion), generee le ' . date('Y-m-d H:i') . '.',
                'rem Aucun identifiant ni secret. A lancer sur le PC sonde, en administrateur.',
            ],
            self::buildAdminCheckLines(),
            self::buildScheduleLines($settings['auto_update']),
            [
                $settings['auto_update']
                    ? 'echo Consigne appliquee : mise a jour automatique mensuelle, ' . ($target !== '' ? 'version cible ' . $target . '.' : 'derniere version publiee.')
                    : 'echo Consigne appliquee : mise a jour automatique retiree de ce PC.',
                'pause',
                '',
            ]
        ));
        $readme = implode("\r\n", [
            sprintf(__('Consigne de mise à jour de GLPI Agent — sonde %1$s (%2$s)', 'printgestion'), $agent->fields['name'], Dropdown::getDropdownName(Entity::getTable(), (int) $agent->fields['entities_id'])),
            sprintf(__('Générée par Print Gestion le %1$s par %2$s.', 'printgestion'), Html::convDateTime(date('Y-m-d H:i:s')), getUserName((int) Session::getLoginUserID())),
            $settings['auto_update']
                ? sprintf(__('Réglage : mise à jour automatique activée, %s.', 'printgestion'), $target !== '' ? sprintf(__('version cible %s', 'printgestion'), $target) : __('dernière version publiée', 'printgestion'))
                : __('Réglage : mise à jour automatique désactivée.', 'printgestion'),
            __('Aucun identifiant, mot de passe ni jeton dans ce dossier.', 'printgestion'),
            '',
            __('1. Sur le PC sonde : clic droit sur le fichier ZIP > Extraire tout.', 'printgestion'),
            __('2. Clic droit sur consigne-mise-a-jour.bat > Exécuter en tant qu\'administrateur.', 'printgestion'),
            __('3. Le message final confirme la consigne appliquée.', 'printgestion'),
            '',
            __('Important : le réglage de GLPI ne change rien sur le PC tant que ce fichier n\'y a pas été lancé. Décocher « Mise à jour automatique » dans GLPI ne retire pas une tâche planifiée déjà posée.', 'printgestion'),
            sprintf(__('Mise à jour : winget (%1$s), le 1er du mois à 3 h, seulement si l\'agent est en attente. Journal : C:\\ProgramData\\PrintGestion\\glpi-agent-update.log.', 'printgestion'), self::WINGET_ID),
            __('Retour à une version plus ancienne : l\'installeur Windows peut refuser de rétrograder ; le journal le signale. Il faut alors désinstaller GLPI Agent puis le réinstaller avec le paquet de l\'entité.', 'printgestion'),
            '',
        ]);

        $path = GLPI_TMP_DIR . '/printgestion-consigne-' . bin2hex(random_bytes(8)) . '.zip';
        $zip  = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            PluginPrintgestionLogger::error('agentsetting', sprintf('Paquet de consigne %s non créé.', $path));
            return ['ok' => false, 'errors' => [__('Paquet de consigne non généré (détail dans le journal printgestion).', 'printgestion')]];
        }
        $zip->addFromString('consigne-mise-a-jour.bat', $bat);
        if ($settings['auto_update']) {
            $zip->addFromString(self::UPDATE_SCRIPT, self::buildUpdateScript($target));
        }
        $zip->addFromString('LISEZMOI.txt', "\xEF\xBB\xBF" . $readme);
        if (!$zip->close()) {
            if (is_file($path)) {
                unlink($path);
            }
            PluginPrintgestionLogger::error('agentsetting', sprintf('Paquet de consigne %s non finalisé.', $path));
            return ['ok' => false, 'errors' => [__('Paquet de consigne non généré (détail dans le journal printgestion).', 'printgestion')]];
        }
        return ['ok' => true, 'errors' => [], 'path' => $path, 'filename' => 'consigne-glpi-agent-' . $name . '.zip', 'content_type' => 'application/zip'];
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Agent && (int) $item->getID() > 0
            && PluginPrintgestionConfig::isFeatureEnabled('deploiement') && Session::haveRight(self::$rightname, READ)) {
            return self::createTabEntry(__('Sonde Print Gestion', 'printgestion'));
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        // Contenu joignable par l'URL de l'onglet : module, droit et accès à l'agent revérifiés.
        if (!$item instanceof Agent || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')
            || !Session::haveRight(self::$rightname, READ) || !$item->canViewItem()) {
            return false;
        }
        self::showForAgent($item);
        return true;
    }

    /** Page « Installeur GLPI Agent » : dernière version connue, mise à jour des nouveaux paquets, statut des PC sondes. */
    public static function showDefaultsCard(bool $can_edit, string $page): void {
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $latest = self::getLatestVersion();
        $fields = PluginPrintgestionConfig::getInstance()->fields;
        $served = PluginPrintgestionAgentdeploy::getServedVersion();

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Dernière version de GLPI Agent, mise à jour automatique et PC sondes', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<p>" . $esc(sprintf(__('Dernière version connue : %1$s (%2$s).', 'printgestion'), $latest['version'], self::getLatestSourceLabel($latest))) . "</p>";
        if (version_compare($served, $latest['version'], '<')) {
            echo "<div class='alert alert-warning'>" . $esc(sprintf(__('L\'installeur servi (%1$s) est plus ancien que la dernière version (%2$s) : indiquez la nouvelle version dans « Paramètres transmis » puis récupérez-la.', 'printgestion'), $served, $latest['version'])) . "</div>";
        }
        if ($can_edit) {
            echo "<form method='post' action='" . $esc($page) . "' class='mb-3'><button type='submit' name='check_latest' value='1' class='btn btn-outline-primary'><i class='ti ti-refresh me-1'></i>"
                . $esc(__('Vérifier sur GitHub maintenant', 'printgestion')) . "</button>" . Html::closeForm(false);
            echo "<form method='post' action='" . $esc($page) . "' class='row g-3 align-items-end'>";
            echo "<div class='col-md-6 col-xl-3'><label class='form-label'>" . $esc(__('Dernière version saisie à la main (vide : GitHub)', 'printgestion')) . "</label>"
                . "<input type='text' class='form-control' name='agent_latest_version' maxlength='20' value='" . $esc($latest['source'] === 'manual' ? $latest['version'] : '') . "' placeholder='" . $esc($latest['version']) . "'></div>";
            echo "<div class='col-md-6 col-xl-3'><div class='form-check'>"
                . "<input type='hidden' name='agent_update_default' value='0'>"
                . "<input class='form-check-input' type='checkbox' id='pg-update-default' name='agent_update_default' value='1'" . ((int) ($fields['agent_update_default'] ?? 1) === 1 ? ' checked' : '') . ">"
                . "<label class='form-check-label' for='pg-update-default'>" . $esc(__('Nouveaux paquets Windows et Linux : poser la mise à jour automatique', 'printgestion')) . "</label></div></div>";
            echo "<div class='col-md-6 col-xl-3'><label class='form-label'>" . $esc(__('Version cible des nouveaux paquets (vide : dernière)', 'printgestion')) . "</label>"
                . "<input type='text' class='form-control' name='agent_update_target' maxlength='20' value='" . $esc($fields['agent_update_target'] ?? '') . "'></div>";
            echo "<div class='col-md-6 col-xl-3'><label class='form-label'>" . $esc(__('Statut GLPI des PC sondes', 'printgestion')) . "</label>"
                . State::dropdown(['name' => 'agent_probe_states_id', 'value' => self::getProbeStateId(), 'display' => false, 'entity' => 0, 'entity_sons' => true]) . "</div>";
            echo "<div class='col-12'><button type='submit' name='save_update_defaults' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer', 'printgestion')) . "</button></div>";
            echo Html::closeForm(false);
        } else {
            echo "<p class='mb-0'>" . $esc(sprintf(
                __('Nouveaux paquets Windows et Linux : %1$s. Statut des PC sondes : %2$s.', 'printgestion'),
                (int) ($fields['agent_update_default'] ?? 1) === 1 ? __('mise à jour automatique posée', 'printgestion') : __('sans mise à jour automatique', 'printgestion'),
                self::getProbeStateId() > 0 ? Dropdown::getDropdownName(State::getTable(), self::getProbeStateId()) : __('aucun', 'printgestion')
            )) . "</p>";
        }
        echo "<p class='text-muted small mt-3 mb-0'>" . $esc(__('Le plugin ne pousse aucune mise à jour. Selon ces réglages, le paquet Windows pose sur le PC une tâche planifiée mensuelle (winget, compte SYSTEM) et le paquet Linux une tâche cron mensuelle (installeur officiel téléchargé sur GitHub, empreinte vérifiée), toutes deux seulement si l\'agent est en attente ; macOS : mise à jour manuelle. Chaque sonde se règle ensuite depuis sa fiche, et le changement n\'est appliqué qu\'en lançant sa consigne sur le PC. Microsoft ne prend pas officiellement en charge winget sous le compte SYSTEM : à vérifier au pilote. Statut des PC sondes : créez-le à la racine (récursif) dans Configuration > Intitulés > Statuts des éléments, puis marquez chaque PC depuis la page « Sondes ».', 'printgestion')) . "</p>";
        echo "</div></div>";
    }

    /** Conformité, mise à jour automatique, imprimantes collectées : ce qui manque à la fiche Agent native. */
    public static function showForAgent(Agent $agent): void {
        global $DB;

        $esc        = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $agents_id  = (int) $agent->getID();
        $can_edit   = Session::haveRight(self::$rightname, UPDATE);
        $latest     = self::getLatestVersion();
        $settings   = self::getSettings($agents_id);
        $installed  = self::getAgentVersion($agent->fields);
        $compliance = self::getCompliance($installed, $settings, $latest);

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Conformité de version', 'printgestion')) . "</h3></div><div class='card-body'><div class='row g-3'>";
        echo "<div class='col-md-3'><div class='text-muted small'>" . $esc(__('Version installée', 'printgestion')) . "</div><div class='fw-bold'>" . $esc($installed !== '' ? $installed : '—') . "</div></div>";
        echo "<div class='col-md-3'><div class='text-muted small'>" . $esc(__('Dernière version connue', 'printgestion')) . "</div><div class='fw-bold'>" . $esc($latest['version']) . "</div>"
            . "<div class='text-muted small'>" . $esc(self::getLatestSourceLabel($latest)) . "</div></div>";
        echo "<div class='col-md-3'><div class='text-muted small'>" . $esc(__('Version visée', 'printgestion')) . "</div><div class='fw-bold'>" . $esc($compliance['target'] !== '' ? $compliance['target'] : '—') . "</div>"
            . ($settings['target_version'] !== '' ? "<div class='text-muted small'>" . $esc(__('épinglée sur cette sonde', 'printgestion')) . "</div>" : '') . "</div>";
        echo "<div class='col-md-3'><div class='text-muted small'>" . $esc(__('État', 'printgestion')) . "</div><span class='badge " . $compliance['class'] . "'>" . $esc($compliance['label']) . "</span></div>";
        echo "</div></div></div>";

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Mise à jour automatique', 'printgestion')) . "</h3></div><div class='card-body'>";
        if ($can_edit) {
            echo "<form method='post' action='" . $esc(self::getPageURL($agents_id)) . "' class='row g-3 align-items-end'>" . Html::hidden('id', ['value' => $agents_id]);
            echo "<div class='col-md-4'><div class='form-check'><input type='hidden' name='auto_update' value='0'>"
                . "<input class='form-check-input' type='checkbox' id='pg-auto-update-{$agents_id}' name='auto_update' value='1'" . ($settings['auto_update'] ? ' checked' : '') . ">"
                . "<label class='form-check-label' for='pg-auto-update-{$agents_id}'>" . $esc(__('Mise à jour automatique', 'printgestion')) . "</label></div></div>";
            echo "<div class='col-md-4'><label class='form-label'>" . $esc(__('Version cible (vide : dernière connue)', 'printgestion')) . "</label>"
                . "<input type='text' class='form-control' name='target_version' maxlength='20' value='" . $esc($settings['target_version']) . "' placeholder='" . $esc($latest['version']) . "'></div>";
            echo "<div class='col-md-4'><button type='submit' name='save_agent_settings' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer', 'printgestion')) . "</button></div>";
            echo Html::closeForm(false);
        } else {
            echo "<p>" . $esc($settings['auto_update']
                ? sprintf(__('Mise à jour automatique : oui, %s.', 'printgestion'), $settings['target_version'] !== '' ? sprintf(__('version cible %s', 'printgestion'), $settings['target_version']) : __('dernière version connue', 'printgestion'))
                : __('Mise à jour automatique : non.', 'printgestion')) . "</p>";
        }
        if ($settings['exists']) {
            echo "<p class='text-muted small mt-2 mb-0'>" . $esc(sprintf(__('Réglé le %1$s par %2$s.', 'printgestion'), Html::convDateTime((string) $settings['date_mod']), getUserName($settings['users_id']))) . "</p>";
        }
        echo "<div class='alert alert-warning mt-3'><i class='ti ti-alert-triangle me-1'></i>" . $esc(__('Ce réglage ne change rien tout seul sur le PC. La tâche de mise à jour (tâche planifiée sous Windows, tâche cron sous Linux) y est posée par le paquet d\'installation ; pour appliquer un changement (désactiver, épingler une version, revenir en arrière), téléchargez la consigne et lancez-la sur la sonde. Décocher la case ici ne désactive pas une tâche déjà posée. Retour à une version plus ancienne : l\'installeur peut refuser de rétrograder (signalé dans le journal de la tâche) ; il faut alors désinstaller puis réinstaller avec le paquet de l\'entité.', 'printgestion')) . "</div>";
        $platform = self::getHostPlatform($agent->fields);
        if ($platform === 'macos') {
            echo "<p class='mb-0'>" . $esc(__('PC sonde sous macOS : pas de mise à jour automatique (aucun mécanisme officiel). Réinstaller le paquet d\'une version plus récente depuis l\'onglet « Déploiement Agent » de l\'entité ; local.cfg est gardé.', 'printgestion')) . "</p>";
        } else {
            echo "<div class='d-flex flex-wrap gap-2'>";
            foreach ([
                'windows' => ['ti-brand-windows', __('Paquet de consigne pour ce PC (Windows)', 'printgestion')],
                'linux'   => ['ti-brand-ubuntu', __('Script de consigne pour ce PC (Linux)', 'printgestion')],
            ] as $os => [$icon, $label]) {
                if ($platform === null || $platform === $os) {
                    echo "<a class='btn btn-outline-secondary' href='" . $esc(self::getConsigneURL($agents_id, $os)) . "'><i class='ti {$icon} me-1'></i>" . $esc($label) . "</a>";
                }
            }
            echo "</div>";
            if ($platform === null) {
                echo "<p class='text-muted small mt-2 mb-0'>" . $esc(__('Système du PC sonde inconnu (pas encore inventorié) : prendre la consigne de son système. macOS : mise à jour manuelle.', 'printgestion')) . "</p>";
            }
        }
        echo "</div></div>";

        $coverage = array_keys(self::getCoverage()[$agents_id] ?? []);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(sprintf(__('Imprimantes collectées par cette sonde (%d)', 'printgestion'), count($coverage))) . "</h3></div><div class='card-body'>";
        if (empty($coverage)) {
            echo "<p class='text-muted mb-0'>" . $esc(__('Aucune : aucune tâche GLPI Inventory de cette sonde ne vise d\'imprimante, et elle n\'a fait aucun inventaire réseau.', 'printgestion')) . "</p></div></div>";
            return;
        }
        $dates  = PluginPrintgestionCollect::getImportDates($coverage);
        $cutoff = static fn(int $entities_id): string => date('Y-m-d H:i:s', time() - PluginPrintgestionCollectfrequency::getSilentDaysForEntity($entities_id) * DAY_TIMESTAMP);
        $ips    = [];
        foreach ($DB->request([
            'SELECT'   => ['mainitems_id', 'name'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_ipaddresses',
            'WHERE'    => ['mainitemtype' => Printer::class, 'mainitems_id' => $coverage, 'version' => 4, 'is_deleted' => 0],
            'ORDER'    => ['name'],
        ]) as $row) {
            $ips[(int) $row['mainitems_id']][] = (string) $row['name'];
        }
        echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0'><thead><tr>"
            . "<th>" . $esc(_n('Imprimante', 'Imprimantes', 1, 'printgestion')) . "</th><th>" . $esc(Entity::getTypeName(1)) . "</th>"
            . "<th>" . $esc(__('Adresse IP', 'printgestion')) . "</th><th>" . $esc(__('Dernier inventaire réseau réussi', 'printgestion')) . "</th></tr></thead><tbody>";
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'entities_id'],
            'FROM'   => Printer::getTable(),
            'WHERE'  => ['id' => $coverage],
            'ORDER'  => ['name'],
        ]) as $printer) {
            $printers_id = (int) $printer['id'];
            $snmp        = $dates[$printers_id]['snmp'] ?? null;
            echo "<tr><td><a href='" . $esc(Printer::getFormURLWithID($printers_id)) . "'>" . $esc($printer['name']) . "</a></td>"
                . "<td>" . $esc(Dropdown::getDropdownName(Entity::getTable(), (int) $printer['entities_id'])) . "</td>"
                . "<td class='font-monospace small'>" . $esc(implode(', ', $ips[$printers_id] ?? []) ?: '—') . "</td>"
                . "<td>" . $esc($snmp !== null ? Html::convDateTime((string) $snmp) : __('aucun connu', 'printgestion'))
                . ($snmp === null || (string) $snmp < $cutoff((int) $printer['entities_id']) ? " <span class='badge bg-red text-red-fg'>" . $esc(__('Muette', 'printgestion')) . "</span>" : '') . "</td></tr>";
        }
        echo "</tbody></table></div></div></div>";
    }

    /** Page « Sondes » du module, vue d'une sonde : en-tête (PC et statut « PC sonde »), puis le contenu de l'onglet. */
    public static function showDetail(Agent $agent): void {
        $esc         = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $agents_id   = (int) $agent->getID();
        $probe_state = self::getProbeStateId();
        $hosts       = self::getHosts([$agent->fields]);
        $host        = (string) $agent->fields['itemtype'] === Computer::class ? ($hosts[(int) $agent->fields['items_id']] ?? null) : null;

        echo "<div class='card mb-3'><div class='card-body'><div class='d-flex flex-wrap align-items-center gap-3'>";
        echo "<h2 class='mb-0'>" . $esc($agent->fields['name']) . "</h2>";
        echo "<span class='fs-3'>" . $esc(Dropdown::getDropdownName(Entity::getTable(), (int) $agent->fields['entities_id'])) . "</span>";
        echo "<span class='text-muted'>" . $esc(sprintf(__('Dernier contact : %s', 'printgestion'), empty($agent->fields['last_contact']) ? '—' : Html::convDateTime((string) $agent->fields['last_contact']))) . "</span>";
        echo "<div class='ms-auto d-flex gap-2'>";
        if (Agent::canView()) {
            echo "<a class='btn btn-outline-secondary' href='" . $esc(Agent::getFormURLWithID($agents_id)) . "'><i class='ti ti-robot me-1'></i>" . $esc(__('Fiche agent native', 'printgestion')) . "</a>";
        }
        echo "<a class='btn btn-outline-secondary' href='" . $esc(self::getPageURL()) . "'><i class='ti ti-list me-1'></i>" . $esc(__('Toutes les sondes', 'printgestion')) . "</a>";
        echo "</div></div>";

        echo "<div class='d-flex flex-wrap align-items-center gap-2 mt-3'><span>" . $esc(__('PC hôte :', 'printgestion')) . "</span> " . self::getHostHtml($agent->fields, $hosts, $probe_state);
        if ($host !== null) {
            echo " <span class='text-muted'>" . $esc(sprintf(__('statut : %s', 'printgestion'), (int) $host['states_id'] > 0 ? Dropdown::getDropdownName(State::getTable(), (int) $host['states_id']) : __('aucun', 'printgestion'))) . "</span>";
            if ($probe_state > 0 && (int) $host['states_id'] !== $probe_state && Session::haveRight(self::$rightname, UPDATE)) {
                echo "<form method='post' action='" . $esc(self::getPageURL($agents_id)) . "' class='d-inline'>" . Html::hidden('id', ['value' => $agents_id])
                    . "<button type='submit' name='mark_probe_host' value='1' class='btn btn-sm btn-outline-primary'><i class='ti ti-tag me-1'></i>"
                    . $esc(sprintf(__('Marquer ce PC comme sonde (statut « %s »)', 'printgestion'), Dropdown::getDropdownName(State::getTable(), $probe_state))) . "</button>" . Html::closeForm(false);
            }
        }
        if ($probe_state === 0) {
            echo " <span class='text-muted small'>" . $esc(__('(aucun statut « PC sonde » choisi sur la page « Installeur GLPI Agent »)', 'printgestion')) . "</span>";
        }
        echo "</div></div></div>";
        self::showForAgent($agent);
    }

    /** Page « Sondes » du module : compteurs, sondes sans contact par entité, conformité de chaque sonde. */
    public static function showList(): void {
        $esc         = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $latest      = self::getLatestVersion();
        $silent_days = PluginPrintgestionCollect::getSilentDays();
        $probes      = PluginPrintgestionAgentalert::getProbes(true, self::getCoverage());
        $settings    = self::getSettingsFor(array_keys($probes));
        $hosts       = self::getHosts($probes);
        $probe_state = self::getProbeStateId();

        $counts = ['silent' => 0, 'update' => 0, 'pinned' => 0];
        $silent = [];
        foreach ($probes as $agents_id => $probe) {
            $probes[$agents_id]['compliance'] = self::getCompliance(self::getAgentVersion($probe), $settings[$agents_id], $latest);
            if ($probe['is_silent']) {
                $counts['silent']++;
                $silent[(string) $probe['entity']][] = $probes[$agents_id];
            }
            if (isset($counts[$probes[$agents_id]['compliance']['state']])) {
                $counts[$probes[$agents_id]['compliance']['state']]++;
            }
        }

        echo "<div class='row row-cards mb-3'>";
        foreach ([
            [sprintf(__('Sondes sans contact depuis plus de %d jours', 'printgestion'), $silent_days), $counts['silent'], $counts['silent'] > 0 ? 'text-red' : 'text-green'],
            [__('Sondes à mettre à jour', 'printgestion'), $counts['update'], $counts['update'] > 0 ? 'text-orange' : 'text-green'],
            [__('Sondes épinglées sur leur version cible', 'printgestion'), $counts['pinned'], 'text-blue'],
            [sprintf(__('Dernière version connue de GLPI Agent (%s)', 'printgestion'), self::getLatestSourceLabel($latest)), $latest['version'], 'text-body'],
        ] as [$label, $value, $class]) {
            echo "<div class='col-sm-6 col-lg-3'><div class='card card-sm'><div class='card-body'><div class='h1 mb-0 {$class}'>" . $esc($value) . "</div><div class='text-muted'>" . $esc($label) . "</div></div></div></div>";
        }
        echo "</div>";

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(sprintf(__('Sondes sans contact depuis plus de %d jours, par entité', 'printgestion'), $silent_days)) . "</h3></div><div class='card-body'>";
        if (empty($silent)) {
            echo "<p class='text-muted mb-0'>" . $esc(__('Aucune : toutes les sondes ont contacté GLPI récemment.', 'printgestion')) . "</p>";
        }
        foreach ($silent as $entity => $list) {
            echo "<div class='mb-2'><span class='fw-bold'>" . $esc($entity) . "</span> : " . implode(', ', array_map(static fn(array $probe): string =>
                "<a href='" . $esc(self::getPageURL((int) $probe['id'])) . "'>" . $esc($probe['name']) . "</a> <span class='text-muted small'>(" . $esc(empty($probe['last_contact']) ? __('jamais', 'printgestion') : Html::convDateTime((string) $probe['last_contact'])) . ")</span>", $list)) . "</div>";
        }
        echo "<p class='text-muted small mt-2 mb-0'>" . $esc(__('Sonde : agent installé avec l\'inventaire réseau ou qui collecte au moins une imprimante. Une sonde qui contacte GLPI ne garantit pas que ses imprimantes remontent : voir « Contrôle de la remontée ». Notifications : Configuration > Inventaire, « Agent cleanup ».', 'printgestion')) . "</p>";
        echo "</div></div>";

        echo "<div class='card'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(self::getTypeName(Session::getPluralNumber())) . "</h3></div><div class='card-body'>";
        if (empty($probes)) {
            echo "<p class='mb-0'>" . $esc(__('Aucune sonde dans vos entités.', 'printgestion')) . "</p></div></div>";
            return;
        }
        echo "<div class='table-responsive'><table class='table table-sm table-hover align-middle mb-0'><thead><tr>"
            . "<th>" . $esc(Entity::getTypeName(1)) . "</th><th>" . $esc(__('Sonde', 'printgestion')) . "</th><th>" . $esc(__('PC hôte', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Version', 'printgestion')) . "</th><th>" . $esc(__('Conformité', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Dernier contact', 'printgestion')) . "</th><th>" . $esc(__('Mise à jour automatique', 'printgestion')) . "</th>"
            . "<th class='text-end'>" . $esc(_n('Imprimante', 'Imprimantes', Session::getPluralNumber(), 'printgestion')) . "</th></tr></thead><tbody>";
        foreach ($probes as $agents_id => $probe) {
            $setting = $settings[$agents_id];
            echo "<tr><td>" . $esc($probe['entity']) . "</td>"
                . "<td><a href='" . $esc(self::getPageURL($agents_id)) . "'>" . $esc($probe['name']) . "</a></td>"
                . "<td>" . self::getHostHtml($probe, $hosts, $probe_state) . "</td>"
                . "<td>" . $esc(self::getAgentVersion($probe) ?: '—') . "</td>"
                . "<td><span class='badge " . $probe['compliance']['class'] . "'>" . $esc($probe['compliance']['label']) . "</span></td>"
                . "<td>" . $esc(empty($probe['last_contact']) ? '—' : Html::convDateTime((string) $probe['last_contact']))
                . ($probe['is_silent'] ? " <span class='badge bg-red text-red-fg'>" . $esc(__('Sans contact', 'printgestion')) . "</span>" : '') . "</td>"
                . "<td>" . $esc($setting['auto_update'] ? ($setting['target_version'] !== '' ? sprintf(__('oui, cible %s', 'printgestion'), $setting['target_version']) : __('oui', 'printgestion')) : __('non', 'printgestion')) . "</td>"
                . "<td class='text-end'>" . (int) $probe['printers'] . "</td></tr>";
        }
        echo "</tbody></table></div></div></div>";
    }

    static function uninstall(Migration $migration) {
        global $DB;

        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, 'PrintgestionCheckAgentVersion')) {
            $task->delete(['id' => (int) $task->getID()]);
        }
        $DB->doQuery('DROP TABLE IF EXISTS `' . self::getTable() . '`');
        return true;
    }
}
