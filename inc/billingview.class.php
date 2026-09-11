<?php
/**
 * PluginPrintgestionBillingview — table MATÉRIALISÉE du « coût à la page », pour
 * le moteur de recherche natif GLPI.
 *
 * Particularité vs alertview : le billing dépend d'une PÉRIODE choisie par
 * l'utilisateur (deltas de compteurs sur un intervalle) → impossible de
 * matérialiser une seule fois pour tous. On matérialise donc PAR UTILISATEUR :
 * le formulaire de filtres (période / client / vue) déclenche un recalcul
 * (PluginPrintgestionBilling::computeForPeriodCached, mis en cache 10 min) puis
 * réécrit les lignes de CET utilisateur dans la table. Search::showList() lit
 * alors la table avec tri / filtres / colonnes / export natifs.
 *
 * Isolation : addDefaultWhere restreint à users_id = utilisateur courant ET
 * view_mode = vue courante (stockée en session). La colonne entities_id permet
 * en plus la restriction d'entité native.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionBillingview extends CommonDBTM {

    static $rightname = 'plugin_printgestion_billing';

    static function getTypeName($nb = 0) {
        return __('Coût à la page', 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_billing_view';
        }
        return parent::getTable($classname);
    }

    public static function canView(): bool {
        return Session::haveRight('plugin_printgestion_billing', READ);
    }

    /** Restriction d'entité native : la table porte entities_id (entité imprimante). */
    function isEntityAssign() {
        return true;
    }

    /**
     * URL de la liste : pagination / tri / recherche natifs doivent revenir au
     * dashboard billing (sinon GLPI génère front/billingview.php → 404).
     */
    static function getSearchURL($full = true) {
        $dir = $full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR;
        return $dir . '/front/dashboard_billing.php';
    }

    static function install(Migration $migration) {
        global $DB;

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $charset   = DBConnection::getDefaultCharset();
            $collation = DBConnection::getDefaultCollation();
            $sign      = DBConnection::getDefaultPrimaryKeySignOption();

            $DB->doQuery("CREATE TABLE IF NOT EXISTS `$table` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `view_mode` enum('printer','client') NOT NULL DEFAULT 'printer',
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `printers_id` int {$sign} NOT NULL DEFAULT '0',
                `printer_name` varchar(255) DEFAULT NULL,
                `entity_name` varchar(255) DEFAULT NULL,
                `contracts_id` int {$sign} NOT NULL DEFAULT '0',
                `contract_name` varchar(255) DEFAULT NULL,
                `printers_count` int DEFAULT NULL,
                `pages_nb` int NOT NULL DEFAULT '0',
                `pages_color` int NOT NULL DEFAULT '0',
                `rate_nb` decimal(14,6) NOT NULL DEFAULT '0.000000',
                `rate_color` decimal(14,6) NOT NULL DEFAULT '0.000000',
                `total_cost` decimal(16,2) NOT NULL DEFAULT '0.00',
                `period_start` date DEFAULT NULL,
                `period_end` date DEFAULT NULL,
                `date_compute` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `users_id` (`users_id`),
                KEY `view_mode` (`view_mode`),
                KEY `entities_id` (`entities_id`),
                KEY `printers_id` (`printers_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC") or die($DB->error());
        }
        return true;
    }

    static function uninstall(Migration $migration) {
        global $DB;
        $DB->doQuery("DROP TABLE IF EXISTS `" . self::getTable() . "`");
        return true;
    }

    /**
     * Recalcule (cache 10 min) puis matérialise les lignes de billing pour
     * l'utilisateur courant, selon période / entité / vue. Remplace TOUTES les
     * lignes de cet utilisateur (les deux vues confondues), puis insère la vue
     * demandée — addDefaultWhere filtre ensuite sur view_mode.
     *
     * @return int Nombre de lignes matérialisées.
     */
    public static function rebuildForUser(
        int $users_id,
        string $start,
        string $end,
        ?int $entities_id,
        string $view
    ): int {
        global $DB;

        if (!in_array($view, ['printer', 'client'], true)) {
            $view = 'printer';
        }
        $table = self::getTable();
        $now   = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        // Calcul (lourd, mais mis en cache par computeForPeriodCached).
        $rows = PluginPrintgestionBilling::computeForPeriodCached($start, $end, $entities_id);

        // On efface uniquement les lignes de cet utilisateur (isolation par session).
        $DB->delete($table, ['users_id' => $users_id]);

        if ($view === 'client') {
            $rows = PluginPrintgestionBilling::groupByClient($rows);
            foreach ($rows as $r) {
                $DB->insert($table, [
                    'users_id'       => $users_id,
                    'view_mode'      => 'client',
                    'entities_id'    => (int) ($r['entities_id'] ?? 0),
                    'printers_id'    => 0,
                    'printer_name'   => null,
                    'entity_name'    => $r['entity_name'] ?? '',
                    'contracts_id'   => 0,
                    'contract_name'  => null,
                    'printers_count' => (int) ($r['printers'] ?? 0),
                    'pages_nb'       => (int) ($r['pages_nb'] ?? 0),
                    'pages_color'    => (int) ($r['pages_color'] ?? 0),
                    'rate_nb'        => 0,
                    'rate_color'     => 0,
                    'total_cost'     => (float) ($r['total_cost'] ?? 0),
                    'period_start'   => $start,
                    'period_end'     => $end,
                    'date_compute'   => $now,
                ]);
            }
        } else {
            foreach ($rows as $r) {
                $DB->insert($table, [
                    'users_id'       => $users_id,
                    'view_mode'      => 'printer',
                    'entities_id'    => (int) ($r['entities_id'] ?? 0),
                    'printers_id'    => (int) ($r['printers_id'] ?? 0),
                    'printer_name'   => $r['printer_name'] ?? '',
                    'entity_name'    => $r['entity_name'] ?? '',
                    'contracts_id'   => (int) ($r['contracts_id'] ?? 0),
                    'contract_name'  => ($r['contract_name'] ?? '') === '—' ? null : ($r['contract_name'] ?? null),
                    'printers_count' => null,
                    'pages_nb'       => (int) ($r['pages_nb'] ?? 0),
                    'pages_color'    => (int) ($r['pages_color'] ?? 0),
                    'rate_nb'        => (float) ($r['rate_nb'] ?? 0),
                    'rate_color'     => (float) ($r['rate_color'] ?? 0),
                    'total_cost'     => (float) ($r['total_cost'] ?? 0),
                    'period_start'   => $start,
                    'period_end'     => $end,
                    'date_compute'   => $now,
                ]);
            }
        }

        return count($rows);
    }

    /**
     * Métriques agrégées (cartes du haut) à partir des lignes matérialisées de
     * l'utilisateur courant. Cohérent avec ce qu'affiche le tableau natif.
     */
    public static function metricsForUser(int $users_id, string $view): array {
        global $DB;
        $table = self::getTable();

        $row = $DB->request([
            'SELECT' => [
                new \QueryExpression('COUNT(*) AS nb_rows'),
                new \QueryExpression('COALESCE(SUM(`pages_nb`),0) AS total_nb'),
                new \QueryExpression('COALESCE(SUM(`pages_color`),0) AS total_color'),
                new \QueryExpression('COALESCE(SUM(`total_cost`),0) AS total_cost'),
                new \QueryExpression('COALESCE(SUM(`printers_count`),0) AS sum_printers'),
                new \QueryExpression('COUNT(DISTINCT `entities_id`) AS nb_entities'),
            ],
            'FROM'  => $table,
            'WHERE' => ['users_id' => $users_id, 'view_mode' => $view],
        ])->current();

        $row = is_array($row) ? $row : [];
        if ($view === 'client') {
            $nb_clients  = (int) ($row['nb_rows'] ?? 0);
            $nb_printers = (int) ($row['sum_printers'] ?? 0);
        } else {
            $nb_printers = (int) ($row['nb_rows'] ?? 0);
            $nb_clients  = (int) ($row['nb_entities'] ?? 0);
        }

        return [
            'nb_printers' => $nb_printers,
            'nb_clients'  => $nb_clients,
            'total_nb'    => (int) ($row['total_nb'] ?? 0),
            'total_color' => (int) ($row['total_color'] ?? 0),
            'total_cost'  => (float) ($row['total_cost'] ?? 0),
        ];
    }

    // ── Affichage spécifique des tarifs (6 décimales + €) ─────────────────────
    static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'rate_nb':
            case 'rate_color':
                $v = (float) ($values[$field] ?? 0);
                if ($v <= 0) {
                    return '—';
                }
                return number_format($v, 6, ',', ' ') . ' €';
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public function rawSearchOptions() {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName()];

        $tab[] = [
            'id'            => '1',
            'table'         => 'glpi_printers',
            'field'         => 'name',
            'name'          => _n('Imprimante', 'Imprimantes', 1, 'printgestion'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '80',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => '2',
            'table'    => self::getTable(),
            'field'    => 'contract_name',
            'name'     => Contract::getTypeName(1),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'printers_count',
            'name'     => _n('Imprimante', 'Imprimantes', 2, 'printgestion'),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'pages_nb',
            'name'     => __('Pages N&B', 'printgestion'),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'pages_color',
            'name'     => __('Pages Couleur', 'printgestion'),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id'            => '6',
            'table'         => self::getTable(),
            'field'         => 'rate_nb',
            'name'          => __('Tarif N&B', 'printgestion'),
            'datatype'      => 'specific',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '7',
            'table'         => self::getTable(),
            'field'         => 'rate_color',
            'name'          => __('Tarif Couleur', 'printgestion'),
            'datatype'      => 'specific',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => '8',
            'table'    => self::getTable(),
            'field'    => 'total_cost',
            'name'     => __('Coût total', 'printgestion'),
            'datatype' => 'decimal',
        ];
        $tab[] = [
            'id'       => '9',
            'table'    => self::getTable(),
            'field'    => 'period_start',
            'name'     => __('Début période', 'printgestion'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id'       => '10',
            'table'    => self::getTable(),
            'field'    => 'period_end',
            'name'     => __('Fin période', 'printgestion'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id'       => '11',
            'table'    => self::getTable(),
            'field'    => 'date_compute',
            'name'     => __('Calculé le', 'printgestion'),
            'datatype' => 'datetime',
        ];

        return $tab;
    }
}
