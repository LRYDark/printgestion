<?php
/**
 * PluginPrintgestionAgentalert — alertes « sonde muette » (module Collecte SNMP / Déploiement Agent, phase 5).
 *
 * Deux cas, qui n'ont pas la même cause :
 *   - agent_silent   : la sonde ne contacte plus GLPI (glpi_agents.last_contact plus ancien que silent_days jours) ;
 *   - printer_silent : l'imprimante ne remonte plus alors qu'une sonde qui la collecte contacte GLPI : aucun
 *                      inventaire réseau depuis silent_days jours, jamais inventoriée (connue depuis plus de
 *                      silent_days jours) ou inventaire à jour sans niveau lisible. Tant que toutes ses sondes
 *                      sont muettes, l'alerte de sonde suffit : l'alerte d'imprimante n'est ni ouverte ni fermée.
 * Date de référence d'une imprimante : dernier inventaire réseau du journal d'import GLPI et des tâches GLPI
 * Inventory (PluginPrintgestionCollect::getImportDates()), jamais last_inventory_update, qu'une découverte fait
 * avancer sans relire les niveaux. Les lignes de niveaux (glpi_printers_cartridgeinfos) ne changent de date que
 * si leur valeur change : elles ne prouvent pas une lecture récente.
 * Sonde : agent qui gère l'inventaire réseau (installé avec feat_NETINV) ou qui collecte au moins une
 * imprimante. Les agents des postes de travail ne sont jamais signalés.
 *
 * Délai : silent_days de la configuration du plugin, pas le délai natif « Agent cleanup », partagé avec les
 * actions natives destructrices (l'action par défaut supprime l'agent, et donc l'acteur de ses tâches).
 * Une alerte ouverte par épisode (index unique sur les alertes ouvertes), notifiée une fois, fermée au retour ;
 * jamais supprimée. Tâche automatique quotidienne PrintgestionSilentProbes. Notifications natives
 * (NotificationTarget, file d'attente) : réglages dans Configuration > Inventaire > Agent cleanup (hook
 * STALE_AGENT_CONFIG), modèles et destinataires dans Configuration > Notifications.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAgentalert extends CommonDBTM {

    static $rightname = 'plugin_printgestion_deploiement';

    const TYPE_AGENT   = 'agent_silent';
    const TYPE_PRINTER = 'printer_silent';

    const REASON_NO_CONTACT = 'no_contact';

    /** Réglages ajoutés à Configuration > Inventaire > Agent cleanup (préfixe « _ » : enregistrés par le cœur). */
    const CONF_NOTIFY_AGENTS   = '_printgestion_notify_silent_agents';
    const CONF_NOTIFY_PRINTERS = '_printgestion_notify_silent_printers';

    /** Couverture des sondes gardée en cache pour les cartes du tableau de bord (secondes). */
    const COVERAGE_CACHE_KEY = 'printgestion_probe_coverage';
    const COVERAGE_CACHE_TTL = 600;

    /** Couverture calculée une fois par requête (la tâche native appelle l'action pour chaque agent). */
    private static ?array $coverage = null;

    static function getTypeName($nb = 0) {
        return _n('Alerte de sonde', 'Alertes de sondes', $nb, 'printgestion');
    }

    public static function getReasonLabels(): array {
        return [
            self::REASON_NO_CONTACT                       => __('Sonde sans contact', 'printgestion'),
            PluginPrintgestionCollect::STATE_STALE        => __('Aucun inventaire réseau récent', 'printgestion'),
            PluginPrintgestionCollect::STATE_NO_INVENTORY => __('Jamais inventoriée en SNMP', 'printgestion'),
            PluginPrintgestionCollect::STATE_NO_LEVEL     => __('Aucun niveau de consommable lisible', 'printgestion'),
        ];
    }

    private static function now(): string {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    private static function getCutoff(): string {
        return date('Y-m-d H:i:s', time() - PluginPrintgestionCollect::getSilentDays() * DAY_TIMESTAMP);
    }

    /** @param bool $use_cache true : cache GLPI de COVERAGE_CACHE_TTL secondes (cartes du tableau de bord). */
    private static function getCoverage(bool $use_cache = false): array {
        global $GLPI_CACHE;

        if (self::$coverage !== null) {
            return self::$coverage;
        }
        if ($use_cache) {
            $cached = $GLPI_CACHE->get(self::COVERAGE_CACHE_KEY);
            if (is_array($cached)) {
                return self::$coverage = $cached;
            }
        }
        self::$coverage = PluginPrintgestionAgentsetting::getCoverage();
        $GLPI_CACHE->set(self::COVERAGE_CACHE_KEY, self::$coverage, self::COVERAGE_CACHE_TTL);
        return self::$coverage;
    }

    /**
     * Sondes : agents qui gèrent l'inventaire réseau ou qui collectent au moins une imprimante.
     *
     * @param bool  $restrict_to_session true : entités de l'utilisateur ; false : toutes (tâche automatique)
     * @param array $coverage            PluginPrintgestionAgentsetting::getCoverage()
     * @return array agents_id => ligne glpi_agents + 'entity', 'is_silent', 'printers' (nombre d'imprimantes collectées)
     */
    public static function getProbes(bool $restrict_to_session, array $coverage): array {
        global $DB;

        $or = ['a.use_module_network_inventory' => 1];
        if (!empty($coverage)) {
            $or['a.id'] = array_keys($coverage);
        }
        $where = [['OR' => $or]];
        if ($restrict_to_session) {
            $where[] = getEntitiesRestrictCriteria('a', '', '', true);
        }
        $cutoff = self::getCutoff();
        $probes = [];
        foreach ($DB->request([
            'SELECT'    => ['a.id', 'a.name', 'a.version', 'a.last_contact', 'a.entities_id', 'a.itemtype', 'a.items_id', 'e.completename AS entity'],
            'FROM'      => 'glpi_agents AS a',
            'LEFT JOIN' => ['glpi_entities AS e' => ['ON' => ['a' => 'entities_id', 'e' => 'id']]],
            'WHERE'     => $where,
            'ORDER'     => ['e.completename', 'a.name'],
        ]) as $row) {
            $agents_id          = (int) $row['id'];
            $probes[$agents_id] = $row + [
                'is_silent' => empty($row['last_contact']) || (string) $row['last_contact'] < $cutoff,
                'printers'  => count($coverage[$agents_id] ?? []),
            ];
        }
        return $probes;
    }

    /** Alertes ouvertes : type => [agents_id (sonde) ou printers_id (imprimante) => ligne]. */
    private static function getOpenAlerts(): array {
        global $DB;

        $out = [self::TYPE_AGENT => [], self::TYPE_PRINTER => []];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['date_end' => null]]) as $row) {
            $type = (string) $row['type'];
            $out[$type][$type === self::TYPE_AGENT ? (int) $row['agents_id'] : (int) $row['printers_id']] = $row;
        }
        return $out;
    }

    /**
     * Imprimantes collectées par une sonde qui contacte GLPI et qui ne remontent plus.
     * Fondé sur les journaux d'inventaire réseau. Passage aux relevés toner : dépendance notée dans
     * Tonerreading::snapshotAllPrinters() (correction 3), à traiter avec le moteur d'alertes.
     *
     * @return array ['problems' => [printers_id => ['entities_id', 'agents_id', 'reason', 'since']],
     *                'skipped'  => [printers_id => true] (toutes leurs sondes sont muettes)]
     */
    private static function findSilentPrinters(array $coverage, array $probes): array {
        global $DB;

        $result            = ['problems' => [], 'skipped' => []];
        $agents_by_printer = [];
        foreach ($coverage as $agents_id => $printers) {
            foreach (array_keys($printers) as $printers_id) {
                $agents_by_printer[(int) $printers_id][] = (int) $agents_id;
            }
        }
        if (empty($agents_by_printer)) {
            return $result;
        }

        $rows = [];
        foreach (array_chunk(array_keys($agents_by_printer), 1000) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['id', 'entities_id', 'date_creation'],
                'FROM'   => Printer::getTable(),
                'WHERE'  => ['id' => $chunk, 'is_deleted' => 0, 'is_template' => 0],
            ]) as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }
        $dates  = PluginPrintgestionCollect::getImportDates(array_keys($rows));
        $levels = PluginPrintgestionSnmpadapter::getLevels(array_keys($rows));
        foreach ($rows as $printers_id => $row) {
            $active = array_values(array_filter(
                $agents_by_printer[$printers_id],
                static fn(int $agents_id): bool => isset($probes[$agents_id]) && !$probes[$agents_id]['is_silent']
            ));
            if (empty($active)) {
                $result['skipped'][$printers_id] = true;
                continue;
            }
            // Seuil de l'entité de l'imprimante : une entité relevée moins souvent n'est pas muette plus tôt.
            $printer_cutoff = date('Y-m-d H:i:s', time() - PluginPrintgestionCollectfrequency::getSilentDaysForEntity((int) $row['entities_id']) * DAY_TIMESTAMP);
            $snmp  = $dates[$printers_id]['snmp'] ?? null;
            $state = PluginPrintgestionCollect::getState($snmp, PluginPrintgestionCollect::hasReadableLevel($levels[$printers_id] ?? []), $printer_cutoff);
            if ($state === PluginPrintgestionCollect::STATE_OK
                || ($state === PluginPrintgestionCollect::STATE_NO_INVENTORY && (string) $row['date_creation'] >= $printer_cutoff)) {
                continue;
            }
            // Sonde de l'alerte : celle du dernier inventaire réseau si elle contacte GLPI, sinon la première active.
            $last_agent = (int) ($dates[$printers_id]['agents_id'] ?? 0);
            $result['problems'][$printers_id] = [
                'entities_id' => (int) $row['entities_id'],
                'agents_id'   => in_array($last_agent, $active, true) ? $last_agent : $active[0],
                'reason'      => $state,
                'since'       => $snmp ?? $row['date_creation'],
            ];
        }
        return $result;
    }

    private static function open(string $type, int $entities_id, int $agents_id, int $printers_id, string $reason, ?string $since, array &$stats): int {
        try {
            $alert = new self();
            if ($alert->add([
                'type'        => $type,
                'entities_id' => $entities_id,
                'agents_id'   => $agents_id,
                'printers_id' => $printers_id,
                'reason'      => $reason,
                'date_begin'  => $since,
            ]) > 0) {
                return 1;
            }
            PluginPrintgestionLogger::error('agentalert', sprintf('Alerte %s non ouverte (agent #%d, imprimante #%d).', $type, $agents_id, $printers_id));
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('agentalert', sprintf('Alerte %s non ouverte (agent #%d, imprimante #%d).', $type, $agents_id, $printers_id), $e);
        }
        $stats['errors']++;
        return 0;
    }

    private static function close(array $row, array &$stats): int {
        try {
            if ((new self())->update(['id' => (int) $row['id'], 'date_end' => self::now()])) {
                return 1;
            }
            PluginPrintgestionLogger::error('agentalert', sprintf('Alerte #%d non fermée.', (int) $row['id']));
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('agentalert', sprintf('Alerte #%d non fermée.', (int) $row['id']), $e);
        }
        $stats['errors']++;
        return 0;
    }

    /**
     * Ouvre et ferme les alertes d'après l'état courant, puis notifie les alertes ouvertes pas encore notifiées
     * dont la notification est activée.
     *
     * @param ?int $agents_id seulement l'alerte de cette sonde (action native « Agent cleanup ») ; null : tout
     * @return array ['agents', 'printers' (ouvertes), 'closed', 'notified', 'errors']
     */
    public static function refresh(?int $agents_id = null): array {
        $stats    = ['agents' => 0, 'printers' => 0, 'closed' => 0, 'notified' => 0, 'errors' => 0];
        $coverage = self::getCoverage();
        $probes   = self::getProbes(false, $coverage);
        $open     = self::getOpenAlerts();

        foreach ($probes as $id => $probe) {
            if (($agents_id === null || $id === $agents_id) && $probe['is_silent'] && !isset($open[self::TYPE_AGENT][$id])) {
                $stats['agents'] += self::open(self::TYPE_AGENT, (int) $probe['entities_id'], $id, 0, self::REASON_NO_CONTACT, $probe['last_contact'], $stats);
            }
        }
        if ($agents_id === null) {
            foreach ($open[self::TYPE_AGENT] as $id => $row) {
                if (!isset($probes[$id]) || !$probes[$id]['is_silent']) {
                    $stats['closed'] += self::close($row, $stats);
                }
            }

            $silent = self::findSilentPrinters($coverage, $probes);
            foreach ($silent['problems'] as $printers_id => $problem) {
                $row = $open[self::TYPE_PRINTER][$printers_id] ?? null;
                if ($row === null) {
                    $stats['printers'] += self::open(self::TYPE_PRINTER, $problem['entities_id'], $problem['agents_id'], $printers_id, $problem['reason'], $problem['since'], $stats);
                } elseif ((string) $row['reason'] !== $problem['reason'] || (int) $row['agents_id'] !== $problem['agents_id'] || (int) $row['entities_id'] !== $problem['entities_id']) {
                    // Même épisode : motif, sonde ou entité mis à jour, sans nouvelle notification.
                    if (!(new self())->update(['id' => (int) $row['id'], 'reason' => $problem['reason'], 'agents_id' => $problem['agents_id'], 'entities_id' => $problem['entities_id']])) {
                        PluginPrintgestionLogger::error('agentalert', sprintf('Alerte #%d non mise à jour.', (int) $row['id']));
                        $stats['errors']++;
                    }
                }
            }
            foreach ($open[self::TYPE_PRINTER] as $printers_id => $row) {
                if (!isset($silent['problems'][$printers_id]) && !isset($silent['skipped'][$printers_id])) {
                    $stats['closed'] += self::close($row, $stats);
                }
            }
        }

        self::notifyPending($stats);
        return $stats;
    }

    /**
     * Notifications natives des alertes ouvertes pas encore notifiées : une par sonde muette, une par entité pour
     * les imprimantes. Rien si le réglage correspondant est désactivé ; l'alerte reste à notifier.
     */
    private static function notifyPending(array &$stats): void {
        global $DB;

        $config = Config::getConfigurationValues('inventory', [self::CONF_NOTIFY_AGENTS, self::CONF_NOTIFY_PRINTERS]);
        $types  = [];
        if (!empty($config[self::CONF_NOTIFY_AGENTS])) {
            $types[] = self::TYPE_AGENT;
        }
        if (!empty($config[self::CONF_NOTIFY_PRINTERS])) {
            $types[] = self::TYPE_PRINTER;
        }
        if (empty($types)) {
            return;
        }

        $groups = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'type', 'entities_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['type' => $types, 'date_end' => null, 'date_notified' => null],
            'ORDER'  => ['entities_id', 'id'],
        ]) as $row) {
            $key            = $row['type'] === self::TYPE_AGENT ? 'agent-' . $row['id'] : 'printers-' . $row['entities_id'];
            $groups[$key][] = (int) $row['id'];
        }
        foreach ($groups as $ids) {
            $alert = new self();
            if (!$alert->getFromDB($ids[0])) {
                continue;
            }
            try {
                $raised = NotificationEvent::raiseEvent((string) $alert->fields['type'], $alert, ['alerts_ids' => $ids]);
            } catch (Throwable $e) {
                PluginPrintgestionLogger::error('notifications', sprintf('Notification %s des alertes %s non émise.', $alert->fields['type'], implode(', ', $ids)), $e);
                $stats['errors']++;
                continue;
            }
            if ($raised) {
                $DB->update(self::getTable(), ['date_notified' => self::now(), 'date_mod' => self::now()], ['id' => $ids]);
                $stats['notified'] += count($ids);
            }
        }
    }

    public static function cronInfo($name) {
        return ['description' => __('Print Gestion : sondes GLPI Agent sans contact et imprimantes qui ne remontent plus', 'printgestion')];
    }

    /** Tâche automatique quotidienne : alertes de sondes et d'imprimantes, notifications. */
    public static function cronPrintgestionSilentProbes($task = null) {
        if (!PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
            return 0;
        }
        $stats = self::refresh();
        if ($task instanceof CronTask) {
            $task->addVolume($stats['agents'] + $stats['printers'] + $stats['closed']);
            $task->log(sprintf(
                'Alertes ouvertes : sondes %d, imprimantes %d — fermées : %d — notifiées : %d — erreurs : %d',
                $stats['agents'],
                $stats['printers'],
                $stats['closed'],
                $stats['notified'],
                $stats['errors']
            ));
        }
        return $stats['errors'] > 0 ? -1 : ($stats['agents'] + $stats['printers'] + $stats['closed'] > 0 ? 1 : 0);
    }

    // ── Configuration > Inventaire > Agent cleanup (hook STALE_AGENT_CONFIG) ──

    /** Entrée du hook natif : réglages Print Gestion et action jouée par la tâche native pour chaque agent muet. */
    public static function getStaleAgentHook(): array {
        return [[
            'label'           => __('Print Gestion : notifications des sondes', 'printgestion'),
            'item_action'     => false,
            'render_callback' => [self::class, 'renderStaleAgentSettings'],
            'action_callback' => [self::class, 'staleAgentAction'],
        ]];
    }

    /**
     * Champs ajoutés à l'écran natif ; valeurs enregistrées par le cœur dans la configuration « inventory ».
     * Avertissement sur l'action native « Nettoyer les agents » (STALE_AGENT_ACTION_CLEAN, action par défaut) : elle
     * supprime l'agent et son historique. En rouge quand le réglage enregistré, puis celui choisi à l'écran, l'applique
     * avec un délai. Le bloc est placé en pleine largeur sous la ligne native du délai et de l'action ; sans
     * JavaScript, il reste dans la cellule du plugin.
     */
    public static function renderStaleAgentSettings($config): string {
        $config  = is_array($config) ? $config : [];
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $delay   = (int) ($config['stale_agents_delay'] ?? 0);
        // Sans valeur enregistrée, le cœur applique son défaut : [STALE_AGENT_ACTION_CLEAN].
        $actions = importArrayFromDB((string) ($config['stale_agents_action'] ?? exportArrayToDB([\Glpi\Inventory\Conf::STALE_AGENT_ACTION_CLEAN])));
        $clean   = is_array($actions) && in_array((string) \Glpi\Inventory\Conf::STALE_AGENT_ACTION_CLEAN, array_map('strval', $actions), true);
        $danger  = __('les agents sans contact depuis %d jours seront supprimés au prochain passage de la tâche automatique « Cleanoldagents ».', 'printgestion');

        $warning = "<div id='pg-stale-agents-warning' class='alert alert-warning mt-2 mb-0'>"
            . "<div class='fw-bold'><i class='ti ti-alert-triangle me-1'></i>" . $esc(sprintf(__('Action « %s » : elle supprime les agents', 'printgestion'), __('Clean agents'))) . "</div>"
            . "<ul class='mb-1 ps-3'>"
            . "<li>" . $esc(__('Supprimer un agent fait perdre son historique : l\'agent est effacé de GLPI, et son historique avec lui.', 'printgestion')) . "</li>"
            . "<li>" . $esc(__('À son contact suivant, le PC revient comme un nouvel agent : il ne figure plus dans les tâches GLPI Inventory de ses raccordements (découverte et relevés de ses imprimantes arrêtés) et ses réglages Print Gestion (mise à jour automatique, version cible) sont perdus.', 'printgestion')) . "</li>"
            . "<li>" . $esc(__('Avec un délai court, cette action efface des sondes saines, simplement éteintes pendant des congés ou une fermeture annuelle.', 'printgestion')) . "</li>"
            . "</ul>"
            . "<div class='small'>" . $esc(__('Pour être prévenu des sondes muettes, les notifications Print Gestion suffisent. Ne choisir cette action qu\'avec un délai plus long que la plus longue fermeture d\'un client.', 'printgestion')) . "</div>"
            . "<div id='pg-stale-agents-danger' class='alert alert-danger mt-2 mb-0'" . ($delay > 0 && $clean ? '' : ' hidden') . ">"
            . "<strong>" . $esc(__('Réglage dangereux :', 'printgestion')) . "</strong> "
            . "<span data-pg-template='" . $esc($danger) . "'>" . $esc(sprintf($danger, $delay)) . "</span>"
            . "</div></div>";

        $script = Html::scriptBlock(<<<'JS'
            (function () {
                var warning = document.getElementById('pg-stale-agents-warning');
                var danger  = document.getElementById('pg-stale-agents-danger');
                var action  = $('select[name="stale_agents_action[]"]');
                var delay   = $('select[name="stale_agents_delay"]');
                if (!warning || !danger || !action.length || !delay.length) {
                    return;
                }
                // Pleine largeur, sous la ligne native du délai et de l'action.
                var row = action.closest('tr');
                $('<tr class="tab_bg_1"><td colspan="4"></td></tr>').insertAfter(row).children('td').append(warning);
                var text = danger.querySelector('[data-pg-template]');
                var refresh = function () {
                    var days = parseInt(delay.val(), 10) || 0;
                    var on = days > 0 && (action.val() || []).map(String).indexOf('0') !== -1;
                    danger.hidden = !on;
                    if (on) {
                        text.textContent = text.getAttribute('data-pg-template').replace('%d', String(days));
                    }
                };
                action.on('change', refresh);
                delay.on('change', refresh);
                refresh();
            })();
            JS);

        return "<div class='d-flex flex-column gap-1'>"
            . "<span>" . $esc(sprintf(__('Sondes sans contact depuis %d jours', 'printgestion'), PluginPrintgestionCollect::getSilentDays())) . "</span>"
            . Dropdown::showYesNo(self::CONF_NOTIFY_AGENTS, (int) ($config[self::CONF_NOTIFY_AGENTS] ?? 0), -1, ['display' => false])
            . "<span class='mt-2'>" . $esc(__('Imprimantes qui ne remontent plus alors que leur sonde contacte GLPI', 'printgestion')) . "</span>"
            . Dropdown::showYesNo(self::CONF_NOTIFY_PRINTERS, (int) ($config[self::CONF_NOTIFY_PRINTERS] ?? 0), -1, ['display' => false])
            . "<span class='text-muted small mt-2'>" . $esc(__('Contrôle quotidien du plugin ; délai « Imprimante muette après (jours) » de Configuration > Print Gestion, indépendant du délai et des actions ci-dessus. Modèles et destinataires : Configuration > Notifications.', 'printgestion')) . "</span>"
            . $warning
            . "</div>"
            . $script;
    }

    /**
     * Action jouée par la tâche native Cleanoldagents pour chaque agent sans contact depuis le délai natif, après
     * les actions natives : ouvre et notifie son alerte s'il est une sonde muette. Vrai si rien n'a échoué.
     */
    public static function staleAgentAction($agent, $config, $item = null): bool {
        if (!$agent instanceof Agent || !PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
            return true;
        }
        try {
            // Agent supprimé juste avant par l'action native « Supprimer » : rien à signaler.
            if (countElementsInTable(Agent::getTable(), ['id' => (int) $agent->getID()]) === 0) {
                return true;
            }
            return self::refresh((int) $agent->getID())['errors'] === 0;
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('agentalert', sprintf('Alerte de la sonde #%d non traitée par la tâche native.', (int) $agent->getID()), $e);
            return false;
        }
    }

    // ── Tableau de bord natif (hook DASHBOARD_CARDS) ──────────────────────────

    /** Cartes du tableau de bord GLPI ; droits vérifiés par chaque fournisseur (liste de cartes mise en cache par GLPI). */
    public static function getDashboardCards($cards = null): array {
        $cards = is_array($cards) ? $cards : [];
        $group = __('Print Gestion — Sondes', 'printgestion');
        $cards['plugin_printgestion_probes_silent'] = [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Sondes GLPI Agent sans contact', 'printgestion'),
            'provider'   => self::class . '::cardSilentProbes',
        ];
        $cards['plugin_printgestion_probes_silent_entities'] = [
            'widgettype' => ['summaryNumbers', 'multipleNumber', 'hbar', 'bar', 'pie', 'donut'],
            'group'      => $group,
            'label'      => __('Sondes GLPI Agent sans contact, par entité', 'printgestion'),
            'provider'   => self::class . '::cardSilentProbesByEntity',
        ];
        $cards['plugin_printgestion_probes_update'] = [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Sondes GLPI Agent à mettre à jour', 'printgestion'),
            'provider'   => self::class . '::cardProbesToUpdate',
        ];
        $cards['plugin_printgestion_printers_silent'] = [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Imprimantes qui ne remontent plus (sonde active)', 'printgestion'),
            'provider'   => self::class . '::cardSilentPrinters',
        ];
        return $cards;
    }

    private static function canViewCards(): bool {
        return PluginPrintgestionConfig::isFeatureEnabled('deploiement') && Session::haveRight(self::$rightname, READ);
    }

    private static function deniedNumber(): array {
        return ['number' => 0, 'url' => '', 'label' => __('Module Déploiement Agent non accessible', 'printgestion'), 'icon' => 'ti ti-lock'];
    }

    public static function cardSilentProbes(array $params = []): array {
        if (!self::canViewCards()) {
            return self::deniedNumber();
        }
        $probes = self::getProbes(true, self::getCoverage(true));
        return [
            'number' => count(array_filter($probes, static fn(array $probe): bool => $probe['is_silent'])),
            'url'    => PluginPrintgestionAgentsetting::getPageURL(),
            'label'  => sprintf(__('Sondes sans contact depuis plus de %d jours', 'printgestion'), PluginPrintgestionCollect::getSilentDays()),
            'icon'   => 'ti ti-wifi-off',
        ];
    }

    public static function cardSilentProbesByEntity(array $params = []): array {
        $label = sprintf(__('Sondes sans contact depuis plus de %d jours, par entité', 'printgestion'), PluginPrintgestionCollect::getSilentDays());
        if (!self::canViewCards()) {
            return ['data' => [], 'label' => $label, 'icon' => 'ti ti-lock'];
        }
        $data = [];
        foreach (self::getProbes(true, self::getCoverage(true)) as $probe) {
            if (!$probe['is_silent']) {
                continue;
            }
            $key          = (int) $probe['entities_id'];
            $data[$key] ??= ['number' => 0, 'label' => (string) ($probe['entity'] ?? ''), 'url' => PluginPrintgestionAgentsetting::getPageURL()];
            $data[$key]['number']++;
        }
        return ['data' => array_values($data), 'label' => $label, 'icon' => 'ti ti-wifi-off'];
    }

    public static function cardProbesToUpdate(array $params = []): array {
        if (!self::canViewCards()) {
            return self::deniedNumber();
        }
        $probes   = self::getProbes(true, self::getCoverage(true));
        $settings = PluginPrintgestionAgentsetting::getSettingsFor(array_keys($probes));
        $latest   = PluginPrintgestionAgentsetting::getLatestVersion();
        $count    = 0;
        foreach ($probes as $agents_id => $probe) {
            if (PluginPrintgestionAgentsetting::getCompliance(PluginPrintgestionAgentsetting::getAgentVersion($probe), $settings[$agents_id], $latest)['state'] === 'update') {
                $count++;
            }
        }
        return [
            'number' => $count,
            'url'    => PluginPrintgestionAgentsetting::getPageURL(),
            'label'  => sprintf(__('Sondes à mettre à jour (dernière version connue : %s)', 'printgestion'), $latest['version'] ?? '—'),
            'icon'   => 'ti ti-arrow-up-circle',
        ];
    }

    public static function cardSilentPrinters(array $params = []): array {
        global $DB;

        if (!self::canViewCards()) {
            return self::deniedNumber();
        }
        // Alertes ouvertes dont la sonde contacte GLPI : celles d'une sonde devenue muette restent ouvertes
        // (pas de nouvelle notification à son retour) mais relèvent alors de l'alerte de sonde.
        $table = self::getTable();
        $count = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => $table,
            'INNER JOIN' => ['glpi_agents' => ['ON' => [$table => 'agents_id', 'glpi_agents' => 'id']]],
            'WHERE'      => [
                "$table.type"               => self::TYPE_PRINTER,
                "$table.date_end"           => null,
                'glpi_agents.last_contact'  => ['>=', self::getCutoff()],
            ] + getEntitiesRestrictCriteria($table),
        ])->current();
        return [
            'number' => (int) ($count['cpt'] ?? 0),
            'url'    => PLUGIN_PRINTGESTION_WEBDIR . '/front/collect.php',
            'label'  => __('Imprimantes qui ne remontent plus alors que leur sonde contacte GLPI (contrôle quotidien)', 'printgestion'),
            'icon'   => 'ti ti-printer-off',
        ];
    }

    static function uninstall(Migration $migration) {
        global $DB;

        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, 'PrintgestionSilentProbes')) {
            $task->delete(['id' => (int) $task->getID()]);
        }
        $DB->dropTable(self::getTable(), true);
        return true;
    }
}
