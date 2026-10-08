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

    /**
     * Icône de l'itemtype, reprise par GLPI dans les listes, les en-têtes et les onglets. Écran de facturation : ce que le parc a coûté sur la période.
     *
     * Sans elle, GLPI retombe sur l'icône par défaut de CommonDBTM, qui ne montre rien.
     */
    static function getIcon() {
        return 'ti ti-report-money';
    }

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

    // La table est créée par le schéma versionné (PluginPrintgestionSchema).

    static function uninstall(Migration $migration) {
        global $DB;
        $DB->dropTable(self::getTable(), true);
        return true;
    }

    /** Durée de vie de l'empreinte des lignes matérialisées d'un utilisateur ($GLPI_CACHE). */
    const MARKER_TTL = 86400;

    /**
     * Recalcule (cache 10 min) puis matérialise les lignes de billing pour
     * l'utilisateur courant, selon période / entité / vue. Remplace TOUTES les
     * lignes de cet utilisateur (les deux vues confondues), puis insère la vue
     * demandée — addDefaultWhere filtre ensuite sur view_mode.
     *
     * Appelée à chaque affichage du dashboard, pagination / tri / colonnes natifs
     * compris : l'effacement + réinsertion n'a lieu que si les lignes à écrire
     * diffèrent de celles déjà en place. L'empreinte de ce qui a été écrit
     * (lignes calculées, vue, période, compteur d'invalidation) est gardée dans
     * $GLPI_CACHE par utilisateur ; identique et même nombre de lignes en table,
     * seule la date de calcul est remise à l'heure, comme le faisait la réécriture.
     *
     * @return int Nombre de lignes matérialisées.
     */
    public static function rebuildForUser(
        int $users_id,
        string $start,
        string $end,
        ?int $entities_id,
        string $view,
        array $filters = []
    ): int {
        global $DB, $GLPI_CACHE;

        $started = microtime(true);
        if (!in_array($view, ['printer', 'client'], true)) {
            $view = 'printer';
        }
        $table = self::getTable();
        $now   = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        // Calcul (lourd, mais mis en cache par computeForPeriodCached).
        $rows = PluginPrintgestionBilling::computeForPeriodCached($start, $end, $entities_id, $filters);
        if ($view === 'client') {
            $rows = PluginPrintgestionBilling::groupByClient($rows);
        }

        // Empreinte de ce que la réécriture produirait (hors date de calcul). Le
        // compteur d'invalidation y entre : « Rafraîchir » force la reconstruction.
        $signature  = md5(serialize([PluginPrintgestionBilling::cacheVersion(), $view, $start, $end, $rows]));
        $marker_key = 'plugin_printgestion_billingview_u' . $users_id;
        if (isset($GLPI_CACHE)) {
            $marker = $GLPI_CACHE->get($marker_key);
            if (is_array($marker)
                && ($marker['signature'] ?? '') === $signature
                && (int) ($marker['count'] ?? -1) === count($rows)
                && self::countRowsForUser($users_id) === count($rows)) {
                if (count($rows) > 0) {
                    $DB->update($table, ['date_compute' => $now], ['users_id' => $users_id]);
                }
                return count($rows);
            }
            // Réécriture : l'empreinte n'est reposée qu'après succès complet.
            $GLPI_CACHE->delete($marker_key);
        }

        // Une seule réécriture à la fois par utilisateur (deux onglets, double clic) :
        // dans une transaction, un seul commit au lieu d'un par ligne. Verrou déjà pris :
        // réécriture comme avant, sans transaction ni empreinte (prochain affichage = reconstruction).
        $lock = 'billingview_' . $users_id;
        if (PluginPrintgestionLogger::lock($lock)) {
            try {
                $DB->beginTransaction();
                try {
                    self::writeRows($table, $users_id, $view, $rows, $start, $end, $now);
                    $DB->commit();
                } catch (Throwable $e) {
                    $DB->rollBack();
                    throw $e;
                }
                if (isset($GLPI_CACHE)) {
                    $GLPI_CACHE->set($marker_key, ['signature' => $signature, 'count' => count($rows)], self::MARKER_TTL);
                }
            } finally {
                PluginPrintgestionLogger::releaseLock($lock);
            }
        } else {
            self::writeRows($table, $users_id, $view, $rows, $start, $end, $now);
            if (isset($GLPI_CACHE)) {
                $GLPI_CACHE->delete($marker_key);
            }
        }

        PluginPrintgestionLogger::duration(
            'facturation',
            'Matérialisation du coût à la page',
            $started,
            sprintf('%d ligne(s), vue %s, du %s au %s, utilisateur %d', count($rows), $view, $start, $end, $users_id)
        );

        return count($rows);
    }

    /** Nombre de lignes matérialisées de l'utilisateur, toutes vues confondues. */
    private static function countRowsForUser(int $users_id): int {
        global $DB;
        $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
        ])->current();
        return (int) ($row['cpt'] ?? 0);
    }

    /**
     * Efface les lignes de l'utilisateur puis insère celles de la vue demandée
     * ($rows déjà regroupées par client pour la vue client).
     */
    private static function writeRows(
        string $table,
        int $users_id,
        string $view,
        array $rows,
        string $start,
        string $end,
        string $now
    ): void {
        global $DB;

        // On efface uniquement les lignes de cet utilisateur (isolation par session).
        $DB->delete($table, ['users_id' => $users_id]);

        if ($view === 'client') {
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
            // Défense en profondeur : les lignes sont déjà calculées sur le périmètre
            // de l'utilisateur, les totaux ne doivent pas pouvoir le dépasser.
            'WHERE' => array_merge(
                ['users_id' => $users_id, 'view_mode' => $view],
                getEntitiesRestrictCriteria($table, '', '', false)
            ),
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
