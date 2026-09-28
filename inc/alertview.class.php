<?php
/**
 * PluginPrintgestionAlertview — écran des alertes toner sur le moteur de recherche natif GLPI.
 *
 * Les alertes sont CALCULÉES (niveaux SNMP, rendements, cycles, verrous) par
 * PluginPrintgestionAlert::listAll() — coûteux. Le résultat est matérialisé ici, une ligne par
 * imprimante et toner : recalcul complet par la tâche horaire et par « Recalculer », recalcul
 * des imprimantes concernées après chaque action de masse. Recherche, tri, filtres, colonnes
 * et export natifs ; restriction d'entité native (colonne entities_id).
 *
 * Actions de masse :
 *   - Commander (droit de validation) : Expedition::createPurchaseOrder(), transactionnelle,
 *     verrous et références revérifiés côté serveur — une ligne verrouillée fait refuser
 *     toute la commande ;
 *   - Ne plus alerter / Réactiver les alertes (modification des alertes).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAlertview extends CommonDBTM implements \Glpi\Search\DefaultSearchRequestInterface {

    static $rightname = 'plugin_printgestion_dashboard';

    /** Cache GLPI : date de la dernière action qui a rendu la vue périmée. */
    const STALE_KEY = 'plugin_printgestion_alertview_stale';

    /**
     * Icône de l'itemtype, reprise par GLPI dans les listes, les en-têtes et les onglets. Écran des alertes toner : ce qui demande une décision.
     *
     * Sans elle, GLPI retombe sur l'icône par défaut de CommonDBTM, qui ne montre rien.
     */
    static function getIcon() {
        return 'ti ti-alert-triangle';
    }

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
        return PluginPrintgestionConfig::isFeatureEnabled('toner') && Session::haveRight('plugin_printgestion_dashboard', READ);
    }

    /** Table calculée : aucune création, modification ou suppression manuelle. */
    public static function canCreate(): bool {
        return false;
    }

    public static function canDelete(): bool {
        return false;
    }

    public static function canPurge(): bool {
        return false;
    }

    public function getForbiddenStandardMassiveAction() {
        return array_merge(parent::getForbiddenStandardMassiveAction(), [
            'update', 'clone', 'delete', 'purge', 'restore', 'add_transfer_list', 'amend_comment', 'add_note',
        ]);
    }

    /**
     * URL de la liste : pagination / tri / recherche natifs doivent pointer vers le
     * dashboard d'alertes (sinon GLPI génère front/alertview.php → 404).
     */
    static function getSearchURL($full = true) {
        $dir = $full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR;
        return $dir . '/front/dashboard_alerts.php';
    }

    /** Par défaut : toners en alerte (critique ou à surveiller), les plus urgents d'abord. */
    public static function getDefaultSearchRequest(): array {
        return [
            'criteria' => [
                ['field' => 6, 'searchtype' => 'notequals', 'value' => PluginPrintgestionAlert::STATUS_OK],
            ],
            'sort'  => 5,
            'order' => 'ASC',
        ];
    }

    // La table est créée par le schéma versionné (PluginPrintgestionSchema).

    static function uninstall(Migration $migration) {
        global $DB;
        $DB->dropTable(self::getTable(), true);
        return true;
    }

    // ── Calcul ────────────────────────────────────────────────────────────────

    /**
     * Recalcule la table à partir du calcul d'alertes.
     *
     * @param ?array $printer_ids null : tout (vidage puis reconstruction) ; sinon seulement
     *                            ces imprimantes (après une action de masse).
     * @return int Nombre de lignes matérialisées.
     */
    public static function rebuild(?array $printer_ids = null): int {
        global $DB, $GLPI_CACHE;

        $started = date('Y-m-d H:i:s');
        $table   = self::getTable();
        $ids     = null;
        if ($printer_ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $printer_ids))));
            if (empty($ids)) {
                return 0;
            }
        }

        // Calcul lourd, TOUTES entités : la table est lue ensuite avec la restriction
        // d'entité native (colonne entities_id) — ne jamais l'exposer sans elle.
        $rows = PluginPrintgestionAlert::listAll(null, false, $ids);
        $now  = $_SESSION['glpi_currenttime'] ?? $started;

        if ($ids === null) {
            $DB->truncate($table);
        } else {
            $DB->delete($table, ['printers_id' => $ids]);
        }

        foreach ($rows as $r) {
            // Référence : résolue pour les toners en alerte (ceux qu'on commande). Aucun stock :
            // il est dans Sage.
            $ref_error = null;
            if ($r['status'] !== PluginPrintgestionAlert::STATUS_OK) {
                $ref       = PluginPrintgestionSnmpmapping::resolveCartridge((int) $r['printers_id'], (string) $r['property']);
                $ref_error = $ref['cartridgeitems_id'] > 0 ? null : (string) $ref['message'];
            }
            $lock = $r['lock'] ?? null;

            $DB->insert($table, [
                'printers_id'       => (int) $r['printers_id'],
                'entities_id'       => (int) $r['entities_id'],
                'toner_property'    => $r['property'] ?? null,
                'toner_color'       => $r['toner_color'] ?? null,
                'level_percent'     => (int) ($r['level'] ?? 0),
                'days_remaining'    => $r['days_remaining'] !== null ? (int) $r['days_remaining'] : null,
                'status'            => $r['status'] ?? 'ok',
                'cartridge_label'   => $r['cartridge_type'] ?? null,
                'has_expedition'    => !empty($r['expedition']) ? 1 : 0,
                'expedition_statut' => !empty($r['expedition']) ? (string) $r['expedition']['statut'] : null,
                'is_snoozed'        => !empty($r['snoozed']) ? 1 : 0,
                'is_estimate'       => !empty($r['is_estimate']) ? 1 : 0,
                'level_suspect'     => !empty($r['level_suspect']) ? 1 : 0,
                // Verrou contourné (consommation anormale) : commandable, signalé à part.
                'lock_reason'       => $lock === null ? null : ($lock['blocking'] ? $lock['reason'] : 'bypassed'),
                'lock_message'      => $lock['message'] ?? null,
                'ref_error'         => $ref_error,
                'date_compute'      => $now,
            ]);
        }

        // Recalcul complet postérieur à la dernière action : la vue n'est plus périmée.
        if ($ids === null && isset($GLPI_CACHE)) {
            $stale = self::getStaleSince();
            if ($stale !== null && $stale <= $started) {
                $GLPI_CACHE->delete(self::STALE_KEY);
            }
        }
        return count($rows);
    }

    /** Reconstruit si la table est vide (1ʳᵉ visite avant le passage du cron). */
    public static function rebuildIfEmpty(): void {
        if ((int) countElementsInTable(self::getTable()) === 0) {
            self::rebuild();
        }
    }

    /** Signale une action qui rend la vue périmée (commande, annulation, seuils…). */
    public static function markStale(): void {
        global $GLPI_CACHE;
        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->set(self::STALE_KEY, date('Y-m-d H:i:s'), 7 * DAY_TIMESTAMP);
        }
    }

    /** Date de la dernière action non encore reflétée par un recalcul complet, sinon null. */
    public static function getStaleSince(): ?string {
        global $GLPI_CACHE;
        if (!isset($GLPI_CACHE)) {
            return null;
        }
        $value = $GLPI_CACHE->get(self::STALE_KEY);
        return (is_string($value) && $value !== '') ? $value : null;
    }

    // ── Actions de masse ──────────────────────────────────────────────────────

    function getSpecificMassiveActions($checkitem = null) {
        $actions = parent::getSpecificMassiveActions($checkitem);
        $self    = __CLASS__;
        $sep     = MassiveAction::CLASS_ACTION_SEPARATOR;
        if (Session::haveRight('plugin_printgestion_validation', UPDATE)) {
            $actions[$self . $sep . 'pg_order'] = "<i class='ti ti-shopping-cart me-1'></i>" . __('Commander', 'printgestion');
        }
        if (Session::haveRight('plugin_printgestion_dashboard', UPDATE)) {
            $actions[$self . $sep . 'pg_snooze']   = "<i class='ti ti-bell-off me-1'></i>" . __('Ne plus alerter pendant…', 'printgestion');
            $actions[$self . $sep . 'pg_unsnooze'] = "<i class='ti ti-bell me-1'></i>" . __('Réactiver les alertes', 'printgestion');
        }
        return $actions;
    }

    static function showMassiveActionsSubForm(MassiveAction $ma) {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        switch ($ma->getAction()) {
            case 'pg_order':
                // Avant le clic : ce qui ferait refuser la commande, et ce qui mérite d'être vu (décompte, liste).
                $items = [];
                foreach (($ma->getItems()[self::class] ?? []) as $id) {
                    $row = new self();
                    if ($row->getFromDB((int) $id) && PluginPrintgestionSecurity::canAccessPrinter((int) $row->fields['printers_id'])) {
                        $items[] = ['printers_id' => (int) $row->fields['printers_id'], 'property' => (string) $row->fields['toner_property']];
                    }
                }
                $preview = PluginPrintgestionExpedition::previewPurchaseOrder($items);
                if (!empty($preview['errors'])) {
                    echo "<div class='alert alert-danger py-2'><div class='fw-bold'>" . $esc(sprintf(
                        _n('%d cartouche ne peut pas être écrite dans le fichier Gesconso : la commande sera refusée.', '%d cartouches ne peuvent pas être écrites dans le fichier Gesconso : la commande sera refusée.', count($preview['errors']), 'printgestion'),
                        count($preview['errors'])
                    )) . "</div><ul class='small mb-0'>";
                    foreach (array_slice($preview['errors'], 0, 20) as $message) {
                        echo '<li>' . $esc($message) . '</li>';
                    }
                    echo "</ul></div>";
                }
                echo PluginPrintgestionGesconso::renderNoticesSummary($preview['notices'], 'pg-order-notices');
                echo "<p class='text-muted small'>" . $esc(__('Une commande pour les toners cochés : fichier Gesconso envoyé aux Achats, une expédition par toner. Refusée en entier si une ligne est verrouillée (envoi ou demande en cours, garde, ticket) ou sans référence, code client ou adresse de livraison : rien n\'est alors enregistré.', 'printgestion')) . "</p>";
                echo "<div class='form-check'><input type='checkbox' class='form-check-input' name='send_planif' value='1' id='pg-ma-planif'>"
                    . "<label class='form-check-label' for='pg-ma-planif'>" . $esc(__('Prévenir la planification (logistique)', 'printgestion')) . "</label></div>";
                echo "<div class='form-check mb-2'><input type='checkbox' class='form-check-input' name='send_courtesy' value='1' id='pg-ma-courtesy'>"
                    . "<label class='form-check-label' for='pg-ma-courtesy'>" . $esc(__('Mail de courtoisie au client (usager renseigné sur la fiche imprimante uniquement)', 'printgestion')) . "</label></div>";
                echo Html::submit(__('Envoyer la commande', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-primary']);
                return true;

            case 'pg_snooze':
                echo "<input type='number' name='days' value='7' min='1' max='365' class='form-control d-inline-block' style='width:90px'> ";
                echo "<span class='me-2'>" . $esc(__('jours', 'printgestion')) . "</span>";
                echo Html::submit(__('Ne plus alerter', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-primary']);
                return true;

            case 'pg_unsnooze':
                echo Html::submit(__('Réactiver', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-primary']);
                return true;
        }
        return parent::showMassiveActionsSubForm($ma);
    }

    static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids) {
        if ($ma->getAction() === 'pg_order') {
            self::processOrder($ma, $item, $ids);
            return;
        }

        global $DB;
        $input       = $ma->getInput();
        $printer_ids = [];

        foreach ($ids as $id) {
            if (!$item->getFromDB($id)) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                continue;
            }
            // Droit d'agir et cloisonnement client, vérifiés à chaque ligne.
            if (!Session::haveRight('plugin_printgestion_dashboard', UPDATE)
                || !PluginPrintgestionSecurity::canAccessPrinter((int) $item->fields['printers_id'])) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                continue;
            }
            $printers_id = (int) $item->fields['printers_id'];
            $property    = (string) $item->fields['toner_property'];

            $ok = false;
            if ($ma->getAction() === 'pg_snooze') {
                $ok = PluginPrintgestionAlert::snooze($printers_id, $property, max(1, min(365, (int) ($input['days'] ?? 7))));
            } elseif ($ma->getAction() === 'pg_unsnooze') {
                $ok = PluginPrintgestionAlert::clearSnooze($printers_id, $property);
            }
            $ma->itemDone($item->getType(), $id, $ok ? MassiveAction::ACTION_OK : MassiveAction::ACTION_KO);
            if ($ok) {
                $printer_ids[$printers_id] = $printers_id;
            }
        }

        // Lignes des imprimantes touchées recalculées aussitôt (statut, verrous).
        if (!empty($printer_ids)) {
            self::rebuild(array_values($printer_ids));
        }
    }

    /** Commander : une seule commande pour toutes les lignes cochées, accessibles. */
    private static function processOrder(MassiveAction $ma, CommonDBTM $item, array $ids): void {
        $input       = $ma->getInput();
        $items       = [];
        $ordered_ids = [];
        $printer_ids = [];

        foreach ($ids as $id) {
            if (!Session::haveRight('plugin_printgestion_validation', UPDATE)
                || !$item->getFromDB($id)
                || !PluginPrintgestionSecurity::canAccessPrinter((int) $item->fields['printers_id'])) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                continue;
            }
            $items[] = [
                'printers_id' => (int) $item->fields['printers_id'],
                'property'    => (string) $item->fields['toner_property'],
                'level'       => (int) $item->fields['level_percent'],
                'days'        => $item->fields['days_remaining'] !== null ? (int) $item->fields['days_remaining'] : null,
            ];
            $ordered_ids[] = $id;
            $printer_ids[(int) $item->fields['printers_id']] = (int) $item->fields['printers_id'];
        }
        if (empty($items)) {
            return;
        }

        $result = PluginPrintgestionExpedition::createPurchaseOrder(
            $items,
            !empty($input['send_planif']),
            !empty($input['send_courtesy'])
        );
        foreach ($ordered_ids as $id) {
            $ma->itemDone($item->getType(), $id, $result['ok'] ? MassiveAction::ACTION_OK : MassiveAction::ACTION_KO);
        }

        $messages = !$result['ok']
            ? explode("\n", (string) $result['error'])
            : (!empty($result['not_sent'])
                ? [sprintf(__('%d expédition(s) enregistrée(s).', 'printgestion'), (int) $result['created']), $result['not_sent']]
                : [sprintf(__('Commande envoyée aux Achats : %d expédition(s) enregistrée(s).', 'printgestion'), (int) $result['created'])]);
        foreach (array_merge($messages, (array) $result['warnings']) as $message) {
            $message = trim(ltrim((string) $message, '- '));
            if ($message !== '') {
                $ma->addMessage(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
            }
        }

        if ($result['ok']) {
            self::rebuild(array_values($printer_ids));
        }
    }

    // ── Moteur de recherche ───────────────────────────────────────────────────

    public static function getStatusLabels(): array {
        return [
            PluginPrintgestionAlert::STATUS_CRITICAL => __('Critique', 'printgestion'),
            PluginPrintgestionAlert::STATUS_WATCH    => __('À surveiller', 'printgestion'),
            PluginPrintgestionAlert::STATUS_OK       => __('Bon', 'printgestion'),
        ];
    }

    public static function getLockLabels(): array {
        return [
            PluginPrintgestionGuard::REASON_IN_PROGRESS => __('Envoi en cours', 'printgestion'),
            PluginPrintgestionGuard::REASON_DEMANDE     => __('Demande en cours', 'printgestion'),
            PluginPrintgestionGuard::REASON_GUARD       => __('Garde après pose', 'printgestion'),
            PluginPrintgestionGuard::REASON_TICKET      => __('Ticket récent', 'printgestion'),
            'bypassed'                                  => __('Contournement (commandable)', 'printgestion'),
        ];
    }

    static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $value = (string) ($values[$field] ?? '');
        $esc   = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        switch ($field) {
            case 'status':
                $classes = ['critical' => 'bg-red text-red-fg', 'watch' => 'bg-orange text-orange-fg', 'ok' => 'bg-green text-green-fg'];
                // Marqueur du menu clic droit : l'identité de la ligne quand la case native manque (lecture seule).
                return "<span class='badge " . ($classes[$value] ?? 'bg-secondary text-secondary-fg') . "'>"
                    . $esc(self::getStatusLabels()[$value] ?? $value) . '</span>'
                    . PluginPrintgestionContextmenu::rowMarker(self::class, (int) ($options['raw_data']['id'] ?? 0));

            case 'lock_reason':
                if ($value === '') {
                    return "<span class='text-muted'>—</span>";
                }
                $class = $value === 'bypassed' ? 'bg-yellow-lt' : 'bg-secondary text-secondary-fg';
                return "<span class='badge {$class}'>" . $esc(self::getLockLabels()[$value] ?? $value) . '</span>';

            case 'expedition_statut':
                return $value === ''
                    ? "<span class='text-muted'>—</span>"
                    : PluginPrintgestionExpedition::getSpecificValueToDisplay('statut', ['statut' => $value]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        $options['value']   = $values[$field] ?? '';
        switch ($field) {
            case 'status':
                return Dropdown::showFromArray($name, self::getStatusLabels(), $options);
            case 'lock_reason':
                return Dropdown::showFromArray($name, self::getLockLabels(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function rawSearchOptions() {
        $table = self::getTable();
        $tab   = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = ['id' => '1', 'table' => 'glpi_printers', 'field' => 'name',
                  'name' => _n('Imprimante', 'Imprimantes', 1, 'printgestion'), 'datatype' => 'itemlink', 'massiveaction' => false];
        $tab[] = ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename',
                  'name' => Entity::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false];
        $tab[] = ['id' => '2', 'table' => $table, 'field' => 'toner_property',
                  'name' => __('Toner', 'printgestion'), 'datatype' => 'string', 'massiveaction' => false];
        $tab[] = ['id' => '3', 'table' => $table, 'field' => 'cartridge_label',
                  'name' => __('Cartouche', 'printgestion'), 'datatype' => 'string', 'massiveaction' => false];
        $tab[] = ['id' => '4', 'table' => $table, 'field' => 'level_percent',
                  'name' => __('Niveau', 'printgestion'), 'datatype' => 'number', 'unit' => '%', 'massiveaction' => false];
        $tab[] = ['id' => '5', 'table' => $table, 'field' => 'days_remaining',
                  'name' => __('Jours restants', 'printgestion'), 'datatype' => 'number', 'massiveaction' => false];
        $tab[] = ['id' => '6', 'table' => $table, 'field' => 'status',
                  'name' => __('Statut', 'printgestion'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false];
        $tab[] = ['id' => '7', 'table' => $table, 'field' => 'toner_color',
                  'name' => __('Couleur', 'printgestion'), 'datatype' => 'string', 'massiveaction' => false];
        $tab[] = ['id' => '8', 'table' => $table, 'field' => 'has_expedition',
                  'name' => __('Expédition en cours', 'printgestion'), 'datatype' => 'bool', 'massiveaction' => false];
        $tab[] = ['id' => '9', 'table' => $table, 'field' => 'is_snoozed',
                  'name' => __('Alertes suspendues', 'printgestion'), 'datatype' => 'bool', 'massiveaction' => false];
        $tab[] = ['id' => '10', 'table' => $table, 'field' => 'date_compute',
                  'name' => __('Calculé le', 'printgestion'), 'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => '11', 'table' => $table, 'field' => 'lock_reason',
                  'name' => __('Verrou', 'printgestion'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false];
        $tab[] = ['id' => '12', 'table' => $table, 'field' => 'lock_message',
                  'name' => __('Motif du verrou', 'printgestion'), 'datatype' => 'text', 'massiveaction' => false];
        $tab[] = ['id' => '13', 'table' => $table, 'field' => 'ref_error',
                  'name' => __('Référence non résolue', 'printgestion'), 'datatype' => 'text', 'massiveaction' => false];
        $tab[] = ['id' => '15', 'table' => $table, 'field' => 'level_suspect',
                  'name' => __('Niveau figé suspect', 'printgestion'), 'datatype' => 'bool', 'massiveaction' => false];
        $tab[] = ['id' => '16', 'table' => $table, 'field' => 'expedition_statut',
                  'name' => __('Envoi en cours', 'printgestion'), 'datatype' => 'specific', 'nosearch' => true, 'massiveaction' => false];
        $tab[] = ['id' => '17', 'table' => $table, 'field' => 'is_estimate',
                  'name' => __('Estimation approximative', 'printgestion'), 'datatype' => 'bool', 'massiveaction' => false];

        return $tab;
    }
}
