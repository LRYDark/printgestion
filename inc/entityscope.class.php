<?php
/**
 * PluginPrintgestionEntityscope — entité des données client du plugin.
 *
 * Les tables métier portent `entities_id` et `is_recursive` (1.6.5) : GLPI restreint alors nativement
 * ce qui s'appuie sur ses mécanismes (canView / canUpdate d'un objet, moteur de recherche, actions de
 * masse). Les contrôles applicatifs sur l'imprimante (PluginPrintgestionSecurity) restent en seconde
 * barrière.
 *
 * Règle : une ligne rattachée à une imprimante prend l'entité et la récursivité de l'imprimante, une
 * liaison BL celles de son expédition, un tarif celles de son contrat ; une ligne de demande hérite de
 * sa demande (CommonDBChild, natif). L'entité suit l'objet : quand une imprimante ou un contrat change
 * d'entité (fiche ou transfert), ses lignes suivent aussitôt. La tâche quotidienne
 * PrintgestionEntityScope recalcule tout et journalise en erreur chaque écart corrigé : un écart veut
 * dire qu'un chemin d'écriture a oublié l'entité.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionEntityscope {

    /** Tables rattachées à une imprimante (colonne printers_id). */
    const PRINTER_TABLES = [
        'glpi_plugin_printgestion_expeditions',
        'glpi_plugin_printgestion_alerts',
        'glpi_plugin_printgestion_alert_snoozes',
        'glpi_plugin_printgestion_cartridge_history',
        'glpi_plugin_printgestion_toner_readings',
        'glpi_plugin_printgestion_historical_yields',
        'glpi_plugin_printgestion_printer_thresholds',
    ];

    /** Tables rattachées à un autre objet : table => [table parente, colonne de liaison]. */
    const PARENT_TABLES = [
        'glpi_plugin_printgestion_expedition_bls' => ['glpi_plugin_printgestion_expeditions', 'expeditions_id'],
        'glpi_plugin_printgestion_demandelines'   => ['glpi_plugin_printgestion_demandes', 'plugin_printgestion_demandes_id'],
        'glpi_plugin_printgestion_contractrates'  => ['glpi_contracts', 'contracts_id'],
    ];

    /** @var array<string, array{entities_id: int, is_recursive: int}> */
    private static array $cache = [];

    /** Toutes les tables qui portent l'entité d'une donnée client. */
    public static function getTables(): array {
        return array_merge(self::PRINTER_TABLES, array_keys(self::PARENT_TABLES));
    }

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
     * Recale l'entité des lignes sur celle de leur objet de rattachement.
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
        // Les expéditions avant leurs liaisons BL : une liaison prend l'entité à jour de son expédition.
        $jobs += self::PARENT_TABLES;
        foreach ($jobs as $table => [$source, $column]) {
            if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'entities_id')) {
                continue;
            }
            $where = '(t.`entities_id` <> s.`entities_id` OR t.`is_recursive` <> s.`is_recursive`)';
            if ($parent_table !== null) {
                if ($source === $parent_table) {
                    $where .= ' AND s.`id` = ' . (int) $parent_id;
                } elseif ($parent_table === 'glpi_printers' && $source === 'glpi_plugin_printgestion_expeditions') {
                    $where .= ' AND s.`printers_id` = ' . (int) $parent_id;
                } else {
                    continue;
                }
            }
            $DB->doQuery("UPDATE `{$table}` AS t INNER JOIN `{$source}` AS s ON s.`id` = t.`{$column}`"
                . " SET t.`entities_id` = s.`entities_id`, t.`is_recursive` = s.`is_recursive` WHERE {$where}");
            $count = (int) $DB->affectedRows();
            if ($count > 0) {
                $fixed[$table] = $count;
            }
        }
        self::$cache = [];
        return $fixed;
    }

    /** Hook item_update d'une imprimante : ses lignes suivent sa nouvelle entité (fiche ou transfert). */
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
        return ['description' => __('Print Gestion : contrôle de l\'entité des données client (expéditions, alertes, relevés…)', 'printgestion')];
    }

    /**
     * Tâche quotidienne : recale toutes les lignes. Chaque écart corrigé est journalisé en erreur, car il
     * révèle un chemin d'écriture qui n'a pas posé l'entité, ou un changement d'entité non intercepté.
     */
    public static function cronPrintgestionEntityScope($task = null) {
        $fixed = self::reconcile();
        if (!empty($fixed)) {
            $detail = implode(', ', array_map(static fn($t, $n) => "{$t} : {$n}", array_keys($fixed), $fixed));
            PluginPrintgestionLogger::error('entityscope', 'Entité de lignes corrigée (écart avec l\'objet de rattachement) — ' . $detail);
            PluginPrintgestionAlertview::markStale();
        }
        if ($task instanceof CronTask) {
            $task->addVolume(array_sum($fixed));
            $task->log(empty($fixed) ? 'Aucun écart d\'entité.' : 'Écarts corrigés : ' . implode(', ', array_map(static fn($t, $n) => "{$t} : {$n}", array_keys($fixed), $fixed)));
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
