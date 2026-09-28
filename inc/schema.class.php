<?php
/**
 * PluginPrintgestionSchema — schéma de la 1.0.0.
 *
 * La 1.0.0 s'installe sur une base vierge du plugin ; elle n'a pas de chemin de mise à jour : une base qui porte
 * une autre version est refusée avec le message qui dit de désinstaller d'abord. Les CREATE TABLE et les lignes de
 * référence ci-dessous n'ont pas été écrits à la main ni reconstruits en relisant l'ancienne chaîne de migrations :
 * ils ont été relevés sur une base installée par cette chaîne (tests/securite/etat_installation.py) puis générés
 * (tests/securite/schema_depuis_etat.py). La preuve est rejouable : tests/securite/comparer_etats.py entre
 * tests/securite/reference/etat-ancien-chemin.json et le relevé d'une installation neuve doit rendre zéro écart.
 *
 * Jeu de caractères, collation et signe des clés sont ceux que GLPI décide (DBConnection), remplis à l'installation.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSchema {

    const VERSION        = '1.0.0';
    const CONFIG_CONTEXT = 'plugin:printgestion';
    const CONFIG_KEY     = 'schema_version';

    /** Tables d'anciennes versions, jamais créées par la 1.0.0 : supprimées à la désinstallation si elles existent. */
    const LEGACY_TABLES = [
        'glpi_plugin_printgestion_sageclients',
        'glpi_plugin_printgestion_entitysageclients',
        'glpi_plugin_printgestion_snmp_rules',
        'glpi_plugin_printgestion_table_prefs',
        'glpi_plugin_printgestion_alertviews',
        'glpi_plugin_printgestion_billingviews',
        'glpi_plugin_printgestion_alerts_view',
    ];

    /** CREATE TABLE de chaque table (jalons __CHARSET__, __COLLATION__, __KEY_SIGN__ remplis par GLPI) ; relevé, jamais réécrit à la main. */
    const TABLES = [
        'glpi_plugin_printgestion_agentalerts' => 'CREATE TABLE `glpi_plugin_printgestion_agentalerts` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `type` enum(\'agent_silent\',\'printer_silent\') NOT NULL,
            `agents_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `reason` varchar(30) NULL DEFAULT NULL,
            `date_begin` timestamp NULL DEFAULT NULL,
            `date_notified` timestamp NULL DEFAULT NULL,
            `date_end` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            `open_lock` tinyint GENERATED ALWAYS AS (if(`date_end` is null,1,NULL)) STORED,
            PRIMARY KEY (`id`),
            KEY `agents_id` (`agents_id`),
            KEY `date_creation` (`date_creation`),
            KEY `date_end` (`date_end`),
            KEY `date_mod` (`date_mod`),
            KEY `entities_id` (`entities_id`),
            KEY `printers_id` (`printers_id`),
            UNIQUE KEY `uniq_open_alert` (`type`, `agents_id`, `printers_id`, `open_lock`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_agentsettings' => 'CREATE TABLE `glpi_plugin_printgestion_agentsettings` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `agents_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `auto_update` tinyint NOT NULL DEFAULT 1,
            `target_version` varchar(20) NULL DEFAULT NULL,
            `users_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `agents_id` (`agents_id`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_alert_snoozes' => 'CREATE TABLE `glpi_plugin_printgestion_alert_snoozes` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL,
            `toner_property` varchar(255) NOT NULL,
            `snooze_until` timestamp NOT NULL,
            `users_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`),
            KEY `printers_id` (`printers_id`),
            KEY `snooze_until` (`snooze_until`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_alerts' => 'CREATE TABLE `glpi_plugin_printgestion_alerts` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `toner_property` varchar(255) NULL DEFAULT NULL,
            `level_percent` int NULL DEFAULT NULL,
            `estimated_days` int NULL DEFAULT NULL,
            `alert_type` enum(\'low_toner\',\'no_install_reminder\',\'contract_expiry\',\'wrong_printer\') NOT NULL,
            `date_alert` timestamp NOT NULL DEFAULT current_timestamp(),
            `mail_sent` tinyint NOT NULL DEFAULT 0,
            `is_resolved` tinyint NOT NULL DEFAULT 0,
            `intended_printers_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `detected_printers_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `expeditions_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `alert_type` (`alert_type`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`),
            KEY `is_resolved` (`is_resolved`),
            KEY `printers_id` (`printers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_alertview' => 'CREATE TABLE `glpi_plugin_printgestion_alertview` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `toner_property` varchar(255) NULL DEFAULT NULL,
            `toner_color` varchar(20) NULL DEFAULT NULL,
            `level_percent` int NOT NULL DEFAULT 0,
            `days_remaining` int NULL DEFAULT NULL,
            `status` enum(\'ok\',\'watch\',\'critical\') NOT NULL DEFAULT \'ok\',
            `cartridge_label` varchar(255) NULL DEFAULT NULL,
            `has_expedition` tinyint NOT NULL DEFAULT 0,
            `is_snoozed` tinyint NOT NULL DEFAULT 0,
            `is_estimate` tinyint NOT NULL DEFAULT 0,
            `date_compute` timestamp NULL DEFAULT NULL,
            `lock_reason` varchar(20) NULL DEFAULT NULL,
            `lock_message` text NULL DEFAULT NULL,
            `ref_error` text NULL DEFAULT NULL,
            `level_suspect` tinyint NOT NULL DEFAULT 0,
            `expedition_statut` varchar(20) NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `printers_id` (`printers_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        // Vue « état de la collecte » : une ligne par imprimante (Collectview::rebuild), lue par le moteur natif.
        'glpi_plugin_printgestion_collectviews' => 'CREATE TABLE `glpi_plugin_printgestion_collectviews` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `state` varchar(20) NOT NULL DEFAULT \'no_inventory\',
            `last_inventory` timestamp NULL DEFAULT NULL,
            `last_discovery` timestamp NULL DEFAULT NULL,
            `agents_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `date_compute` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `printers_id` (`printers_id`),
            KEY `entities_id` (`entities_id`),
            KEY `state` (`state`),
            KEY `agents_id` (`agents_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        // Vue « sonde » : une ligne par agent (Agentview::rebuild), lue par le moteur natif.
        'glpi_plugin_printgestion_agentviews' => 'CREATE TABLE `glpi_plugin_printgestion_agentviews` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `agents_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_probe` tinyint NOT NULL DEFAULT 0,
            `is_silent` tinyint NOT NULL DEFAULT 0,
            `compliance` varchar(20) NULL DEFAULT NULL,
            `target_version` varchar(20) NULL DEFAULT NULL,
            `version_status` varchar(20) NULL DEFAULT NULL,
            `printers_count` int NOT NULL DEFAULT 0,
            `update_declared` varchar(20) NULL DEFAULT NULL,
            `last_network_inventory` timestamp NULL DEFAULT NULL,
            `date_compute` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `agents_id` (`agents_id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_probe` (`is_probe`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_billing' => 'CREATE TABLE `glpi_plugin_printgestion_billing` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `contracts_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `period_start` date NOT NULL,
            `period_end` date NOT NULL,
            `pages_nb` int NOT NULL DEFAULT 0,
            `pages_color` int NOT NULL DEFAULT 0,
            `rate_nb` decimal(10,6) NOT NULL DEFAULT 0.000000,
            `rate_color` decimal(10,6) NOT NULL DEFAULT 0.000000,
            `total_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
            `generated_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `printers_id` (`printers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_billing_view' => 'CREATE TABLE `glpi_plugin_printgestion_billing_view` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `users_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `view_mode` enum(\'printer\',\'client\') NOT NULL DEFAULT \'printer\',
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `printer_name` varchar(255) NULL DEFAULT NULL,
            `entity_name` varchar(255) NULL DEFAULT NULL,
            `contracts_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `contract_name` varchar(255) NULL DEFAULT NULL,
            `printers_count` int NULL DEFAULT NULL,
            `pages_nb` int NOT NULL DEFAULT 0,
            `pages_color` int NOT NULL DEFAULT 0,
            `rate_nb` decimal(14,6) NOT NULL DEFAULT 0.000000,
            `rate_color` decimal(14,6) NOT NULL DEFAULT 0.000000,
            `total_cost` decimal(16,2) NOT NULL DEFAULT 0.00,
            `period_start` date NULL DEFAULT NULL,
            `period_end` date NULL DEFAULT NULL,
            `date_compute` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `printers_id` (`printers_id`),
            KEY `users_id` (`users_id`),
            KEY `view_mode` (`view_mode`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_cartridge_history' => 'CREATE TABLE `glpi_plugin_printgestion_cartridge_history` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `cartridgeitems_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `cartridgetypes_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `toner_property` varchar(255) NULL DEFAULT NULL,
            `toner_color` enum(\'black\',\'cyan\',\'magenta\',\'yellow\',\'other\') NOT NULL DEFAULT \'black\',
            `level_at_install` int NULL DEFAULT NULL,
            `level_at_removal` int NULL DEFAULT NULL,
            `date_install` timestamp NULL DEFAULT NULL,
            `date_removal` timestamp NULL DEFAULT NULL,
            `pages_printed` int NULL DEFAULT NULL,
            `printer_counter_at_install` int NULL DEFAULT NULL,
            `printer_counter_at_removal` int NULL DEFAULT NULL,
            `is_detected` tinyint NOT NULL DEFAULT 0,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `idx_slot_install` (`printers_id`, `toner_property`, `is_detected`, `date_install`),
            KEY `is_recursive` (`is_recursive`),
            KEY `printers_id` (`printers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_cartridge_snmp' => 'CREATE TABLE `glpi_plugin_printgestion_cartridge_snmp` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `cartridgeitems_id` int __KEY_SIGN__ NOT NULL,
            `snmp_property` varchar(255) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `snmp_property` (`snmp_property`),
            UNIQUE KEY `uniq_binding` (`cartridgeitems_id`, `snmp_property`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_collectfrequencies' => 'CREATE TABLE `glpi_plugin_printgestion_collectfrequencies` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `frequency` varchar(10) NOT NULL DEFAULT \'daily\',
            `modifier` smallint unsigned NOT NULL DEFAULT 1,
            `users_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`),
            UNIQUE KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_configs' => 'CREATE TABLE `glpi_plugin_printgestion_configs` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `group_planif` int __KEY_SIGN__ NULL DEFAULT NULL,
            `group_achat` int __KEY_SIGN__ NULL DEFAULT NULL,
            `group_commercial` int __KEY_SIGN__ NULL DEFAULT NULL,
            `mode_planif` enum(\'group\',\'emails\') NOT NULL DEFAULT \'group\',
            `mode_achat` enum(\'group\',\'emails\') NOT NULL DEFAULT \'group\',
            `mode_commercial` enum(\'group\',\'emails\') NOT NULL DEFAULT \'group\',
            `emails_planif` text NULL DEFAULT NULL,
            `emails_achat` text NULL DEFAULT NULL,
            `emails_commercial` text NULL DEFAULT NULL,
            `threshold_days` int NOT NULL DEFAULT 30,
            `threshold_level` int NOT NULL DEFAULT 15,
            `reminder_days` int NOT NULL DEFAULT 7,
            `reminder_recipients` enum(\'planif\',\'commercial\',\'both\') NOT NULL DEFAULT \'both\',
            `detection_delta` int NOT NULL DEFAULT 20,
            `gabarit_planif` int __KEY_SIGN__ NULL DEFAULT NULL,
            `gabarit_planif_group` int __KEY_SIGN__ NULL DEFAULT NULL,
            `gabarit_achat` int __KEY_SIGN__ NULL DEFAULT NULL,
            `gabarit_commercial` int __KEY_SIGN__ NULL DEFAULT NULL,
            `gabarit_rappel` int __KEY_SIGN__ NULL DEFAULT NULL,
            `gabarit_courtoisie` int __KEY_SIGN__ NULL DEFAULT NULL,
            `wrong_printer_lookback_days` int NOT NULL DEFAULT 30,
            `default_pages_per_cartridge` int NOT NULL DEFAULT 5000,
            `enable_contrats` tinyint NOT NULL DEFAULT 1,
            `enable_toner` tinyint NOT NULL DEFAULT 1,
            `enable_cout` tinyint NOT NULL DEFAULT 1,
            `guard_days` int NOT NULL DEFAULT 5,
            `guard_bypass_level` int NOT NULL DEFAULT 10,
            `guard_ticket_days` int NOT NULL DEFAULT 10,
            `consumables_contracttypes` text NULL DEFAULT NULL,
            `gesconso_separator` varchar(20) NOT NULL DEFAULT \' # \',
            `gesconso_designation_max` int NOT NULL DEFAULT 69,
            `silent_days` int NOT NULL DEFAULT 3,
            `demande_reminder_days` int NOT NULL DEFAULT 2,
            `enable_deploiement` tinyint NOT NULL DEFAULT 1,
            `enable_sage` tinyint NOT NULL DEFAULT 1,
            `agent_version` varchar(20) NULL DEFAULT NULL,
            `agent_httpd_trust` varchar(255) NULL DEFAULT NULL,
            `agent_latest_version` varchar(20) NULL DEFAULT NULL,
            `agent_latest_source` varchar(10) NULL DEFAULT NULL,
            `agent_latest_checked` timestamp NULL DEFAULT NULL,
            `agent_update_default` tinyint NOT NULL DEFAULT 1,
            `agent_update_target` varchar(20) NULL DEFAULT NULL,
            `agent_probe_states_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `gls_client_id` varchar(255) NULL DEFAULT NULL,
            `gls_client_secret` varchar(255) NULL DEFAULT NULL,
            `gls_secret_date` timestamp NULL DEFAULT NULL,
            `mbe_username` varchar(255) NULL DEFAULT NULL,
            `mbe_passphrase` varchar(255) NULL DEFAULT NULL,
            `mbe_secret_date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_contractrates' => 'CREATE TABLE `glpi_plugin_printgestion_contractrates` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `contracts_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `type_cout` enum(\'nb\',\'color\',\'both\') NOT NULL DEFAULT \'both\',
            `rate` decimal(10,6) NOT NULL DEFAULT 0.000000,
            `actif` tinyint NOT NULL DEFAULT 1,
            `date_creation` timestamp NULL DEFAULT NULL,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `contracts_id` (`contracts_id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_demandelines' => 'CREATE TABLE `glpi_plugin_printgestion_demandelines` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `plugin_printgestion_demandes_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `toner_property` varchar(255) NOT NULL DEFAULT \'\',
            `cartridgeitems_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `quantity` int NOT NULL DEFAULT 1,
            `unit_price` decimal(20,4) NULL DEFAULT NULL,
            `is_under_contract` tinyint NOT NULL DEFAULT 0,
            `contracts_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `level_at_proposal` int NULL DEFAULT NULL,
            `estimated_days` int NULL DEFAULT NULL,
            `statut` enum(\'proposed\',\'validated\',\'exported\',\'shipped\',\'delivered\',\'installed\',\'cancelled\') NOT NULL DEFAULT \'proposed\',
            `expeditions_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            `active_lock` tinyint GENERATED ALWAYS AS (if(`statut` in (\'proposed\',\'validated\'),1,NULL)) STORED,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `cartridgeitems_id` (`cartridgeitems_id`),
            KEY `contracts_id` (`contracts_id`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`),
            KEY `entities_id` (`entities_id`),
            KEY `expeditions_id` (`expeditions_id`),
            KEY `idx_slot_statut` (`printers_id`, `toner_property`, `statut`),
            KEY `is_recursive` (`is_recursive`),
            KEY `plugin_printgestion_demandes_id` (`plugin_printgestion_demandes_id`),
            UNIQUE KEY `uniq_active_slot` (`printers_id`, `toner_property`, `active_lock`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_demandes' => 'CREATE TABLE `glpi_plugin_printgestion_demandes` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NULL DEFAULT NULL,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `locations_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `statut` enum(\'proposed\',\'validated\',\'exported\',\'shipped\',\'delivered\',\'installed\',\'cancelled\') NOT NULL DEFAULT \'proposed\',
            `delivery_mode` enum(\'direct\',\'technician\') NOT NULL DEFAULT \'direct\',
            `contact` varchar(255) NULL DEFAULT NULL,
            `delivery_comment` text NULL DEFAULT NULL,
            `users_id_validate` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `date_validate` timestamp NULL DEFAULT NULL,
            `users_id_cancel` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `date_cancel` timestamp NULL DEFAULT NULL,
            `cancel_reason` text NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            `proposal_lock` tinyint GENERATED ALWAYS AS (if(`statut` = \'proposed\',1,NULL)) STORED,
            `date_last_reminder` timestamp NULL DEFAULT NULL,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`),
            KEY `locations_id` (`locations_id`),
            KEY `statut` (`statut`),
            UNIQUE KEY `uniq_open_proposal` (`entities_id`, `locations_id`, `proposal_lock`),
            KEY `users_id_cancel` (`users_id_cancel`),
            KEY `users_id_validate` (`users_id_validate`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_expedition_bls' => 'CREATE TABLE `glpi_plugin_printgestion_expedition_bls` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `expeditions_id` int __KEY_SIGN__ NOT NULL,
            `bl_surveys_id` int __KEY_SIGN__ NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `bl_surveys_id` (`bl_surveys_id`),
            KEY `entities_id` (`entities_id`),
            KEY `expeditions_id` (`expeditions_id`),
            KEY `is_recursive` (`is_recursive`),
            UNIQUE KEY `uniq_exp_bl` (`expeditions_id`, `bl_surveys_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_expeditions' => 'CREATE TABLE `glpi_plugin_printgestion_expeditions` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `toner_property` varchar(255) NULL DEFAULT NULL,
            `toner_color` enum(\'black\',\'cyan\',\'magenta\',\'yellow\',\'other\') NULL DEFAULT NULL,
            `statut` enum(\'pending\',\'shipped\',\'transit\',\'delivered\',\'installed\',\'cancelled\') NOT NULL DEFAULT \'pending\',
            `transport_number` varchar(255) NULL DEFAULT NULL,
            `transport_carrier` enum(\'ups\',\'gls\',\'chronopost\',\'other\') NULL DEFAULT NULL,
            `bl_surveys_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `users_id_tech` int __KEY_SIGN__ NULL DEFAULT NULL,
            `users_id_planif` int __KEY_SIGN__ NULL DEFAULT NULL,
            `level_at_alert` int NULL DEFAULT NULL,
            `estimated_days` int NULL DEFAULT NULL,
            `date_alert` timestamp NULL DEFAULT NULL,
            `date_shipped` timestamp NULL DEFAULT NULL,
            `date_delivered` timestamp NULL DEFAULT NULL,
            `date_installed` timestamp NULL DEFAULT NULL,
            `notes` text NULL DEFAULT NULL,
            `group_id` varchar(36) NULL DEFAULT NULL,
            `active_lock` tinyint GENERATED ALWAYS AS (if(`statut` in (\'installed\',\'cancelled\'),NULL,1)) STORED,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `tracking_key` varchar(32) NULL DEFAULT NULL,
            `tracking_suffix` varchar(8) NULL DEFAULT NULL,
            `tracking_status` varchar(32) NULL DEFAULT NULL,
            `tracking_label` varchar(255) NULL DEFAULT NULL,
            `tracking_event_datetime` varchar(40) NULL DEFAULT NULL,
            `tracking_event_place` varchar(255) NULL DEFAULT NULL,
            `tracking_checked_at` timestamp NULL DEFAULT NULL,
            `tracking_failures` int NOT NULL DEFAULT 0,
            `tracking_state` varchar(16) NOT NULL DEFAULT \'\',
            `tracking_parcels` text NULL DEFAULT NULL,
            `mbe_master_tracking` varchar(32) NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `bl_surveys_id` (`bl_surveys_id`),
            KEY `entities_id` (`entities_id`),
            KEY `group_id` (`group_id`),
            KEY `idx_slot_statut` (`printers_id`, `toner_property`, `statut`),
            KEY `is_recursive` (`is_recursive`),
            KEY `printers_id` (`printers_id`),
            UNIQUE KEY `uniq_active_slot` (`printers_id`, `toner_property`, `active_lock`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_historical_yields' => 'CREATE TABLE `glpi_plugin_printgestion_historical_yields` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL,
            `property_name` varchar(255) NOT NULL,
            `yield_per_percent` decimal(10,2) NOT NULL DEFAULT 0.00,
            `cycle_pages` int NOT NULL DEFAULT 0,
            `cycle_start_date` timestamp NULL DEFAULT NULL,
            `cycle_end_date` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`),
            KEY `lookup` (`printers_id`, `property_name`, `date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_printer_thresholds' => 'CREATE TABLE `glpi_plugin_printgestion_printer_thresholds` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL,
            `threshold_level` int NULL DEFAULT NULL,
            `threshold_days` int NULL DEFAULT NULL,
            `pages_per_cartridge` int NULL DEFAULT NULL,
            `date_mod` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`),
            UNIQUE KEY `printers_id` (`printers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_purchaseorders' => 'CREATE TABLE `glpi_plugin_printgestion_purchaseorders` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `group_id` char(36) NOT NULL,
            `source` varchar(10) NOT NULL,
            `documents_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `users_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `nb_lines` int NOT NULL DEFAULT 0,
            `mail_rows` longtext NOT NULL,
            `status` varchar(10) NOT NULL DEFAULT \'pending\',
            `attempts` int NOT NULL DEFAULT 0,
            `last_error` text NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_last_attempt` timestamp NULL DEFAULT NULL,
            `date_sent` timestamp NULL DEFAULT NULL,
            `date_notified` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `date_creation` (`date_creation`),
            KEY `documents_id` (`documents_id`),
            UNIQUE KEY `group_id` (`group_id`),
            KEY `status` (`status`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_raccordementips' => 'CREATE TABLE `glpi_plugin_printgestion_raccordementips` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `plugin_printgestion_raccordements_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `ip` varchar(15) NOT NULL DEFAULT \'\',
            `ip_num` int unsigned NOT NULL DEFAULT 0,
            `locations_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `comment` text NULL DEFAULT NULL,
            `contracts_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `result` enum(\'pending\',\'waiting_discovery\',\'waiting_inventory\',\'found\',\'no_levels\',\'no_snmp\',\'not_printer\',\'wrong_entity\') NOT NULL DEFAULT \'pending\',
            `itemtype` varchar(100) NULL DEFAULT NULL,
            `items_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `items_entities_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `date_check` timestamp NULL DEFAULT NULL,
            `applied_items_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `date_applied` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `contracts_id` (`contracts_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `locations_id` (`locations_id`),
            KEY `result` (`result`),
            UNIQUE KEY `uniq_ip` (`plugin_printgestion_raccordements_id`, `ip_num`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_raccordementlogs' => 'CREATE TABLE `glpi_plugin_printgestion_raccordementlogs` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `plugin_printgestion_raccordements_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `date` timestamp NULL DEFAULT NULL,
            `users_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `step` tinyint NOT NULL DEFAULT 0,
            `level` enum(\'info\',\'success\',\'warning\',\'error\') NOT NULL DEFAULT \'info\',
            `message` text NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `raccordement_date` (`plugin_printgestion_raccordements_id`, `date`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_raccordements' => 'CREATE TABLE `glpi_plugin_printgestion_raccordements` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `agents_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `status` enum(\'open\',\'configured\',\'triggered\',\'closed\',\'abandoned\') NOT NULL DEFAULT \'open\',
            `snmpcredentials_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `ipranges` text NULL DEFAULT NULL,
            `discovery_tasks` text NULL DEFAULT NULL,
            `inventory_tasks` text NULL DEFAULT NULL,
            `created_items` text NULL DEFAULT NULL,
            `users_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            `date_configured` timestamp NULL DEFAULT NULL,
            `date_triggered` timestamp NULL DEFAULT NULL,
            `date_inventory_prepared` timestamp NULL DEFAULT NULL,
            `date_verified` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `agents_id` (`agents_id`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`),
            KEY `entities_id` (`entities_id`),
            KEY `status` (`status`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_sagearticles' => 'CREATE TABLE `glpi_plugin_printgestion_sagearticles` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `ref` varchar(255) NOT NULL DEFAULT \'\',
            `label` varchar(255) NULL DEFAULT NULL,
            `is_in_last_import` tinyint NOT NULL DEFAULT 1,
            `date_import` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `is_in_last_import` (`is_in_last_import`),
            UNIQUE KEY `ref` (`ref`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_sagedeliveries' => 'CREATE TABLE `glpi_plugin_printgestion_sagedeliveries` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `client_code` varchar(255) NOT NULL DEFAULT \'\',
            `address_key` varchar(255) NOT NULL DEFAULT \'\',
            `label` varchar(255) NOT NULL DEFAULT \'\',
            `address` text NULL DEFAULT NULL,
            `postcode` varchar(255) NULL DEFAULT NULL,
            `town` varchar(255) NULL DEFAULT NULL,
            `is_in_last_import` tinyint NOT NULL DEFAULT 1,
            `date_import` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `address_key` (`address_key`),
            UNIQUE KEY `client_address` (`client_code`, `address_key`),
            KEY `is_in_last_import` (`is_in_last_import`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_sageimports' => 'CREATE TABLE `glpi_plugin_printgestion_sageimports` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `type` enum(\'clients\',\'deliveries\',\'articles\') NOT NULL,
            `filename` varchar(255) NOT NULL DEFAULT \'\',
            `users_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `nb_created` int NOT NULL DEFAULT 0,
            `nb_updated` int NOT NULL DEFAULT 0,
            `nb_unchanged` int NOT NULL DEFAULT 0,
            `nb_absent` int NOT NULL DEFAULT 0,
            `nb_linked` int NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `date_creation` (`date_creation`),
            KEY `type` (`type`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_snmp_mapping' => 'CREATE TABLE `glpi_plugin_printgestion_snmp_mapping` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `snmp_property` varchar(255) NOT NULL,
            `cartridgeitemtypes_id` int __KEY_SIGN__ NULL DEFAULT NULL,
            `toner_color` enum(\'black\',\'cyan\',\'magenta\',\'yellow\',\'other\') NOT NULL DEFAULT \'black\',
            `manufacturer` varchar(255) NULL DEFAULT NULL COMMENT \'legacy, non utilisé\',
            `cartridge_type` varchar(100) NULL DEFAULT NULL COMMENT \'legacy, non utilisé\',
            PRIMARY KEY (`id`),
            KEY `cartridgeitemtypes_id` (`cartridgeitemtypes_id`),
            UNIQUE KEY `uniq_snmp_property` (`snmp_property`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_snmprules' => 'CREATE TABLE `glpi_plugin_printgestion_snmprules` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `manufacturers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `property_pattern` varchar(255) NOT NULL DEFAULT \'\',
            `action` enum(\'ignore\',\'invert\') NOT NULL DEFAULT \'ignore\',
            `comment` varchar(255) NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `manufacturers_id` (`manufacturers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
        'glpi_plugin_printgestion_toner_readings' => 'CREATE TABLE `glpi_plugin_printgestion_toner_readings` (
            `id` int __KEY_SIGN__ NOT NULL AUTO_INCREMENT,
            `printers_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `property_name` varchar(255) NOT NULL,
            `level_percent` int NOT NULL DEFAULT 0,
            `reading_date` timestamp NOT NULL DEFAULT current_timestamp(),
            `total_pages` int NOT NULL DEFAULT 0,
            `bw_pages` int NOT NULL DEFAULT 0,
            `color_pages` int NOT NULL DEFAULT 0,
            `is_suspect` tinyint NOT NULL DEFAULT 0,
            `entities_id` int __KEY_SIGN__ NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `idx_lookup` (`printers_id`, `property_name`, `reading_date`),
            KEY `is_recursive` (`is_recursive`),
            KEY `printers_id` (`printers_id`),
            KEY `reading_date` (`reading_date`),
            UNIQUE KEY `uniq_daily` (`printers_id`, `property_name`, `reading_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT=DYNAMIC',
    ];

    /** Lignes de référence posées à l'installation (les identifiants de gabarits sont remplis ensuite par hook.php). */
    const SEEDS = [
        'glpi_plugin_printgestion_configs' => [
            ['agent_httpd_trust' => null, 'agent_latest_checked' => null, 'agent_latest_source' => null, 'agent_latest_version' => null, 'agent_probe_states_id' => '0', 'agent_update_default' => '1', 'agent_update_target' => null, 'agent_version' => null, 'consumables_contracttypes' => null, 'default_pages_per_cartridge' => '5000', 'demande_reminder_days' => '2', 'detection_delta' => '20', 'emails_achat' => null, 'emails_commercial' => null, 'emails_planif' => null, 'enable_contrats' => '1', 'enable_cout' => '1', 'enable_deploiement' => '1', 'enable_sage' => '1', 'enable_toner' => '1', 'gabarit_achat' => null, 'gabarit_commercial' => null, 'gabarit_courtoisie' => null, 'gabarit_planif' => null, 'gabarit_planif_group' => null, 'gabarit_rappel' => null, 'gesconso_designation_max' => '69', 'gesconso_separator' => ' # ', 'gls_client_id' => null, 'gls_client_secret' => null, 'gls_secret_date' => null, 'group_achat' => null, 'group_commercial' => null, 'group_planif' => null, 'guard_bypass_level' => '10', 'guard_days' => '5', 'guard_ticket_days' => '10', 'id' => '1', 'mbe_passphrase' => null, 'mbe_secret_date' => null, 'mbe_username' => null, 'mode_achat' => 'group', 'mode_commercial' => 'group', 'mode_planif' => 'group', 'reminder_days' => '7', 'reminder_recipients' => 'both', 'silent_days' => '3', 'threshold_days' => '30', 'threshold_level' => '15', 'wrong_printer_lookback_days' => '30'],
        ],
        'glpi_plugin_printgestion_snmp_mapping' => [
            ['cartridge_type' => null, 'cartridgeitemtypes_id' => null, 'id' => '1', 'manufacturer' => null, 'snmp_property' => 'tonerblack', 'toner_color' => 'black'],
            ['cartridge_type' => null, 'cartridgeitemtypes_id' => null, 'id' => '2', 'manufacturer' => null, 'snmp_property' => 'tonercyan', 'toner_color' => 'cyan'],
            ['cartridge_type' => null, 'cartridgeitemtypes_id' => null, 'id' => '3', 'manufacturer' => null, 'snmp_property' => 'tonermagenta', 'toner_color' => 'magenta'],
            ['cartridge_type' => null, 'cartridgeitemtypes_id' => null, 'id' => '4', 'manufacturer' => null, 'snmp_property' => 'toneryellow', 'toner_color' => 'yellow'],
            ['cartridge_type' => null, 'cartridgeitemtypes_id' => null, 'id' => '5', 'manufacturer' => null, 'snmp_property' => 'fuserkit', 'toner_color' => 'other'],
            ['cartridge_type' => null, 'cartridgeitemtypes_id' => null, 'id' => '6', 'manufacturer' => null, 'snmp_property' => 'transferkit', 'toner_color' => 'other'],
            ['cartridge_type' => null, 'cartridgeitemtypes_id' => null, 'id' => '7', 'manufacturer' => null, 'snmp_property' => 'maintenancekit', 'toner_color' => 'other'],
        ],
    ];

    /** Version de schéma enregistrée en base, null si aucune. */
    public static function getInstalledVersion(): ?string {
        $value = Config::getConfigurationValue(self::CONFIG_CONTEXT, self::CONFIG_KEY);
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Pourquoi l'installation ne peut pas avoir lieu : une base qui porte une autre version du plugin, ou une
     * installation antérieure au versionnement. Chaîne vide quand la base est vierge du plugin, ou déjà en 1.0.0
     * (réinstallation à l'identique, sans effet).
     */
    public static function refusal(): string {
        global $DB;

        $installed = self::getInstalledVersion();
        if ($installed !== null && $installed !== self::VERSION) {
            return sprintf(
                __('Print Gestion %1$s s\'installe sur une base vierge du plugin ; cette base porte la version %2$s. Désinstallez d\'abord le plugin (Configuration → Plugins → Désinstaller), puis installez-le.', 'printgestion'),
                self::VERSION,
                $installed
            );
        }
        if ($installed === null && $DB->tableExists('glpi_plugin_printgestion_configs')) {
            return sprintf(
                __('Print Gestion %s s\'installe sur une base vierge du plugin ; cette base porte une installation antérieure sans numéro de version. Désinstallez d\'abord le plugin (Configuration → Plugins → Désinstaller), puis installez-le.', 'printgestion'),
                self::VERSION
            );
        }
        return '';
    }

    /** Crée les tables absentes, pose les lignes de référence, enregistre la version. Sans effet sur une base déjà en 1.0.0. */
    public static function install(): void {
        global $DB;

        $replacements = [
            '__CHARSET__'   => DBConnection::getDefaultCharset(),
            '__COLLATION__' => DBConnection::getDefaultCollation(),
            '__KEY_SIGN__'  => DBConnection::getDefaultPrimaryKeySignOption(),
        ];
        foreach (self::TABLES as $table => $sql) {
            if ($DB->tableExists($table)) {
                continue;
            }
            if ($DB->doQuery(strtr($sql, $replacements)) === false) {
                throw new RuntimeException(sprintf('Print Gestion : création de la table %s en échec : %s', $table, $DB->error()));
            }
        }
        foreach (self::SEEDS as $table => $rows) {
            if (countElementsInTable($table) > 0) {
                continue;
            }
            foreach ($rows as $row) {
                $DB->insert($table, $row);
            }
        }
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_KEY => self::VERSION]);
    }

    /**
     * Crée une table du plugin si elle manque : une vue ajoutée après l'installation existe dès qu'on en a besoin,
     * sans attendre un passage par « Mettre à jour » dans la liste des plugins.
     */
    public static function createIfMissing(string $table): void {
        global $DB;

        if (!isset(self::TABLES[$table]) || $DB->tableExists($table)) {
            return;
        }
        $replacements = [
            '__CHARSET__'   => DBConnection::getDefaultCharset(),
            '__COLLATION__' => DBConnection::getDefaultCollation(),
            '__KEY_SIGN__'  => DBConnection::getDefaultPrimaryKeySignOption(),
        ];
        if ($DB->doQuery(strtr(self::TABLES[$table], $replacements)) === false) {
            throw new RuntimeException(sprintf('Print Gestion : création de la table %s en échec : %s', $table, $DB->error()));
        }
    }

    /** Toutes les tables (vivantes et anciennes) et le contexte de configuration du plugin. */
    public static function uninstall(): void {
        global $DB;

        foreach (array_merge(array_reverse(array_keys(self::TABLES)), self::LEGACY_TABLES) as $table) {
            $DB->dropTable($table, true);
        }
        // Tous les réglages du contexte du plugin, par la classe Config : ceux qu'elle connaît, un par un.
        Config::deleteConfigurationValues(self::CONFIG_CONTEXT, array_keys(Config::getConfigurationValues(self::CONFIG_CONTEXT)));
    }
}
