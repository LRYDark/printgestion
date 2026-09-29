<?php
/**
 * Ce que le plugin sait de chaque agent GLPI en tant que sonde, matérialisé pour le moteur de recherche natif.
 *
 * Sonde ou non (inventaire réseau installé, ou au moins une imprimante collectée), sans contact, conformité de
 * version, imprimantes collectées, mise à jour automatique déclarée par l'installation, dernier inventaire réseau
 * réussi : autant de choses que la fiche native de l'Agent ne connaît pas, calculées ici une ligne par agent, toutes
 * entités confondues (lecture toujours sous la restriction d'entité native). Les colonnes s'ajoutent à la liste
 * native des Agents (hook getAddSearchOptionsNew) comme à celle du module (PluginPrintgestionSonde).
 *
 * Recalculée avec la vue des imprimantes (PluginPrintgestionCollectview::rebuild()), dont elle tire les dates.
 */
class PluginPrintgestionAgentview extends CommonDBTM {

    /** Options de recherche ajoutées aux agents : plage réservée au plugin, jamais réutilisée ailleurs. */
    const OPTION_PROBE      = 74001;
    const OPTION_SILENT     = 74002;
    const OPTION_COMPLIANCE = 74003;
    const OPTION_PRINTERS   = 74004;
    const OPTION_UPDATE     = 74005;
    const OPTION_INVENTORY  = 74006;

    static $rightname = 'plugin_printgestion_deploiement';

    private static bool $table_checked = false;

    public static function getTable($classname = null) {
        return 'glpi_plugin_printgestion_agentviews';
    }

    static function getTypeName($nb = 0) {
        return __('Sonde Print Gestion', 'printgestion');
    }

    // ── Calcul ─────────────────────────────────────────────────────────────

    public static function ensureTable(): void {
        if (self::$table_checked) {
            return;
        }
        self::$table_checked = true;
        PluginPrintgestionSchema::createIfMissing(self::getTable());
    }

    /**
     * Recalcule la vue : une ligne par agent de GLPI, sonde ou non.
     *
     * @param ?array $analysis résultat de Collect::analyze(false), ou null pour le calculer ici
     */
    public static function rebuild(?array $analysis = null): int {
        global $DB;

        self::ensureTable();
        if ($analysis === null) {
            $analysis = PluginPrintgestionCollect::analyze(false);
        }
        $coverage = PluginPrintgestionAgentsetting::getCoverage();
        $probes   = PluginPrintgestionAgentalert::getProbes(false, $coverage);
        $settings = PluginPrintgestionAgentsetting::getSettingsFor(array_keys($probes));
        $latest   = PluginPrintgestionAgentsetting::getLatestVersion();
        $reports  = PluginPrintgestionAgentreport::index();
        $cutoff   = date('Y-m-d H:i:s', time() - PluginPrintgestionCollect::getSilentDays() * DAY_TIMESTAMP);

        // Dernier inventaire réseau réussi par sonde : la plus récente des dates d'inventaire de ses imprimantes.
        $last_inventory = [];
        foreach ($analysis['printers'] as $printer) {
            $agents_id = (int) ($printer['agents_id'] ?? 0);
            $date      = $printer['last_inventory'] ?? null;
            if ($agents_id > 0 && $date !== null && (!isset($last_inventory[$agents_id]) || (string) $date > $last_inventory[$agents_id])) {
                $last_inventory[$agents_id] = (string) $date;
            }
        }

        $now   = date('Y-m-d H:i:s');
        $count = 0;
        $DB->truncate(self::getTable());
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id', 'last_contact', 'version', 'itemtype', 'items_id'],
            'FROM'   => Agent::getTable(),
        ]) as $agent) {
            $agents_id = (int) $agent['id'];
            $probe     = $probes[$agents_id] ?? null;
            $version   = PluginPrintgestionAgentsetting::getAgentVersion($agent);
            $report    = $reports[PluginPrintgestionAgentreport::key((int) $agent['entities_id'], PluginPrintgestionAgentsetting::getHostName($agent))] ?? null;
            $declared  = 'none';
            if ($report !== null) {
                $declared = !empty($report['removed']) ? 'retire' : (!empty($report['scheduled']) ? 'posee' : 'non_posee');
            }
            $agent_settings = $settings[$agents_id] ?? PluginPrintgestionAgentsetting::getSettings($agents_id);
            $compliance     = $probe !== null ? PluginPrintgestionAgentsetting::getCompliance($version, $agent_settings, $latest) : null;
            $DB->insert(self::getTable(), [
                'agents_id'              => $agents_id,
                'entities_id'            => (int) $agent['entities_id'],
                'is_probe'               => $probe !== null ? 1 : 0,
                'is_silent'              => empty($agent['last_contact']) || (string) $agent['last_contact'] < $cutoff ? 1 : 0,
                'compliance'             => $compliance['state'] ?? null,
                'target_version'         => $compliance['target'] ?? null,
                'version_status'         => $probe !== null ? PluginPrintgestionCollect::getAgentVersionStatus($version, $agent_settings) : null,
                'printers_count'         => count($coverage[$agents_id] ?? []),
                'update_declared'        => $declared,
                'last_network_inventory' => $last_inventory[$agents_id] ?? null,
                'date_compute'           => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /** Sondes des entités de l'utilisateur : total, sans contact, à mettre à jour, plus récentes que le parc. */
    public static function getCounts(): array {
        global $DB;

        self::ensureTable();
        $row = $DB->request([
            'SELECT' => [
                new QueryExpression('COUNT(*) AS `probes`'),
                new QueryExpression('COALESCE(SUM(`is_silent` = 1), 0) AS `silent`'),
                new QueryExpression("COALESCE(SUM(`compliance` = 'update'), 0) AS `update`"),
                new QueryExpression("COALESCE(SUM(`compliance` = 'ahead'), 0) AS `ahead`"),
            ],
            'FROM'   => self::getTable(),
            'WHERE'  => array_merge(['is_probe' => 1], getEntitiesRestrictCriteria(self::getTable(), '', '', false)),
        ])->current();
        return [
            'probes' => (int) ($row['probes'] ?? 0),
            'silent' => (int) ($row['silent'] ?? 0),
            'update' => (int) ($row['update'] ?? 0),
            'ahead'  => (int) ($row['ahead'] ?? 0),
        ];
    }

    /** Agents qui collectent des imprimantes, pour la ligne des prérequis : total, sans contact, trop anciens, à mettre à jour. */
    public static function getPrerequisiteCounts(): array {
        global $DB;

        self::ensureTable();
        $row = $DB->request([
            'SELECT' => [
                new QueryExpression('COUNT(*) AS `agents`'),
                new QueryExpression('COALESCE(SUM(`is_silent` = 1), 0) AS `silent`'),
                new QueryExpression("COALESCE(SUM(`version_status` = 'old'), 0) AS `old`"),
                new QueryExpression("COALESCE(SUM(`version_status` = 'update'), 0) AS `update`"),
            ],
            'FROM'   => self::getTable(),
            'WHERE'  => array_merge(['printers_count' => ['>', 0]], getEntitiesRestrictCriteria(self::getTable(), '', '', false)),
        ])->current();
        return [
            'agents' => (int) ($row['agents'] ?? 0),
            'silent' => (int) ($row['silent'] ?? 0),
            'old'    => (int) ($row['old'] ?? 0),
            'update' => (int) ($row['update'] ?? 0),
        ];
    }

    // ── Options de recherche des agents ────────────────────────────────────

    /** Conformité de version : libellé et classe de pastille par état. */
    public static function getComplianceLabels(): array {
        return [
            'ok'        => [__('À jour', 'printgestion'), 'bg-green text-green-fg'],
            'update'    => [__('À mettre à jour', 'printgestion'), 'bg-orange text-orange-fg'],
            'ahead'     => [__('Plus récente que le parc', 'printgestion'), 'bg-blue text-blue-fg'],
            'unknown'   => [__('Version installée inconnue', 'printgestion'), 'bg-secondary text-secondary-fg'],
            'no_latest' => [__('Version du parc inconnue', 'printgestion'), 'bg-secondary text-secondary-fg'],
        ];
    }

    /** Mise à jour automatique déclarée par le fichier d'installation, ou par le retrait. */
    public static function getUpdateLabels(): array {
        return [
            'none'      => __('non déclarée', 'printgestion'),
            'posee'     => __('posée', 'printgestion'),
            'non_posee' => __('non posée', 'printgestion'),
            'retire'    => __('agent retiré', 'printgestion'),
        ];
    }

    /**
     * Colonnes ajoutées aux agents : jointure sur la vue par agents_id.
     *
     * Dans la liste native de GLPI (hook), sous un groupe « Print Gestion » : le moteur préfixe alors chaque en-tête
     * du nom du groupe. Dans la liste du module (rawSearchOptions du type dédié), sans groupe : en-tête nu.
     * `searchequalsonfield` : sans lui, « égal » sur une table jointe compare son `id`, pas la colonne.
     */
    public static function getOptionsForAgents(bool $with_group = true): array {
        self::ensureTable();
        $table   = self::getTable();
        $join    = ['jointype' => 'child'];
        $options = [
            ['id' => self::OPTION_PROBE, 'table' => $table, 'field' => 'is_probe', 'name' => __('Sonde', 'printgestion'),
             'datatype' => 'bool', 'searchequalsonfield' => true, 'joinparams' => $join, 'massiveaction' => false],
            ['id' => self::OPTION_SILENT, 'table' => $table, 'field' => 'is_silent', 'name' => __('Sans contact', 'printgestion'),
             'datatype' => 'bool', 'searchequalsonfield' => true, 'joinparams' => $join, 'massiveaction' => false],
            ['id' => self::OPTION_COMPLIANCE, 'table' => $table, 'field' => 'compliance', 'name' => __('Conformité de version', 'printgestion'),
             'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'searchequalsonfield' => true, 'joinparams' => $join, 'massiveaction' => false],
            ['id' => self::OPTION_PRINTERS, 'table' => $table, 'field' => 'printers_count', 'name' => __('Imprimantes collectées', 'printgestion'),
             'datatype' => 'number', 'searchequalsonfield' => true, 'joinparams' => $join, 'massiveaction' => false],
            ['id' => self::OPTION_UPDATE, 'table' => $table, 'field' => 'update_declared', 'name' => __('Mise à jour automatique déclarée', 'printgestion'),
             'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'searchequalsonfield' => true, 'joinparams' => $join, 'massiveaction' => false],
            ['id' => self::OPTION_INVENTORY, 'table' => $table, 'field' => 'last_network_inventory', 'name' => __('Dernier inventaire réseau réussi', 'printgestion'),
             'datatype' => 'datetime', 'searchequalsonfield' => true, 'joinparams' => $join, 'massiveaction' => false],
        ];
        return $with_group ? array_merge([['id' => 'printgestion', 'name' => 'Print Gestion']], $options) : $options;
    }

    static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $esc   = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $value = (string) ($values[$field] ?? '');
        switch ($field) {
            case 'compliance':
                if ($value === '') {
                    return "<span class='text-muted'>—</span>";
                }
                [$label, $class] = self::getComplianceLabels()[$value] ?? [$value, 'bg-secondary text-secondary-fg'];
                // La version visée du parc est un détail d'administrateur : le technicien voit l'état, pas le numéro.
                $target = (string) ($values['target_version'] ?? ($options['raw_data']['target_version'] ?? ''));
                if ($target !== '' && in_array($value, ['update', 'ahead'], true) && PluginPrintgestionUi::isAdmin()) {
                    $label .= ' (' . $target . ')';
                }
                return "<span class='badge {$class}'>" . $esc($label) . "</span>";

            case 'update_declared':
                return $value === '' ? "<span class='text-muted'>—</span>" : $esc(self::getUpdateLabels()[$value] ?? $value);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        $options['value']   = $values[$field] ?? '';
        switch ($field) {
            case 'compliance':
                return Dropdown::showFromArray($name, array_map(static fn(array $c): string => $c[0], self::getComplianceLabels()), $options);
            case 'update_declared':
                return Dropdown::showFromArray($name, self::getUpdateLabels(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /** Liste des sondes du module, filtrée : option => valeur (recherche « égal »). */
    public static function getListURL(array $filters): string {
        $criteria = [];
        foreach ($filters as $option => $value) {
            $criteria[] = ['field' => (int) $option, 'searchtype' => 'equals', 'value' => $value];
        }
        return PluginPrintgestionSonde::getSearchURL() . '?' . http_build_query(['criteria' => $criteria, 'reset' => 'reset']);
    }
}
