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
 *   déjà posée. macOS : service launchd mensuel posé par le fichier d'installation ; pas de consigne à part.
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

    /**
     * Les trois actions d'une consigne, portées par l'URL du bouton : poser la tâche, la retirer, ou mettre à jour
     * tout de suite sans rien changer à l'automatisation. Aucune n'est un réglage stocké — le bouton dit ce qu'il
     * fabrique, et rien dans GLPI ne peut diverger de ce que le PC a reçu.
     */
    const ACTION_POSER      = '1';
    const ACTION_RETIRER    = '0';
    const ACTION_MAINTENANT = 'now';

    /** Script de mise à jour posé sur le PC sonde, dans %ProgramData%\PrintGestion. */
    const UPDATE_SCRIPT = 'glpi-agent-update.cmd';

    /** Tâche du scan local des imprimantes, posée seulement quand GLPI Inventory est absent (chantier du scan). */
    const SCAN_TASK_NAME = 'GLPI Agent - scan imprimantes (Print Gestion)';

    /**
     * Le scan Linux : un script, et une ligne de cron.d qui porte la cadence.
     *
     * cron.d et non cron.daily : lui seul sait dire « toutes les 3 heures » ou « le 1er du mois ». L'ancien chemin
     * reste connu du fichier de retrait, pour les postes installés avant ce changement.
     */
    const LINUX_SCAN_SCRIPT = '/usr/local/sbin/glpi-agent-printgestion-scan';
    const LINUX_SCAN_CRON   = '/etc/cron.d/printgestion-scan';
    const LINUX_SCAN_OLD    = '/etc/cron.daily/glpi-agent-printgestion-scan';

    /** Tâche cron mensuelle posée sur le PC sonde Linux, et son journal. */
    const LINUX_CRON = '/etc/cron.monthly/glpi-agent-printgestion';
    const LINUX_LOG  = '/var/log/glpi-agent-printgestion-update.log';

    /**
     * Mise à jour automatique sur macOS : un service launchd mensuel, l'équivalent du cron de Linux.
     *
     * Le service appelle un script déposé à part plutôt que d'embarquer les commandes : un plist se relit mal, et
     * le script se lance à la main pour vérifier ce qu'il ferait (« sudo sh <script> »).
     */
    const MACOS_LABEL  = 'com.printgestion.glpi-agent-update';
    const MACOS_PLIST  = '/Library/LaunchDaemons/com.printgestion.glpi-agent-update.plist';
    const MACOS_SCRIPT = '/usr/local/sbin/glpi-agent-printgestion-update';
    const MACOS_LOG    = '/var/log/glpi-agent-printgestion-update.log';

    /** Journal du scan local. En constante, pour que le fichier de retrait sache l'effacer lui aussi. */
    const LINUX_SCAN_LOG = '/var/log/glpi-agent-printgestion-scan.log';

    static function getTypeName($nb = 0) {
        return _n('Sonde', 'Sondes', $nb, 'printgestion');
    }

    public static function getPageURL(?int $agents_id = null): string {
        return PLUGIN_PRINTGESTION_WEBDIR . '/front/sondes.php' . ($agents_id !== null ? '?id=' . $agents_id : '');
    }

    /**
     * URL du fichier de consigne. L'intention est dans l'URL, pas dans un réglage stocké : le bouton dit ce qu'il
     * fabrique, et rien ne peut diverger entre ce que GLPI affiche et ce que le fichier fera.
     */
    public static function getConsigneURL(int $agents_id, string $os = 'windows', string $action = self::ACTION_POSER): string {
        return PLUGIN_PRINTGESTION_WEBDIR . '/front/sonde.consigne.php?agents_id=' . $agents_id
            . '&os=' . rawurlencode($os) . '&maj=' . rawurlencode($action);
    }

    /** Vrai si cette action est connue : la route refuse tout le reste plutôt que de deviner. */
    public static function isConsigneAction(string $action): bool {
        return in_array($action, [self::ACTION_POSER, self::ACTION_RETIRER, self::ACTION_MAINTENANT], true);
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

    public static function getLatestSourceLabel(array $latest): string {
        return match ($latest['source']) {
            'manual' => __('saisie à la main', 'printgestion'),
            'github' => sprintf(__('vérifiée sur GitHub le %s', 'printgestion'), Html::convDateTime((string) $latest['checked'])),
            default  => __('version de l\'installeur servi, faute de vérification sur GitHub ou de saisie', 'printgestion'),
        };
    }

    public static function isValidVersion(string $version): bool {
        return (bool) preg_match('/^\d+\.\d+(\.\d+)?$/', $version);
    }

    /** Mémo des versions publiées, dans la configuration GLPI du plugin (pas de colonne, donc pas de schéma). */
    const VERSIONS_CONTEXT = 'plugin:printgestion';
    const VERSIONS_KEY     = 'agent_published_versions';
    const VERSIONS_AT      = 'agent_published_versions_at';

    /** Durée de validité du mémo : un jour. Une release de plus attend le lendemain, ou le bouton « Vérifier ». */
    const VERSIONS_TTL = DAY_TIMESTAMP;

    /** Nombre de versions gardées : de quoi revenir en arrière, pas un historique. */
    const VERSIONS_KEPT = 10;

    /**
     * Versions publiées de GLPI Agent, de la plus récente à la plus ancienne, pour les menus déroulants.
     *
     * Elles viennent de GitHub, pas du cache des fichiers : le cache n'en garde qu'une seule (récupérer une version
     * efface la précédente), une liste des fichiers téléchargés n'aurait donc qu'une ligne.
     *
     * $refresh force la lecture ; sinon le mémo d'un jour suffit. Liste vide : GitHub n'a jamais répondu — l'écran
     * repasse alors en champ libre plutôt que d'afficher un menu vide.
     *
     * @return string[]
     */
    public static function getPublishedVersions(bool $refresh = false): array {
        $memo = Config::getConfigurationValues(self::VERSIONS_CONTEXT, [self::VERSIONS_KEY, self::VERSIONS_AT]);
        $list = json_decode((string) ($memo[self::VERSIONS_KEY] ?? ''), true);
        $list = is_array($list) ? array_values(array_filter($list, static fn($v) => is_string($v) && self::isValidVersion($v))) : [];
        $age  = (int) ($memo[self::VERSIONS_AT] ?? 0);
        // Une tentative par jour, succès ou échec : sans cette borne, un serveur sans accès à GitHub attendrait dix
        // secondes de connexion à chaque affichage de la page. Le bouton « Vérifier sur GitHub » force la lecture.
        if (!$refresh && $age > time() - self::VERSIONS_TTL) {
            return $list;
        }

        try {
            $client   = Toolbox::getGuzzleClient(['timeout' => 30, 'connect_timeout' => 10]);
            $response = $client->request('GET', sprintf('https://api.github.com/repos/%s/releases?per_page=30', PluginPrintgestionAgentdeploy::REPOSITORY), [
                'headers' => ['User-Agent' => 'GLPI-printgestion', 'Accept' => 'application/vnd.github+json'],
            ]);
            $releases = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::warning('agentsetting', 'Liste des versions de GLPI Agent non lue sur GitHub.', $e);
            // L'heure est notée même en échec : on ne réessaie pas avant demain. Le mémo précédent, même périmé,
            // vaut mieux que rien.
            Config::setConfigurationValues(self::VERSIONS_CONTEXT, [self::VERSIONS_AT => (string) time()]);
            return $list;
        }

        $found = [];
        foreach (is_array($releases) ? $releases : [] as $release) {
            if (!empty($release['prerelease']) || !empty($release['draft'])) {
                continue;
            }
            $version = ltrim(trim((string) ($release['tag_name'] ?? '')), 'v');
            if (self::isValidVersion($version) && !in_array($version, $found, true)) {
                $found[] = $version;
            }
        }
        if ($found === []) {
            Config::setConfigurationValues(self::VERSIONS_CONTEXT, [self::VERSIONS_AT => (string) time()]);
            return $list;
        }
        usort($found, static fn(string $a, string $b) => version_compare($b, $a));
        $found = array_slice($found, 0, self::VERSIONS_KEPT);
        Config::setConfigurationValues(self::VERSIONS_CONTEXT, [
            self::VERSIONS_KEY => json_encode($found),
            self::VERSIONS_AT  => (string) time(),
        ]);
        return $found;
    }

    /**
     * Menu déroulant d'une version, ou champ libre si aucune liste n'a jamais pu être récupérée.
     *
     * La valeur déjà réglée est toujours proposée, même absente de la liste : un menu qui perd en silence un
     * épinglage volontaire (ou une version retirée de GitHub) ferait basculer tout un parc sans le dire.
     *
     * @param string $vide libellé de l'option vide : les deux champs n'ont pas le même « rien »
     */
    public static function versionField(string $name, string $value, string $vide): string {
        $versions = self::getPublishedVersions();
        if ($versions === []) {
            return "<input type='text' class='form-control' name='" . $name . "' maxlength='20' value='"
                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "' placeholder='" . htmlspecialchars(PluginPrintgestionAgentdeploy::DEFAULT_VERSION, ENT_QUOTES, 'UTF-8') . "'>"
                . "<div class='form-hint'>" . htmlspecialchars(__('Liste des versions publiées non récupérée (GitHub injoignable) : saisie libre.', 'printgestion'), ENT_QUOTES, 'UTF-8') . "</div>";
        }
        if ($value !== '' && !in_array($value, $versions, true)) {
            array_unshift($versions, $value);
        }
        $html = "<select class='form-select' name='" . $name . "'>";
        $html .= "<option value=''>" . htmlspecialchars($vide, ENT_QUOTES, 'UTF-8') . "</option>";
        foreach ($versions as $version) {
            $html .= "<option value='" . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . "'"
                . ($version === $value ? ' selected' : '') . ">" . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . "</option>";
        }
        return $html . "</select>";
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

        // Le même clic rafraîchit la liste des versions proposées dans les menus : elle vient de la même source.
        self::getPublishedVersions(true);

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
        $errors = [];
        if ($manual !== '' && !self::isValidVersion($manual)) {
            $errors[] = __('Dernière version invalide (exemple : 1.20).', 'printgestion');
        }
        if (!empty($errors)) {
            return $errors;
        }
        $latest = self::getLatestVersion();
        $config = PluginPrintgestionConfig::getInstance();
        $update = [
            'id'                    => (int) $config->getID(),
            'agent_update_default'  => empty($input['agent_update_default']) ? 0 : 1,
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

    /**
     * Version des agents : celle que le serveur distribue aux nouvelles sondes, et celle vers laquelle les sondes
     * déjà installées convergent. Une seule, pour les deux.
     *
     * Il y en avait trois : une par sonde, une « cible du parc », une « servie ». Elles disaient la même chose à des
     * moments différents, et rien ne garantissait qu'elles disent la même chose. Un parc qui vise une version que le
     * serveur ne distribue pas est un parc qui ne se met jamais à jour.
     *
     * Jamais vide : faute de réglage, c'est la version de référence du plugin.
     */
    public static function getTargetVersion(): string {
        return PluginPrintgestionAgentdeploy::getServedVersion();
    }

    /**
     * Réglages d'une sonde. Il n'en reste aucun qui lui soit propre : la version cible est celle du parc, la mise à
     * jour automatique est une action (deux boutons de consigne) et non plus un souhait stocké. La forme du tableau
     * est gardée pour ses lecteurs (conformité, alertes, écrans).
     */
    public static function getDefaultSettings(): array {
        return ['target_version' => self::getTargetVersion()];
    }

    public static function getSettings(int $agents_id): array {
        return self::getDefaultSettings();
    }

    /** @return array agents_id => réglages */
    public static function getSettingsFor(array $agents_ids): array {
        return array_fill_keys(array_map('intval', $agents_ids), self::getDefaultSettings());
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
     * @return array ['state' => ok|update|ahead|unknown|no_latest, 'target' => string, 'label' => string, 'class' => string]
     */
    public static function getCompliance(string $installed, array $settings, array $latest): array {
        // Une seule version compte désormais : celle que ce serveur distribue. « À jour » veut donc dire « dans la
        // version du parc », et plus « au moins aussi récente que ce que GitHub publie ».
        $target = $settings['target_version'];
        if (!preg_match('/^\d+(\.\d+)+$/', $installed)) {
            return ['state' => 'unknown', 'target' => $target, 'label' => __('Version installée inconnue', 'printgestion'), 'class' => 'bg-secondary text-secondary-fg'];
        }
        if ($target === '') {
            return ['state' => 'no_latest', 'target' => '', 'label' => __('Version du parc inconnue', 'printgestion'), 'class' => 'bg-secondary text-secondary-fg'];
        }
        return match (version_compare($installed, $target)) {
            0       => ['state' => 'ok', 'target' => $target, 'label' => __('À jour', 'printgestion'), 'class' => 'bg-green text-green-fg'],
            -1      => ['state' => 'update', 'target' => $target, 'label' => sprintf(__('À mettre à jour (%s)', 'printgestion'), $target), 'class' => 'bg-orange text-orange-fg'],
            // Plus récente que ce que le serveur distribue : ce n'est pas une panne, mais le parc n'est pas où on
            // le croit — par exemple après un retour arrière de la version servie.
            default => ['state' => 'ahead', 'target' => $target, 'label' => sprintf(__('Plus récente que la version du parc (%s)', 'printgestion'), $target), 'class' => 'bg-blue text-blue-fg'],
        };
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
     * Statut GLPI des PC sondes, enregistré depuis la page « Sondes » — là où on s'en sert. Il n'avait rien à faire
     * au milieu des versions.
     *
     * @return string[] erreurs ; vide si enregistré
     */
    public static function saveProbeState(array $input): array {
        $state = max(0, (int) ($input['agent_probe_states_id'] ?? 0));
        if ($state > 0 && countElementsInTable(State::getTable(), ['id' => $state]) === 0) {
            return [__('Statut des PC sondes introuvable.', 'printgestion')];
        }
        $config = PluginPrintgestionConfig::getInstance();
        return $config->update(['id' => (int) $config->getID(), 'agent_probe_states_id' => $state])
            ? []
            : [__('Statut des PC sondes non enregistré.', 'printgestion')];
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
            return ['ok' => false, 'message' => __('Aucun statut « PC sonde » choisi : réglez-le en haut de cette page (droit de configuration du plugin).', 'printgestion')];
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
        return implode("\r\n", array_merge([
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
        ],
        // Déjà dans la version visée : ne rien faire. Sans ce contrôle, « --force » réinstallait l'agent tous les
        // mois — service arrêté et relancé au passage — alors qu'il était déjà à jour. Les espaces autour du numéro
        // sont voulus : la colonne de winget est alignée, et « 1.19 » ne doit pas reconnaître « 1.19.1 ».
        $target === '' ? [] : [
            '"%WINGET_DIR%\\winget.exe" list --id ' . self::WINGET_ID . ' --exact --accept-source-agreements 2>nul | findstr /c:" ' . $target . ' " >nul',
            'if not errorlevel 1 (',
            '  echo [%DATE% %TIME%] Deja en version ' . $target . ' : rien a faire>> "%LOG%"',
            '  popd',
            '  exit /b 0',
            ')',
        ],
        [
            $winget,
            'set "RC=%ERRORLEVEL%"',
            'popd',
            'echo [%DATE% %TIME%] Fin, code %RC%>> "%LOG%"',
            'exit /b 0',
            '',
        ]));
    }

    /** Nom des entrées que Print Gestion pose dans la ToolBox de l'agent : les siennes, reconnaissables à l'œil. */
    const TOOLBOX_NAME = 'printgestion';

    /**
     * Configuration de la ToolBox de l'agent, pour le scan des imprimantes quand GLPI Inventory manque au serveur.
     *
     * La ToolBox est native à GLPI Agent (depuis 1.6) : plage IP, identifiant SNMP et tâche de scan planifiée, dont
     * les résultats partent à server0 — le serveur GLPI, qui les reçoit en natif sans le plugin voisin. Le
     * technicien voit et corrige tout dans http://127.0.0.1:62354/toolbox. Elle remplace un scan fait maison
     * (tâche planifiée qui enchaînait glpi-netdiscovery et glpi-injector), invisible depuis cette page.
     *
     * Format relu dans le code de l'agent (lib/GLPI/Agent/HTTP/Server/ToolBox/*.pm) : credentials, ip_range,
     * scheduling et jobs sont des tables indexées par nom, enabled vaut yes ou no. Pas de next_run_date : sans elle,
     * la tâche part presque aussitôt au démarrage du service, au lieu d'attendre une période entière.
     *
     * Repères remplacés sur le poste : @FIRST@, @LAST@ (la plage, rendue par le serveur), @DELAY@ (la cadence) et
     * @COMMUNITY@ (déjà échappée pour une chaîne YAML entre apostrophes). La communauté est écrite en clair : c'est le
     * fonctionnement de la ToolBox. Le fichier est réservé aux administrateurs du poste.
     */
    public static function buildToolboxYaml(): array {
        $nom = self::TOOLBOX_NAME;
        return [
            '# ToolBox de GLPI Agent, configuree par Print Gestion : scan des imprimantes, GLPI Inventory manquant au serveur.',
            '# Visible et modifiable dans http://127.0.0.1:62354/toolbox',
            'configuration:',
            '  updating_support: yes',
            '# Les deux versions, v2c puis v1 : beaucoup d imprimantes n exposent que SNMPv1, et l agent essaie',
            '# les identifiants d une plage dans l ordre jusqu a ce que l un reponde.',
            'credentials:',
            '  ' . $nom . '-snmp:',
            '    type: snmp',
            '    snmpversion: v2c',
            "    community: '@COMMUNITY@'",
            "    description: 'Imprimantes (Print Gestion)'",
            '  ' . $nom . '-snmp-v1:',
            '    type: snmp',
            '    snmpversion: v1',
            "    community: '@COMMUNITY@'",
            "    description: 'Imprimantes, SNMPv1 (Print Gestion)'",
            'ip_range:',
            '  ' . $nom . ':',
            '    ip_start: @FIRST@',
            '    ip_end: @LAST@',
            '    credentials:',
            '      - ' . $nom . '-snmp',
            '      - ' . $nom . '-snmp-v1',
            "    description: 'Imprimantes (Print Gestion)'",
            'scheduling:',
            '  ' . $nom . ':',
            '    type: delay',
            '    delay: @DELAY@',
            "    description: 'Cadence choisie a l installation (Print Gestion)'",
            'jobs:',
            '  ' . $nom . '-imprimantes:',
            '    type: netscan',
            '    enabled: yes',
            '    scheduling:',
            '      - ' . $nom,
            '    config:',
            '      ip_range:',
            '        - ' . $nom,
            '      threads: 10',
            '      timeout: 1',
            '      target: server0',
            "    description: 'Scan des imprimantes (Print Gestion)'",
        ];
    }

    /**
     * Activation de la ToolBox : désactivée par défaut dans l'agent. Réservée au poste lui-même et aux adresses de
     * httpd-trust (forbid_not_trusted) : l'interface montre la communauté SNMP.
     */
    public static function buildToolboxPluginConfig(): array {
        return [
            '# Active par Print Gestion : scan des imprimantes par la ToolBox de l agent.',
            'disabled = no',
            '# Seuls le poste lui-meme et les adresses de httpd-trust y accedent : la page montre la communaute SNMP.',
            'forbid_not_trusted = yes',
        ];
    }

    /**
     * Arguments de schtasks pour poser la tâche de mise à jour, sans le nom du programme : le 1er du mois à 3 h,
     * compte SYSTEM, privilèges les plus élevés, remplacement d'une tâche existante. @SCRIPT@ est le chemin du
     * script à lancer, que l'appelant remplace — le .bat par %ProgramData%\\..., la fenêtre par le chemin résolu.
     *
     * Les guillemets échappés autour de @SCRIPT@ sont voulus : schtasks reçoit /TR "\\"C:\\...\\script.cmd\\"", donc
     * un chemin lui-même entre guillemets, seule forme qui survit à un espace dans le chemin.
     */
    public static function buildScheduleArguments(): string {
        return '/Create /TN "' . self::TASK_NAME . '" /TR "\\"@SCRIPT@\\"" /SC MONTHLY /D 1 /ST 03:00 /RU SYSTEM /RL HIGHEST /F';
    }

    /**
     * Lignes .bat qui posent la tâche planifiée mensuelle (script copié depuis le dossier du ZIP, dont la présence
     * est vérifiée d'abord : un .bat lancé depuis l'aperçu du ZIP est seul dans son dossier) ou la retirent.
     *
     * @param bool   $enabled    vrai pour poser la tâche, faux pour la retirer
     * @param string $source_dir sous-dossier du paquet où se trouve le script, relatif au .bat lancé (« » à côté)
     */
    public static function buildScheduleLines(bool $enabled, string $source_dir = ''): array {
        $task   = '"' . self::TASK_NAME . '"';
        $script = '%ProgramData%\\PrintGestion\\' . self::UPDATE_SCRIPT;
        $source = '%~dp0' . $source_dir . self::UPDATE_SCRIPT;
        if (!$enabled) {
            return [
                'schtasks /Delete /TN ' . $task . ' /F >nul 2>&1',
                'if exist "' . $script . '" del /F /Q "' . $script . '"',
            ];
        }
        return [
            'if not exist "' . $source . '" (',
            '  echo Fichier ' . self::UPDATE_SCRIPT . ' introuvable : extraire tout le ZIP, puis relancer depuis le dossier extrait.',
            '  pause',
            '  exit /b 1',
            ')',
            'if not exist "%ProgramData%\\PrintGestion" mkdir "%ProgramData%\\PrintGestion"',
            'copy /Y "' . $source . '" "' . $script . '" >nul',
            'schtasks ' . str_replace('@SCRIPT@', $script, self::buildScheduleArguments()) . ' >nul',
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
            '# curl, ou wget a defaut : les postes sans curl existent (vu a l installation), le poste choisit.',
            'pg_get() {',
            '  if command -v curl >/dev/null 2>&1; then curl -fsSL --max-time "$3" -H "Accept: application/vnd.github+json" -o "$2" "$1"; return $?; fi',
            '  wget -q -T "$3" --header="Accept: application/vnd.github+json" -O "$2" "$1"',
            '}',
            'pg_page() {',
            '  if command -v curl >/dev/null 2>&1; then curl -s --max-time 10 "$1"; return $?; fi',
            '  wget -q -T 10 -O - "$1"',
            '}',
            '# Pas de mise a jour pendant une tache de l agent : il doit etre en attente.',
            'if ! pg_page http://127.0.0.1:' . Agent::DEFAULT_PORT . '/status | grep -q waiting; then',
            '  echo "Agent occupe ou injoignable : mise a jour reportee"',
            '  exit 0',
            'fi',
            'WORK=$(mktemp -d) || exit 1',
            'trap \'rm -rf "$WORK"\' EXIT',
            'API="https://api.github.com/repos/' . $repository . '/releases"',
            'if [ -n "$TARGET" ]; then URL="$API/tags/$TARGET"; else URL="$API/latest"; fi',
            'if ! pg_get "$URL" "$WORK/release.json" 60; then',
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
            'if ! pg_get "$ASSET" "$WORK/installer.pl" 900; then',
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

    /**
     * Script de mise à jour pour macOS, lancé chaque mois par le service launchd.
     *
     * Même prudence que sous Linux, avec les outils du Mac : rien pendant une tâche de l'agent, la release lue
     * sur GitHub, le paquet de CETTE puce seulement (Apple Silicon ou Intel), son empreinte SHA-256 vérifiée
     * contre celle publiée, et l'installation seulement si la version diffère. `curl`, `perl`, `shasum` et
     * `installer` sont livrés avec macOS : rien à installer sur le poste du client.
     *
     * @param string $target version épinglée, ou '' pour la dernière publiée
     */
    public static function buildMacosUpdateScript(string $target): string {
        $repository = PluginPrintgestionAgentdeploy::REPOSITORY;
        // Le paquet porte le nom de la puce : GLPI-Agent-1.20_arm64.pkg ou GLPI-Agent-1.20_x86_64.pkg.
        $perl = 'local $/; my $r = decode_json(<STDIN>); my $s = $ENV{PG_SUFFIXE};'
            . ' for my $a (@{$r->{assets}}) {'
            . ' next unless $a->{name} =~ /^GLPI-Agent-([0-9.]+)_$s[.]pkg$/;'
            . ' my $v = $1; my ($d) = ($a->{digest} // "") =~ /^sha256:([0-9a-f]{64})$/;'
            . ' print "$v $a->{browser_download_url} ", ($d // ""), "' . chr(92) . 'n"; last }';
        return implode("\n", [
            '#!/bin/sh',
            '# Mise a jour de GLPI Agent posee par Print Gestion : service launchd mensuel, compte root.',
            '# ' . ($target !== '' ? 'Version cible : ' . $target . '.' : 'Derniere version publiee.') . ' Aucun identifiant ni secret.',
            'TARGET="' . $target . '"',
            'exec >>' . self::MACOS_LOG . ' 2>&1',
            'echo "[$(date "+%Y-%m-%d %H:%M:%S")] Debut"',
            '# Pas de mise a jour pendant une tache de l agent : il doit etre en attente.',
            'if ! curl -s --max-time 10 http://127.0.0.1:' . Agent::DEFAULT_PORT . '/status | grep -q waiting; then',
            '  echo "Agent occupe ou injoignable : mise a jour reportee"',
            '  exit 0',
            'fi',
            'WORK=$(mktemp -d) || exit 1',
            'trap ' . chr(39) . 'rm -rf "$WORK"' . chr(39) . ' EXIT',
            'API="https://api.github.com/repos/' . $repository . '/releases"',
            'if [ -n "$TARGET" ]; then URL="$API/tags/$TARGET"; else URL="$API/latest"; fi',
            'if ! curl -fsSL --max-time 60 -H "Accept: application/vnd.github+json" -o "$WORK/release.json" "$URL"; then',
            '  echo "GitHub injoignable ou version inconnue : mise a jour reportee"',
            '  exit 1',
            'fi',
            '# La puce de ce Mac : on ne telecharge que son paquet.',
            'case "$(uname -m)" in arm64) PG_SUFFIXE=arm64 ;; *) PG_SUFFIXE=x86_64 ;; esac',
            'export PG_SUFFIXE',
            '# Version, adresse et empreinte SHA-256 du paquet officiel, publiees par GitHub.',
            'set -- $(perl -MJSON::PP -e ' . chr(39) . $perl . chr(39) . ' < "$WORK/release.json")',
            'VERSION="$1"; ASSET="$2"; SHA="$3"',
            'if [ -z "$VERSION" ] || [ -z "$SHA" ]; then',
            '  echo "Paquet macOS ($PG_SUFFIXE) ou empreinte absents de la release : rien n est fait"',
            '  exit 1',
            'fi',
            'PG_BIN=$(command -v glpi-agent 2>/dev/null)',
            'if [ -z "$PG_BIN" ] && [ -x /Applications/GLPI-Agent/bin/glpi-agent ]; then PG_BIN=/Applications/GLPI-Agent/bin/glpi-agent; fi',
            'INSTALLED=""',
            'if [ -n "$PG_BIN" ]; then',
            // Les crochets plutôt que l'antislash dans l'expression : une barre oblique inverse de moins à faire
            // voyager du PHP au shell, et un motif que l'on relit sans compter les échappements.
            '  INSTALLED=$("$PG_BIN" --version 2>/dev/null | head -n 1 | awk ' . chr(39)
                . 'match($0, /[0-9]+[.][0-9]+([.][0-9]+)?/) { print substr($0, RSTART, RLENGTH); exit }' . chr(39) . ')',
            'fi',
            'if [ "$INSTALLED" = "$VERSION" ]; then',
            '  echo "Deja en version $VERSION"',
            '  exit 0',
            'fi',
            'case "$ASSET" in',
            '  https://github.com/' . $repository . '/releases/download/*) ;;',
            '  *) echo "Adresse de telechargement inattendue : $ASSET"; exit 1 ;;',
            'esac',
            'if ! curl -fsSL --max-time 900 -o "$WORK/agent.pkg" "$ASSET"; then',
            '  echo "Telechargement interrompu"',
            '  exit 1',
            'fi',
            'if ! echo "$SHA  $WORK/agent.pkg" | shasum -a 256 -c - >/dev/null 2>&1; then',
            '  echo "Empreinte differente de celle publiee : paquet refuse"',
            '  exit 1',
            'fi',
            '# Le paquet garde la configuration existante (/Applications/GLPI-Agent/etc/conf.d).',
            'installer -pkg "$WORK/agent.pkg" -target /',
            'echo "[$(date "+%Y-%m-%d %H:%M:%S")] Fin, version $VERSION, code $?"',
            'exit 0',
            '',
        ]);
    }

    /**
     * Lignes sh qui posent le service launchd mensuel de macOS, ou le retirent.
     *
     * Le service est recréé à chaque installation : « bootout » d'abord (sans quoi launchd garde l'ancien), puis
     * « bootstrap ». Sur macOS 12 et avant, `unload`/`load` prennent le relais.
     */
    public static function buildMacosScheduleLines(bool $enabled, string $target): array {
        if (!$enabled) {
            return [
                'launchctl bootout system ' . self::MACOS_PLIST . ' 2>/dev/null || launchctl unload ' . self::MACOS_PLIST . ' 2>/dev/null',
                'rm -f ' . self::MACOS_PLIST . ' ' . self::MACOS_SCRIPT,
            ];
        }
        return array_merge(
            [
                'mkdir -p ' . dirname(self::MACOS_SCRIPT),
                "cat > " . self::MACOS_SCRIPT . " <<'PRINTGESTION_MAJ'",
            ],
            explode("\n", rtrim(self::buildMacosUpdateScript($target), "\n")),
            [
                'PRINTGESTION_MAJ',
                'chmod 755 ' . self::MACOS_SCRIPT,
                "cat > " . self::MACOS_PLIST . " <<'PRINTGESTION_PLIST'",
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">',
                '<plist version="1.0">',
                '<dict>',
                '  <key>Label</key><string>' . self::MACOS_LABEL . '</string>',
                '  <key>ProgramArguments</key>',
                '  <array><string>/bin/sh</string><string>' . self::MACOS_SCRIPT . '</string></array>',
                '  <key>StartCalendarInterval</key>',
                '  <dict><key>Day</key><integer>1</integer><key>Hour</key><integer>3</integer><key>Minute</key><integer>0</integer></dict>',
                '  <key>RunAtLoad</key><false/>',
                '</dict>',
                '</plist>',
                'PRINTGESTION_PLIST',
                'chmod 644 ' . self::MACOS_PLIST,
                'launchctl bootout system ' . self::MACOS_PLIST . ' 2>/dev/null',
                'launchctl unload ' . self::MACOS_PLIST . ' 2>/dev/null',
                'if ! launchctl bootstrap system ' . self::MACOS_PLIST . ' 2>/dev/null; then',
                '  launchctl load ' . self::MACOS_PLIST . ' 2>/dev/null',
                'fi',
            ]
        );
    }

    /** Lignes sh qui posent la tâche cron mensuelle Linux (curl nécessaire) ou la retirent. */
    public static function buildLinuxScheduleLines(bool $enabled, string $target): array {
        if (!$enabled) {
            return ['rm -f ' . self::LINUX_CRON];
        }
        return array_merge(
            [
                'if command -v curl >/dev/null 2>&1 || command -v wget >/dev/null 2>&1; then',
                "  cat > " . self::LINUX_CRON . " <<'PRINTGESTION_CRON'",
            ],
            explode("\n", rtrim(self::buildLinuxUpdateScript($target), "\n")),
            [
                'PRINTGESTION_CRON',
                '  chmod 755 ' . self::LINUX_CRON,
                '  echo "Mise a jour automatique mensuelle posee : ' . ($target !== '' ? 'version cible ' . $target : 'derniere version publiee') . ' (' . self::LINUX_CRON . ')."',
                'else',
                '  echo "ni curl ni wget : mise a jour automatique non posee (installer curl puis relancer ce script)."',
                'fi',
            ]
        );
    }

    /**
     * Fichier de consigne d'une sonde : lancé sur le PC, il y pose ou y retire la tâche planifiée de mise à jour
     * automatique. Windows : ZIP (lanceur, script de la tâche, note) ; Linux : script sh seul. Fichier temporaire
     * que l'appelant supprime.
     *
     * $action vient de l'URL du bouton, jamais d'un réglage stocké : le bouton dit ce qu'il fabrique, et GLPI ne
     * garde aucun souhait qui pourrait diverger de ce que le PC a reçu.
     *
     * ACTION_POSER et ACTION_RETIRER ne mettent pas l'agent à jour : elles installent ou retirent la tâche qui, elle,
     * le fera le 1er du mois. ACTION_MAINTENANT fait l'inverse : elle met à jour sur le champ et ne touche pas à la
     * tâche — mettre à jour maintenant et automatiser sont deux décisions distinctes.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'path', 'filename', 'content_type']
     */
    public static function buildConsignePackage(Agent $agent, string $os = 'windows', string $action = self::ACTION_POSER): array {
        $settings    = self::getSettings((int) $agent->getID());
        $target      = $settings['target_version'];
        $poser       = $action === self::ACTION_POSER;
        $maintenant  = $action === self::ACTION_MAINTENANT;
        $name        = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $agent->fields['name']), '-');
        $name        = $name !== '' ? $name : 'sonde-' . (int) $agent->getID();

        if ($os === 'linux') {
            $filename = ($maintenant ? 'mise-a-jour-maintenant-' : 'consigne-glpi-agent-') . $name . '.sh';
            $script   = implode("\n", array_merge(
                [
                    '#!/bin/sh',
                    '# ' . ($maintenant ? 'Mise a jour immediate' : 'Consigne de mise a jour') . ' de GLPI Agent pour la sonde ' . $name . ' (Print Gestion), generee le ' . date('Y-m-d H:i') . '.',
                    '# Aucun identifiant ni secret. A lancer sur le PC sonde : sudo sh ' . $filename,
                    'if [ "$(id -u)" -ne 0 ]; then',
                    '  echo "A lancer en root : sudo sh ' . $filename . '"',
                    '  exit 1',
                    'fi',
                ],
                !$maintenant ? [] : array_merge(
                    [
                        '# Le meme script que celui de la tache, lance une fois, tout de suite. La tache planifiee,',
                        '# elle, n est pas touchee : mettre a jour maintenant et automatiser sont deux decisions.',
                        'PG_SCRIPT=$(mktemp)',
                        "cat > \"\$PG_SCRIPT\" <<'PRINTGESTION_NOW'",
                    ],
                    explode("\n", rtrim(self::buildLinuxUpdateScript($target), "\n")),
                    [
                        'PRINTGESTION_NOW',
                        'chmod 755 "$PG_SCRIPT"',
                        'echo "Mise a jour en cours (quelques minutes)..."',
                        '"$PG_SCRIPT"',
                        'rm -f "$PG_SCRIPT"',
                        'echo',
                        'echo "Journal (' . self::LINUX_LOG . ') :"',
                        'tail -n 20 ' . self::LINUX_LOG . ' 2>/dev/null || echo "journal introuvable"',
                        '',
                    ]
                ),
                $maintenant ? [] : self::buildLinuxScheduleLines($poser, $target),
                $maintenant || $poser ? [''] : ['echo "Consigne appliquee : mise a jour automatique retiree de ce PC."', '']
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

        $log = '%ProgramData%\\PrintGestion\\glpi-agent-update.log';
        $bat = implode("\r\n", array_merge(
            [
                '@echo off',
                'rem ' . ($maintenant ? 'Mise a jour immediate' : 'Consigne de mise a jour') . ' de GLPI Agent pour la sonde ' . $name . ' (Print Gestion), generee le ' . date('Y-m-d H:i') . '.',
                'rem Aucun identifiant ni secret. A lancer sur le PC sonde, en administrateur.',
            ],
            self::buildAdminCheckLines(),
            !$maintenant ? [] : [
                'rem Le meme script que celui de la tache, lance une fois, tout de suite. La tache planifiee, elle,',
                'rem n est pas touchee : mettre a jour maintenant et automatiser sont deux decisions.',
                'if not exist "%ProgramData%\\PrintGestion" mkdir "%ProgramData%\\PrintGestion"',
                'copy /Y "%~dp0' . self::UPDATE_SCRIPT . '" "%ProgramData%\\PrintGestion\\' . self::UPDATE_SCRIPT . '" >nul',
                'echo Mise a jour en cours (quelques minutes)...',
                'call "%ProgramData%\\PrintGestion\\' . self::UPDATE_SCRIPT . '"',
                'echo.',
                'rem Le script ne fait rien quand l agent travaille : son journal dit pourquoi.',
                'echo Journal :',
                'type "' . $log . '"',
            ],
            $maintenant ? [] : self::buildScheduleLines($poser),
            [
                $maintenant
                    ? 'echo.'
                    : ($poser
                        ? 'echo Consigne appliquee : mise a jour automatique mensuelle, ' . ($target !== '' ? 'version cible ' . $target . '.' : 'derniere version publiee.')
                        : 'echo Consigne appliquee : mise a jour automatique retiree de ce PC.'),
                'pause',
                '',
            ]
        ));
        $readme = implode("\r\n", [
            sprintf(__('Consigne de mise à jour de GLPI Agent — sonde %1$s (%2$s)', 'printgestion'), $agent->fields['name'], Dropdown::getDropdownName(Entity::getTable(), (int) $agent->fields['entities_id'])),
            sprintf(__('Générée par Print Gestion le %1$s par %2$s.', 'printgestion'), Html::convDateTime(date('Y-m-d H:i:s')), getUserName((int) Session::getLoginUserID())),
            match (true) {
                $maintenant => __('Ce que ce fichier fait : il met GLPI Agent à jour tout de suite, une fois. Il ne touche pas à la mise à jour automatique. Si l\'agent est en train de travailler, rien n\'est fait et le journal le dit.', 'printgestion'),
                $poser      => sprintf(__('Ce que ce fichier fait : il pose la mise à jour automatique sur ce PC, %s.', 'printgestion'), $target !== '' ? sprintf(__('version cible %s', 'printgestion'), $target) : __('dernière version publiée', 'printgestion')),
                default     => __('Ce que ce fichier fait : il retire la mise à jour automatique de ce PC.', 'printgestion'),
            },
            __('Aucun identifiant, mot de passe ni jeton dans ce dossier.', 'printgestion'),
            '',
            __('1. Sur le PC sonde : clic droit sur le fichier ZIP > Extraire tout.', 'printgestion'),
            sprintf(__('2. Clic droit sur %s > Exécuter en tant qu\'administrateur.', 'printgestion'), $maintenant ? 'mise-a-jour-maintenant.bat' : 'consigne-mise-a-jour.bat'),
            __('3. Le message final confirme la consigne appliquée.', 'printgestion'),
            '',
            __('Important : GLPI ne pousse rien. Rien ne change sur ce PC tant que ce fichier n\'y a pas été lancé.', 'printgestion'),
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
        $zip->addFromString($maintenant ? 'mise-a-jour-maintenant.bat' : 'consigne-mise-a-jour.bat', $bat);
        if ($poser || $maintenant) {
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

    /**
     * Icône de l'onglet. Onglet « Sonde Print Gestion » de la fiche Agent : l'état du lien avec la sonde.
     *
     * Sans cette méthode, GLPI retombe sur l'icône par défaut de CommonDBTM, qui vaut « fa-empty-icon » et
     * que createTabEntry() remplace alors par rien : le libellé reste nu à côté des onglets natifs.
     */
    static function getIcon() {
        return 'ti ti-plug-connected';
    }

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

    /**
     * Page « Installeur GLPI Agent » : version de référence (la dernière publiée, qui sert à constater un retard),
     * ce que contiendront les prochains paquets, et le statut GLPI qui repère les PC sondes dans le parc. Aucun de
     * ces réglages ne touche un PC déjà installé.
     */
    /**
     * Ce qui décide de ce que contiendront les prochains paquets : la version des agents (champ tenu par
     * Agentdeploy), la dernière version publiée, et la règle de mise à jour automatique.
     *
     * Affiché **dans** la carte « Paramètres transmis à l'installation », et non plus dans une carte à part : c'est
     * la même question que la version servie, et une carte séparée laissait croire à un autre sujet.
     *
     * @param string $page URL de la page, pour les formulaires
     */
    /**
     * Avertissement pleine largeur : la version distribuée est plus ancienne que la dernière publiée.
     *
     * Rendu **hors** du formulaire : une colonne de douze au milieu d'une grille la coupe en deux, et les colonnes
     * suivantes repartent à la ligne sans raison visible.
     */
    public static function getServedVersionWarning(): string {
        $latest = self::getLatestVersion();
        $served = PluginPrintgestionAgentdeploy::getServedVersion();
        if (!version_compare($served, $latest['version'], '<')) {
            return '';
        }
        return "<div class='alert alert-warning'>" . htmlspecialchars(sprintf(
            __('La version distribuée (%1$s) est plus ancienne que la dernière publiée (%2$s) : choisissez-la ci-dessous, enregistrez, puis récupérez-la.', 'printgestion'),
            $served,
            $latest['version']
        ), ENT_QUOTES, 'UTF-8') . "</div>";
    }

    /** Colonne « Dernière version publiée » : ce qui apprend qu'une version plus récente existe. */
    public static function showLatestVersionColumn(bool $can_edit, string $classes): void {
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $latest = self::getLatestVersion();
        echo "<div class='" . $classes . "'>";
        echo "<label class='form-label'>" . $esc(__('Dernière version publiée', 'printgestion')) . "</label>";
        echo "<div class='pt-1'><span class='fw-bold'>" . $esc($latest['version']) . "</span></div>";
        echo "<div class='form-hint'>" . $esc(self::getLatestSourceLabel($latest)) . "</div>";
        if ($can_edit) {
            // Second bouton d'envoi du MÊME formulaire, et non un formulaire de plus : imbriquer deux formulaires
            // est invalide en HTML, et le navigateur en perd un — silencieusement.
            echo "<button type='submit' data-pg-submit-once='1' name='check_latest' value='1' class='btn btn-sm btn-outline-primary mt-2'><i class='ti ti-refresh me-1'></i>"
                . $esc(__('Vérifier sur GitHub', 'printgestion')) . "</button>";
        }
        echo "</div>";
    }

    /**
     * Colonne de la règle de mise à jour : qui décide, vous ou le technicien sur place.
     *
     * Deux boutons radio plutôt qu'une case et un « sinon » : la case seule laissait deviner l'autre branche, et
     * elle renvoyait à « la case » d'une fenêtre que le lecteur n'a pas sous les yeux — elle s'ouvrira plus tard,
     * sur le PC du client. Ici les deux cas sont écrits, et on lit ce qu'on choisit.
     */
    public static function showUpdateRuleColumn(bool $can_edit, string $classes): void {
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $impose = (int) (PluginPrintgestionConfig::getInstance()->fields['agent_update_default'] ?? 1) === 1;
        echo "<div class='" . $classes . "'>";
        echo "<label class='form-label'>" . $esc(__('Mise à jour automatique des sondes', 'printgestion')) . "</label>";
        if (!$can_edit) {
            echo "<div class='pt-1'>" . $esc($impose
                ? __('Toujours posée par le fichier d\'installation.', 'printgestion')
                : __('Laissée au technicien au moment d\'installer.', 'printgestion')) . "</div></div>";
            return;
        }
        foreach ([
            ['1', $impose, __('Toujours posée', 'printgestion'), __('Le fichier d\'installation l\'installe sans rien demander.', 'printgestion')],
            ['0', !$impose, __('Laissée au technicien', 'printgestion'), __('Il la coche ou non au moment d\'installer, décochée par défaut.', 'printgestion')],
        ] as [$valeur, $choisi, $titre, $aide]) {
            $id = 'pg-maj-' . $valeur;
            echo "<div class='form-check'>"
                . "<input class='form-check-input' type='radio' name='agent_update_default' id='" . $id . "' value='" . $valeur . "'" . ($choisi ? ' checked' : '') . ">"
                . "<label class='form-check-label' for='" . $id . "'>" . $esc($titre)
                . "<span class='d-block form-hint'>" . $esc($aide) . "</span></label></div>";
        }
        echo "</div>";
    }

    /**
     * Colonne de secours : la dernière version saisie à la main, **seulement** si GitHub n'a pas répondu.
     *
     * Affichée en permanence, elle invitait à remplir un champ inutile — et une version saisie à la main prend
     * ensuite le pas sur GitHub, en silence.
     */
    public static function showManualVersionColumn(string $classes): void {
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $latest = self::getLatestVersion();
        if ($latest['source'] === 'github') {
            return;
        }
        echo "<div class='" . $classes . "'><label class='form-label'>" . $esc(__('Dernière version, à la main', 'printgestion')) . "</label>"
            . "<input type='text' class='form-control' name='agent_latest_version' maxlength='20' value='" . $esc($latest['source'] === 'manual' ? $latest['version'] : '') . "' placeholder='" . $esc($latest['version']) . "'>"
            . "<div class='form-hint'>" . $esc(__('GitHub n\'a pas répondu depuis ce serveur : renseignez la dernière version publiée pour que « À mettre à jour » reste juste. Effacer ce champ rend la main à GitHub.', 'printgestion')) . "</div></div>";
    }

    /** Rappel de ce que le plugin fait et ne fait pas des mises à jour, sous les paramètres des paquets. */
    public static function getUpdateNotice(): string {
        return __('Le plugin ne pousse aucune mise à jour : rien ne part d\'ici vers un PC. Selon ces réglages, le fichier d\'installation pose sur le PC une tâche planifiée mensuelle (Windows : winget, compte SYSTEM ; Linux : cron ; macOS : service launchd — installeur officiel vérifié), qui ne fait rien tant que l\'agent travaille. Ensuite, seule une consigne lancée sur le PC change cette tâche (sur un Mac : relancer le fichier d\'installation de l\'entité). Microsoft ne prend pas officiellement en charge winget sous le compte SYSTEM : à vérifier au pilote.', 'printgestion');
    }

    /**
     * Statut GLPI donné aux PC qui servent de sonde, réglé là où on s'en sert : la page « Sondes », à côté du bouton
     * qui marque un PC. Il n'avait rien à faire au milieu des versions.
     */
    public static function showProbeStateForm(string $page): void {
        $esc   = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $state = self::getProbeStateId();
        if (!Session::haveRight('plugin_printgestion_config', UPDATE)) {
            echo "<p class='text-muted small mb-0'>" . $esc(sprintf(
                __('Statut donné aux PC sondes : %s.', 'printgestion'),
                $state > 0 ? Dropdown::getDropdownName(State::getTable(), $state) : __('aucun', 'printgestion')
            )) . "</p>";
            return;
        }
        echo "<form method='post' action='" . $esc($page) . "' class='row g-2 align-items-end' data-pg-admin='1'>";
        echo "<div class='col-md-5'><label class='form-label'>" . $esc(__('Statut GLPI des PC sondes', 'printgestion')) . "</label>"
            . State::dropdown(['name' => 'agent_probe_states_id', 'value' => $state, 'display' => false, 'entity' => 0, 'entity_sons' => true])
            . "<div class='form-hint'>" . $esc(__('Créez-le à la racine (récursif) dans Configuration > Intitulés > Statuts des éléments. Il sert à repérer les PC sondes dans le parc ; « Marquer ce PC comme sonde » le pose sur l\'ordinateur.', 'printgestion')) . "</div></div>";
        echo "<div class='col-md-3'><button type='submit' data-pg-submit-once='1' name='save_probe_state' value='1' class='btn btn-outline-primary'><i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer', 'printgestion')) . "</button></div>";
        echo Html::closeForm(false);
    }

    /**
     * Ce que l'installation a déclaré avoir fait sur le PC — la seule chose que GLPI en sache.
     *
     * Plus rien à comparer : depuis que la case a cédé la place à deux actions, GLPI ne garde aucun souhait, donc
     * aucun écart n'est possible. La ligne informe, elle ne juge pas. Et elle dit ce qui a été fait le jour de
     * l'installation, jamais ce que quelqu'un a pu changer depuis sur le PC.
     */
    private static function showRealState(Agent $agent): string {
        $rapport = PluginPrintgestionAgentreport::find(
            (int) $agent->fields['entities_id'],
            self::getHostName($agent->fields)
        );
        $texte = PluginPrintgestionAgentreport::describe($rapport);
        return $rapport === null
            ? "<p class='text-muted small mb-0'>" . htmlspecialchars($texte, ENT_QUOTES, 'UTF-8') . "</p>"
            : PluginPrintgestionUi::statusLine(!empty($rapport['scheduled']) ? 'ok' : 'info', $texte);
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

        $admin = PluginPrintgestionUi::isAdmin();
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Version de l\'agent', 'printgestion')) . "</h3></div><div class='card-body'>";
        if (!$admin) {
            // Technicien : l'état seul, sans numéro de version.
            echo "<span class='badge " . $compliance['class'] . "'>" . $esc($compliance['label']) . "</span></div></div>";
        } else {
        echo "<div class='row g-3' data-pg-admin='1'>";
        echo "<div class='col-md-3'><div class='text-muted small'>" . $esc(__('Version installée', 'printgestion')) . "</div><div class='fw-bold'>" . $esc($installed !== '' ? $installed : '—') . "</div></div>";
        echo "<div class='col-md-3'><div class='text-muted small'>" . $esc(__('Dernière version connue', 'printgestion')) . "</div><div class='fw-bold'>" . $esc($latest['version']) . "</div>"
            . "<div class='text-muted small'>" . $esc(self::getLatestSourceLabel($latest)) . "</div></div>";
        echo "<div class='col-md-3'><div class='text-muted small'>" . $esc(__('Version visée', 'printgestion')) . "</div><div class='fw-bold'>" . $esc($compliance['target'] !== '' ? $compliance['target'] : '—') . "</div>"
            . ($settings['target_version'] !== '' ? "<div class='text-muted small'>" . $esc(__('épinglée pour tout le parc', 'printgestion')) . "</div>" : '') . "</div>";
        echo "<div class='col-md-3'><div class='text-muted small'>" . $esc(__('État', 'printgestion')) . "</div><span class='badge " . $compliance['class'] . "'>" . $esc($compliance['label']) . "</span></div>";
        echo "</div></div></div>";
        }

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Mise à jour automatique', 'printgestion')) . "</h3></div><div class='card-body'>";
        // La seule chose que GLPI sache de ce PC : ce que l'installation lui a déclaré. Plus de case cochée qui
        // affirmerait un état que personne n'a vérifié.
        echo self::showRealState($agent);
        if ($admin && $settings['target_version'] !== '') {
            // La version cible est celle du parc : on la rappelle, et on dit où elle se règle. Une seule fois, au
            // même endroit pour toutes les sondes.
            echo "<p class='mt-2 mb-0' data-pg-admin='1'>" . $esc(sprintf(
                __('Version cible du parc : %s (page « Installeur GLPI Agent »).', 'printgestion'),
                $settings['target_version']
            )) . "</p>";
        }
        echo "<p class='mt-3 mb-2'>" . $esc(__('Ces trois boutons fabriquent un fichier à lancer sur le PC sonde : GLPI ne pousse rien. « Mettre à jour maintenant » met à jour une fois, sur le champ, sans rien changer à l\'automatisation.', 'printgestion')) . ' '
            . PluginPrintgestionUi::infoButton(__('Mise à jour automatique', 'printgestion'), $admin ? '<p>' . $esc(__('GLPI ne pousse aucune mise à jour : rien ne part d\'ici vers ce PC. La tâche de mise à jour y est posée par le fichier d\'installation (imposée si la page « Installeur GLPI Agent » le demande, sinon proposée au technicien) ; ensuite, seuls ces deux fichiers la posent ou la retirent, une fois lancés sur le PC. La ligne ci-dessus est la seule chose que GLPI sache de ce PC : ce que l\'installation lui a déclaré ce jour-là. La tâche elle-même ne met pas à jour sur commande — elle s\'exécute le 1er du mois à 3 h, et seulement si l\'agent est en attente. Retour à une version plus ancienne : renseignez la version cible, posez la consigne, et sachez que l\'installeur peut refuser de rétrograder (signalé dans le journal de la tâche) ; il faut alors désinstaller puis réinstaller avec le fichier de l\'entité.', 'printgestion')) . '</p>' : '') . "</p>";
        $platform = self::getHostPlatform($agent->fields);
        if ($platform === 'macos') {
            echo "<p class='mb-0'>" . $esc(__('Mac : la mise à jour automatique (service launchd, le 1er du mois à 3 h) se pose ou se retire en relançant le fichier d\'installation de l\'entité ; pas de consigne à part sous macOS.', 'printgestion')) . "</p>";
        } else {
            echo "<div class='d-flex flex-wrap gap-2'>";
            // Un bouton par action, et l'action dans le libellé : personne n'a à deviner ce que le fichier fera.
            foreach (['windows' => 'ti-brand-windows', 'linux' => 'ti-brand-ubuntu'] as $os => $icon) {
                if ($platform !== null && $platform !== $os) {
                    continue;
                }
                $suffixe = $platform === null ? sprintf(' (%s)', $os === 'windows' ? __('Windows', 'printgestion') : __('Linux', 'printgestion')) : '';
                echo "<a class='btn btn-primary' href='" . $esc(self::getConsigneURL($agents_id, $os, self::ACTION_MAINTENANT)) . "'><i class='ti ti-refresh me-1'></i>"
                    . $esc(__('Mettre à jour maintenant', 'printgestion') . $suffixe) . "</a>";
                echo "<a class='btn btn-outline-primary' href='" . $esc(self::getConsigneURL($agents_id, $os, self::ACTION_POSER)) . "'><i class='ti {$icon} me-1'></i>"
                    . $esc(__('Poser la mise à jour automatique', 'printgestion') . $suffixe) . "</a>";
                echo "<a class='btn btn-outline-secondary' href='" . $esc(self::getConsigneURL($agents_id, $os, self::ACTION_RETIRER)) . "'><i class='ti {$icon} me-1'></i>"
                    . $esc(__('Retirer la mise à jour automatique', 'printgestion') . $suffixe) . "</a>";
            }
            echo "</div>";
            if ($platform === null) {
                echo "<p class='text-muted small mt-2 mb-0'>" . $esc(__('Système du PC pas encore connu : prendre la consigne de son système.', 'printgestion')) . "</p>";
            }
        }
        echo "</div></div>";

        $coverage = array_keys(self::getCoverage()[$agents_id] ?? []);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(sprintf(__('Imprimantes collectées par cette sonde (%d)', 'printgestion'), count($coverage))) . "</h3></div><div class='card-body'>";
        if (empty($coverage)) {
            echo "<p class='text-muted mb-0'>" . $esc(__('Aucune imprimante collectée par cette sonde pour l\'instant.', 'printgestion')) . "</p></div></div>";
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
        $columns = [
            'printer'   => _n('Imprimante', 'Imprimantes', 1, 'printgestion'),
            'entity'    => Entity::getTypeName(1),
            'ip'        => __('Adresse IP', 'printgestion'),
            'inventory' => __('Dernier inventaire réseau réussi', 'printgestion'),
        ];
        $entries = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'entities_id'],
            'FROM'   => Printer::getTable(),
            'WHERE'  => ['id' => $coverage],
            'ORDER'  => ['name'],
        ]) as $printer) {
            $printers_id = (int) $printer['id'];
            $snmp        = $dates[$printers_id]['snmp'] ?? null;
            $entries[]   = [
                'itemtype'  => Printer::class,
                'id'        => $printers_id,
                'printer'   => "<a href='" . $esc(Printer::getFormURLWithID($printers_id)) . "'>" . $esc($printer['name']) . "</a>",
                'entity'    => Dropdown::getDropdownName(Entity::getTable(), (int) $printer['entities_id']),
                'ip'        => "<span class='font-monospace small'>" . $esc(implode(', ', $ips[$printers_id] ?? []) ?: '—') . "</span>",
                'inventory' => $esc($snmp !== null ? Html::convDateTime((string) $snmp) : __('aucun connu', 'printgestion'))
                    . ($snmp === null || (string) $snmp < $cutoff((int) $printer['entities_id']) ? " <span class='badge bg-red text-red-fg'>" . $esc(__('Muette', 'printgestion')) . "</span>" : ''),
            ];
        }
        // Gabarit natif de GLPI (components/datatable.html.twig) : le même rendu que les autres listes du plugin.
        echo PluginPrintgestionUi::datatable($columns, $entries, ['printer' => 'raw_html', 'ip' => 'raw_html', 'inventory' => 'raw_html']);
        echo "</div></div>";
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
        if ($probe_state === 0 && PluginPrintgestionUi::isAdmin()) {
            echo " <span class='text-muted small' data-pg-admin='1'>" . $esc(__('(aucun statut « PC sonde » choisi : il se règle sur la page « Sondes »)', 'printgestion')) . "</span>";
        }
        echo "</div></div></div>";
        self::showForAgent($agent);
    }

    static function uninstall(Migration $migration) {
        global $DB;

        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, 'PrintgestionCheckAgentVersion')) {
            $task->delete(['id' => (int) $task->getID()]);
        }
        $DB->dropTable(self::getTable(), true);
        return true;
    }
}
