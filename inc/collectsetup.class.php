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

    /**
     * Versions de GLPI Inventory : 1.6.0 minimum (« GLPI v11 compatibility »), bloquant en dessous ; validée avec
     * 1.6.10 ; au-delà, et à partir de la série 1.7.0 (GLPIINVENTORY_MAX_VERSION), simple avertissement
     * (checkVersion()).
     */
    const GLPIINVENTORY_MIN_VERSION    = '1.6.0';
    const GLPIINVENTORY_MAX_VERSION    = '1.7.0';
    const GLPIINVENTORY_TESTED_VERSION = '1.6.10';

    /** Prérequis de GLPI Inventory, calculés une fois par requête. */
    private static ?array $prerequisites = null;

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

    /**
     * Prérequis de GLPI Inventory pour l'assistant de raccordement, tous vérifiés avant sa première étape :
     * plugin installé, activé et chargé, version prise en charge, classes présentes, tâche automatique
     * « taskscheduler » (préparation des relevés périodiques) programmée. Chaque message dit où agir dans GLPI.
     * Tâche automatique qui n'a pas tourné récemment : avertissement seulement, la découverte lancée par
     * l'assistant partant quand même.
     *
     * @return array ['blocking' => string[], 'warnings' => string[], 'version' => string]
     */
    public static function getPrerequisites(): array {
        if (self::$prerequisites !== null) {
            return self::$prerequisites;
        }
        // « installed » : le plugin est-il au moins posé sur ce GLPI ? Absent, c'est un choix — les sondes
        // scanneront en local. Posé mais inutilisable, c'est une panne. Les deux empêchent le pilotage, mais ne se
        // disent pas de la même façon à l'écran.
        $out    = ['blocking' => [], 'warnings' => [], 'version' => '', 'installed' => false];
        $plugin = new Plugin();
        if (!$plugin->getFromDBbyDir('glpiinventory') || (int) $plugin->fields['state'] === Plugin::NOTINSTALLED) {
            $out['blocking'][] = sprintf(
                __('GLPI Inventory envoie aux sondes les plages IP à scanner : sans lui, chaque sonde scanne en local et rien ne se pilote depuis GLPI. L\'installer puis l\'activer dans Configuration → Plugins (version %s ou plus récente).', 'printgestion'),
                self::GLPIINVENTORY_MIN_VERSION
            );
            return self::$prerequisites = $out;
        }
        $out['installed'] = true;
        $out['version'] = (string) $plugin->fields['version'];
        $state          = (int) $plugin->fields['state'];
        if ($state !== Plugin::ACTIVATED) {
            $by_state = [
                Plugin::NOTACTIVATED   => __('GLPI Inventory est installé mais désactivé : Configuration → Plugins, « Activer ».', 'printgestion'),
                Plugin::TOBECONFIGURED => __('GLPI Inventory est installé mais pas configuré : Configuration → Plugins, terminer sa configuration, puis « Activer ».', 'printgestion'),
                Plugin::NOTUPDATED     => __('Les fichiers de GLPI Inventory ont changé de version mais sa mise à jour n\'est pas lancée : Configuration → Plugins, « Mettre à jour », puis « Activer ».', 'printgestion'),
                Plugin::TOBECLEANED    => __('GLPI Inventory est enregistré mais ses fichiers sont absents du dossier plugins/glpiinventory : remettre les fichiers de la version installée, ou le « Nettoyer » dans Configuration → Plugins puis le réinstaller.', 'printgestion'),
                Plugin::REPLACED       => __('GLPI Inventory est marqué comme remplacé par un autre plugin : Configuration → Plugins.', 'printgestion'),
            ];
            $out['blocking'][] = $by_state[$state] ?? sprintf(__('GLPI Inventory n\'est pas actif (état « %s ») : Configuration → Plugins.', 'printgestion'), Plugin::getState($state));
            return self::$prerequisites = $out;
        }
        if (!Plugin::isPluginActive('glpiinventory')) {
            $out['blocking'][] = __('GLPI Inventory est activé mais GLPI ne le charge pas (fichiers absents, ou version incompatible avec ce GLPI) : Configuration → Plugins.', 'printgestion');
            return self::$prerequisites = $out;
        }

        $version_check   = self::checkVersion($out['version']);
        $out['blocking'] = array_merge($out['blocking'], $version_check['blocking']);
        $out['warnings'] = array_merge($out['warnings'], $version_check['warnings']);
        if (!self::isAvailable()) {
            $out['blocking'][] = __('GLPI Inventory est actif mais ses classes sont introuvables (fichiers incomplets dans plugins/glpiinventory) : remettre les fichiers de la version installée.', 'printgestion');
        }
        $cron = new CronTask();
        if (!$cron->getFromDBbyName('PluginGlpiinventoryTask', 'taskscheduler')) {
            $out['blocking'][] = __('Tâche automatique « taskscheduler » de GLPI Inventory introuvable : c\'est elle qui prépare les relevés périodiques. Relancer la mise à jour de GLPI Inventory (Configuration → Plugins).', 'printgestion');
        } elseif ((int) $cron->fields['state'] === CronTask::STATE_DISABLE) {
            $out['blocking'][] = __('Tâche automatique « taskscheduler » de GLPI Inventory désactivée : la première découverte partirait, mais aucun relevé suivant. Configuration → Actions automatiques → taskscheduler : statut « Programmée ».', 'printgestion');
        } else {
            $lastrun = (string) ($cron->fields['lastrun'] ?? '');
            $delay   = max(2 * HOUR_TIMESTAMP, 10 * (int) $cron->fields['frequency']);
            if ($lastrun === '' || (int) strtotime($lastrun) < time() - $delay) {
                $out['warnings'][] = sprintf(
                    __('La tâche automatique « taskscheduler » de GLPI Inventory %s : vérifier que le cron de GLPI tourne (Configuration → Actions automatiques). La découverte lancée par l\'assistant part quand même, pas les relevés suivants.', 'printgestion'),
                    $lastrun === '' ? __('n\'a encore jamais tourné', 'printgestion') : sprintf(__('n\'a pas tourné depuis le %s', 'printgestion'), Html::convDateTime($lastrun))
                );
            }
        }
        return self::$prerequisites = $out;
    }

    /**
     * Borne de version de GLPI Inventory : plus ancienne que la version minimale = bloquant (l'assistant y crée
     * plages IP et tâches dans un format absent des anciennes versions) ; plus récente que la version validée,
     * nouvelle série comprise = avertissement seulement, pour ne jamais bloquer les techniciens à la sortie
     * d'une version de GLPI Inventory. Une version que GLPI juge incompatible n'est pas chargée : bloquée plus haut.
     *
     * @return array ['blocking' => string[], 'warnings' => string[]]
     */
    public static function checkVersion(string $version): array {
        $out = ['blocking' => [], 'warnings' => []];
        if (version_compare($version, self::GLPIINVENTORY_MIN_VERSION, '<')) {
            $out['blocking'][] = sprintf(
                __('GLPI Inventory %1$s est trop ancien pour l\'assistant, qui y crée les plages IP et les tâches des sondes : le mettre à jour en %2$s ou plus récent (Configuration → Plugins).', 'printgestion'),
                $version,
                self::GLPIINVENTORY_MIN_VERSION
            );
        } elseif (version_compare($version, self::GLPIINVENTORY_TESTED_VERSION, '>')) {
            $out['warnings'][] = sprintf(
                __('GLPI Inventory %1$s est plus récent que la version validée avec Print Gestion (%2$s)%3$s : le raccordement reste possible, vérifiez le premier de bout en bout (plage, tâche, imprimantes remontées).', 'printgestion'),
                $version,
                self::GLPIINVENTORY_TESTED_VERSION,
                version_compare($version, self::GLPIINVENTORY_MAX_VERSION, '>=') ? __(', nouvelle série', 'printgestion') : ''
            );
        }
        return $out;
    }

    /**
     * Objets de collecte créés par un raccordement, dans l'ordre inverse de leur création.
     *
     * Le raccordement garde la liste de ce qu'il a **créé** (`created_items`) : c'est elle qui sert ici, et elle
     * seule. Une plage IP ou des identifiants SNMP que l'assistant avait *réutilisés* parce qu'ils existaient déjà
     * ne sont pas touchés — ils servent peut-être à un autre client. L'ordre compte : les jobs avant leurs tâches,
     * la liaison avant la plage et les identifiants qu'elle relie.
     *
     * Tout passe par les classes du plugin voisin, donc par ses propres nettoyages (états et journaux des jobs).
     * Plugin absent ou désinstallé entre-temps : il n'y a plus rien à supprimer, et l'on ne casse rien.
     *
     * @return int nombre d'objets supprimés, pour le compte rendu
     */
    public static function purgeCreatedItems(PluginPrintgestionRaccordement $racc): int {
        $liste = self::createdItemsList($racc);
        if ($liste === []) {
            return 0;
        }
        $supprimes = 0;
        foreach ($liste as [$itemtype, $id]) {
            $item = getItemForItemtype($itemtype);
            if (!$item instanceof CommonDBTM || !$item->getFromDB($id)) {
                continue;
            }
            if ($item->delete(['id' => $id], true)) {
                $supprimes++;
            } else {
                PluginPrintgestionLogger::warning('collectsetup', sprintf(
                    'Raccordement %1$d : %2$s n° %3$d non supprimé.',
                    (int) $racc->getID(),
                    $itemtype,
                    $id
                ));
            }
        }
        if ($supprimes > 0) {
            // Plages IP et tâches supprimées : la couverture des sondes en cache est périmée.
            PluginPrintgestionAgentalert::invalidateCoverageCache();
        }
        PluginPrintgestionLogger::info('collectsetup', sprintf(
            'Raccordement %1$d : %2$d objet(s) de collecte supprimé(s) — ce que l\'assistant avait créé.',
            (int) $racc->getID(),
            $supprimes
        ));
        return $supprimes;
    }

    /**
     * Combien d'objets de collecte un « tout supprimer » emporterait, sans rien supprimer.
     *
     * Le poste le demande avant de lancer le retrait : annoncer un nombre, puis en supprimer un autre, ce serait
     * pire que de ne rien annoncer. La liste est donc la même des deux côtés.
     */
    public static function countCreatedItems(PluginPrintgestionRaccordement $racc): int {
        return count(self::createdItemsList($racc));
    }

    /**
     * Ce que le raccordement a créé et qui existe encore, dans l'ordre où il faut le supprimer : les jobs avant
     * leurs tâches, la liaison avant la plage et les identifiants qu'elle relie.
     *
     * Une seule liste pour deux usages — compter et supprimer. Deux codes auraient fini par diverger, et c'est
     * justement le nombre annoncé au technicien qui aurait menti.
     *
     * @return array [[itemtype, id], ...]
     */
    private static function createdItemsList(PluginPrintgestionRaccordement $racc): array {
        if (!self::isAvailable()) {
            return [];
        }
        $created = importArrayFromDB((string) ($racc->fields['created_items'] ?? ''));
        if (!is_array($created) || $created === []) {
            return [];
        }
        $liste = [];
        foreach ([
            'PluginGlpiinventoryTaskjob',
            'PluginGlpiinventoryTask',
            'PluginGlpiinventoryIPRange_SNMPCredential',
            self::RANGE_TYPE,
            SNMPCredential::class,
        ] as $itemtype) {
            foreach (array_map('intval', (array) ($created[$itemtype] ?? [])) as $id) {
                if ($id <= 0 || !class_exists($itemtype)) {
                    continue;
                }
                $item = getItemForItemtype($itemtype);
                if (!$item instanceof CommonDBTM || !$item->getFromDB($id)) {
                    continue;
                }
                $liste[] = [$itemtype, $id];
            }
        }
        return $liste;
    }

    public static function getMethodLabel(string $method): string {
        return $method === 'networkdiscovery'
            ? __('Découverte réseau', 'printgestion')
            // « SNMP » plutôt que « niveaux » : c'est le nom que porte cette collecte partout ailleurs, dans GLPI
            // Inventory comme chez le constructeur de l'imprimante.
            : __('Inventaire réseau (SNMP)', 'printgestion');
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

        // « both » : les deux versions, v2c d'abord. C'est le choix par défaut, et celui de l'installation depuis
        // le PC — une imprimante qui n'expose que SNMPv1 reste sinon muette, sans que rien ne le dise.
        $version   = (string) ($input['snmpversion'] ?? '');
        $version   = $version === 'both' ? '2' : $version;
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

    /**
     * L'identifiant jumeau : la même communauté dans l'autre version SNMP.
     *
     * GLPI Inventory essaie les identifiants d'une plage dans l'ordre de leur rang et garde celui qui répond : en
     * poser deux ne coûte qu'un objet de plus, et évite qu'une imprimante n'exposant que SNMPv1 reste muette.
     * Réutilisé s'il existe déjà. Rendu seulement pour un identifiant tout neuf, jamais quand l'administrateur a
     * choisi lui-même des identifiants existants — là, c'est sa décision.
     */
    private static function planCompanionCredential(array $input, array $main, array &$notes): ?array {
        global $DB;

        if ((string) ($input['snmpversion'] ?? '') !== 'both' || ($input['credential_mode'] ?? 'existing') !== 'new') {
            return null;
        }
        $community = (string) ($input['community'] ?? '');
        if ($community === '' || ($main['action'] ?? '') === '') {
            return null;
        }
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'community'],
            'FROM'   => SNMPCredential::getTable(),
            'WHERE'  => ['is_deleted' => 0, 'snmpversion' => '1'],
            'ORDER'  => ['id'],
        ]) as $row) {
            if ((string) $row['community'] === $community) {
                $notes[] = sprintf(__('Identifiants SNMP v1 « %s » réutilisés : une imprimante qui n\'expose que v1 répondra aussi.', 'printgestion'), $row['name']);
                return ['action' => 'reuse', 'id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        }
        $name = sprintf(__('SNMP v1 « %s »', 'printgestion'), $community);
        if (mb_strlen($name) > 64 || countElementsInTable(SNMPCredential::getTable(), ['name' => $name, 'is_deleted' => 0]) > 0) {
            // Nom déjà pris par d'autres identifiants : on s'en tient au principal plutôt que d'échouer.
            $notes[] = __('Identifiants SNMP v1 non créés : ce nom est déjà pris. Une imprimante qui n\'expose que SNMPv1 ne répondra pas.', 'printgestion');
            return null;
        }
        return ['action' => 'create', 'id' => 0, 'name' => $name, 'snmpversion' => '1', 'community' => $community];
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

    /**
     * Modules GLPI Inventory d'une sonde d'imprimantes, posés à l'installation : ce qui sert aux imprimantes, et
     * rien d'autre. Nom du module => coché.
     *
     * L'inventaire de l'ordinateur reste coché : c'est lui qui fait exister la sonde dans GLPI (fiche ordinateur,
     * rattachement à l'entité par le TAG), et c'est par ce nom d'ordinateur que le compte rendu d'installation la
     * retrouve. Les trois premiers sont obligatoires ; les autres, s'ils manquent au serveur, n'ont rien à décocher.
     */
    const PRINTER_PROBE_MODULES = [
        'INVENTORY'            => true,
        'NETWORKDISCOVERY'     => true,
        'NETWORKINVENTORY'     => true,
        'InventoryComputerESX' => false,
        'DEPLOY'               => false,
        'Collect'              => false,
        'WAKEONLAN'            => false,
    ];

    /**
     * Coche ou décoche un module pour une sonde, par la même mécanique que les écrans de GLPI Inventory : l'agent
     * figure dans les exceptions du module exactement quand l'état voulu diffère de l'activation globale.
     *
     * @return ?string ce qui a changé ; null si le module était déjà dans l'état voulu
     * @throws DomainException module introuvable, ou écriture refusée
     */
    private static function setModuleForAgent(string $modulename, int $agents_id, bool $wanted): ?string {
        $module = self::getModule($modulename);
        if ($module === null) {
            throw new DomainException(sprintf(__('module de collecte « %s » introuvable sur ce serveur', 'printgestion'), $modulename));
        }
        if (self::isModuleActiveForAgent($module, $agents_id) === $wanted) {
            return null;
        }
        $autres     = array_values(array_diff(self::getExceptions($module), [(string) $agents_id]));
        $exceptions = ((int) $module['is_active'] === 1) === $wanted ? $autres : array_merge($autres, [(string) $agents_id]);
        $agentmodule = new PluginGlpiinventoryAgentmodule();
        if (!$agentmodule->update(['id' => (int) $module['id'], 'exceptions' => exportArrayToDB($exceptions)])) {
            throw new DomainException(sprintf(__('module « %s » non réglé pour la sonde', 'printgestion'), $modulename));
        }
        return sprintf($wanted ? __('Module %s activé pour la sonde.', 'printgestion') : __('Module %s désactivé pour la sonde.', 'printgestion'), $modulename);
    }

    /**
     * Pose le profil d'une sonde d'imprimantes (PRINTER_PROBE_MODULES) sur un agent.
     *
     * Appelé par le compte rendu d'installation, dès que la sonde est connue de GLPI — et indépendamment du
     * raccordement : sans les deux modules réseau, GLPI Inventory n'envoie aucune tâche réseau à la sonde, et une
     * sonde installée sans raccordement abouti restait sourde aux imprimantes.
     *
     * @return array ['ok' => bool, 'events' => [[niveau, message]]]
     */
    public static function applyPrinterProbeProfile(int $agents_id): array {
        if (!self::isAvailable()) {
            return ['ok' => false, 'events' => [['info', __('GLPI Inventory absent : aucun module à régler.', 'printgestion')]]];
        }
        $events = [];
        foreach (self::PRINTER_PROBE_MODULES as $modulename => $wanted) {
            // Un module à décocher qui n'existe pas sur ce serveur est, de fait, décoché.
            if (!$wanted && self::getModule($modulename) === null) {
                continue;
            }
            try {
                $change = self::setModuleForAgent($modulename, $agents_id, $wanted);
                if ($change !== null) {
                    $events[] = ['success', $change];
                }
            } catch (DomainException $e) {
                $events[] = ['error', $e->getMessage()];
            } catch (Throwable $e) {
                \Glpi\Error\ErrorHandler::logCaughtException($e);
                $events[] = ['error', sprintf(__('module « %s » : erreur technique, détail dans le journal PHP de GLPI', 'printgestion'), $modulename)];
            }
        }
        $ok = !in_array('error', array_column($events, 0), true);
        if ($ok && empty($events)) {
            $events[] = ['info', __('Modules de la sonde déjà réglés pour les imprimantes.', 'printgestion')];
        }
        return ['ok' => $ok, 'events' => $events];
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
        $plan = ['errors' => [], 'notes' => [], 'credential' => null, 'credential_alt' => null, 'ranges' => [], 'modules' => [], 'tasks' => []];
        if (!self::isAvailable()) {
            $plan['errors'][] = __('La collecte réseau n\'est pas disponible sur ce serveur (module d\'inventaire réseau absent ou inactif) : prévenez l\'administrateur.', 'printgestion');
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
        // Le jumeau dans l'autre version : posé en second, il ne sert que si le premier ne répond pas.
        $plan['credential_alt'] = $plan['credential'] === null
            ? null
            : self::planCompanionCredential($input, $plan['credential'], $plan['notes']);

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
                        __('La plage à créer (%1$s – %2$s) chevaucherait la plage « %3$s » (%4$s – %5$s) : déclarez les adresses de part et d\'autre dans des raccordements séparés, ou demandez à l\'administrateur d\'élargir la plage existante.', 'printgestion'),
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
                $plan['errors'][] = sprintf(__('Module de collecte « %s » introuvable sur ce serveur : prévenez l\'administrateur.', 'printgestion'), $modulename);
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
                            __('La tâche « %1$s » collecte déjà la plage « %2$s » avec cette sonde, mais elle est désactivée : demandez à l\'administrateur de la réactiver plutôt que d\'en créer une seconde.', 'printgestion'),
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

            // Le jumeau dans l'autre version SNMP : une imprimante qui n'expose que SNMPv1 répond ainsi elle aussi.
            $alt_id = (int) ($plan['credential_alt']['id'] ?? 0);
            if (($plan['credential_alt']['action'] ?? '') === 'create') {
                $alt    = new SNMPCredential();
                $alt_id = (int) $alt->add([
                    'name'        => $plan['credential_alt']['name'],
                    'snmpversion' => $plan['credential_alt']['snmpversion'],
                    'community'   => $plan['credential_alt']['community'],
                ]);
                self::assertCreated($alt_id, __('identifiants SNMP (deuxième version)', 'printgestion'));
                $created[SNMPCredential::class][] = $alt_id;
                $events[] = ['success', sprintf(__('Identifiants SNMP « %1$s » créés (n° %2$d) : essayés après les premiers.', 'printgestion'), $plan['credential_alt']['name'], $alt_id)];
            } elseif ($alt_id > 0) {
                $events[] = ['info', sprintf(__('Identifiants SNMP « %s » réutilisés comme deuxième version.', 'printgestion'), $plan['credential_alt']['name'])];
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

                // Les identifiants de la plage, dans l'ordre : le principal, puis son jumeau dans l'autre version.
                // GLPI Inventory les essaie par rang et garde celui qui répond.
                foreach (array_filter([$credential_id, $alt_id]) as $lie_id) {
                    $link_criteria = ['plugin_glpiinventory_ipranges_id' => $range_id, 'snmpcredentials_id' => $lie_id];
                    if (countElementsInTable(PluginGlpiinventoryIPRange_SNMPCredential::getTable(), $link_criteria) > 0) {
                        continue;
                    }
                    $rank    = self::getNextRank($range_id);
                    $link    = new PluginGlpiinventoryIPRange_SNMPCredential();
                    $link_id = (int) $link->add($link_criteria + ['rank' => $rank]);
                    self::assertCreated($link_id, __('liaison entre la plage et les identifiants SNMP', 'printgestion'));
                    $created[PluginGlpiinventoryIPRange_SNMPCredential::class][] = $link_id;
                    $events[] = ['success', sprintf(__('Identifiants SNMP n° %1$d liés à la plage n° %2$d (rang %3$d).', 'printgestion'), $lie_id, $range_id, $rank)];
                }
            }

            // Les deux modules réseau seulement : l'assistant raccorde aussi des postes existants, qui peuvent faire
            // autre chose que sonder des imprimantes. Le profil complet n'est posé qu'à l'installation.
            foreach (self::METHODS as $modulename) {
                $change = self::setModuleForAgent($modulename, $agents_id, true);
                if ($change !== null) {
                    $events[] = ['success', $change];
                }
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
        // Plages IP et tâches créées : la couverture des sondes en cache (écrans Agent, Sondes) est périmée.
        PluginPrintgestionAgentalert::invalidateCoverageCache();
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

    /**
     * Echeance a laquelle la sonde aura forcement rappele GLPI.
     *
     * L'agent travaille en tirage : il appelle GLPI, et GLPI lui rend alors les taches a executer. L'intervalle
     * maximal entre deux appels est le reglage natif « Frequence d'inventaire (en heures) » (Administration >
     * Inventaire), que GLPI renvoie a l'agent sous le nom « expiration ».
     *
     * @return array ['hours' => int, 'last' => string, 'deadline' => string] ; deadline vide si la sonde n'a
     *               jamais contacte GLPI — il n'y a alors aucune echeance a annoncer.
     */
    public static function getNextContact(Agent $agent): array {
        $hours = max(1, (int) (new \Glpi\Inventory\Conf())->inventory_frequency);
        $last  = trim((string) ($agent->fields['last_contact'] ?? ''));
        $stamp = $last !== '' ? strtotime($last) : false;
        return [
            'hours'    => $hours,
            'last'     => $stamp !== false ? $last : '',
            'deadline' => $stamp !== false ? date('Y-m-d H:i:s', $stamp + $hours * HOUR_TIMESTAMP) : '',
        ];
    }

    /**
     * Ce que la sonde fera d'elle-meme, et quand : la phrase a dire a quelqu'un qui n'est PAS devant le PC.
     *
     * @param string $what ce qui partira (« la découverte », « le relevé des niveaux »), insere en milieu de phrase.
     */
    public static function getPickupSentence(Agent $agent, string $what): string {
        $next = self::getNextContact($agent);
        if ($next['deadline'] === '') {
            return sprintf(
                __('Rien à faire à distance : %1$s partira au prochain contact de la sonde avec GLPI, qui a lieu au moins toutes les %2$d h.', 'printgestion'),
                $what,
                $next['hours']
            );
        }
        return sprintf(
            __('Rien à faire à distance : %1$s partira toute seule, au plus tard le %2$s — la sonde appelle GLPI au moins toutes les %3$d h.', 'printgestion'),
            $what,
            Html::convDateTime($next['deadline']),
            $next['hours']
        );
    }

    /** Ce qui prouve qu'une sonde fonctionne : son dernier contact, jamais une reponse a une demande du serveur. */
    public static function getProofOfLife(Agent $agent): string {
        $next = self::getNextContact($agent);
        return $next['last'] === ''
            ? __('Cette sonde n\'a encore jamais contacté GLPI.', 'printgestion')
            : sprintf(__('Elle a contacté GLPI le %s.', 'printgestion'), Html::convDateTime($next['last']));
    }

    /** « Demander le statut » natif : GET /status sur le port de l'agent, comme la fiche agent. */
    public static function requestStatus(Agent $agent): array {
        $name   = (string) $agent->fields['name'];
        $answer = trim((string) ($agent->requestStatus()['answer'] ?? ''));
        if ($answer === __('Not allowed')) {
            return ['info', sprintf(
                __('« %1$s » n\'accepte pas les demandes de ce serveur (réglage HTTPD_TRUST). Sans conséquence : c\'est la sonde qui appelle GLPI, jamais l\'inverse. %2$s', 'printgestion'),
                $name,
                self::getProofOfLife($agent)
            )];
        }
        if ($answer === '' || $answer === __('Unknown')) {
            // Jamais de contact : le seul cas ou l'absence de reponse dit vraiment quelque chose.
            if (self::getNextContact($agent)['last'] === '') {
                return ['warning', sprintf(
                    __('« %s » ne répond pas, et n\'a encore jamais contacté GLPI : vérifier que l\'agent tourne sur le PC et que l\'URL du serveur qu\'il connaît est la bonne.', 'printgestion'),
                    $name
                )];
            }
            return ['info', sprintf(
                __('GLPI n\'a pas de canal direct vers « %1$s », et n\'en a pas besoin : c\'est la sonde qui appelle GLPI, jamais l\'inverse. %2$s', 'printgestion'),
                $name,
                self::getProofOfLife($agent)
            )];
        }
        return ['success', sprintf(__('Statut de « %1$s » : %2$s.', 'printgestion'), $name, $answer)];
    }

    /** Réveil natif (GET /now sur le port de l'agent, comme « Demander un inventaire » de la fiche agent). */
    private static function wakeUp(Agent $agent, string $what): array {
        $answer = trim((string) ($agent->requestInventory()['answer'] ?? ''));
        // Un reveil reussi fait seulement gagner l'attente. Echoue, il ne change rien : la consigne est deja
        // posee cote serveur, et la sonde la prendra a son prochain appel. Donc jamais un avertissement.
        if ($answer === __('Not allowed')) {
            return ['info', sprintf(
                __('La sonde n\'accepte pas les ordres de ce serveur (réglage HTTPD_TRUST). %s', 'printgestion'),
                self::getPickupSentence($agent, $what)
            )];
        }
        if ($answer === '' || $answer === __('Unknown')) {
            return ['info', sprintf(
                __('GLPI ne pousse rien vers la sonde, et n\'a pas à le faire. %s', 'printgestion'),
                self::getPickupSentence($agent, $what)
            )];
        }
        return ['success', sprintf(__('Sonde réveillée par GLPI : elle lance %s maintenant, sans attendre.', 'printgestion'), $what)];
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
            return ['ok' => false, 'events' => [['error', __('La collecte réseau n\'est pas disponible sur ce serveur (module d\'inventaire réseau absent ou inactif) : prévenez l\'administrateur.', 'printgestion')]]];
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
                return ['ok' => false, 'events' => [['error', sprintf(__('La tâche de découverte n° %d de ce raccordement a été supprimée côté serveur : recommencez l\'étape de configuration.', 'printgestion'), $tasks_id)]]];
            }
            if ((int) $task->fields['is_active'] !== 1) {
                return ['ok' => false, 'events' => [['error', sprintf(__('La tâche de découverte « %s » a été désactivée côté serveur : demandez à l\'administrateur de la réactiver pour lancer la découverte.', 'printgestion'), $task->fields['name'])]]];
            }
            $tasks[] = $task;
        }

        $since = (string) Session::getCurrentTime();
        // Date de début posée par la fréquence de l'entité : effacée pour lancer la découverte tout de suite.
        PluginPrintgestionCollectfrequency::releaseTasks($task_ids);
        foreach ($tasks as $task) {
            $task->forceRunning();
        }
        $pending = self::countPendingJobstates(self::getJobIds($racc, 'networkdiscovery'), (int) $agent->getID());
        if ($pending === 0) {
            return ['ok' => false, 'events' => [['error', __('Aucune découverte n\'a été préparée pour cette sonde : dates et plages horaires de la tâche de découverte à vérifier par l\'administrateur.', 'printgestion')]]];
        }

        $DB->update(PluginPrintgestionRaccordement::IPS_TABLE, [
            'result'            => 'waiting_discovery',
            'itemtype'          => null,
            'items_id'          => 0,
            'items_entities_id' => null,
            'date_check'        => null,
        ], ['plugin_printgestion_raccordements_id' => (int) $racc->getID()]);
        if (!$racc->update([
            'id'                      => (int) $racc->getID(),
            'status'                  => PluginPrintgestionRaccordement::STATUS_TRIGGERED,
            'date_triggered'          => $since,
            'date_inventory_prepared' => 'NULL',
            'date_verified'           => 'NULL',
        ])) {
            PluginPrintgestionLogger::error('collectsetup', sprintf('Raccordement #%d : découverte préparée (%d job(s)) mais statut « découverte lancée » non enregistré.', $racc->getID(), $pending));
            return ['ok' => false, 'events' => [['error', sprintf(
                __('%d découverte(s) préparée(s), mais le raccordement n\'a pas pu passer à « découverte lancée » : relancez l\'étape.', 'printgestion'),
                $pending
            )]]];
        }

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
            return ['events' => [['error', __('La collecte réseau n\'est pas disponible sur ce serveur (module d\'inventaire réseau absent ou inactif) : prévenez l\'administrateur.', 'printgestion')]]];
        }
        $events      = [];
        $entities_id = (int) $racc->fields['entities_id'];
        $agents_id   = (int) $racc->fields['agents_id'];

        // Les deux modules réseau de la sonde, revérifiés à chaque point : sans eux, GLPI Inventory n'envoie
        // aucune tâche réseau et la découverte attend indéfiniment, sans rien dire. Ils sont posés à
        // l'installation, mais une sonde réinstallée, un module désactivé globalement ou une intervention dans
        // GLPI les remettent à zéro — et personne ne fait le lien. Déjà bons : aucune écriture, aucun message.
        foreach (self::applyPrinterProbeProfile($agents_id)['events'] as [$niveau, $message]) {
            // « Déjà réglés » n'apprend rien et reviendrait à chaque point, toutes les dix secondes pendant une
            // installation : seuls un changement ou un refus méritent une ligne.
            if ($niveau !== 'info') {
                $events[] = [$niveau, $message];
            }
        }

        $progress    = self::getProgress($racc);

        if ($progress['discovery_finished'] && !$progress['discovery_failed'] && empty($racc->fields['date_inventory_prepared'])) {
            // Date de début posée par la fréquence de l'entité : effacée pour relever les niveaux tout de suite.
            PluginPrintgestionCollectfrequency::releaseTasks(self::getTaskIds($racc, 'networkinventory'));
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
            // La corbeille n'est PAS exclue : une fiche mise à la corbeille reste reconnue par GLPI à chaque
            // découverte (même adresse MAC), et l'ignorer ici faisait dire « pas de réponse SNMP » à une
            // imprimante qui répondait parfaitement.
            $supprimable = $DB->fieldExists($table, 'is_deleted');
            foreach ($DB->request([
                'SELECT'     => array_merge(
                    ['ip.name AS ip', 'item.id', 'item.name', 'item.entities_id'],
                    $supprimable ? ['item.is_deleted'] : []
                ),
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
                    'is_deleted'  => (int) ($row['is_deleted'] ?? 0) === 1,
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
            // Répond, mais la fiche est ailleurs : autre entité, ou corbeille. Les deux empêchent le relevé, et
            // les deux se corrigent d'un clic — ce n'est pas un problème de réseau.
            if ($printer['entities_id'] !== $entities_id || !empty($printer['is_deleted'])) {
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
