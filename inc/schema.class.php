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
}
