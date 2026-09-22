<?php
/**
 * PluginPrintgestionEntityscope — entité des données client du plugin.
 *
 * Les tables métier portent `entities_id` et `is_recursive` : GLPI restreint alors nativement
 * ce qui s'appuie sur ses mécanismes (canView / canUpdate d'un objet, moteur de recherche, actions de
 * masse). Les contrôles applicatifs (PluginPrintgestionSecurity) restent en seconde barrière et lisent
 * la même entité.
 *
 * Deux règles, selon la nature de la donnée :
 *  - Technique (relevés toner, rendements, historique des cartouches, seuils, mises en veille) : la ligne
 *    suit son imprimante. Quand l'imprimante change d'entité (fiche ou transfert), ses lignes suivent
 *    aussitôt ; un tarif suit de même son contrat. La tâche quotidienne PrintgestionEntityScope recale ces
 *    tables et journalise en erreur chaque écart : il révèle un chemin d'écriture qui a oublié l'entité.
 *  - Commerciale (expéditions, alertes, liaisons BL, demandes et leurs lignes) : l'entité est figée à la
 *    création et n'est plus jamais recalculée. Une imprimante transférée d'un client à un autre laisse son
 *    historique commercial chez le premier. Un écart avec l'entité actuelle de l'imprimante est normal :
 *    ni la tâche ni aucun crochet ne lit ces tables.
 *
 * Ligne orpheline : son objet de rattachement a été purgé et son entité n'a pas pu être déterminée
 * (entité racine, non récursive : invisible des comptes clients). findOrphans() les liste pour qu'un
 * administrateur tranche (configuration du plugin) ; rien n'est supprimé ni rattaché d'office.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionEntityscope {

    /** Données techniques rattachées à une imprimante (colonne printers_id) : suivent l'imprimante. */
    const PRINTER_TABLES = [
        'glpi_plugin_printgestion_alert_snoozes',
        'glpi_plugin_printgestion_cartridge_history',
        'glpi_plugin_printgestion_toner_readings',
        'glpi_plugin_printgestion_historical_yields',
        'glpi_plugin_printgestion_printer_thresholds',
    ];

    /** Données rattachées à un contrat : suivent le contrat. table => [table parente, colonne de liaison]. */
    const CONTRACT_TABLES = [
        'glpi_plugin_printgestion_contractrates' => ['glpi_contracts', 'contracts_id'],
    ];

    /** Données commerciales : entité figée à la création. table => [table de rattachement, colonne]. */
    const FROZEN_TABLES = [
        'glpi_plugin_printgestion_expeditions'    => ['glpi_printers', 'printers_id'],
        'glpi_plugin_printgestion_alerts'         => ['glpi_printers', 'printers_id'],
        'glpi_plugin_printgestion_expedition_bls' => ['glpi_plugin_printgestion_expeditions', 'expeditions_id'],
        'glpi_plugin_printgestion_demandelines'   => ['glpi_plugin_printgestion_demandes', 'plugin_printgestion_demandes_id'],
    ];

    /** @var array<string, array{entities_id: int, is_recursive: int}> */
    private static array $cache = [];

    /**
     * Entité et récursivité d'une imprimante, à écrire sur une ligne qui lui est rattachée. Imprimante
     * introuvable : entité racine, non récursive (visible des seuls comptes de la racine), avec une trace.
     *
     * @return array{entities_id: int, is_recursive: int}
     */
    public static function forPrinter(int $printers_id): array {
        return self::fromTable('glpi_printers', $printers_id);
    }

    /** @return array{entities_id: int, is_recursive: int} */
    public static function forExpedition(int $expeditions_id): array {
        return self::fromTable('glpi_plugin_printgestion_expeditions', $expeditions_id);
    }

    /** @return array{entities_id: int, is_recursive: int} */
    public static function forContract(int $contracts_id): array {
        return self::fromTable('glpi_contracts', $contracts_id);
    }

    private static function fromTable(string $table, int $id): array {
        global $DB;

        $key = $table . '#' . $id;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $row = $id > 0 ? $DB->request([
            'SELECT' => ['entities_id', 'is_recursive'],
            'FROM'   => $table,
            'WHERE'  => ['id' => $id],
            'LIMIT'  => 1,
        ])->current() : null;
        if (!is_array($row)) {
            PluginPrintgestionLogger::warning('entityscope', sprintf('%s #%d introuvable : ligne rattachée à l\'entité racine, non récursive.', $table, $id));
            return ['entities_id' => 0, 'is_recursive' => 0];
        }
        return self::$cache[$key] = ['entities_id' => (int) $row['entities_id'], 'is_recursive' => (int) $row['is_recursive']];
    }

    /**
     * Recale l'entité des données techniques sur celle de leur imprimante ou de leur contrat. Ne lit jamais
     * les tables commerciales (FROZEN_TABLES).
     *
     * @param string|null $parent_table glpi_printers ou glpi_contracts pour ne traiter que ce qui en dépend ; null : tout
     * @param int|null    $parent_id    identifiant de cet objet ; null : tous
     * @return array<string, int> table => lignes corrigées
     */
    public static function reconcile(?string $parent_table = null, ?int $parent_id = null): array {
        global $DB;

        $fixed = [];
        $jobs  = [];
        foreach (self::PRINTER_TABLES as $table) {
            $jobs[$table] = ['glpi_printers', 'printers_id'];
        }
        $jobs += self::CONTRACT_TABLES;
        foreach ($jobs as $table => [$source, $column]) {
            if (($parent_table !== null && $source !== $parent_table)
                || !$DB->tableExists($table) || !$DB->fieldExists($table, 'entities_id')) {
                continue;
            }
            // Recopie depuis l'objet de rattachement, par l'API de GLPI : elle quote les noms et bâtit la jointure.
            $src_entity    = new \Glpi\DBAL\QueryExpression($DB::quoteName("{$source}.entities_id"));
            $src_recursive = new \Glpi\DBAL\QueryExpression($DB::quoteName("{$source}.is_recursive"));
            $where = ['OR' => [
                "{$table}.entities_id"  => ['<>', $src_entity],
                "{$table}.is_recursive" => ['<>', $src_recursive],
            ]];
            if ($parent_table !== null) {
                $where = ['AND' => [$where, ["{$source}.id" => (int) $parent_id]]];
            }
            $DB->update(
                $table,
                ["{$table}.entities_id" => $src_entity, "{$table}.is_recursive" => $src_recursive],
                $where,
                ['INNER JOIN' => [$source => ['ON' => [$table => $column, $source => 'id']]]]
            );
            $count = (int) $DB->affectedRows();
            if ($count > 0) {
                $fixed[$table] = $count;
            }
        }
        self::$cache = [];
        return $fixed;
    }

    /**
     * Lignes orphelines : objet de rattachement purgé et entité restée à la racine, non récursive (entité
     * indéterminable). Invisibles des comptes clients ; à trancher par un administrateur.
     *
     * @return array<string, array{count: int, ids: int[]}> table => nombre et premiers identifiants
     */
    public static function findOrphans(int $max_ids = 20): array {
        global $DB;

        $orphans = [];
        $sources = array_fill_keys(self::PRINTER_TABLES, ['glpi_printers', 'printers_id']) + self::CONTRACT_TABLES + self::FROZEN_TABLES;
        foreach ($sources as $table => [$source, $column]) {
            if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'entities_id')) {
                continue;
            }
            $criteria = [
                'FROM'      => $table . ' AS t',
                'LEFT JOIN' => [$source . ' AS s' => ['ON' => ['t' => $column, 's' => 'id']]],
                'WHERE'     => ['s.id' => null, 't.entities_id' => 0, 't.is_recursive' => 0],
            ];
            $count = (int) ($DB->request($criteria + ['COUNT' => 'cnt'])->current()['cnt'] ?? 0);
            if ($count === 0) {
                continue;
            }
            $ids = [];
            foreach ($DB->request($criteria + ['SELECT' => ['t.id'], 'ORDER' => ['t.id'], 'LIMIT' => $max_ids]) as $row) {
                $ids[] = (int) $row['id'];
            }
            $orphans[$table] = ['count' => $count, 'ids' => $ids];
        }
        return $orphans;
    }

    /** Résumé lisible de findOrphans() : « table : n (#1, #2…) ». */
    public static function describeOrphans(array $orphans): string {
        return implode(', ', array_map(
            static fn($table, $o) => sprintf('%s : %d (#%s%s)', $table, $o['count'], implode(', #', $o['ids']), $o['count'] > count($o['ids']) ? '…' : ''),
            array_keys($orphans),
            $orphans
        ));
    }

    /** Hook item_update d'une imprimante : ses données techniques suivent sa nouvelle entité (fiche ou transfert). */
    public static function onPrinterUpdate(CommonDBTM $item): void {
        if (array_intersect(['entities_id', 'is_recursive'], (array) ($item->updates ?? []))) {
            $fixed = self::reconcile('glpi_printers', (int) $item->getID());
            if (!empty($fixed)) {
                PluginPrintgestionAlertview::markStale();
            }
        }
    }

    /** Hook item_update d'un contrat : ses tarifs suivent sa nouvelle entité. */
    public static function onContractUpdate(CommonDBTM $item): void {
        if (array_intersect(['entities_id', 'is_recursive'], (array) ($item->updates ?? []))) {
            self::reconcile('glpi_contracts', (int) $item->getID());
        }
    }

    public static function cronInfo($name) {
        return ['description' => __('Print Gestion : contrôle de l\'entité des données techniques (relevés, historique des cartouches, seuils…)', 'printgestion')];
    }

    /**
     * Tâche quotidienne : recale les données techniques. Chaque écart corrigé est journalisé en erreur, car il
     * révèle un chemin d'écriture qui n'a pas posé l'entité, ou un changement d'entité non intercepté. Les
     * données commerciales ne sont pas lues. Les lignes orphelines sont signalées, jamais modifiées.
     */
    public static function cronPrintgestionEntityScope($task = null) {
        $fixed = self::reconcile();
        if (!empty($fixed)) {
            $detail = implode(', ', array_map(static fn($t, $n) => "{$t} : {$n}", array_keys($fixed), $fixed));
            PluginPrintgestionLogger::error('entityscope', 'Entité de lignes corrigée (écart avec l\'objet de rattachement) — ' . $detail);
            PluginPrintgestionAlertview::markStale();
        }
        $orphans = self::findOrphans();
        if (!empty($orphans)) {
            PluginPrintgestionLogger::warning('entityscope', 'Lignes orphelines à trancher (objet de rattachement purgé, entité indéterminée) — ' . self::describeOrphans($orphans));
        }
        if ($task instanceof CronTask) {
            $task->addVolume(array_sum($fixed));
            $task->log((empty($fixed) ? 'Aucun écart d\'entité.' : 'Écarts corrigés : ' . implode(', ', array_map(static fn($t, $n) => "{$t} : {$n}", array_keys($fixed), $fixed)))
                . (empty($orphans) ? '' : ' Lignes orphelines : ' . array_sum(array_column($orphans, 'count')) . ' (voir la configuration du plugin).'));
        }
        return empty($fixed) ? 0 : 1;
    }

    static function install(Migration $migration) {
        CronTask::Register(self::class, 'PrintgestionEntityScope', DAY_TIMESTAMP, ['state' => CronTask::STATE_WAITING]);
        return true;
    }

    static function uninstall(Migration $migration) {
        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, 'PrintgestionEntityScope')) {
            $task->delete(['id' => $task->getID()]);
        }
        return true;
    }
}
