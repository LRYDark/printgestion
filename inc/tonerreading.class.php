<?php
/**
 * PluginPrintgestionTonerreading — archivage horodaté des niveaux toner.
 *
 * Lit glpi_printers_cartridgeinfos (SNMP inventaire GLPI 11) et écrit un
 * snapshot dans glpi_plugin_printgestion_toner_readings à chaque passage cron.
 *
 * Gère les 3 types de valeurs SNMP : % numérique, "OK", "WARNING".
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionTonerreading extends CommonDBTM {

    static $rightname = 'plugin_printgestion_dashboard';

    static function getTypeName($nb = 0) {
        return _n('Relevé toner', 'Relevés toner', $nb, 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_toner_readings';
        }
        return parent::getTable($classname);
    }

    /**
     * Parse une valeur SNMP brute remontée par l'agent GLPI.
     * Retourne ['type' => 'percent|ok|warning|unknown', 'value' => int|null, 'usable' => bool].
     */
    public static function parseTonerValue(string $raw): array {
        $raw = trim($raw);

        if ($raw === '') {
            return ['type' => 'unknown', 'value' => null, 'usable' => false];
        }

        // Numérique (ex: "27", "27%", "  85 %")
        if (preg_match('/^(\d{1,3})\s*%?$/', $raw, $m)) {
            $pct = (int)$m[1];
            if ($pct >= 0 && $pct <= 100) {
                return ['type' => 'percent', 'value' => $pct, 'usable' => true];
            }
        }

        $upper = strtoupper($raw);
        if ($upper === 'OK') {
            return ['type' => 'ok', 'value' => null, 'usable' => false];
        }
        if ($upper === 'WARNING' || $upper === 'WARN') {
            return ['type' => 'warning', 'value' => null, 'usable' => false];
        }

        return ['type' => 'unknown', 'value' => null, 'usable' => false];
    }

    /**
     * Enregistre un snapshot des niveaux toner actuels pour TOUTES les imprimantes.
     *
     * Optimisé pour 10k+ imprimantes :
     * - Dedup : ne stocke que si le niveau diffère du dernier relevé connu
     * - Bulk insert : 1 seule requête multi-lignes au lieu de N
     * - Unique key daily en BDD → les réinsertions même jour sont automatiquement ignorées
     */
    public static function snapshotAllPrinters(): int {
        global $DB;

        $table = self::getTable();
        // Normalisé à minuit du jour courant — garantit que 2 runs le même jour
        // produisent la même clé (printer, property, reading_date) → le unique key
        // daily déclenche ON DUPLICATE KEY UPDATE et overwrite au lieu de doublonner.
        $now = date('Y-m-d 00:00:00');

        // 1. Charge tous les derniers relevés connus en une seule requête
        $latest = [];
        foreach ($DB->request([
            'SELECT' => [
                'printers_id',
                'property_name',
                new \QueryExpression('MAX(reading_date) AS max_date'),
            ],
            'FROM'   => $table,
            'GROUPBY'=> ['printers_id', 'property_name'],
        ]) as $r) {
            $key = $r['printers_id'] . '|' . $r['property_name'];
            $latest[$key] = $r['max_date'];
        }
        $latest_levels = [];
        if (!empty($latest)) {
            foreach ($DB->request([
                'SELECT' => ['printers_id', 'property_name', 'level_percent', 'reading_date'],
                'FROM'   => $table,
                'WHERE'  => ['reading_date' => ['IN', array_unique(array_values($latest))]],
            ]) as $r) {
                $key = $r['printers_id'] . '|' . $r['property_name'];
                if (isset($latest[$key]) && $latest[$key] === $r['reading_date']) {
                    $latest_levels[$key] = (int)$r['level_percent'];
                }
            }
        }

        // 2. Précharge les compteurs pages COURANTS (dernier printerlog) par imprimante.
        //    Priorité : on prend le relevé avec la date la plus récente, et on calcule
        //    total/bw/color selon la logique universelle (même que getCountersForPeriod).
        $printer_counters = [];
        // Sous-requête : dernier log par imprimante (max reading_date)
        $latest_log_dates = [];
        foreach ($DB->request([
            'SELECT' => [
                'items_id',
                new \QueryExpression('MAX(`date`) AS max_date'),
            ],
            'FROM'   => 'glpi_printerlogs',
            'WHERE'  => ['itemtype' => 'Printer'],
            'GROUPBY'=> ['items_id'],
        ]) as $r) {
            $latest_log_dates[(int)$r['items_id']] = $r['max_date'];
        }
        // Résout les compteurs complets pour chaque (printer, max_date)
        if (!empty($latest_log_dates)) {
            foreach ($DB->request([
                'SELECT' => [
                    'items_id', 'date',
                    'total_pages', 'bw_pages', 'color_pages',
                    'bw_prints',  'color_prints',
                    'bw_copies',  'color_copies',
                ],
                'FROM'   => 'glpi_printerlogs',
                'WHERE'  => [
                    'itemtype' => 'Printer',
                    'date'     => ['IN', array_unique(array_values($latest_log_dates))],
                ],
            ]) as $row) {
                $pid = (int)$row['items_id'];
                if (!isset($latest_log_dates[$pid]) || $latest_log_dates[$pid] !== $row['date']) {
                    continue;
                }
                // Logique universelle (identique à printercoststab::getCountersForPeriod)
                $total_p   = (int)$row['total_pages'];
                $bw_p      = (int)$row['bw_pages'];
                $color_p   = (int)$row['color_pages'];
                $bw_pr     = (int)$row['bw_prints'];
                $color_pr  = (int)$row['color_prints'];
                $bw_co     = (int)$row['bw_copies'];
                $color_co  = (int)$row['color_copies'];

                $sum_gran = $bw_pr + $color_pr + $bw_co + $color_co;
                $sum_agg  = $bw_p + $color_p;
                $total    = max($total_p, $sum_agg, $sum_gran);
                $color    = max($color_p, $color_pr + $color_co);
                $bw       = max(0, $total - $color);

                $printer_counters[$pid] = [
                    'total' => $total,
                    'bw'    => $bw,
                    'color' => $color,
                ];
            }
        }

        // 3. Scanne l'inventaire SNMP et prépare les inserts
        $to_insert = [];
        foreach ($DB->request([
            'SELECT' => ['printers_id', 'property', 'value'],
            'FROM'   => 'glpi_printers_cartridgeinfos',
        ]) as $row) {
            $parsed = self::parseTonerValue((string)$row['value']);
            if (!$parsed['usable']) {
                continue;
            }
            $pid   = (int)$row['printers_id'];
            $prop  = (string)$row['property'];
            $level = (int)$parsed['value'];
            $key   = $pid . '|' . $prop;

            // Dedup : skip si identique au dernier relevé stocké
            if (isset($latest_levels[$key]) && $latest_levels[$key] === $level) {
                continue;
            }

            $pc = $printer_counters[$pid] ?? ['total' => 0, 'bw' => 0, 'color' => 0];
            $to_insert[] = [
                'printers_id'   => $pid,
                'property_name' => $prop,
                'level_percent' => $level,
                'total_pages'   => $pc['total'],
                'bw_pages'      => $pc['bw'],
                'color_pages'   => $pc['color'],
                'reading_date'  => $now,
            ];
        }

        if (empty($to_insert)) {
            return 0;
        }

        // 4. Bulk insert — un seul INSERT ... VALUES (...), (...), ... ON DUPLICATE KEY UPDATE
        //    L'ON DUPLICATE protège contre le cas où 2 runs le même jour essaient d'insérer
        //    (unique key daily en BDD). On splitte en batchs de 500 pour éviter les limites
        //    de taille du paquet MySQL (max_allowed_packet).
        $inserted = 0;
        foreach (array_chunk($to_insert, 500) as $chunk) {
            $values_sql = [];
            foreach ($chunk as $ins) {
                $values_sql[] = sprintf(
                    "(%d, %s, %d, %d, %d, %d, %s)",
                    $ins['printers_id'],
                    $DB->quote($ins['property_name']),
                    $ins['level_percent'],
                    $ins['total_pages'],
                    $ins['bw_pages'],
                    $ins['color_pages'],
                    $DB->quote($ins['reading_date'])
                );
            }
            $sql = "INSERT INTO `{$table}` "
                 . "(`printers_id`, `property_name`, `level_percent`, `total_pages`, `bw_pages`, `color_pages`, `reading_date`) "
                 . "VALUES " . implode(', ', $values_sql)
                 . " ON DUPLICATE KEY UPDATE "
                 . "`level_percent` = VALUES(`level_percent`), "
                 . "`total_pages`   = VALUES(`total_pages`), "
                 . "`bw_pages`      = VALUES(`bw_pages`), "
                 . "`color_pages`   = VALUES(`color_pages`), "
                 . "`reading_date`  = VALUES(`reading_date`)";
            try {
                $DB->doQuery($sql);
                $inserted += count($chunk);
            } catch (Throwable $e) {
                // Un lot en échec ne bloque pas les suivants, mais les relevés perdus sont tracés.
                PluginPrintgestionLogger::error(
                    'Tonerreading::snapshotAllPrinters',
                    sprintf('Insertion d\'un lot de %d relevé(s) toner en échec : relevés non enregistrés.', count($chunk)),
                    $e
                );
            }
        }
        return $inserted;
    }

    /**
     * Retourne le niveau le plus récent pour une imprimante + propriété.
     * Retourne null si aucun relevé.
     */
    public static function getLatestLevel(int $printers_id, string $property): ?array {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['level_percent', 'reading_date'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'printers_id'   => $printers_id,
                'property_name' => $property,
            ],
            'ORDER'  => ['reading_date DESC'],
            'LIMIT'  => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    /**
     * Retourne le niveau le plus proche de N jours en arrière (fallback : le plus ancien).
     */
    public static function getLevelNDaysAgo(int $printers_id, string $property, int $days): ?array {
        global $DB;

        $target = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $row = $DB->request([
            'SELECT' => ['level_percent', 'reading_date'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'printers_id'   => $printers_id,
                'property_name' => $property,
                'reading_date'  => ['<=', $target],
            ],
            'ORDER'  => ['reading_date DESC'],
            'LIMIT'  => 1,
        ])->current();

        if (!is_array($row)) {
            $row = $DB->request([
                'SELECT' => ['level_percent', 'reading_date'],
                'FROM'   => self::getTable(),
                'WHERE'  => [
                    'printers_id'   => $printers_id,
                    'property_name' => $property,
                ],
                'ORDER'  => ['reading_date ASC'],
                'LIMIT'  => 1,
            ])->current();
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Purge les relevés de plus de $days jours (par défaut 160).
     * 160 jours couvre largement la fenêtre de calcul de 30 jours + marge
     * pour l'historique d'audit et les rapports mensuels.
     */
    public static function purgeOld(int $days = 160): int {
        global $DB;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $result = $DB->delete(self::getTable(), [
            'reading_date' => ['<', $cutoff],
        ]);
        return (int)$result;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
