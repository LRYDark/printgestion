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
 *     toute la commande ; une ligne que Gesconso ne peut pas recevoir est retirée sur
 *     confirmation au clic (pg_exclude), une référence manquante se saisit dans la fenêtre
 *     (Cartridgesnmp::renderQuickLink(), aperçu en direct par ajax/order_preview.php) ;
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
     * Un seul calcul à la fois (verrou LOCK_NAME) : un recalcul complet déjà en cours fait sauter un autre complet ;
     * un recalcul partiel qui le croise devient une demande, traitée par la tâche dans la minute.
     *
     * @return int Nombre de lignes matérialisées (0 si le calcul a été reporté).
     */
    public static function rebuild(?array $printer_ids = null): int {
        $ids = null;
        if ($printer_ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $printer_ids))));
            if (empty($ids)) {
                return 0;
            }
        }
        self::$last_skipped = !PluginPrintgestionLogger::lock(self::LOCK_NAME);
        if (self::$last_skipped) {
            if ($ids === null) {
                PluginPrintgestionLogger::info('alertes', 'Recalcul complet des alertes sauté : un autre est déjà en cours.');
            } else {
                // Le recalcul complet en cours a pu lire l'état d'avant l'action : un autre est demandé.
                self::requestRebuild();
            }
            return 0;
        }
        $timer = microtime(true);
        try {
            $count = self::doRebuild($ids);
        } finally {
            PluginPrintgestionLogger::releaseLock(self::LOCK_NAME);
        }
        PluginPrintgestionLogger::duration(
            'alertes',
            $ids === null ? 'Recalcul complet des alertes' : sprintf('Recalcul des alertes de %d imprimante(s)', count($ids)),
            $timer,
            sprintf('%d toner(s)', $count)
        );
        return $count;
    }

    /** Verrou du calcul (Logger::lock()). */
    const LOCK_NAME = 'alertview';

    private static function doRebuild(?array $ids): int {
        global $DB, $GLPI_CACHE;

        $started = date('Y-m-d H:i:s');
        $table   = self::getTable();

        // Un inventaire reçu après un relevé manuel rend ce relevé caduc : la sonde a raison.
        PluginPrintgestionManualreading::supersedeByInventory();
        // Calcul lourd, TOUTES entités : la table est lue ensuite avec la restriction
        // d'entité native (colonne entities_id) — ne jamais l'exposer sans elle.
        $rows = PluginPrintgestionAlert::listAll(null, false, $ids);
        $now  = $_SESSION['glpi_currenttime'] ?? $started;

        $records = self::buildRecords($rows, $now);

        // Remplacement dans une transaction courte (lignes déjà prêtes) : pendant le calcul, l'écran montre les
        // alertes d'avant, jamais une table vide (ce que faisait le vidage TRUNCATE, hors transaction par nature).
        $DB->beginTransaction();
        try {
            $DB->delete($table, $ids === null ? [1] : ['printers_id' => $ids]);
            foreach ($records as $record) {
                $DB->insert($table, $record);
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            PluginPrintgestionLogger::error('alertes', 'Recalcul des alertes annulé : la table garde le calcul précédent.', $e);
            throw $e;
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

    /** Lignes de la table, prêtes à insérer : référence de cartouche résolue pour les toners en alerte. */
    private static function buildRecords(array $rows, string $now): array {
        $records = [];
        foreach ($rows as $r) {
            // Référence : résolue pour les toners en alerte (ceux qu'on commande). Aucun stock :
            // il est dans Sage.
            $ref_error = null;
            if ($r['status'] !== PluginPrintgestionAlert::STATUS_OK) {
                $ref       = PluginPrintgestionSnmpmapping::resolveCartridge((int) $r['printers_id'], (string) $r['property']);
                $ref_error = $ref['cartridgeitems_id'] > 0 ? null : (string) $ref['message'];
            }
            $lock = $r['lock'] ?? null;

            $records[] = [
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
            ];
        }
        return $records;
    }

    // ── Recalcul demandé depuis l'écran, fait par la tâche PrintgestionRebuildAlerts ──

    /** Cache GLPI : date de la demande de recalcul complet en attente. */
    const REQUEST_KEY = 'plugin_printgestion_alertview_request';

    /** Demande en attente depuis plus longtemps : la tâche ne passe pas (affiché à l'écran). */
    const REQUEST_LATE_SECONDS = 300;

    /**
     * Demande un recalcul complet, fait hors de la page par la tâche minute PrintgestionRebuildAlerts. Une demande
     * déjà en attente garde sa date. La tâche est enregistrée si elle manque (plugin mis à jour par copie).
     */
    public static function requestRebuild(): void {
        global $GLPI_CACHE;

        if (!isset($GLPI_CACHE)) {
            return;
        }
        if (self::getRequestedAt() === null) {
            $GLPI_CACHE->set(self::REQUEST_KEY, date('Y-m-d H:i:s'), DAY_TIMESTAMP);
        }
        // Cette tâche seulement : une autre tâche absente l'a peut-être été voulue (carte Santé pour celles-là).
        $task = new CronTask();
        if (!$task->getFromDBbyName(PluginPrintgestionReminder::class, 'PrintgestionRebuildAlerts')) {
            foreach (PluginPrintgestionReminder::getCronTaskDefinitions() as $definition) {
                if ($definition['name'] === 'PrintgestionRebuildAlerts') {
                    CronTask::register($definition['itemtype'], $definition['name'], $definition['frequency'], $definition['options']);
                }
            }
        }
    }

    /** Cache GLPI : date de la demande en cours de traitement par la tâche (l'écran attend la fin du calcul). */
    const RUNNING_KEY = 'plugin_printgestion_alertview_running';

    /** Date de la demande pas encore prise par la tâche, sinon null. */
    public static function getRequestedAt(): ?string {
        global $GLPI_CACHE;

        $value = isset($GLPI_CACHE) ? $GLPI_CACHE->get(self::REQUEST_KEY) : null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /** Date de la demande de recalcul en attente ou en cours de calcul, sinon null (affichage, état AJAX). */
    public static function getPendingRequest(): ?string {
        global $GLPI_CACHE;

        $requested = self::getRequestedAt();
        if ($requested !== null || !isset($GLPI_CACHE)) {
            return $requested;
        }
        $value = $GLPI_CACHE->get(self::RUNNING_KEY);
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /** Dernier appel de rebuild() reporté (verrou déjà pris). */
    private static bool $last_skipped = false;

    /**
     * Tâche minute : traite la demande en attente, ou une table vide (première installation). La demande est retirée
     * avant le calcul : une demande posée pendant le calcul (action non prise en compte) attend le passage suivant ;
     * un calcul reporté remet la demande, avec sa date d'origine.
     *
     * @return ?int toners recalculés, null s'il n'y avait rien à faire
     */
    public static function processRequest(): ?int {
        global $GLPI_CACHE;

        $requested = self::getRequestedAt();
        if ($requested === null && (int) countElementsInTable(self::getTable()) > 0) {
            return null;
        }
        if ($requested !== null && isset($GLPI_CACHE)) {
            $GLPI_CACHE->set(self::RUNNING_KEY, $requested, HOUR_TIMESTAMP);
            $GLPI_CACHE->delete(self::REQUEST_KEY);
        }
        try {
            $count = self::rebuild();
        } finally {
            if (isset($GLPI_CACHE)) {
                $GLPI_CACHE->delete(self::RUNNING_KEY);
            }
        }
        if (self::$last_skipped && $requested !== null && isset($GLPI_CACHE) && self::getRequestedAt() === null) {
            $GLPI_CACHE->set(self::REQUEST_KEY, $requested, DAY_TIMESTAMP);
        }
        return $count;
    }

    /** Table vide (1ʳᵉ visite avant le passage de la tâche horaire) : calcul demandé, jamais fait dans la page. */
    public static function rebuildIfEmpty(): void {
        if ((int) countElementsInTable(self::getTable()) === 0) {
            self::requestRebuild();
        }
    }

    /**
     * Signale une action qui rend la vue périmée (commande, annulation, seuils…), et demande le recalcul complet qui
     * la remet à jour en arrière-plan : le bandeau « pas encore reflétées » tombe dans la minute, pas à l'heure.
     */
    public static function markStale(): void {
        global $GLPI_CACHE;
        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->set(self::STALE_KEY, date('Y-m-d H:i:s'), 7 * DAY_TIMESTAMP);
        }
        self::requestRebuild();
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
                // Avant le clic : ce qui ne pourra pas être commandé, et ce qui mérite d'être vu (décompte, liste).
                $ids     = array_map('intval', array_values($ma->getItems()[self::class] ?? []));
                $items   = self::getOrderItems($ids);
                $preview = self::renderOrderPreview($items, []);
                $rand    = mt_rand();
                $flags   = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
                // Bloc recalculé en direct pendant la saisie d'une référence (ajax/order_preview.php).
                // Fenêtre native centrée (classe « center ») : le contenu de la commande se lit aligné à gauche.
                echo "<div class='text-start'>";
                echo "<div id='pg-order-preview-{$rand}'>" . $preview['html'] . "</div>";
                // Toner sans cartouche liée : sa référence se saisit ici, la cartouche est créée à l'envoi.
                echo PluginPrintgestionCartridgesnmp::renderQuickLink(PluginPrintgestionCartridgesnmp::getMissingReferences($items));
                echo "<p class='text-muted small'>" . $esc(__('Une commande pour les toners cochés : fichier Gesconso envoyé aux Achats, une expédition par toner. Une cartouche sans référence, code client ou adresse de livraison est retirée de la commande, après confirmation à l\'envoi. Refusée en entier si une ligne est verrouillée (envoi ou demande en cours, garde, ticket) : rien n\'est alors enregistré.', 'printgestion')) . "</p>";
                // Options de l'envoi, regroupées : chaque case à côté de son libellé.
                echo "<div class='border rounded px-3 py-2 mb-3'>";
                foreach ([
                    'send_planif'       => ['pg-ma-planif', 'ti ti-truck', __('Prévenir la planification (logistique)', 'printgestion')],
                    'send_courtesy'     => ['pg-ma-courtesy', 'ti ti-mail', __('Mail de courtoisie au client (usager renseigné sur la fiche imprimante uniquement)', 'printgestion')],
                    'download_gesconso' => ['pg-ma-download', 'ti ti-file-spreadsheet', __('Télécharger le fichier Gesconso envoyé aux Achats', 'printgestion')],
                ] as $name => [$id, $icon, $label]) {
                    echo "<div class='form-check my-1'><input type='checkbox' class='form-check-input' name='{$name}' value='1' id='{$id}'>"
                        . "<label class='form-check-label' for='{$id}'><i class='{$icon} me-1'></i>" . $esc($label) . "</label></div>";
                }
                echo "</div></div>";
                echo Html::submit(__('Envoyer la commande', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-primary', 'id' => "pg-order-submit-{$rand}"]);
                echo "<script>window.pgOrderPreview && window.pgOrderPreview(" . json_encode([
                    'box'      => "pg-order-preview-{$rand}",
                    'button'   => "pg-order-submit-{$rand}",
                    'url'      => PLUGIN_PRINTGESTION_WEBDIR . '/ajax/order_preview.php',
                    'ids'      => $ids,
                    'blocked'  => (object) $preview['blocked'],
                    'total'    => $preview['total'],
                    'messages' => [
                        'none'    => __('Aucune cartouche ne peut être commandée : corrigez les lignes en rouge avant d\'envoyer.', 'printgestion'),
                        'head'    => __('Ces cartouches ne peuvent pas être écrites dans le fichier Gesconso et ne seront PAS commandées :', 'printgestion'),
                        'confirm' => __('Envoyer quand même la commande des %d autre(s) cartouche(s) ?', 'printgestion'),
                    ],
                ], $flags) . ");</script>";
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

    /** Lignes cochées à commander, réduites au périmètre de l'utilisateur : [['printers_id', 'property']]. */
    public static function getOrderItems(array $ids): array {
        $items = [];
        foreach ($ids as $id) {
            $row = new self();
            if ($row->getFromDB((int) $id) && PluginPrintgestionSecurity::canAccessPrinter((int) $row->fields['printers_id'])) {
                $items[] = ['printers_id' => (int) $row->fields['printers_id'], 'property' => (string) $row->fields['toner_property']];
            }
        }
        return $items;
    }

    /**
     * Aperçu de la commande, avec les références saisies (pg_newcart) contrôlées comme si les cartouches existaient :
     * en rouge ce qui ne pourra pas être écrit dans le fichier Gesconso, en vert ce que la saisie débloque.
     *
     * @return array ['html' => string, 'blocked' => « printers_id|property » => motif, 'total' => int]
     */
    public static function renderOrderPreview(array $items, $newcart): array {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $quick   = PluginPrintgestionCartridgesnmp::previewQuickLink(PluginPrintgestionCartridgesnmp::getMissingReferences($items), $newcart);
        $preview = PluginPrintgestionExpedition::previewPurchaseOrder($items, $quick['cartridges']);
        $blocked = $preview['line_errors'];
        // Saisie refusée (référence inconnue de Sage, doublon…) : son motif remplace « aucune cartouche liée ».
        foreach ($quick['errors'] as $key => $message) {
            if (isset($blocked[$key])) {
                $blocked[$key] = [($preview['labels'][$key] ?? $key) . ' : ' . $message];
            }
        }
        $ready = array_diff_key($quick['notes'], $blocked);
        $total = count($preview['labels']);
        $html  = '';

        if (!empty($blocked)) {
            $html .= "<div class='alert alert-danger py-2'><div class='w-100'><div class='fw-bold'><i class='ti ti-alert-circle me-1'></i>" . $esc(sprintf(
                _n('%d cartouche ne peut pas être écrite dans le fichier Gesconso : elle ne sera pas commandée.', '%d cartouches ne peuvent pas être écrites dans le fichier Gesconso : elles ne seront pas commandées.', count($blocked), 'printgestion'),
                count($blocked)
            )) . "</div><ul class='small mb-1'>";
            foreach (array_slice(array_merge(...array_values($blocked)), 0, 20) as $message) {
                $html .= '<li>' . $esc($message) . '</li>';
            }
            $html .= "</ul><div class='small'>" . $esc($total > count($blocked)
                ? sprintf(__('Les %d autre(s) partent normalement, après confirmation à l\'envoi.', 'printgestion'), $total - count($blocked))
                : __('Aucune cartouche commandable en l\'état.', 'printgestion')) . "</div></div></div>";
        }
        if ((empty($blocked) && $total > 0) || !empty($ready)) {
            $html .= "<div class='alert alert-success py-2'><div class='w-100'><div class='fw-bold'><i class='ti ti-circle-check me-1'></i>" . $esc(empty($blocked)
                ? sprintf(_n('%d cartouche pourra être écrite dans le fichier Gesconso.', 'Les %d cartouches pourront être écrites dans le fichier Gesconso.', $total, 'printgestion'), $total)
                : __('Débloquées par votre saisie :', 'printgestion')) . "</div>";
            if (!empty($ready)) {
                $html .= "<ul class='small mb-0'>";
                foreach ($ready as $key => $note) {
                    $html .= '<li>' . $esc(($preview['labels'][$key] ?? $key) . ' : ' . $note) . '</li>';
                }
                $html .= '</ul>';
            }
            $html .= '</div></div>';
        }

        return [
            'html'    => $html . PluginPrintgestionGesconso::renderNoticesSummary($preview['notices'], 'pg-order-notices'),
            'blocked' => array_map(static fn(array $messages) => (string) reset($messages), $blocked),
            'total'   => $total,
        ];
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
        // Lignes que Gesconso ne pouvait pas recevoir, retirées sur confirmation au clic : ni commandées ni enregistrées.
        $exclude  = array_flip(array_map('strval', (array) ($input['pg_exclude'] ?? [])));
        $excluded = [];

        foreach ($ids as $id) {
            if (!Session::haveRight('plugin_printgestion_validation', UPDATE)
                || !$item->getFromDB($id)
                || !PluginPrintgestionSecurity::canAccessPrinter((int) $item->fields['printers_id'])) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                continue;
            }
            if (isset($exclude[(int) $item->fields['printers_id'] . '|' . (string) $item->fields['toner_property']])) {
                $excluded[] = Dropdown::getDropdownName('glpi_printers', (int) $item->fields['printers_id']) . ' — ' . $item->fields['toner_property'];
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
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
        if (!empty($excluded)) {
            $ma->addMessage(htmlspecialchars(sprintf(
                __('Non commandée(s), retirée(s) de la commande à votre demande : %s.', 'printgestion'),
                implode(', ', $excluded)
            ), ENT_QUOTES, 'UTF-8'));
        }
        if (empty($items)) {
            return;
        }

        // Références saisies dans le sous-formulaire : cartouches créées ou liées avant la commande, qui les résout.
        if (!empty($input['pg_newcart'])) {
            $quick = PluginPrintgestionCartridgesnmp::applyQuickLink(
                PluginPrintgestionCartridgesnmp::getMissingReferences($items),
                $input['pg_newcart']
            );
            foreach ($quick['messages'] as $message) {
                $ma->addMessage(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
            }
            if (!$quick['ok']) {
                $ma->addMessage(htmlspecialchars(__('Commande non envoyée : aucune cartouche n\'a été créée.', 'printgestion'), ENT_QUOTES, 'UTF-8'));
                foreach ($ordered_ids as $id) {
                    $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                }
                return;
            }
            if ($quick['done']) {
                // Colonne « référence » des lignes à jour même si la commande est refusée ensuite.
                self::rebuild(array_values($printer_ids));
            }
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

        // Fichier archivé de la commande : lien dans le message de fin, et téléchargement direct si la case est cochée.
        $orders_id = (int) ($result['order_id'] ?? 0);
        if ($result['ok'] && $orders_id > 0) {
            Session::addMessageAfterRedirect(
                "<i class='ti ti-file-spreadsheet me-1'></i><a href='" . htmlspecialchars(PluginPrintgestionPurchaseorder::getDownloadURL($orders_id), ENT_QUOTES, 'UTF-8') . "'>"
                    . htmlspecialchars(__('Télécharger le fichier Gesconso de cette commande', 'printgestion'), ENT_QUOTES, 'UTF-8') . '</a>',
                false,
                INFO
            );
            if (!empty($input['download_gesconso'])) {
                PluginPrintgestionPurchaseorder::queueDownload($orders_id);
            }
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
