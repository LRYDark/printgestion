<?php
/**
 * PluginPrintgestionCollectsetup — configuration de collecte SNMP dans GLPI Inventory pour un
 * raccordement d'imprimantes (module Collecte SNMP / Déploiement Agent, phase 2).
 *
 * Objets créés en PHP avec les classes de GLPI Inventory, avec les valeurs exactes que laissent ses
 * écrans (relevées en base après une configuration faite à la main dans l'interface) :
 * - plage IP : name, entities_id, ip_start, ip_end ;
 * - identifiants SNMP liés à la plage : rank = rang le plus élevé + 1 ;
 * - modules de la sonde : identifiant de l'agent, en texte, dans « exceptions » de NETWORKDISCOVERY
 *   et NETWORKINVENTORY (une exception inverse l'activation globale pour cet agent) ;
 * - une tâche par méthode, GLPI Inventory ne gérant plus plusieurs jobs dans une tâche : tâche créée
 *   inactive, job avec targets [{"PluginGlpiinventoryIPRange":"<id>"}], actors [{"Agent":"<id>"}] et
 *   restrict_to_task_entity = 1, puis tâche activée (une tâche active ne se modifie plus).
 *
 * L'existant est vérifié avant toute écriture, dans l'entité et pour la sonde : deux clients, ou deux
 * sites d'un même client, ont souvent le même réseau privé, leurs plages ne se gênent pas. Rien n'est
 * créé en double, rien n'est créé si une vérification échoue, les écritures sont faites dans une
 * transaction. Le résultat d'une adresse ne tient compte que des équipements cités par les journaux
 * des tâches de la sonde depuis le déclenchement : jamais de l'équipement d'un autre client à la même
 * adresse.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionCollectsetup {

    /** Méthode de tâche GLPI Inventory => module d'agent correspondant. */
    const METHODS = [
        'networkdiscovery' => 'NETWORKDISCOVERY',
        'networkinventory' => 'NETWORKINVENTORY',
    ];

    const RANGE_TYPE = 'PluginGlpiinventoryIPRange';

    /** Équipements cités par les journaux de découverte et d'inventaire réseau. */
    const DEVICE_TYPES = ['Printer', 'NetworkEquipment', 'Unmanaged', 'Computer', 'Phone'];

    /** Plugin GLPI Inventory actif, avec les classes utilisées ici. */
    public static function isAvailable(): bool {
        if (!Plugin::isPluginActive('glpiinventory')) {
            return false;
        }
        foreach ([
            'PluginGlpiinventoryTask', 'PluginGlpiinventoryTaskjob', 'PluginGlpiinventoryTaskjobstate',
            'PluginGlpiinventoryTaskjoblog', 'PluginGlpiinventoryIPRange', 'PluginGlpiinventoryIPRange_SNMPCredential',
            'PluginGlpiinventoryAgentmodule',
        ] as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }
        return true;
    }

    public static function getMethodLabel(string $method): string {
        return $method === 'networkdiscovery'
            ? __('Découverte réseau', 'printgestion')
            : __('Inventaire réseau (niveaux)', 'printgestion');
    }

    /** Liste d'identifiants gardée en JSON par le raccordement. */
    public static function getIds(?string $json): array {
        $ids = importArrayFromDB((string) $json);
        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
    }

    // ── Identifiants SNMP ─────────────────────────────────────────────────────

    /** Identifiants SNMP utilisables : nom et version, jamais la communauté. */
    public static function getCredentials(): array {
        global $DB;

        $credentials = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'snmpversion'],
            'FROM'   => SNMPCredential::getTable(),
            'WHERE'  => ['is_deleted' => 0],
            'ORDER'  => ['id'],
        ]) as $row) {
            $credentials[(int) $row['id']] = $row;
        }
        return $credentials;
    }

    public static function getVersionLabel($version): string {
        return ['1' => 'v1', '2' => 'v2c', '3' => 'v3'][(string) $version] ?? (string) $version;
    }

    /** Identifiants choisis, ou à créer (v1 / v2c) sauf s'ils existent déjà à l'identique. */
    private static function planCredential(array $input, array &$errors, array &$notes): ?array {
        global $DB;

        if (($input['credential_mode'] ?? 'existing') !== 'new') {
            $id         = (int) ($input['snmpcredentials_id'] ?? 0);
            $credential = new SNMPCredential();
            if ($id <= 0 || !$credential->getFromDB($id) || (int) $credential->fields['is_deleted'] === 1) {
                $errors[] = __('Choisissez les identifiants SNMP des imprimantes.', 'printgestion');
                return null;
            }
            return ['action' => 'reuse', 'id' => $id, 'name' => (string) $credential->fields['name']];
        }

        $version   = (string) ($input['snmpversion'] ?? '');
        $community = (string) ($input['community'] ?? '');
        $name      = trim((string) ($input['credential_name'] ?? ''));
        if (!in_array($version, ['1', '2'], true)) {
            $errors[] = __('Version SNMP : v1 ou v2c. SNMP v3 : créez les identifiants dans GLPI (Configuration → Identifiants SNMP), puis choisissez-les ici.', 'printgestion');
            return null;
        }
        if (!preg_match('/^[\x21-\x7E]{1,255}$/', $community)) {
            $errors[] = __('Communauté SNMP : de 1 à 255 caractères imprimables, sans espace ni accent.', 'printgestion');
            return null;
        }
        // Communauté sensible à la casse : comparée en PHP, pas avec la collation de la base.
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'community'],
            'FROM'   => SNMPCredential::getTable(),
            'WHERE'  => ['is_deleted' => 0, 'snmpversion' => $version],
            'ORDER'  => ['id'],
        ]) as $row) {
            if ((string) $row['community'] === $community) {
                $notes[] = sprintf(__('Des identifiants SNMP identiques existent déjà : « %s » est réutilisé, rien n\'est créé.', 'printgestion'), $row['name']);
                return ['action' => 'reuse', 'id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        }
        if ($name === '' || mb_strlen($name) > 64) {
            $errors[] = __('Nom des nouveaux identifiants SNMP : de 1 à 64 caractères.', 'printgestion');
            return null;
        }
        if (countElementsInTable(SNMPCredential::getTable(), ['name' => $name, 'is_deleted' => 0]) > 0) {
            $errors[] = sprintf(__('Des identifiants SNMP s\'appellent déjà « %s » : choisissez-les dans la liste, ou donnez un autre nom.', 'printgestion'), $name);
            return null;
        }
        return ['action' => 'create', 'id' => 0, 'name' => $name, 'snmpversion' => $version, 'community' => $community];
    }

    // ── Existant dans GLPI Inventory ──────────────────────────────────────────

    /** Plages IP d'une entité, avec leurs bornes numériques. */
    private static function getRanges(int $entities_id): array {
        global $DB;

        $ranges = [];
        foreach ($DB->request([
            'FROM'  => PluginGlpiinventoryIPRange::getTable(),
            'WHERE' => ['entities_id' => $entities_id],
            'ORDER' => ['id'],
        ]) as $row) {
            $start = ip2long(trim((string) $row['ip_start']));
            $end   = ip2long(trim((string) $row['ip_end']));
            if ($start === false || $end === false) {
                // Bornes illisibles (GLPI Inventory ne les contrôle pas) : ne couvre aucune adresse.
                continue;
            }
            $row['start'] = min($start, $end);
            $row['end']   = max($start, $end);
            $ranges[(int) $row['id']] = $row;
        }
        return $ranges;
    }

    /** Jobs d'une méthode, avec leur tâche, et les plages, imprimantes, agents et postes qu'ils visent. */
    public static function getJobs(string $method): array {
        global $DB;

        $jobs = [];
        foreach ($DB->request([
            'SELECT'     => ['j.id', 'j.plugin_glpiinventory_tasks_id', 'j.targets', 'j.actors', 't.name AS task_name', 't.is_active'],
            'FROM'       => PluginGlpiinventoryTaskjob::getTable() . ' AS j',
            'INNER JOIN' => [
                PluginGlpiinventoryTask::getTable() . ' AS t' => ['ON' => ['j' => 'plugin_glpiinventory_tasks_id', 't' => 'id']],
            ],
            'WHERE'      => ['j.method' => $method],
            'ORDER'      => ['j.id'],
        ]) as $row) {
            $row['range_ids']    = self::extractIds((string) $row['targets'], self::RANGE_TYPE);
            $row['printer_ids']  = self::extractIds((string) $row['targets'], Printer::class);
            $row['agent_ids']    = self::extractIds((string) $row['actors'], Agent::class);
            $row['computer_ids'] = self::extractIds((string) $row['actors'], Computer::class);
            $jobs[] = $row;
        }
        return $jobs;
    }

    /** Identifiants d'un type dans une liste de GLPI Inventory : [{"Type":"id"}, …]. */
    private static function extractIds(string $json, string $itemtype): array {
        $list = importArrayFromDB($json);
        $ids  = [];
        if (is_array($list)) {
            foreach ($list as $entry) {
                if (is_array($entry) && isset($entry[$itemtype])) {
                    $ids[] = (int) $entry[$itemtype];
                }
            }
        }
        return $ids;
    }

    private static function getModule(string $modulename): ?array {
        global $DB;

        $row = $DB->request([
            'FROM'  => PluginGlpiinventoryAgentmodule::getTable(),
            'WHERE' => ['modulename' => $modulename],
            'LIMIT' => 1,
        ])->current();
        return is_array($row) ? $row : null;
    }

    /** Agents en exception d'un module, en texte comme les écrans de GLPI Inventory les enregistrent. */
    private static function getExceptions(array $module): array {
        $exceptions = importArrayFromDB((string) $module['exceptions']);
        return is_array($exceptions) ? array_values(array_map('strval', $exceptions)) : [];
    }

    /** Même lecture que GLPI Inventory : une exception inverse l'activation globale du module. */
    private static function isModuleActiveForAgent(array $module, int $agents_id): bool {
        $is_active = (int) $module['is_active'] === 1;
        return in_array((string) $agents_id, self::getExceptions($module), true) ? !$is_active : $is_active;
    }

    private static function getNextRank(int $ranges_id): int {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'rank AS max_rank'],
            'FROM'   => PluginGlpiinventoryIPRange_SNMPCredential::getTable(),
            'WHERE'  => ['plugin_glpiinventory_ipranges_id' => $ranges_id],
        ])->current();
        return (int) ($row['max_rank'] ?? 0) + 1;
    }

    private static function assertCreated(int $id, string $what): void {
        if ($id <= 0) {
            throw new DomainException(sprintf(__('création refusée par GLPI : %s', 'printgestion'), $what));
        }
    }

    // ── Étape 3 : configuration ───────────────────────────────────────────────

    /**
     * Étape 3 sans rien écrire : ce qui sera réutilisé ou créé, et ce qui l'empêche.
     *
     * @param array $input credential_mode (existing|new), snmpcredentials_id, credential_name,
     *                     snmpversion, community
     * @return array ['errors', 'notes', 'credential', 'ranges', 'modules' => [module => actif],
     *                'tasks' => [méthode => ['reuse' => [id => nom], 'create' => ?array]]]
     */
    public static function plan(PluginPrintgestionRaccordement $racc, array $input): array {
        $plan = ['errors' => [], 'notes' => [], 'credential' => null, 'ranges' => [], 'modules' => [], 'tasks' => []];
        if (!self::isAvailable()) {
            $plan['errors'][] = __('Plugin GLPI Inventory absent ou inactif : aucune tâche réseau ne peut être créée.', 'printgestion');
            return $plan;
        }
        $agent = new Agent();
        if (!$agent->getFromDB((int) $racc->fields['agents_id'])) {
            $plan['errors'][] = __('La sonde choisie à l\'étape 1 n\'existe plus dans GLPI.', 'printgestion');
            return $plan;
        }
        $entities_id = (int) $racc->fields['entities_id'];
        $agents_id   = (int) $agent->getID();
        $computer_id = ($agent->fields['itemtype'] ?? '') === Computer::class ? (int) $agent->fields['items_id'] : 0;
        $ips         = array_map(static fn(array $row): int => (int) $row['ip_num'], $racc->getIps());
        if (empty($ips)) {
            $plan['errors'][] = __('Aucune adresse déclarée à l\'étape 2.', 'printgestion');
            return $plan;
        }
        $entity_name = Dropdown::getDropdownName('glpi_entities', $entities_id);

        $plan['credential'] = self::planCredential($input, $plan['errors'], $plan['notes']);

        // Plages de l'entité : celles de cette sonde, ou d'aucune tâche, sont candidates ; celles d'une
        // autre sonde (autre site au même plan d'adresses) sont laissées de côté.
        $jobs = [];
        foreach (array_keys(self::METHODS) as $method) {
            $jobs[$method] = self::getJobs($method);
        }
        $is_mine = static fn(array $job): bool => in_array($agents_id, $job['agent_ids'], true)
            || ($computer_id > 0 && in_array($computer_id, $job['computer_ids'], true));
        $candidates = [];
        $others     = [];
        foreach (self::getRanges($entities_id) as $range_id => $range) {
            $mine  = false;
            $tasks = [];
            foreach ($jobs as $method_jobs) {
                foreach ($method_jobs as $job) {
                    if (in_array($range_id, $job['range_ids'], true)) {
                        $mine    = $mine || $is_mine($job);
                        $tasks[] = (string) $job['task_name'];
                    }
                }
            }
            if (!$mine && !empty($tasks)) {
                $range['tasks']    = array_values(array_unique($tasks));
                $others[$range_id] = $range;
            } else {
                $candidates[$range_id] = $range;
            }
        }

        $reused    = [];
        $uncovered = [];
        foreach ($ips as $ip) {
            $inside = false;
            foreach ($candidates as $range_id => $range) {
                if ($range['start'] <= $ip && $ip <= $range['end']) {
                    $reused[$range_id] = $range;
                    $inside            = true;
                }
            }
            if (!$inside) {
                $uncovered[] = $ip;
            }
        }
        foreach ($others as $range) {
            foreach ($ips as $ip) {
                if ($range['start'] <= $ip && $ip <= $range['end']) {
                    $plan['notes'][] = sprintf(
                        __('La plage « %1$s » de cette entité couvre aussi ces adresses, mais une autre sonde la collecte (tâche « %2$s ») : autre site au même plan d\'adresses ? Elle n\'est pas touchée, cette sonde aura sa propre plage.', 'printgestion'),
                        $range['name'],
                        implode(' », « ', $range['tasks'])
                    );
                    break;
                }
            }
        }
        foreach ($reused as $range_id => $range) {
            $plan['ranges'][] = [
                'action'   => 'reuse',
                'id'       => $range_id,
                'name'     => (string) $range['name'],
                'ip_start' => long2ip($range['start']),
                'ip_end'   => long2ip($range['end']),
            ];
        }
        if (!empty($uncovered)) {
            $start   = min($uncovered);
            $end     = max($uncovered);
            $overlap = false;
            foreach ($candidates as $range) {
                if (!($range['end'] < $start || $range['start'] > $end)) {
                    $overlap          = true;
                    $plan['errors'][] = sprintf(
                        __('La plage à créer (%1$s – %2$s) chevaucherait la plage « %3$s » (%4$s – %5$s) : déclarez les adresses de part et d\'autre dans des raccordements séparés, ou élargissez la plage existante dans GLPI Inventory.', 'printgestion'),
                        long2ip($start),
                        long2ip($end),
                        $range['name'],
                        long2ip($range['start']),
                        long2ip($range['end'])
                    );
                }
            }
            if (!$overlap) {
                $plan['ranges'][] = [
                    'action'   => 'create',
                    'id'       => 0,
                    'name'     => mb_substr(sprintf('Print Gestion · %s · %s – %s', $entity_name, long2ip($start), long2ip($end)), 0, 255),
                    'ip_start' => long2ip($start),
                    'ip_end'   => long2ip($end),
                ];
            }
        }

        $credential_id = (int) ($plan['credential']['id'] ?? 0);
        foreach ($plan['ranges'] as $index => $range) {
            $plan['ranges'][$index]['linked'] = $range['action'] === 'reuse' && $credential_id > 0
                && countElementsInTable(PluginGlpiinventoryIPRange_SNMPCredential::getTable(), [
                    'plugin_glpiinventory_ipranges_id' => $range['id'],
                    'snmpcredentials_id'               => $credential_id,
                ]) > 0;
        }

        foreach (self::METHODS as $modulename) {
            $module = self::getModule($modulename);
            if ($module === null) {
                $plan['errors'][] = sprintf(__('Module « %s » introuvable dans GLPI Inventory.', 'printgestion'), $modulename);
                continue;
            }
            $plan['modules'][$modulename] = self::isModuleActiveForAgent($module, $agents_id);
        }

        $has_new_range = !empty(array_filter($plan['ranges'], static fn(array $range): bool => $range['action'] === 'create'));
        foreach (array_keys(self::METHODS) as $method) {
            $task_plan = ['reuse' => [], 'create' => null];
            $without   = [];
            foreach ($reused as $range_id => $range) {
                $found = false;
                foreach ($jobs[$method] as $job) {
                    if (!in_array($range_id, $job['range_ids'], true) || !$is_mine($job)) {
                        continue;
                    }
                    $found = true;
                    if ((int) $job['is_active'] !== 1) {
                        $plan['errors'][] = sprintf(
                            __('La tâche « %1$s » collecte déjà la plage « %2$s » avec cette sonde, mais elle est désactivée : réactivez-la dans GLPI Inventory plutôt que d\'en créer une seconde.', 'printgestion'),
                            $job['task_name'],
                            $range['name']
                        );
                    } else {
                        $task_plan['reuse'][(int) $job['plugin_glpiinventory_tasks_id']] = (string) $job['task_name'];
                    }
                }
                if (!$found) {
                    $without[] = $range_id;
                }
            }
            if ($has_new_range || !empty($without)) {
                $task_plan['create'] = [
                    'name'      => mb_substr(sprintf('Print Gestion · %s · %s · %s', self::getMethodLabel($method), $entity_name, $agent->fields['name']), 0, 255),
                    'ranges'    => $without,
                    'new_range' => $has_new_range,
                ];
            }
            $plan['tasks'][$method] = $task_plan;
        }

        $plan['errors'] = array_values(array_unique($plan['errors']));
        $plan['notes']  = array_values(array_unique($plan['notes']));
        return $plan;
    }

    /**
     * Étape 3, écriture : applique un plan sans erreur, dans une transaction. Si une création échoue,
     * rien n'est gardé.
     *
     * @return array ['ok' => bool, 'events' => [[niveau, message]]]
     */
    public static function apply(PluginPrintgestionRaccordement $racc, array $plan): array {
        global $DB;

        if (!empty($plan['errors']) || $plan['credential'] === null) {
            return ['ok' => false, 'events' => [['error', __('Configuration non créée : vérifications en échec.', 'printgestion')]]];
        }
        $entities_id = (int) $racc->fields['entities_id'];
        $agents_id   = (int) $racc->fields['agents_id'];
        $comment     = sprintf(
            __('Créé par l\'assistant de raccordement Print Gestion n° %1$d (%2$s).', 'printgestion'),
            (int) $racc->getID(),
            getUserName((int) Session::getLoginUserID())
        );
        $events      = [];
        $created     = [];
        $range_ids   = [];
        $tasks       = array_fill_keys(array_keys(self::METHODS), []);

        $DB->beginTransaction();
        try {
            $credential_id = (int) $plan['credential']['id'];
            if ($plan['credential']['action'] === 'create') {
                $credential    = new SNMPCredential();
                $credential_id = (int) $credential->add([
                    'name'        => $plan['credential']['name'],
                    'snmpversion' => $plan['credential']['snmpversion'],
                    'community'   => $plan['credential']['community'],
                ]);
                self::assertCreated($credential_id, __('identifiants SNMP', 'printgestion'));
                $created[SNMPCredential::class][] = $credential_id;
                $events[] = ['success', sprintf(__('Identifiants SNMP « %1$s » créés (n° %2$d).', 'printgestion'), $plan['credential']['name'], $credential_id)];
            } else {
                $events[] = ['info', sprintf(__('Identifiants SNMP « %s » réutilisés.', 'printgestion'), $plan['credential']['name'])];
            }

            $new_range_id = 0;
            foreach ($plan['ranges'] as $range) {
                $range_id = (int) $range['id'];
                if ($range['action'] === 'create') {
                    $iprange  = new PluginGlpiinventoryIPRange();
                    $range_id = (int) $iprange->add([
                        'name'        => $range['name'],
                        'entities_id' => $entities_id,
                        'ip_start'    => $range['ip_start'],
                        'ip_end'      => $range['ip_end'],
                    ]);
                    self::assertCreated($range_id, __('plage IP', 'printgestion'));
                    $new_range_id                = $range_id;
                    $created[self::RANGE_TYPE][] = $range_id;
                    $events[] = ['success', sprintf(__('Plage IP « %1$s » créée (%2$s – %3$s, n° %4$d).', 'printgestion'), $range['name'], $range['ip_start'], $range['ip_end'], $range_id)];
                } else {
                    $events[] = ['info', sprintf(__('Plage IP « %1$s » réutilisée (%2$s – %3$s, n° %4$d).', 'printgestion'), $range['name'], $range['ip_start'], $range['ip_end'], $range_id)];
                }
                $range_ids[] = $range_id;

                $link_criteria = ['plugin_glpiinventory_ipranges_id' => $range_id, 'snmpcredentials_id' => $credential_id];
                if (countElementsInTable(PluginGlpiinventoryIPRange_SNMPCredential::getTable(), $link_criteria) === 0) {
                    $rank    = self::getNextRank($range_id);
                    $link    = new PluginGlpiinventoryIPRange_SNMPCredential();
                    $link_id = (int) $link->add($link_criteria + ['rank' => $rank]);
                    self::assertCreated($link_id, __('liaison entre la plage et les identifiants SNMP', 'printgestion'));
                    $created[PluginGlpiinventoryIPRange_SNMPCredential::class][] = $link_id;
                    $events[] = ['success', sprintf(__('Identifiants SNMP liés à la plage n° %1$d (rang %2$d).', 'printgestion'), $range_id, $rank)];
                }
            }

            foreach (self::METHODS as $modulename) {
                $module = self::getModule($modulename);
                if ($module === null) {
                    throw new DomainException(sprintf(__('module « %s » introuvable dans GLPI Inventory', 'printgestion'), $modulename));
                }
                if (self::isModuleActiveForAgent($module, $agents_id)) {
                    continue;
                }
                $exceptions  = self::getExceptions($module);
                $exceptions  = (int) $module['is_active'] === 1
                    ? array_values(array_diff($exceptions, [(string) $agents_id]))
                    : array_merge($exceptions, [(string) $agents_id]);
                $agentmodule = new PluginGlpiinventoryAgentmodule();
                if (!$agentmodule->update(['id' => (int) $module['id'], 'exceptions' => exportArrayToDB($exceptions)])) {
                    throw new DomainException(sprintf(__('module « %s » non activé pour la sonde', 'printgestion'), $modulename));
                }
                $events[] = ['success', sprintf(__('Module %s activé pour la sonde.', 'printgestion'), $modulename)];
            }

            foreach ($plan['tasks'] as $method => $task_plan) {
                foreach ($task_plan['reuse'] as $tasks_id => $task_name) {
                    $tasks[$method][] = (int) $tasks_id;
                    $events[] = ['info', sprintf(__('Tâche « %s » réutilisée : elle collecte déjà cette plage avec cette sonde.', 'printgestion'), $task_name)];
                }
                if ($task_plan['create'] === null) {
                    continue;
                }
                $targets = $task_plan['create']['ranges'];
                if ($task_plan['create']['new_range']) {
                    $targets[] = $new_range_id;
                }

                $task     = new PluginGlpiinventoryTask();
                $tasks_id = (int) $task->add([
                    'name'                    => $task_plan['create']['name'],
                    'entities_id'             => $entities_id,
                    'comment'                 => $comment,
                    'is_active'               => 0,
                    'reprepare_if_successful' => 1,
                ]);
                self::assertCreated($tasks_id, __('tâche', 'printgestion'));
                $created[PluginGlpiinventoryTask::class][] = $tasks_id;

                $job     = new PluginGlpiinventoryTaskjob();
                $jobs_id = (int) $job->add([
                    'plugin_glpiinventory_tasks_id' => $tasks_id,
                    'entities_id'                   => $entities_id,
                    'name'                          => self::getMethodLabel($method),
                    'method'                        => $method,
                    'targets'                       => exportArrayToDB(array_map(
                        static fn(int $id): array => [self::RANGE_TYPE => (string) $id],
                        $targets
                    )),
                    'actors'                        => exportArrayToDB([[Agent::class => (string) $agents_id]]),
                    'comment'                       => '',
                    'restrict_to_task_entity'       => 1,
                ]);
                self::assertCreated($jobs_id, __('job de la tâche', 'printgestion'));
                $created[PluginGlpiinventoryTaskjob::class][] = $jobs_id;

                if (!$task->update(['id' => $tasks_id, 'is_active' => 1])) {
                    throw new DomainException(__('la tâche créée n\'a pas pu être activée', 'printgestion'));
                }
                $tasks[$method][] = $tasks_id;
                $events[] = ['success', sprintf(
                    __('Tâche « %1$s » créée et activée (n° %2$d) : plage(s) n° %3$s, sonde n° %4$d.', 'printgestion'),
                    $task_plan['create']['name'],
                    $tasks_id,
                    implode(', ', $targets),
                    $agents_id
                )];
            }

            if (!$racc->update([
                'id'                 => (int) $racc->getID(),
                'status'             => PluginPrintgestionRaccordement::STATUS_CONFIGURED,
                'snmpcredentials_id' => $credential_id,
                'ipranges'           => exportArrayToDB(array_values(array_unique($range_ids))),
                'discovery_tasks'    => exportArrayToDB(array_values(array_unique($tasks['networkdiscovery']))),
                'inventory_tasks'    => exportArrayToDB(array_values(array_unique($tasks['networkinventory']))),
                'created_items'      => exportArrayToDB($created),
                'date_configured'    => Session::getCurrentTime(),
            ])) {
                throw new DomainException(__('raccordement non mis à jour', 'printgestion'));
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            if ($e instanceof DomainException) {
                // Refus décrit par le plugin lui-même : message sans donnée sensible.
                $reason = $e->getMessage();
            } else {
                // Erreur de la base ou de GLPI : la requête peut contenir la communauté SNMP. Elle va dans le
                // journal PHP de GLPI, jamais à l'écran ni dans le journal du raccordement.
                \Glpi\Error\ErrorHandler::logCaughtException($e);
                $reason = __('erreur technique, détail dans le journal PHP de GLPI (php-errors.log)', 'printgestion');
            }
            return ['ok' => false, 'events' => [['error', sprintf(__('Configuration annulée, rien n\'a été gardé : %s.', 'printgestion'), $reason)]]];
        }
        return ['ok' => true, 'events' => $events];
    }

    // ── Étape 4 : déclenchement et vérification ───────────────────────────────

    public static function getTaskIds(PluginPrintgestionRaccordement $racc, string $method): array {
        return self::getIds((string) ($racc->fields[$method === 'networkdiscovery' ? 'discovery_tasks' : 'inventory_tasks'] ?? ''));
    }

    private static function getJobIds(PluginPrintgestionRaccordement $racc, string $method): array {
        global $DB;

        $tasks = self::getTaskIds($racc, $method);
        if (empty($tasks)) {
            return [];
        }
        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => PluginGlpiinventoryTaskjob::getTable(),
            'WHERE'  => ['plugin_glpiinventory_tasks_id' => $tasks, 'method' => $method],
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    /** Jobs préparés, envoyés ou en cours de retour pour la sonde. */
    private static function countPendingJobstates(array $jobs_ids, int $agents_id): int {
        if (empty($jobs_ids)) {
            return 0;
        }
        return countElementsInTable(PluginGlpiinventoryTaskjobstate::getTable(), [
            'plugin_glpiinventory_taskjobs_id' => $jobs_ids,
            'agents_id'                        => $agents_id,
            'state'                            => [
                PluginGlpiinventoryTaskjobstate::PREPARED,
                PluginGlpiinventoryTaskjobstate::SERVER_HAS_SENT_DATA,
                PluginGlpiinventoryTaskjobstate::AGENT_HAS_SENT_DATA,
            ],
        ]);
    }

    /** « Demander le statut » natif : GET /status sur le port de l'agent, comme la fiche agent. */
    public static function requestStatus(Agent $agent): array {
        $name   = (string) $agent->fields['name'];
        $answer = trim((string) ($agent->requestStatus()['answer'] ?? ''));
        if ($answer === __('Not allowed')) {
            return ['warning', sprintf(__('Statut de « %s » : la sonde refuse les demandes de ce serveur (HTTPD_TRUST). Son dernier contact reste la preuve qu\'elle fonctionne.', 'printgestion'), $name)];
        }
        if ($answer === '' || $answer === __('Unknown')) {
            return ['warning', sprintf(__('Statut de « %s » : pas de réponse. GLPI ne joint pas la sonde (normal derrière la box du client) : son dernier contact reste la preuve qu\'elle fonctionne.', 'printgestion'), $name)];
        }
        return ['success', sprintf(__('Statut de « %1$s » : %2$s.', 'printgestion'), $name, $answer)];
    }

    /** Réveil natif (GET /now sur le port de l'agent, comme « Demander un inventaire » de la fiche agent). */
    private static function wakeUp(Agent $agent, string $what): array {
        $port   = (int) ($agent->fields['port'] ?? 0) ?: Agent::DEFAULT_PORT;
        $answer = trim((string) ($agent->requestInventory()['answer'] ?? ''));
        if ($answer === __('Not allowed')) {
            return ['warning', sprintf(__('La sonde refuse l\'ordre de ce serveur (HTTPD_TRUST) : sur le PC sonde, ouvrez http://127.0.0.1:%1$d et cliquez « Force an Inventory » pour lancer %2$s.', 'printgestion'), $port, $what)];
        }
        if ($answer === '' || $answer === __('Unknown')) {
            return ['warning', sprintf(__('GLPI ne joint pas la sonde pour lancer %1$s (normal derrière la box du client). Sur le PC sonde, ouvrez http://127.0.0.1:%2$d et cliquez « Force an Inventory » ; sinon elle partira au prochain contact de l\'agent.', 'printgestion'), $what, $port)];
        }
        return ['success', sprintf(__('Sonde réveillée par GLPI : elle lance %s maintenant.', 'printgestion'), $what)];
    }

    /**
     * Prépare la découverte (démarrage forcé natif des tâches de découverte) et tente de réveiller la
     * sonde. Les résultats précédents repartent de zéro : ils ne valent que depuis ce déclenchement.
     *
     * @return array ['ok' => bool, 'events' => [[niveau, message]]]
     */
    public static function trigger(PluginPrintgestionRaccordement $racc): array {
        global $DB;

        if (!self::isAvailable()) {
            return ['ok' => false, 'events' => [['error', __('Plugin GLPI Inventory absent ou inactif.', 'printgestion')]]];
        }
        $agent = new Agent();
        if (!$agent->getFromDB((int) $racc->fields['agents_id'])) {
            return ['ok' => false, 'events' => [['error', __('La sonde du raccordement n\'existe plus dans GLPI.', 'printgestion')]]];
        }
        $task_ids = self::getTaskIds($racc, 'networkdiscovery');
        if (empty($task_ids)) {
            return ['ok' => false, 'events' => [['error', __('Aucune tâche de découverte enregistrée pour ce raccordement.', 'printgestion')]]];
        }
        $tasks = [];
        foreach ($task_ids as $tasks_id) {
            $task = new PluginGlpiinventoryTask();
            if (!$task->getFromDB($tasks_id)) {
                return ['ok' => false, 'events' => [['error', sprintf(__('La tâche de découverte n° %d a été supprimée dans GLPI Inventory.', 'printgestion'), $tasks_id)]]];
            }
            if ((int) $task->fields['is_active'] !== 1) {
                return ['ok' => false, 'events' => [['error', sprintf(__('La tâche « %s » a été désactivée dans GLPI Inventory : réactivez-la pour lancer la découverte.', 'printgestion'), $task->fields['name'])]]];
            }
            $tasks[] = $task;
        }

        $since = (string) Session::getCurrentTime();
        foreach ($tasks as $task) {
            $task->forceRunning();
        }
        $pending = self::countPendingJobstates(self::getJobIds($racc, 'networkdiscovery'), (int) $agent->getID());
        if ($pending === 0) {
            return ['ok' => false, 'events' => [['error', __('GLPI Inventory n\'a préparé aucune découverte pour cette sonde : vérifiez les dates et plages horaires de la tâche de découverte.', 'printgestion')]]];
        }

        $DB->update(PluginPrintgestionRaccordement::IPS_TABLE, [
            'result'            => 'waiting_discovery',
            'itemtype'          => null,
            'items_id'          => 0,
            'items_entities_id' => null,
            'date_check'        => null,
        ], ['plugin_printgestion_raccordements_id' => (int) $racc->getID()]);
        $racc->update([
            'id'                      => (int) $racc->getID(),
            'status'                  => PluginPrintgestionRaccordement::STATUS_TRIGGERED,
            'date_triggered'          => $since,
            'date_inventory_prepared' => 'NULL',
            'date_verified'           => 'NULL',
        ]);

        return ['ok' => true, 'events' => [
            ['success', sprintf(_n('Découverte préparée : %d job en attente de la sonde.', 'Découverte préparée : %d jobs en attente de la sonde.', $pending, 'printgestion'), $pending)],
            self::wakeUp($agent, __('la découverte', 'printgestion')),
        ]];
    }

    /**
     * Jobs d'une sonde depuis le déclenchement : terminés (journal OK ou en erreur), équipements cités
     * ([[Printer::8]]), jobs encore en attente.
     *
     * @return array ['done' => ["Type::id" => bool], 'messages' => ["Type::id" => texte],
     *                'errors' => ["Type::id" => texte], 'seen' => ["Type::id" => true], 'pending' => int]
     */
    private static function getRuns(array $jobs_ids, int $agents_id, string $since): array {
        global $DB;

        $runs = ['done' => [], 'messages' => [], 'errors' => [], 'seen' => [], 'pending' => 0];
        if (empty($jobs_ids) || $since === '') {
            return $runs;
        }
        $jobstates = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'itemtype', 'items_id', 'state'],
            'FROM'   => PluginGlpiinventoryTaskjobstate::getTable(),
            'WHERE'  => ['plugin_glpiinventory_taskjobs_id' => $jobs_ids, 'agents_id' => $agents_id],
        ]) as $row) {
            $jobstates[(int) $row['id']] = $row;
            if (in_array((int) $row['state'], [
                PluginGlpiinventoryTaskjobstate::PREPARED,
                PluginGlpiinventoryTaskjobstate::SERVER_HAS_SENT_DATA,
                PluginGlpiinventoryTaskjobstate::AGENT_HAS_SENT_DATA,
            ], true)) {
                $runs['pending']++;
            }
        }
        if (empty($jobstates)) {
            return $runs;
        }
        foreach ($DB->request([
            'SELECT' => ['plugin_glpiinventory_taskjobstates_id', 'state', 'comment'],
            'FROM'   => PluginGlpiinventoryTaskjoblog::getTable(),
            'WHERE'  => [
                'plugin_glpiinventory_taskjobstates_id' => array_keys($jobstates),
                'date'                                  => ['>=', $since],
            ],
            'ORDER'  => ['id'],
        ]) as $log) {
            $jobstate = $jobstates[(int) $log['plugin_glpiinventory_taskjobstates_id']];
            $comment  = (string) $log['comment'];
            if (preg_match_all('/\[\[([A-Za-z]+)::(\d+)\]\]/', $comment, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $runs['seen'][$match[1] . '::' . (int) $match[2]] = true;
                }
            }
            $log_state = (int) $log['state'];
            if ($log_state === PluginGlpiinventoryTaskjoblog::TASK_OK || $log_state === PluginGlpiinventoryTaskjoblog::TASK_ERROR) {
                $key                    = $jobstate['itemtype'] . '::' . (int) $jobstate['items_id'];
                $runs['done'][$key]     = $log_state === PluginGlpiinventoryTaskjoblog::TASK_OK;
                $runs['messages'][$key] = $comment;
                if ($log_state === PluginGlpiinventoryTaskjoblog::TASK_ERROR) {
                    $runs['errors'][$key] = $comment;
                } else {
                    unset($runs['errors'][$key]);
                }
            }
        }
        return $runs;
    }

    /**
     * Avancement depuis le déclenchement : découverte terminée pour toutes les plages du raccordement,
     * en erreur, et relevé des niveaux.
     */
    public static function getProgress(PluginPrintgestionRaccordement $racc): array {
        $agents_id = (int) $racc->fields['agents_id'];
        $since     = (string) ($racc->fields['date_triggered'] ?? '');
        $discovery = self::getRuns(self::getJobIds($racc, 'networkdiscovery'), $agents_id, $since);
        $inventory = self::getRuns(self::getJobIds($racc, 'networkinventory'), $agents_id, $since);
        $range_ids = self::getIds((string) $racc->fields['ipranges']);

        $finished = !empty($range_ids);
        $failed   = false;
        $messages = [];
        foreach ($range_ids as $range_id) {
            $key = self::RANGE_TYPE . '::' . $range_id;
            if (!isset($discovery['done'][$key])) {
                $finished = false;
                continue;
            }
            $failed     = $failed || !$discovery['done'][$key];
            $messages[] = $discovery['messages'][$key];
        }
        return [
            'discovery'          => $discovery,
            'inventory'          => $inventory,
            'discovery_finished' => $finished,
            'discovery_failed'   => $finished && $failed,
            'discovery_message'  => implode(' ; ', array_filter($messages, 'strlen')),
        ];
    }

    /**
     * Vérifie chaque adresse déclarée. Découverte terminée : relevé des niveaux préparé une seule fois
     * (démarrage forcé natif des tâches d'inventaire) et sonde réveillée.
     *
     * @return array ['events' => [[niveau, message]]]
     */
    public static function verify(PluginPrintgestionRaccordement $racc): array {
        global $DB;

        if (!self::isAvailable()) {
            return ['events' => [['error', __('Plugin GLPI Inventory absent ou inactif.', 'printgestion')]]];
        }
        $events      = [];
        $entities_id = (int) $racc->fields['entities_id'];
        $agents_id   = (int) $racc->fields['agents_id'];
        $progress    = self::getProgress($racc);

        if ($progress['discovery_finished'] && !$progress['discovery_failed'] && empty($racc->fields['date_inventory_prepared'])) {
            foreach (self::getTaskIds($racc, 'networkinventory') as $tasks_id) {
                $task = new PluginGlpiinventoryTask();
                if ($task->getFromDB($tasks_id) && (int) $task->fields['is_active'] === 1) {
                    $task->forceRunning();
                }
            }
            $racc->update(['id' => (int) $racc->getID(), 'date_inventory_prepared' => Session::getCurrentTime()]);
            $pending = self::countPendingJobstates(self::getJobIds($racc, 'networkinventory'), $agents_id);
            if ($pending > 0) {
                $events[] = ['info', sprintf(_n('Découverte terminée : relevé des niveaux préparé pour %d équipement.', 'Découverte terminée : relevé des niveaux préparé pour %d équipements.', $pending, 'printgestion'), $pending)];
                $agent    = new Agent();
                if ($agent->getFromDB($agents_id)) {
                    $events[] = self::wakeUp($agent, __('le relevé des niveaux', 'printgestion'));
                }
            } else {
                $events[] = ['warning', __('Découverte terminée, mais aucun équipement à relever : rien n\'a répondu en SNMP avec ces identifiants dans l\'entité.', 'printgestion')];
            }
            $progress = self::getProgress($racc);
        }

        $keys  = array_keys($progress['discovery']['seen'] + $progress['inventory']['seen'] + $progress['inventory']['done']);
        $by_ip = self::getItemsByIp($keys);
        $now   = Session::getCurrentTime();
        foreach ($racc->getIps() as $row) {
            $result = self::classify(
                $entities_id,
                $by_ip[(string) $row['ip']] ?? [],
                $progress['discovery_finished'] && !$progress['discovery_failed'],
                $progress['inventory']['done']
            );
            $DB->update(PluginPrintgestionRaccordement::IPS_TABLE, $result + ['date_check' => $now], ['id' => (int) $row['id']]);
        }
        $racc->update(['id' => (int) $racc->getID(), 'date_verified' => $now]);
        return ['events' => $events];
    }

    /** Équipements cités par les journaux, par adresse IPv4. */
    private static function getItemsByIp(array $keys): array {
        global $DB;

        $ids = [];
        foreach ($keys as $key) {
            [$itemtype, $id] = array_pad(explode('::', (string) $key, 2), 2, '0');
            if (in_array($itemtype, self::DEVICE_TYPES, true) && (int) $id > 0) {
                $ids[$itemtype][(int) $id] = (int) $id;
            }
        }
        $by_ip = [];
        foreach ($ids as $itemtype => $item_ids) {
            $table = $itemtype::getTable();
            $where = [
                'ip.mainitemtype' => $itemtype,
                'ip.mainitems_id' => array_values($item_ids),
                'ip.is_deleted'   => 0,
                'ip.version'      => 4,
            ];
            if ($DB->fieldExists($table, 'is_deleted')) {
                $where['item.is_deleted'] = 0;
            }
            foreach ($DB->request([
                'SELECT'     => ['ip.name AS ip', 'item.id', 'item.name', 'item.entities_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_ipaddresses AS ip',
                'INNER JOIN' => [$table . ' AS item' => ['ON' => ['ip' => 'mainitems_id', 'item' => 'id']]],
                'WHERE'      => $where,
            ]) as $row) {
                $by_ip[(string) $row['ip']][] = [
                    'itemtype'    => $itemtype,
                    'id'          => (int) $row['id'],
                    'name'        => (string) $row['name'],
                    'entities_id' => (int) $row['entities_id'],
                ];
            }
        }
        return $by_ip;
    }

    /**
     * Résultat d'une adresse à partir des équipements que la sonde y a trouvés depuis le déclenchement.
     *
     * @return array colonnes result, itemtype, items_id, items_entities_id de la table des adresses
     */
    private static function classify(int $entities_id, array $items, bool $discovery_finished, array $inventory_done): array {
        $printer   = null;
        $other     = null;
        $unmanaged = null;
        foreach ($items as $item) {
            if ($item['itemtype'] === Printer::class) {
                $printer ??= $item;
            } elseif ($item['itemtype'] === Unmanaged::class) {
                $unmanaged ??= $item;
            } else {
                $other ??= $item;
            }
        }
        $item   = $printer ?? $other ?? $unmanaged;
        $result = [
            'result'            => 'waiting_discovery',
            'itemtype'          => $item['itemtype'] ?? null,
            'items_id'          => $item['id'] ?? 0,
            'items_entities_id' => $item['entities_id'] ?? null,
        ];

        if ($printer !== null) {
            if ($printer['entities_id'] !== $entities_id) {
                $result['result'] = 'wrong_entity';
            } elseif (isset($inventory_done[Printer::class . '::' . $printer['id']])) {
                $result['result'] = countElementsInTable('glpi_printers_cartridgeinfos', ['printers_id' => $printer['id']]) > 0 ? 'found' : 'no_levels';
            } else {
                $result['result'] = 'waiting_inventory';
            }
            return $result;
        }
        if ($discovery_finished) {
            $result['result'] = $other !== null ? 'not_printer' : 'no_snmp';
        }
        return $result;
    }
}
