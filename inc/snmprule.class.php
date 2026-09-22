<?php
/**
 * PluginPrintgestionSnmprule — règle de lecture SNMP par constructeur (table snmprules).
 *
 * Écart constaté sur un modèle du parc : ignorer une propriété, ou inverser sa valeur
 * (100 − valeur). Motif de propriété avec « * » ; constructeur 0 = tous. Aucune règle par
 * défaut : un bac de récupération (réceptacle) remonte déjà la place restante selon la
 * RFC 3805.
 *
 * Saisie dans la configuration du plugin, appliquée par le service de lecture des niveaux
 * PluginPrintgestionSnmpadapter.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSnmprule extends CommonDBTM {

    static $rightname = 'plugin_printgestion_config';

    const ACTION_IGNORE = 'ignore';
    const ACTION_INVERT = 'invert';

    static function getTypeName($nb = 0) {
        return _n('Règle de lecture SNMP', 'Règles de lecture SNMP', $nb, 'printgestion');
    }

    public static function getActionLabels(): array {
        return [
            self::ACTION_IGNORE => __('Ignorer la propriété', 'printgestion'),
            self::ACTION_INVERT => __('Inverser la valeur (100 − valeur)', 'printgestion'),
        ];
    }

    /** Carte de la configuration : règles existantes (suppression) et ajout. */
    public static function showConfigCard(): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(__('Lecture des niveaux SNMP — règles par constructeur', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small mb-3'>" . $esc(__('Sentinelles de la Printer MIB (-1 non mesurable, -2 inconnu, -3 « il en reste ») jamais lues comme un pourcentage ; pourcentage reconstitué depuis les valeurs max / utilisé / restant quand il manque. Ajoutez une règle seulement pour un écart constaté sur un modèle (motif de propriété avec *, ex. wastetoner* ; constructeur vide = tous).', 'printgestion')) . "</p>";

        $rules = (new self())->find([], ['manufacturers_id', 'property_pattern']);
        if (!empty($rules)) {
            echo "<div class='table-responsive'><table class='table table-sm'><thead><tr>"
                . "<th>" . $esc(Manufacturer::getTypeName(1)) . "</th><th>" . $esc(__('Propriété', 'printgestion')) . "</th>"
                . "<th>" . $esc(__('Action', 'printgestion')) . "</th><th>" . $esc(__('Motif', 'printgestion')) . "</th>"
                . "<th class='text-center'>" . $esc(__('Supprimer', 'printgestion')) . "</th></tr></thead><tbody>";
            foreach ($rules as $rule) {
                echo "<tr><td>" . ((int) $rule['manufacturers_id'] > 0 ? $esc(Dropdown::getDropdownName('glpi_manufacturers', (int) $rule['manufacturers_id'])) : $esc(__('Tous', 'printgestion'))) . "</td>"
                    . "<td><code>" . $esc($rule['property_pattern']) . "</code></td>"
                    . "<td>" . $esc(self::getActionLabels()[$rule['action']] ?? $rule['action']) . "</td>"
                    . "<td>" . $esc($rule['comment']) . "</td>"
                    . "<td class='text-center'><input type='checkbox' class='form-check-input' name='snmprule_delete[" . (int) $rule['id'] . "]' value='1'></td></tr>";
            }
            echo "</tbody></table></div>";
        }

        echo "<div class='row g-2 align-items-end'>";
        echo "<div class='col-md-3'><label class='form-label'>" . $esc(Manufacturer::getTypeName(1)) . "</label>";
        Manufacturer::dropdown(['name' => 'snmprule_new[manufacturers_id]', 'value' => 0, 'emptylabel' => __('Tous', 'printgestion')]);
        echo "</div>";
        echo "<div class='col-md-3'><label class='form-label'>" . $esc(__('Propriété (motif)', 'printgestion')) . "</label>"
            . "<input type='text' class='form-control' name='snmprule_new[property_pattern]' maxlength='255' placeholder='wastetoner*'></div>";
        echo "<div class='col-md-3'><label class='form-label'>" . $esc(__('Action', 'printgestion')) . "</label>";
        Dropdown::showFromArray('snmprule_new[action]', self::getActionLabels());
        echo "</div>";
        echo "<div class='col-md-3'><label class='form-label'>" . $esc(__('Motif de la règle', 'printgestion')) . "</label>"
            . "<input type='text' class='form-control' name='snmprule_new[comment]' maxlength='255'></div>";
        echo "</div>";
        echo "</div></div>";
    }

    /**
     * Enregistre la carte de configuration : suppressions cochées, ajout d'une règle.
     * @return string[] Messages d'erreur.
     */
    public static function saveConfig(array $input): array {
        $errors = [];
        foreach ((array) ($input['snmprule_delete'] ?? []) as $id => $flag) {
            if ((int) $flag !== 1 || (int) $id <= 0) {
                continue;
            }
            if (!(new self())->delete(['id' => (int) $id], true)) {
                $errors[] = sprintf(__('Règle SNMP #%d : suppression refusée.', 'printgestion'), (int) $id);
            }
        }

        $new     = (array) ($input['snmprule_new'] ?? []);
        $pattern = trim((string) ($new['property_pattern'] ?? ''));
        if ($pattern !== '') {
            $action = (string) ($new['action'] ?? '');
            if (!isset(self::getActionLabels()[$action])) {
                $errors[] = __('Règle SNMP : action inconnue.', 'printgestion');
            } elseif (!preg_match('/^[A-Za-z0-9_*.\-]+$/', $pattern)) {
                $errors[] = __('Règle SNMP : motif de propriété invalide (lettres, chiffres, _ - . et * uniquement).', 'printgestion');
            } elseif (!(new self())->add([
                'manufacturers_id' => max(0, (int) ($new['manufacturers_id'] ?? 0)),
                'property_pattern' => mb_substr($pattern, 0, 255),
                'action'           => $action,
                'comment'          => mb_substr(trim((string) ($new['comment'] ?? '')), 0, 255),
            ])) {
                $errors[] = __('Règle SNMP : enregistrement refusé.', 'printgestion');
            }
        }
        PluginPrintgestionSnmpadapter::resetCache();
        return $errors;
    }

    static function uninstall(Migration $migration) {
        global $DB;
        // Ancien nom de la table (versions de développement) : supprimé s'il existe encore.
        $DB->dropTable(self::getTable(), true);
        $DB->dropTable('glpi_plugin_printgestion_snmpadapters', true);
        return true;
    }
}
