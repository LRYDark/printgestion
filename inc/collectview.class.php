<?php
/**
 * État de la collecte de chaque imprimante, matérialisé pour le moteur de recherche natif de GLPI.
 *
 * Collect::analyze() calcule l'état de chaque imprimante (jamais inventoriée, muette, sans niveau lisible, collecte
 * normale), sa dernière date d'inventaire SNMP, sa dernière découverte et la sonde qui l'a relevée — un calcul trop
 * lourd pour être rejoué à chaque tri de tableau. Il est rangé ici, une ligne par imprimante, toutes entités
 * confondues : la lecture passe toujours par la restriction d'entité native (entities_id, is_recursive de
 * l'imprimante), et ces colonnes s'ajoutent aux listes natives des imprimantes (hook getAddSearchOptionsNew) comme
 * à celle du module (PluginPrintgestionPrintercollect).
 *
 * Rafraîchie par la tâche horaire des alertes, à l'affichage quand elle date de plus de STALE_SECONDS, et sur
 * demande (« Recalculer maintenant »). rebuild() recalcule aussi la vue des sondes, qui en dérive.
 */
class PluginPrintgestionCollectview extends CommonDBTM {

    const STALE_SECONDS = 900;

    /** Options de recherche ajoutées aux imprimantes : plage réservée au plugin, jamais réutilisée ailleurs. */
    const OPTION_STATE     = 74011;
    const OPTION_INVENTORY = 74012;
    const OPTION_DISCOVERY = 74013;
    const OPTION_AGENT     = 74014;

    static $rightname = 'plugin_printgestion_deploiement';

    private static bool $table_checked = false;

    public static function getTable($classname = null) {
        return 'glpi_plugin_printgestion_collectviews';
    }

    static function getTypeName($nb = 0) {
        return __('État de la collecte', 'printgestion');
    }

    // ── Calcul ─────────────────────────────────────────────────────────────

    /** La table existe — créée si elle manque : installation antérieure à cette vue, sans « Mettre à jour ». */
    public static function ensureTable(): void {
        if (self::$table_checked) {
            return;
        }
        self::$table_checked = true;
        PluginPrintgestionSchema::createIfMissing(self::getTable());
    }

    /** Recalcule la vue des imprimantes puis celle des sondes ; renvoie le nombre d'imprimantes rangées. */
    public static function rebuild(): int {
        global $DB;

        self::ensureTable();
        PluginPrintgestionAgentview::ensureTable();
        // Calcul lourd, TOUTES entités : la table est lue ensuite avec la restriction d'entité native.
        $analysis = PluginPrintgestionCollect::analyze(false);
        $now      = date('Y-m-d H:i:s');
        $DB->truncate(self::getTable());
        foreach ($analysis['printers'] as $printer) {
            $DB->insert(self::getTable(), [
                'printers_id'    => (int) $printer['id'],
                'entities_id'    => (int) ($printer['entities_id'] ?? 0),
                'is_recursive'   => (int) ($printer['is_recursive'] ?? 0),
                'state'          => (string) $printer['state'],
                'last_inventory' => $printer['last_inventory'],
                'last_discovery' => $printer['last_discovery'],
                'agents_id'      => (int) ($printer['agents_id'] ?? 0),
                'date_compute'   => $now,
            ]);
        }
        PluginPrintgestionAgentview::rebuild($analysis);
        return count($analysis['printers']);
    }

    /** Date du dernier calcul, ou null si la vue est vide. */
    public static function getComputedAt(): ?string {
        global $DB;

        self::ensureTable();
        $row = $DB->request(['SELECT' => [new QueryExpression('MAX(`date_compute`) AS `last`')], 'FROM' => self::getTable()])->current();
        return isset($row['last']) && $row['last'] !== null ? (string) $row['last'] : null;
    }

    /** Recalcule si la vue est vide ou plus vieille que STALE_SECONDS. */
    public static function rebuildIfStale(): void {
        $last = self::getComputedAt();
        if ($last === null || strtotime($last) < time() - self::STALE_SECONDS) {
            self::rebuild();
        }
    }

    /** Restriction aux entités de l'utilisateur : celle de l'imprimante, récursivité comprise. */
    private static function getRestriction(): array {
        return getEntitiesRestrictCriteria(self::getTable(), '', '', true);
    }

    /** Nombre d'imprimantes par état, dans les entités de l'utilisateur. */
    public static function getCounts(): array {
        global $DB;

        self::ensureTable();
        $counts = array_fill_keys(array_keys(PluginPrintgestionCollect::getStateLabels()), 0);
        foreach ($DB->request([
            'SELECT'  => ['state', 'COUNT' => 'id AS n'],
            'FROM'    => self::getTable(),
            'WHERE'   => self::getRestriction(),
            'GROUPBY' => ['state'],
        ]) as $row) {
            $counts[(string) $row['state']] = (int) $row['n'];
        }
        return $counts;
    }

    /** Imprimantes de la vue dans les entités de l'utilisateur (analyses de réglage de l'administrateur). */
    public static function getPrinterIds(): array {
        global $DB;

        self::ensureTable();
        $ids = [];
        foreach ($DB->request(['SELECT' => ['printers_id'], 'FROM' => self::getTable(), 'WHERE' => self::getRestriction()]) as $row) {
            $ids[] = (int) $row['printers_id'];
        }
        return $ids;
    }

    /** Prérequis de la collecte lus dans les vues : mêmes clés que Collect::getPrerequisites(). */
    public static function getPrerequisites(): array {
        global $DB;

        self::ensureTable();
        $plugin = new Plugin();
        $day    = date('Y-m-d H:i:s', time() - DAY_TIMESTAMP);
        $week   = date('Y-m-d H:i:s', time() - 7 * DAY_TIMESTAMP);
        $row    = $DB->request([
            'SELECT' => [
                new QueryExpression('COUNT(*) AS `printers`'),
                new QueryExpression('MAX(`last_inventory`) AS `last`'),
                new QueryExpression('COALESCE(SUM(`last_inventory` >= ' . $DB::quoteValue($day) . '), 0) AS `d1`'),
                new QueryExpression('COALESCE(SUM(`last_inventory` >= ' . $DB::quoteValue($week) . '), 0) AS `d7`'),
            ],
            'FROM'   => self::getTable(),
            'WHERE'  => self::getRestriction(),
        ])->current();
        $agents = PluginPrintgestionAgentview::getPrerequisiteCounts();

        return [
            'inventory_enabled'       => (bool) Config::getConfigurationValue('inventory', 'enabled_inventory'),
            'glpiinventory_installed' => $plugin->isInstalled('glpiinventory'),
            'glpiinventory_active'    => $plugin->isActivated('glpiinventory'),
            'printers'                => (int) ($row['printers'] ?? 0),
            'inventoried_24h'         => (int) ($row['d1'] ?? 0),
            'inventoried_7d'          => (int) ($row['d7'] ?? 0),
            'last_inventory'          => isset($row['last']) && $row['last'] !== null ? (string) $row['last'] : null,
            'agents'                  => $agents['agents'],
            'agents_silent'           => $agents['silent'],
            'agents_old'              => $agents['old'],
            'agents_update'           => $agents['update'],
        ];
    }

    // ── Options de recherche des imprimantes ───────────────────────────────

    /** Couleur (Tabler) de chaque état, pour les pastilles et les tuiles. */
    public static function getStateColors(): array {
        return [
            PluginPrintgestionCollect::STATE_NO_INVENTORY => 'secondary',
            PluginPrintgestionCollect::STATE_STALE        => 'red',
            PluginPrintgestionCollect::STATE_NO_LEVEL     => 'orange',
            PluginPrintgestionCollect::STATE_OK           => 'green',
        ];
    }

    /** Colonnes ajoutées aux imprimantes (listes natives et liste du module) : jointure sur la vue par printers_id. */
    public static function getSearchOptionsToAdd(): array {
        self::ensureTable();
        $table = self::getTable();
        $join  = ['jointype' => 'child'];
        return [
            ['id' => 'printgestion_collecte', 'name' => __('Print Gestion — collecte', 'printgestion')],
            ['id' => self::OPTION_STATE, 'table' => $table, 'field' => 'state', 'name' => __('État de la collecte (Print Gestion)', 'printgestion'),
             'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'joinparams' => $join, 'massiveaction' => false],
            ['id' => self::OPTION_INVENTORY, 'table' => $table, 'field' => 'last_inventory', 'name' => __('Dernier inventaire SNMP (Print Gestion)', 'printgestion'),
             'datatype' => 'datetime', 'joinparams' => $join, 'massiveaction' => false],
            ['id' => self::OPTION_DISCOVERY, 'table' => $table, 'field' => 'last_discovery', 'name' => __('Dernière découverte réseau (Print Gestion)', 'printgestion'),
             'datatype' => 'datetime', 'joinparams' => $join, 'massiveaction' => false],
            ['id' => self::OPTION_AGENT, 'table' => Agent::getTable(), 'field' => 'name', 'name' => __('Sonde (Print Gestion)', 'printgestion'),
             'datatype' => 'dropdown', 'massiveaction' => false,
             'joinparams' => ['beforejoin' => ['table' => $table, 'joinparams' => $join]]],
        ];
    }

    static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'state') {
            $state = (string) ($values[$field] ?? '');
            if ($state === '') {
                // Imprimante absente de la vue : pas encore calculée.
                return "<span class='text-muted'>—</span>";
            }
            $labels = PluginPrintgestionCollect::getStateLabels();
            $colors = self::getStateColors();
            return "<span class='badge bg-" . ($colors[$state] ?? 'secondary') . "-lt'>"
                . htmlspecialchars($labels[$state] ?? $state, ENT_QUOTES, 'UTF-8') . "</span>";
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'state') {
            $options['display'] = false;
            $options['value']   = $values[$field] ?? '';
            return Dropdown::showFromArray($name, PluginPrintgestionCollect::getStateLabels(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /** Liste du module filtrée sur un état (tuiles, liens). */
    public static function getStateURL(string $state): string {
        return PluginPrintgestionPrintercollect::getSearchURL() . '&' . http_build_query([
            'criteria' => [['field' => self::OPTION_STATE, 'searchtype' => 'equals', 'value' => $state]],
            'reset'    => 'reset',
        ]);
    }

    // ── Écran « Sondes & remontée » ─────────────────────────────────────────

    /**
     * L'écran de supervision du module : tuiles, prérequis, fraîcheur et recalcul, puis l'une des deux listes
     * natives — sondes (Agents de GLPI, colonnes du plugin) ou imprimantes (état de la collecte) — et, pour
     * l'administrateur, les analyses de réglage. Une seule liste native par page : c'est ce que le moteur de
     * recherche sait faire.
     */
    public static function showPage(string $vue): void {
        $esc        = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $admin      = PluginPrintgestionUi::isAdmin();
        $can_update = Session::haveRight(self::$rightname, UPDATE);
        $silent     = PluginPrintgestionCollect::getSilentDays();
        $agents     = PluginPrintgestionAgentview::getCounts();
        $counts     = self::getCounts();
        $labels     = PluginPrintgestionCollect::getStateLabels();
        $colors     = self::getStateColors();
        $icons      = [
            PluginPrintgestionCollect::STATE_NO_INVENTORY => 'ti ti-help-circle',
            PluginPrintgestionCollect::STATE_STALE        => 'ti ti-wifi-off',
            PluginPrintgestionCollect::STATE_NO_LEVEL     => 'ti ti-droplet-off',
            PluginPrintgestionCollect::STATE_OK           => 'ti ti-check',
        ];

        // ── Tuiles : sondes puis imprimantes, chacune ouvrant la liste filtrée ──
        $tiles = [
            [
                'count'   => $agents['silent'],
                'label'   => __('Sondes sans contact', 'printgestion'),
                'tooltip' => sprintf(__('Sondes sans contact depuis plus de %d jours', 'printgestion'), $silent),
                'icon'    => 'ti ti-wifi-off',
                'color'   => $agents['silent'] > 0 ? 'red' : 'green',
                'url'     => PluginPrintgestionAgentview::getListURL([PluginPrintgestionAgentview::OPTION_SILENT => 1]),
            ],
            [
                'count' => $agents['update'],
                'label' => __('Sondes à mettre à jour', 'printgestion'),
                'icon'  => 'ti ti-refresh',
                'color' => $agents['update'] > 0 ? 'orange' : 'green',
                'url'   => PluginPrintgestionAgentview::getListURL([PluginPrintgestionAgentview::OPTION_COMPLIANCE => 'update']),
            ],
        ];
        if ($admin && $agents['ahead'] > 0) {
            // Plus récentes que ce que le serveur distribue : après un retour arrière de la version du parc.
            $tiles[] = [
                'count' => $agents['ahead'],
                'label' => __('Sondes plus récentes que le parc', 'printgestion'),
                'icon'  => 'ti ti-arrow-up',
                'color' => 'blue',
                'url'   => PluginPrintgestionAgentview::getListURL([PluginPrintgestionAgentview::OPTION_COMPLIANCE => 'ahead']),
            ];
        }
        foreach ($labels as $state => $label) {
            $tiles[] = [
                'count' => $counts[$state],
                'label' => sprintf(__('Imprimantes : %s', 'printgestion'), $label),
                'icon'  => $icons[$state],
                'color' => $colors[$state],
                'url'   => self::getStateURL($state),
            ];
        }
        if ($admin) {
            $latest  = PluginPrintgestionAgentsetting::getLatestVersion();
            $tiles[] = [
                'count'   => $latest['version'],
                'label'   => __('Dernière version connue de GLPI Agent', 'printgestion'),
                'tooltip' => PluginPrintgestionAgentsetting::getLatestSourceLabel($latest),
                'icon'    => 'ti ti-package',
                'color'   => 'secondary',
            ];
        }
        PluginPrintgestionUi::statsBar($tiles, 'printgestionSupervisionStatsBar');

        // ── Prérequis, fraîcheur, recalcul ──
        PluginPrintgestionCollect::showPrerequisitesLine(self::getPrerequisites());
        $computed = self::getComputedAt();
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<span class='text-muted small'>" . $esc($computed !== null
            ? sprintf(__('État calculé le %s', 'printgestion'), Html::convDateTime($computed))
            : __('État pas encore calculé', 'printgestion')) . "</span>";
        if ($can_update) {
            $page = $vue === 'imprimantes' ? PluginPrintgestionPrintercollect::getSearchURL() : PluginPrintgestionSonde::getSearchURL();
            echo "<form method='post' action='" . $esc($page) . "' class='ms-auto'>";
            echo "<button type='submit' name='recompute_views' value='1' class='btn btn-sm btn-outline-secondary'>"
                . "<i class='ti ti-refresh me-1'></i>" . $esc(__('Recalculer maintenant', 'printgestion')) . "</button>";
            Html::closeForm();
        }
        echo PluginPrintgestionUi::infoButton(__('Sondes & remontée', 'printgestion'), $admin
            ? "<p>" . $esc(__('Sonde : agent installé avec l\'inventaire réseau, ou qui collecte au moins une imprimante. Imprimante : ce que l\'inventaire GLPI reçoit réellement, avant tout calcul d\'alerte.', 'printgestion')) . "</p>"
                . "<p>" . $esc(sprintf(__('Date de référence : dernier inventaire réseau (SNMP) du journal d\'import GLPI ; une simple découverte réseau ne compte pas. Muette : aucun inventaire depuis plus de %d jour(s) (Configuration → Print Gestion). L\'état est recalculé toutes les heures, à l\'ouverture de l\'écran s\'il date de plus de quinze minutes, et sur demande.', 'printgestion'), $silent)) . "</p>"
                . "<p class='mb-0'>" . $esc(__('Les mêmes colonnes existent dans Administration → Agents et Parc → Imprimantes : c\'est là que se font les actions massives sur ces objets, avec leurs droits natifs.', 'printgestion')) . "</p>"
            : '');
        echo "</div>";

        // ── Deux vues : sondes, imprimantes ──
        $pills = [
            'sondes'      => [PluginPrintgestionSonde::getSearchURL(), 'ti ti-robot', _n('Sonde', 'Sondes', Session::getPluralNumber(), 'printgestion')],
            'imprimantes' => [PluginPrintgestionPrintercollect::getSearchURL(), 'ti ti-printer', __('Imprimantes collectées', 'printgestion')],
        ];
        echo "<ul class='nav nav-pills mb-3'>";
        foreach ($pills as $key => [$url, $icon, $label]) {
            echo "<li class='nav-item'><a class='nav-link" . ($key === $vue ? ' active' : '') . "' href='" . $esc($url) . "'><i class='{$icon} me-1'></i>" . $esc($label) . "</a></li>";
        }
        echo "</ul>";

        if ($vue === 'imprimantes') {
            self::showPrintersList($admin);
            if ($admin) {
                PluginPrintgestionCollect::showAdminAnalyses(self::getPrinterIds());
            }
        } else {
            self::showProbesList($admin);
        }
    }

    /** Sondes : liste native des Agents (type dédié PluginPrintgestionSonde), colonnes du plugin, clic droit. */
    private static function showProbesList(bool $admin): void {
        $itemtype = PluginPrintgestionSonde::class;
        $params   = Search::manageParams($itemtype, $_GET);
        $params['target']             = PluginPrintgestionSonde::getSearchURL();
        // Pas d'action massive sur un type dérivé : elles se font sur l'Agent lui-même (Administration > Agents).
        $params['showmassiveactions'] = false;
        // Nom, entité, dernier contact, sans contact, imprimantes collectées, dernier inventaire réseau ; les
        // versions et la mise à jour déclarée pour l'administrateur (absentes, pas cachées).
        $forced = [1, 2, 4, PluginPrintgestionAgentview::OPTION_SILENT, PluginPrintgestionAgentview::OPTION_PRINTERS, PluginPrintgestionAgentview::OPTION_INVENTORY];
        if ($admin) {
            $forced = array_merge($forced, [8, PluginPrintgestionAgentview::OPTION_COMPLIANCE, PluginPrintgestionAgentview::OPTION_UPDATE]);
        }
        echo "<div class='search_page row'><div class='col search-container' data-glpi-search-container>";
        Search::showList($itemtype, $params, $forced);
        echo "</div></div>";
        PluginPrintgestionContextmenu::render($itemtype);
    }

    /** Imprimantes : liste native des Imprimantes (type dédié PluginPrintgestionPrintercollect), état de la collecte. */
    private static function showPrintersList(bool $admin): void {
        $itemtype = PluginPrintgestionPrintercollect::class;
        $params   = Search::manageParams($itemtype, $_GET);
        $params['target']             = PluginPrintgestionPrintercollect::getSearchURL();
        $params['showmassiveactions'] = false;
        // Nom, entité, état, dernier inventaire SNMP ; découverte et sonde pour l'administrateur.
        $forced = [1, 80, self::OPTION_STATE, self::OPTION_INVENTORY];
        if ($admin) {
            $forced = array_merge($forced, [self::OPTION_DISCOVERY, self::OPTION_AGENT]);
        }
        echo "<div class='search_page row'><div class='col search-container' data-glpi-search-container>";
        Search::showList($itemtype, $params, $forced);
        echo "</div></div>";
        PluginPrintgestionContextmenu::render($itemtype);
    }
}
