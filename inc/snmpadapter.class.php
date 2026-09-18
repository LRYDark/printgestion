<?php
/**
 * PluginPrintgestionSnmpadapter — service de lecture fiable des niveaux de consommables
 * remontés par l'inventaire SNMP de GLPI (glpi_printers_cartridgeinfos). Classe de service :
 * ni table, ni formulaire, ni droits propres.
 *
 * 1. Sentinelles de la Printer MIB (RFC 3805, prtMarkerSuppliesLevel) :
 *      -1 = autre / non mesurable, -2 = inconnu, -3 = « il en reste » (présent, niveau non
 *      chiffré). Aucune n'est un pourcentage : jamais lue comme 0 % ni comme 100 %.
 * 2. États bruts : une propriété « …max », « …used » ou « …remaining » n'est pas un
 *    emplacement ; quand le pourcentage de l'emplacement manque (ou est une sentinelle),
 *    il est reconstitué : restant / max, ou (max − utilisé) / max.
 * 3. Règles par constructeur (PluginPrintgestionSnmprule, saisies dans la configuration)
 *    pour les écarts constatés sur le parc : ignorer une propriété, ou inverser sa valeur.
 *
 * Données préchargées une fois par requête (toutes les imprimantes) : aucune requête par
 * imprimante dans les boucles des tâches automatiques.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSnmpadapter {

    /** Suffixes d'état brut ajoutés par l'inventaire GLPI au nom de l'emplacement. */
    const STATE_SUFFIXES = ['remaining', 'used', 'max'];

    /** printers_id => [property => valeur brute] */
    private static ?array $raw = null;
    /** printers_id => manufacturers_id */
    private static ?array $manufacturers = null;
    /** Règles actives : [['manufacturers_id', 'pattern' (regex), 'action']] */
    private static ?array $rules = null;

    /** Oublie les données préchargées (après un changement de règles, ou pour relire l'inventaire). */
    public static function resetCache(): void {
        self::$raw           = null;
        self::$manufacturers = null;
        self::$rules         = null;
    }

    /**
     * Lecture d'une valeur brute seule, sans contexte d'imprimante : pourcentage, OK /
     * WARNING, sentinelles. Retour : ['type', 'value' (int|null), 'usable' (bool)].
     * type : percent | ok | warning | sentinel_other | sentinel_unknown | sentinel_some | unknown.
     */
    public static function parseRaw(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return ['type' => 'unknown', 'value' => null, 'usable' => false];
        }
        if (preg_match('/^-\s*([123])$/', $raw, $m)) {
            $types = ['1' => 'sentinel_other', '2' => 'sentinel_unknown', '3' => 'sentinel_some'];
            return ['type' => $types[$m[1]], 'value' => null, 'usable' => false];
        }
        if (preg_match('/^(\d{1,3})\s*%?$/', $raw, $m) && (int) $m[1] <= 100) {
            return ['type' => 'percent', 'value' => (int) $m[1], 'usable' => true];
        }
        $upper = mb_strtoupper($raw);
        if ($upper === 'OK') {
            return ['type' => 'ok', 'value' => null, 'usable' => false];
        }
        if ($upper === 'WARNING' || $upper === 'WARN') {
            return ['type' => 'warning', 'value' => null, 'usable' => false];
        }
        return ['type' => 'unknown', 'value' => null, 'usable' => false];
    }

    /** Propriété d'état brut (…max, …used, …remaining) : son emplacement de base, sinon null. */
    public static function getStateBase(string $property): ?array {
        foreach (self::STATE_SUFFIXES as $suffix) {
            if (strlen($property) > strlen($suffix) && str_ends_with(mb_strtolower($property), $suffix)) {
                return ['base' => substr($property, 0, -strlen($suffix)), 'state' => $suffix];
            }
        }
        return null;
    }

    /**
     * Niveaux lisibles par imprimante et emplacement, règles appliquées, pourcentages
     * reconstitués depuis les états bruts quand il le faut.
     *
     * @param ?array $printer_ids Restreindre à ces imprimantes (null : toutes).
     * @return array printers_id => [property => ['type', 'value', 'usable', 'source' (percent|computed)]]
     *               Les propriétés d'état brut et les propriétés ignorées n'y figurent pas.
     */
    public static function getLevels(?array $printer_ids = null): array {
        self::preload();

        $ids = $printer_ids === null
            ? array_keys(self::$raw)
            : array_values(array_unique(array_map('intval', $printer_ids)));

        $out = [];
        foreach ($ids as $printers_id) {
            $values = self::$raw[$printers_id] ?? [];
            if (empty($values)) {
                continue;
            }

            // Emplacements : propriétés sans suffixe d'état, plus les bases dont seuls les
            // états bruts sont remontés.
            $slots  = [];
            $states = [];
            foreach ($values as $property => $raw) {
                $base = self::getStateBase((string) $property);
                if ($base === null) {
                    $slots[$property] = true;
                } else {
                    $states[$base['base']][$base['state']] = $raw;
                    $slots[$base['base']] = $slots[$base['base']] ?? true;
                }
            }

            foreach (array_keys($slots) as $property) {
                $property = (string) $property;
                $action   = self::ruleFor($printers_id, $property);
                if ($action === PluginPrintgestionSnmprule::ACTION_IGNORE) {
                    continue;
                }

                $parsed = isset($values[$property])
                    ? self::parseRaw((string) $values[$property]) + ['source' => 'percent']
                    : ['type' => 'unknown', 'value' => null, 'usable' => false, 'source' => 'percent'];

                if (!$parsed['usable'] && isset($states[$property]['max'])) {
                    $computed = self::computeFromStates($states[$property]);
                    if ($computed !== null) {
                        $parsed = ['type' => 'percent', 'value' => $computed, 'usable' => true, 'source' => 'computed'];
                    }
                }
                if (!isset($values[$property]) && !$parsed['usable']) {
                    continue; // états bruts inexploitables, pas d'emplacement fantôme
                }

                if ($parsed['usable'] && $action === PluginPrintgestionSnmprule::ACTION_INVERT) {
                    $parsed['value'] = 100 - (int) $parsed['value'];
                }
                $out[$printers_id][$property] = $parsed;
            }
        }
        return $out;
    }

    /**
     * Niveau d'un seul emplacement (mêmes règles que getLevels()). Sans imprimante ni
     * propriété : lecture de la valeur brute seule.
     */
    public static function parse(string $raw, string $property = '', int $printers_id = 0): array {
        if ($property === '' || $printers_id <= 0) {
            return self::parseRaw($raw);
        }
        if (self::getStateBase($property) !== null) {
            return ['type' => 'state', 'value' => null, 'usable' => false];
        }
        $levels = self::getLevels([$printers_id]);
        return $levels[$printers_id][$property]
            ?? ['type' => 'ignored', 'value' => null, 'usable' => false];
    }

    /** Pourcentage depuis les états bruts : restant / max, sinon (max − utilisé) / max. */
    private static function computeFromStates(array $states): ?int {
        $max = self::rawNumber($states['max'] ?? null);
        if ($max === null || $max <= 0) {
            return null;
        }
        $remaining = self::rawNumber($states['remaining'] ?? null);
        if ($remaining === null) {
            $used = self::rawNumber($states['used'] ?? null);
            if ($used === null) {
                return null;
            }
            $remaining = $max - $used;
        }
        if ($remaining < 0) {
            return null; // sentinelle ou incohérence : non chiffrable
        }
        return (int) max(0, min(100, round($remaining * 100 / $max)));
    }

    private static function rawNumber($raw): ?float {
        $raw = trim((string) $raw);
        return preg_match('/^-?\d+(\.\d+)?$/', $raw) ? (float) $raw : null;
    }

    /** Action de la règle la plus précise (constructeur de l'imprimante avant « tous »). */
    private static function ruleFor(int $printers_id, string $property): ?string {
        $manufacturer = self::$manufacturers[$printers_id] ?? 0;
        $generic      = null;
        foreach (self::$rules as $rule) {
            if (!preg_match($rule['pattern'], $property)) {
                continue;
            }
            if ($rule['manufacturers_id'] === $manufacturer && $manufacturer > 0) {
                return $rule['action'];
            }
            if ($rule['manufacturers_id'] === 0 && $generic === null) {
                $generic = $rule['action'];
            }
        }
        return $generic;
    }

    private static function preload(): void {
        global $DB;

        if (self::$raw !== null) {
            return;
        }

        self::$raw = [];
        foreach ($DB->request([
            'SELECT' => ['printers_id', 'property', 'value'],
            'FROM'   => 'glpi_printers_cartridgeinfos',
        ]) as $row) {
            self::$raw[(int) $row['printers_id']][(string) $row['property']] = (string) $row['value'];
        }

        self::$manufacturers = [];
        foreach ($DB->request(['SELECT' => ['id', 'manufacturers_id'], 'FROM' => 'glpi_printers']) as $row) {
            self::$manufacturers[(int) $row['id']] = (int) $row['manufacturers_id'];
        }

        // Table absente (plugin non installé) : aucune règle appliquée.
        self::$rules = [];
        $table       = PluginPrintgestionSnmprule::getTable();
        if ($DB->tableExists($table)) {
            foreach ($DB->request(['FROM' => $table, 'ORDER' => ['id']]) as $row) {
                $pattern = '/^' . str_replace('\*', '.*', preg_quote(trim((string) $row['property_pattern']), '/')) . '$/i';
                self::$rules[] = [
                    'manufacturers_id' => (int) $row['manufacturers_id'],
                    'pattern'          => $pattern,
                    'action'           => (string) $row['action'],
                ];
            }
        }
    }
}
