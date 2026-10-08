<?php
/**
 * PluginPrintgestionCollect — contrôle de la remontée SNMP : ce que l'inventaire GLPI reçoit
 * réellement des imprimantes, avant tout calcul d'alerte.
 *
 * L'absence de remontée est un ÉTAT À SIGNALER, jamais une absence d'alerte : une imprimante
 * dont on ne lit plus les niveaux ne déclenchera aucune alerte toner, précisément quand il
 * faudrait s'en inquiéter. Aucune hypothèse de couverture complète du parc par les agents.
 *
 * Page en lecture seule (tables natives GLPI, aucune écriture) :
 *   1. prérequis : inventaire GLPI activé, plugin GLPI Inventory, inventaires reçus sur 24 h
 *      et 7 jours, agents (version, dernier contact) ;
 *   2. état de chaque imprimante, daté par le journal d'import GLPI (glpi_rulematchedlogs) et, avec
 *      GLPI Inventory, par le journal de ses tâches d'inventaire réseau (voir getImportDates()) :
 *      seul un inventaire réseau compte, une découverte réseau fait avancer
 *      glpi_printers.last_inventory_update sans relire niveaux ni compteurs ;
 *   3. valeurs de consommables reçues, par fabricant et modèle ;
 *   4. compteurs disponibles et incohérences, par modèle ;
 *   5. numéros de série en double.
 *
 * États d'une imprimante (ni supprimée ni modèle) :
 *   - no_inventory : aucun inventaire réseau dans le journal d'import ;
 *   - stale        : dernier inventaire réseau plus ancien que silent_days jours ;
 *   - no_level     : inventaire à jour mais aucun niveau lisible (sentinelles, OK, valeurs
 *                    inconnues) : pas d'alerte possible ;
 *   - ok.
 * Le journal d'import GLPI ne garde que les 30 derniers passages par équipement : une
 * imprimante découverte chaque jour et plus inventoriée depuis 30 passages y apparaît
 * « jamais inventoriée ».
 * Agent : celui du dernier inventaire réseau (à défaut, de la dernière découverte) ; muet si
 * son dernier contact date de plus de silent_days jours.
 */

use Glpi\Agent\Communication\AbstractRequest;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionCollect extends CommonGLPI {

    static $rightname = 'plugin_printgestion_dashboard';

    const STATE_NO_INVENTORY = 'no_inventory';
    const STATE_STALE        = 'stale';
    const STATE_NO_LEVEL     = 'no_level';
    const STATE_OK           = 'ok';

    const DEFAULT_SILENT_DAYS = 3;

    /**
     * glpi-agent : en dessous, une valeur de compteur invalide fait rejeter tout l'inventaire. Au-dessus, la
     * version visée est la dernière version connue ou la version cible de la sonde (PluginPrintgestionAgentsetting).
     */
    const AGENT_MIN_VERSION = '1.15';

    /** Fenêtre de recherche des baisses du compteur total (jours). */
    const COUNTER_WINDOW_DAYS = 90;

    /** Nombre maximal de lignes par liste détaillée. */
    const LIST_MAX = 200;

    static function getTypeName($nb = 0) {
        return __('Contrôle de la remontée', 'printgestion');
    }

    public static function getStateLabels(): array {
        return [
            self::STATE_NO_INVENTORY => __('Jamais inventoriée en SNMP', 'printgestion'),
            self::STATE_STALE        => __('Muette', 'printgestion'),
            self::STATE_NO_LEVEL     => __('Sans niveau lisible', 'printgestion'),
            self::STATE_OK           => __('Collecte normale', 'printgestion'),
        ];
    }

    /** Délai (jours) au-delà duquel une imprimante ou un agent est considéré muet. */
    public static function getSilentDays(): int {
        $days = (int) (PluginPrintgestionConfig::getInstance()->fields['silent_days'] ?? 0);
        return $days > 0 ? $days : self::DEFAULT_SILENT_DAYS;
    }

    /** Limite « muette » d'une imprimante : délai global, porté à la fréquence de relevé de son entité plus un jour. */
    public static function getPrinterCutoff(int $entities_id, string $format = 'Y-m-d H:i:s'): string {
        return date($format, time() - PluginPrintgestionCollectfrequency::getSilentDaysForEntity($entities_id) * DAY_TIMESTAMP);
    }

    /** Méthodes du journal d'import GLPI qui correspondent à un inventaire réseau (SNMP). */
    private static function getNetworkInventoryMethods(): array {
        return [AbstractRequest::SNMP_QUERY, AbstractRequest::OLD_SNMP_QUERY, AbstractRequest::NETINV_TASK];
    }

    /**
     * Derniers passages de chaque imprimante : inventaire réseau (SNMP) et découverte.
     *
     * Journal d'import GLPI (glpi_rulematchedlogs) : snmp / snmpquery / netinventory prouvent un
     * inventaire réseau, netdiscovery une découverte. Avec GLPI Inventory, le cœur y note l'inventaire
     * réseau « inventory » (le chemin du plugin ne lui transmet pas la requête), comme le premier import
     * par une découverte ou une imprimante déclarée dans l'inventaire d'un PC : cette méthode ne prouve
     * rien. L'inventaire réseau est alors lu dans le journal des tâches d'inventaire réseau de GLPI
     * Inventory, avec la sonde qui l'a fait.
     *
     * @return array printers_id => ['snmp' => ?string, 'discovery' => ?string, 'agents_id' => int]
     */
    public static function getImportDates(array $printer_ids): array {
        global $DB;

        $inventory = self::getNetworkInventoryMethods();
        $out       = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $printer_ids))), 1000) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['id', 'items_id', 'method', 'agents_id', 'date'],
                'FROM'   => 'glpi_rulematchedlogs',
                'WHERE'  => ['itemtype' => Printer::class, 'items_id' => $chunk],
                'ORDER'  => ['date ASC', 'id ASC'],
            ]) as $row) {
                $pid    = (int) $row['items_id'];
                $method = (string) $row['method'];
                $agent  = (int) $row['agents_id'];
                $out[$pid] ??= ['snmp' => null, 'discovery' => null, 'agents_id' => 0];
                if (in_array($method, $inventory, true)) {
                    $out[$pid]['snmp'] = (string) $row['date'];
                    if ($agent > 0) {
                        $out[$pid]['agents_id'] = $agent;
                    }
                } elseif ($method === AbstractRequest::NETDISCOVERY_TASK) {
                    $out[$pid]['discovery'] = (string) $row['date'];
                    if ($out[$pid]['snmp'] === null && $agent > 0) {
                        $out[$pid]['agents_id'] = $agent;
                    }
                }
            }
        }
        foreach (self::getGlpiInventoryNetworkInventories($printer_ids) as $pid => $run) {
            $out[$pid] ??= ['snmp' => null, 'discovery' => null, 'agents_id' => 0];
            if ($out[$pid]['snmp'] === null || $run['date'] > $out[$pid]['snmp']) {
                $out[$pid]['snmp'] = $run['date'];
                if ($run['agents_id'] > 0) {
                    $out[$pid]['agents_id'] = $run['agents_id'];
                }
            }
        }
        return $out;
    }

    /**
     * Inventaires réseau réussis par GLPI Inventory : dernière mise à jour de chaque imprimante par une
     * tâche d'inventaire réseau (« ==updatetheitem== … [[Printer::id]] »), avec la sonde qui l'a faite.
     * Vide si GLPI Inventory est absent ; limité à ce que GLPI Inventory garde de ses tâches.
     *
     * @return array printers_id => ['date' => string, 'agents_id' => int]
     */
    public static function getGlpiInventoryNetworkInventories(array $printer_ids): array {
        global $DB;

        if (empty($printer_ids) || !Plugin::isPluginActive('glpiinventory') || !$DB->tableExists('glpi_plugin_glpiinventory_taskjoblogs')) {
            return [];
        }
        $wanted = array_flip(array_map('intval', $printer_ids));
        // L'imprimante n'est nommée que dans le texte du journal : une ligne d'état de tâche couvre tout un passage
        // de la sonde. Journal entier lu une fois par requête et gardé ; pour quelques imprimantes (fiche, relevé
        // manuel), seules les lignes qui les citent sont lues — même résultat, la base filtre au lieu de PHP.
        if (self::$network_runs !== null && self::$network_runs_at < time() - 60) {
            self::$network_runs = null; // tâche planifiée longue : relu au-delà d'une minute
        }
        if (self::$network_runs === null && count($wanted) > self::FEW_PRINTERS) {
            self::$network_runs    = self::readNetworkRuns(null);
            self::$network_runs_at = time();
        }
        $runs = self::$network_runs ?? self::readNetworkRuns(array_keys($wanted));
        return array_intersect_key($runs, $wanted);
    }

    /** Au-delà, le journal d'inventaire réseau de GLPI Inventory est lu en entier (une fois par requête). */
    const FEW_PRINTERS = 50;

    /** Journal entier déjà lu pendant la requête : printers_id => ['date', 'agents_id']. */
    private static ?array $network_runs = null;
    private static int $network_runs_at = 0;

    /**
     * Dernier inventaire réseau réussi par imprimante, d'après le journal des tâches de GLPI Inventory.
     *
     * @param ?int[] $printer_ids null : toutes les imprimantes citées par le journal
     */
    private static function readNetworkRuns(?array $printer_ids): array {
        global $DB;

        $started = microtime(true);
        $where   = ['j.method' => 'networkinventory', 'l.comment' => ['LIKE', '%==updatetheitem==%']];
        if ($printer_ids !== null) {
            $where[] = ['OR' => array_map(
                static fn(int $id): array => ['l.comment' => ['LIKE', '%[[Printer::' . $id . ']]%']],
                $printer_ids
            )];
        }
        $out  = [];
        $rows = 0;
        foreach ($DB->request([
            'SELECT'     => ['l.date', 'l.comment', 's.agents_id'],
            'FROM'       => 'glpi_plugin_glpiinventory_taskjoblogs AS l',
            'INNER JOIN' => [
                'glpi_plugin_glpiinventory_taskjobstates AS s' => ['ON' => ['l' => 'plugin_glpiinventory_taskjobstates_id', 's' => 'id']],
                'glpi_plugin_glpiinventory_taskjobs AS j'      => ['ON' => ['s' => 'plugin_glpiinventory_taskjobs_id', 'j' => 'id']],
            ],
            'WHERE'      => $where,
            'ORDER'      => ['l.date ASC', 'l.id ASC'],
        ]) as $row) {
            $rows++;
            if (!preg_match_all('/\[\[Printer::(\d+)\]\]/', (string) $row['comment'], $matches)) {
                continue;
            }
            foreach ($matches[1] as $id) {
                $out[(int) $id] = ['date' => (string) $row['date'], 'agents_id' => (int) $row['agents_id']];
            }
        }
        if ($printer_ids === null) {
            PluginPrintgestionLogger::duration('collecte', 'Lecture du journal d\'inventaire réseau de GLPI Inventory', $started, sprintf('%d ligne(s), %d imprimante(s)', $rows, count($out)));
        }
        return $out;
    }

    /**
     * État d'une version de glpi-agent : old (avant AGENT_MIN_VERSION), update (sous la version visée), ok,
     * unknown. Version visée : version cible de la sonde si elle est épinglée, sinon dernière version connue
     * (même règle que la page « Sondes » : PluginPrintgestionAgentsetting::getCompliance()).
     *
     * @param ?array $settings réglages de la sonde (PluginPrintgestionAgentsetting::getSettingsFor()) ; null : par défaut
     */
    public static function getAgentVersionStatus(string $version, ?array $settings = null): string {
        $installed = PluginPrintgestionAgentsetting::normalizeVersion($version);
        if (!preg_match('/^\d+(\.\d+)+$/', $installed)) {
            return 'unknown';
        }
        if (version_compare($installed, self::AGENT_MIN_VERSION, '<')) {
            return 'old';
        }
        $compliance = PluginPrintgestionAgentsetting::getCompliance(
            $installed,
            $settings ?? PluginPrintgestionAgentsetting::getDefaultSettings(),
            PluginPrintgestionAgentsetting::getLatestVersion()
        );
        return match ($compliance['state']) {
            'ok', 'ahead' => 'ok',
            'update'       => 'update',
            default        => 'unknown',
        };
    }

    /** État de collecte d'une imprimante (voir l'en-tête de la classe) ; $cutoff : limite de silent_days. */
    public static function getState(?string $last_inventory, bool $readable, string $cutoff): string {
        if ($last_inventory === null) {
            return self::STATE_NO_INVENTORY;
        }
        if ($last_inventory < $cutoff) {
            return self::STATE_STALE;
        }
        return $readable ? self::STATE_OK : self::STATE_NO_LEVEL;
    }

    /** Au moins un niveau lisible parmi les valeurs d'une imprimante (PluginPrintgestionSnmpadapter::getLevels()). */
    public static function hasReadableLevel(array $levels): bool {
        foreach ($levels as $parsed) {
            if (!empty($parsed['usable'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * États de collecte.
     *
     * @param bool $restrict_to_session true : entités de l'utilisateur ; false : toutes
     *                                  (tâche automatique uniquement).
     * @return array ['printers' => [id => ['id', 'name', 'entity', 'state', 'last_inventory',
     *                                      'last_discovery', 'agents_id', 'agent_name',
     *                                      'agent_last_contact']],
     *                'counts'   => [état => nombre],
     *                'agents'   => [agents_id => ['id', 'name', 'last_contact', 'version',
     *                                             'version_status', 'is_silent', 'printers']]]
     */
    public static function analyze(bool $restrict_to_session = true): array {
        global $DB;

        $cutoff = date('Y-m-d H:i:s', time() - self::getSilentDays() * DAY_TIMESTAMP);
        $out    = [
            'printers' => [],
            'counts'   => array_fill_keys(array_keys(self::getStateLabels()), 0),
            'agents'   => [],
        ];

        $criteria = [
            'SELECT'    => ['p.id', 'p.name', 'p.entities_id', 'p.is_recursive', 'e.completename AS entity'],
            'FROM'      => 'glpi_printers AS p',
            'LEFT JOIN' => ['glpi_entities AS e' => ['ON' => ['p' => 'entities_id', 'e' => 'id']]],
            'WHERE'     => ['p.is_deleted' => 0, 'p.is_template' => 0],
            'ORDER'     => ['e.completename', 'p.name'],
        ];
        if ($restrict_to_session) {
            $criteria['WHERE'][] = getEntitiesRestrictCriteria('p', '', '', true);
        }
        $printers = iterator_to_array($DB->request($criteria), false);
        if (empty($printers)) {
            return $out;
        }
        $ids    = array_map(static fn(array $p) => (int) $p['id'], $printers);
        $dates  = self::getImportDates($ids);
        // Imprimantes suivies à la main : le dernier relevé saisi vaut inventaire s'il est plus récent.
        $manual = PluginPrintgestionManualreading::getLastForPrinters($ids);

        $agents    = [];
        $agent_ids = array_values(array_unique(array_filter(array_column($dates, 'agents_id'))));
        if (!empty($agent_ids)) {
            $settings = PluginPrintgestionAgentsetting::getSettingsFor($agent_ids);
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'last_contact', 'version'],
                'FROM'   => 'glpi_agents',
                'WHERE'  => ['id' => $agent_ids],
            ]) as $agent) {
                $version = PluginPrintgestionAgentsetting::getAgentVersion($agent);
                $agents[(int) $agent['id']] = [
                    'id'             => (int) $agent['id'],
                    'name'           => (string) $agent['name'],
                    'last_contact'   => $agent['last_contact'],
                    'version'        => $version,
                    'version_status' => self::getAgentVersionStatus($version, $settings[(int) $agent['id']] ?? null),
                    'is_silent'      => empty($agent['last_contact']) || (string) $agent['last_contact'] < $cutoff,
                    'printers'       => 0,
                ];
            }
        }

        $levels = PluginPrintgestionSnmpadapter::getLevels($ids);

        foreach ($printers as $printer) {
            $pid       = (int) $printer['id'];
            $snmp      = $dates[$pid]['snmp'] ?? null;
            $is_manual = 0;
            if (isset($manual[$pid]) && ($snmp === null || $manual[$pid]['date'] > $snmp)) {
                $snmp      = $manual[$pid]['date'];
                $is_manual = 1;
            }

            // Seuil de l'entité de l'imprimante : une entité relevée moins souvent n'est pas muette plus tôt.
            $state = self::getState($snmp, self::hasReadableLevel($levels[$pid] ?? []), self::getPrinterCutoff((int) $printer['entities_id']));

            $agents_id = $is_manual ? 0 : (int) ($dates[$pid]['agents_id'] ?? 0);
            if (isset($agents[$agents_id])) {
                $agents[$agents_id]['printers']++;
            }

            $out['counts'][$state]++;
            $out['printers'][$pid] = [
                'id'                 => $pid,
                'name'               => (string) $printer['name'],
                'entity'             => (string) ($printer['entity'] ?? ''),
                'entities_id'        => (int) $printer['entities_id'],
                'is_recursive'       => (int) ($printer['is_recursive'] ?? 0),
                'is_manual'          => $is_manual,
                'state'              => $state,
                'last_inventory'     => $snmp,
                'last_discovery'     => $dates[$pid]['discovery'] ?? null,
                'agents_id'          => $agents_id,
                'agent_name'         => $agents[$agents_id]['name'] ?? '',
                'agent_last_contact' => $agents[$agents_id]['last_contact'] ?? null,
            ];
        }

        uasort($agents, static fn(array $a, array $b) => [$b['is_silent'], $b['printers']] <=> [$a['is_silent'], $a['printers']]);
        $out['agents'] = $agents;
        return $out;
    }

    /** Prérequis de la collecte, à partir de l'analyse des états. */
    public static function getPrerequisites(array $analysis): array {
        $plugin      = new Plugin();
        $now         = time();
        $last        = null;
        $inventoried = ['24h' => 0, '7d' => 0];
        foreach ($analysis['printers'] as $printer) {
            if ($printer['last_inventory'] === null) {
                continue;
            }
            $timestamp = strtotime((string) $printer['last_inventory']);
            if ($timestamp >= $now - DAY_TIMESTAMP) {
                $inventoried['24h']++;
            }
            if ($timestamp >= $now - 7 * DAY_TIMESTAMP) {
                $inventoried['7d']++;
            }
            if ($last === null || (string) $printer['last_inventory'] > $last) {
                $last = (string) $printer['last_inventory'];
            }
        }
        $versions = array_count_values(array_column($analysis['agents'], 'version_status'));

        return [
            'inventory_enabled'       => (bool) Config::getConfigurationValue('inventory', 'enabled_inventory'),
            'glpiinventory_installed' => $plugin->isInstalled('glpiinventory'),
            'glpiinventory_active'    => $plugin->isActivated('glpiinventory'),
            'printers'                => count($analysis['printers']),
            'inventoried_24h'         => $inventoried['24h'],
            'inventoried_7d'          => $inventoried['7d'],
            'last_inventory'          => $last,
            'agents'                  => count($analysis['agents']),
            'agents_silent'           => count(array_filter($analysis['agents'], static fn(array $a) => $a['is_silent'])),
            'agents_old'              => $versions['old'] ?? 0,
            'agents_update'           => $versions['update'] ?? 0,
        ];
    }

    /** Classes de valeurs brutes de consommables, dans l'ordre d'affichage. */
    public static function getValueClassLabels(): array {
        return [
            'percent'  => __('Pourcentage', 'printgestion'),
            'zero'     => __('0 (faux zéro possible)', 'printgestion'),
            'ok'       => __('OK (non chiffré)', 'printgestion'),
            'warning'  => __('WARNING', 'printgestion'),
            'pages'    => __('Pages restantes', 'printgestion'),
            'unit'     => __('Autre unité', 'printgestion'),
            'negative' => __('Négatif', 'printgestion'),
            'over'     => __('Supérieur à 100', 'printgestion'),
            'empty'    => __('Vide', 'printgestion'),
            'other'    => __('Autre', 'printgestion'),
        ];
    }

    /** Classe d'une valeur brute de consommable (affichage seulement, aucune interprétation). */
    public static function classifyValue(string $raw): string {
        $value = trim($raw);
        $upper = mb_strtoupper($value);
        if ($value === '') {
            return 'empty';
        }
        if ($upper === 'OK') {
            return 'ok';
        }
        if ($upper === 'WARNING' || $upper === 'WARN') {
            return 'warning';
        }
        if (preg_match('/^-\d+$/', $value)) {
            return 'negative';
        }
        if (preg_match('/^(\d+)\s*%?$/', $value, $matches)) {
            $number = (int) $matches[1];
            return $number === 0 ? 'zero' : ($number <= 100 ? 'percent' : 'over');
        }
        if (preg_match('/^\d+\s*(pages|impressions|sheets)$/i', $value)) {
            return 'pages';
        }
        if (preg_match('/^-?\d+(\.\d+)?\s*[a-z?]+$/i', $value)) {
            return 'unit';
        }
        return 'other';
    }

    /** Fabricant, modèle et nom des imprimantes : printers_id => ['key', 'manufacturer', 'model', 'name']. */
    private static function getPrinterModels(array $printer_ids): array {
        global $DB;

        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $printer_ids))), 1000) as $chunk) {
            foreach ($DB->request([
                'SELECT'    => ['p.id', 'p.name', 'p.entities_id', 'p.manufacturers_id', 'p.printermodels_id', 'm.name AS manufacturer', 'pm.name AS model'],
                'FROM'      => 'glpi_printers AS p',
                'LEFT JOIN' => [
                    'glpi_manufacturers AS m'  => ['ON' => ['p' => 'manufacturers_id', 'm' => 'id']],
                    'glpi_printermodels AS pm' => ['ON' => ['p' => 'printermodels_id', 'pm' => 'id']],
                ],
                'WHERE'     => ['p.id' => $chunk],
            ]) as $row) {
                $out[(int) $row['id']] = [
                    'key'          => (int) $row['manufacturers_id'] . '|' . (int) $row['printermodels_id'],
                    'manufacturer' => (string) ($row['manufacturer'] ?? ''),
                    'model'        => (string) ($row['model'] ?? ''),
                    'name'         => (string) $row['name'],
                    'entities_id'  => (int) $row['entities_id'],
                ];
            }
        }
        return $out;
    }

    /**
     * Valeurs de consommables reçues, par fabricant et modèle.
     *
     * @return array clé modèle => ['manufacturer', 'model', 'printers' (nombre),
     *               'properties' => [propriété => ['classes' => [classe => nombre],
     *                                              'examples' => [classe => valeur brute]]]]
     */
    public static function analyzeValues(array $printer_ids): array {
        global $DB;

        $models = self::getPrinterModels($printer_ids);
        $out    = [];
        foreach (array_chunk(array_keys($models), 1000) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['printers_id', 'property', 'value'],
                'FROM'   => 'glpi_printers_cartridgeinfos',
                'WHERE'  => ['printers_id' => $chunk],
            ]) as $row) {
                $pid      = (int) $row['printers_id'];
                $model    = $models[$pid];
                $key      = $model['key'];
                $property = (string) $row['property'];
                $class    = self::classifyValue((string) $row['value']);

                $out[$key] ??= ['manufacturer' => $model['manufacturer'], 'model' => $model['model'], 'printers' => [], 'properties' => []];
                $out[$key]['printers'][$pid] = true;
                $out[$key]['properties'][$property]['classes'][$class] = ($out[$key]['properties'][$property]['classes'][$class] ?? 0) + 1;
                if ($class !== 'percent' && !isset($out[$key]['properties'][$property]['examples'][$class])) {
                    $out[$key]['properties'][$property]['examples'][$class] = mb_substr((string) $row['value'], 0, 40);
                }
            }
        }
        foreach ($out as &$entry) {
            $entry['printers'] = count($entry['printers']);
            ksort($entry['properties']);
        }
        unset($entry);
        uasort($out, static fn(array $a, array $b) => [$a['manufacturer'], $a['model']] <=> [$b['manufacturer'], $b['model']]);
        return $out;
    }

    /**
     * Compteurs disponibles et incohérences, par modèle, sur le dernier relevé de compteurs
     * de chaque imprimante (glpi_printerlogs), et baisses du compteur total sur la fenêtre.
     *
     * @return array ['models' => [clé => compteurs agrégés], 'color_without_counter' => [...],
     *                'color_over_total' => [...], 'resets' => [...]]
     */
    public static function analyzeCounters(array $printer_ids): array {
        global $DB;

        $out = ['models' => [], 'color_without_counter' => [], 'color_over_total' => [], 'resets' => []];
        $models = self::getPrinterModels($printer_ids);
        if (empty($models)) {
            return $out;
        }
        $ids          = array_keys($models);

        // Imprimantes couleur : un consommable cyan, magenta ou jaune remonté.
        $color_printers = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($DB->request([
                'SELECT'   => ['printers_id'],
                'DISTINCT' => true,
                'FROM'     => 'glpi_printers_cartridgeinfos',
                'WHERE'    => [
                    'printers_id' => $chunk,
                    'OR'          => [
                        ['property' => ['LIKE', '%cyan%']],
                        ['property' => ['LIKE', '%magenta%']],
                        ['property' => ['LIKE', '%yellow%']],
                    ],
                ],
            ]) as $row) {
                $color_printers[(int) $row['printers_id']] = true;
            }
        }

        // Dernier relevé de compteurs de chaque imprimante.
        $last = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $last_dates = [];
            foreach ($DB->request([
                'SELECT'  => ['items_id', new QueryExpression('MAX(`date`) AS `last_date`')],
                'FROM'    => 'glpi_printerlogs',
                'WHERE'   => ['itemtype' => Printer::class, 'items_id' => $chunk],
                'GROUPBY' => ['items_id'],
            ]) as $row) {
                $last_dates[(int) $row['items_id']] = (string) $row['last_date'];
            }
            if (empty($last_dates)) {
                continue;
            }
            foreach ($DB->request([
                'FROM'  => 'glpi_printerlogs',
                'WHERE' => [
                    'itemtype' => Printer::class,
                    'items_id' => array_keys($last_dates),
                    'date'     => array_values(array_unique($last_dates)),
                ],
            ]) as $row) {
                $pid = (int) $row['items_id'];
                if (($last_dates[$pid] ?? null) === (string) $row['date']) {
                    $last[$pid] = $row;
                }
            }
        }

        // Baisses du compteur total (0 = non remonté, ignoré) sur la fenêtre.
        $resets = [];
        $window = date('Y-m-d', time() - self::COUNTER_WINDOW_DAYS * DAY_TIMESTAMP);
        foreach (array_chunk($ids, 1000) as $chunk) {
            $previous = [];
            foreach ($DB->request([
                'SELECT' => ['items_id', 'date', 'total_pages'],
                'FROM'   => 'glpi_printerlogs',
                'WHERE'  => ['itemtype' => Printer::class, 'items_id' => $chunk, 'date' => ['>=', $window]],
                'ORDER'  => ['items_id', 'date'],
            ]) as $row) {
                $pid   = (int) $row['items_id'];
                $total = (int) $row['total_pages'];
                if ($total <= 0) {
                    continue;
                }
                if (isset($previous[$pid]) && $total < $previous[$pid]) {
                    $resets[$pid] = ['date' => (string) $row['date'], 'before' => $previous[$pid], 'after' => $total];
                }
                $previous[$pid] = $total;
            }
        }

        foreach ($models as $pid => $model) {
            $key = $model['key'];
            $out['models'][$key] ??= [
                'manufacturer' => $model['manufacturer'], 'model' => $model['model'], 'printers' => 0,
                'with_log' => 0, 'stale_log' => 0, 'total_only' => 0, 'split' => 0, 'no_counter' => 0,
                'color_printers' => 0, 'color_without_counter' => 0, 'color_over_total' => 0, 'resets' => 0,
            ];
            $entry = &$out['models'][$key];
            $entry['printers']++;
            $is_color = isset($color_printers[$pid]);
            if ($is_color) {
                $entry['color_printers']++;
            }
            if (isset($resets[$pid])) {
                $entry['resets']++;
                if (count($out['resets']) < self::LIST_MAX) {
                    $out['resets'][] = ['id' => $pid, 'name' => $model['name'], 'model' => $model['model']] + $resets[$pid];
                }
            }

            $log = $last[$pid] ?? null;
            if ($log === null) {
                unset($entry);
                continue;
            }
            $entry['with_log']++;
            if ((string) $log['date'] < self::getPrinterCutoff($model['entities_id'], 'Y-m-d')) {
                $entry['stale_log']++;
            }
            $total = (int) $log['total_pages'];
            $bw    = max((int) $log['bw_pages'], (int) $log['bw_prints'] + (int) $log['bw_copies']);
            $color = max((int) $log['color_pages'], (int) $log['color_prints'] + (int) $log['color_copies']);
            if ($bw > 0 || $color > 0) {
                $entry['split']++;
            } elseif ($total > 0) {
                $entry['total_only']++;
            } else {
                $entry['no_counter']++;
            }
            if ($is_color && $color === 0) {
                $entry['color_without_counter']++;
                if (count($out['color_without_counter']) < self::LIST_MAX) {
                    $out['color_without_counter'][] = ['id' => $pid, 'name' => $model['name'], 'model' => $model['model'], 'date' => (string) $log['date'], 'total' => $total];
                }
            }
            if ($total > 0 && (int) $log['color_pages'] > $total) {
                $entry['color_over_total']++;
                if (count($out['color_over_total']) < self::LIST_MAX) {
                    $out['color_over_total'][] = ['id' => $pid, 'name' => $model['name'], 'model' => $model['model'], 'date' => (string) $log['date'], 'total' => $total, 'color' => (int) $log['color_pages']];
                }
            }
            unset($entry);
        }
        uasort($out['models'], static fn(array $a, array $b) => [$a['manufacturer'], $a['model']] <=> [$b['manufacturer'], $b['model']]);
        return $out;
    }

    /**
     * Numéros de série portés par plusieurs imprimantes actives (doublons d'import : règles
     * d'import GLPI, inventaire local d'un poste, remplacement de carte…).
     *
     * @return array numéro de série => [['id', 'name', 'entity'], ...]
     */
    public static function findDuplicateSerials(bool $restrict_to_session = true): array {
        global $DB;

        $where = ['p.is_deleted' => 0, 'p.is_template' => 0, ['NOT' => ['p.serial' => null]], ['NOT' => ['p.serial' => '']]];
        if ($restrict_to_session) {
            $where[] = getEntitiesRestrictCriteria('p', '', '', true);
        }

        $serials = [];
        foreach ($DB->request([
            'SELECT'  => ['p.serial', new QueryExpression('COUNT(*) AS `cpt`')],
            'FROM'    => 'glpi_printers AS p',
            'WHERE'   => $where,
            'GROUPBY' => ['p.serial'],
            'HAVING'  => ['cpt' => ['>', 1]],
        ]) as $row) {
            $serials[] = (string) $row['serial'];
        }
        if (empty($serials)) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT'    => ['p.id', 'p.name', 'p.serial', 'p.entities_id', 'e.completename AS entity'],
            'FROM'      => 'glpi_printers AS p',
            'LEFT JOIN' => ['glpi_entities AS e' => ['ON' => ['p' => 'entities_id', 'e' => 'id']]],
            'WHERE'     => array_merge($where, ['p.serial' => $serials]),
            'ORDER'     => ['p.serial', 'p.name'],
        ]) as $row) {
            $out[(string) $row['serial']][] = [
                'id'          => (int) $row['id'],
                'name'        => (string) $row['name'],
                'entities_id' => (int) $row['entities_id'],
                'entity'      => (string) ($row['entity'] ?? ''),
            ];
        }
        return $out;
    }

    /** Écran « Contrôle de la remontée ». */
    /**
     * Ligne « prérequis de la collecte » (repliée, verte ou rouge) : inventaire GLPI, plugin GLPI Inventory,
     * inventaires SNMP reçus, agents, versions. $pre : Collectview::getPrerequisites() ou getPrerequisites().
     */
    public static function showPrerequisitesLine(array $pre): void {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $item = static function (bool $ok, string $label, string $detail) use ($esc): void {
            echo "<div class='col-md-6 col-xl-4'><div class='d-flex align-items-start'>"
                . "<i class='ti " . ($ok ? 'ti-circle-check text-success' : 'ti-alert-triangle text-warning') . " fs-2 me-2'></i>"
                . "<div><div class='fw-bold'>" . $esc($label) . "</div><div class='text-muted small'>" . $esc($detail) . "</div></div></div></div>";
        };
        ob_start();
        echo "<div class='row g-3'>";
        $item(
            $pre['inventory_enabled'],
            __('Inventaire GLPI', 'printgestion'),
            $pre['inventory_enabled']
                ? __('Activé.', 'printgestion')
                : __('Désactivé (Administration → Inventaire) : aucun inventaire ne peut être reçu.', 'printgestion')
        );
        $item(
            $pre['glpiinventory_active'],
            __('Plugin GLPI Inventory', 'printgestion'),
            $pre['glpiinventory_active']
                ? __('Actif : tâches de découverte et d\'inventaire réseau disponibles.', 'printgestion')
                : ($pre['glpiinventory_installed']
                    ? __('Installé mais inactif : les tâches d\'inventaire réseau ne sont pas distribuées aux agents.', 'printgestion')
                    : __('Absent : sans lui, GLPI ne planifie pas l\'inventaire réseau des imprimantes.', 'printgestion'))
        );
        $item(
            $pre['inventoried_24h'] > 0,
            __('Inventaires SNMP reçus', 'printgestion'),
            sprintf(
                __('%1$d imprimante(s) sur 24 h, %2$d sur 7 jours, sur %3$d. Dernier inventaire : %4$s.', 'printgestion'),
                $pre['inventoried_24h'],
                $pre['inventoried_7d'],
                $pre['printers'],
                $pre['last_inventory'] !== null ? Html::convDateTime($pre['last_inventory']) : __('aucun', 'printgestion')
            )
        );
        $item(
            $pre['agents'] > 0 && $pre['agents_silent'] === 0,
            __('Agents', 'printgestion'),
            sprintf(__('%1$d agent(s) ont inventorié ces imprimantes, dont %2$d qui ne remonte(nt) plus.', 'printgestion'), $pre['agents'], $pre['agents_silent'])
        );
        $item(
            $pre['agents'] > 0 && $pre['agents_old'] === 0 && $pre['agents_update'] === 0,
            __('Versions des agents', 'printgestion'),
            sprintf(
                __('%1$d trop ancienne(s) (avant %2$s : une valeur invalide fait rejeter tout l\'inventaire), %3$d à mettre à jour (dernière version connue : %4$s, ou version cible de la sonde).', 'printgestion'),
                $pre['agents_old'],
                self::AGENT_MIN_VERSION,
                $pre['agents_update'],
                PluginPrintgestionAgentsetting::getLatestVersion()['version']
            )
        );
        echo "</div>";
        $prerequisites_html = (string) ob_get_clean();
        $prerequisites_ok   = $pre['inventory_enabled'] && $pre['glpiinventory_active'] && $pre['inventoried_24h'] > 0
            && $pre['agents'] > 0 && $pre['agents_silent'] === 0 && $pre['agents_old'] === 0;
        echo "<div class='card mb-3'><div class='card-body'>" . PluginPrintgestionUi::statusLine(
            $prerequisites_ok ? 'ok' : 'error',
            $prerequisites_ok ? __('Collecte : prérequis corrects', 'printgestion') : __('Collecte incomplète — contactez l\'administrateur', 'printgestion'),
            $prerequisites_html
        ) . "</div></div>";
    }

    /**
     * Analyses de réglage, pour l'administrateur (repliées) : valeurs de consommables reçues par modèle, compteurs
     * disponibles par modèle, numéros de série en double. $printer_ids : imprimantes des entités de l'utilisateur.
     */
    public static function showAdminAnalyses(array $printer_ids): void {
        if (!PluginPrintgestionUi::isAdmin()) {
            return;
        }
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        ob_start();
        // 3. Valeurs de consommables reçues.
        $class_labels = self::getValueClassLabels();
        $class_colors = [
            'percent' => 'green', 'zero' => 'orange', 'ok' => 'azure', 'warning' => 'yellow', 'pages' => 'teal',
            'unit' => 'purple', 'negative' => 'red', 'over' => 'red', 'empty' => 'secondary', 'other' => 'secondary',
        ];
        $values = self::analyzeValues($printer_ids);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Valeurs de consommables reçues, par modèle', 'printgestion')) . "</h3></div>";
        echo "<div class='card-body pb-0'><p class='text-muted small'>" . $esc(__('Valeurs brutes de glpi_printers_cartridgeinfos, telles que l\'agent les envoie : pourcentage, OK ou WARNING (niveau non chiffré), 0 (faux zéro possible), pages restantes, autre unité, valeur négative ou supérieure à 100. Une valeur non chiffrée n\'a de sens qu\'avec le fabricant : ce tableau sert à régler la lecture par constructeur et par modèle.', 'printgestion')) . "</p></div>";
        if (empty($values)) {
            echo "<div class='card-body text-muted'>" . $esc(__('Aucune valeur de consommable remontée.', 'printgestion')) . "</div></div>";
        } else {
            $columns = [
                'manufacturer' => Manufacturer::getTypeName(1),
                'model'        => __('Modèle', 'printgestion'),
                'printers'     => __('Imprimantes', 'printgestion'),
                'property'     => __('Propriété', 'printgestion'),
                'values'       => __('Valeurs reçues', 'printgestion'),
                'examples'     => __('Exemples non chiffrés', 'printgestion'),
            ];
            $entries = [];
            foreach ($values as $entry) {
                foreach ($entry['properties'] as $property => $data) {
                    $badges = '';
                    foreach ($class_labels as $class => $class_label) {
                        if (!empty($data['classes'][$class])) {
                            $badges .= "<span class='badge bg-" . $class_colors[$class] . "-lt me-1'>" . $esc($class_label) . ' ' . (int) $data['classes'][$class] . "</span>";
                        }
                    }
                    $examples = [];
                    foreach ($data['examples'] ?? [] as $raw) {
                        $examples[] = "<code>" . $esc($raw) . "</code>";
                    }
                    $entries[] = [
                        'manufacturer' => $entry['manufacturer'] !== '' ? $entry['manufacturer'] : '—',
                        'model'        => $entry['model'] !== '' ? $entry['model'] : '—',
                        'printers'     => (int) $entry['printers'],
                        'property'     => "<code>" . $esc($property) . "</code>",
                        'values'       => $badges,
                        'examples'     => implode(' ', $examples),
                    ];
                }
            }
            echo PluginPrintgestionUi::datatable($columns, $entries, ['printers' => 'integer', 'property' => 'raw_html', 'values' => 'raw_html', 'examples' => 'raw_html']);
            echo "</div>";
        }

        $values_html = (string) ob_get_clean();
        echo PluginPrintgestionUi::adminDetails(__('Valeurs de consommables reçues, par modèle', 'printgestion'), $values_html);
        ob_start();
        // 4. Compteurs disponibles et incohérences.
        $counters = self::analyzeCounters($printer_ids);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Compteurs disponibles, par modèle', 'printgestion')) . "</h3></div>";
        echo "<div class='card-body pb-0'><p class='text-muted small'>" . $esc(sprintf(
            __('Dernier relevé de compteurs de chaque imprimante (glpi_printerlogs, un relevé par jour). « Total seul » : aucune répartition noir et blanc / couleur, facturation couleur impossible par SNMP. « Relevé ancien » : plus de %1$d jour(s), ou la fréquence de relevé de l\'entité plus un jour. « Baisses » : compteur total en baisse sur %2$d jours (remise à zéro, remplacement de carte, changement de compteur lu par l\'agent).', 'printgestion'),
            self::getSilentDays(),
            self::COUNTER_WINDOW_DAYS
        )) . "</p></div>";
        if (empty($counters['models'])) {
            echo "<div class='card-body text-muted'>" . $esc(__('Aucune imprimante.', 'printgestion')) . "</div></div>";
        } else {
            $headers = [
                'printers'              => __('Imprimantes', 'printgestion'),
                'with_log'              => __('Avec relevé', 'printgestion'),
                'stale_log'             => __('Relevé ancien', 'printgestion'),
                'split'                 => __('N&B et couleur', 'printgestion'),
                'total_only'            => __('Total seul', 'printgestion'),
                'no_counter'            => __('Compteurs à 0', 'printgestion'),
                'color_without_counter' => __('Couleur sans compteur couleur', 'printgestion'),
                'color_over_total'      => __('Couleur > total', 'printgestion'),
                'resets'                => __('Baisses', 'printgestion'),
            ];
            $warn = ['stale_log', 'total_only', 'no_counter', 'color_without_counter', 'color_over_total', 'resets'];
            $columns = ['manufacturer' => Manufacturer::getTypeName(1), 'model' => __('Modèle', 'printgestion')] + $headers;
            $entries = [];
            foreach ($counters['models'] as $entry) {
                $line = [
                    'manufacturer' => $entry['manufacturer'] !== '' ? $entry['manufacturer'] : '—',
                    'model'        => $entry['model'] !== '' ? $entry['model'] : '—',
                ];
                foreach (array_keys($headers) as $field) {
                    $count        = (int) $entry[$field];
                    $line[$field] = in_array($field, $warn, true) && $count > 0
                        ? "<span class='d-block text-end text-danger fw-bold'>" . $count . "</span>"
                        : "<span class='d-block text-end'>" . $count . "</span>";
                }
                $entries[] = $line;
            }
            echo PluginPrintgestionUi::datatable($columns, $entries, array_fill_keys(array_keys($headers), 'raw_html'));

            $lists = [
                'color_without_counter' => [__('Imprimantes couleur sans compteur couleur', 'printgestion'), [__('Date du relevé', 'printgestion'), __('Total', 'printgestion')]],
                'color_over_total'      => [__('Compteur couleur supérieur au total', 'printgestion'), [__('Date du relevé', 'printgestion'), __('Couleur / total', 'printgestion')]],
                'resets'                => [__('Baisses du compteur total', 'printgestion'), [__('Date', 'printgestion'), __('Avant → après', 'printgestion')]],
            ];
            foreach ($lists as $key => [$title, $columns]) {
                if (empty($counters[$key])) {
                    continue;
                }
                echo "<div class='card-body pt-2 pb-0'><h4 class='mb-2'>" . $esc($title) . " <span class='badge bg-secondary text-secondary-fg'>" . count($counters[$key]) . "</span></h4></div>";
                $cols    = ['printer' => _n('Imprimante', 'Imprimantes', 1, 'printgestion'), 'model' => __('Modèle', 'printgestion'), 'when' => $columns[0], 'figure' => $columns[1]];
                $entries = [];
                foreach ($counters[$key] as $row) {
                    $figure = match ($key) {
                        'color_over_total' => number_format($row['color'], 0, ',', ' ') . ' / ' . number_format($row['total'], 0, ',', ' '),
                        'resets'           => number_format($row['before'], 0, ',', ' ') . ' → ' . number_format($row['after'], 0, ',', ' '),
                        default            => number_format($row['total'], 0, ',', ' '),
                    };
                    $entries[] = [
                        'itemtype' => Printer::class,
                        'id'       => (int) $row['id'],
                        'printer'  => "<a href='" . $esc(Printer::getFormURLWithID($row['id'])) . "'>" . $esc($row['name']) . "</a>",
                        'model'    => $row['model'],
                        'when'     => Html::convDate($row['date']),
                        'figure'   => "<span class='d-block text-end'>" . $esc($figure) . "</span>",
                    ];
                }
                echo PluginPrintgestionUi::datatable($cols, $entries, ['printer' => 'raw_html', 'figure' => 'raw_html']);
            }
            echo "</div>";
        }

        $counters_html = (string) ob_get_clean();
        echo PluginPrintgestionUi::adminDetails(__('Compteurs disponibles, par modèle', 'printgestion'), $counters_html);
        ob_start();
        // 5. Numéros de série en double.
        $duplicates = self::findDuplicateSerials(true);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Numéros de série en double', 'printgestion'))
            . " <span class='badge bg-secondary text-secondary-fg ms-1'>" . count($duplicates) . "</span></h3></div>";
        if (empty($duplicates)) {
            echo "<div class='card-body text-muted'>" . $esc(__('Aucun : chaque numéro de série correspond à une seule imprimante active.', 'printgestion')) . "</div></div>";
        } else {
            echo "<div class='card-body pb-0'><p class='text-muted small'>" . $esc(__('Plusieurs imprimantes actives pour un même numéro de série : l\'historique des niveaux et des compteurs est scindé entre elles (règles d\'import GLPI, inventaire local d\'un poste, import par nom ou par adresse IP). À fusionner ou corriger dans GLPI. Dans des entités différentes, elles ne partagent pas les verrous anti-double-envoi.', 'printgestion')) . "</p></div>";
            $entries = [];
            foreach ($duplicates as $serial => $printers) {
                $links = array_map(
                    static fn(array $p) => "<a href='" . $esc(Printer::getFormURLWithID($p['id'])) . "'>" . $esc($p['name']) . "</a>" . ($p['entity'] !== '' ? " <span class='text-muted small'>(" . $esc($p['entity']) . ")</span>" : ''),
                    $printers
                );
                // Entités différentes : verrous anti-double-envoi non partagés (Guard::resolveMachines()), à vérifier.
                $cross = count(array_unique(array_column($printers, 'entities_id'))) > 1
                    ? "<br><span class='badge bg-warning text-warning-fg'>" . $esc(__('Entités différentes : verrous anti-double-envoi non partagés, à vérifier', 'printgestion')) . "</span>"
                    : '';
                $entries[] = ['serial' => "<code>" . $esc($serial) . "</code>" . $cross, 'printers' => implode('<br>', $links)];
            }
            // data-pg-noclick : plusieurs imprimantes par ligne, la ligne ne s'ouvre pas d'un clic (comme avant).
            echo "<div data-pg-noclick='1'>" . PluginPrintgestionUi::datatable(
                ['serial' => __('Numéro de série', 'printgestion'), 'printers' => _n('Imprimante', 'Imprimantes', 2, 'printgestion')],
                $entries,
                ['serial' => 'raw_html', 'printers' => 'raw_html']
            ) . "</div>";
            echo "</div>";
        }
        echo PluginPrintgestionUi::adminDetails(__('Numéros de série en double', 'printgestion'), (string) ob_get_clean());
    }
}
