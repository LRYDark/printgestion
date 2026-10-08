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
     * @param ?int[] $printer_ids null : tout le parc (tâche quotidienne) ; sinon ces imprimantes seulement (relevé
     *                            manuel : la même détection, sans reparcourir tout le parc pendant la page).
     * @return int Nombre de changements détectés.
     */
    public static function detectChanges(?array $printer_ids = null): int {
        global $DB;

        $config         = PluginPrintgestionConfig::getInstance();
        $delta_threshold = max(1, min(100, (int)($config->fields['detection_delta'] ?? 20)));

        $detected = 0;
        $started  = microtime(true);

        // Récupère les couples (printer, property) ayant au moins 2 relevés
        $pairs = $DB->request([
            'SELECT'   => ['printers_id', 'property_name'],
            'FROM'     => 'glpi_plugin_printgestion_toner_readings',
            'WHERE'    => $printer_ids === null ? [] : ['printers_id' => array_map('intval', $printer_ids)],
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
                    $DB->insert('glpi_plugin_printgestion_historical_yields', PluginPrintgestionEntityscope::forPrinter($printers_id) + [
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
            $DB->insert(self::getTable(), PluginPrintgestionEntityscope::forPrinter($printers_id) + [
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

        if ($printer_ids === null) {
            PluginPrintgestionLogger::duration('cartouches', 'Détection des changements de cartouche (tout le parc)', $started, sprintf('%d changement(s)', $detected));
        }
        return $detected;
    }

    /**
     * Détecte le cas « mauvaise imprimante » et crée une alerte wrong_printer : une
     * proposition à confirmer par un humain, jamais une réattribution automatique.
     *
     * Rapprochement strict, pour ne jamais conclure à tort qu'un envoi a été posé ailleurs :
     *   - envoi déjà parti (expédié, en transit ou livré) dans la fenêtre de détection ;
     *     jamais un envoi encore en attente, dont la cartouche n'a pas pu être posée ;
     *   - autre imprimante de la même entité, pour la même propriété SNMP ;
     *   - même site de livraison (racine du lieu) ;
     *   - même référence de cartouche, résolue pour les deux imprimantes.
     * Site ou référence inconnus : aucun rapprochement.
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

        // Entité, site et référence de l'imprimante détectée
        $detectedRow = $DB->request([
            'SELECT' => ['entities_id', 'locations_id'],
            'FROM'   => 'glpi_printers',
            'WHERE'  => ['id' => $detected_printers_id],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($detectedRow)) {
            return 0;
        }
        $detected_entity = (int)$detectedRow['entities_id'];
        $detected_site   = PluginPrintgestionDemande::getSiteLocationId((int)$detectedRow['locations_id']);
        $detected_ref    = (int)PluginPrintgestionSnmpmapping::resolveCartridge($detected_printers_id, $property)['cartridgeitems_id'];
        if ($detected_site <= 0 || $detected_ref <= 0) {
            return 0;
        }

        // Envoi parti le plus récent pour une autre imprimante du même site, même référence
        $expedition = null;
        foreach ($DB->request([
            'SELECT'    => ['e.id', 'e.printers_id', 'p.locations_id'],
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
                'e.statut'         => PluginPrintgestionExpedition::DEPARTED_STATUSES,
            ],
            'ORDER' => ['e.date_alert DESC'],
        ]) as $candidate) {
            if (PluginPrintgestionDemande::getSiteLocationId((int)$candidate['locations_id']) !== $detected_site) {
                continue;
            }
            if ((int)PluginPrintgestionSnmpmapping::resolveCartridge((int)$candidate['printers_id'], $property)['cartridgeitems_id'] !== $detected_ref) {
                continue;
            }
            $expedition = $candidate;
            break;
        }

        if ($expedition === null) {
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

        $DB->insert('glpi_plugin_printgestion_alerts', PluginPrintgestionEntityscope::forPrinter($detected_printers_id) + [
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
     * Ouvre une cartouche native sur une imprimante, par la classe Cartridge de GLPI.
     *
     * add() puis update() : Cartridge::prepareInputForAdd() ne garde de l'entrée que la référence — l'entité et la date
     * d'entrée viennent d'elle, comme l'ajout au stock de l'écran natif. Pas install() : elle prendrait une cartouche du
     * stock réel de l'administrateur, et une détection SNMP n'a pas à consommer son stock. La trace posée sur
     * l'imprimante est celle d'install(), avec son texte traduit par GLPI.
     *
     * @return int identifiant de la cartouche ; 0 si GLPI a refusé (elle n'est alors pas laissée à moitié créée)
     */
    private static function openNativeCartridge(int $cartridgeitems_id, int $printers_id, string $date, int $pages): int {
        $cartridge = new Cartridge();
        $id        = (int) $cartridge->add(['cartridgeitems_id' => $cartridgeitems_id]);
        if ($id <= 0) {
            return 0;
        }
        if (!$cartridge->update(['id' => $id, 'printers_id' => $printers_id, 'date_in' => $date, 'date_use' => $date, 'pages' => $pages])) {
            $cartridge->delete(['id' => $id], true);
            return 0;
        }
        Log::history($printers_id, Printer::class, ['0', '', __('Installing a cartridge')], 0, Log::HISTORY_LOG_SIMPLE_MESSAGE);
        return $id;
    }

    /**
     * Clôt une cartouche native, par la classe Cartridge de GLPI.
     *
     * update() plutôt qu'uninstall() : uninstall() prend le dernier compteur enregistré sur l'imprimante, alors que le
     * plugin a déjà le plus récent (le journal des pages). GLPI stocke dans « pages » le compteur de l'imprimante au
     * moment de la clôture, pas un écart. La trace posée sur l'imprimante est celle d'uninstall().
     */
    private static function closeNativeCartridge(int $cartridges_id, int $printers_id, string $date, int $pages): bool {
        $cartridge = new Cartridge();
        if (!$cartridge->update(['id' => $cartridges_id, 'date_out' => $date, 'pages' => $pages])) {
            return false;
        }
        Log::history($printers_id, Printer::class, ['0', '', __('Uninstalling a cartridge')], 0, Log::HISTORY_LOG_SIMPLE_MESSAGE);
        return true;
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
            $parsed = PluginPrintgestionTonerreading::parseTonerValue((string)$r['value'], (string)$r['property'], (int)$r['printers_id']);
            if (!$parsed['usable']) {
                continue;
            }

            $printers_id = (int)$r['printers_id'];
            $property    = (string)$r['property'];

            // Résolution stricte (modèle obligatoire) : pas de cartouche native sans référence sûre.
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
            // Compteur imprimante actuel (utilisé pour la cartouche native ET
            // pour la ligne miroir interne — une seule query)
            $current_counter = self::getPrinterPagesCounter($printers_id);

            if (self::openNativeCartridge($cartridgeitems_id, $printers_id, $install_date, $current_counter) <= 0) {
                continue;
            }

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
                $parsed_live = PluginPrintgestionTonerreading::parseTonerValue((string)$r['value'], $property, $printers_id);
                $level_at_install = $parsed_live['usable'] ? (int)$parsed_live['value'] : 100;

                $DB->insert(self::getTable(), PluginPrintgestionEntityscope::forPrinter($printers_id) + [
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

        // L'imprimante doit exister. Son entité n'est plus lue : la cartouche prend celle de sa référence,
        // la règle de la classe native.
        if (countElementsInTable(Printer::getTable(), ['id' => $printers_id]) === 0) {
            return;
        }

        // Résolution stricte (modèle obligatoire) : pas de synchronisation native sans référence sûre.
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
            self::closeNativeCartridge((int) $active['id'], $printers_id, $today, $current_counter);
        }

        // 2. La nouvelle cartouche active, pages initialisées au compteur actuel.
        self::openNativeCartridge($cartridgeitems_id, $printers_id, $today, $current_counter);
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
