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

    /**
     * Niveau figé : un relevé au niveau inchangé est conservé, marqué suspect, quand
     * l'imprimante a imprimé au moins l'équivalent de ce nombre de points de pourcentage
     * (rendement de la cartouche / 100) depuis le relevé précédent.
     */
    const FROZEN_PERCENT_STEPS = 3;

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
     * Lecture d'une valeur SNMP (PluginPrintgestionSnmpadapter). Avec la propriété et
     * l'imprimante : règles par constructeur et pourcentage reconstitué depuis les états
     * bruts ; sinon lecture de la valeur seule (pourcentage, OK / WARNING, sentinelles
     * -1 / -2 / -3, jamais lues comme un pourcentage).
     * Retourne ['type', 'value' => int|null, 'usable' => bool].
     */
    public static function parseTonerValue(string $raw, string $property = '', int $printers_id = 0): array {
        return PluginPrintgestionSnmpadapter::parse($raw, $property, $printers_id);
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
        $latest_pages  = [];
        if (!empty($latest)) {
            foreach ($DB->request([
                'SELECT' => ['printers_id', 'property_name', 'level_percent', 'reading_date', 'total_pages', 'bw_pages', 'color_pages'],
                'FROM'   => $table,
                'WHERE'  => ['reading_date' => ['IN', array_unique(array_values($latest))]],
            ]) as $r) {
                $key = $r['printers_id'] . '|' . $r['property_name'];
                if (isset($latest[$key]) && $latest[$key] === $r['reading_date']) {
                    $latest_levels[$key] = (int)$r['level_percent'];
                    $latest_pages[$key]  = ['total' => (int)$r['total_pages'], 'bw' => (int)$r['bw_pages'], 'color' => (int)$r['color_pages']];
                }
            }
        }

        // Rendement par imprimante (pages par cartouche) : seuil de détection d'un niveau figé.
        $default_yield  = max(100, (int)(PluginPrintgestionConfig::getInstance()->fields['default_pages_per_cartridge'] ?? 5000));
        $printer_yields = [];
        foreach ($DB->request([
            'SELECT' => ['printers_id', 'pages_per_cartridge'],
            'FROM'   => 'glpi_plugin_printgestion_printer_thresholds',
            'WHERE'  => ['pages_per_cartridge' => ['>', 0]],
        ]) as $r) {
            $printer_yields[(int)$r['printers_id']] = (int)$r['pages_per_cartridge'];
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

        // 3. Niveaux lisibles de l'inventaire SNMP (sentinelles écartées, règles appliquées,
        //    pourcentages reconstitués) et préparation des inserts
        $to_insert = [];
        $slots     = [];
        foreach (PluginPrintgestionSnmpadapter::getLevels() as $pid => $properties) {
            foreach ($properties as $prop => $parsed) {
                $slots[] = [(int)$pid, (string)$prop, $parsed];
            }
        }
        // Entité et récursivité des imprimantes, portées par chaque relevé (cloisonnement natif, 1.6.5).
        $scopes = [];
        if (!empty($slots)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'entities_id', 'is_recursive'],
                'FROM'   => 'glpi_printers',
                'WHERE'  => ['id' => array_values(array_unique(array_column($slots, 0)))],
            ]) as $printer) {
                $scopes[(int) $printer['id']] = [(int) $printer['entities_id'], (int) $printer['is_recursive']];
            }
        }
        foreach ($slots as [$pid, $prop, $parsed]) {
            if (!$parsed['usable']) {
                continue;
            }
            $level = (int)$parsed['value'];
            $key   = $pid . '|' . $prop;

            $pc         = $printer_counters[$pid] ?? ['total' => 0, 'bw' => 0, 'color' => 0];
            $is_suspect = 0;

            // Niveau identique au dernier relevé stocké : relevé inutile… sauf si l'imprimante a
            // imprimé nettement plus que ce que représente un point de pourcentage. Niveau figé
            // suspect : conservé et marqué, plutôt qu'écarté (ses compteurs restent exploitables).
            // Dépendance notée (Déploiement Agent, correction 3), à traiter avec le moteur d'alertes, pas avant
            // les données du site pilote : l'alerte « imprimante qui ne remonte plus de niveaux » ne pourra reposer
            // sur cette table que lorsqu'un niveau inchangé sera gardé et marqué au lieu d'être écarté ici, et que
            // reading_date sera la date d'une vraie lecture SNMP (aujourd'hui : jour du passage de la tâche, copie
            // des valeurs courantes de GLPI, relues ou non). Voir DOC_TECHNIQUE, phase 5 du Déploiement Agent.
            if (isset($latest_levels[$key]) && $latest_levels[$key] === $level) {
                $counter   = in_array(PluginPrintgestionSnmpmapping::detectColor($prop), ['cyan', 'magenta', 'yellow'], true) ? 'color' : 'total';
                $printed   = $pc[$counter] - ($latest_pages[$key][$counter] ?? $pc[$counter]);
                $threshold = self::FROZEN_PERCENT_STEPS * max(1, (int)round(($printer_yields[$pid] ?? $default_yield) / 100));
                if ($printed < $threshold) {
                    continue;
                }
                $is_suspect = 1;
            }

            $to_insert[] = [
                'printers_id'   => $pid,
                'property_name' => $prop,
                'level_percent' => $level,
                'total_pages'   => $pc['total'],
                'bw_pages'      => $pc['bw'],
                'color_pages'   => $pc['color'],
                'is_suspect'    => $is_suspect,
                'reading_date'  => $now,
                'entities_id'   => $scopes[$pid][0] ?? 0,
                'is_recursive'  => $scopes[$pid][1] ?? 0,
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
                    "(%d, %s, %d, %d, %d, %d, %d, %s, %d, %d)",
                    $ins['printers_id'],
                    $DB->quote($ins['property_name']),
                    $ins['level_percent'],
                    $ins['total_pages'],
                    $ins['bw_pages'],
                    $ins['color_pages'],
                    $ins['is_suspect'],
                    $DB->quote($ins['reading_date']),
                    $ins['entities_id'],
                    $ins['is_recursive']
                );
            }
            $sql = "INSERT INTO `{$table}` "
                 . "(`printers_id`, `property_name`, `level_percent`, `total_pages`, `bw_pages`, `color_pages`, `is_suspect`, `reading_date`, `entities_id`, `is_recursive`) "
                 . "VALUES " . implode(', ', $values_sql)
                 . " ON DUPLICATE KEY UPDATE "
                 . "`level_percent` = VALUES(`level_percent`), "
                 . "`total_pages`   = VALUES(`total_pages`), "
                 . "`bw_pages`      = VALUES(`bw_pages`), "
                 . "`color_pages`   = VALUES(`color_pages`), "
                 . "`is_suspect`    = VALUES(`is_suspect`), "
                 . "`entities_id`   = VALUES(`entities_id`), "
                 . "`is_recursive`  = VALUES(`is_recursive`), "
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
