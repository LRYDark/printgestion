<?php
/**
 * PluginPrintgestionPrinterrate — tarif à la page propre à une imprimante, prioritaire sur celui de son contrat.
 *
 * Le tarif N&B / couleur se règle d'abord par contrat (PluginPrintgestionContractrate, fiche du contrat). Une
 * imprimante peut avoir le sien, type par type : un tarif N&B saisi sur l'imprimante remplace celui du contrat, un
 * champ laissé vide garde celui du contrat. getRatesForPrinter() est le seul endroit qui décide du tarif d'une
 * imprimante : facturation, export Excel et onglet « Coût à la page » passent tous par lui.
 *
 * Table créée au premier besoin (PluginPrintgestionSchema::createIfMissing()), sans « Mettre à jour ».
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionPrinterrate extends CommonDBTM {

    static $rightname = 'plugin_printgestion_contrats';

    /** Tarifs propres déjà lus : printers_id => ['nb' => ?float, 'color' => ?float]. */
    private static ?array $overrides = null;

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_printerrates';
        }
        return parent::getTable($classname);
    }

    static function getTypeName($nb = 0) {
        return _n('Tarif d\'imprimante', 'Tarifs d\'imprimante', $nb, 'printgestion');
    }

    /** Table assurée (créée si elle manque) ; faux si sa création a échoué (cause dans le journal du plugin). */
    public static function hasTable(): bool {
        static $ok = null;
        if ($ok === null) {
            try {
                PluginPrintgestionSchema::createIfMissing(self::getTable());
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
                PluginPrintgestionLogger::error('cout', 'Table des tarifs d\'imprimante non créée.', $e);
            }
        }
        return $ok;
    }

    /** Tarifs propres de toutes les imprimantes, lus en une requête (printers_id => ['nb' => ?float, 'color' => ?float]). */
    private static function getOverrides(): array {
        global $DB;

        if (self::$overrides === null) {
            self::$overrides = [];
            if (self::hasTable()) {
                foreach ($DB->request(['SELECT' => ['printers_id', 'rate_nb', 'rate_color'], 'FROM' => self::getTable()]) as $row) {
                    self::$overrides[(int) $row['printers_id']] = [
                        'nb'    => $row['rate_nb'] === null ? null : (float) $row['rate_nb'],
                        'color' => $row['rate_color'] === null ? null : (float) $row['rate_color'],
                    ];
                }
            }
        }
        return self::$overrides;
    }

    /** Tarif propre d'une imprimante, type par type (null : celui du contrat). */
    public static function getOverride(int $printers_id): array {
        return self::getOverrides()[$printers_id] ?? ['nb' => null, 'color' => null];
    }

    /**
     * Tarif appliqué à une imprimante : le sien type par type, sinon celui du contrat.
     *
     * @param ?array $contract_rates Tarifs du contrat déjà lus (['nb', 'color']) ; null : lus ici
     * @return array ['nb' => float, 'color' => float, 'nb_own' => bool, 'color_own' => bool]
     */
    public static function getRatesForPrinter(int $printers_id, ?array $contract_rates = null): array {
        $contract_rates ??= PluginPrintgestionContractrate::getRatesForContract(
            PluginPrintgestionContractrate::getContractIdForPrinter($printers_id)
        );
        $own = self::getOverride($printers_id);
        return [
            'nb'        => $own['nb'] ?? (float) $contract_rates['nb'],
            'color'     => $own['color'] ?? (float) $contract_rates['color'],
            'nb_own'    => $own['nb'] !== null,
            'color_own' => $own['color'] !== null,
        ];
    }

    /** Modifier le tarif d'une imprimante : droit d'édition des tarifs (module Contrats) et droit d'écriture sur elle. */
    public static function canEditFor(Printer $printer): bool {
        return Session::haveRight(self::$rightname, UPDATE) && $printer->can((int) $printer->getID(), UPDATE);
    }

    /** Un tarif saisi : vide = celui du contrat (null) ; sinon nombre positif, 6 décimales au plus. false si invalide. */
    public static function parseRate(string $raw): float|null|false {
        $raw = str_replace([' ', "\u{00A0}", "\u{202F}", '€'], '', trim($raw));
        $raw = str_replace(',', '.', $raw);
        if ($raw === '') {
            return null;
        }
        return preg_match('/^\d{1,4}(\.\d{1,6})?$/', $raw) ? (float) $raw : false;
    }

    /**
     * Enregistre le tarif propre d'une imprimante (les deux champs vides : retour au tarif du contrat, ligne retirée).
     *
     * @return string[] erreurs (vide : enregistré)
     */
    public static function saveFor(Printer $printer, string $raw_nb, string $raw_color): array {
        $nb    = self::parseRate($raw_nb);
        $color = self::parseRate($raw_color);
        $errors = [];
        foreach ([[$nb, __('Tarif N&B', 'printgestion')], [$color, __('Tarif Couleur', 'printgestion')]] as [$value, $label]) {
            if ($value === false) {
                $errors[] = sprintf(__('%s : nombre positif attendu (6 décimales au plus), ou vide pour le tarif du contrat.', 'printgestion'), $label);
            }
        }
        if (!empty($errors) || !self::hasTable()) {
            return !empty($errors) ? $errors : [__('Enregistrement impossible (détail dans le journal printgestion).', 'printgestion')];
        }

        $printers_id = (int) $printer->getID();
        $existing    = new self();
        $found       = $existing->getFromDBByCrit(['printers_id' => $printers_id]);
        if ($nb === null && $color === null) {
            if ($found) {
                $existing->delete(['id' => $existing->getID()], true);
            }
        } else {
            $fields = [
                'printers_id' => $printers_id,
                'rate_nb'     => $nb,
                'rate_color'  => $color,
                'users_id'    => (int) Session::getLoginUserID(),
                'entities_id' => (int) $printer->fields['entities_id'],
            ];
            $ok = $found ? $existing->update(['id' => $existing->getID()] + $fields) : (bool) $existing->add($fields);
            if (!$ok) {
                return [__('Enregistrement refusé par GLPI.', 'printgestion')];
            }
        }
        self::$overrides = null;
        return [];
    }
}
