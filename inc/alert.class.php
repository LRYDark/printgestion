<?php
/**
 * PluginPrintgestionAlert — calcul intelligent des alertes toner (Point 4).
 *
 * Vitesse de consommation basée sur fenêtre de 30 jours :
 *   vitesse = (level_t-30 - level_t) / 30
 *   jours_restants = level_t / vitesse
 *
 * Retourne le statut et déclenche les alertes quand seuils franchis.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAlert extends CommonDBTM {

    static $rightname = 'plugin_printgestion_dashboard';

    const STATUS_OK       = 'ok';
    const STATUS_WATCH    = 'watch';
    const STATUS_CRITICAL = 'critical';

    static function getTypeName($nb = 0) {
        return _n('Alerte Print Gestion', 'Alertes Print Gestion', $nb, 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_alerts';
        }
        return parent::getTable($classname);
    }

    /** Fenêtre de lissage de la cadence de pages par jour (vacances scolaires comprises). */
    const PAGES_RATE_DAYS = 28;

    /**
     * Calcul d'estimation jours restants — approche FM Audit-like précise.
     *
     * Améliorations vs version simple :
     *   - Détecte l'install point (hausse ≥ 20% niveau) → baseline = cycle courant seulement
     *   - Per-toner counter : black → bw_pages delta, CMY → color_pages delta
     *   - Fallback yield historique (cycle précédent de ce même toner) avant yield global
     *
     * @param int    $level_live_snmp   Valeur SNMP live parsée
     * @param array  $readings          Tous les relevés stockés de ce toner (ASC par date)
     * @param string $toner_color       black / cyan / magenta / yellow / other
     * @param int    $default_yield     Yield par défaut (pages/cartouche entière)
     * @param float|null $historical_yield Yield du cycle précédent (pages/1%) ou null
     */
    public static function computeRemainingPagesBased(
        int $level_live_snmp,
        array $readings,
        string $toner_color,
        int $default_yield,
        ?float $historical_yield = null
    ): array {
        if (empty($readings)) {
            return [
                'level'          => $level_live_snmp,
                'speed_per_day'  => 0.0,
                'days_remaining' => null,
                'is_estimate'    => false,
            ];
        }

        // ── 1. Détection du cycle courant (install point) ────────────────
        // Un install point = une hausse de niveau >= 20% entre 2 relevés consécutifs.
        // On part du dernier relevé et on remonte jusqu'au dernier install point.
        $cycle_start_idx = 0;
        for ($i = count($readings) - 1; $i > 0; $i--) {
            $cur  = (int)$readings[$i]['level_percent'];
            $prev = (int)$readings[$i - 1]['level_percent'];
            if ($cur - $prev >= 20) {
                $cycle_start_idx = $i;
                break;
            }
        }
        $cycle = array_slice($readings, $cycle_start_idx);

        // Relevés « niveau figé » (is_suspect) : compteurs fiables, niveau non. Ils servent à la
        // cadence de pages et aux pages imprimées depuis le dernier niveau fiable, pas au rendement.
        $reliable = array_values(array_filter($cycle, static fn(array $r) => empty($r['is_suspect'])));
        if (empty($reliable)) {
            $reliable = $cycle;
        }
        $latest_any    = end($readings);
        $level_suspect = !empty($latest_any['is_suspect']);

        $latest = end($reliable);
        $level_now = (int)$latest['level_percent'];

        // ── 2. Sélection du bon compteur selon la couleur du toner ───────
        // black/other → bw_pages ; cyan/magenta/yellow → color_pages
        $is_color_toner = in_array($toner_color, ['cyan', 'magenta', 'yellow'], true);
        $counter_key    = $is_color_toner ? 'color_pages' : 'bw_pages';

        $pages_now_cnt   = (int)($latest[$counter_key] ?? 0);
        $pages_now_total = (int)($latest['total_pages'] ?? 0);
        // Fallback : si le counter spécifique est 0 mais total > 0, on retombe sur total
        // (certaines imprimantes ne remontent pas split bw/color correctement)
        if ($pages_now_cnt === 0 && $pages_now_total > 0) {
            $pages_now_cnt = $pages_now_total;
        }

        // ── 3. Calcul du yield mesuré sur le cycle courant ───────────────
        // Baseline = premier relevé du cycle (juste après install), niveau le plus haut
        $baseline = reset($cycle);
        $yield_per_percent = 0.0;
        $yield_source      = 'default';

        if ($baseline) {
            $level_base    = (int)$baseline['level_percent'];
            $pages_base    = (int)($baseline[$counter_key] ?? 0);
            $pages_base_tt = (int)($baseline['total_pages'] ?? 0);
            if ($pages_base === 0 && $pages_base_tt > 0) {
                $pages_base = $pages_base_tt;
            }
            $delta_pct = $level_base - $level_now;
            $delta_pgs = $pages_now_cnt - $pages_base;
            if ($delta_pct >= 3 && $delta_pgs > 0) {
                $yield_per_percent = $delta_pgs / $delta_pct;
                $yield_source      = 'measured';
            }
        }

        // Fallback 1 : yield historique (cycle précédent de ce même toner)
        if ($yield_per_percent <= 0 && $historical_yield !== null && $historical_yield > 0) {
            $yield_per_percent = $historical_yield;
            $yield_source      = 'historical';
        }

        // Fallback 2 : yield configuré (défaut global, divisé par 3 pour CMY car chaque
        // couleur contribue à ~1/3 des pages couleur imprimées — approximation raisonnable)
        if ($yield_per_percent <= 0) {
            $base_yield = max(1.0, $default_yield / 100.0);
            $yield_per_percent = $is_color_toner ? ($base_yield * 3) : $base_yield;
            $yield_source      = 'default';
        }

        // ── 4. Pages restantes ───────────────────────────────────────────
        $pages_remaining = $level_now * $yield_per_percent;

        // ── 5. Pages par jour lissées sur PAGES_RATE_DAYS (28) jours glissants : une semaine de
        //       vacances (écoles, mairies) ne fausse plus la cadence. Tous les relevés comptent,
        //       suspects compris, et la fenêtre dépasse le cycle courant (compteur de l'imprimante). ──
        $counter_of = static function (array $r) use ($counter_key): int {
            $specific = (int)($r[$counter_key] ?? 0);
            $total    = (int)($r['total_pages'] ?? 0);
            return ($specific === 0 && $total > 0) ? $total : $specific;
        };

        $pages_per_day = 0.0;
        $cutoff_ts     = time() - (self::PAGES_RATE_DAYS * 86400);
        $past_reading  = null;
        foreach ($readings as $r) {
            $ts = strtotime((string)$r['reading_date']);
            if ($ts !== false && $ts <= $cutoff_ts) {
                $past_reading = $r;
            }
        }
        // Moins de 28 jours d'historique : le plus ancien relevé disponible.
        if ($past_reading === null && count($readings) >= 2) {
            $past_reading = reset($readings);
        }

        $pages_last = $counter_of($latest_any);
        if ($past_reading && $past_reading !== $latest_any) {
            $pages_past = $counter_of($past_reading);
            $date_past  = strtotime((string)$past_reading['reading_date']);
            $date_now   = strtotime((string)$latest_any['reading_date']);
            if ($pages_last > $pages_past && $date_now > $date_past) {
                $days_elapsed  = max(1, ($date_now - $date_past) / 86400);
                $pages_per_day = ($pages_last - $pages_past) / $days_elapsed;
            }
        }

        // Pages imprimées depuis le dernier niveau fiable (niveau figé) : déduites du restant.
        $pages_remaining = max(0.0, $pages_remaining - max(0, $pages_last - $pages_now_cnt));

        // ── 6. Jours restants ────────────────────────────────────────────
        // Guards : div 0 impossible ici (yield_per_percent >= 1 via max, pages_per_day > 0)
        // mais on vérifie explicitement par sécurité (configs invalides, edge cases).
        if ($pages_per_day <= 0 || $yield_per_percent <= 0) {
            return [
                'level'          => $level_now,
                'speed_per_day'  => 0.0,
                'days_remaining' => null,
                'is_estimate'    => $level_suspect,
                'level_suspect'  => $level_suspect,
            ];
        }

        $days_remaining = (int)floor($pages_remaining / $pages_per_day);

        return [
            'level'          => $level_now,
            'speed_per_day'  => $pages_per_day / $yield_per_percent,
            'days_remaining' => $days_remaining,
            'is_estimate'    => $yield_source !== 'measured' || $level_suspect,
            'level_suspect'  => $level_suspect,
            'yield_source'   => $yield_source,
            'pages_per_day'  => $pages_per_day,
            'yield_per_pct'  => $yield_per_percent,
        ];
    }

    /**
     * Classe un calcul en ok / watch / critical.
     */
    public static function classify(array $computed, int $threshold_level, int $threshold_days): string {
        $level = (int)($computed['level'] ?? 0);
        $days  = $computed['days_remaining'];

        // Critique : niveau < seuil brut OU jours < 7
        if ($level <= $threshold_level) {
            return self::STATUS_CRITICAL;
        }
        if ($days !== null && $days <= 7) {
            return self::STATUS_CRITICAL;
        }

        // À surveiller : jours restants <= threshold_days
        if ($days !== null && $days <= $threshold_days) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_OK;
    }

    /**
     * Génère la liste complète des alertes.
     * Retourne un tableau de lignes enrichies.
     *
     * @param int|null $entities_id         Filtre sur une entité précise, en plus du périmètre.
     * @param bool     $restrict_to_session true (défaut) : limité aux entités actives de
     *                                      l'utilisateur connecté. false : toutes les entités,
     *                                      réservé aux traitements internes (tâches
     *                                      automatiques, matérialisation) — jamais à un
     *                                      affichage ni à un export.
     * @param ?array   $printer_ids         Restreindre à ces imprimantes (recalcul partiel).
     */
    public static function listAll(?int $entities_id = null, bool $restrict_to_session = true, ?array $printer_ids = null): array {
        global $DB;

        $config               = PluginPrintgestionConfig::getInstance();
        $threshold_level_def  = (int)($config->fields['threshold_level'] ?? 15);
        $threshold_days_def   = (int)($config->fields['threshold_days']  ?? 30);
        $default_yield_config = (int)($config->fields['default_pages_per_cartridge'] ?? 5000);

        // Récupère les couples snoozés pour filtrage
        $snoozed = self::getSnoozedPairs();

        $rows = [];

        // ── Préchargement batch (optimisation N+1) ────────────────────────

        // 1. TOUS les relevés récents (160j max selon purge) groupés par toner
        //    → permet de détecter les install points + baseline cycle courant + delta 7j.
        //    SELECT dynamique : bw_pages/color_pages sont post-migration, fallback si absents.
        $tr_table   = 'glpi_plugin_printgestion_toner_readings';
        $all_readings_by_toner = [];
        if ($DB->tableExists($tr_table)) {
            $tr_select = ['printers_id', 'property_name', 'level_percent', 'reading_date'];
            if ($DB->fieldExists($tr_table, 'total_pages')) $tr_select[] = 'total_pages';
            if ($DB->fieldExists($tr_table, 'bw_pages'))    $tr_select[] = 'bw_pages';
            if ($DB->fieldExists($tr_table, 'color_pages')) $tr_select[] = 'color_pages';
            if ($DB->fieldExists($tr_table, 'is_suspect'))  $tr_select[] = 'is_suspect';

            $tr_criteria = [
                'SELECT' => $tr_select,
                'FROM'   => $tr_table,
                'ORDER'  => ['printers_id', 'property_name', 'reading_date ASC'],
            ];
            if ($printer_ids !== null) {
                $tr_criteria['WHERE'] = ['printers_id' => array_values(array_map('intval', $printer_ids)) ?: [0]];
            }
            foreach ($DB->request($tr_criteria) as $r) {
                $k = $r['printers_id'] . '|' . $r['property_name'];
                if (!isset($all_readings_by_toner[$k])) {
                    $all_readings_by_toner[$k] = [];
                }
                $all_readings_by_toner[$k][] = $r;
            }
        }

        // 2. Seuils personnalisés par imprimante (table post-migration)
        $printer_thresholds = [];
        if ($DB->tableExists('glpi_plugin_printgestion_printer_thresholds')) {
            foreach ($DB->request([
                'SELECT' => ['printers_id', 'threshold_level', 'threshold_days', 'pages_per_cartridge'],
                'FROM'   => 'glpi_plugin_printgestion_printer_thresholds',
            ]) as $r) {
                $printer_thresholds[(int)$r['printers_id']] = $r;
            }
        }

        // 3. Yields mesurés historiques (table post-migration)
        $historical_yields = [];
        if ($DB->tableExists('glpi_plugin_printgestion_historical_yields')) {
            foreach ($DB->request([
                'SELECT' => ['printers_id', 'property_name', 'yield_per_percent'],
                'FROM'   => 'glpi_plugin_printgestion_historical_yields',
                'ORDER'  => ['date_creation DESC'],
            ]) as $r) {
                $k = $r['printers_id'] . '|' . $r['property_name'];
                if (!isset($historical_yields[$k])) {
                    $historical_yields[$k] = (float)$r['yield_per_percent'];
                }
            }
        }

        // 4. SNMP mappings préchargés (fix N+1 résiduel)
        $snmp_mappings_by_property = [];
        foreach ($DB->request([
            'SELECT' => ['snmp_property', 'toner_color', 'cartridgeitemtypes_id'],
            'FROM'   => 'glpi_plugin_printgestion_snmp_mapping',
        ]) as $r) {
            $snmp_mappings_by_property[strtolower((string)$r['snmp_property'])] = $r;
        }

        // 5. Expéditions en cours (ni posées ni annulées) indexées par (printers_id, property)
        $active_expeditions = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_printgestion_expeditions',
            'WHERE' => [
                'statut' => PluginPrintgestionExpedition::ACTIVE_STATUSES,
            ],
            'ORDER' => ['id DESC'],
        ]) as $e) {
            $k = $e['printers_id'] . '|' . $e['toner_property'];
            if (!isset($active_expeditions[$k])) {
                $active_expeditions[$k] = $e;
            }
        }

        // ── Imprimantes du périmètre, puis niveaux lisibles (PluginPrintgestionSnmpadapter) ──
        $criteria = [
            'SELECT' => [
                'p.id AS printers_id',
                'p.name AS printer_name',
                'p.entities_id',
                'e.completename AS entity_name',
            ],
            'FROM'      => 'glpi_printers AS p',
            'LEFT JOIN' => [
                'glpi_entities AS e' => [
                    'ON' => ['p' => 'entities_id', 'e' => 'id'],
                ],
            ],
            'WHERE' => [
                'p.is_deleted'  => 0,
                'p.is_template' => 0,
            ],
            'ORDER' => ['e.completename', 'p.name'],
        ];
        if ($printer_ids !== null) {
            $criteria['WHERE']['p.id'] = array_values(array_unique(array_map('intval', $printer_ids))) ?: [0];
        }

        if ($entities_id !== null && $entities_id >= 0) {
            $criteria['WHERE']['p.entities_id'] = $entities_id;
        }
        if ($restrict_to_session) {
            // Cloisonnement client : jamais au-delà des entités de l'utilisateur,
            // même si le filtre d'entité est vide ou manipulé.
            $criteria['WHERE'][] = getEntitiesRestrictCriteria('p', '', '', true);
        }

        $printers = iterator_to_array($DB->request($criteria), false);
        $levels   = PluginPrintgestionSnmpadapter::getLevels(array_column($printers, 'printers_id'));
        $slots    = [];
        foreach ($printers as $printer) {
            $printer_levels = $levels[(int)$printer['printers_id']] ?? [];
            ksort($printer_levels);
            foreach ($printer_levels as $property => $parsed) {
                $slots[] = $printer + ['property' => (string)$property, 'parsed' => $parsed];
            }
        }

        foreach ($slots as $r) {
            $parsed = $r['parsed'];
            if (!$parsed['usable']) {
                continue;
            }

            $printers_id = (int)$r['printers_id'];
            $property    = (string)$r['property'];
            $k           = $printers_id . '|' . $property;

            // Snooze : on NE skip PAS la ligne. À la place, on force le status
            // à 'ok' plus bas → la cartouche reste visible dans le dashboard
            // mais n'apparaît plus comme critique / à surveiller (pas d'alerte
            // mail, pas de pulse, pas de badge orange).
            $is_snoozed = isset($snoozed[$k]);

            // Seuils : override par imprimante si défini, sinon config globale
            $pt = $printer_thresholds[$printers_id] ?? null;
            $eff_threshold_level = ($pt && !empty($pt['threshold_level'])) ? (int)$pt['threshold_level'] : $threshold_level_def;
            $eff_threshold_days  = ($pt && !empty($pt['threshold_days']))  ? (int)$pt['threshold_days']  : $threshold_days_def;
            $eff_yield           = ($pt && !empty($pt['pages_per_cartridge'])) ? (int)$pt['pages_per_cartridge'] : $default_yield_config;

            // Mapping SNMP : batch preload (fix N+1)
            $mapping      = $snmp_mappings_by_property[strtolower($property)] ?? null;
            $toner_color  = is_array($mapping) ? (string)($mapping['toner_color'] ?? 'other') : 'other';
            // Détection couleur via property name si pas de mapping explicit
            if ($toner_color === 'other') {
                $toner_color = PluginPrintgestionSnmpmapping::detectColor($property);
            }

            $computed = self::computeRemainingPagesBased(
                $parsed['value'],
                $all_readings_by_toner[$k] ?? [],
                $toner_color,
                $eff_yield,
                $historical_yields[$k] ?? null
            );
            $status = self::classify($computed, $eff_threshold_level, $eff_threshold_days);

            // Snooze actif : la cartouche n'est plus considérée comme en alerte.
            if ($is_snoozed) {
                $status = self::STATUS_OK;
            }

            $expedition = $active_expeditions[$k] ?? null;

            // Label cartouche : utilise le mapping préchargé + fallback property name
            $cartridge_label = $property;
            if (is_array($mapping) && !empty($mapping['cartridgeitemtypes_id'])) {
                // Note : getCartridgeLabelForProperty reste en fallback seulement pour cas complexes
                $cartridge_label = PluginPrintgestionSnmpmapping::getCartridgeLabelForProperty($printers_id, $property);
            }

            $rows[] = [
                'printers_id'    => $printers_id,
                'printer_name'   => (string)$r['printer_name'],
                'entities_id'    => (int)$r['entities_id'],
                'entity_name'    => (string)($r['entity_name'] ?? ''),
                'property'       => $property,
                'level'          => $computed['level'],
                'speed_per_day'  => $computed['speed_per_day'],
                'days_remaining' => $computed['days_remaining'],
                'is_estimate'    => (bool)($computed['is_estimate'] ?? false),
                // Niveau figé alors que l'imprimante imprime : lecture SNMP suspecte.
                'level_suspect'  => (bool)($computed['level_suspect'] ?? false),
                'status'         => $status,
                'cartridge_type' => $cartridge_label,
                'toner_color'    => $toner_color,
                'expedition'     => $expedition,
                'snoozed'        => $is_snoozed,
                'snooze_until'   => $is_snoozed ? $snoozed[$k] : null,
            ];
        }

        // Verrous anti-double-envoi (envoi en cours, garde après pose, ticket récent),
        // évalués en requêtes groupées pour toutes les lignes.
        $locks = PluginPrintgestionGuard::evaluate(array_map(static fn($r) => [
            'printers_id' => $r['printers_id'],
            'property'    => $r['property'],
            'level'       => $r['level'],
        ], $rows));
        foreach ($rows as &$row_ref) {
            $row_ref['lock'] = $locks[$row_ref['printers_id'] . '|' . $row_ref['property']] ?? null;
        }
        unset($row_ref);

        // Tri : critical en premier, puis watch, puis ok ; dans chaque groupe par jours restants
        $priority = [self::STATUS_CRITICAL => 0, self::STATUS_WATCH => 1, self::STATUS_OK => 2];
        usort($rows, function ($a, $b) use ($priority) {
            $pa = $priority[$a['status']] ?? 3;
            $pb = $priority[$b['status']] ?? 3;
            if ($pa !== $pb) return $pa <=> $pb;
            $da = $a['days_remaining'] ?? PHP_INT_MAX;
            $db = $b['days_remaining'] ?? PHP_INT_MAX;
            return $da <=> $db;
        });

        return $rows;
    }

    /**
     * Signale que l'écran des alertes (table matérialisée alertview) ne reflète plus l'état
     * réel : commande, annulation, seuils… Bandeau « Recalculer » sur l'écran ; recalcul
     * complet par la tâche horaire. Une commande revérifie de toute façon les verrous et les
     * références côté serveur.
     */
    public static function invalidateCache(): void {
        PluginPrintgestionAlertview::markStale();
    }

    /**
     * Enregistre une alerte en BDD (pour traçabilité + éviter les doublons de mail).
     */
    public static function logAlert(int $printers_id, string $property, int $level, ?int $days, string $type): int {
        global $DB;

        $DB->insert(self::getTable(), [
            'printers_id'    => $printers_id,
            'toner_property' => $property,
            'level_percent'  => $level,
            'estimated_days' => $days,
            'alert_type'     => $type,
            'date_alert'     => date('Y-m-d H:i:s'),
            'mail_sent'      => 0,
        ]);

        return (int)$DB->insertId();
    }

    /**
     * Parcourt les alertes critical/watch et déclenche les notifications mail.
     * Anti-doublon : n'envoie pas si alerte du même type créée depuis < 24h.
     *
     * DIGEST : toutes les alertes du run partent dans UN seul mail commercial
     * (liste des toners bas) au lieu d'un mail par imprimante/toner.
     */
    public static function sendPendingAlerts(): int {
        global $DB;

        $config = PluginPrintgestionConfig::getInstance();
        $gabarit_commercial = (int)($config->fields['gabarit_commercial'] ?? 0);

        // Tâche automatique : toutes les entités (destinataires internes uniquement).
        $rows    = self::listAll(null, false);
        $pending = [];

        foreach ($rows as $row) {
            if ($row['status'] === self::STATUS_OK) {
                continue;
            }
            // Ignorer tout emplacement verrouillé : envoi en cours quel que soit son
            // statut (commandé, stock vide, expédié, en transit, livré non posé), garde
            // après pose ou ticket récent — sauf contournement (consommation anormale).
            if (!empty($row['lock']) && $row['lock']['blocking']) {
                continue;
            }

            // Anti-doublon 24h
            $recent = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'printers_id'    => $row['printers_id'],
                    'toner_property' => $row['property'],
                    'alert_type'     => 'low_toner',
                    'date_alert'     => ['>=', date('Y-m-d H:i:s', strtotime('-24 hours'))],
                ],
                'LIMIT' => 1,
            ])->current();

            if (is_array($recent)) {
                continue;
            }

            $id = self::logAlert(
                $row['printers_id'],
                $row['property'],
                $row['level'],
                $row['days_remaining'],
                'low_toner'
            );

            $pending[] = ['alert_id' => $id, 'row' => $row];
        }

        if (empty($pending) || $gabarit_commercial <= 0) {
            return 0;
        }

        $recipients = self::resolveRecipientsForRole('commercial');
        if (empty($recipients)) {
            return 0;
        }

        if (count($pending) === 1) {
            // Envoi SIMPLE : balises unitaires détaillées
            $row = $pending[0]['row'];
            $balises = [
                '##printgestion.printer##'  => $row['printer_name'],
                '##printgestion.client##'   => $row['entity_name'],
                '##printgestion.toner##'    => $row['property'],
                '##printgestion.level##'    => (string)$row['level'],
                '##printgestion.days##'     => $row['days_remaining'] !== null ? (string)$row['days_remaining'] : 'N/A',
                '##printgestion.cartridge##'=> $row['cartridge_type'] ?? $row['property'],
            ];
        } else {
            // Envoi MULTI : agrégats + liste détaillée (plafonnée)
            $max  = PluginPrintgestionExpedition::MAIL_LIST_MAX;
            $list = '<ul>';
            foreach (array_slice($pending, 0, $max) as $p) {
                $row = $p['row'];
                $days_txt = $row['days_remaining'] !== null ? ($row['days_remaining'] . ' j') : 'N/A';
                $list .= '<li>'
                    . '<strong>' . htmlspecialchars((string)$row['printer_name'], ENT_QUOTES, 'UTF-8') . '</strong>'
                    . ' — ' . htmlspecialchars((string)$row['entity_name'], ENT_QUOTES, 'UTF-8')
                    . ' — ' . htmlspecialchars((string)$row['property'], ENT_QUOTES, 'UTF-8')
                    . ' — ' . (int)$row['level'] . '% — ' . htmlspecialchars($days_txt, ENT_QUOTES, 'UTF-8')
                    . '</li>';
            }
            if (count($pending) > $max) {
                $list .= '<li>… et ' . (count($pending) - $max) . ' autres</li>';
            }
            $list .= '</ul>';

            $clients  = array_values(array_unique(array_map(fn($p) => (string)$p['row']['entity_name'], $pending)));
            $printers = array_values(array_unique(array_map(fn($p) => (string)$p['row']['printer_name'], $pending)));
            $level_min = min(array_map(fn($p) => (int)$p['row']['level'], $pending));
            $days_vals = array_filter(
                array_map(fn($p) => $p['row']['days_remaining'], $pending),
                fn($d) => $d !== null
            );

            $balises = [
                '##printgestion.printer##'         => count($printers) > 1
                    ? sprintf(__('%d imprimantes', 'printgestion'), count($printers))
                    : (string)($printers[0] ?? ''),
                '##printgestion.client##'          => count($clients) > 1
                    ? sprintf(__('%d clients', 'printgestion'), count($clients))
                    : (string)($clients[0] ?? ''),
                '##printgestion.toner##'           => sprintf(__('%d toners bas', 'printgestion'), count($pending)),
                '##printgestion.level##'           => (string)$level_min,
                '##printgestion.days##'            => !empty($days_vals) ? (string)min($days_vals) : 'N/A',
                '##printgestion.cartridges_list##' => $list,
                '##printgestion.count##'           => (string)count($pending),
            ];
        }

        if (!PluginPrintgestionConfig::sendMail($recipients, $gabarit_commercial, $balises)) {
            return 0;
        }

        foreach ($pending as $p) {
            $DB->update(self::getTable(), ['mail_sent' => 1], ['id' => $p['alert_id']]);
        }

        return count($pending);
    }

    /**
     * Résout les destinataires mail pour un rôle donné (planif / achat / commercial).
     *
     * Selon la config :
     * - mode 'group'  → récupère les emails par défaut des membres du groupe GLPI
     * - mode 'emails' → récupère les emails par défaut des users GLPI sélectionnés
     *                   (NB: la colonne `emails_X` stocke une liste CSV d'IDs users)
     */
    public static function resolveRecipientsForRole(string $role): array {
        $config = PluginPrintgestionConfig::getInstance();
        $mode   = (string)($config->fields['mode_' . $role] ?? 'group');

        if ($mode === 'emails') {
            $raw = (string)($config->fields['emails_' . $role] ?? '');
            if ($raw === '') {
                return [];
            }
            $user_ids = array_values(array_filter(
                array_map('intval', preg_split('/[,;\s]+/u', $raw) ?: []),
                fn($id) => $id > 0
            ));
            return self::resolveEmailsForUsers($user_ids);
        }

        // Mode 'group' : emails par défaut des membres du groupe GLPI
        return self::resolveRecipientsForGroup((int)($config->fields['group_' . $role] ?? 0));
    }

    /**
     * Retourne les emails par défaut d'une liste d'IDs users GLPI.
     */
    public static function resolveEmailsForUsers(array $user_ids): array {
        global $DB;

        $user_ids = array_values(array_filter(array_map('intval', $user_ids), fn($id) => $id > 0));
        if (empty($user_ids)) {
            return [];
        }

        $emails = [];
        foreach ($DB->request([
            'SELECT' => ['email'],
            'FROM'   => 'glpi_useremails',
            'WHERE'  => [
                'users_id'   => $user_ids,
                'is_default' => 1,
            ],
        ]) as $row) {
            $e = trim((string)($row['email'] ?? ''));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($e)] = $e;
            }
        }

        return array_values($emails);
    }

    /**
     * Récupère les emails des membres d'un groupe GLPI.
     * Conservé pour compatibilité : utilisé en interne par resolveRecipientsForRole.
     */
    public static function resolveRecipientsForGroup(int $groups_id): array {
        global $DB;

        if ($groups_id <= 0) {
            return [];
        }

        $emails = [];
        $rows = $DB->request([
            'SELECT'    => ['u.id', 'u.name'],
            'FROM'      => 'glpi_groups_users AS gu',
            'INNER JOIN'=> [
                'glpi_users AS u' => [
                    'ON' => ['gu' => 'users_id', 'u' => 'id'],
                ],
            ],
            'WHERE'     => ['gu.groups_id' => $groups_id],
        ]);

        foreach ($rows as $u) {
            $ue = $DB->request([
                'SELECT' => ['email'],
                'FROM'   => 'glpi_useremails',
                'WHERE'  => [
                    'users_id'    => (int)$u['id'],
                    'is_default'  => 1,
                ],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($ue) && !empty($ue['email'])) {
                $emails[] = (string)$ue['email'];
            }
        }

        return $emails;
    }

    /**
     * Liste des alertes prioritaires pour le dashboard.
     * Agrège :
     *   - Alertes wrong_printer non résolues (avec jointures pour avoir les noms)
     *   - Expéditions en retard (shipped/transit depuis > reminder_days)
     *
     * Retourne un tableau de lignes typées : ['kind' => 'wrong_printer'|'late_shipment', ...]
     */
    public static function listPriorityAlerts(?int $entities_id = null): array {
        global $DB;

        $out = [];
        $config = PluginPrintgestionConfig::getInstance();

        // ── 1. Alertes wrong_printer non résolues ──
        $criteria = [
            'SELECT' => [
                'a.id',
                'a.detected_printers_id',
                'a.intended_printers_id',
                'a.expeditions_id',
                'a.toner_property',
                'a.level_percent',
                'a.date_alert',
                'pd.name AS detected_name',
                'pi.name AS intended_name',
                'pd.entities_id',
                'ei.completename AS entity_name',
                'ex.statut AS expedition_statut',
                'ex.date_shipped',
                'ex.date_delivered',
            ],
            'FROM'      => 'glpi_plugin_printgestion_alerts AS a',
            'LEFT JOIN' => [
                'glpi_plugin_printgestion_expeditions AS ex' => [
                    'ON' => ['a' => 'expeditions_id', 'ex' => 'id'],
                ],
                'glpi_printers AS pd' => [
                    'ON' => ['a' => 'detected_printers_id', 'pd' => 'id'],
                ],
                'glpi_printers AS pi' => [
                    'ON' => ['a' => 'intended_printers_id', 'pi' => 'id'],
                ],
                'glpi_entities AS ei' => [
                    'ON' => ['pd' => 'entities_id', 'ei' => 'id'],
                ],
            ],
            'WHERE' => [
                'a.alert_type'  => 'wrong_printer',
                'a.is_resolved' => 0,
            ],
            'ORDER' => ['a.date_alert DESC'],
        ];
        if ($entities_id !== null && $entities_id >= 0) {
            $criteria['WHERE']['pd.entities_id'] = $entities_id;
        }
        // Cloisonnement client (affichage) : entités de l'utilisateur uniquement.
        $criteria['WHERE'][] = getEntitiesRestrictCriteria('pd', '', '', true);

        foreach ($DB->request($criteria) as $a) {
            $days_since = !empty($a['date_alert'])
                ? max(0, (int)((time() - strtotime((string)$a['date_alert'])) / 86400))
                : 0;

            $out[] = [
                'kind'                 => 'wrong_printer',
                'alert_id'             => (int)$a['id'],
                'detected_printers_id' => (int)$a['detected_printers_id'],
                'intended_printers_id' => (int)$a['intended_printers_id'],
                'expeditions_id'       => (int)$a['expeditions_id'],
                'detected_name'        => (string)($a['detected_name'] ?? '?'),
                'intended_name'        => (string)($a['intended_name'] ?? '?'),
                'entity_name'          => (string)($a['entity_name'] ?? ''),
                'toner_property'       => (string)$a['toner_property'],
                'level_percent'        => (int)($a['level_percent'] ?? 0),
                'days_since'           => $days_since,
                'date_detected'        => (string)($a['date_alert'] ?? ''),
                'expedition_statut'    => (string)($a['expedition_statut'] ?? ''),
                'date_shipped'         => (string)($a['date_shipped'] ?? ''),
                'date_delivered'       => (string)($a['date_delivered'] ?? ''),
            ];
        }

        // ── 2. Expéditions en retard : expédiées, en transit ou livrées, mais non
        //       posées plus de reminder_days après l'expédition ──
        $reminder_days = max(1, (int)($config->fields['reminder_days'] ?? 7));
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$reminder_days} days"));

        $criteria = [
            'SELECT' => [
                'e.id',
                'e.printers_id',
                'e.toner_property',
                'e.statut',
                'e.transport_carrier',
                'e.transport_number',
                'e.date_shipped',
                'p.name AS printer_name',
                'p.entities_id',
                'ei.completename AS entity_name',
            ],
            'FROM'      => 'glpi_plugin_printgestion_expeditions AS e',
            'LEFT JOIN' => [
                'glpi_printers AS p' => [
                    'ON' => ['e' => 'printers_id', 'p' => 'id'],
                ],
                'glpi_entities AS ei' => [
                    'ON' => ['p' => 'entities_id', 'ei' => 'id'],
                ],
            ],
            'WHERE' => [
                'e.statut'       => ['shipped', 'transit', 'delivered'],
                'e.date_shipped' => ['<=', $cutoff],
            ],
            'ORDER' => ['e.date_shipped ASC'],
        ];
        if ($entities_id !== null && $entities_id >= 0) {
            $criteria['WHERE']['p.entities_id'] = $entities_id;
        }
        // Cloisonnement client (affichage) : entités de l'utilisateur uniquement.
        $criteria['WHERE'][] = getEntitiesRestrictCriteria('p', '', '', true);

        foreach ($DB->request($criteria) as $e) {
            $days_since = !empty($e['date_shipped'])
                ? max(0, (int)((time() - strtotime((string)$e['date_shipped'])) / 86400))
                : 0;

            $out[] = [
                'kind'             => 'late_shipment',
                'expedition_id'    => (int)$e['id'],
                'printers_id'      => (int)$e['printers_id'],
                'printer_name'     => (string)($e['printer_name'] ?? '?'),
                'entity_name'      => (string)($e['entity_name'] ?? ''),
                'toner_property'   => (string)$e['toner_property'],
                'statut'           => (string)$e['statut'],
                'carrier'          => (string)($e['transport_carrier'] ?? ''),
                'tracking'         => (string)($e['transport_number'] ?? ''),
                'days_since'       => $days_since,
            ];
        }

        return $out;
    }

    /**
     * Crée ou met à jour un snooze pour (imprimante, propriété) jusqu'à une date.
     * Les lignes snoozées sont filtrées de listAll() et du dashboard.
     */
    public static function snooze(int $printers_id, string $property, int $days): bool {
        global $DB;

        if ($printers_id <= 0 || $property === '' || $days <= 0) {
            return false;
        }

        $until = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        // Si un snooze actif existe déjà → on prolonge (on remplace la date)
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_printgestion_alert_snoozes',
            'WHERE'  => [
                'printers_id'    => $printers_id,
                'toner_property' => $property,
            ],
            'LIMIT'  => 1,
        ])->current();

        if (is_array($existing)) {
            return (bool)$DB->update('glpi_plugin_printgestion_alert_snoozes', [
                'snooze_until' => $until,
                'users_id'     => (int)(Session::getLoginUserID() ?: 0),
            ], ['id' => (int)$existing['id']]);
        }

        return (bool)$DB->insert('glpi_plugin_printgestion_alert_snoozes', [
            'printers_id'    => $printers_id,
            'toner_property' => $property,
            'snooze_until'   => $until,
            'users_id'       => (int)(Session::getLoginUserID() ?: 0),
            'date_creation'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Retourne les couples (printer, property) actuellement snoozés.
     * Clé = "{printers_id}|{property}".
     */
    public static function getSnoozedPairs(): array {
        global $DB;

        $out = [];
        $now = date('Y-m-d H:i:s');

        foreach ($DB->request([
            'SELECT' => ['printers_id', 'toner_property', 'snooze_until'],
            'FROM'   => 'glpi_plugin_printgestion_alert_snoozes',
            'WHERE'  => ['snooze_until' => ['>', $now]],
        ]) as $r) {
            $key = $r['printers_id'] . '|' . $r['toner_property'];
            $out[$key] = (string)$r['snooze_until'];
        }

        return $out;
    }

    /**
     * Supprime un snooze expiré ou manuel.
     */
    public static function clearSnooze(int $printers_id, string $property): bool {
        global $DB;
        return (bool)$DB->delete('glpi_plugin_printgestion_alert_snoozes', [
            'printers_id'    => $printers_id,
            'toner_property' => $property,
        ]);
    }

    /**
     * Marque une alerte comme résolue (action manuelle "Ignorer").
     */
    public static function resolveAlert(int $alert_id): bool {
        global $DB;
        if ($alert_id <= 0) {
            return false;
        }
        return (bool)$DB->update('glpi_plugin_printgestion_alerts', [
            'is_resolved' => 1,
        ], ['id' => $alert_id]);
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
