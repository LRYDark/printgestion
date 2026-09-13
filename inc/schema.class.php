<?php
/**
 * PluginPrintgestionSchema — versionnement du schéma de base du plugin.
 *
 * La version du schéma installé est enregistrée dans la configuration native
 * GLPI (table glpi_configs, contexte « plugin:printgestion », clé
 * « schema_version »). Chaque évolution du schéma est une étape déclarée dans
 * STEPS, jouée une seule fois et dans l'ordre lors de l'installation ou du
 * « Mettre à jour » du plugin. La version n'est enregistrée qu'après la réussite
 * complète de l'étape : une erreur SQL lève une exception, l'installation échoue
 * visiblement et l'étape sera rejouée à la tentative suivante.
 *
 * Ajouter une évolution de schéma :
 *   1. écrire une méthode migrateToXYZ(Migration $migration) idempotente ;
 *   2. la déclarer à la fin de STEPS ;
 *   3. incrémenter PLUGIN_PRINTGESTION_VERSION (setup.php) : sans changement de
 *      version, GLPI ne propose pas la mise à jour et l'étape n'est jamais jouée.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSchema {

    const CONFIG_CONTEXT = 'plugin:printgestion';
    const CONFIG_KEY     = 'schema_version';

    /** Étapes de migration, dans l'ordre : version cible => méthode. */
    const STEPS = [
        '1.0.0' => 'migrateTo100',
        '1.1.0' => 'migrateTo110',
        '1.2.0' => 'migrateTo120',
        '1.2.1' => 'migrateTo121',
        '1.2.2' => 'migrateTo122',
        '1.3.0' => 'migrateTo130',
        '1.3.1' => 'migrateTo131',
        '1.4.0' => 'migrateTo140',
        '1.4.1' => 'migrateTo141',
        '1.5.0' => 'migrateTo150',
        '1.5.1' => 'migrateTo151',
        '1.5.2' => 'migrateTo152',
        '1.5.3' => 'migrateTo153',
        '1.5.4' => 'migrateTo154',
        '1.5.5' => 'migrateTo155',
        '1.5.6' => 'migrateTo156',
        '1.5.7' => 'migrateTo157',
        '1.5.8' => 'migrateTo158',
        '1.5.9' => 'migrateTo159',
        '1.6.0' => 'migrateTo160',
        '1.6.1' => 'migrateTo161',
    ];

    /** Version de schéma attendue par le code déployé. */
    public static function getTargetVersion(): string {
        return (string) array_key_last(self::STEPS);
    }

    /** Version du schéma installé, ou null si aucune n'est enregistrée. */
    public static function getInstalledVersion(): ?string {
        $value = Config::getConfigurationValue(self::CONFIG_CONTEXT, self::CONFIG_KEY);
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Joue les étapes non encore appliquées, dans l'ordre.
     *
     * @throws RuntimeException si la base est plus récente que le code, ou si
     *                          une requête de migration échoue.
     */
    public static function migrate(Migration $migration): void {
        // Aucune version enregistrée : installation neuve, ou installation
        // antérieure au versionnement. L'étape 1.0.0 couvre les deux cas.
        $installed = self::getInstalledVersion() ?? '0.0.0';
        $target    = self::getTargetVersion();

        if (version_compare($installed, $target, '>')) {
            throw new RuntimeException(sprintf(
                'Print Gestion : le schéma installé (%s) est plus récent que celui attendu par le code déployé (%s). '
                . 'Déployez la version du plugin correspondant à la base.',
                $installed,
                $target
            ));
        }

        foreach (self::STEPS as $version => $method) {
            if (version_compare($installed, $version, '>=')) {
                continue;
            }
            $migration->displayMessage(sprintf('Print Gestion — migration du schéma vers %s', $version));
            self::$method($migration);
            Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_KEY => $version]);
            $installed = $version;
        }
    }

    /**
     * 1.0.0 — schéma de référence, tel qu'il existait avant le versionnement.
     * Crée les tables absentes et applique les ajouts historiques de colonnes et
     * d'index quand ils manquent : sans effet sur une base déjà à jour.
     */
    private static function migrateTo100(Migration $migration): void {
        PluginPrintgestionConfig::installSchemaBaseline($migration);
        PluginPrintgestionSnmpmapping::seedDefaults();
    }

    /**
     * 1.1.0 — clés API transporteurs chiffrées avec GLPIKey.
     * Colonnes passées en TEXT (une valeur chiffrée est plus longue que la valeur
     * en clair), puis chiffrement des valeurs déjà enregistrées.
     * Liste des colonnes volontairement figée ici : une étape livrée ne dépend pas
     * de constantes applicatives susceptibles d'évoluer.
     */
    private static function migrateTo110(Migration $migration): void {
        global $DB;

        $table  = 'glpi_plugin_printgestion_configs';
        $fields = ['api_ups', 'api_gls', 'api_chronopost'];

        foreach ($fields as $field) {
            $migration->changeField($table, $field, $field, 'text');
        }
        $migration->migrationOneTable($table);

        $row = $DB->request([
            'SELECT' => $fields,
            'FROM'   => $table,
            'WHERE'  => ['id' => 1],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($row)) {
            return;
        }

        $glpikey = new GLPIKey();
        $update  = [];
        foreach ($fields as $field) {
            $plain = (string) ($row[$field] ?? '');
            if ($plain === '') {
                continue;
            }
            $encrypted = $glpikey->encrypt($plain);
            if ($encrypted === '') {
                // Clé de chiffrement GLPI illisible : ni valeur vide (clé API perdue),
                // ni valeur en clair conservée sans le dire — la migration échoue.
                throw new RuntimeException(sprintf(
                    'Print Gestion : impossible de chiffrer la colonne %s (clé de chiffrement GLPI illisible). Migration interrompue.',
                    $field
                ));
            }
            $update[$field] = $encrypted;
        }
        if (!empty($update)) {
            $DB->update($table, $update, ['id' => 1]);
        }
    }

    /**
     * 1.2.0 — cycle d'expédition : « livrée » ne clôt plus un envoi, seule la pose.
     * Nouveaux statuts « installed » (posée : pose détectée ou confirmée) et
     * « cancelled » (annulée, sans suppression) ; colonne date_installed.
     * Données existantes : les expéditions « delivered » passent « installed » (date de
     * pose = date de livraison, à défaut d'expédition ou de commande) — décision
     * projet : ne bloquer aucune machine sur un historique dont la pose est inconnue.
     */
    private static function migrateTo120(Migration $migration): void {
        global $DB;

        $table = 'glpi_plugin_printgestion_expeditions';

        $migration->changeField(
            $table,
            'statut',
            'statut',
            "enum('pending','shipped','transit','delivered','stock_empty','installed','cancelled') NOT NULL DEFAULT 'pending'"
        );
        $migration->addField($table, 'date_installed', 'timestamp NULL DEFAULT NULL', ['after' => 'date_delivered']);
        $migration->migrationOneTable($table);

        $DB->doQuery(
            "UPDATE `{$table}`
                SET `statut` = 'installed',
                    `date_installed` = COALESCE(`date_delivered`, `date_shipped`, `date_alert`, NOW())
              WHERE `statut` = 'delivered'"
        );
    }

    /**
     * 1.2.1 — unicité garantie par la base : au plus UN envoi en cours par
     * (imprimante, toner). MariaDB et MySQL n'ont pas d'index unique partiel : la
     * colonne générée active_lock vaut 1 pour un envoi en cours, NULL pour un envoi
     * posé ou annulé ; la clé unique (printers_id, toner_property, active_lock) ignore
     * les NULL. Tout statut futur est considéré « en cours » par défaut (sûr).
     * Doublons existants : l'envoi le plus récent reste en cours, les plus anciens
     * passent « annulée » avec une note explicative — aucune ligne supprimée.
     */
    private static function migrateTo121(Migration $migration): void {
        global $DB;

        $table  = 'glpi_plugin_printgestion_expeditions';
        $active = ['pending', 'stock_empty', 'shipped', 'transit', 'delivered'];

        // 1. Doublons d'envois en cours déjà présents (sinon la clé unique échouerait).
        $closed = 0;
        foreach ($DB->request([
            'SELECT'  => [
                'printers_id',
                'toner_property',
                new \QueryExpression('MAX(`id`) AS `keep_id`'),
                new \QueryExpression('COUNT(*) AS `cnt`'),
            ],
            'FROM'    => $table,
            'WHERE'   => [
                'statut' => $active,
                'NOT'    => ['toner_property' => null],
            ],
            'GROUPBY' => ['printers_id', 'toner_property'],
            'HAVING'  => ['cnt' => ['>', 1]],
        ]) as $dup) {
            foreach ($DB->request([
                'SELECT' => ['id', 'notes'],
                'FROM'   => $table,
                'WHERE'  => [
                    'printers_id'    => (int) $dup['printers_id'],
                    'toner_property' => (string) $dup['toner_property'],
                    'statut'         => $active,
                    'id'             => ['<', (int) $dup['keep_id']],
                ],
            ]) as $old) {
                $DB->update($table, [
                    'statut' => 'cancelled',
                    'notes'  => trim((string) ($old['notes'] ?? '') . "\n[" . date('Y-m-d H:i') . '] '
                        . 'Migration 1.2.1 : doublon d\'un envoi en cours pour la même imprimante et le même toner, '
                        . 'clôturé « annulée ». Envoi conservé en cours : #' . (int) $dup['keep_id']),
                ], ['id' => (int) $old['id']]);
                $closed++;
            }
        }
        if ($closed > 0) {
            $migration->displayMessage(sprintf(
                'Print Gestion — %d envoi(s) en double clôturé(s) « annulée » (motif dans la note de chaque expédition).',
                $closed
            ));
        }

        // 2. Colonne générée, clé unique, index de recherche (imprimante, toner, statut).
        $migration->addField(
            $table,
            'active_lock',
            "tinyint GENERATED ALWAYS AS (IF(`statut` IN ('installed','cancelled'), NULL, 1)) STORED"
        );
        $migration->migrationOneTable($table);
        $migration->addKey($table, ['printers_id', 'toner_property', 'active_lock'], 'uniq_active_slot', 'UNIQUE');
        $migration->addKey($table, ['printers_id', 'toner_property', 'statut'], 'idx_slot_statut');
        $migration->migrationOneTable($table);
    }

    /**
     * 1.2.2 — verrous anti-double-envoi.
     * Paramètres : garde après pose (jours), seuil de contournement (%), délai d'un
     * ticket récent (jours). Historique des cartouches : is_detected distingue une pose
     * réellement détectée (hausse de niveau) des lignes d'amorçage, datées du premier
     * inventaire, qui ne doivent pas ouvrir de garde ; index de recherche associé.
     * Les poses antérieures à cette étape restent à 0 : au pire, pas de garde pendant
     * les guard_days jours qui suivent la mise à jour.
     */
    private static function migrateTo122(Migration $migration): void {
        $config = 'glpi_plugin_printgestion_configs';
        $migration->addField($config, 'guard_days', "int NOT NULL DEFAULT '5'");
        $migration->addField($config, 'guard_bypass_level', "int NOT NULL DEFAULT '10'");
        $migration->addField($config, 'guard_ticket_days', "int NOT NULL DEFAULT '10'");
        $migration->migrationOneTable($config);

        $history = 'glpi_plugin_printgestion_cartridge_history';
        $migration->addField($history, 'is_detected', "tinyint NOT NULL DEFAULT '0'");
        $migration->migrationOneTable($history);
        $migration->addKey($history, ['printers_id', 'toner_property', 'is_detected', 'date_install'], 'idx_slot_install');
        $migration->migrationOneTable($history);
    }

    /**
     * 1.3.0 — sous contrat / hors contrat : types de contrat natifs (ContractType)
     * « consommables inclus », liste d'IDs séparés par des virgules. Vide par défaut :
     * tant que rien n'est paramétré, toute ligne est hors contrat (jamais de prix 0).
     */
    private static function migrateTo130(Migration $migration): void {
        $config = 'glpi_plugin_printgestion_configs';
        $migration->addField($config, 'consumables_contracttypes', 'text DEFAULT NULL');
        $migration->migrationOneTable($config);
    }

    /**
     * 1.3.1 — objet « Demande d'envoi » : en-tête (client = entité, site de livraison =
     * lieu racine, mode de livraison, contact, commentaire, validation, annulation) et
     * lignes (imprimante, toner, cartouche, quantité, prix unitaire, contrat).
     * Statuts : proposed → validated → exported → shipped → delivered → installed, plus
     * cancelled — aucune suppression.
     * Unicité garantie par la base, même principe que les expéditions (étape 1.2.1) :
     *  - au plus UNE ligne ouverte (proposée ou validée) par (imprimante, toner) :
     *    colonne générée active_lock, clé unique uniq_active_slot ;
     *  - au plus UNE demande proposée par (entité, site) : colonne générée
     *    proposal_lock, clé unique uniq_open_proposal (regroupement automatique).
     * Liste des statuts volontairement figée ici (étape livrée = SQL immuable).
     */
    private static function migrateTo131(Migration $migration): void {
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $statuses  = "'proposed','validated','exported','shipped','delivered','installed','cancelled'";

        if (!$DB->tableExists('glpi_plugin_printgestion_demandes')) {
            $migration->displayMessage('Print Gestion — création de la table des demandes d\'envoi');
            $DB->doQuery("CREATE TABLE `glpi_plugin_printgestion_demandes` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) DEFAULT NULL,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `locations_id` int {$sign} NOT NULL DEFAULT '0',
                `statut` enum({$statuses}) NOT NULL DEFAULT 'proposed',
                `delivery_mode` enum('direct','technician') NOT NULL DEFAULT 'direct',
                `contact` varchar(255) DEFAULT NULL,
                `delivery_comment` text,
                `users_id_validate` int {$sign} NOT NULL DEFAULT '0',
                `date_validate` timestamp NULL DEFAULT NULL,
                `users_id_cancel` int {$sign} NOT NULL DEFAULT '0',
                `date_cancel` timestamp NULL DEFAULT NULL,
                `cancel_reason` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `proposal_lock` tinyint GENERATED ALWAYS AS (IF(`statut` = 'proposed', 1, NULL)) STORED,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_open_proposal` (`entities_id`, `locations_id`, `proposal_lock`),
                KEY `locations_id` (`locations_id`),
                KEY `statut` (`statut`),
                KEY `users_id_validate` (`users_id_validate`),
                KEY `users_id_cancel` (`users_id_cancel`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }

        if (!$DB->tableExists('glpi_plugin_printgestion_demandelines')) {
            $migration->displayMessage('Print Gestion — création de la table des lignes de demande d\'envoi');
            $DB->doQuery("CREATE TABLE `glpi_plugin_printgestion_demandelines` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_printgestion_demandes_id` int {$sign} NOT NULL DEFAULT '0',
                `printers_id` int {$sign} NOT NULL DEFAULT '0',
                `toner_property` varchar(255) NOT NULL DEFAULT '',
                `cartridgeitems_id` int {$sign} NOT NULL DEFAULT '0',
                `quantity` int NOT NULL DEFAULT '1',
                `unit_price` decimal(20,4) DEFAULT NULL,
                `is_under_contract` tinyint NOT NULL DEFAULT '0',
                `contracts_id` int {$sign} NOT NULL DEFAULT '0',
                `level_at_proposal` int DEFAULT NULL,
                `estimated_days` int DEFAULT NULL,
                `statut` enum({$statuses}) NOT NULL DEFAULT 'proposed',
                `expeditions_id` int {$sign} NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `active_lock` tinyint GENERATED ALWAYS AS (IF(`statut` IN ('proposed','validated'), 1, NULL)) STORED,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_active_slot` (`printers_id`, `toner_property`, `active_lock`),
                KEY `plugin_printgestion_demandes_id` (`plugin_printgestion_demandes_id`),
                KEY `idx_slot_statut` (`printers_id`, `toner_property`, `statut`),
                KEY `cartridgeitems_id` (`cartridgeitems_id`),
                KEY `contracts_id` (`contracts_id`),
                KEY `expeditions_id` (`expeditions_id`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
    }

    /**
     * 1.4.0 — référentiel Sage importé par fichier (aucune liaison directe avec Sage) :
     *  - sageclients        : clients Sage (code, intitulé) ;
     *  - entitysageclients  : correspondance entité GLPI → client Sage, propre au plugin
     *                         (aucun champ natif ne convient, registration_number est le
     *                         SIRET) ; une entité a au plus un client, un client peut
     *                         couvrir plusieurs entités ;
     *  - sagedeliveries     : adresses de livraison, plusieurs par client, rapprochées des
     *                         lieux GLPI par Location.code ;
     *  - sagearticles       : articles, rapprochés des cartouches par CartridgeItem.ref ;
     *  - sageimports        : trace de chaque import (type, fichier, auteur, volumes).
     * Une ligne absente d'un import suivant n'est jamais supprimée : is_in_last_import = 0.
     */
    private static function migrateTo140(Migration $migration): void {
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $options   = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        $tables = [
            'glpi_plugin_printgestion_sageclients' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `code` varchar(255) NOT NULL DEFAULT '',
                `name` varchar(255) NOT NULL DEFAULT '',
                `is_in_last_import` tinyint NOT NULL DEFAULT '1',
                `date_import` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `code` (`code`),
                KEY `is_in_last_import` (`is_in_last_import`)",
            'glpi_plugin_printgestion_entitysageclients' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `plugin_printgestion_sageclients_id` int {$sign} NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `entities_id` (`entities_id`),
                KEY `plugin_printgestion_sageclients_id` (`plugin_printgestion_sageclients_id`)",
            'glpi_plugin_printgestion_sagedeliveries' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `client_code` varchar(255) NOT NULL DEFAULT '',
                `address_key` varchar(255) NOT NULL DEFAULT '',
                `label` varchar(255) NOT NULL DEFAULT '',
                `address` text,
                `postcode` varchar(255) DEFAULT NULL,
                `town` varchar(255) DEFAULT NULL,
                `is_in_last_import` tinyint NOT NULL DEFAULT '1',
                `date_import` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `client_address` (`client_code`, `address_key`),
                KEY `address_key` (`address_key`),
                KEY `is_in_last_import` (`is_in_last_import`)",
            'glpi_plugin_printgestion_sagearticles' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `ref` varchar(255) NOT NULL DEFAULT '',
                `label` varchar(255) DEFAULT NULL,
                `is_in_last_import` tinyint NOT NULL DEFAULT '1',
                `date_import` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ref` (`ref`),
                KEY `is_in_last_import` (`is_in_last_import`)",
            'glpi_plugin_printgestion_sageimports' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `type` enum('clients','deliveries','articles') NOT NULL,
                `filename` varchar(255) NOT NULL DEFAULT '',
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `nb_created` int NOT NULL DEFAULT '0',
                `nb_updated` int NOT NULL DEFAULT '0',
                `nb_unchanged` int NOT NULL DEFAULT '0',
                `nb_absent` int NOT NULL DEFAULT '0',
                `nb_linked` int NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `type` (`type`),
                KEY `users_id` (`users_id`),
                KEY `date_creation` (`date_creation`)",
        ];

        foreach ($tables as $table => $definition) {
            if (!$DB->tableExists($table)) {
                $migration->displayMessage(sprintf('Print Gestion — création de la table %s', $table));
                $DB->doQuery("CREATE TABLE `{$table}` ({$definition}\n            ) {$options}");
            }
        }
    }

    /**
     * 1.4.1 — fichier Gesconso : séparateur de la colonne Designation (espaces compris,
     * « # » entouré d'espaces comme le fichier réel) et longueur maximale de la
     * désignation (69 par défaut, limite usuelle de Sage ; 67 caractères prouvés).
     */
    private static function migrateTo141(Migration $migration): void {
        $config = 'glpi_plugin_printgestion_configs';
        $migration->addField($config, 'gesconso_separator', "varchar(20) NOT NULL DEFAULT ' # '");
        $migration->addField($config, 'gesconso_designation_max', "int NOT NULL DEFAULT '69'");
        $migration->migrationOneTable($config);
    }

    /**
     * 1.5.0 — règles de lecture SNMP par constructeur : ignorer ou inverser une propriété
     * (motif avec *), constructeur 0 = tous. Table vide par défaut : les sentinelles et les
     * états bruts sont gérés sans règle. Table renommée snmprules en 1.5.5.
     */
    private static function migrateTo150(Migration $migration): void {
        global $DB;

        $table = 'glpi_plugin_printgestion_snmpadapters';
        if ($DB->tableExists($table)) {
            return;
        }
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $migration->displayMessage('Print Gestion — création de la table des règles de lecture SNMP');
        $DB->doQuery("CREATE TABLE `{$table}` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `manufacturers_id` int {$sign} NOT NULL DEFAULT '0',
            `property_pattern` varchar(255) NOT NULL DEFAULT '',
            `action` enum('ignore','invert') NOT NULL DEFAULT 'ignore',
            `comment` varchar(255) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `manufacturers_id` (`manufacturers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    /**
     * 1.5.1 — relevés « niveau figé » conservés et marqués (is_suspect = 1) au lieu d'être
     * écartés : niveau inchangé alors que l'imprimante a imprimé. Relevés existants : 0.
     */
    private static function migrateTo151(Migration $migration): void {
        $table = 'glpi_plugin_printgestion_toner_readings';
        $migration->addField($table, 'is_suspect', "tinyint NOT NULL DEFAULT '0'");
        $migration->migrationOneTable($table);
    }

    /**
     * 1.5.2 — collecte SNMP : délai (jours) sans inventaire au-delà duquel une imprimante ou
     * un agent est signalé muet.
     */
    private static function migrateTo152(Migration $migration): void {
        $config = 'glpi_plugin_printgestion_configs';
        $migration->addField($config, 'silent_days', "int NOT NULL DEFAULT '3'");
        $migration->migrationOneTable($config);
    }

    /**
     * 1.5.3 — notifications natives des demandes d'envoi : délai de relance (jours, 0 =
     * désactivé) et date de la dernière relance de chaque demande.
     */
    private static function migrateTo153(Migration $migration): void {
        $config = 'glpi_plugin_printgestion_configs';
        $migration->addField($config, 'demande_reminder_days', "int NOT NULL DEFAULT '2'");
        $migration->migrationOneTable($config);

        $demandes = 'glpi_plugin_printgestion_demandes';
        $migration->addField($demandes, 'date_last_reminder', 'timestamp NULL DEFAULT NULL');
        $migration->migrationOneTable($demandes);
    }

    /**
     * 1.5.4 — écran des alertes sur le moteur de recherche natif : la table matérialisée
     * alertview porte aussi le verrou anti-double-envoi, la référence non résolue, le stock
     * GLPI, le niveau figé suspect et le statut de l'envoi en cours. Table recalculée par la
     * tâche horaire : colonnes vides jusqu'au prochain calcul.
     */
    private static function migrateTo154(Migration $migration): void {
        $table = 'glpi_plugin_printgestion_alertview';
        $migration->addField($table, 'lock_reason', 'varchar(20) DEFAULT NULL');
        $migration->addField($table, 'lock_message', 'text DEFAULT NULL');
        $migration->addField($table, 'ref_error', 'text DEFAULT NULL');
        $migration->addField($table, 'stock', "int NOT NULL DEFAULT '0'");
        $migration->addField($table, 'level_suspect', "tinyint NOT NULL DEFAULT '0'");
        $migration->addField($table, 'expedition_statut', 'varchar(20) DEFAULT NULL');
        $migration->migrationOneTable($table);
    }

    /**
     * 1.5.5 — règles de lecture SNMP portées par leur propre classe (PluginPrintgestionSnmprule) :
     * table snmpadapters renommée snmprules, règles conservées. Le service de lecture des
     * niveaux (PluginPrintgestionSnmpadapter) n'a plus de table.
     */
    private static function migrateTo155(Migration $migration): void {
        global $DB;

        $old = 'glpi_plugin_printgestion_snmpadapters';
        $new = 'glpi_plugin_printgestion_snmprules';
        if ($DB->tableExists($new)) {
            return;
        }
        if ($DB->tableExists($old)) {
            $migration->displayMessage('Print Gestion — table des règles de lecture SNMP renommée');
            $migration->renameTable($old, $new);
            return;
        }
        // Table disparue entre-temps : recréée vide, avec la définition de l'étape 1.5.0.
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $migration->displayMessage('Print Gestion — création de la table des règles de lecture SNMP');
        $DB->doQuery("CREATE TABLE `{$new}` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `manufacturers_id` int {$sign} NOT NULL DEFAULT '0',
            `property_pattern` varchar(255) NOT NULL DEFAULT '',
            `action` enum('ignore','invert') NOT NULL DEFAULT 'ignore',
            `comment` varchar(255) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `manufacturers_id` (`manufacturers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    /**
     * 1.5.6 — réattribution « mauvaise imprimante » manuelle uniquement : le délai de
     * réattribution automatique n'existe plus.
     */
    private static function migrateTo156(Migration $migration): void {
        $config = 'glpi_plugin_printgestion_configs';
        $migration->dropField($config, 'wrong_printer_auto_reassign_days');
        $migration->migrationOneTable($config);
    }

    /**
     * 1.5.7 — aucun stock GLPI : le stock est dans Sage, que le plugin ne lit pas.
     * Les envois « stock vide » repassent « en attente » (ils sont commandés, rien de plus),
     * avec une note dans chaque expédition, puis le statut disparaît. Retirés aussi : la
     * colonne stock de la table des alertes et sa préférence d'affichage, le type d'alerte
     * « stock_empty » (jamais écrit) et la mention « stock vide » du gabarit du mail aux
     * Achats, qui est la commande quel que soit le stock.
     */
    private static function migrateTo157(Migration $migration): void {
        global $DB;

        $table = 'glpi_plugin_printgestion_expeditions';
        $moved = 0;
        foreach ($DB->request([
            'SELECT' => ['id', 'notes'],
            'FROM'   => $table,
            'WHERE'  => ['statut' => 'stock_empty'],
        ]) as $row) {
            $DB->update($table, [
                'statut' => 'pending',
                'notes'  => trim((string) ($row['notes'] ?? '') . "\n[" . date('Y-m-d H:i') . '] '
                    . 'Migration 1.5.7 : statut « stock vide » supprimé (aucun stock GLPI), envoi repassé « en attente ».'),
            ], ['id' => (int) $row['id']]);
            $moved++;
        }
        if ($moved > 0) {
            $migration->displayMessage(sprintf(
                'Print Gestion — %d envoi(s) « stock vide » repassé(s) « en attente » (note dans chaque expédition).',
                $moved
            ));
        }
        $migration->changeField(
            $table,
            'statut',
            'statut',
            "enum('pending','shipped','transit','delivered','installed','cancelled') NOT NULL DEFAULT 'pending'"
        );
        $migration->migrationOneTable($table);

        $alerts = 'glpi_plugin_printgestion_alerts';
        if (countElementsInTable($alerts, ['alert_type' => 'stock_empty']) === 0) {
            $migration->changeField(
                $alerts,
                'alert_type',
                'alert_type',
                "enum('low_toner','no_install_reminder','contract_expiry','wrong_printer') NOT NULL"
            );
            $migration->migrationOneTable($alerts);
        } else {
            $migration->displayMessage('Print Gestion — type d\'alerte « stock_empty » conservé : des alertes le portent.');
        }

        $alertview = 'glpi_plugin_printgestion_alertview';
        $migration->dropField($alertview, 'stock');
        $migration->migrationOneTable($alertview);
        $DB->delete('glpi_displaypreferences', [
            'itemtype' => 'PluginPrintgestionAlertview',
            'num'      => 14,
        ]);

        $templates = 'glpi_notificationtemplates';
        $marker    = 'Created by plugin printgestion';
        $new_name  = 'Print Gestion - Commande cartouches (Achats)';
        if (countElementsInTable($templates, ['name' => $new_name, 'comment' => $marker]) === 0) {
            $DB->update(
                $templates,
                ['name' => $new_name],
                ['name' => 'Print Gestion - Commander cartouche (stock vide)', 'comment' => $marker]
            );
        }
    }

    /**
     * 1.5.8 — mapping SNMP sous les noms réels de l'inventaire GLPI. Les lignes pré-remplies
     * à l'installation sous des libellés qu'aucun inventaire ne produit (« Toner Noir »,
     * « Black Toner Remaining », « developercyan »…) sont retirées si elles sont restées
     * telles quelles (ni type de cartouche, ni constructeur) et qu'aucune imprimante ne
     * remonte cette propriété ; les kits sont remplacés par leur nom réel. Une ligne
     * modifiée par l'administrateur est conservée. Listes figées ici.
     */
    private static function migrateTo158(Migration $migration): void {
        global $DB;

        $table        = 'glpi_plugin_printgestion_snmp_mapping';
        $replacements = [
            'Kit unité de fusion' => 'fuserkit',
            'Kit de transfert'    => 'transferkit',
            "Kit d'entretien"     => 'maintenancekit',
        ];
        $legacy = array_merge([
            'Toner Noir', 'Black Toner Remaining', 'black-toner-remaining', 'developerblack',
            'Toner Cyan', 'Cyan Toner Remaining', 'cyan-toner-remaining', 'developercyan',
            'Toner Magenta', 'Magenta Toner Remaining', 'magenta-toner-remaining', 'developermagenta',
            'Toner Jaune', 'Yellow Toner Remaining', 'yellow-toner-remaining', 'developeryellow',
        ], array_keys($replacements));

        $removed = 0;
        $added   = 0;
        foreach ($DB->request([
            'SELECT' => ['id', 'snmp_property'],
            'FROM'   => $table,
            'WHERE'  => [
                'snmp_property' => $legacy,
                'OR'            => [['cartridgeitemtypes_id' => null], ['cartridgeitemtypes_id' => 0]],
                ['OR' => [['manufacturer' => null], ['manufacturer' => '']]],
                ['OR' => [['cartridge_type' => null], ['cartridge_type' => '']]],
            ],
        ]) as $row) {
            if (countElementsInTable('glpi_printers_cartridgeinfos', ['property' => $row['snmp_property']]) > 0) {
                continue; // remontée par au moins une imprimante : conservée
            }
            $DB->delete($table, ['id' => (int) $row['id']]);
            $removed++;

            $real = $replacements[(string) $row['snmp_property']] ?? null;
            if ($real !== null && countElementsInTable($table, ['snmp_property' => $real]) === 0) {
                $DB->insert($table, ['snmp_property' => $real, 'toner_color' => 'other']);
                $added++;
            }
        }
        if ($removed > 0) {
            $migration->displayMessage(sprintf(
                'Print Gestion — mapping SNMP : %d ligne(s) pré-remplie(s) sous un libellé jamais remonté retirée(s), %d kit(s) ajouté(s) sous leur nom réel.',
                $removed,
                $added
            ));
        }
    }

    /**
     * 1.5.9 — modules et droits séparés : « Collecte SNMP / Déploiement Agent » (droit
     * plugin_printgestion_deploiement) et « Référentiel Sage » (droit plugin_printgestion_sage,
     * jusqu'ici couvert par le droit de configuration du plugin), chacun avec son interrupteur.
     * Personne ne perd un accès : le module Sage reprend l'état du module toner dont il
     * dépendait ; le droit Sage reprend le droit de configuration (lecture → lecture,
     * modification → lecture et modification) ; le droit Déploiement est donné en lecture et
     * modification à qui modifiait la configuration. Noms figés ici.
     */
    private static function migrateTo159(Migration $migration): void {
        global $DB;

        $config          = 'glpi_plugin_printgestion_configs';
        $had_sage_switch = $DB->fieldExists($config, 'enable_sage');
        $migration->addField($config, 'enable_deploiement', "tinyint NOT NULL DEFAULT '1'");
        $migration->addField($config, 'enable_sage', "tinyint NOT NULL DEFAULT '1'");
        $migration->migrationOneTable($config);
        if (!$had_sage_switch && $DB->fieldExists($config, 'enable_toner')) {
            $DB->doQuery("UPDATE `{$config}` SET `enable_sage` = `enable_toner`");
        }

        foreach (['plugin_printgestion_deploiement', 'plugin_printgestion_sage'] as $name) {
            if (countElementsInTable('glpi_profilerights', ['name' => $name]) === 0) {
                // Une ligne à 0 par profil ; vide aussi le cache des droits possibles.
                ProfileRight::addProfileRights([$name]);
            }
        }
        foreach ($DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'plugin_printgestion_config'],
        ]) as $row) {
            $config_rights = (int) $row['rights'];
            $grants        = [
                'plugin_printgestion_sage'        => ($config_rights & UPDATE) ? (READ | UPDATE) : ($config_rights & READ),
                'plugin_printgestion_deploiement' => ($config_rights & UPDATE) ? (READ | UPDATE) : 0,
            ];
            foreach ($grants as $name => $rights) {
                if ($rights > 0) {
                    // Seulement une ligne encore à 0 : un droit déjà réglé n'est pas écrasé.
                    $DB->update('glpi_profilerights', ['rights' => $rights], [
                        'profiles_id' => (int) $row['profiles_id'],
                        'name'        => $name,
                        'rights'      => 0,
                    ]);
                }
            }
        }
    }

    /**
     * 1.6.0 — Déploiement Agent : réglages de l'installeur GLPI Agent servi par le plugin.
     * Vides, ils prennent la valeur automatique : version connue du plugin, URL de GLPI
     * (point d'entrée du plugin GLPI Inventory s'il est actif), aucune adresse autorisée à
     * réveiller l'agent en plus du poste lui-même.
     */
    private static function migrateTo160(Migration $migration): void {
        $config = 'glpi_plugin_printgestion_configs';
        $migration->addField($config, 'agent_version', 'varchar(20) DEFAULT NULL');
        $migration->addField($config, 'agent_server_url', 'varchar(255) DEFAULT NULL');
        $migration->addField($config, 'agent_httpd_trust', 'varchar(255) DEFAULT NULL');
        $migration->migrationOneTable($config);
    }

    /**
     * 1.6.1 — Déploiement Agent, assistant de raccordement des imprimantes : le raccordement
     * (entité, sonde, avancement, objets GLPI Inventory utilisés ou créés), les adresses déclarées
     * avec leur résultat, et le journal horodaté de l'exécution. Rien n'est supprimé : un
     * raccordement abandonné garde ses adresses et son journal.
     * Statuts et résultats volontairement figés ici (étape livrée = SQL immuable).
     */
    private static function migrateTo161(Migration $migration): void {
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $options   = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        if (!$DB->tableExists('glpi_plugin_printgestion_raccordements')) {
            $migration->displayMessage('Print Gestion — création de la table des raccordements d\'imprimantes');
            $DB->doQuery("CREATE TABLE `glpi_plugin_printgestion_raccordements` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `agents_id` int {$sign} NOT NULL DEFAULT '0',
                `status` enum('open','configured','triggered','closed','abandoned') NOT NULL DEFAULT 'open',
                `snmpcredentials_id` int {$sign} NOT NULL DEFAULT '0',
                `ipranges` text,
                `discovery_tasks` text,
                `inventory_tasks` text,
                `created_items` text,
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_configured` timestamp NULL DEFAULT NULL,
                `date_triggered` timestamp NULL DEFAULT NULL,
                `date_inventory_prepared` timestamp NULL DEFAULT NULL,
                `date_verified` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `agents_id` (`agents_id`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) {$options}");
        }

        if (!$DB->tableExists('glpi_plugin_printgestion_raccordementips')) {
            $migration->displayMessage('Print Gestion — création de la table des adresses de raccordement');
            $DB->doQuery("CREATE TABLE `glpi_plugin_printgestion_raccordementips` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_printgestion_raccordements_id` int {$sign} NOT NULL DEFAULT '0',
                `ip` varchar(15) NOT NULL DEFAULT '',
                `ip_num` int unsigned NOT NULL DEFAULT '0',
                `result` enum('pending','waiting_discovery','waiting_inventory','found','no_levels','no_snmp','not_printer','wrong_entity') NOT NULL DEFAULT 'pending',
                `itemtype` varchar(100) DEFAULT NULL,
                `items_id` int {$sign} NOT NULL DEFAULT '0',
                `items_entities_id` int {$sign} DEFAULT NULL,
                `date_check` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ip` (`plugin_printgestion_raccordements_id`, `ip_num`),
                KEY `result` (`result`),
                KEY `item` (`itemtype`, `items_id`)
            ) {$options}");
        }

        if (!$DB->tableExists('glpi_plugin_printgestion_raccordementlogs')) {
            $migration->displayMessage('Print Gestion — création du journal des raccordements');
            $DB->doQuery("CREATE TABLE `glpi_plugin_printgestion_raccordementlogs` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plugin_printgestion_raccordements_id` int {$sign} NOT NULL DEFAULT '0',
                `date` timestamp NULL DEFAULT NULL,
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `step` tinyint NOT NULL DEFAULT '0',
                `level` enum('info','success','warning','error') NOT NULL DEFAULT 'info',
                `message` text,
                PRIMARY KEY (`id`),
                KEY `raccordement_date` (`plugin_printgestion_raccordements_id`, `date`),
                KEY `users_id` (`users_id`)
            ) {$options}");
        }
    }
}
