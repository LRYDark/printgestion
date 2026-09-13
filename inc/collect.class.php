<?php
/**
 * PluginPrintgestionCollect — fiabilité de la collecte SNMP : imprimantes muettes et agents
 * qui ne remontent plus.
 *
 * L'absence de remontée est un ÉTAT À SIGNALER, jamais une absence d'alerte : une imprimante
 * dont on ne lit plus les niveaux ne déclenchera aucune alerte toner, précisément quand il
 * faudrait s'en inquiéter. Aucune hypothèse de couverture complète du parc par les agents.
 *
 * États d'une imprimante (ni supprimée ni modèle) :
 *   - no_inventory : jamais inventoriée en SNMP (ni date d'inventaire ni consommable remonté) ;
 *   - stale        : dernier inventaire plus ancien que silent_days jours ;
 *   - no_level     : inventaire à jour mais aucun niveau lisible (sentinelles, OK, valeurs
 *                    inconnues) : pas d'alerte possible ;
 *   - ok.
 * Agent : dernier agent ayant inventorié l'imprimante (journal des règles d'import) ; muet
 * si son dernier contact date de plus de silent_days jours.
 */

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

    static function getTypeName($nb = 0) {
        return __('Collecte SNMP', 'printgestion');
    }

    public static function getStateLabels(): array {
        return [
            self::STATE_NO_INVENTORY => __('Jamais remontée', 'printgestion'),
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

    /**
     * États de collecte.
     *
     * @param bool $restrict_to_session true : entités de l'utilisateur ; false : toutes
     *                                  (tâche automatique uniquement).
     * @return array ['printers' => [id => ['id', 'name', 'entity', 'state', 'last_inventory',
     *                                      'agents_id', 'agent_name', 'agent_last_contact']],
     *                'counts'   => [état => nombre],
     *                'agents'   => [agents_id => ['id', 'name', 'last_contact', 'is_silent', 'printers']]]
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
            'SELECT'    => ['p.id', 'p.name', 'p.last_inventory_update', 'e.completename AS entity'],
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
        $ids = array_map(static fn(array $p) => (int) $p['id'], $printers);

        // Dernière remontée de consommables (à défaut de date d'inventaire sur la fiche).
        $cartridge_dates = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($DB->request([
                'SELECT'  => ['printers_id', new QueryExpression('MAX(`date_mod`) AS `last_date`')],
                'FROM'    => 'glpi_printers_cartridgeinfos',
                'WHERE'   => ['printers_id' => $chunk],
                'GROUPBY' => ['printers_id'],
            ]) as $row) {
                $cartridge_dates[(int) $row['printers_id']] = (string) $row['last_date'];
            }
        }

        // Dernier agent ayant inventorié chaque imprimante.
        $agent_of = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['items_id', 'agents_id', 'date'],
                'FROM'   => 'glpi_rulematchedlogs',
                'WHERE'  => ['itemtype' => Printer::class, 'items_id' => $chunk, 'agents_id' => ['>', 0]],
                'ORDER'  => ['date ASC'],
            ]) as $row) {
                $agent_of[(int) $row['items_id']] = (int) $row['agents_id'];
            }
        }
        $agents = [];
        if (!empty($agent_of)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'last_contact'],
                'FROM'   => 'glpi_agents',
                'WHERE'  => ['id' => array_values(array_unique($agent_of))],
            ]) as $agent) {
                $agents[(int) $agent['id']] = [
                    'id'           => (int) $agent['id'],
                    'name'         => (string) $agent['name'],
                    'last_contact' => $agent['last_contact'],
                    'is_silent'    => empty($agent['last_contact']) || (string) $agent['last_contact'] < $cutoff,
                    'printers'     => 0,
                ];
            }
        }

        $levels = PluginPrintgestionSnmpadapter::getLevels($ids);

        foreach ($printers as $printer) {
            $pid  = (int) $printer['id'];
            $last = (string) ($printer['last_inventory_update'] ?? '');
            if ($last === '' && isset($cartridge_dates[$pid])) {
                $last = $cartridge_dates[$pid];
            }

            $readable = false;
            foreach ($levels[$pid] ?? [] as $parsed) {
                if ($parsed['usable']) {
                    $readable = true;
                    break;
                }
            }

            if ($last === '' && !isset($cartridge_dates[$pid])) {
                $state = self::STATE_NO_INVENTORY;
            } elseif ($last < $cutoff) {
                $state = self::STATE_STALE;
            } elseif (!$readable) {
                $state = self::STATE_NO_LEVEL;
            } else {
                $state = self::STATE_OK;
            }

            $agents_id = $agent_of[$pid] ?? 0;
            if (isset($agents[$agents_id])) {
                $agents[$agents_id]['printers']++;
            }

            $out['counts'][$state]++;
            $out['printers'][$pid] = [
                'id'                 => $pid,
                'name'               => (string) $printer['name'],
                'entity'             => (string) ($printer['entity'] ?? ''),
                'state'              => $state,
                'last_inventory'     => $last !== '' ? $last : null,
                'agents_id'          => $agents_id,
                'agent_name'         => $agents[$agents_id]['name'] ?? '',
                'agent_last_contact' => $agents[$agents_id]['last_contact'] ?? null,
            ];
        }

        uasort($agents, static fn(array $a, array $b) => [$b['is_silent'], $b['printers']] <=> [$a['is_silent'], $a['printers']]);
        $out['agents'] = $agents;
        return $out;
    }

    /** Écran « Collecte SNMP » : compteurs, agents, imprimantes de l'état choisi. */
    public static function showPage(string $state_filter): void {
        $esc      = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $analysis = self::analyze(true);
        $labels   = self::getStateLabels();
        $page     = PLUGIN_PRINTGESTION_WEBDIR . '/front/collect.php';

        $colors = [self::STATE_NO_INVENTORY => 'secondary', self::STATE_STALE => 'red', self::STATE_NO_LEVEL => 'orange', self::STATE_OK => 'green'];
        $icons  = [self::STATE_NO_INVENTORY => 'ti ti-help-circle', self::STATE_STALE => 'ti ti-wifi-off', self::STATE_NO_LEVEL => 'ti ti-droplet-off', self::STATE_OK => 'ti ti-check'];
        $cards  = [];
        foreach ($labels as $state => $label) {
            $cards[] = [
                'count' => $analysis['counts'][$state],
                'label' => $label,
                'icon'  => $icons[$state],
                'color' => $colors[$state],
                'url'   => $page . '?state=' . $state,
            ];
        }
        PluginPrintgestionUi::statsBar($cards, 'printgestionCollectStatsBar');

        echo "<p class='text-muted small'>" . $esc(sprintf(
            __('Muette : aucun inventaire depuis plus de %d jour(s) (Configuration → Print Gestion). Une imprimante muette ou sans niveau lisible ne déclenche aucune alerte toner : à traiter comme une alerte.', 'printgestion'),
            self::getSilentDays()
        )) . "</p>";

        // Agents.
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(__('Agents d\'inventaire des imprimantes', 'printgestion')) . "</h3></div>";
        if (empty($analysis['agents'])) {
            echo "<div class='card-body text-muted'>" . $esc(__('Aucun agent connu pour ces imprimantes (journal d\'import GLPI vide).', 'printgestion')) . "</div></div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-sm card-table'><thead><tr>"
                . "<th>" . $esc(__('Agent', 'printgestion')) . "</th><th>" . $esc(__('Dernier contact', 'printgestion')) . "</th>"
                . "<th class='text-end'>" . $esc(__('Imprimantes', 'printgestion')) . "</th><th>" . $esc(__('État', 'printgestion')) . "</th></tr></thead><tbody>";
            foreach ($analysis['agents'] as $agent) {
                echo "<tr><td><a href='" . $esc(Agent::getFormURLWithID($agent['id'])) . "'>" . $esc($agent['name']) . "</a></td>"
                    . "<td>" . $esc(Html::convDateTime((string) $agent['last_contact'])) . "</td>"
                    . "<td class='text-end'>" . (int) $agent['printers'] . "</td>"
                    . "<td>" . ($agent['is_silent']
                        ? "<span class='badge bg-red text-red-fg'>" . $esc(__('Ne remonte plus', 'printgestion')) . "</span>"
                        : "<span class='badge bg-green text-green-fg'>" . $esc(__('Actif', 'printgestion')) . "</span>") . "</td></tr>";
            }
            echo "</tbody></table></div></div>";
        }

        // Imprimantes de l'état choisi (par défaut : tout sauf la collecte normale).
        $rows = array_filter($analysis['printers'], static fn(array $p) => $state_filter === ''
            ? $p['state'] !== self::STATE_OK
            : $p['state'] === $state_filter);

        echo "<div class='card'><div class='card-header'><h3 class='card-title mb-0'>" . $esc($state_filter === ''
            ? __('Imprimantes à surveiller (hors collecte normale)', 'printgestion')
            : sprintf(__('Imprimantes : %s', 'printgestion'), $labels[$state_filter] ?? $state_filter))
            . " <span class='badge bg-secondary text-secondary-fg ms-1'>" . count($rows) . "</span></h3></div>";
        if (empty($rows)) {
            echo "<div class='card-body text-muted'>" . $esc(__('Aucune.', 'printgestion')) . "</div></div>";
            return;
        }
        echo "<div class='table-responsive'><table class='table table-sm card-table'><thead><tr>"
            . "<th>" . $esc(_n('Imprimante', 'Imprimantes', 1, 'printgestion')) . "</th><th>" . $esc(Entity::getTypeName(1)) . "</th>"
            . "<th>" . $esc(__('État', 'printgestion')) . "</th><th>" . $esc(__('Dernier inventaire', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Agent', 'printgestion')) . "</th></tr></thead><tbody>";
        foreach (array_slice($rows, 0, 1000) as $printer) {
            echo "<tr><td><a href='" . $esc(Printer::getFormURLWithID($printer['id'])) . "'>" . $esc($printer['name']) . "</a></td>"
                . "<td>" . $esc($printer['entity']) . "</td>"
                . "<td><span class='badge bg-" . $colors[$printer['state']] . "-lt'>" . $esc($labels[$printer['state']]) . "</span></td>"
                . "<td>" . $esc(Html::convDateTime((string) $printer['last_inventory'])) . "</td>"
                . "<td>" . $esc($printer['agent_name']) . "</td></tr>";
        }
        echo "</tbody></table></div></div>";
    }
}
