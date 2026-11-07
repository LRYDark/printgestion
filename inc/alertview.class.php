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

    static string $rightname = 'plugin_printgestion_dashboard';

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
            $actions[$self . $sep . 'pg_snooze']     = "<i class='ti ti-bell-off me-1'></i>" . __('Ne plus alerter pendant…', 'printgestion');
            $actions[$self . $sep . 'pg_unsnooze']   = "<i class='ti ti-bell me-1'></i>" . __('Réactiver les alertes', 'printgestion');
            $actions[$self . $sep . 'pg_thresholds'] = "<i class='ti ti-adjustments me-1'></i>" . __('Seuils d\'alerte de l\'imprimante…', 'printgestion');
            $actions[$self . $sep . 'pg_suspect']    = "<i class='ti ti-snowflake me-1'></i>" . __('Niveau figé suspect…', 'printgestion');
        }
        if (Session::haveRight('plugin_printgestion_expedition', UPDATE)) {
            $actions[$self . $sep . 'pg_cancel_shipment'] = "<i class='ti ti-truck-off me-1'></i>" . __('Annuler l\'envoi en cours…', 'printgestion');
        }
        return $actions;
    }

    static function showMassiveActionsSubForm(MassiveAction $ma) {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        switch ($ma->getAction()) {
            case 'pg_order':
                // Avant le clic : ce qui ne pourra pas être commandé, et ce qui mérite d'être vu (décompte, liste).
                $ids     = array_map('intval', array_values($ma->getItems()[self::class] ?? []));
                // Lieu de livraison proposé : dernier sous-lieu choisi pour l'imprimante, sinon rien (choix obligatoire).
                $items   = self::getOrderItems($ids, self::getDefaultLocations(self::getOrderItems($ids)));
                $preview = self::renderOrderPreview($items, []);
                $rand    = mt_rand();
                $flags   = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
                // Bloc recalculé en direct pendant la saisie d'une référence ou le choix d'un lieu (ajax/order_preview.php).
                // Fenêtre native centrée (classe « center ») : le contenu de la commande se lit aligné à gauche.
                echo "<div class='text-start'>";
                echo self::renderLocationChoice($items);
                echo "<div id='pg-order-preview-{$rand}'>" . $preview['html'] . "</div>";
                // Toner sans cartouche liée : sa référence se saisit ici, la cartouche est créée à l'envoi.
                echo PluginPrintgestionCartridgesnmp::renderQuickLink(PluginPrintgestionCartridgesnmp::getMissingReferences($items));
                echo "<p class='text-muted small'>" . $esc(__('Une commande pour les toners cochés : fichier Gesconso envoyé aux Achats, une expédition par toner. Une cartouche sans référence, code client ou adresse de livraison est retirée de la commande, après confirmation à l\'envoi. Refusée en entier si une ligne est verrouillée (envoi ou demande en cours, garde, ticket) : rien n\'est alors enregistré.', 'printgestion')) . "</p>";
                // Options de l'envoi, regroupées : chaque case à côté de son libellé.
                echo "<div class='border rounded px-3 py-2 mb-3'>";
                foreach ([
                    'send_planif'       => ['pg-ma-planif', 'ti ti-truck', __('Prévenir la planification (logistique)', 'printgestion')],
                    'send_courtesy'     => ['pg-ma-courtesy', 'ti ti-mail', __('Mail de courtoisie au client (mails du lieu de livraison, sinon utilisateur de la fiche imprimante, sinon messagerie de l\'entité)', 'printgestion')],
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
                        'location' => __('Choisissez le lieu de livraison de : %s', 'printgestion'),
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

            case 'pg_suspect':
                // Forçage jusqu'au prochain changement de cartouche du toner ; « Automatique » rend la main au calcul.
                echo "<div class='text-start'><p class='text-muted small'>" . $esc(__('Le calcul juge un niveau figé suspect quand l\'imprimante a imprimé l\'équivalent d\'au moins 3 % de sa cartouche sans que le niveau bouge. Un forçage passe avant ce calcul et tient jusqu\'au prochain changement de cartouche de ce toner.', 'printgestion')) . "</p>";
                Dropdown::showFromArray('pg_suspect', [
                    '1'    => __('Oui : niveau figé suspect', 'printgestion'),
                    '0'    => __('Non : le niveau est fiable', 'printgestion'),
                    'auto' => __('Automatique (calcul du plugin)', 'printgestion'),
                ], ['value' => 'auto']);
                echo "</div><div class='mt-2'>" . Html::submit(__('Appliquer', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-primary']) . "</div>";
                return true;

            case 'pg_cancel_shipment':
                echo "<div class='text-start'><p class='text-muted small'>" . $esc(__('L\'envoi en cours de chaque toner coché passe « Annulée » : la cartouche redevient commandable, le motif est gardé dans les notes de l\'envoi.', 'printgestion')) . "</p>"
                    . "<textarea class='form-control mb-2' name='pg_cancel_reason' rows='2' required maxlength='1000' placeholder='"
                    . $esc(__('Motif de l\'annulation (obligatoire)', 'printgestion')) . "'></textarea></div>";
                echo Html::submit(__('Annuler l\'envoi', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-danger']);
                return true;

            case 'pg_thresholds':
                // Par imprimante, pour toutes ses cartouches ; un champ vide ne change rien.
                $global = PluginPrintgestionConfig::getInstance()->fields;
                echo "<div class='text-start'><p class='text-muted small'>" . $esc(__('Seuils propres aux imprimantes des lignes cochées (toutes leurs cartouches). Un champ laissé vide ne change rien ; « Revenir aux valeurs globales » efface les seuils propres.', 'printgestion')) . "</p><div class='row g-2'>";
                foreach ([
                    'threshold_level'         => [__('Critique sous (%)', 'printgestion'), (int) ($global['threshold_level'] ?? 15)],
                    'threshold_days_critical' => [__('Critique sous (jours restants)', 'printgestion'), (int) ($global['threshold_days_critical'] ?? PluginPrintgestionAlert::DEFAULT_DAYS_CRITICAL)],
                    'threshold_days'          => [__('À surveiller sous (jours restants)', 'printgestion'), (int) ($global['threshold_days'] ?? 30)],
                    'pages_per_cartridge'     => [__('Rendement (pages par cartouche)', 'printgestion'), (int) ($global['default_pages_per_cartridge'] ?? 5000)],
                ] as $name => [$label, $default]) {
                    echo "<div class='col-sm-6'><label class='form-label mb-1'>" . $esc($label) . "</label>"
                        . "<input type='number' min='0' class='form-control' name='pg_th[" . $name . "]' placeholder='" . $esc(sprintf(__('global : %d', 'printgestion'), $default)) . "'></div>";
                }
                echo "</div><div class='form-check mt-2'><input type='checkbox' class='form-check-input' name='pg_th_reset' value='1' id='pg-th-reset'>"
                    . "<label class='form-check-label' for='pg-th-reset'>" . $esc(__('Revenir aux valeurs globales', 'printgestion')) . "</label></div></div>";
                echo "<div class='mt-2'>" . Html::submit(__('Enregistrer', 'printgestion'), ['name' => 'massiveaction', 'class' => 'btn btn-primary']) . "</div>";
                return true;
        }
        return parent::showMassiveActionsSubForm($ma);
    }

    /**
     * Lignes cochées à commander, réduites au périmètre de l'utilisateur : [['printers_id', 'property', 'locations_id']].
     *
     * @param array $locations printers_id => lieu de livraison choisi (0 ou absent : aucun choix)
     */
    public static function getOrderItems(array $ids, array $locations = []): array {
        $items = [];
        foreach ($ids as $id) {
            $row = new self();
            if ($row->getFromDB((int) $id) && PluginPrintgestionSecurity::canAccessPrinter((int) $row->fields['printers_id'])) {
                $printers_id = (int) $row->fields['printers_id'];
                $items[] = [
                    'printers_id'  => $printers_id,
                    'property'     => (string) $row->fields['toner_property'],
                    'locations_id' => max(0, (int) ($locations[$printers_id] ?? 0)),
                ];
            }
        }
        return $items;
    }

    /**
     * Imprimantes de la commande dont le lieu a des sous-lieux : c'est pour elles que le lieu de livraison se choisit.
     *
     * @return array printers_id => ['name' => string, 'locations_id' => lieu de l'imprimante, 'entities_id' => int]
     */
    public static function getPrintersToLocate(array $items): array {
        $out = [];
        foreach ($items as $item) {
            $printers_id = (int) $item['printers_id'];
            if (isset($out[$printers_id])) {
                continue;
            }
            $printer = new Printer();
            if (!$printer->getFromDB($printers_id)) {
                continue;
            }
            $locations_id = (int) $printer->fields['locations_id'];
            if (!empty(PluginPrintgestionDeliverylocation::subLocations($locations_id))) {
                $out[$printers_id] = [
                    'name'         => (string) $printer->fields['name'],
                    'locations_id' => $locations_id,
                    'entities_id'  => (int) $printer->fields['entities_id'],
                ];
            }
        }
        return $out;
    }

    /** Lieux proposés d'office : le dernier sous-lieu choisi pour chaque imprimante qui en a (printers_id => locations_id). */
    public static function getDefaultLocations(array $items): array {
        $out = [];
        foreach (self::getPrintersToLocate($items) as $printers_id => $printer) {
            $last = PluginPrintgestionExpedition::getLastDeliveryLocation($printers_id, $printer['locations_id']);
            if ($last > 0) {
                $out[$printers_id] = $last;
            }
        }
        return $out;
    }

    /**
     * Choix du lieu de livraison, une ligne par imprimante dont le lieu a des sous-lieux : liste native des lieux
     * (Location::dropdown), réduite au lieu de l'imprimante et à tous ses sous-lieux. Rien pour les autres
     * imprimantes : elles sont livrées à leur lieu.
     */
    public static function renderLocationChoice(array $items): string {
        $printers = self::getPrintersToLocate($items);
        if (empty($printers)) {
            return '';
        }
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $chosen = [];
        foreach ($items as $item) {
            $chosen[(int) $item['printers_id']] = (int) ($item['locations_id'] ?? 0);
        }
        $html = "<div class='border rounded px-3 py-2 mb-3'><div class='fw-bold mb-1'><i class='ti ti-map-pin me-1'></i>"
            . $esc(__('Lieu de livraison', 'printgestion')) . "</div><div class='text-muted small mb-2'>"
            . $esc(__('Ces imprimantes ont des sous-lieux (étages, services…) : choisissez où livrer. Le lieu choisi donne l\'adresse de livraison Sage et le contact du mail de courtoisie ; il est proposé à la commande suivante.', 'printgestion'))
            . "</div>";
        foreach ($printers as $printers_id => $printer) {
            $html .= "<div class='row g-2 align-items-center mb-1' data-pg-location-for='" . $esc($printer['name']) . "'>"
                . "<div class='col-sm-4 text-truncate'>" . $esc($printer['name']) . "</div><div class='col-sm-8'>"
                . Location::dropdown([
                    'name'                => 'pg_location[' . $printers_id . ']',
                    'value'               => $chosen[$printers_id] ?? 0,
                    'condition'           => ['glpi_locations.id' => array_merge([$printer['locations_id']], PluginPrintgestionDeliverylocation::subLocations($printer['locations_id']))],
                    'display_emptychoice' => true,
                    'emptylabel'          => __('— Choisir le lieu de livraison —', 'printgestion'),
                    'width'               => '100%',
                    'display'             => false,
                ])
                . "</div></div>";
        }
        return $html . "</div>";
    }

    /**
     * Imprimantes dont le lieu de livraison reste à choisir : lieu à sous-lieux, et aucun lieu choisi sous le sien.
     *
     * @return string[] noms des imprimantes
     */
    public static function getMissingLocations(array $items): array {
        $missing = [];
        $chosen  = [];
        foreach ($items as $item) {
            $chosen[(int) $item['printers_id']] = (int) ($item['locations_id'] ?? 0);
        }
        foreach (self::getPrintersToLocate($items) as $printers_id => $printer) {
            if (!PluginPrintgestionDeliverylocation::isWithin($chosen[$printers_id] ?? 0, $printer['locations_id'])) {
                $missing[] = $printer['name'];
            }
        }
        return $missing;
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
            'html'    => $html . self::renderDeliverySummary($items)
                . PluginPrintgestionGesconso::renderNoticesSummary($preview['notices'], 'pg-order-notices'),
            'blocked' => array_map(static fn(array $messages) => (string) reset($messages), $blocked),
            'total'   => $total,
        ];
    }

    /**
     * Où part chaque imprimante, avant le clic : lieu de livraison, intitulé Sage (et d'où il vient), adresses du mail
     * de courtoisie (et d'où elles viennent). Un lieu encore à choisir est en rouge.
     */
    public static function renderDeliverySummary(array $items): string {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $missing = array_flip(self::getMissingLocations($items));
        $lines   = [];
        $seen    = [];
        foreach ($items as $item) {
            $printers_id = (int) $item['printers_id'];
            if (isset($seen[$printers_id])) {
                continue;
            }
            $seen[$printers_id] = true;
            $printer = new Printer();
            if (!$printer->getFromDB($printers_id)) {
                continue;
            }
            $name = (string) $printer->fields['name'];
            if (isset($missing[$name])) {
                $lines[] = "<li class='text-danger'><strong>" . $esc($name) . '</strong> : '
                    . $esc(__('lieu de livraison à choisir ci-dessus.', 'printgestion')) . '</li>';
                continue;
            }
            $entities_id = (int) $printer->fields['entities_id'];
            $delivery_id = PluginPrintgestionDeliverylocation::resolveForPrinter((int) $printer->fields['locations_id'], (int) ($item['locations_id'] ?? 0));
            $delivery    = PluginPrintgestionDeliverylocation::describe($delivery_id, $entities_id);
            $rule        = PluginPrintgestionSage::describeRule($entities_id, $delivery);
            $courtesy    = PluginPrintgestionExpedition::resolveCourtesyRecipients($printers_id, $delivery_id);

            $parts   = [$delivery['completename'] !== ''
                ? sprintf(__('livraison « %s »', 'printgestion'), $delivery['completename'])
                : __('imprimante sans lieu', 'printgestion')];
            $parts[] = $rule['label'] === ''
                ? __('intitulé Sage absent', 'printgestion')
                : sprintf(
                    $rule['label_source'] === 'location' ? __('intitulé Sage « %1$s » (code du lieu « %2$s »)', 'printgestion') : __('intitulé Sage « %1$s » (commentaires de l\'entité)', 'printgestion'),
                    $rule['label'],
                    $rule['label_location']
                );
            $sources = [
                'location' => __('commentaire du lieu « %s »', 'printgestion'),
                'user'     => __('utilisateur %s', 'printgestion'),
                'entity'   => __('messagerie de l\'entité « %s »', 'printgestion'),
            ];
            $parts[] = empty($courtesy['recipients'])
                ? __('courtoisie : aucune adresse', 'printgestion')
                : sprintf(
                    __('courtoisie : %1$s (%2$s)', 'printgestion'),
                    implode(', ', array_column($courtesy['recipients'], 'email')),
                    sprintf($sources[$courtesy['source']], $courtesy['from'])
                );
            $lines[] = '<li><strong>' . $esc($name) . '</strong> : ' . $esc(implode(' · ', $parts)) . '</li>';
        }
        if (empty($lines)) {
            return '';
        }
        return "<div class='alert alert-info py-2'><div class='w-100'><div class='fw-bold'><i class='ti ti-map-pin me-1'></i>"
            . $esc(__('Livraison et courtoisie', 'printgestion')) . "</div><ul class='small mb-0'>" . implode('', $lines) . '</ul></div></div>';
    }

    static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids) {
        if ($ma->getAction() === 'pg_order') {
            self::processOrder($ma, $item, $ids);
            return;
        }

        $input       = $ma->getInput();
        $action      = $ma->getAction();
        $printer_ids = [];
        // Droit de chaque action : l'annulation d'un envoi relève des expéditions, le reste des alertes toner.
        $right       = $action === 'pg_cancel_shipment' ? 'plugin_printgestion_expedition' : 'plugin_printgestion_dashboard';
        $reason      = trim((string) ($input['pg_cancel_reason'] ?? ''));
        if ($action === 'pg_cancel_shipment' && $reason === '') {
            $ma->addMessage(htmlspecialchars(__('Motif de l\'annulation obligatoire : aucun envoi annulé.', 'printgestion'), ENT_QUOTES, 'UTF-8'));
            foreach ($ids as $id) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
            }
            return;
        }
        // Seuils : saisis une fois pour toutes les lignes, appliqués une fois par imprimante.
        $thresholds = [];
        if ($action === 'pg_thresholds') {
            foreach (array_keys(PluginPrintgestionPrinterThresholdsTab::FIELDS) as $field) {
                $raw = trim((string) ($input['pg_th'][$field] ?? ''));
                if (!empty($input['pg_th_reset'])) {
                    $thresholds[$field] = '';
                } elseif ($raw !== '') {
                    $thresholds[$field] = $raw;
                }
            }
        }
        $done_printers = [];

        foreach ($ids as $id) {
            if (!$item->getFromDB($id)) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                continue;
            }
            // Droit d'agir et cloisonnement client, vérifiés à chaque ligne.
            if (!Session::haveRight($right, UPDATE)
                || !PluginPrintgestionSecurity::canAccessPrinter((int) $item->fields['printers_id'])) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                continue;
            }
            $printers_id = (int) $item->fields['printers_id'];
            $property    = (string) $item->fields['toner_property'];

            $ok = false;
            if ($action === 'pg_snooze') {
                $ok = PluginPrintgestionAlert::snooze($printers_id, $property, max(1, min(365, (int) ($input['days'] ?? 7))));
            } elseif ($action === 'pg_unsnooze') {
                $ok = PluginPrintgestionAlert::clearSnooze($printers_id, $property);
            } elseif ($action === 'pg_suspect') {
                $choice = (string) ($input['pg_suspect'] ?? 'auto');
                $ok     = PluginPrintgestionToneroverride::set($printers_id, $property, $choice === 'auto' ? null : $choice === '1')
                    || $choice === 'auto';
            } elseif ($action === 'pg_cancel_shipment') {
                $expedition = PluginPrintgestionExpedition::getActiveForPrinterProperty($printers_id, $property);
                $ok         = $expedition !== null && PluginPrintgestionExpedition::cancel((int) $expedition['id'], $reason);
                if ($expedition === null) {
                    $ma->addMessage(htmlspecialchars(sprintf(
                        __('%1$s — %2$s : aucun envoi en cours à annuler.', 'printgestion'),
                        Dropdown::getDropdownName('glpi_printers', $printers_id),
                        $property
                    ), ENT_QUOTES, 'UTF-8'));
                }
            } elseif ($action === 'pg_thresholds') {
                $ok = empty($thresholds)
                    || isset($done_printers[$printers_id])
                    || PluginPrintgestionPrinterThresholdsTab::saveFor($printers_id, $thresholds);
                $done_printers[$printers_id] = true;
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
        // Lieu de livraison choisi par imprimante (liste du sous-formulaire), revérifié par Expedition::buildPurchaseRowData().
        $locations = array_map('intval', (array) ($input['pg_location'] ?? []));

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
                'printers_id'  => (int) $item->fields['printers_id'],
                'property'     => (string) $item->fields['toner_property'],
                'level'        => (int) $item->fields['level_percent'],
                'days'         => $item->fields['days_remaining'] !== null ? (int) $item->fields['days_remaining'] : null,
                'locations_id' => max(0, $locations[(int) $item->fields['printers_id']] ?? 0),
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
        // Lieu de livraison obligatoire pour une imprimante dont le lieu a des sous-lieux : jamais livrée au hasard.
        $missing = self::getMissingLocations($items);
        if (!empty($missing)) {
            $ma->addMessage(htmlspecialchars(sprintf(
                __('Commande non envoyée : lieu de livraison à choisir pour %s. Rien n\'a été enregistré.', 'printgestion'),
                implode(', ', $missing)
            ), ENT_QUOTES, 'UTF-8'));
            foreach ($ordered_ids as $id) {
                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
            }
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
