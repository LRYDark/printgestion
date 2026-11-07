<?php
/**
 * PluginPrintgestionToneroverride — « Niveau figé suspect » forcé à la main, par imprimante et toner (action du tableau
 * des alertes toner : Oui, Non ou Automatique).
 *
 * Le calcul (PluginPrintgestionAlert::computeRemainingPagesBased()) juge un niveau figé suspect quand l'imprimante a
 * imprimé l'équivalent d'au moins Tonerreading::FROZEN_PERCENT_STEPS points de cartouche sans que le niveau bouge.
 * Un forçage passe avant ce calcul et tient jusqu'au prochain changement de cartouche de ce toner
 * (PluginPrintgestionCartridgehistory::detectChanges() l'efface), ou jusqu'à « Automatique ».
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionToneroverride {

    const TABLE = 'glpi_plugin_printgestion_toner_overrides';

    /**
     * Forçages en place : « printers_id|propriété » => bool (vrai : suspect forcé, faux : pas suspect forcé).
     *
     * @param ?array $printer_ids restreindre à ces imprimantes (null : toutes)
     */
    public static function getAll(?array $printer_ids = null): array {
        global $DB;

        $criteria = ['SELECT' => ['printers_id', 'toner_property', 'level_suspect'], 'FROM' => self::TABLE];
        if ($printer_ids !== null) {
            $criteria['WHERE'] = ['printers_id' => array_values(array_map('intval', $printer_ids)) ?: [0]];
        }
        $out = [];
        foreach ($DB->request($criteria) as $row) {
            $out[(int) $row['printers_id'] . '|' . (string) $row['toner_property']] = (int) $row['level_suspect'] === 1;
        }
        return $out;
    }

    /** Force (vrai / faux) ou rend au calcul (null) le « niveau figé suspect » d'un toner. */
    public static function set(int $printers_id, string $property, ?bool $suspect): bool {
        global $DB;

        if ($printers_id <= 0 || $property === '') {
            return false;
        }
        if ($suspect === null) {
            return (bool) $DB->delete(self::TABLE, ['printers_id' => $printers_id, 'toner_property' => $property]);
        }
        $fields = [
            'level_suspect' => $suspect ? 1 : 0,
            'users_id'      => (int) Session::getLoginUserID(),
            'date_mod'      => date('Y-m-d H:i:s'),
        ];
        $exists = countElementsInTable(self::TABLE, ['printers_id' => $printers_id, 'toner_property' => $property]) > 0;
        return $exists
            ? (bool) $DB->update(self::TABLE, $fields, ['printers_id' => $printers_id, 'toner_property' => $property])
            : (bool) $DB->insert(self::TABLE, PluginPrintgestionEntityscope::forPrinter($printers_id) + $fields + [
                'printers_id'    => $printers_id,
                'toner_property' => $property,
            ]);
    }

    /** Nouvelle cartouche détectée sur ce toner : le forçage ne vaut plus, le calcul reprend la main. */
    public static function clearForToner(int $printers_id, string $property): void {
        global $DB;

        $DB->delete(self::TABLE, ['printers_id' => $printers_id, 'toner_property' => $property]);
    }
}
