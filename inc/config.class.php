<?php
/**
 * PluginPrintgestionConfig — configuration singleton id=1 (pattern plugin Gestion).
 * Gère aussi l'installation/désinstallation des tables annexes du plugin.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionConfig extends CommonDBTM {

    static $rightname = 'plugin_printgestion_config';

    /** Colonnes chiffrées avec GLPIKey (déclarées au hook secured_fields dans setup.php). */
    const SECRET_FIELDS = ['api_ups', 'api_gls', 'api_chronopost'];

    static private $_instance = null;

    /** Cause du dernier échec de sendMail() ; chaîne vide après un envoi réussi. */
    private static string $last_mail_error = '';

    /** Cause du dernier échec de sendMail(), à afficher ou journaliser par l'appelant. */
    public static function getLastMailError(): string {
        return self::$last_mail_error;
    }

    /**
     * Valeur déchiffrée d'une clé API enregistrée. Chaîne vide si aucune clé, ou si
     * elle est indéchiffrable (GLPIKey signale alors lui-même l'échec).
     */
    public static function getSecret(string $field): string {
        if (!in_array($field, self::SECRET_FIELDS, true)) {
            throw new InvalidArgumentException(sprintf('Champ secret inconnu : %s', $field));
        }
        $stored = (string)(self::getInstance()->fields[$field] ?? '');
        if ($stored === '') {
            return '';
        }
        return (string)(new GLPIKey())->decrypt($stored);
    }

    function __construct() {
        global $DB;
        if ($DB->tableExists($this->getTable())) {
            $this->getFromDB(1);
        }
    }

    static function getInstance() {
        if (!isset(self::$_instance)) {
            global $DB;
            self::$_instance = new self();
            // Résilience : si la table config n'existe pas (plugin non installé /
            // désinstallation en cours), getFromDB() lèverait une erreur SQL → on
            // retombe sur une config vide pour ne pas casser plugin_init / les pages.
            if (!$DB->tableExists(self::getTable()) || !self::$_instance->getFromDB(1)) {
                self::$_instance->getEmpty();
            }
        }
        return self::$_instance;
    }

    static function getTypeName($nb = 0) {
        return __('Print Gestion', 'printgestion');
    }

    /**
     * Types de contrat natifs (ContractType) paramétrés « consommables inclus » :
     * une ligne de commande est sous contrat si l'imprimante a un contrat en cours
     * de l'un de ces types. Liste vide = rien n'est sous contrat.
     */
    public static function getConsumablesContractTypes(): array {
        $raw = (string)(self::getInstance()->fields['consumables_contracttypes'] ?? '');
        $ids = array_filter(
            array_map('intval', preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            static fn(int $id) => $id > 0
        );
        return array_values(array_unique($ids));
    }

    /**
     * Interrupteur de feature. Permet de n'activer que ce qu'on utilise
     * (menu + onglets + crons du domaine désactivés sinon → pas de ressources
     * gaspillées). Features : 'contrats' | 'toner' | 'cout'.
     * Défaut activé (1) si la colonne n'existe pas encore (install en cours).
     */
    static function isFeatureEnabled(string $feature): bool {
        global $DB;
        // Table config absente (plugin non installé / uninstall en cours) → aucune
        // feature active : évite que plugin_init interroge des tables supprimées.
        if (!$DB->tableExists(self::getTable())) {
            return false;
        }
        $cfg = self::getInstance();
        $col = 'enable_' . $feature;
        return (int) ($cfg->fields[$col] ?? 1) === 1;
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'Config') {
            return __('Print Gestion', 'printgestion');
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() == 'Config') {
            self::showConfigForm();
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────
    //  SCHÉMA DE RÉFÉRENCE (étape 1.0.0 du versionnement)
    // ─────────────────────────────────────────────────────────────

    /**
     * Schéma de référence 1.0.0. Appelé UNIQUEMENT par
     * PluginPrintgestionSchema::migrateTo100(). Toute évolution ultérieure du
     * schéma est une nouvelle étape de PluginPrintgestionSchema, jamais une
     * modification de cette méthode.
     */
    static function installSchemaBaseline(Migration $migration) {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // ─── Table config (singleton id=1) ────────────────────────
        $table = 'glpi_plugin_printgestion_configs';
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = "CREATE TABLE IF NOT EXISTS `$table` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `group_tech` int {$default_key_sign} DEFAULT NULL,
                `group_planif` int {$default_key_sign} DEFAULT NULL,
                `group_achat` int {$default_key_sign} DEFAULT NULL,
                `group_commercial` int {$default_key_sign} DEFAULT NULL,
                `mode_planif` enum('group','emails') NOT NULL DEFAULT 'group',
                `mode_achat` enum('group','emails') NOT NULL DEFAULT 'group',
                `mode_commercial` enum('group','emails') NOT NULL DEFAULT 'group',
                `emails_planif` text DEFAULT NULL,
                `emails_achat` text DEFAULT NULL,
                `emails_commercial` text DEFAULT NULL,
                `threshold_days` int NOT NULL DEFAULT '30',
                `threshold_level` int NOT NULL DEFAULT '15',
                `reminder_days` int NOT NULL DEFAULT '7',
                `reminder_recipients` enum('planif','commercial','both') NOT NULL DEFAULT 'both',
                `detection_delta` int NOT NULL DEFAULT '20',
                `gabarit_planif` int {$default_key_sign} DEFAULT NULL,
                `gabarit_planif_group` int {$default_key_sign} DEFAULT NULL,
                `gabarit_achat` int {$default_key_sign} DEFAULT NULL,
                `gabarit_commercial` int {$default_key_sign} DEFAULT NULL,
                `gabarit_rappel` int {$default_key_sign} DEFAULT NULL,
                `gabarit_courtoisie` int {$default_key_sign} DEFAULT NULL,
                `api_ups` varchar(255) DEFAULT NULL,
                `api_gls` varchar(255) DEFAULT NULL,
                `api_chronopost` varchar(255) DEFAULT NULL,
                `tracking_frequency` int NOT NULL DEFAULT '4',
                `plugin_gestion_enabled` tinyint NOT NULL DEFAULT '0',
                `wrong_printer_auto_reassign_days` int NOT NULL DEFAULT '7',
                `wrong_printer_lookback_days` int NOT NULL DEFAULT '30',
                `billing_require_contract` tinyint NOT NULL DEFAULT '1',
                `billing_require_counter` tinyint NOT NULL DEFAULT '1',
                `billing_require_activity` tinyint NOT NULL DEFAULT '1',
                `default_pages_per_cartridge` int NOT NULL DEFAULT '5000',
                `enable_contrats` tinyint NOT NULL DEFAULT '1',
                `enable_toner` tinyint NOT NULL DEFAULT '1',
                `enable_cout` tinyint NOT NULL DEFAULT '1',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
            $DB->doQuery($query); // lève une exception en cas d'erreur SQL

            $config = new self();
            $config->add(['id' => 1]);
        } else {
            // Migration : ajout colonnes pour installations existantes
            foreach ([
                'mode_planif'                      => "enum('group','emails') NOT NULL DEFAULT 'group'",
                'mode_achat'                       => "enum('group','emails') NOT NULL DEFAULT 'group'",
                'mode_commercial'                  => "enum('group','emails') NOT NULL DEFAULT 'group'",
                'emails_planif'                    => "text DEFAULT NULL",
                'emails_achat'                     => "text DEFAULT NULL",
                'emails_commercial'                => "text DEFAULT NULL",
                'wrong_printer_auto_reassign_days' => "int NOT NULL DEFAULT '7'",
                'wrong_printer_lookback_days'      => "int NOT NULL DEFAULT '30'",
                'billing_require_contract'         => "tinyint NOT NULL DEFAULT '1'",
                'billing_require_counter'          => "tinyint NOT NULL DEFAULT '1'",
                'billing_require_activity'         => "tinyint NOT NULL DEFAULT '1'",
                'default_pages_per_cartridge'      => "int NOT NULL DEFAULT '5000'",
                'gabarit_planif_group'             => "int {$default_key_sign} DEFAULT NULL",
                'gabarit_courtoisie'               => "int {$default_key_sign} DEFAULT NULL",
                'reminder_recipients'              => "enum('planif','commercial','both') NOT NULL DEFAULT 'both'",
                'enable_contrats'                  => "tinyint NOT NULL DEFAULT '1'",
                'enable_toner'                     => "tinyint NOT NULL DEFAULT '1'",
                'enable_cout'                      => "tinyint NOT NULL DEFAULT '1'",
            ] as $col => $def) {
                if (!$DB->fieldExists($table, $col)) {
                    $migration->addField($table, $col, $def);
                }
            }
            $migration->migrationOneTable($table);
        }

        // ─── Tables annexes ───────────────────────────────────────
        $tables = [];

        $tables['glpi_plugin_printgestion_contractrates'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_contractrates` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `type_cout` enum('nb','color','both') NOT NULL DEFAULT 'both',
                `rate` decimal(10,6) NOT NULL DEFAULT '0.000000',
                `actif` tinyint NOT NULL DEFAULT '1',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `contracts_id` (`contracts_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        $tables['glpi_plugin_printgestion_toner_readings'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_toner_readings` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `printers_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `property_name` varchar(255) NOT NULL,
                `level_percent` int NOT NULL DEFAULT '0',
                `reading_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `printers_id` (`printers_id`),
                KEY `reading_date` (`reading_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        $tables['glpi_plugin_printgestion_cartridge_history'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_cartridge_history` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `printers_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `cartridgeitems_id` int {$default_key_sign} DEFAULT NULL,
                `cartridgetypes_id` int {$default_key_sign} DEFAULT NULL,
                `toner_property` varchar(255) DEFAULT NULL,
                `toner_color` enum('black','cyan','magenta','yellow','other') NOT NULL DEFAULT 'black',
                `level_at_install` int DEFAULT NULL,
                `level_at_removal` int DEFAULT NULL,
                `date_install` timestamp NULL DEFAULT NULL,
                `date_removal` timestamp NULL DEFAULT NULL,
                `pages_printed` int DEFAULT NULL,
                `printer_counter_at_install` int DEFAULT NULL,
                `printer_counter_at_removal` int DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `printers_id` (`printers_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        $tables['glpi_plugin_printgestion_expeditions'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_expeditions` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `printers_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `toner_property` varchar(255) DEFAULT NULL,
                `toner_color` enum('black','cyan','magenta','yellow','other') DEFAULT NULL,
                `statut` enum('pending','shipped','transit','delivered','stock_empty') NOT NULL DEFAULT 'pending',
                `transport_number` varchar(255) DEFAULT NULL,
                `transport_carrier` enum('ups','gls','chronopost','other') DEFAULT NULL,
                `bl_surveys_id` int {$default_key_sign} DEFAULT NULL,
                `users_id_tech` int {$default_key_sign} DEFAULT NULL,
                `users_id_planif` int {$default_key_sign} DEFAULT NULL,
                `level_at_alert` int DEFAULT NULL,
                `estimated_days` int DEFAULT NULL,
                `date_alert` timestamp NULL DEFAULT NULL,
                `date_shipped` timestamp NULL DEFAULT NULL,
                `date_delivered` timestamp NULL DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `group_id` varchar(36) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `printers_id` (`printers_id`),
                KEY `bl_surveys_id` (`bl_surveys_id`),
                KEY `group_id` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        $tables['glpi_plugin_printgestion_snmp_mapping'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_snmp_mapping` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `snmp_property` varchar(255) NOT NULL,
                `cartridgeitemtypes_id` int {$default_key_sign} DEFAULT NULL,
                `toner_color` enum('black','cyan','magenta','yellow','other') NOT NULL DEFAULT 'black',
                `manufacturer` varchar(255) DEFAULT NULL COMMENT 'legacy, non utilisé',
                `cartridge_type` varchar(100) DEFAULT NULL COMMENT 'legacy, non utilisé',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_snmp_property` (`snmp_property`),
                KEY `cartridgeitemtypes_id` (`cartridgeitemtypes_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        $tables['glpi_plugin_printgestion_alerts'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_alerts` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `printers_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `toner_property` varchar(255) DEFAULT NULL,
                `level_percent` int DEFAULT NULL,
                `estimated_days` int DEFAULT NULL,
                `alert_type` enum('low_toner','stock_empty','no_install_reminder','contract_expiry','wrong_printer') NOT NULL,
                `date_alert` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `mail_sent` tinyint NOT NULL DEFAULT '0',
                `is_resolved` tinyint NOT NULL DEFAULT '0',
                `intended_printers_id` int {$default_key_sign} DEFAULT NULL,
                `detected_printers_id` int {$default_key_sign} DEFAULT NULL,
                `expeditions_id` int {$default_key_sign} DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `printers_id` (`printers_id`),
                KEY `is_resolved` (`is_resolved`),
                KEY `alert_type` (`alert_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        // Snooze d'alerte : permet de masquer temporairement une ligne du dashboard
        // pour un couple (imprimante, propriété SNMP) jusqu'à une date donnée.
        $tables['glpi_plugin_printgestion_alert_snoozes'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_alert_snoozes` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `printers_id` int {$default_key_sign} NOT NULL,
                `toner_property` varchar(255) NOT NULL,
                `snooze_until` timestamp NOT NULL,
                `users_id` int {$default_key_sign} DEFAULT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `printers_id` (`printers_id`),
                KEY `snooze_until` (`snooze_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        // Binding direct cartouche ↔ propriété SNMP (sans passer par un type intermédiaire).
        // Un même cartridgeitems_id peut être bindé à plusieurs propriétés SNMP
        // (ex: HP W2031X → "Toner Cyan" + "tonercyan" + "developercyan").
        $tables['glpi_plugin_printgestion_cartridge_snmp'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_cartridge_snmp` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `cartridgeitems_id` int {$default_key_sign} NOT NULL,
                `snmp_property` varchar(255) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_binding` (`cartridgeitems_id`, `snmp_property`),
                KEY `snmp_property` (`snmp_property`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        $tables['glpi_plugin_printgestion_billing'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_billing` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `printers_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `contracts_id` int {$default_key_sign} DEFAULT NULL,
                `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `period_start` date NOT NULL,
                `period_end` date NOT NULL,
                `pages_nb` int NOT NULL DEFAULT '0',
                `pages_color` int NOT NULL DEFAULT '0',
                `rate_nb` decimal(10,6) NOT NULL DEFAULT '0.000000',
                `rate_color` decimal(10,6) NOT NULL DEFAULT '0.000000',
                `total_cost` decimal(10,2) NOT NULL DEFAULT '0.00',
                `generated_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `printers_id` (`printers_id`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        // Yields mesurés par cycle cartouche : dès qu'une cartouche est détectée comme
        // changée, on fige son yield réel (pages/1%) dans cette table. Le prochain
        // cycle de la même imprimante+property démarre avec ce yield au lieu du défaut.
        $tables['glpi_plugin_printgestion_historical_yields'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_historical_yields` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `printers_id` int {$default_key_sign} NOT NULL,
                `property_name` varchar(255) NOT NULL,
                `yield_per_percent` decimal(10,2) NOT NULL DEFAULT '0.00',
                `cycle_pages` int NOT NULL DEFAULT '0',
                `cycle_start_date` timestamp NULL DEFAULT NULL,
                `cycle_end_date` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `lookup` (`printers_id`, `property_name`, `date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        // Liaison N:N expéditions ↔ BL (plugin Gestion). Permet d'associer
        // plusieurs BL à une même expédition. L'ancienne colonne
        // expeditions.bl_surveys_id reste pour rétro-compat (1 BL principal).
        $tables['glpi_plugin_printgestion_expedition_bls'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_expedition_bls` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `expeditions_id` int {$default_key_sign} NOT NULL,
                `bl_surveys_id` int {$default_key_sign} NOT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_exp_bl` (`expeditions_id`, `bl_surveys_id`),
                KEY `expeditions_id` (`expeditions_id`),
                KEY `bl_surveys_id` (`bl_surveys_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        // Seuils d'alerte personnalisés par imprimante (override de la config globale)
        $tables['glpi_plugin_printgestion_printer_thresholds'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_printer_thresholds` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `printers_id` int {$default_key_sign} NOT NULL,
                `threshold_level` int DEFAULT NULL,
                `threshold_days` int DEFAULT NULL,
                `pages_per_cartridge` int DEFAULT NULL,
                `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `printers_id` (`printers_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        // ─── Tables MATÉRIALISÉES (tableaux Search natifs) ───────────────────
        $tables['glpi_plugin_printgestion_alertview'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_alertview` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `printers_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `toner_property` varchar(255) DEFAULT NULL,
                `toner_color` varchar(20) DEFAULT NULL,
                `level_percent` int NOT NULL DEFAULT '0',
                `days_remaining` int DEFAULT NULL,
                `status` enum('ok','watch','critical') NOT NULL DEFAULT 'ok',
                `cartridge_label` varchar(255) DEFAULT NULL,
                `has_expedition` tinyint NOT NULL DEFAULT '0',
                `is_snoozed` tinyint NOT NULL DEFAULT '0',
                `is_estimate` tinyint NOT NULL DEFAULT '0',
                `date_compute` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `printers_id` (`printers_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        $tables['glpi_plugin_printgestion_billing_view'] = "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_printgestion_billing_view` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `view_mode` enum('printer','client') NOT NULL DEFAULT 'printer',
                `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `printers_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `printer_name` varchar(255) DEFAULT NULL,
                `entity_name` varchar(255) DEFAULT NULL,
                `contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0',
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
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        foreach ($tables as $t => $sql) {
            if (!$DB->tableExists($t)) {
                $migration->displayMessage("Installing $t");
                $DB->doQuery($sql); // lève une exception en cas d'erreur SQL
            }
        }

        // Cleanup : drop des anciennes tables cache (plus utilisées).
        // ATTENTION : ne JAMAIS lister ici une table VIVANTE. Les tables
        // matérialisées ACTUELLES sont `_alertview` et `_billing_view` (créées
        // juste au-dessus) → elles ne doivent PAS figurer ici, sinon l'install
        // crée puis supprime aussitôt la table ⇒ « table doesn't exist » au runtime.
        foreach ([
            'glpi_plugin_printgestion_alerts_view',
            'glpi_plugin_printgestion_alertviews',
            'glpi_plugin_printgestion_billingviews',
        ] as $old_cache) {
            if ($DB->tableExists($old_cache)) {
                $DB->doQuery("DROP TABLE IF EXISTS `{$old_cache}`");
            }
        }

        // Migration alerts : nouveaux champs (wrong_printer + résolution)
        $alerts_table = 'glpi_plugin_printgestion_alerts';
        if ($DB->tableExists($alerts_table)) {
            foreach ([
                'is_resolved'          => "tinyint NOT NULL DEFAULT '0'",
                'intended_printers_id' => "int {$default_key_sign} DEFAULT NULL",
                'detected_printers_id' => "int {$default_key_sign} DEFAULT NULL",
                'expeditions_id'       => "int {$default_key_sign} DEFAULT NULL",
            ] as $col => $def) {
                if (!$DB->fieldExists($alerts_table, $col)) {
                    $migration->addField($alerts_table, $col, $def);
                }
            }
            // Élargir l'enum alert_type pour inclure 'wrong_printer' (redéfinition
            // identique sans effet si la colonne est déjà à jour).
            $migration->changeField(
                $alerts_table,
                'alert_type',
                'alert_type',
                "enum('low_toner','stock_empty','no_install_reminder','contract_expiry','wrong_printer') NOT NULL"
            );
            $migration->migrationOneTable($alerts_table);
        }

        // Migration toner_readings : ajout colonne total_pages + index composite + unique key daily
        $tr_table = 'glpi_plugin_printgestion_toner_readings';
        if ($DB->tableExists($tr_table)) {
            // Colonnes compteurs pages (total + split bw/color) au moment du relevé
            $tr_fields = [
                'total_pages' => "int NOT NULL DEFAULT '0'",
                'bw_pages'    => "int NOT NULL DEFAULT '0'",
                'color_pages' => "int NOT NULL DEFAULT '0'",
            ];
            $tr_changed = false;
            foreach ($tr_fields as $f => $def) {
                if (!$DB->fieldExists($tr_table, $f)) {
                    $migration->addField($tr_table, $f, $def);
                    $tr_changed = true;
                }
            }
            if ($tr_changed) {
                $migration->migrationOneTable($tr_table);
            }

            // Unique key : 1 relevé max par (imprimante, propriété, jour) → dedup natif
            $has_uniq = $DB->request([
                'FROM'  => 'information_schema.STATISTICS',
                'WHERE' => [
                    'TABLE_SCHEMA' => new \QueryExpression('DATABASE()'),
                    'TABLE_NAME'   => $tr_table,
                    'INDEX_NAME'   => 'uniq_daily',
                ],
            ])->count();
            if ($has_uniq === 0) {
                // Dédoublonnage préalable : garde le dernier reading par (printer, property, date).
                // Une erreur (conflit résiduel…) lève une exception : migration en échec, visible.
                $DB->doQuery("DELETE t1 FROM `{$tr_table}` t1
                    INNER JOIN `{$tr_table}` t2
                    WHERE t1.printers_id = t2.printers_id
                      AND t1.property_name = t2.property_name
                      AND DATE(t1.reading_date) = DATE(t2.reading_date)
                      AND t1.id < t2.id");
                $DB->doQuery("ALTER TABLE `{$tr_table}`
                    ADD UNIQUE KEY `uniq_daily` (`printers_id`, `property_name`, `reading_date`)");
            }

            // Index composite pour lookup rapide (getLatestLevel, getLevelNDaysAgo)
            $has_idx = $DB->request([
                'FROM'  => 'information_schema.STATISTICS',
                'WHERE' => [
                    'TABLE_SCHEMA' => new \QueryExpression('DATABASE()'),
                    'TABLE_NAME'   => $tr_table,
                    'INDEX_NAME'   => 'idx_lookup',
                ],
            ])->count();
            if ($has_idx === 0) {
                $DB->doQuery("ALTER TABLE `{$tr_table}`
                    ADD INDEX `idx_lookup` (`printers_id`, `property_name`, `reading_date`)");
            }
        }

        // Migration cron snapshot : passage horaire → journalier (optimisation scale)
        $DB->update('glpi_crontasks', [
            'frequency' => DAY_TIMESTAMP,
        ], [
            'itemtype' => 'PluginPrintgestionReminder',
            'name'     => 'PrintgestionSnapshotReadings',
            'frequency' => ['<', DAY_TIMESTAMP],
        ]);

        // Migration snmp_mapping : ajout cartridgeitemtypes_id + dédoublonnage sur snmp_property
        $snmp_table = 'glpi_plugin_printgestion_snmp_mapping';
        if ($DB->tableExists($snmp_table)) {
            if (!$DB->fieldExists($snmp_table, 'cartridgeitemtypes_id')) {
                $migration->addField(
                    $snmp_table,
                    'cartridgeitemtypes_id',
                    "int {$default_key_sign} DEFAULT NULL",
                    ['after' => 'cartridge_type']
                );
                $migration->addKey($snmp_table, 'cartridgeitemtypes_id');
                $migration->migrationOneTable($snmp_table);
            }

            // Dédoublonnage : supprime les doublons sur snmp_property en gardant la ligne
            // avec cartridgeitemtypes_id non null en priorité, sinon la plus ancienne.
            $duplicates = $DB->request([
                'SELECT' => ['snmp_property', new \QueryExpression('COUNT(*) AS cnt')],
                'FROM'   => $snmp_table,
                'GROUPBY'=> ['snmp_property'],
                'HAVING' => ['cnt' => ['>', 1]],
            ]);
            foreach ($duplicates as $dup) {
                $property = (string)$dup['snmp_property'];
                $rows = $DB->request([
                    'SELECT' => ['id', 'cartridgeitemtypes_id'],
                    'FROM'   => $snmp_table,
                    'WHERE'  => ['snmp_property' => $property],
                    'ORDER'  => [
                        new \QueryExpression('(cartridgeitemtypes_id IS NULL) ASC'),
                        'id ASC',
                    ],
                ]);
                $keep = null;
                foreach ($rows as $r) {
                    if ($keep === null) {
                        $keep = (int)$r['id'];
                        continue;
                    }
                    $DB->delete($snmp_table, ['id' => (int)$r['id']]);
                }
            }

            // Ajout unique index sur snmp_property si absent
            $indexes = $DB->request([
                'FROM'  => 'information_schema.STATISTICS',
                'WHERE' => [
                    'TABLE_SCHEMA' => new \QueryExpression('DATABASE()'),
                    'TABLE_NAME'   => $snmp_table,
                    'INDEX_NAME'   => 'uniq_snmp_property',
                ],
            ])->count();
            if ($indexes === 0) {
                // Doublon résiduel après dédoublonnage = exception : migration en échec, visible.
                $DB->doQuery("ALTER TABLE `{$snmp_table}` ADD UNIQUE KEY `uniq_snmp_property` (`snmp_property`)");
            }
        }

        // Migration expeditions : ajout group_id (envoi groupé multi-cartouches)
        $exp_table = 'glpi_plugin_printgestion_expeditions';
        if ($DB->tableExists($exp_table)) {
            if (!$DB->fieldExists($exp_table, 'group_id')) {
                $migration->addField($exp_table, 'group_id', "varchar(36) DEFAULT NULL", ['after' => 'notes']);
                $migration->addKey($exp_table, 'group_id');
                $migration->migrationOneTable($exp_table);
            }
        }

        return true;
    }

    static function uninstall(Migration $migration) {
        global $DB;
        $tables = [
            'glpi_plugin_printgestion_configs',
            'glpi_plugin_printgestion_contractrates',
            'glpi_plugin_printgestion_toner_readings',
            'glpi_plugin_printgestion_cartridge_history',
            'glpi_plugin_printgestion_expeditions',
            'glpi_plugin_printgestion_snmp_mapping',
            'glpi_plugin_printgestion_alerts',
            'glpi_plugin_printgestion_billing',
            'glpi_plugin_printgestion_cartridge_snmp',
            'glpi_plugin_printgestion_alert_snoozes',
            'glpi_plugin_printgestion_printer_thresholds',
            'glpi_plugin_printgestion_historical_yields',
            'glpi_plugin_printgestion_expedition_bls',
            'glpi_plugin_printgestion_table_prefs', // legacy
            // Anciennes tables cache (maintenant abandonnées) — drop si existent
            'glpi_plugin_printgestion_alertviews',
            'glpi_plugin_printgestion_billingviews',
            'glpi_plugin_printgestion_alerts_view',
            'glpi_plugin_printgestion_billing_view',
        ];
        foreach ($tables as $t) {
            // DROP IF EXISTS direct : idempotent et immunisé contre le cache de
            // tableExists(). Certaines tables matérialisées (alertview, billing_view)
            // sont aussi supprimées par leur propre classe ::uninstall() juste avant ;
            // un `Migration::dropTable()` (sans IF EXISTS) lèverait alors « Unknown
            // table » (1051) et interromprait toute la désinstallation.
            $DB->doQuery('DROP TABLE IF EXISTS `' . $t . '`');
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────
    //  FORMULAIRE DE CONFIGURATION
    // ─────────────────────────────────────────────────────────────

    static function showConfigForm() {
        if (!Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        $canedit = Session::haveRight(self::$rightname, UPDATE);
        $config  = self::getInstance();

        // Pattern plugin Gestion : showFormHeader() ouvre un <form> avec
        // action=$this->getFormURL() (→ plugins/printgestion/front/config.form.php)
        // ET inclut automatiquement _glpi_csrf_token. On referme ensuite la ligne
        // de table ouverte par showFormHeader pour rendre nos cards à la place.
        $config->showFormHeader(['colspan' => 4]);
        echo '</td></tr></table>';

        // ── Seuils ────────────────────────────────────────────────
        // Helper : rend un label avec icône d'info et tooltip
        $label_with_tip = function (string $label, string $tip): string {
            $tip_esc = htmlspecialchars($tip, ENT_QUOTES, 'UTF-8');
            return "<label class='form-label'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . " <i class='fa-solid fa-circle-info text-muted ms-1' data-bs-toggle='tooltip'"
                . " title=\"{$tip_esc}\"></i></label>";
        };

        // ── Activation des modules (interrupteurs de features) ────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Activation des modules', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted mb-3'>"
            . __("Désactivez les modules non utilisés : leur menu, leurs onglets et leurs tâches planifiées ne seront pas chargés (aucune ressource consommée).", 'printgestion')
            . "</p>";
        echo "<div class='row g-3'>";
        $feature_toggle = function (string $feature, string $label) use ($config) {
            $on = (int) ($config->fields['enable_' . $feature] ?? 1) === 1;
            $id = 'enable_' . $feature;
            echo "<div class='col-md-4'><div class='form-check form-switch'>";
            echo "<input type='hidden' name='" . $id . "' value='0'>";
            echo "<input type='checkbox' class='form-check-input' id='" . $id . "' name='" . $id . "' value='1'"
                . ($on ? ' checked' : '') . ">";
            echo "<label class='form-check-label' for='" . $id . "'>"
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</label>";
            echo "</div></div>";
        };
        $feature_toggle('contrats', __('Gestion contractuelle', 'printgestion'));
        $feature_toggle('toner', __('Gestion toner & expéditions', 'printgestion'));
        $feature_toggle('cout', __('Coût à la page', 'printgestion'));
        echo "</div></div></div>";

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __("Seuils d'alerte", 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<div class='row g-3'>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Seuil jours estimés', 'printgestion'),
                __("Nombre de jours restants estimés en-dessous duquel un toner passe en statut « À surveiller ». Utilisé par le dashboard Alertes pour classer les cartouches qui vont bientôt devoir être remplacées.", 'printgestion')
            );
        echo "<input type='number' min='0' class='form-control' name='threshold_days' value='"
            . (int)($config->fields['threshold_days'] ?? 30) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Seuil niveau min (%)', 'printgestion'),
                __("Pourcentage de toner en-dessous duquel une cartouche passe en statut « Critique » (alerte immédiate, envoi mail aux commerciaux pour information client). Indépendant du nombre de jours restants.", 'printgestion')
            );
        echo "<input type='number' min='0' max='100' class='form-control' name='threshold_level' value='"
            . (int)($config->fields['threshold_level'] ?? 15) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Délai rappel (jours)', 'printgestion'),
                __("Nombre de jours après l'envoi d'une cartouche avant de déclencher un rappel automatique (mail + alerte dashboard) si l'installation n'a pas été détectée. Permet d'éviter les cartouches expédiées mais jamais posées.", 'printgestion')
            );
        echo "<input type='number' min='0' class='form-control' name='reminder_days' value='"
            . (int)($config->fields['reminder_days'] ?? 7) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Delta détection cartouche (%)', 'printgestion'),
                __("Hausse minimale de niveau toner (en %) entre deux relevés SNMP pour considérer qu'une cartouche a été physiquement remplacée. Ex: 20 signifie qu'une hausse de +20 points (ex: 5% → 80%) déclenche la détection.", 'printgestion')
            );
        echo "<input type='number' min='1' max='100' class='form-control' name='detection_delta' value='"
            . (int)($config->fields['detection_delta'] ?? 20) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __("Fenêtre détection mauvaise imprimante (jours)", 'printgestion'),
                __("Pose détectée sur une imprimante qui n'attendait aucun envoi : le plugin cherche dans les N jours précédents un envoi déjà parti (expédié, en transit ou livré) pour une autre imprimante du même site, même référence de cartouche. Il le signale alors en « mauvaise imprimante » sur l'écran Expéditions, à vérifier et confirmer : aucune réattribution automatique.", 'printgestion')
            );
        echo "<input type='number' min='1' class='form-control' name='wrong_printer_lookback_days' value='"
            . (int)($config->fields['wrong_printer_lookback_days'] ?? 30) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Rendement par défaut (pages/cartouche)', 'printgestion'),
                __("Yield par défaut en pages imprimables par cartouche. Utilisé pour estimer les jours restants quand l'historique de consommation du toner est insuffisant pour mesurer le yield réel. Un yield réel est calculé automatiquement dès qu'une baisse ≥ 3 points est observée.", 'printgestion')
            );
        echo "<input type='number' min='100' class='form-control' name='default_pages_per_cartridge' value='"
            . (int)($config->fields['default_pages_per_cartridge'] ?? 5000) . "'></div>";

        echo "<div class='col-md-3'>"
            . $label_with_tip(
                __('Imprimante muette après (jours)', 'printgestion'),
                __("Sans inventaire depuis ce nombre de jours, une imprimante (ou l'agent qui l'inventorie) est signalée muette dans l'écran « Contrôle de la remontée » : elle ne peut plus déclencher d'alerte toner.", 'printgestion')
            );
        echo "<input type='number' min='1' max='365' class='form-control' name='silent_days' value='"
            . PluginPrintgestionCollect::getSilentDays() . "'></div>";

        echo "</div></div></div>";

        // ── Anti-double-envoi ─────────────────────────────────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Anti-double-envoi', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Un envoi en cours (commandé, expédié, livré mais non posé) bloque toujours une nouvelle commande pour la même machine et le même toner, sans limite de durée. Les réglages ci-dessous ajoutent deux verrous temporaires, contournables en cas de consommation anormale.", 'printgestion')
            . "</p>";
        echo "<div class='row g-3'>";

        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Garde après pose (jours)', 'printgestion'),
                __("Après une pose détectée ou confirmée sur une machine et un toner, aucune nouvelle commande n'est proposée pendant ce nombre de jours — y compris quand la cartouche a été posée sur une autre machine que prévu. Protège contre une fausse détection ou un niveau qui oscille. 0 = garde désactivée.", 'printgestion')
            );
        echo "<input type='number' min='0' max='365' class='form-control' name='guard_days' value='"
            . (int)($config->fields['guard_days'] ?? 5) . "'></div>";

        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Seuil de contournement (%)', 'printgestion'),
                __("Pendant la garde ou malgré un ticket récent, une commande reste possible si le niveau mesuré du toner est inférieur ou égal à ce seuil (consommation anormale). Ne s'applique jamais à un envoi en cours non posé.", 'printgestion')
            );
        echo "<input type='number' min='0' max='100' class='form-control' name='guard_bypass_level' value='"
            . (int)($config->fields['guard_bypass_level'] ?? 10) . "'></div>";

        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Ticket récent (jours)', 'printgestion'),
                __("Aucune nouvelle commande si un ticket non résolu lié à la machine a été ouvert il y a moins de ce nombre de jours. 0 = verrou désactivé.", 'printgestion')
            );
        echo "<input type='number' min='0' max='365' class='form-control' name='guard_ticket_days' value='"
            . (int)($config->fields['guard_ticket_days'] ?? 10) . "'></div>";

        echo "</div></div></div>";

        // ── Contrats : consommables inclus (sous contrat / hors contrat) ──
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Contrats — consommables inclus', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Une ligne de commande est « sous contrat » (prix 0) si l'imprimante est liée à un contrat en cours dont le type figure ci-dessous. Sinon elle est « hors contrat » : le prix reste vide, jamais 0. Contrat en cours : date de début atteinte, puis reconduction tacite ou date de fin (début + durée) postérieure à aujourd'hui ; si plusieurs contrats sont en cours, le plus récemment commencé est retenu.", 'printgestion')
            . "</p>";
        echo "<div class='row g-3'><div class='col-md-8'>"
            . $label_with_tip(
                __('Types de contrat « consommables inclus »', 'printgestion'),
                __("Types de contrat GLPI (Configuration → Intitulés → Types de contrat) pour lesquels les consommables sont fournis sans facturation. Aucun type sélectionné : toutes les lignes sont hors contrat.", 'printgestion')
            );
        Dropdown::show('ContractType', [
            'name'     => 'consumables_contracttypes',
            'multiple' => true,
            'value'    => self::getConsumablesContractTypes(),
            'width'    => '100%',
        ]);
        echo "</div></div>";
        echo "</div></div>";

        // ── Fichier Gesconso (commande aux Achats) ───────────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Fichier Gesconso (commande aux Achats)', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Colonne Designation : n° de série, lieu et libellé de la cartouche, séparés par le séparateur ci-dessous (espaces compris). Au-delà de la longueur maximale, la désignation est tronquée et un avertissement est affiché.", 'printgestion')
            . "</p>";
        echo "<div class='row g-3'>";
        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Séparateur de la désignation', 'printgestion'),
                __("Espaces compris. Le fichier réel importé dans Gesconso utilise « # » entouré d'un espace de chaque côté.", 'printgestion')
            );
        echo "<input type='text' class='form-control' name='gesconso_separator' maxlength='20' value='"
            . htmlspecialchars(PluginPrintgestionGesconso::getSeparator(), ENT_QUOTES, 'UTF-8') . "'></div>";
        echo "<div class='col-md-4'>"
            . $label_with_tip(
                __('Longueur maximale de la désignation', 'printgestion'),
                __("69 par défaut (limite usuelle de Sage ; 67 caractères ont été importés avec succès).", 'printgestion')
            );
        echo "<input type='number' min='10' max='255' class='form-control' name='gesconso_designation_max' value='"
            . PluginPrintgestionGesconso::getDesignationMax() . "'></div>";
        echo "</div></div></div>";

        // ── Lecture des niveaux SNMP : règles par constructeur ────
        PluginPrintgestionSnmprule::showConfigCard();

        // ── Notifications natives des demandes d'envoi ───────────
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Notifications natives des demandes d\'envoi', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Événements GLPI « Demande d'envoi proposée », « en attente (relance) » et « exportée vers les Achats » (Configuration → Notifications, type Demande d'envoi) : créés inactifs, à activer après avoir choisi les destinataires (profil ou groupe des valideurs, Achats…).", 'printgestion')
            . " <a href='" . htmlspecialchars(Notification::getSearchURL(), ENT_QUOTES, 'UTF-8') . "'>" . __('Ouvrir les notifications', 'printgestion') . "</a></p>";
        echo "<div class='row g-3'><div class='col-md-4'>"
            . $label_with_tip(
                __('Relance d\'une demande en attente (jours)', 'printgestion'),
                __("Une demande proposée non validée, ou validée non exportée, depuis ce nombre de jours déclenche l'événement de relance, au plus une fois par période. 0 = relance désactivée.", 'printgestion')
            );
        echo "<input type='number' min='0' max='90' class='form-control' name='demande_reminder_days' value='"
            . (int)($config->fields['demande_reminder_days'] ?? 2) . "'></div></div>";
        echo "</div></div>";

        // ── Alertes de contrat natives GLPI ──────────────────────
        PluginPrintgestionContractalert::showConfigCard();

        // Active les tooltips Bootstrap sur les icônes d'info
        echo "<script>
(function() {
  if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle=\"tooltip\"]').forEach(function(el) {
      new bootstrap.Tooltip(el);
    });
  }
})();
</script>";

        // ── Dashboard Coût à la page : filtres d'affichage ────────
        $req_contract = (int)($config->fields['billing_require_contract'] ?? 1);
        $req_counter  = (int)($config->fields['billing_require_counter']  ?? 1);
        $req_activity = (int)($config->fields['billing_require_activity'] ?? 1);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Dashboard Coût à la page — Filtres', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __("Par défaut, seules les imprimantes avec un contrat, des compteurs de page ET une activité "
                . "(pages N&B ou couleur > 0 sur la période) s'affichent. Décoche une option pour élargir.", 'printgestion')
            . "</p>";
        echo "<div class='row g-3'>";
        echo "<div class='col-md-4'><div class='form-check form-switch'>";
        echo "<input type='hidden' name='billing_require_contract' value='0'>";
        echo "<input type='checkbox' class='form-check-input' id='billing_require_contract' "
            . "name='billing_require_contract' value='1'" . ($req_contract === 1 ? ' checked' : '') . ">";
        echo "<label class='form-check-label' for='billing_require_contract'>"
            . __('N\'afficher que les imprimantes liées à un contrat', 'printgestion') . "</label>";
        echo "</div></div>";
        echo "<div class='col-md-4'><div class='form-check form-switch'>";
        echo "<input type='hidden' name='billing_require_counter' value='0'>";
        echo "<input type='checkbox' class='form-check-input' id='billing_require_counter' "
            . "name='billing_require_counter' value='1'" . ($req_counter === 1 ? ' checked' : '') . ">";
        echo "<label class='form-check-label' for='billing_require_counter'>"
            . __('N\'afficher que les imprimantes ayant au moins un compteur de page', 'printgestion') . "</label>";
        echo "</div></div>";
        echo "<div class='col-md-4'><div class='form-check form-switch'>";
        echo "<input type='hidden' name='billing_require_activity' value='0'>";
        echo "<input type='checkbox' class='form-check-input' id='billing_require_activity' "
            . "name='billing_require_activity' value='1'" . ($req_activity === 1 ? ' checked' : '') . ">";
        echo "<label class='form-check-label' for='billing_require_activity'>"
            . __('N\'afficher que les imprimantes avec au moins 1 page imprimée (N&B ou Couleur) sur la période', 'printgestion') . "</label>";
        echo "</div></div>";
        echo "</div></div></div>";

        // ── Card unique : Rôles & notifications ───────────────────
        $roles = [
            'planif'     => [
                'label'   => __('Planification (expédition cartouche)', 'printgestion'),
                'gabarit' => 'gabarit_planif',
            ],
            'achat'      => [
                'label'   => __('Achats (stock vide)', 'printgestion'),
                'gabarit' => 'gabarit_achat',
            ],
            'commercial' => [
                'label'   => __('Commercial (information client)', 'printgestion'),
                'gabarit' => 'gabarit_commercial',
            ],
        ];

        echo "<div class='card mb-3'><div class='card-header d-flex justify-content-between align-items-center'>"
            . "<h3 class='card-title mb-0'>" . __('Rôles & notifications', 'printgestion') . "</h3>"
            . "<button type='button' class='btn btn-sm btn-outline-primary' data-bs-toggle='modal' data-bs-target='#pg-notif-modal'>"
            . "<i class='fa-solid fa-eye me-1'></i>" . __('Qui est notifié ?', 'printgestion') . "</button>"
            . "</div><div class='card-body'>";

        echo "<div class='alert alert-info py-2 mb-3'>"
            . "<i class='fa-solid fa-circle-info me-1'></i>"
            . __("L'accès aux dashboards Print Gestion est géré via les droits de profil GLPI "
                . "(Administration → Profils → Print Gestion). Cette section configure uniquement "
                . "où sont envoyées les notifications email.", 'printgestion')
            . "</div>";

        // 3 rôles avec notifications (switch groupe/emails + gabarit)
        foreach ($roles as $role => $cfg) {
            $mode        = (string)($config->fields['mode_' . $role] ?? 'group');
            $group_value = (int)($config->fields['group_' . $role] ?? 0);
            // NB: la colonne emails_X stocke désormais une liste CSV d'IDs users GLPI
            $users_raw   = (string)($config->fields['emails_' . $role] ?? '');
            $users_ids   = array_values(array_filter(
                array_map('intval', preg_split('/[,;\s]+/', $users_raw) ?: []),
                fn($id) => $id > 0
            ));

            $use_users = ($mode === 'emails'); // 'emails' = legacy name, signifie "users directs"
            $group_div = "printgestion-{$role}-group";
            $users_div = "printgestion-{$role}-users";
            $switch_id = "printgestion-{$role}-switch";

            echo "<div class='mb-4 pb-3 border-bottom'>";
            echo "<h5 class='mb-2'>" . htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8') . "</h5>";

            // Gabarit
            echo "<div class='row mb-3 align-items-center'><div class='col-md-4'><label class='form-label mb-0'>"
                . __('Gabarit de notification', 'printgestion') . "</label></div><div class='col-md-8'>";
            Dropdown::show('NotificationTemplate', [
                'name'                => $cfg['gabarit'],
                'value'               => (int)($config->fields[$cfg['gabarit']] ?? 0),
                'display_emptychoice' => true,
                'emptylabel'          => '-----',
            ]);
            echo "</div></div>";

            // Destinataires : label + switch + panneau dynamique
            echo "<div class='row align-items-start'><div class='col-md-4'><label class='form-label mb-0'>"
                . __('Destinataires', 'printgestion') . "</label></div><div class='col-md-8'>";

            echo "<div class='form-check form-switch mb-2'>";
            echo "<input type='hidden' name='mode_{$role}' value='" . ($use_users ? 'emails' : 'group') . "' id='mode_{$role}_hidden'>";
            echo "<input class='form-check-input' type='checkbox' role='switch' id='{$switch_id}'"
                . ($use_users ? ' checked' : '')
                . " onchange=\"printgestionToggleMode('{$role}', this.checked)\">";
            echo "<label class='form-check-label' for='{$switch_id}'>"
                . __('Utiliser des utilisateurs directs (sinon : groupe GLPI)', 'printgestion') . "</label>";
            echo "</div>";

            // Panneau : groupe GLPI
            echo "<div id='{$group_div}' style='display:" . ($use_users ? 'none' : 'block') . "'>";
            Group::dropdown([
                'name'                => 'group_' . $role,
                'value'               => $group_value,
                'display_emptychoice' => true,
                'emptylabel'          => '-----',
            ]);
            echo "<small class='text-muted d-block mt-1'>"
                . __('Les emails par défaut de tous les utilisateurs du groupe seront utilisés.', 'printgestion')
                . "</small>";
            echo "</div>";

            // Panneau : users GLPI directs (User::dropdown multiple — select2 natif)
            // NB: en mode multiple, User::dropdown attend le tableau d'IDs dans `value`
            // (singulier), pas `values` — écrase `values` avec `value ?? []` ligne 4261.
            echo "<div id='{$users_div}' style='display:" . ($use_users ? 'block' : 'none') . "'>";
            User::dropdown([
                'name'                => 'emails_' . $role,
                'value'               => $users_ids,
                'multiple'            => true,
                'right'               => 'all',
                'display_emptychoice' => false,
                'width'               => '100%',
            ]);
            echo "<small class='text-muted d-block mt-1'>"
                . __('Choisir un ou plusieurs utilisateurs GLPI. Leur email par défaut sera utilisé.', 'printgestion')
                . "</small>";
            echo "</div>";

            echo "</div></div>"; // row
            echo "</div>"; // rôle
        }

        // Rappel installation : gabarit + destinataires (planif / commercial / les deux)
        echo "<div class='mb-4 pb-3 border-bottom'>";
        echo "<h5 class='mb-2'>" . __('Rappel installation', 'printgestion') . "</h5>";
        echo "<div class='row mb-3 align-items-center'><div class='col-md-4'><label class='form-label mb-0'>"
            . __('Gabarit si cartouche non installée après délai', 'printgestion') . "</label></div><div class='col-md-8'>";
        Dropdown::show('NotificationTemplate', [
            'name'                => 'gabarit_rappel',
            'value'               => (int)($config->fields['gabarit_rappel'] ?? 0),
            'display_emptychoice' => true,
            'emptylabel'          => '-----',
        ]);
        echo "</div></div>";
        echo "<div class='row align-items-center'><div class='col-md-4'><label class='form-label mb-0'>"
            . __('Destinataires du rappel', 'printgestion') . "</label></div><div class='col-md-8'>";
        Dropdown::showFromArray('reminder_recipients', [
            'planif'     => __('Planification seule', 'printgestion'),
            'commercial' => __('Commercial seul', 'printgestion'),
            'both'       => __('Planification + Commercial', 'printgestion'),
        ], ['value' => (string)($config->fields['reminder_recipients'] ?? 'both')]);
        echo "<small class='text-muted d-block mt-1'>"
            . __('Évite que les commerciaux relancent une demande déjà traitée.', 'printgestion')
            . "</small>";
        echo "</div></div>";
        echo "</div>";

        // Courtoisie client : gabarit (destinataire = usager imprimante, sinon entité)
        echo "<div>";
        echo "<h5 class='mb-2'>" . __('Courtoisie client', 'printgestion') . "</h5>";
        echo "<div class='row align-items-center'><div class='col-md-4'><label class='form-label mb-0'>"
            . __('Gabarit envoyé au client lors d\'un envoi de cartouche', 'printgestion') . "</label></div><div class='col-md-8'>";
        Dropdown::show('NotificationTemplate', [
            'name'                => 'gabarit_courtoisie',
            'value'               => (int)($config->fields['gabarit_courtoisie'] ?? 0),
            'display_emptychoice' => true,
            'emptylabel'          => '-----',
        ]);
        echo "<small class='text-muted d-block mt-1'>"
            . __('Destinataire : uniquement l\'usager renseigné sur la fiche imprimante. Sans usager, aucun mail n\'est envoyé pour cette imprimante.', 'printgestion')
            . "</small>";
        echo "</div></div>";
        echo "</div>";

        echo "</div></div>"; // fin card Rôles & notifications

        // ── Modale « Qui est notifié ? » : VUE PAR DESTINATAIRE ─────────────────
        // Pour chaque rôle, on liste TOUTES les notifications qu'il reçoit selon la
        // config (ex : Commercial = info toner bas + rappel SI la cible rappel l'inclut),
        // en signalant les gabarits non configurés (notification inactive).
        $resolveTxt = function (string $role): string {
            $emails = PluginPrintgestionAlert::resolveRecipientsForRole($role);
            return empty($emails)
                ? "<span class='text-danger'>" . __('aucun destinataire', 'printgestion') . "</span>"
                : "<span class='text-success'>" . htmlspecialchars(implode(', ', $emails), ENT_QUOTES, 'UTF-8') . "</span>";
        };
        $rmode = (string)($config->fields['reminder_recipients'] ?? 'both');
        $rappel_to_planif     = in_array($rmode, ['planif', 'both'], true);
        $rappel_to_commercial = in_array($rmode, ['commercial', 'both'], true);
        $gabOk = function (string $field) use ($config): bool {
            return (int)($config->fields[$field] ?? 0) > 0;
        };
        // Rend une liste <ul> de notifications. Chaque item : [texte, gabarit_actif?].
        $notifList = function (array $items): string {
            if (empty($items)) {
                return "<span class='text-muted'>—</span>";
            }
            $html = '<ul style="margin:0;padding-left:18px;">';
            foreach ($items as $it) {
                $warn = $it[1] ? '' : " <span style='color:#b91c1c;'>(" . __('gabarit non configuré', 'printgestion') . ")</span>";
                $html .= '<li>' . htmlspecialchars($it[0], ENT_QUOTES, 'UTF-8') . $warn . '</li>';
            }
            return $html . '</ul>';
        };

        $recipients = [
            [
                __('Commercial', 'printgestion'),
                $notifList(array_merge(
                    [[__('Information toner bas — cron horaire, 1 seul mail digest par run', 'printgestion'), $gabOk('gabarit_commercial')]],
                    $rappel_to_commercial ? [[__('Rappel cartouche non installée — cron, 1 seul mail digest par run', 'printgestion'), $gabOk('gabarit_rappel')]] : []
                )),
                $resolveTxt('commercial'),
            ],
            [
                __('Planification', 'printgestion'),
                $notifList(array_merge(
                    [[__('Expédition cartouche (simple : gabarit unitaire / multi : gabarit groupé avec client par ligne) — « Envoyer cartouche » si la case Planif est cochée', 'printgestion'), $gabOk('gabarit_planif')]],
                    $rappel_to_planif ? [[__('Rappel cartouche non installée — cron, 1 seul mail digest par run', 'printgestion'), $gabOk('gabarit_rappel')]] : []
                )),
                $resolveTxt('planif'),
            ],
            [
                __('Achat', 'printgestion'),
                $notifList([[__('Commande cartouche — fichier Excel joint (détail dans l\'Excel, plus de tableau dans le mail) — dès qu\'une cartouche est à commander', 'printgestion'), true]]),
                $resolveTxt('achat'),
            ],
            [
                __('Client (courtoisie)', 'printgestion'),
                $notifList([[__('Cartouche(s) en cours d\'envoi — regroupé par contact (1 mail listant ses imprimantes) — si la case Courtoisie est cochée', 'printgestion'), $gabOk('gabarit_courtoisie')]]),
                "<em>" . __('Usager renseigné sur la fiche imprimante (aucun envoi sans usager) — varie par imprimante', 'printgestion') . "</em>",
            ],
            [
                __('Demandeur (en copie)', 'printgestion'),
                $notifList([[__('En copie (CC) des mails Achat et Planif', 'printgestion'), true]]),
                "<em>" . __('L\'utilisateur qui déclenche l\'envoi', 'printgestion') . "</em>",
            ],
        ];

        echo "<div class='modal fade' id='pg-notif-modal' tabindex='-1'><div class='modal-dialog modal-lg modal-dialog-scrollable'><div class='modal-content'>";
        echo "<div class='modal-header'><h5 class='modal-title'><i class='fa-solid fa-bell me-2'></i>"
            . __('Qui est notifié ?', 'printgestion') . "</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button></div>";
        echo "<div class='modal-body'><p class='text-muted small'>"
            . __('Pour chaque destinataire, les notifications qu\'il reçoit selon la configuration ENREGISTRÉE (enregistre avant de vérifier).', 'printgestion')
            . "</p>";
        echo "<table class='table table-sm align-middle'><thead><tr>"
            . "<th>" . __('Destinataire', 'printgestion') . "</th>"
            . "<th>" . __('Notifications reçues', 'printgestion') . "</th>"
            . "<th>" . __('Emails résolus', 'printgestion') . "</th></tr></thead><tbody>";
        foreach ($recipients as $r) {
            echo "<tr><td><strong>" . htmlspecialchars($r[0], ENT_QUOTES, 'UTF-8') . "</strong></td>"
                . "<td class='small'>" . $r[1] . "</td>"
                . "<td class='small'>" . $r[2] . "</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "<div class='modal-footer'><button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>"
            . __('Fermer', 'printgestion') . "</button></div>";
        echo "</div></div></div>";

        // ── Card : Mapping SNMP → Cartouches GLPI ─────────────────
        self::showSnmpMappingCard();

        // ── JS : toggle switch entre panneau groupe / panneau users ─
        echo <<<'HTML'
<script>
function printgestionToggleMode(role, useUsers) {
    var g = document.getElementById('printgestion-' + role + '-group');
    var u = document.getElementById('printgestion-' + role + '-users');
    var h = document.getElementById('mode_' + role + '_hidden');
    if (g) g.style.display = useUsers ? 'none' : 'block';
    if (u) u.style.display = useUsers ? 'block' : 'none';
    if (h) h.value = useUsers ? 'emails' : 'group';
}
</script>
HTML;

        // ── Transporteurs / API ───────────────────────────────────
        // Clés chiffrées (GLPIKey) et jamais réaffichées, même partiellement.
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . __('Transporteurs (clés API)', 'printgestion') . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>"
            . __('Les clés sont enregistrées chiffrées et ne sont jamais réaffichées. Un champ laissé vide conserve la clé déjà enregistrée.', 'printgestion')
            . "</p>";
        foreach ([
            'api_ups'        => 'UPS',
            'api_gls'        => 'GLS',
            'api_chronopost' => 'Chronopost',
        ] as $field => $label) {
            $is_set = (string)($config->fields[$field] ?? '') !== '';
            echo "<div class='row mb-2 align-items-center'><div class='col-md-4'>"
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</div><div class='col-md-5'>";
            echo "<input type='password' class='form-control' name='{$field}' value='' autocomplete='new-password' placeholder='"
                . htmlspecialchars(
                    $is_set
                        ? __('Clé enregistrée — saisir pour la remplacer', 'printgestion')
                        : __('Aucune clé enregistrée', 'printgestion'),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . "'>";
            echo "</div><div class='col-md-3'>";
            if ($is_set) {
                echo "<div class='form-check'>"
                    . "<input type='checkbox' class='form-check-input' name='clear_{$field}' value='1' id='clear_{$field}'>"
                    . "<label class='form-check-label' for='clear_{$field}'>"
                    . __('Effacer la clé', 'printgestion') . "</label></div>";
            }
            echo "</div></div>";
        }
        echo "<div class='row mb-2 align-items-center'><div class='col-md-4'>"
            . __('Fréquence tracking (heures)', 'printgestion') . "</div><div class='col-md-6'>";
        echo "<input type='number' min='1' class='form-control' name='tracking_frequency' value='"
            . (int)($config->fields['tracking_frequency'] ?? 4) . "'>";
        echo "</div></div>";
        echo "</div></div>";

        // ── Intégrations (visible UNIQUEMENT si plugin Gestion installé et actif) ──
        $gestion_active = false;
        try {
            $plugin = new Plugin();
            $gestion_active = $plugin->isInstalled('gestion') && $plugin->isActivated('gestion');
        } catch (Throwable $e) {
            // Section « Intégrations » masquée pour cet affichage, cause tracée.
            PluginPrintgestionLogger::error(
                'Config::showConfigForm',
                "Lecture de l'état du plugin Gestion impossible : section Intégrations masquée.",
                $e
            );
        }

        if ($gestion_active) {
            echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
                . __('Intégrations', 'printgestion') . "</h3></div><div class='card-body'>";
            echo "<div class='row mb-2 align-items-center'><div class='col-md-4'>"
                . __('Activer lien plugin Gestion (BL signé → livraison)', 'printgestion') . "</div><div class='col-md-6'>";
            Dropdown::showYesNo('plugin_gestion_enabled', (int)($config->fields['plugin_gestion_enabled'] ?? 0));
            echo "</div></div>";
            echo "</div></div>";
        }

        // Rouvrir la table/tr/td attendue par showFormButtons avant de fermer
        echo '<table><tr><td>';
        $config->showFormButtons(['candel' => false]);
        return true;
    }

    /**
     * Carte éditable "Mapping SNMP → Cartouches GLPI" — édition inline.
     *
     * Une ligne par propriété SNMP unique (pas de doublon constructeur).
     * Chaque ligne est un mini-formulaire avec dropdown type + couleur + Save + Delete.
     * Ligne d'ajout en bas du tableau.
     */
    protected static function showSnmpMappingCard(): void {
        global $DB;

        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'>"
            . __('Mapping SNMP → Cartouches GLPI (fallback)', 'printgestion') . "</h3></div>";
        echo "<div class='card-body'>";

        // Bandeau info TOUJOURS visible
        echo "<div class='alert alert-info py-2 mb-2'>"
            . "<i class='fa-solid fa-lightbulb me-1'></i>"
            . __("<strong>Méthode recommandée</strong> : associe directement les propriétés SNMP "
                . "depuis la fiche de chaque cartouche (onglet <strong>Print Gestion</strong> après avoir "
                . "déclaré les modèles d'imprimantes compatibles). Plus simple et sans créer de types. "
                . "Cette section ici reste utile uniquement comme fallback basé sur les types GLPI.", 'printgestion')
            . "</div>";

        // Bouton toggle : flèche qui replie/déploie le tableau en dessous
        echo "<div class='text-center mb-2'>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
            . "data-bs-toggle='collapse' data-bs-target='#printgestion-mapping-collapse' "
            . "aria-expanded='false' aria-controls='printgestion-mapping-collapse' "
            . "id='printgestion-mapping-toggle'>";
        echo "<i class='fa-solid fa-chevron-down me-1'></i>";
        echo "<span class='printgestion-toggle-label'>" . __('Afficher le tableau', 'printgestion') . "</span>";
        echo "</button>";
        echo "</div>";

        // Section repliable : contient tout le tableau + bouton "Ajouter une ligne"
        echo "<div class='collapse' id='printgestion-mapping-collapse'>";

        echo "<p class='text-muted small mb-3'>"
            . __("Une ligne par propriété SNMP. Modifie plusieurs lignes, coche celles à supprimer, "
                . "ajoute-en de nouvelles via <strong>+ Ajouter une ligne</strong>, puis clique "
                . "<strong>Sauvegarder</strong> en bas de page. La couleur est déduite automatiquement du nom.", 'printgestion')
            . "</p>";

        // Liste des types pour la template JS (plain <select> sur les new rows)
        $cartridge_types = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_cartridgeitemtypes',
            'ORDER'  => ['name'],
        ]) as $t) {
            $cartridge_types[(int)$t['id']] = (string)$t['name'];
        }

        // Pré-charge les lignes pour pouvoir émettre les hidden inputs AVANT le <table>
        // (les <input> directement dans <tbody> sans <td> sont déplacés hors du
        // form par les parseurs HTML des navigateurs → pas envoyés au POST).
        $mapping_rows = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_printgestion_snmp_mapping',
            'ORDER' => ['snmp_property'],
        ]) as $m) {
            $mapping_rows[] = $m;
        }

        // NB: on n'ouvre PAS de <form> ici. Toute la section est rendue à l'intérieur
        // du form principal ouvert par showFormHeader() plus haut dans showConfigForm().
        // Le bouton "Save" natif de showFormButtons() en bas de page déclenchera le POST
        // vers front/config.form.php qui traitera aussi ce batch mapping.
        echo Html::hidden('snmp_mapping_batch', ['value' => '1']);

        // Enumération des IDs à traiter (DOIT être hors du <table> pour survivre
        // au parsing DOM du navigateur)
        foreach ($mapping_rows as $m) {
            echo Html::hidden('existing_ids[]', ['value' => (int)$m['id']]);
        }

        echo "<table class='tab_cadre_fixehov' style='width:100%' id='printgestion-mapping-table'>";
        echo "<thead><tr class='noHover'>";
        echo "<th style='width:50%'>" . __('Propriété SNMP', 'printgestion') . "</th>";
        echo "<th style='width:40%'>" . __('Type cartouche GLPI', 'printgestion') . "</th>";
        echo "<th style='width:10%'>" . __('Supprimer', 'printgestion') . "</th>";
        echo "</tr></thead><tbody id='printgestion-existing-rows'>";

        $has_rows = !empty($mapping_rows);
        foreach ($mapping_rows as $m) {
            $id  = (int)$m['id'];
            $prop = htmlspecialchars((string)$m['snmp_property'], ENT_QUOTES, 'UTF-8');
            $current_type = (int)($m['cartridgeitemtypes_id'] ?? 0);

            echo "<tr id='row-{$id}' data-row-id='{$id}'>";
            echo "<td><span class='row-property'>{$prop}</span></td>";
            echo "<td>";
            // Dropdown natif GLPI — nom FLAT (pas de syntaxe tableau) +
            // rand numérique unique par ligne pour éviter les collisions de DOM ID
            Dropdown::show('CartridgeItemType', [
                'name'                => "existing_type_{$id}",
                'value'               => $current_type,
                'display_emptychoice' => true,
                'emptylabel'          => '-----',
                'rand'                => $id + 100000,
            ]);
            echo "</td>";
            echo "<td>";
            echo "<div class='form-check'>";
            echo "<input type='checkbox' class='form-check-input' name='delete[{$id}]' value='1' "
                . "id='del-{$id}' onchange=\"printgestionToggleDelete({$id}, this.checked)\">";
            echo "<label class='form-check-label small text-danger' for='del-{$id}'>"
                . "<i class='fa-solid fa-trash'></i></label>";
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }

        if (!$has_rows) {
            echo "<tr id='printgestion-empty-row'><td colspan='3' class='text-muted text-center'>"
                . __('Aucun mapping défini', 'printgestion') . "</td></tr>";
        }

        echo "</tbody>";
        echo "<tbody id='printgestion-new-rows'></tbody>";
        echo "</table>";

        // Seul le bouton "Ajouter une ligne" reste — la sauvegarde passe par
        // le bouton Save natif GLPI en bas de page (showFormButtons)
        echo "<div class='mt-3'>";
        echo "<button type='button' class='btn btn-sm btn-outline-primary' onclick='printgestionAddMappingRow()'>"
            . "<i class='fa-solid fa-plus'></i> " . __('Ajouter une ligne', 'printgestion') . "</button>";
        echo "<span class='text-muted small ms-2'>"
            . __('Les modifications seront enregistrées avec le bouton Sauvegarder en bas de page.', 'printgestion')
            . "</span>";
        echo "</div>";

        // Template <select> caché pour les nouvelles lignes clonées via JS.
        // Les nouvelles lignes utilisent un <select> natif HTML (pas Dropdown::show)
        // parce qu'on ne peut pas cloner un select2 initialisé côté JS sans casser
        // son binding ajax. Ce n'est pas moche : les options sont chargées à l'avance.
        $tpl_options = "<option value=''>-----</option>";
        foreach ($cartridge_types as $tid => $tlabel) {
            $tpl_options .= "<option value='{$tid}'>"
                . htmlspecialchars($tlabel, ENT_QUOTES, 'UTF-8') . "</option>";
        }
        echo "<template id='printgestion-mapping-template'>";
        echo "<select name='__PLACEHOLDER__' class='form-select form-select-sm'>{$tpl_options}</select>";
        echo "</template>";

        echo <<<'HTML'
<script>
let printgestionNewRowIdx = 0;

function printgestionToggleDelete(rowId, checked) {
    const tr = document.getElementById('row-' + rowId);
    if (!tr) return;
    tr.style.textDecoration = checked ? 'line-through' : '';
    tr.style.opacity = checked ? '0.5' : '';
}

function printgestionAddMappingRow() {
    const tbody = document.getElementById('printgestion-new-rows');
    const idx   = printgestionNewRowIdx++;

    // Récupère le HTML du <select> depuis le <template> et renomme le placeholder
    const tpl = document.getElementById('printgestion-mapping-template');
    const selectHtml = tpl.innerHTML.replace(
        '__PLACEHOLDER__',
        'new[' + idx + '][cartridgeitemtypes_id]'
    );

    const tr = document.createElement('tr');
    tr.className = 'table-warning';
    tr.innerHTML =
        '<td><input type="text" class="form-control form-control-sm" ' +
            'name="new[' + idx + '][snmp_property]" ' +
            'placeholder="Toner Noir, developerblack, …" required></td>' +
        '<td>' + selectHtml + '</td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger" ' +
            'onclick="this.closest(\'tr\').remove()">' +
            '<i class="fa-solid fa-xmark"></i></button></td>';
    tbody.appendChild(tr);

    // Cache l'éventuelle ligne "Aucun mapping défini"
    const empty = document.getElementById('printgestion-empty-row');
    if (empty) empty.style.display = 'none';
}

// Rotation du chevron + libellé au toggle du collapse.
// Exécution immédiate (pas DOMContentLoaded) car ce script est injecté via AJAX
// dans l'onglet Config, le DOMContentLoaded a déjà été tiré avant l'injection.
// Les éléments HTML sont juste au-dessus dans le flux → déjà disponibles ici.
(function() {
    const collapseEl = document.getElementById('printgestion-mapping-collapse');
    const toggleBtn  = document.getElementById('printgestion-mapping-toggle');
    if (!collapseEl || !toggleBtn) return;
    const chevron = toggleBtn.querySelector('.fa-chevron-down, .fa-chevron-up');
    const label   = toggleBtn.querySelector('.printgestion-toggle-label');
    collapseEl.addEventListener('show.bs.collapse', function() {
        if (chevron) { chevron.classList.remove('fa-chevron-down'); chevron.classList.add('fa-chevron-up'); }
        if (label)   { label.textContent = 'Masquer le tableau'; }
    });
    collapseEl.addEventListener('hide.bs.collapse', function() {
        if (chevron) { chevron.classList.remove('fa-chevron-up'); chevron.classList.add('fa-chevron-down'); }
        if (label)   { label.textContent = 'Afficher le tableau'; }
    });
})();
</script>
HTML;

        // Ferme : collapse, card-body, card
        echo "</div></div></div>";
    }

    // ─────────────────────────────────────────────────────────────
    //  ENVOI DE MAIL PAR GABARIT (pattern plugin Gestion MailSend)
    // ─────────────────────────────────────────────────────────────

    /**
     * Envoie un mail à partir d'un gabarit de notification.
     * GLPI 11 : API Symfony Mailer via GLPIMailer::getEmail().
     *
     * @param string|array $email      Destinataire(s) — premier = TO, suivants = CC.
     * @param int          $gabarit_id ID du gabarit glpi_notificationtemplates.
     * @param array        $balises    ['##printgestion.printer##' => 'valeur', ...]
     * @param string|null  $attachment Chemin fichier à joindre (optionnel).
     */
    public static function sendMail($email, int $gabarit_id, array $balises = [], ?string $attachment = null): bool {
        global $DB, $CFG_GLPI;

        self::$last_mail_error = '';

        if ($gabarit_id <= 0) {
            self::$last_mail_error = __('aucun modèle de notification configuré', 'printgestion');
            PluginPrintgestionLogger::warning('Config::sendMail', 'Mail non envoyé : ' . self::$last_mail_error . '.');
            return false;
        }

        // Parsing & validation emails
        $items = is_array($email)
            ? $email
            : preg_split('/[,\s;]+/u', (string)$email, -1, PREG_SPLIT_NO_EMPTY);
        $valid = [];
        foreach ($items as $e) {
            $e = trim((string)$e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $valid[strtolower($e)] = $e;
            }
        }
        if (empty($valid)) {
            self::$last_mail_error = __('aucune adresse email valide parmi les destinataires', 'printgestion');
            PluginPrintgestionLogger::warning(
                'Config::sendMail',
                sprintf('Mail non envoyé (modèle %d) : %s.', $gabarit_id, self::$last_mail_error)
            );
            return false;
        }
        $valid = array_values($valid);
        $to    = array_shift($valid);
        $cc    = $valid;

        // Chargement gabarit avec fallback de langue
        $curLang = $_SESSION['glpilanguage'] ?? ($CFG_GLPI['language'] ?? 'fr_FR');
        $langs   = array_values(array_unique([$curLang, substr($curLang, 0, 2), 'fr_FR']));

        $tpl = null;
        foreach ($langs as $lang) {
            $row = $DB->request([
                'SELECT' => ['subject', 'content_text', 'content_html'],
                'FROM'   => 'glpi_notificationtemplatetranslations',
                'WHERE'  => [
                    'notificationtemplates_id' => $gabarit_id,
                    'language'                 => $lang,
                ],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($row)) {
                $tpl = $row;
                break;
            }
        }
        if ($tpl === null) {
            $tpl = $DB->request([
                'SELECT' => ['subject', 'content_text', 'content_html'],
                'FROM'   => 'glpi_notificationtemplatetranslations',
                'WHERE'  => ['notificationtemplates_id' => $gabarit_id],
                'LIMIT'  => 1,
            ])->current();
        }
        if (!is_array($tpl)) {
            self::$last_mail_error = sprintf(__('modèle de notification %d introuvable ou sans traduction', 'printgestion'), $gabarit_id);
            PluginPrintgestionLogger::warning('Config::sendMail', 'Mail non envoyé : ' . self::$last_mail_error . '.');
            return false;
        }

        $subject  = (string)($tpl['subject'] ?? '');
        $bodyText = isset($tpl['content_text']) ? html_entity_decode((string)$tpl['content_text'], ENT_QUOTES, 'UTF-8') : '';
        $bodyHtml = isset($tpl['content_html']) ? html_entity_decode((string)$tpl['content_html'], ENT_QUOTES, 'UTF-8') : '';

        // Balises disponibles par défaut
        $defaults = [
            '##printgestion.printer##'         => '',
            '##printgestion.client##'          => '',
            '##printgestion.toner##'           => '',
            '##printgestion.level##'           => '',
            '##printgestion.days##'            => '',
            '##printgestion.cartridge##'       => '',
            '##printgestion.stock##'           => '',
            '##printgestion.contract##'        => '',
            '##printgestion.carrier##'         => '',
            '##printgestion.tracking##'        => '',
            '##printgestion.cartridges_list##' => '',
            '##printgestion.printers_list##'   => '',
            '##printgestion.count##'           => '',
            '##printgestion.glpi_url##'        => (string)($CFG_GLPI['url_base'] ?? ''),
        ];
        $all = array_merge($defaults, $balises);

        // Balises dont la valeur est du HTML construit par le plugin (listes <ul>
        // dont chaque valeur dynamique est échappée à la construction). Toutes les
        // autres valeurs — noms d'imprimante, de client, de cartouche… issus de
        // l'inventaire SNMP ou de la saisie — sont du TEXTE, échappé dans le corps HTML.
        $html_tags = ['##printgestion.cartridges_list##', '##printgestion.printers_list##'];

        foreach ($all as $tag => $val) {
            $val     = (string)$val;
            $is_html = in_array($tag, $html_tags, true);

            // Version texte : pour une liste HTML, un élément par ligne, sans balises.
            $plain = $is_html
                ? trim(html_entity_decode(
                    strip_tags((string)preg_replace('#</li>\s*#i', "\n", $val)),
                    ENT_QUOTES,
                    'UTF-8'
                ))
                : $val;

            // Sujet : une seule ligne (aucun retour à la ligne injecté dans l'en-tête).
            $subject  = str_replace($tag, str_replace(["\r", "\n"], ' ', $plain), $subject);
            $bodyText = str_replace($tag, $plain, $bodyText);
            $bodyHtml = str_replace(
                $tag,
                $is_html ? $val : htmlspecialchars($val, ENT_QUOTES, 'UTF-8'),
                $bodyHtml
            );
        }

        // Mailer GLPI 11 (Symfony)
        $mmail = new GLPIMailer();
        $mmail->addCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");

        $fromEmail = !empty($CFG_GLPI['from_email'])
            ? (string)$CFG_GLPI['from_email']
            : (string)($CFG_GLPI['admin_email'] ?? 'no-reply@localhost');
        $fromName  = $CFG_GLPI['from_email_name'] ?? $CFG_GLPI['admin_email_name'] ?? 'GLPI';
        $fromName  = (is_string($fromName) && $fromName !== '') ? $fromName : 'GLPI';

        $emailObj = $mmail->getEmail();
        $emailObj->from(new \Symfony\Component\Mime\Address($fromEmail, $fromName));
        $emailObj->to($to);
        if (!empty($cc)) {
            $emailObj->cc(...$cc);
        }
        if ($attachment && file_exists($attachment)) {
            $emailObj->attachFromPath($attachment);
        }

        if ($subject !== '') {
            $mmail->Subject = $subject;
        }
        $mmail->Body    = $bodyHtml;
        $mmail->AltBody = $bodyText;

        $ok = (bool)$mmail->send();
        if (!$ok) {
            self::$last_mail_error = (string)$mmail->getError();
            // Seule trace pour les envois des tâches automatiques (pas de session à l'écran).
            PluginPrintgestionLogger::error(
                'Config::sendMail',
                sprintf(
                    'Échec d\'envoi (modèle %d, destinataire principal %s) : %s',
                    $gabarit_id,
                    $to,
                    self::$last_mail_error
                )
            );
            Session::addMessageAfterRedirect(
                __('Erreur envoi mail Print Gestion : ', 'printgestion') . self::$last_mail_error,
                true, ERROR
            );
        }
        return $ok;
    }
}
