<?php
/**
 * PluginPrintgestionCartridgesnmp — onglet "Print Gestion" sur la fiche CartridgeItem.
 *
 * Permet de binder directement une cartouche à une ou plusieurs propriétés SNMP
 * remontées par les imprimantes compatibles (déclarées dans l'onglet "Modèles
 * d'imprimantes compatibles" de la cartouche).
 *
 * Workflow utilisateur :
 *   1. Créer une cartouche dans Assets → Cartouches
 *   2. Onglet "Modèles d'imprimantes compatibles" → ajouter les modèles d'imprimante
 *   3. Onglet "Print Gestion" (celui-ci) → cocher les propriétés SNMP correspondantes
 *   4. Save → la cartouche est directement liée aux propriétés SNMP, sans avoir
 *      à créer de types intermédiaires dans GLPI
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionCartridgesnmp extends CommonDBTM {

    static $rightname = 'cartridge';

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_cartridge_snmp';
        }
        return parent::getTable($classname);
    }

    static function getTypeName($nb = 0) {
        return __('Propriétés SNMP', 'printgestion');
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'CartridgeItem' && Session::haveRight('cartridge', READ)) {
            $nb = countElementsInTable(self::getTable(), ['cartridgeitems_id' => $item->getID()]);
            return self::createTabEntry(__('Print Gestion', 'printgestion'), $nb);
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() == 'CartridgeItem') {
            self::showForCartridge($item);
        }
        return true;
    }

    /**
     * Liste les propriétés SNMP disponibles pour une cartouche donnée.
     *
     * Filtre : seules les propriétés remontées par des imprimantes dont le modèle
     * est déclaré compatible avec cette cartouche (via glpi_cartridgeitems_printermodels).
     * On ne garde que les valeurs numériques (%) — les OK/WARNING sont skippés.
     */
    public static function getAvailableSnmpProperties(int $cartridgeitems_id): array {
        global $DB;

        if ($cartridgeitems_id <= 0) {
            return [];
        }

        $rows = $DB->request([
            'SELECT'     => ['ci.property', 'ci.value'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_printers_cartridgeinfos AS ci',
            'INNER JOIN' => [
                'glpi_printers AS p' => [
                    'ON' => ['ci' => 'printers_id', 'p' => 'id'],
                ],
                'glpi_cartridgeitems_printermodels AS cpm' => [
                    'ON' => ['p' => 'printermodels_id', 'cpm' => 'printermodels_id'],
                ],
            ],
            'WHERE' => [
                'cpm.cartridgeitems_id' => $cartridgeitems_id,
                'p.is_deleted'          => 0,
                'p.is_template'         => 0,
            ],
            'ORDER' => ['ci.property'],
        ]);

        $properties = [];
        foreach ($rows as $r) {
            // Skip les valeurs non exploitables (OK, WARNING, vide)
            $parsed = PluginPrintgestionTonerreading::parseTonerValue((string)$r['value']);
            if (!$parsed['usable']) {
                continue;
            }
            $prop = (string)$r['property'];
            if (!in_array($prop, $properties, true)) {
                $properties[] = $prop;
            }
        }

        sort($properties);
        return $properties;
    }

    /**
     * Retourne les propriétés SNMP actuellement bindées à une cartouche.
     */
    public static function getBoundProperties(int $cartridgeitems_id): array {
        global $DB;

        if ($cartridgeitems_id <= 0) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['snmp_property'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['cartridgeitems_id' => $cartridgeitems_id],
        ]) as $r) {
            $out[] = (string)$r['snmp_property'];
        }
        return $out;
    }

    /**
     * Affiche l'onglet Print Gestion sur la fiche CartridgeItem.
     */
    static function showForCartridge(CartridgeItem $item): void {
        $cartridgeitems_id = (int)$item->getID();
        $canedit = $item->can($cartridgeitems_id, UPDATE);

        // Vérifie qu'au moins un modèle d'imprimante est déclaré compatible
        global $DB;
        $has_compat = $DB->request([
            'FROM'  => 'glpi_cartridgeitems_printermodels',
            'WHERE' => ['cartridgeitems_id' => $cartridgeitems_id],
            'LIMIT' => 1,
        ])->count() > 0;

        echo "<div class='card mt-3'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'>"
            . __('Liaison aux propriétés SNMP', 'printgestion') . "</h3></div>";
        echo "<div class='card-body'>";

        if (!$has_compat) {
            echo "<div class='alert alert-warning mb-0'>";
            echo "<i class='fa-solid fa-triangle-exclamation me-1'></i>";
            echo __("Aucun modèle d'imprimante compatible n'est déclaré sur cette cartouche. "
                . "Ajoute d'abord les modèles via l'onglet <strong>Modèles d'imprimantes compatibles</strong> "
                . "ci-dessus, puis reviens ici pour choisir les propriétés SNMP correspondantes.", 'printgestion');
            echo "</div>";
            echo "</div></div>";
            return;
        }

        $properties = self::getAvailableSnmpProperties($cartridgeitems_id);
        $bound      = self::getBoundProperties($cartridgeitems_id);

        if (empty($properties)) {
            echo "<div class='alert alert-info mb-0'>";
            echo "<i class='fa-solid fa-circle-info me-1'></i>";
            echo __("Aucune propriété SNMP détectée pour les modèles d'imprimantes compatibles. "
                . "Vérifie que l'agent GLPI a bien inventorié au moins une imprimante de ces modèles, "
                . "puis reviens.", 'printgestion');
            echo "</div>";
            echo "</div></div>";
            return;
        }

        echo "<p class='text-muted small'>"
            . __("Coche les propriétés SNMP qui correspondent à cette cartouche. Le plugin "
                . "l'utilisera automatiquement pour peupler l'onglet Cartouches natif des imprimantes.", 'printgestion')
            . "</p>";

        echo "<form method='post' action='" . PLUGIN_PRINTGESTION_WEBDIR . "/front/cartridgesnmp.form.php'>";
        echo Html::hidden('cartridgeitems_id', ['value' => $cartridgeitems_id]);

        echo "<table class='tab_cadre_fixehov' style='width:100%'>";
        echo "<thead><tr class='noHover'>";
        echo "<th style='width:80px;text-align:center'>" . __('Lié', 'printgestion') . "</th>";
        echo "<th>" . __('Propriété SNMP', 'printgestion') . "</th>";
        echo "</tr></thead><tbody>";

        foreach ($properties as $prop) {
            $checked = in_array($prop, $bound, true) ? 'checked' : '';
            $prop_h  = htmlspecialchars($prop, ENT_QUOTES, 'UTF-8');
            echo "<tr>";
            echo "<td class='text-center'>";
            if ($canedit) {
                echo "<input type='checkbox' class='form-check-input' "
                    . "name='bound[]' value='{$prop_h}' {$checked}>";
            } else {
                echo $checked ? '✓' : '—';
            }
            echo "</td>";
            echo "<td>{$prop_h}</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";

        if ($canedit) {
            echo "<div class='text-center mt-3'>";
            echo "<button type='submit' name='save_bindings' value='1' class='btn btn-primary'>"
                . "<i class='fa-solid fa-save me-1'></i>" . _sx('button', 'Save') . "</button>";
            echo "</div>";
        }

        Html::closeForm();
        echo "</div></div>";
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
