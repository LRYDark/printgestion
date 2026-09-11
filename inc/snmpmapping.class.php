<?php
/**
 * PluginPrintgestionSnmpmapping — mapping SNMP par constructeur.
 *
 * Table simple stockant : manufacturer + snmp_property → cartridge_type + toner_color.
 * Utilisée pour savoir quelle cartouche commander quand une propriété toner baisse.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSnmpmapping extends CommonDBTM {

    static $rightname = 'plugin_printgestion_config';

    static function getTypeName($nb = 0) {
        return _n('Mapping SNMP', 'Mappings SNMP', $nb, 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_snmp_mapping';
        }
        return parent::getTable($classname);
    }

    /**
     * Pré-remplit la table avec les mappings par défaut observés chez JCD Groupe.
     * Ne fait rien si la table contient déjà des entrées.
     */
    /**
     * Détecte automatiquement la couleur d'un toner depuis le nom de la propriété SNMP
     * ou le nom d'un type de cartouche. Utilisé pour colorer la barre de progression
     * dans le dashboard alertes (pas de logique métier dessus).
     */
    public static function detectColor(string $text): string {
        $lower = mb_strtolower($text);
        if (str_contains($lower, 'noir') || str_contains($lower, 'black')) {
            return 'black';
        }
        if (str_contains($lower, 'cyan')) {
            return 'cyan';
        }
        if (str_contains($lower, 'magenta')) {
            return 'magenta';
        }
        if (str_contains($lower, 'jaune') || str_contains($lower, 'yellow')) {
            return 'yellow';
        }
        return 'other';
    }

    public static function seedDefaults(): void {
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return;
        }

        $count = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
        ])->current();

        if (is_array($count) && (int)($count['cpt'] ?? 0) > 0) {
            return;
        }

        // Une ligne par propriété SNMP UNIQUE, peu importe le constructeur.
        // Les variantes constructeur pointent souvent vers le même type logique
        // (ex: "Toner Noir", "developerblack", "tonerblack" = toner noir générique).
        $default = [
            // Toner noir — variantes constructeur
            'Toner Noir'              => 'black',
            'tonerblack'              => 'black',
            'developerblack'          => 'black',
            'Black Toner Remaining'   => 'black',
            'black-toner-remaining'   => 'black',
            // Toner cyan
            'Toner Cyan'              => 'cyan',
            'tonercyan'               => 'cyan',
            'developercyan'           => 'cyan',
            'Cyan Toner Remaining'    => 'cyan',
            'cyan-toner-remaining'    => 'cyan',
            // Toner magenta
            'Toner Magenta'           => 'magenta',
            'tonermagenta'            => 'magenta',
            'developermagenta'        => 'magenta',
            'Magenta Toner Remaining' => 'magenta',
            'magenta-toner-remaining' => 'magenta',
            // Toner jaune
            'Toner Jaune'             => 'yellow',
            'toneryellow'             => 'yellow',
            'developeryellow'         => 'yellow',
            'Yellow Toner Remaining'  => 'yellow',
            'yellow-toner-remaining'  => 'yellow',
            // Kits (tambour, fusion, transfert, entretien)
            'Kit unité de fusion'     => 'other',
            'Kit de transfert'        => 'other',
            "Kit d'entretien"         => 'other',
        ];

        foreach ($default as $property => $color) {
            $DB->insert(self::getTable(), [
                'snmp_property' => $property,
                'toner_color'   => $color,
            ]);
        }
    }

    /**
     * Résout le cartridgeitems_id à utiliser pour une imprimante + propriété SNMP.
     *
     * Cascade de priorités (du plus précis au plus large) :
     *   1. Type GLPI défini dans le mapping + compatible avec le modèle d'imprimante
     *      (via glpi_cartridgeitems_printermodels) + même entité
     *   2. Type GLPI défini dans le mapping + compatible avec le modèle d'imprimante
     *   3. Type GLPI défini dans le mapping uniquement (n'importe quelle imprimante)
     *   4. Compatible avec le modèle d'imprimante + nom matchant cartridge_type (LIKE)
     *   5. Nom matchant cartridge_type (LIKE) — fallback historique
     *
     * Retourne l'ID cartridgeitems ou 0 si rien trouvé.
     */
    public static function resolveCartridgeItemForSnmp(int $printers_id, string $property): int {
        global $DB;

        // Récupère modèle imprimante + entité (utilisé par toutes les stratégies)
        $printerRow = $DB->request([
            'SELECT' => ['printermodels_id', 'entities_id'],
            'FROM'   => 'glpi_printers',
            'WHERE'  => ['id' => $printers_id],
            'LIMIT'  => 1,
        ])->current();

        $printermodels_id = is_array($printerRow) ? (int)($printerRow['printermodels_id'] ?? 0) : 0;
        $entities_id      = is_array($printerRow) ? (int)($printerRow['entities_id'] ?? 0)      : 0;

        // ══════════════════════════════════════════════════════════════
        //  PRIORITÉ 0 : binding direct cartouche ↔ propriété SNMP
        //  (défini depuis l'onglet Print Gestion de la fiche CartridgeItem).
        //  On restreint aux cartouches compatibles avec le modèle imprimante.
        // ══════════════════════════════════════════════════════════════
        if ($printermodels_id > 0) {
            $row = $DB->request([
                'SELECT'     => ['ci.id'],
                'FROM'       => 'glpi_cartridgeitems AS ci',
                'INNER JOIN' => [
                    'glpi_plugin_printgestion_cartridge_snmp AS pcs' => [
                        'ON' => ['ci' => 'id', 'pcs' => 'cartridgeitems_id'],
                    ],
                    'glpi_cartridgeitems_printermodels AS cpm' => [
                        'ON' => ['ci' => 'id', 'cpm' => 'cartridgeitems_id'],
                    ],
                ],
                'WHERE' => [
                    'ci.is_deleted'         => 0,
                    'pcs.snmp_property'     => $property,
                    'cpm.printermodels_id'  => $printermodels_id,
                ],
                'LIMIT' => 1,
            ])->current();
            if (is_array($row)) return (int)$row['id'];
        }

        // Fallback : binding direct sans filtre modèle imprimante (edge case : cartouche
        // non déclarée compatible mais quand même bindée à cette propriété)
        $row = $DB->request([
            'SELECT'     => ['ci.id'],
            'FROM'       => 'glpi_cartridgeitems AS ci',
            'INNER JOIN' => [
                'glpi_plugin_printgestion_cartridge_snmp AS pcs' => [
                    'ON' => ['ci' => 'id', 'pcs' => 'cartridgeitems_id'],
                ],
            ],
            'WHERE' => [
                'ci.is_deleted'     => 0,
                'pcs.snmp_property' => $property,
            ],
            'LIMIT' => 1,
        ])->current();
        if (is_array($row)) return (int)$row['id'];

        // ══════════════════════════════════════════════════════════════
        //  FALLBACK : ancien système par type de cartouche (via table mapping)
        //  Conservé pour les utilisateurs qui n'ont pas encore migré leurs
        //  bindings vers le nouveau mode direct.
        // ══════════════════════════════════════════════════════════════
        $map = self::resolveForPrinter($printers_id, $property);
        if ($map === null) {
            return 0;
        }

        $type_id = (int)($map['cartridgeitemtypes_id'] ?? 0);
        if ($type_id <= 0) {
            return 0;
        }

        // ── Priorité 1 : type + modèle imprimante + entité ──
        if ($type_id > 0 && $printermodels_id > 0) {
            $row = $DB->request([
                'SELECT' => ['ci.id'],
                'FROM'   => 'glpi_cartridgeitems AS ci',
                'INNER JOIN' => [
                    'glpi_cartridgeitems_printermodels AS cpm' => [
                        'ON' => ['ci' => 'id', 'cpm' => 'cartridgeitems_id'],
                    ],
                ],
                'WHERE' => [
                    'ci.is_deleted'           => 0,
                    'ci.cartridgeitemtypes_id'=> $type_id,
                    'ci.entities_id'          => $entities_id,
                    'cpm.printermodels_id'    => $printermodels_id,
                ],
                'LIMIT' => 1,
            ])->current();
            if (is_array($row)) return (int)$row['id'];
        }

        // ── Priorité 2 : type + modèle imprimante (toute entité) ──
        if ($type_id > 0 && $printermodels_id > 0) {
            $row = $DB->request([
                'SELECT' => ['ci.id'],
                'FROM'   => 'glpi_cartridgeitems AS ci',
                'INNER JOIN' => [
                    'glpi_cartridgeitems_printermodels AS cpm' => [
                        'ON' => ['ci' => 'id', 'cpm' => 'cartridgeitems_id'],
                    ],
                ],
                'WHERE' => [
                    'ci.is_deleted'           => 0,
                    'ci.cartridgeitemtypes_id'=> $type_id,
                    'cpm.printermodels_id'    => $printermodels_id,
                ],
                'LIMIT' => 1,
            ])->current();
            if (is_array($row)) return (int)$row['id'];
        }

        // ── Priorité 3 : type seul ──
        if ($type_id > 0) {
            $row = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_cartridgeitems',
                'WHERE'  => [
                    'is_deleted'            => 0,
                    'cartridgeitemtypes_id' => $type_id,
                ],
                'LIMIT' => 1,
            ])->current();
            if (is_array($row)) return (int)$row['id'];
        }

        return 0;
    }

    /**
     * Retourne un libellé lisible pour la cartouche associée à une propriété SNMP.
     *
     * Cascade :
     *   1. Nom du cartridgeitem GLPI résolu via resolveCartridgeItemForSnmp (binding direct OU type)
     *   2. Nom du type GLPI (cartridgeitemtypes_id) si défini dans le mapping SNMP
     *   3. La propriété SNMP elle-même (ex: "Toner Noir")
     *
     * Utilisé pour l'affichage dashboard et pour la balise ##printgestion.cartridge##
     * dans les emails.
     */
    public static function getCartridgeLabelForProperty(int $printers_id, string $property): string {
        global $DB;

        // 1. Essai via le résolveur intelligent → nom du cartridgeitem
        $cartridgeitems_id = self::resolveCartridgeItemForSnmp($printers_id, $property);
        if ($cartridgeitems_id > 0) {
            $row = $DB->request([
                'SELECT' => ['name'],
                'FROM'   => 'glpi_cartridgeitems',
                'WHERE'  => ['id' => $cartridgeitems_id],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($row) && !empty($row['name'])) {
                return (string)$row['name'];
            }
        }

        // 2. Fallback : nom du type GLPI si défini
        $map = self::resolveForPrinter($printers_id, $property);
        if (is_array($map) && !empty($map['cartridgeitemtypes_id'])) {
            $row = $DB->request([
                'SELECT' => ['name'],
                'FROM'   => 'glpi_cartridgeitemtypes',
                'WHERE'  => ['id' => (int)$map['cartridgeitemtypes_id']],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($row) && !empty($row['name'])) {
                return (string)$row['name'];
            }
        }

        // 3. Dernier fallback : la propriété SNMP brute
        return $property;
    }

    /**
     * Résout une propriété SNMP (globale, indépendante du constructeur).
     * Retourne ['cartridgeitemtypes_id' => int, 'toner_color' => string] ou null.
     */
    public static function resolveForPrinter(int $printers_id, string $property): ?array {
        global $DB;

        if ($property === '') {
            return null;
        }

        $row = $DB->request([
            'SELECT' => ['cartridgeitemtypes_id', 'toner_color'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['snmp_property' => $property],
            'LIMIT'  => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    static function install(Migration $migration) {
        // La table est créée par PluginPrintgestionConfig::install().
        // On pré-remplit avec les mappings par défaut si la table est vide.
        self::seedDefaults();
        return true;
    }

    static function uninstall(Migration $migration) {
        return true;
    }
}
