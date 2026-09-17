<?php
/**
 * PluginPrintgestionBilling — Point 6 : calcul coût à la page + consolidation.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionBilling extends CommonDBTM {

    static $rightname = 'plugin_printgestion_billing';

    static function getTypeName($nb = 0) {
        return __('Facturation Print Gestion', 'printgestion');
    }

    public static function getTable($classname = null) {
        if ($classname === null || $classname === static::class) {
            return 'glpi_plugin_printgestion_billing';
        }
        return parent::getTable($classname);
    }

    /**
     * Calcule les coûts par imprimante pour une période donnée.
     * Retourne une liste de lignes consolidées.
     */
    /**
     * @param array $filters critères de l'écran Facturation (jamais un réglage) : 'contract', 'counter', 'activity'
     *                       (bool, vrai par défaut) — imprimantes liées à un contrat, avec un compteur, avec de l'activité.
     */
    public static function computeForPeriod(string $start, string $end, ?int $entities_id = null, array $filters = []): array {
        global $DB;

        $require_contract = (bool) ($filters['contract'] ?? true);
        $require_counter  = (bool) ($filters['counter']  ?? true);
        $require_activity = (bool) ($filters['activity'] ?? true);

        $rows = [];

        $criteria = [
            'SELECT' => [
                'p.id AS printers_id',
                'p.name AS printer_name',
                'p.entities_id',
                'e.completename AS entity_name',
            ],
            'FROM'      => 'glpi_printers AS p',
            'LEFT JOIN' => [
                'glpi_entities AS e' => [
                    'ON' => ['p' => 'entities_id', 'e' => 'id'],
                ],
            ],
            'WHERE' => [
                'p.is_deleted'  => 0,
                'p.is_template' => 0,
            ],
            'ORDER' => ['e.completename', 'p.name'],
        ];

        if ($entities_id !== null && $entities_id >= 0) {
            $criteria['WHERE']['p.entities_id'] = $entities_id;
        }
        // Calcul destiné à l'affichage et à l'export : toujours limité aux entités
        // de l'utilisateur connecté, même si le filtre d'entité est vide ou manipulé.
        $criteria['WHERE'][] = getEntitiesRestrictCriteria('p', '', '', true);

        foreach ($DB->request($criteria) as $p) {
            $printers_id  = (int)$p['printers_id'];
            $contracts_id = PluginPrintgestionContractrate::getContractIdForPrinter($printers_id);
            $rates        = PluginPrintgestionContractrate::getRatesForContract($contracts_id);
            $counters     = PluginPrintgestionPrinterCostsTab::getCountersForPeriod($printers_id, $start, $end);

            $delta_nb    = max(0, $counters['end_nb']    - $counters['start_nb']);
            $delta_color = max(0, $counters['end_color'] - $counters['start_color']);
            $cost        = $delta_nb * $rates['nb'] + $delta_color * $rates['color'];

            $contract_name = '—';
            if ($contracts_id > 0) {
                $c = new Contract();
                if ($c->getFromDB($contracts_id)) {
                    $contract_name = (string)$c->fields['name'];
                }
            }

            // Filtres configurables côté config plugin (3 toggles, ON par défaut) :
            //   - billing_require_contract : n'affiche que les imprimantes avec contrat
            //   - billing_require_counter  : n'affiche que celles avec au moins un log
            //   - billing_require_activity : n'affiche que celles avec N&B ou Couleur > 0
            $has_counter  = ($counters['end_nb'] > 0 || $counters['end_color'] > 0
                          || $counters['start_nb'] > 0 || $counters['start_color'] > 0);

            // Activité = au moins une page imprimée (N&B OU couleur) sur la période.
            // Une imprimante couleur qui n'imprime qu'en N&B ce mois-ci compte quand même.
            $has_activity = ($delta_nb > 0 || $delta_color > 0);

            if ($require_contract && $contracts_id === 0) {
                continue;
            }
            if ($require_counter && !$has_counter) {
                continue;
            }
            if ($require_activity && !$has_activity) {
                continue;
            }
            // Aucun filtre de sécurité : si l'admin décoche tout, toutes les imprimantes
            // (y compris celles sans contrat ET sans compteur) sont affichées.

            $rows[] = [
                'printers_id'   => $printers_id,
                'printer_name'  => (string)$p['printer_name'],
                'entities_id'   => (int)$p['entities_id'],
                'entity_name'   => (string)($p['entity_name'] ?? ''),
                'contracts_id'  => $contracts_id,
                'contract_name' => $contract_name,
                'pages_nb'      => $delta_nb,
                'pages_color'   => $delta_color,
                'rate_nb'       => $rates['nb'],
                'rate_color'    => $rates['color'],
                'total_cost'    => $cost,
            ];
        }

        return $rows;
    }

    /**
     * Détecte si une imprimante est couleur.
     * Double heuristique (l'une OU l'autre suffit) :
     *   1. Un toner couleur (cyan/magenta/yellow) est présent dans
     *      glpi_printers_cartridgeinfos. Critère le plus fiable — les monochromes
     *      n'ont physiquement que le toner noir donc l'agent ne remonte rien d'autre.
     *   2. Fallback : au moins un printerlog a color_pages > 0 (historique).
     */
    protected static function isColorPrinter(int $printers_id, array $current_counters): bool {
        global $DB;

        // 1. Toner couleur dans l'inventaire SNMP
        $row = $DB->request([
            'SELECT' => [new \QueryExpression('COUNT(*) AS cnt')],
            'FROM'   => 'glpi_printers_cartridgeinfos',
            'WHERE'  => [
                'printers_id' => $printers_id,
                'property'    => ['LIKE', '%cyan%'], // tonercyan, tonerCyan, etc.
            ],
        ])->current();
        if (is_array($row) && (int)($row['cnt'] ?? 0) > 0) {
            return true;
        }
        foreach (['magenta', 'yellow'] as $kw) {
            $row = $DB->request([
                'SELECT' => [new \QueryExpression('COUNT(*) AS cnt')],
                'FROM'   => 'glpi_printers_cartridgeinfos',
                'WHERE'  => [
                    'printers_id' => $printers_id,
                    'property'    => ['LIKE', '%' . $kw . '%'],
                ],
            ])->current();
            if (is_array($row) && (int)($row['cnt'] ?? 0) > 0) {
                return true;
            }
        }

        // 2. Fallback : un printerlog a remonté color_pages > 0 historiquement
        if ($current_counters['end_color'] > 0 || $current_counters['start_color'] > 0) {
            return true;
        }
        $row = $DB->request([
            'SELECT' => [new \QueryExpression('MAX(color_pages) AS max_color')],
            'FROM'   => 'glpi_printerlogs',
            'WHERE'  => ['itemtype' => 'Printer', 'items_id' => $printers_id],
        ])->current();
        return is_array($row) && (int)($row['max_color'] ?? 0) > 0;
    }

    /**
     * Version cachée de computeForPeriod via $GLPI_CACHE (fichiers dans files/_cache/).
     * TTL 10min — suffisant pour un dashboard consulté.
     */
    public static function computeForPeriodCached(string $start, string $end, ?int $entities_id = null, array $filters = []): array {
        global $GLPI_CACHE;

        // Les critères de l'écran font partie de la clé.
        $filters_sig = (int) (bool) ($filters['contract'] ?? true)
            . '_' . (int) (bool) ($filters['counter']  ?? true)
            . '_' . (int) (bool) ($filters['activity'] ?? true);
        // v3 = formule universelle total = max(sources), color = max, bw = total - color
        // Version counter pour invalidation manuelle (refresh dashboard)
        $ver = (int)($GLPI_CACHE->get('plugin_printgestion_billing_ver') ?? 0);
        // Le périmètre d'entités de l'utilisateur fait partie de la clé : les lignes
        // calculées sont restreintes à ce périmètre.
        $key = 'plugin_printgestion_billing_v3_' . $ver . '_'
             . md5($start . '|' . $end . '|' . ($entities_id ?? 'all') . '|' . $filters_sig
                 . '|' . PluginPrintgestionSecurity::sessionEntityScopeKey());
        if (isset($GLPI_CACHE) && $GLPI_CACHE->has($key)) {
            $cached = $GLPI_CACHE->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $rows = self::computeForPeriod($start, $end, $entities_id, $filters);
        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->set($key, $rows, 600);
        }
        return $rows;
    }

    /**
     * Liste paginée + filtrée + triée pour le dashboard (source = cache GLPI).
     * Params attendus : start, end, entities_id, view (printer|client), page, per_page, search, sort_col, sort_dir.
     */
    public static function listPagedCached(array $params): array {
        $start = (string)($params['start'] ?? date('Y-m-01'));
        $end   = (string)($params['end']   ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-d');

        $entities_id = (isset($params['entities_id']) && $params['entities_id'] !== '' && (int)$params['entities_id'] >= 0)
            ? (int)$params['entities_id'] : null;

        $view = (string)($params['view'] ?? 'printer');
        if (!in_array($view, ['printer', 'client'], true)) {
            $view = 'printer';
        }

        $rows = self::computeForPeriodCached($start, $end, $entities_id, (array) ($params['filters'] ?? []));
        if ($view === 'client') {
            $rows = self::groupByClient($rows);
        }

        // Recherche globale
        $search = trim((string)($params['search'] ?? ''));
        if ($search !== '') {
            $q = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function ($r) use ($q) {
                $hay = mb_strtolower(
                    ($r['printer_name']  ?? '') . ' '
                    . ($r['entity_name']   ?? '') . ' '
                    . ($r['contract_name'] ?? '')
                );
                return mb_strpos($hay, $q) !== false;
            }));
        }

        // Tri
        $sort_col = (string)($params['sort_col'] ?? '');
        $sort_dir = strtolower((string)($params['sort_dir'] ?? 'asc')) === 'desc' ? -1 : 1;
        if ($sort_col !== '') {
            usort($rows, function ($a, $b) use ($sort_col, $sort_dir) {
                $av = $a[$sort_col] ?? '';
                $bv = $b[$sort_col] ?? '';
                if (is_numeric($av) && is_numeric($bv)) {
                    return (($av <=> $bv)) * $sort_dir;
                }
                return strcasecmp((string)$av, (string)$bv) * $sort_dir;
            });
        }

        $total    = count($rows);
        $page     = max(1, (int)($params['page'] ?? 1));
        $per_page = max(1, min(500, (int)($params['per_page'] ?? 25)));
        $offset   = ($page - 1) * $per_page;

        // Totaux globaux (avant pagination)
        $total_nb    = array_sum(array_column($rows, 'pages_nb'));
        $total_color = array_sum(array_column($rows, 'pages_color'));
        $total_cost  = array_sum(array_column($rows, 'total_cost'));

        if ($view === 'client') {
            $nb_clients  = $total;
            $nb_printers = (int)array_sum(array_column($rows, 'printers'));
        } else {
            $nb_printers = $total;
            $nb_clients  = count(array_unique(array_column($rows, 'entities_id')));
        }

        return [
            'rows'        => array_slice($rows, $offset, $per_page),
            'total'       => $total,
            'total_nb'    => (int)$total_nb,
            'total_color' => (int)$total_color,
            'total_cost'  => (float)$total_cost,
            'nb_clients'  => $nb_clients,
            'nb_printers' => $nb_printers,
        ];
    }

    public static function invalidateCache(): void {
        global $GLPI_CACHE;
        if (!isset($GLPI_CACHE)) {
            return;
        }
        // PSR-16 ne supporte pas delete-by-pattern : on bump un compteur inclus
        // dans la clé de cache. Toutes les variantes en cache deviennent
        // orphelines et expirent via leur TTL de 10min.
        $ver = (int)($GLPI_CACHE->get('plugin_printgestion_billing_ver') ?? 0);
        $GLPI_CACHE->set('plugin_printgestion_billing_ver', $ver + 1, 86400);
    }

    /**
     * Consolide les lignes par client (entité GLPI).
     */
    public static function groupByClient(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $eid = (int)$r['entities_id'];
            if (!isset($out[$eid])) {
                $out[$eid] = [
                    'entities_id' => $eid,
                    'entity_name' => $r['entity_name'],
                    'printers'    => 0,
                    'pages_nb'    => 0,
                    'pages_color' => 0,
                    'total_cost'  => 0.0,
                ];
            }
            $out[$eid]['printers']++;
            $out[$eid]['pages_nb']    += $r['pages_nb'];
            $out[$eid]['pages_color'] += $r['pages_color'];
            $out[$eid]['total_cost']  += $r['total_cost'];
        }
        usort($out, fn($a, $b) => $b['total_cost'] <=> $a['total_cost']);
        return array_values($out);
    }

    static function install(Migration $migration) { return true; }
    static function uninstall(Migration $migration) { return true; }
}
