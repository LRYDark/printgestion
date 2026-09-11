<?php
/**
 * PluginPrintgestionAlertview — table MATÉRIALISÉE des alertes toner, pour le
 * moteur de recherche natif GLPI.
 *
 * Les alertes sont CALCULÉES (SNMP, rendements, cycles…) par PluginPrintgestionAlert
 * — coûteux. On matérialise le résultat dans cette table (1 ligne par couple
 * imprimante/toner), rafraîchie par le cron (et par le bouton « Rafraîchir »).
 * Le dashboard fait alors un Search::showList() rapide dessus, avec recherche /
 * tri / filtres / colonnes / export natifs. La colonne entities_id permet la
 * restriction d'entité native (pas besoin de addDefaultWhere).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAlertview extends CommonDBTM {

    static $rightname = 'plugin_printgestion_dashboard';

    static function getTypeName($nb = 0) {
        return _n('Alerte toner', 'Alertes toner', $nb, 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_alertview';
        }
        return parent::getTable($classname);
    }

    public static function canView(): bool {
        return Session::haveRight('plugin_printgestion_dashboard', READ);
    }

    /**
     * URL de la liste : pagination / tri / recherche natifs doivent pointer vers
     * le dashboard d'alertes (sinon GLPI génère front/alertview.php → 404).
     */
    static function getSearchURL($full = true) {
        $dir = $full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR;
        return $dir . '/front/dashboard_alerts.php';
    }

    // La table est créée par le schéma versionné (PluginPrintgestionSchema).

    static function uninstall(Migration $migration) {
        global $DB;
        $DB->doQuery("DROP TABLE IF EXISTS `" . self::getTable() . "`");
        return true;
    }

    /**
     * Reconstruit la table à partir du calcul d'alertes (TRUNCATE + INSERT all).
     * Appelée par le cron et le bouton « Rafraîchir ». Stocke TOUTES les entités
     * (la restriction par utilisateur est faite ensuite par le moteur Search).
     *
     * @return int Nombre de lignes matérialisées.
     */
    public static function rebuild(): int {
        global $DB;

        $rows  = PluginPrintgestionAlert::listAll(); // calcul lourd (toutes entités)
        $table = self::getTable();
        $now   = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $DB->doQuery("TRUNCATE TABLE `$table`");

        foreach ($rows as $r) {
            $DB->insert($table, [
                'printers_id'     => (int) $r['printers_id'],
                'entities_id'     => (int) $r['entities_id'],
                'toner_property'  => $r['property'] ?? null,
                'toner_color'     => $r['toner_color'] ?? null,
                'level_percent'   => (int) ($r['level'] ?? 0),
                'days_remaining'  => $r['days_remaining'] !== null ? (int) $r['days_remaining'] : null,
                'status'          => $r['status'] ?? 'ok',
                'cartridge_label' => $r['cartridge_type'] ?? null,
                'has_expedition'  => !empty($r['expedition']) ? 1 : 0,
                'is_snoozed'      => !empty($r['snoozed']) ? 1 : 0,
                'is_estimate'     => !empty($r['is_estimate']) ? 1 : 0,
                'date_compute'    => $now,
            ]);
        }
        return count($rows);
    }

    /** Reconstruit si la table est vide (1ʳᵉ visite avant le passage du cron). */
    public static function rebuildIfEmpty(): void {
        global $DB;
        if ((int) (countElementsInTable(self::getTable())) === 0) {
            self::rebuild();
        }
    }

    // ── Actions de masse natives (remplacent l'ancien menu contextuel) ────────

    function getSpecificMassiveActions($checkitem = null) {
        $actions = parent::getSpecificMassiveActions($checkitem);
        if (Session::haveRight('plugin_printgestion_dashboard', UPDATE)) {
            $self = __CLASS__;
            $sep  = MassiveAction::CLASS_ACTION_SEPARATOR;
            $actions[$self . $sep . 'pg_snooze'] = "<i class='fa-solid fa-bell-slash me-1'></i>" . __('Snoozer', 'printgestion');
            $actions[$self . $sep . 'pg_send']   = "<i class='fa-solid fa-paper-plane me-1'></i>" . __('Envoyer cartouche', 'printgestion');
        }
        return $actions;
    }

    static function showMassiveActionsSubForm(MassiveAction $ma) {
        switch ($ma->getAction()) {
            case 'pg_snooze':
                echo "<input type='number' name='days' value='7' min='1' class='form-control d-inline-block' style='width:90px'> ";
                echo "<span class='me-2'>" . __('jours', 'printgestion') . "</span>";
                echo Html::submit(__('Snoozer', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-primary']);
                return true;
            case 'pg_send':
                echo "<p class='mb-2'>" . __('Créer une expédition de cartouche pour les lignes sélectionnées ?', 'printgestion') . "</p>";
                echo Html::submit(__('Envoyer', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-primary']);
                return true;
        }
        return parent::showMassiveActionsSubForm($ma);
    }

    static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids) {
        global $DB;
        $action = $ma->getAction();
        $input  = $ma->getInput();

        foreach ($ids as $id) {
            if (!$item->getFromDB($id)) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                continue;
            }
            $printers_id = (int) $item->fields['printers_id'];
            $property    = (string) $item->fields['toner_property'];
            $level       = (int) $item->fields['level_percent'];
            $days        = $item->fields['days_remaining'] !== null ? (int) $item->fields['days_remaining'] : null;

            if ($action === 'pg_snooze') {
                $d = max(1, (int) ($input['days'] ?? 7));
                if (PluginPrintgestionAlert::snooze($printers_id, $property, $d)) {
                    // Reflète tout de suite dans la vue (recalcul complet au prochain cron).
                    $DB->update(self::getTable(), ['is_snoozed' => 1, 'status' => 'ok'], ['id' => $id]);
                    $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
                } else {
                    $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                }
            } elseif ($action === 'pg_send') {
                $exp = PluginPrintgestionExpedition::createFromAlert($printers_id, $property, $level, $days);
                if ($exp > 0) {
                    $DB->update(self::getTable(), ['has_expedition' => 1], ['id' => $id]);
                    $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
                } else {
                    $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                }
            } else {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
            }
        }
    }

    public function rawSearchOptions() {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = [
            'id'            => '1',
            'table'         => 'glpi_printers',
            'field'         => 'name',
            'name'          => _n('Imprimante', 'Imprimantes', 1, 'printgestion'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '80',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => '2',
            'table'    => self::getTable(),
            'field'    => 'toner_property',
            'name'     => __('Toner', 'printgestion'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'cartridge_label',
            'name'     => __('Cartouche', 'printgestion'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'level_percent',
            'name'     => __('Niveau (%)', 'printgestion'),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'days_remaining',
            'name'     => __('Jours restants', 'printgestion'),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id'       => '6',
            'table'    => self::getTable(),
            'field'    => 'status',
            'name'     => __('Statut', 'printgestion'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '7',
            'table'    => self::getTable(),
            'field'    => 'toner_color',
            'name'     => __('Couleur', 'printgestion'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '8',
            'table'    => self::getTable(),
            'field'    => 'has_expedition',
            'name'     => __('Expédition en cours', 'printgestion'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => '9',
            'table'    => self::getTable(),
            'field'    => 'is_snoozed',
            'name'     => __('Snoozé', 'printgestion'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => '10',
            'table'    => self::getTable(),
            'field'    => 'date_compute',
            'name'     => __('Calculé le', 'printgestion'),
            'datatype' => 'datetime',
        ];

        return $tab;
    }
}
