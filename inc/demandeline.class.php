<?php
/**
 * PluginPrintgestionDemandeline — ligne d'une demande d'envoi : une cartouche pour une
 * imprimante et un toner (propriété SNMP).
 *
 * Porte la cartouche résolue (résolution stricte), la quantité, le prix unitaire (0 sous
 * contrat ; vide ou saisi hors contrat, jamais 0), le contrat retenu et son propre
 * statut. Une ligne proposée ou validée est OUVERTE : elle bloque son emplacement
 * (anti-double-envoi) et la base garantit au plus une ligne ouverte par imprimante et
 * toner (clé uniq_active_slot).
 *
 * Aucune suppression : une ligne s'annule. Ajouts et modifications sont journalisés dans
 * l'historique de la demande.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionDemandeline extends CommonDBChild {

    static $itemtype  = 'PluginPrintgestionDemande';
    static $items_id  = 'plugin_printgestion_demandes_id';
    static $rightname = 'plugin_printgestion_validation';

    static function getTypeName($nb = 0) {
        return _n('Ligne de demande', 'Lignes de demande', $nb, 'printgestion');
    }

    public function pre_deleteItem() {
        Session::addMessageAfterRedirect(
            __('Une ligne de demande d\'envoi ne se supprime pas : annulez-la.', 'printgestion'),
            false,
            ERROR
        );
        return false;
    }

    public function getNonLoggedFields(): array {
        return ['date_creation', 'date_mod'];
    }

    /** Libellé de la ligne dans l'historique de la demande (ajout). */
    public function getHistoryNameForItem(CommonDBTM $item, $case) {
        return self::describe($this->fields);
    }

    /**
     * Modification d'un champ, journalisée dans l'historique de la demande : ligne,
     * champ, ancienne et nouvelle valeur lisibles. Champs techniques non journalisés.
     */
    public function getHistoryChangeWhenUpdateField($field) {
        $labels = [
            'quantity'          => __('Quantité', 'printgestion'),
            'unit_price'        => __('Prix unitaire', 'printgestion'),
            'statut'            => __('Statut', 'printgestion'),
            'cartridgeitems_id' => __('Cartouche', 'printgestion'),
            'is_under_contract' => __('Contrat', 'printgestion'),
            'contracts_id'      => __('Contrat retenu', 'printgestion'),
            'expeditions_id'    => __('Expédition', 'printgestion'),
        ];
        if (!isset($labels[$field])) {
            return [];
        }
        $old = self::formatValue($field, $this->oldvalues[$field] ?? null);
        $new = self::formatValue($field, $this->fields[$field] ?? null);
        return [
            '0',
            $old,
            sprintf('%1$s · %2$s : %3$s → %4$s', self::describe($this->fields), $labels[$field], $old, $new),
        ];
    }

    /** « #id Imprimante — Toner » */
    public static function describe(array $fields): string {
        global $DB;

        $printers_id = (int) ($fields['printers_id'] ?? 0);
        $printer     = $printers_id > 0
            ? $DB->request([
                'SELECT' => ['name'],
                'FROM'   => 'glpi_printers',
                'WHERE'  => ['id' => $printers_id],
                'LIMIT'  => 1,
            ])->current()
            : null;

        return sprintf(
            '#%1$d %2$s — %3$s',
            (int) ($fields['id'] ?? 0),
            is_array($printer) ? (string) $printer['name'] : '#' . $printers_id,
            (string) ($fields['toner_property'] ?? '')
        );
    }

    /** Prix lisible : jusqu'à 4 décimales, sans zéros inutiles (« 12,5 € », « 0 € »). */
    public static function formatPrice(float $price): string {
        return rtrim(rtrim(number_format($price, 4, ',', ' '), '0'), ',') . ' €';
    }

    private static function formatValue(string $field, $value): string {
        switch ($field) {
            case 'unit_price':
                return ($value === null || $value === '') ? __('vide', 'printgestion') : self::formatPrice((float) $value);
            case 'statut':
                return PluginPrintgestionDemande::getStatusLabels()[(string) $value] ?? (string) $value;
            case 'is_under_contract':
                return (int) $value === 1 ? __('sous contrat', 'printgestion') : __('hors contrat', 'printgestion');
            case 'cartridgeitems_id':
                return (int) $value > 0 ? Dropdown::getDropdownName('glpi_cartridgeitems', (int) $value) : __('non résolue', 'printgestion');
            case 'contracts_id':
                return (int) $value > 0 ? Dropdown::getDropdownName('glpi_contracts', (int) $value) : '—';
        }
        return (string) $value;
    }

    // Table créée par le schéma versionné (étape 1.3.1), supprimée par
    // PluginPrintgestionDemande::uninstall().
}
