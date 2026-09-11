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

    /** Référence non résolue : imprimante ou propriété introuvable. */
    const REF_NO_PRINTER = 'no_printer';
    /** Référence non résolue : modèle d'imprimante non renseigné. */
    const REF_NO_MODEL   = 'no_model';
    /** Référence non résolue : aucune cartouche liée et compatible avec le modèle. */
    const REF_NOT_FOUND  = 'not_found';
    /** Référence non résolue : plusieurs cartouches possibles, aucune n'est choisie. */
    const REF_AMBIGUOUS  = 'ambiguous';

    /** Résolutions calculées pendant la requête courante : "printers_id|property" => résultat. */
    private static array $resolved = [];

    /**
     * Résout la cartouche (CartridgeItem) à commander pour une imprimante et une
     * propriété SNMP. Résolution STRICTE : le modèle d'imprimante est obligatoire et
     * seule une cartouche déclarée compatible avec ce modèle peut être retenue.
     *
     *   1. Liaison directe cartouche ↔ propriété (onglet Print Gestion de la cartouche),
     *      restreinte aux cartouches compatibles avec le modèle de l'imprimante.
     *   2. À défaut, type de cartouche du mapping SNMP, restreint aux cartouches de ce
     *      type compatibles avec le modèle.
     *
     * Plusieurs candidates à la même étape : celle de l'entité de l'imprimante si elle
     * est seule dans ce cas, sinon aucune — l'ambiguïté est signalée, jamais tranchée
     * au hasard (cartouche standard et XL liées à la même propriété, par exemple).
     * Aucun repli sans modèle : ni liaison directe toutes imprimantes confondues, ni type
     * seul toutes marques. Une référence non résolue rend la cartouche non commandable.
     *
     * @return array ['cartridgeitems_id' => int (0 si non résolue),
     *                'error'             => ?string (REF_*, null si résolue),
     *                'message'           => string (motif lisible, vide si résolue)]
     */
    public static function resolveCartridge(int $printers_id, string $property): array {
        global $DB;

        $key = $printers_id . '|' . $property;
        if (isset(self::$resolved[$key])) {
            return self::$resolved[$key];
        }

        $printer = $DB->request([
            'SELECT'    => ['p.printermodels_id', 'p.entities_id', 'pm.name AS model_name'],
            'FROM'      => 'glpi_printers AS p',
            'LEFT JOIN' => [
                'glpi_printermodels AS pm' => ['ON' => ['p' => 'printermodels_id', 'pm' => 'id']],
            ],
            'WHERE'     => ['p.id' => $printers_id],
            'LIMIT'     => 1,
        ])->current();

        if (!is_array($printer) || $property === '') {
            return self::$resolved[$key] = self::refResult(
                0,
                self::REF_NO_PRINTER,
                __('Imprimante ou propriété SNMP introuvable.', 'printgestion')
            );
        }

        $model_id    = (int) $printer['printermodels_id'];
        $entities_id = (int) $printer['entities_id'];
        if ($model_id <= 0) {
            return self::$resolved[$key] = self::refResult(
                0,
                self::REF_NO_MODEL,
                __('Modèle d\'imprimante non renseigné sur la fiche : la cartouche ne peut pas être déterminée.', 'printgestion')
            );
        }

        // 1. Liaison directe, restreinte au modèle.
        $candidates = self::compatibleCartridges(
            $model_id,
            ['glpi_plugin_printgestion_cartridge_snmp AS pcs' => ['ON' => ['ci' => 'id', 'pcs' => 'cartridgeitems_id']]],
            ['pcs.snmp_property' => $property]
        );

        // 2. Type de cartouche du mapping SNMP, restreint au modèle.
        if (empty($candidates)) {
            $map     = self::resolveForPrinter($printers_id, $property);
            $type_id = is_array($map) ? (int) ($map['cartridgeitemtypes_id'] ?? 0) : 0;
            if ($type_id > 0) {
                $candidates = self::compatibleCartridges($model_id, [], ['ci.cartridgeitemtypes_id' => $type_id]);
            }
        }

        $model_name = (string) ($printer['model_name'] ?? '');
        if (empty($candidates)) {
            return self::$resolved[$key] = self::refResult(
                0,
                self::REF_NOT_FOUND,
                sprintf(
                    __('Aucune cartouche liée à la propriété « %1$s » et déclarée compatible avec le modèle « %2$s » (onglet Print Gestion de la cartouche, ou type du mapping SNMP).', 'printgestion'),
                    $property,
                    $model_name
                )
            );
        }

        if (count($candidates) > 1) {
            $same_entity = array_filter(
                $candidates,
                static fn(array $c) => (int) $c['entities_id'] === $entities_id
            );
            if (count($same_entity) !== 1) {
                return self::$resolved[$key] = self::refResult(
                    0,
                    self::REF_AMBIGUOUS,
                    sprintf(
                        __('Plusieurs cartouches possibles pour la propriété « %1$s » et le modèle « %2$s » (%3$s) : ne liez qu\'une seule cartouche à cette propriété pour ce modèle.', 'printgestion'),
                        $property,
                        $model_name,
                        implode(', ', array_column($candidates, 'name'))
                    )
                );
            }
            $candidates = $same_entity;
        }

        return self::$resolved[$key] = self::refResult((int) array_key_first($candidates), null, '');
    }

    /**
     * ID de la cartouche résolue (résolution stricte, voir resolveCartridge()),
     * 0 si aucune référence n'est déterminée.
     */
    public static function resolveCartridgeItemForSnmp(int $printers_id, string $property): int {
        return (int) self::resolveCartridge($printers_id, $property)['cartridgeitems_id'];
    }

    /**
     * Cartouches non supprimées déclarées compatibles avec un modèle d'imprimante,
     * filtrées par les jointures et conditions données. Clé = id.
     */
    private static function compatibleCartridges(int $printermodels_id, array $join, array $where): array {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'     => ['ci.id', 'ci.name', 'ci.entities_id'],
            'FROM'       => 'glpi_cartridgeitems AS ci',
            'INNER JOIN' => $join + [
                'glpi_cartridgeitems_printermodels AS cpm' => ['ON' => ['ci' => 'id', 'cpm' => 'cartridgeitems_id']],
            ],
            'WHERE'      => $where + [
                'ci.is_deleted'        => 0,
                'cpm.printermodels_id' => $printermodels_id,
            ],
            'ORDER'      => ['ci.name'],
        ]) as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private static function refResult(int $cartridgeitems_id, ?string $error, string $message): array {
        return [
            'cartridgeitems_id' => $cartridgeitems_id,
            'error'             => $error,
            'message'           => $message,
        ];
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

    // Table créée et pré-remplie (seedDefaults) par le schéma versionné
    // (PluginPrintgestionSchema, étape 1.0.0).

    static function uninstall(Migration $migration) {
        return true;
    }
}
