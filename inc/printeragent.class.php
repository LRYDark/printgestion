<?php
/**
 * PluginPrintgestionPrinteragent — sonde responsable d'une imprimante inventoriée en SNMP (module Collecte
 * SNMP / Déploiement Agent, phase 4).
 *
 * La carte native « Informations d'inventaire » d'une imprimante n'indique aucun agent : GLPI ne garde le
 * lien agent ↔ actif que pour les ordinateurs. Ce bloc retrouve la sonde par la plage IP et la tâche de GLPI
 * Inventory qui couvrent l'imprimante (à défaut : la sonde de son dernier inventaire réseau), et n'affiche
 * que ce qui manque : nom de l'agent (lien vers sa fiche native), version, dernier contact, et date du
 * dernier inventaire réseau réussi de l'imprimante.
 *
 * Emplacement : dans la carte native quand l'utilisateur la voit (droit Inventaire en lecture, imprimante
 * dynamique), sinon sous le formulaire de l'imprimante. Droit : Déploiement en lecture.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionPrinteragent {

    /** Module actif, droit Déploiement en lecture, imprimante enregistrée et visible. */
    private static function canShow(CommonGLPI $item): bool {
        return $item instanceof Printer
            && (int) $item->getID() > 0
            && PluginPrintgestionConfig::isFeatureEnabled('deploiement')
            && Session::haveRight('plugin_printgestion_deploiement', READ)
            && $item->canViewItem();
    }

    /** La carte native « Informations d'inventaire » est-elle affichée pour cet utilisateur ? */
    private static function nativeCardShown(Printer $printer): bool {
        return Session::haveRight('inventory', READ) && $printer->isDynamic();
    }

    /** Hook AUTOINVENTORY_INFORMATION : bloc dans la carte native. */
    public static function showInInventoryCard($item): void {
        if (self::canShow($item) && self::nativeCardShown($item)) {
            self::render($item, true);
        }
    }

    /** Hook POST_ITEM_FORM : bloc sous le formulaire, quand la carte native n'est pas affichée. */
    public static function showAfterForm(array $params): void {
        $item = $params['item'] ?? null;
        if ($item instanceof CommonGLPI && self::canShow($item) && !self::nativeCardShown($item)) {
            self::render($item, false);
        }
    }

    /**
     * Sondes qui collectent l'imprimante : acteurs des jobs GLPI Inventory (inventaire, puis découverte)
     * qui visent une plage contenant une de ses adresses IPv4 dans son entité ou une entité parente, ou
     * l'imprimante elle-même.
     *
     * @return array agents_id => ['agent' => ligne glpi_agents, 'sources' => [texte]]
     */
    public static function getProbes(Printer $printer): array {
        global $DB;

        if (!PluginPrintgestionCollectsetup::isAvailable()) {
            return [];
        }
        $printers_id = (int) $printer->getID();
        $ips         = [];
        foreach ($DB->request([
            'SELECT'   => ['name'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_ipaddresses',
            'WHERE'    => ['mainitemtype' => Printer::class, 'mainitems_id' => $printers_id, 'version' => 4, 'is_deleted' => 0],
        ]) as $row) {
            $long = ip2long(trim((string) $row['name']));
            if ($long !== false) {
                $ips[] = $long;
            }
        }

        $entities = [(int) $printer->fields['entities_id']];
        foreach (getAncestorsOf(Entity::getTable(), (int) $printer->fields['entities_id']) as $ancestor) {
            $entities[] = (int) $ancestor;
        }
        $ranges = [];
        if (!empty($ips)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'ip_start', 'ip_end'],
                'FROM'   => PluginGlpiinventoryIPRange::getTable(),
                'WHERE'  => ['entities_id' => $entities],
            ]) as $range) {
                $start = ip2long(trim((string) $range['ip_start']));
                $end   = ip2long(trim((string) $range['ip_end']));
                if ($start === false || $end === false) {
                    continue;
                }
                foreach ($ips as $ip) {
                    if (min($start, $end) <= $ip && $ip <= max($start, $end)) {
                        $ranges[(int) $range['id']] = (string) $range['name'];
                        break;
                    }
                }
            }
        }

        $sources = [];
        foreach (array_keys(PluginPrintgestionCollectsetup::METHODS) as $method) {
            foreach (PluginPrintgestionCollectsetup::getJobs($method) as $job) {
                $via = [];
                foreach ($job['range_ids'] as $range_id) {
                    if (isset($ranges[$range_id])) {
                        $via[] = sprintf(__('plage « %s »', 'printgestion'), $ranges[$range_id]);
                    }
                }
                if (in_array($printers_id, $job['printer_ids'], true)) {
                    $via[] = __('imprimante ciblée directement', 'printgestion');
                }
                if (empty($via)) {
                    continue;
                }
                $agent_ids = $job['agent_ids'];
                if (!empty($job['computer_ids'])) {
                    foreach ($DB->request([
                        'SELECT' => ['id'],
                        'FROM'   => Agent::getTable(),
                        'WHERE'  => ['itemtype' => Computer::class, 'items_id' => $job['computer_ids']],
                    ]) as $agent) {
                        $agent_ids[] = (int) $agent['id'];
                    }
                }
                foreach (array_unique($agent_ids) as $agents_id) {
                    $sources[$agents_id][] = sprintf(
                        __('%1$s : tâche « %2$s »%3$s, %4$s', 'printgestion'),
                        PluginPrintgestionCollectsetup::getMethodLabel($method),
                        $job['task_name'],
                        (int) $job['is_active'] === 1 ? '' : ' ' . __('(désactivée)', 'printgestion'),
                        implode(', ', $via)
                    );
                }
            }
        }
        return self::loadAgents($sources);
    }

    /** Lignes glpi_agents des sondes trouvées, avec leurs sources. */
    private static function loadAgents(array $sources): array {
        global $DB;

        if (empty($sources)) {
            return [];
        }
        $probes = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'version', 'last_contact'],
            'FROM'   => Agent::getTable(),
            'WHERE'  => ['id' => array_keys($sources)],
            'ORDER'  => ['last_contact DESC'],
        ]) as $agent) {
            $probes[(int) $agent['id']] = ['agent' => $agent, 'sources' => array_values(array_unique($sources[(int) $agent['id']]))];
        }
        return $probes;
    }

    private static function render(Printer $printer, bool $in_card): void {
        $esc         = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $printers_id = (int) $printer->getID();
        $dates       = PluginPrintgestionCollect::getImportDates([$printers_id])[$printers_id] ?? ['snmp' => null, 'discovery' => null, 'agents_id' => 0];
        $probes      = self::getProbes($printer);
        if (empty($probes) && (int) $dates['agents_id'] > 0) {
            // Journaux : sonde du dernier inventaire réseau, à défaut de la dernière découverte.
            $source = !empty($dates['snmp'])
                ? __('sonde de son dernier inventaire réseau (aucune tâche GLPI Inventory ne la couvre aujourd\'hui)', 'printgestion')
                : __('sonde de sa dernière découverte (aucune tâche GLPI Inventory ne la couvre aujourd\'hui)', 'printgestion');
            $probes = self::loadAgents([(int) $dates['agents_id'] => [$source]]);
        }
        $silent_days = PluginPrintgestionCollect::getSilentDays();
        $cutoff      = date('Y-m-d H:i:s', time() - $silent_days * DAY_TIMESTAMP);
        $date        = static fn($value): string => empty($value) ? '—' : Html::convDateTime((string) $value);

        if ($in_card) {
            echo "<div class='card-body row border-top'>";
        } else {
            echo "<div class='card mt-3'><div class='card-header'><h4 class='card-title mb-0'><i class='ti ti-antenna me-1'></i>"
                . $esc(__('Sonde responsable (Print Gestion)', 'printgestion')) . "</h4></div><div class='card-body row'>";
        }
        if ($in_card) {
            echo "<div class='col-12 mb-2 fw-bold'><i class='ti ti-antenna me-1'></i>" . $esc(__('Sonde responsable (Print Gestion)', 'printgestion')) . "</div>";
        }

        // Dernier inventaire réseau réussi : de l'imprimante, une seule fois.
        $inventory = $esc($date($dates['snmp']));
        if (empty($dates['snmp'])) {
            $inventory = $esc(__('aucun connu', 'printgestion'));
        } elseif ((string) $dates['snmp'] < $cutoff) {
            $inventory .= " <span class='badge bg-red text-red-fg'>" . $esc(sprintf(__('plus de %d jours', 'printgestion'), $silent_days)) . "</span>";
        }
        if (!empty($dates['discovery']) && (empty($dates['snmp']) || (string) $dates['discovery'] > (string) $dates['snmp'])) {
            $inventory .= "<div class='text-muted small'>" . $esc(sprintf(__('Découverte seule le %s : elle fait avancer la date d\'inventaire de la carte sans relire les niveaux.', 'printgestion'), $date($dates['discovery']))) . "</div>";
        }

        if (empty($probes)) {
            echo "<div class='mb-3 col-12 col-sm-8'><span class='text-muted'>"
                . $esc(__('Aucune sonde trouvée : aucune plage IP de GLPI Inventory ne couvre l\'adresse de cette imprimante, ou GLPI Inventory est absent.', 'printgestion'))
                . "</span></div>";
        }
        foreach ($probes as $probe) {
            $agent     = $probe['agent'];
            $name      = Agent::canView()
                ? "<a href='" . $esc(Agent::getFormURLWithID((int) $agent['id'])) . "'>" . $esc($agent['name']) . "</a>"
                : $esc($agent['name']);
            $modules   = importArrayFromDB((string) $agent['version']);
            $version   = is_array($modules) && !empty($modules) ? (string) reset($modules) : trim((string) $agent['version']);
            $is_silent = empty($agent['last_contact']) || (string) $agent['last_contact'] < $cutoff;
            echo "<div class='mb-3 col-12 col-sm-3'><label class='form-label'>" . $esc(__('Agent', 'printgestion')) . "</label><span>{$name}</span>"
                . "<div class='text-muted small'>" . $esc(implode(' ; ', $probe['sources'])) . "</div></div>";
            echo "<div class='mb-3 col-12 col-sm-3'><label class='form-label'>" . $esc(__('Version', 'printgestion')) . "</label><span>" . $esc($version !== '' ? $version : '—') . "</span></div>";
            echo "<div class='mb-3 col-12 col-sm-3'><label class='form-label'>" . $esc(__('Dernier contact', 'printgestion')) . "</label><span>" . $esc($date($agent['last_contact']))
                . ($is_silent ? " <span class='badge bg-red text-red-fg'>" . $esc(__('Muette', 'printgestion')) . "</span>" : '') . "</span></div>";
            echo "<div class='mb-3 col-12 col-sm-3'><label class='form-label'>" . $esc(__('Dernier inventaire réseau réussi', 'printgestion')) . "</label><span>{$inventory}</span></div>";
        }
        if (empty($probes)) {
            echo "<div class='mb-3 col-12 col-sm-4'><label class='form-label'>" . $esc(__('Dernier inventaire réseau réussi', 'printgestion')) . "</label><span>{$inventory}</span></div>";
        }
        echo $in_card ? "</div>" : "</div></div>";
    }
}
