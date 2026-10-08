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

        // Tarifs et nom du contrat lus une fois par contrat, pas une fois par imprimante :
        // un contrat couvre souvent tout un parc (lectures seules, même résultat).
        $rates_by_contract = [];
        $names_by_contract = [];

        foreach ($DB->request($criteria) as $p) {
            $printers_id  = (int)$p['printers_id'];
            $contracts_id = PluginPrintgestionContractrate::getContractIdForPrinter($printers_id);

            // Imprimante écartée de toute façon (filtre contrat) : inutile de lire ses compteurs.
            if ($require_contract && $contracts_id === 0) {
                continue;
            }

            $rates        = $rates_by_contract[$contracts_id]
                ??= PluginPrintgestionContractrate::getRatesForContract($contracts_id);
            $counters     = PluginPrintgestionPrinterCostsTab::getCountersForPeriod($printers_id, $start, $end);

            $delta_nb    = max(0, $counters['end_nb']    - $counters['start_nb']);
            $delta_color = max(0, $counters['end_color'] - $counters['start_color']);
            $cost        = $delta_nb * $rates['nb'] + $delta_color * $rates['color'];

            if (!isset($names_by_contract[$contracts_id])) {
                $names_by_contract[$contracts_id] = '—';
                if ($contracts_id > 0) {
                    $c = new Contract();
                    if ($c->getFromDB($contracts_id)) {
                        $names_by_contract[$contracts_id] = (string)$c->fields['name'];
                    }
                }
            }
            $contract_name = $names_by_contract[$contracts_id];

            // Filtres configurables côté config plugin (3 toggles, ON par défaut) :
            //   - contract : n'affiche que les imprimantes avec contrat
            //   - counter  : n'affiche que celles avec au moins un log
            //   - activity : n'affiche que celles avec N&B ou Couleur > 0
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
        $ver = self::cacheVersion();
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

        $started = microtime(true);
        $rows    = self::computeForPeriod($start, $end, $entities_id, $filters);
        PluginPrintgestionLogger::duration(
            'facturation',
            'Calcul du coût à la page',
            $started,
            sprintf('%d imprimante(s) retenue(s), du %s au %s', count($rows), $start, $end)
        );
        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->set($key, $rows, 600);
        }
        return $rows;
    }

    /**
     * Compteur d'invalidation manuelle (bouton « Rafraîchir »), inclus dans la clé du
     * calcul en cache et dans l'empreinte des lignes matérialisées par utilisateur
     * (PluginPrintgestionBillingview::rebuildForUser) : l'incrémenter force les deux.
     */
    public static function cacheVersion(): int {
        global $GLPI_CACHE;
        if (!isset($GLPI_CACHE)) {
            return 0;
        }
        return (int)($GLPI_CACHE->get('plugin_printgestion_billing_ver') ?? 0);
    }

    public static function invalidateCache(): void {
        global $GLPI_CACHE;
        if (!isset($GLPI_CACHE)) {
            return;
        }
        // PSR-16 ne supporte pas delete-by-pattern : on bump un compteur inclus
        // dans la clé de cache. Toutes les variantes en cache deviennent
        // orphelines et expirent via leur TTL de 10min. Le même compteur entre dans
        // l'empreinte des lignes matérialisées : chaque utilisateur est reconstruit
        // à son prochain affichage.
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
