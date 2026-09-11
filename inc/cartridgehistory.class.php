<?php
/**
 * PluginPrintgestionCartridgehistory — Point 3 : détection automatique des
 * changements de cartouches via analyse des hausses de niveau toner.
 *
 * Algorithme : pour chaque imprimante × propriété toner,
 *   level_t = relevé actuel, level_t-1 = relevé le plus récent précédent.
 *   si level_t - level_t-1 >= detection_delta (%) → changement détecté.
 *
 * Le seuil detection_delta est paramétrable dans la configuration (défaut 20%).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionCartridgehistory extends CommonDBTM {

    static $rightname = 'plugin_printgestion_dashboard';

    static function getTypeName($nb = 0) {
        return _n('Historique cartouche', 'Historiques cartouches', $nb, 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_cartridge_history';
        }
        return parent::getTable($classname);
    }

    /**
     * Boucle sur toutes les imprimantes × propriétés et détecte les changements.
     * À appeler juste après PluginPrintgestionTonerreading::snapshotAllPrinters().
     *
     * @return int Nombre de changements détectés.
     */
    public static function detectChanges(): int {
        global $DB;

        $config         = PluginPrintgestionConfig::getInstance();
        $delta_threshold = max(1, min(100, (int)($config->fields['detection_delta'] ?? 20)));

        $detected = 0;

        // Récupère les couples (printer, property) ayant au moins 2 relevés
        $pairs = $DB->request([
            'SELECT'   => ['printers_id', 'property_name'],
            'FROM'     => 'glpi_plugin_printgestion_toner_readings',
            'GROUPBY'  => ['printers_id', 'property_name'],
        ]);

        foreach ($pairs as $pair) {
            $printers_id = (int)$pair['printers_id'];
            $property    = (string)$pair['property_name'];

            $twoLast = [];
            foreach ($DB->request([
                'SELECT' => ['id', 'level_percent', 'reading_date'],
                'FROM'   => 'glpi_plugin_printgestion_toner_readings',
                'WHERE'  => [
                    'printers_id'   => $printers_id,
                    'property_name' => $property,
                ],
                'ORDER'  => ['reading_date DESC'],
                'LIMIT'  => 2,
            ]) as $r) {
                $twoLast[] = $r;
            }

            if (count($twoLast) < 2) {
                continue;
            }

            $current  = $twoLast[0];
            $previous = $twoLast[1];

            $delta = (int)$current['level_percent'] - (int)$previous['level_percent'];
            if ($delta < $delta_threshold) {
                continue;
            }

            // Hausse significative détectée → changement de cartouche
            // Vérifier qu'on n'a pas déjà enregistré ce changement (idempotence)
            $alreadyLogged = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'printers_id'    => $printers_id,
                    'toner_property' => $property,
                    'date_install'   => $current['reading_date'],
                ],
                'LIMIT' => 1,
            ])->current();

            if (is_array($alreadyLogged)) {
                continue;
            }

            // ══════════════════════════════════════════════════════════════
            //  Check "mauvaise imprimante" :
            //  uniquement si CETTE imprimante n'attendait aucun envoi pour ce toner
            //  (sinon la cartouche posée est la sienne) et qu'un envoi EN COURS
            //  existe sur une AUTRE imprimante de la MÊME entité → alerte
            //  wrong_printer. La pose est tout de même enregistrée dans l'historique
            //  ci-dessous : elle ouvre la garde anti-double-envoi de cette imprimante.
            // ══════════════════════════════════════════════════════════════
            $wrong_printer_alert_id = PluginPrintgestionExpedition::getActiveForPrinterProperty($printers_id, $property) === null
                ? self::detectAndLogWrongPrinter(
                    $printers_id,
                    $property,
                    (int)$current['level_percent'],
                    (string)$current['reading_date']
                )
                : 0;

            // Clôture la cartouche précédente (si existe)
            $openEntry = $DB->request([
                'SELECT' => ['id', 'level_at_install', 'printer_counter_at_install', 'date_install'],
                'FROM'   => self::getTable(),
                'WHERE'  => [
                    'printers_id'    => $printers_id,
                    'toner_property' => $property,
                    'date_removal'   => null,
                ],
                'ORDER'  => ['id DESC'],
                'LIMIT'  => 1,
            ])->current();

            // Résout le type de cartouche
            $mapping = PluginPrintgestionSnmpmapping::resolveForPrinter($printers_id, $property);
            $color   = is_array($mapping) ? (string)($mapping['toner_color'] ?? 'black') : 'black';

            // Récupère le dernier compteur pages imprimées — avec fallback natif GLPI
            $currentCounter = self::getPrinterPagesCounter($printers_id);

            if (is_array($openEntry)) {
                $pagesPrinted = null;
                if (isset($openEntry['printer_counter_at_install'])) {
                    $pagesPrinted = max(0, $currentCounter - (int)$openEntry['printer_counter_at_install']);
                }
                $DB->update(self::getTable(), [
                    'level_at_removal'           => (int)$previous['level_percent'],
                    'date_removal'               => $current['reading_date'],
                    'printer_counter_at_removal' => $currentCounter,
                    'pages_printed'              => $pagesPrinted,
                ], ['id' => (int)$openEntry['id']]);

                // ── Mémoization du yield mesuré pour le cycle qui vient de se terminer ──
                // Formule : yield (pages / 1%) = pages_imprimées / (level_install - level_removal)
                // Permet au prochain cycle d'avoir une estimation précise dès le 1er relevé.
                $lvl_install = (int)($openEntry['level_at_install'] ?? 0);
                $lvl_removal = (int)$previous['level_percent'];
                $delta_pct   = $lvl_install - $lvl_removal;
                if ($delta_pct >= 10 && is_int($pagesPrinted) && $pagesPrinted > 0) {
                    $yield_per_pct = round($pagesPrinted / $delta_pct, 2);
                    $DB->insert('glpi_plugin_printgestion_historical_yields', [
                        'printers_id'       => $printers_id,
                        'property_name'     => $property,
                        'yield_per_percent' => $yield_per_pct,
                        'cycle_pages'       => $pagesPrinted,
                        'cycle_start_date'  => (string)$openEntry['date_install'] ?? null,
                        'cycle_end_date'    => (string)$current['reading_date'],
                    ]);
                }
            }

            // Nouvelle entrée d'installation : pose réellement détectée (is_detected = 1,
            // contrairement aux lignes d'amorçage) — point de départ de la garde.
            $DB->insert(self::getTable(), [
                'printers_id'                => $printers_id,
                'toner_property'             => $property,
                'toner_color'                => $color,
                'level_at_install'           => (int)$current['level_percent'],
                'date_install'               => $current['reading_date'],
                'printer_counter_at_install' => $currentCounter,
                'is_detected'                => 1,
            ]);

            if ($wrong_printer_alert_id > 0) {
                // Cartouche d'un envoi destiné à une autre imprimante : cartouches natives
                // et clôture de l'envoi sont faites à la réattribution.
                $detected++;
                continue;
            }

            // Sync avec les tables natives GLPI (glpi_cartridges) :
            // → GLPI affiche nativement "Cartouches en cours" / "Cartouches usagées"
            //    dans l'onglet Cartouches de la fiche imprimante.
            // Le paramètre cartridge_type n'est plus utilisé (le résolveur lit le mapping).
            self::syncNativeCartridges(
                $printers_id,
                $property,
                $current['reading_date'],
                $currentCounter
            );

            $detected++;

            // Pose détectée : clôt l'envoi en cours de cette imprimante et de ce toner.
            PluginPrintgestionExpedition::markInstalledOnDetection($printers_id, $property, (string)$current['reading_date']);

            // Auto-résolution des alertes wrong_printer qui pointaient vers cette
            // imprimante : elle vient enfin de recevoir sa cartouche, donc les alertes
            // disant "cartouche attendue ici, détectée ailleurs" sont obsolètes.
            self::resolveWrongPrinterAlertsForIntended($printers_id, $property);
        }

        return $detected;
    }

    /**
     * Détecte le cas "mauvaise imprimante" et crée une alerte wrong_printer
     * si un envoi EN COURS (ni posé ni annulé) récent existe pour la même propriété
     * SNMP sur une autre imprimante de la même entité. Un envoi déjà posé ou annulé
     * ne peut pas être la cartouche détectée : il n'est jamais retenu.
     *
     * Retourne l'ID de l'alerte créée, ou 0 si pas de cas wrong_printer.
     *
     * Anti-doublon : si une alerte wrong_printer non résolue existe déjà pour
     * ce couple (detected_printer, property), on la retourne sans en créer une
     * nouvelle.
     */
    public static function detectAndLogWrongPrinter(
        int $detected_printers_id,
        string $property,
        int $level_at_detection,
        string $detected_at
    ): int {
        global $DB;

        $config = PluginPrintgestionConfig::getInstance();
        $lookback_days = max(1, (int)($config->fields['wrong_printer_lookback_days'] ?? 30));
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$lookback_days} days"));

        // Entité de l'imprimante détectée
        $detectedRow = $DB->request([
            'SELECT' => ['entities_id'],
            'FROM'   => 'glpi_printers',
            'WHERE'  => ['id' => $detected_printers_id],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($detectedRow)) {
            return 0;
        }
        $detected_entity = (int)$detectedRow['entities_id'];

        // Cherche une expédition récente pour la même propriété + autre imprimante + même entité
        $expedition = $DB->request([
            'SELECT'    => ['e.id', 'e.printers_id'],
            'FROM'      => 'glpi_plugin_printgestion_expeditions AS e',
            'INNER JOIN'=> [
                'glpi_printers AS p' => [
                    'ON' => ['e' => 'printers_id', 'p' => 'id'],
                ],
            ],
            'WHERE' => [
                'e.toner_property' => $property,
                'e.printers_id'    => ['<>', $detected_printers_id],
                'p.entities_id'    => $detected_entity,
                'e.date_alert'     => ['>=', $cutoff],
                'e.statut'         => PluginPrintgestionExpedition::ACTIVE_STATUSES,
            ],
            'ORDER' => ['e.date_alert DESC'],
            'LIMIT' => 1,
        ])->current();

        if (!is_array($expedition)) {
            return 0; // Pas de cas wrong_printer
        }

        $intended_printers_id = (int)$expedition['printers_id'];
        $expeditions_id       = (int)$expedition['id'];

        // Anti-doublon : alerte non résolue déjà présente ?
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_printgestion_alerts',
            'WHERE'  => [
                'alert_type'           => 'wrong_printer',
                'detected_printers_id' => $detected_printers_id,
                'intended_printers_id' => $intended_printers_id,
                'toner_property'       => $property,
                'is_resolved'          => 0,
            ],
            'LIMIT' => 1,
        ])->current();

        if (is_array($existing)) {
            return (int)$existing['id'];
        }

        $DB->insert('glpi_plugin_printgestion_alerts', [
            'alert_type'           => 'wrong_printer',
            'printers_id'          => $detected_printers_id,
            'detected_printers_id' => $detected_printers_id,
            'intended_printers_id' => $intended_printers_id,
            'expeditions_id'       => $expeditions_id,
            'toner_property'       => $property,
            'level_percent'        => $level_at_detection,
            'date_alert'           => $detected_at,
            'is_resolved'          => 0,
            'mail_sent'            => 0,
        ]);

        return (int)$DB->insertId();
    }

    /**
     * Auto-résolution d'une alerte wrong_printer quand une hausse est détectée
     * sur l'imprimante prévue (scénario chemin A : le client a remis la cartouche
     * à la bonne place physiquement).
     */
    public static function resolveWrongPrinterAlertsForIntended(int $intended_printers_id, string $property): void {
        global $DB;

        $DB->update('glpi_plugin_printgestion_alerts', [
            'is_resolved' => 1,
        ], [
            'alert_type'           => 'wrong_printer',
            'intended_printers_id' => $intended_printers_id,
            'toner_property'       => $property,
            'is_resolved'          => 0,
        ]);
    }

    /**
     * Retourne le compteur total de pages d'une imprimante avec fallback robuste :
     *   1. Dernière ligne de glpi_printerlogs (collectée par l'agent)
     *   2. glpi_printers.last_pages_counter (valeur live de l'agent)
     *   3. 0 si rien de dispo
     */
    public static function getPrinterPagesCounter(int $printers_id): int {
        global $DB;

        // 1. glpi_printerlogs (historique détaillé)
        $row = $DB->request([
            'SELECT' => ['total_pages'],
            'FROM'   => 'glpi_printerlogs',
            'WHERE'  => [
                'itemtype' => 'Printer',
                'items_id' => $printers_id,
            ],
            'ORDER'  => ['date DESC'],
            'LIMIT'  => 1,
        ])->current();
        if (is_array($row) && (int)($row['total_pages'] ?? 0) > 0) {
            return (int)$row['total_pages'];
        }

        // 2. glpi_printers.last_pages_counter (fallback natif)
        $row = $DB->request([
            'SELECT' => ['last_pages_counter'],
            'FROM'   => 'glpi_printers',
            'WHERE'  => ['id' => $printers_id],
            'LIMIT'  => 1,
        ])->current();
        if (is_array($row)) {
            return (int)($row['last_pages_counter'] ?? 0);
        }

        return 0;
    }

    /**
     * Bootstrap initial : pour chaque imprimante × propriété toner usable,
     * crée une entrée native glpi_cartridges "en cours d'utilisation" si aucune
     * n'existe déjà. Idempotent : skip les couples déjà présents.
     *
     * Appelé depuis le cron snapshot pour donner une visibilité immédiate dans
     * l'onglet Cartouches natif de GLPI, sans attendre une détection de changement.
     *
     * @return int Nombre de cartouches natives créées.
     */
    public static function bootstrapNativeCartridges(): int {
        global $DB;

        $created = 0;

        // Toutes les propriétés SNMP live avec valeur exploitable
        $rows = $DB->request([
            'SELECT' => ['ci.printers_id', 'ci.property', 'ci.value', 'ci.date_creation', 'p.entities_id'],
            'FROM'   => 'glpi_printers_cartridgeinfos AS ci',
            'INNER JOIN' => [
                'glpi_printers AS p' => [
                    'ON' => ['ci' => 'printers_id', 'p' => 'id'],
                ],
            ],
            'WHERE'  => [
                'p.is_deleted'  => 0,
                'p.is_template' => 0,
            ],
        ]);

        foreach ($rows as $r) {
            $parsed = PluginPrintgestionTonerreading::parseTonerValue((string)$r['value']);
            if (!$parsed['usable']) {
                continue;
            }

            $printers_id = (int)$r['printers_id'];
            $property    = (string)$r['property'];

            // Résolution via le résolveur intelligent (cascade : type+model → type → nom)
            $cartridgeitems_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
            if ($cartridgeitems_id <= 0) {
                continue;
            }

            // Déjà une cartouche active pour ce couple (printer, cartridgeitems_id) ?
            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_cartridges',
                'WHERE'  => [
                    'printers_id'       => $printers_id,
                    'cartridgeitems_id' => $cartridgeitems_id,
                    'date_out'          => null,
                ],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($existing)) {
                continue;
            }

            // Création : on utilise la date_creation SNMP comme date d'installation
            // (première fois que l'agent a vu cette propriété = approximation raisonnable)
            $date_creation_snmp = (string)($r['date_creation'] ?? '');
            $install_date = ($date_creation_snmp !== '' && $date_creation_snmp !== '0000-00-00 00:00:00')
                ? substr($date_creation_snmp, 0, 10)
                : date('Y-m-d');
            $now = date('Y-m-d H:i:s');

            // Compteur imprimante actuel (utilisé pour glpi_cartridges.pages ET
            // pour la ligne miroir interne — une seule query)
            $current_counter = self::getPrinterPagesCounter($printers_id);

            $DB->insert('glpi_cartridges', [
                'entities_id'       => (int)$r['entities_id'],
                'cartridgeitems_id' => $cartridgeitems_id,
                'printers_id'       => $printers_id,
                'date_in'           => $install_date,
                'date_use'          => $install_date,
                'date_out'          => null,
                'pages'             => $current_counter,
                'date_creation'     => $now,
                'date_mod'          => $now,
            ]);

            // Création miroir dans la table interne — sert à suivre level_at_install
            // et à garder un historique SNMP cohérent au prochain changement.
            $internal_exists = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'printers_id'    => $printers_id,
                    'toner_property' => $property,
                    'date_removal'   => null,
                ],
                'LIMIT' => 1,
            ])->current();

            if (!is_array($internal_exists)) {
                // Parse la valeur live pour le niveau à l'install
                $parsed_live = PluginPrintgestionTonerreading::parseTonerValue((string)$r['value']);
                $level_at_install = $parsed_live['usable'] ? (int)$parsed_live['value'] : 100;

                $DB->insert(self::getTable(), [
                    'printers_id'                => $printers_id,
                    'toner_property'             => $property,
                    'toner_color'                => PluginPrintgestionSnmpmapping::detectColor($property),
                    'level_at_install'           => $level_at_install,
                    'date_install'               => $install_date . ' 00:00:00',
                    'printer_counter_at_install' => $current_counter,
                ]);
            }

            $created++;
        }

        return $created;
    }

    /**
     * Synchronise une détection de changement cartouche avec les tables natives GLPI.
     *
     * - Ferme la cartouche native active (date_out = now, pages = delta compteur)
     * - Ouvre une nouvelle cartouche native (date_in = date_use = now)
     *
     * Le cartridgeitems_id est résolu par nom (LIKE) dans glpi_cartridgeitems.
     * Si aucun match, pas de sync native (mais ma table interne garde la trace).
     *
     * @param int    $printers_id     ID de l'imprimante
     * @param string $property        Propriété SNMP (ex : "Toner Cyan")
     * @param string $detected_at     Date/heure de la détection (Y-m-d H:i:s)
     * @param int    $current_counter Compteur total_pages de l'imprimante
     * @param string $cartridge_type  Nom cartouche issu du mapping (ex : "Toner Cyan")
     */
    public static function syncNativeCartridges(
        int $printers_id,
        string $property,
        string $detected_at,
        int $current_counter,
        string $cartridge_type = '' // conservé pour compat (non utilisé, le résolveur lit le mapping)
    ): void {
        global $DB;

        // Entité de l'imprimante (requise pour glpi_cartridges)
        $printerRow = $DB->request([
            'SELECT' => ['entities_id'],
            'FROM'   => 'glpi_printers',
            'WHERE'  => ['id' => $printers_id],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($printerRow)) {
            return;
        }
        $entities_id = (int)$printerRow['entities_id'];

        // Résolveur intelligent (cascade : type+model → type → nom LIKE)
        $cartridgeitems_id = PluginPrintgestionSnmpmapping::resolveCartridgeItemForSnmp($printers_id, $property);
        if ($cartridgeitems_id <= 0) {
            return;
        }
        $today = substr($detected_at, 0, 10); // date seule pour glpi_cartridges

        // 1. Clôture la/les cartouches natives actives sur cette imprimante pour ce
        //    cartridgeitems_id (date_out NULL). On calcule les pages imprimées en
        //    delta sur le compteur imprimante.
        $activeCartridges = $DB->request([
            'SELECT' => ['id', 'date_use'],
            'FROM'   => 'glpi_cartridges',
            'WHERE'  => [
                'printers_id'       => $printers_id,
                'cartridgeitems_id' => $cartridgeitems_id,
                'date_out'          => null,
            ],
        ]);

        foreach ($activeCartridges as $active) {
            // GLPI stocke dans glpi_cartridges.pages le COMPTEUR IMPRIMANTE au moment
            // où la cartouche est clôturée (pas un delta). Le calcul "pages imprimées"
            // est fait dynamiquement par GLPI comme diff entre cartouches successives.
            $DB->update('glpi_cartridges', [
                'date_out' => $today,
                'pages'    => $current_counter,
                'date_mod' => $detected_at,
            ], ['id' => (int)$active['id']]);
        }

        // 2. Crée la nouvelle cartouche active (date_in = date_use = today, date_out = null)
        // On initialise pages = compteur actuel (même pattern que l'ajout natif GLPI,
        // cf. Cartridge.php:330 $toadd['pages'] = $printer->fields['last_pages_counter']).
        $DB->insert('glpi_cartridges', [
            'entities_id'       => $entities_id,
            'cartridgeitems_id' => $cartridgeitems_id,
            'printers_id'       => $printers_id,
            'date_in'           => $today,
            'date_use'          => $today,
            'date_out'          => null,
            'pages'             => $current_counter,
            'date_creation'     => $detected_at,
            'date_mod'          => $detected_at,
        ]);
    }

    /**
     * Retourne l'historique d'une imprimante (toutes cartouches).
     */
    public static function getHistoryForPrinter(int $printers_id): array {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['printers_id' => $printers_id],
            'ORDER' => ['date_install DESC'],
        ]) as $r) {
            $rows[] = $r;
        }
        return $rows;
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
