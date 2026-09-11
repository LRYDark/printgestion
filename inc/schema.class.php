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
}
