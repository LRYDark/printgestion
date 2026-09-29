<?php
/**
 * PluginPrintgestionRaccordement — assistant de raccordement des imprimantes chez un client (module
 * Collecte SNMP / Déploiement Agent, phase 2). Étape par étape, sans passer à la suivante tant que la
 * précédente n'a pas réussi :
 * 1. sonde présente : contact récent, poste, découverte et inventaire réseau installés, statut demandé
 *    à l'agent par le mécanisme natif ;
 * 2. adresses des imprimantes déclarées, gardées en attente (rien n'est appliqué) ;
 * 3. configuration de collecte créée dans GLPI Inventory (PluginPrintgestionCollectsetup) ;
 * 4. découverte déclenchée, puis résultat vérifié adresse par adresse : trouvée, pas de réponse SNMP,
 *    mauvaise entité, sans niveaux.
 * Lecture : droit Déploiement ; actions : droit Déploiement en modification, dans les entités de
 * l'utilisateur. Journal horodaté de chaque étape, consultable ensuite. Rien n'est supprimé : un
 * raccordement abandonné garde ses adresses et son journal, les objets créés dans GLPI Inventory
 * restent en place. Lieu, commentaire et contrat des imprimantes : phase 3.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionRaccordement extends CommonDBTM {

    static $rightname = 'plugin_printgestion_deploiement';

    const STATUS_OPEN       = 'open';
    const STATUS_CONFIGURED = 'configured';
    const STATUS_TRIGGERED  = 'triggered';
    const STATUS_CLOSED     = 'closed';
    const STATUS_ABANDONED  = 'abandoned';

    const IPS_TABLE  = 'glpi_plugin_printgestion_raccordementips';
    /** Après ce délai depuis le déclenchement, la vérification cesse de se relancer : état franc et quoi faire. */
    const VERIFY_LIMIT = 30 * MINUTE_TIMESTAMP;
    const LOGS_TABLE = 'glpi_plugin_printgestion_raccordementlogs';

    /** Adresses déclarées au plus par raccordement (un /22). */
    const MAX_IPS = 1024;

    /** Contact de moins d'une heure : la sonde vient de parler à GLPI. */
    const RECENT_CONTACT = 3600;

    /** Au-delà de ce nombre, les adresses sans aucune réponse sont regroupées sur une ligne. */
    const GROUP_NO_RESPONSE = 10;

    static function getTypeName($nb = 0) {
        return _n('Raccordement d\'imprimantes', 'Raccordements d\'imprimantes', $nb, 'printgestion');
    }

    /** Nombre d'étapes de l'assistant, et donc la borne de tout numéro d'étape reçu. */
    const STEPS = 5;

    /**
     * Étape ouverte par la page en cours de rendu.
     *
     * Elle est posée une fois par showWizard() et lue par les formulaires, qui la renvoient telle quelle pour que
     * l'enregistrement ramène là où l'on travaillait. La passer en paramètre à chaque méthode d'affichage aurait
     * fait huit signatures à changer pour une donnée qui ne vaut que le temps d'un rendu.
     */
    private static int $view = 0;

    public static function getPageURL(?int $id = null, ?int $entities_id = null, int $step = 0): string {
        $url  = PLUGIN_PRINTGESTION_WEBDIR . '/front/raccordement.php';
        $etat = $step >= 1 && $step <= self::STEPS ? '&step=' . $step : '';
        if ($id !== null) {
            return $url . '?id=' . $id . $etat;
        }
        return $entities_id !== null ? $url . '?entities_id=' . $entities_id : $url;
    }

    /** Liste native : la page du module (pagination, tri et recherche natifs y reviennent). */
    static function getSearchURL($full = true) {
        return ($full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR) . '/front/raccordement.php';
    }

    /** Fiche d'un raccordement : l'assistant (raccordement.php?id=N). */
    static function getFormURL($full = true) {
        return self::getSearchURL($full);
    }

    /** Liste native filtrée sur une entité, une sonde, ou les deux. */
    public static function getListURL(?int $entities_id = null, ?int $agents_id = null): string {
        $criteria = [];
        if ($entities_id !== null) {
            $criteria[] = ['field' => 80, 'searchtype' => 'equals', 'value' => $entities_id];
        }
        if ($agents_id !== null) {
            $criteria[] = ['field' => 3, 'searchtype' => 'equals', 'value' => $agents_id];
        }
        return self::getSearchURL() . '?' . http_build_query(['criteria' => $criteria, 'reset' => 'reset']);
    }

    /** Champ caché qui fait revenir l'enregistrement sur l'étape affichée. */
    public static function stepField(): string {
        return self::$view >= 1 ? Html::hidden('step', ['value' => self::$view]) : '';
    }

    public static function getStatusLabels(): array {
        return [
            self::STATUS_OPEN       => [__('En cours', 'printgestion'), 'bg-blue text-blue-fg'],
            self::STATUS_CONFIGURED => [__('Collecte configurée', 'printgestion'), 'bg-blue text-blue-fg'],
            self::STATUS_TRIGGERED  => [__('Découverte lancée', 'printgestion'), 'bg-orange text-orange-fg'],
            self::STATUS_CLOSED     => [__('Terminé', 'printgestion'), 'bg-green text-green-fg'],
            self::STATUS_ABANDONED  => [__('Abandonné', 'printgestion'), 'bg-secondary text-secondary-fg'],
        ];
    }

    /** Résultat d'une adresse : libellé, classe du badge. */
    public static function getResultLabels(): array {
        return [
            'found'             => [__('Trouvée', 'printgestion'), 'bg-green text-green-fg'],
            'wrong_entity'      => [__('Mauvaise entité', 'printgestion'), 'bg-red text-red-fg'],
            'no_levels'         => [__('Sans niveaux', 'printgestion'), 'bg-orange text-orange-fg'],
            'no_snmp'           => [__('Pas de réponse SNMP', 'printgestion'), 'bg-red text-red-fg'],
            'not_printer'       => [__('Pas une imprimante', 'printgestion'), 'bg-orange text-orange-fg'],
            'waiting_inventory' => [__('Trouvée, niveaux en attente', 'printgestion'), 'bg-blue text-blue-fg'],
            'waiting_discovery' => [__('En attente de la découverte', 'printgestion'), 'bg-secondary text-secondary-fg'],
            'pending'           => [__('Non vérifiée', 'printgestion'), 'bg-secondary text-secondary-fg'],
        ];
    }

    // ── Adresses et journal ───────────────────────────────────────────────────

    public function getIps(): array {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::IPS_TABLE,
            'WHERE' => ['plugin_printgestion_raccordements_id' => (int) $this->getID()],
            'ORDER' => ['ip_num'],
        ]), false);
    }

    public function getResultCounts(): array {
        $counts = [];
        foreach ($this->getIps() as $row) {
            $counts[$row['result']] = ($counts[$row['result']] ?? 0) + 1;
        }
        return $counts;
    }

    /** « Trouvée : 2 · Sans niveaux : 1 », dans l'ordre des résultats. */
    public static function formatCounts(array $counts): string {
        $parts = [];
        foreach (self::getResultLabels() as $result => [$label]) {
            if (!empty($counts[$result])) {
                $parts[] = $label . ' : ' . (int) $counts[$result];
            }
        }
        return empty($parts) ? __('aucune adresse', 'printgestion') : implode(' · ', $parts);
    }

    public function addLog(int $step, string $level, string $message): void {
        global $DB;

        $DB->insert(self::LOGS_TABLE, [
            'plugin_printgestion_raccordements_id' => (int) $this->getID(),
            'date'                                 => Session::getCurrentTime(),
            'users_id'                             => (int) Session::getLoginUserID(),
            'step'                                 => $step,
            'level'                                => $level,
            'message'                              => $message,
        ]);
    }

    /** Adresses de l'étape 2, remplacées en bloc tant que la configuration n'est pas créée. */
    protected function replaceIps(array $ips): bool {
        global $DB;

        $DB->beginTransaction();
        try {
            $DB->delete(self::IPS_TABLE, ['plugin_printgestion_raccordements_id' => (int) $this->getID()]);
            foreach ($ips as $ip_num => $ip) {
                $DB->insert(self::IPS_TABLE, [
                    'plugin_printgestion_raccordements_id' => (int) $this->getID(),
                    'ip'                                   => $ip,
                    'ip_num'                               => (int) $ip_num,
                    'result'                               => 'pending',
                ]);
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            \Glpi\Error\ErrorHandler::logCaughtException($e);
            return false;
        }
        return true;
    }

    private function countIps(): int {
        return countElementsInTable(self::IPS_TABLE, ['plugin_printgestion_raccordements_id' => (int) $this->getID()]);
    }

    /**
     * Étape la plus avancée que l'état des données permet d'ouvrir — au-delà, il n'y aurait rien à montrer.
     *
     * Sans adresse, la collecte n'a rien à configurer ; sans découverte lancée, il n'y a aucune imprimante à qui
     * appliquer un lieu.
     */
    public function getReachableStep(): int {
        if (!empty($this->fields['date_triggered'])) {
            return 5;
        }
        return $this->countIps() > 0 ? 4 : 2;
    }

    /** Étape ouverte quand la page n'en demande aucune : celle où il reste quelque chose à faire. */
    public function getCurrentStep(): int {
        if ($this->fields['status'] === self::STATUS_CLOSED) {
            return 5;
        }
        if (in_array($this->fields['status'], [self::STATUS_CONFIGURED, self::STATUS_TRIGGERED], true)
            || !empty($this->fields['date_configured'])) {
            return 4;
        }
        return $this->countIps() > 0 ? 3 : 2;
    }

    // ── Adresses saisies ──────────────────────────────────────────────────────

    /**
     * Adresses saisies à l'étape 2 : une par ligne, ou séparées par des virgules, points-virgules ou
     * espaces ; plage « 192.168.1.30-192.168.1.35 » ou « 192.168.1.30-35 » ; réseau « 192.168.1.0/24 »
     * (de /22 à /32, sans l'adresse du réseau ni celle de diffusion).
     *
     * @return array ['ips' => [numérique => texte], 'errors' => [message]]
     */
    public static function parseIps(string $text): array {
        $ips    = [];
        $errors = [];
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $token, $matches)) {
                $base = self::toLong($matches[1]);
                $bits = (int) $matches[2];
                if ($base === null || $bits < 22 || $bits > 32) {
                    $errors[] = sprintf(__('« %s » : réseau refusé (de /22 à /32).', 'printgestion'), $token);
                    continue;
                }
                $mask          = $bits === 32 ? 0xFFFFFFFF : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
                $network       = $base & $mask;
                $broadcast     = $network | (~$mask & 0xFFFFFFFF);
                [$start, $end] = $bits >= 31 ? [$network, $broadcast] : [$network + 1, $broadcast - 1];
            } elseif (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})-(\d{1,3}(?:\.\d{1,3}){3}|\d{1,3})$#', $token, $matches)) {
                $start = self::toLong($matches[1]);
                $end   = str_contains($matches[2], '.')
                    ? self::toLong($matches[2])
                    : self::toLong(substr($matches[1], 0, (int) strrpos($matches[1], '.') + 1) . $matches[2]);
                if ($start === null || $end === null || $start > $end) {
                    $errors[] = sprintf(__('« %s » : plage refusée (adresse incorrecte, ou début après la fin).', 'printgestion'), $token);
                    continue;
                }
            } else {
                $start = self::toLong($token);
                $end   = $start;
                if ($start === null) {
                    $errors[] = sprintf(__('« %s » : adresse IPv4 incorrecte.', 'printgestion'), $token);
                    continue;
                }
            }
            if ($end - $start + 1 + count($ips) > self::MAX_IPS) {
                $errors[] = sprintf(__('Plus de %d adresses : déclarez-les en plusieurs raccordements.', 'printgestion'), self::MAX_IPS);
                break;
            }
            for ($ip = $start; $ip <= $end; $ip++) {
                if (!self::isUsableAddress($ip)) {
                    $errors[] = sprintf(__('« %s » : adresse réservée (0.x, 127.x, multidiffusion ou diffusion).', 'printgestion'), long2ip($ip));
                    continue 2;
                }
                $ips[$ip] = long2ip($ip);
            }
        }
        ksort($ips);
        return ['ips' => $ips, 'errors' => array_values(array_unique($errors))];
    }

    private static function toLong(string $ip): ?int {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $long = ip2long($ip);
        return $long === false ? null : $long;
    }

    private static function isUsableAddress(int $ip): bool {
        $first = $ip >> 24;
        return $first !== 0 && $first !== 127 && $first < 224;
    }

    /** Adresses regroupées en plages contiguës : « 192.168.1.20–192.168.1.25, 192.168.1.40 ». */
    public static function summarizeIps(array $longs, string $dash = '–', string $glue = ', '): string {
        $longs = array_map('intval', $longs);
        sort($longs);
        $parts = [];
        $start = null;
        $prev  = null;
        foreach ($longs as $ip) {
            if ($start !== null && $ip === $prev + 1) {
                $prev = $ip;
                continue;
            }
            if ($start !== null) {
                $parts[] = $start === $prev ? long2ip($start) : long2ip($start) . $dash . long2ip($prev);
            }
            $start = $ip;
            $prev  = $ip;
        }
        if ($start !== null) {
            $parts[] = $start === $prev ? long2ip($start) : long2ip($start) . $dash . long2ip($prev);
        }
        return implode($glue, $parts);
    }

    // ── Étape 1 : sonde ───────────────────────────────────────────────────────

    /** Prérequis de GLPI et de l'entité : sans eux, aucun raccordement ne peut aboutir. */
    public static function getBlockers(Entity $entity): array {
        // GLPI Inventory installé, activé, en version prise en charge, tâche automatique programmée.
        $blockers = PluginPrintgestionCollectsetup::getPrerequisites()['blocking'];
        if ((int) (new \Glpi\Inventory\Conf())->enabled_inventory !== 1) {
            $blockers[] = __('Inventaire désactivé dans GLPI (Administration → Inventaire → Activer l\'inventaire) : GLPI refuse tout ce que la sonde envoie.', 'printgestion');
        }
        $tag = trim((string) ($entity->fields['tag'] ?? ''));
        if ($tag === '') {
            $blockers[] = __('TAG de l\'entité vide : les imprimantes n\'arriveraient pas dans cette entité (onglet Informations avancées de l\'entité).', 'printgestion');
        } elseif (!PluginPrintgestionAgentdeploy::isValidTag($tag) || countElementsInTable(Entity::getTable(), ['tag' => $tag]) > 1) {
            $blockers[] = __('TAG de l\'entité inutilisable : caractères refusés, ou porté aussi par une autre entité.', 'printgestion');
        }
        if (PluginPrintgestionAgentdeploy::getTagRuleStatus()['active'] === null) {
            $blockers[] = __('Aucune règle d\'affectation par TAG active : les imprimantes découvertes arriveraient dans l\'entité par défaut (voir le bloc 1 de l\'onglet Déploiement Agent).', 'printgestion');
        }
        return $blockers;
    }

    /** Version d'un agent : texte simple, ou versions par module. */
    private static function getAgentVersion(array $agent): string {
        if (isset($agent['version_value'])) {
            return (string) $agent['version_value'];
        }
        $modules = importArrayFromDB((string) ($agent['version'] ?? ''));
        return is_array($modules) && !empty($modules) ? (string) reset($modules) : trim((string) ($agent['version'] ?? ''));
    }

    /**
     * Conditions d'une sonde. Bloquant : aucun contact récent, découverte ou inventaire réseau absent,
     * TAG différent de celui de l'entité. Avertissement : pas de contact dans l'heure, poste pas encore
     * inventorié.
     *
     * @return array ['blocking' => [], 'warnings' => [], 'contact' => none|recent|old|silent]
     */
    public static function checkAgent(Entity $entity, array $agent): array {
        $blocking  = [];
        $warnings  = [];
        $tag       = trim((string) ($entity->fields['tag'] ?? ''));
        $frequency = max(1, (int) (new \Glpi\Inventory\Conf())->inventory_frequency);
        $age       = empty($agent['last_contact']) ? null : time() - (int) strtotime((string) $agent['last_contact']);

        if ($age === null) {
            $contact    = 'none';
            $blocking[] = __('Aucun contact avec GLPI.', 'printgestion');
        } elseif ($age > 2 * $frequency * HOUR_TIMESTAMP) {
            $contact    = 'silent';
            $blocking[] = sprintf(__('Dernier contact trop ancien (%s) : la sonde ne parle plus à GLPI (poste éteint, service arrêté, réseau).', 'printgestion'), Html::convDateTime((string) $agent['last_contact']));
        } elseif ($age > self::RECENT_CONTACT) {
            $contact    = 'old';
            $warnings[] = sprintf(__('Pas de contact depuis %s : si l\'agent vient d\'être installé, attendez son premier contact ; sinon demandez son statut.', 'printgestion'), Html::convDateTime((string) $agent['last_contact']));
        } else {
            $contact = 'recent';
        }
        if ((int) ($agent['use_module_network_discovery'] ?? 0) !== 1 || (int) ($agent['use_module_network_inventory'] ?? 0) !== 1) {
            $blocking[] = __('Découverte ou inventaire réseau absent de l\'agent : réinstallez-le avec le paquet de l\'onglet Déploiement Agent (ADDLOCAL=feat_AGENT,feat_NETINV), puis attendez son contact.', 'printgestion');
        }
        $agent_tag = trim((string) ($agent['tag'] ?? ''));
        if (mb_strtolower($agent_tag) !== mb_strtolower($tag)) {
            $blocking[] = sprintf(__('TAG de l\'agent « %1$s » différent de celui de l\'entité « %2$s » : les imprimantes arriveraient ailleurs. Réinstallez avec le paquet de cette entité.', 'printgestion'), $agent_tag, $tag);
        }
        if (!is_a((string) ($agent['itemtype'] ?? ''), CommonDBTM::class, true) || (int) ($agent['items_id'] ?? 0) <= 0) {
            $warnings[] = __('Poste de la sonde pas encore inventorié : son premier inventaire suit l\'installation de quelques minutes.', 'printgestion');
        }
        return ['blocking' => $blocking, 'warnings' => $warnings, 'contact' => $contact];
    }

    /** Agent rattaché à l'entité, ou null. */
    private static function findEntityAgent(int $entities_id, int $agents_id): ?Agent {
        $agent = new Agent();
        if ($agents_id <= 0 || !$agent->getFromDB($agents_id) || (int) $agent->fields['entities_id'] !== $entities_id) {
            return null;
        }
        return $agent;
    }

    /** Conditions bloquantes de l'étape 1, revérifiées avant chaque écriture dans GLPI Inventory. */
    private function getAgentBlocking(Entity $entity): array {
        $agent = self::findEntityAgent((int) $entity->getID(), (int) $this->fields['agents_id']);
        if ($agent === null) {
            return [__('La sonde n\'est plus rattachée à cette entité.', 'printgestion')];
        }
        return array_merge(self::getBlockers($entity), self::checkAgent($entity, $agent->fields)['blocking']);
    }

    private static function getHostName(array $agent): string {
        $itemtype = (string) ($agent['itemtype'] ?? '');
        $items_id = (int) ($agent['items_id'] ?? 0);
        return is_a($itemtype, CommonDBTM::class, true) && $items_id > 0 ? (string) $itemtype::getFriendlyNameById($items_id) : '—';
    }

    /** Poste qui porte la sonde, en lien vers sa fiche (HTML échappé). */
    private static function getHostHtml(array $agent): string {
        $itemtype = (string) ($agent['itemtype'] ?? '');
        $items_id = (int) ($agent['items_id'] ?? 0);
        if (!is_a($itemtype, CommonDBTM::class, true) || $items_id <= 0) {
            return '—';
        }
        return "<a href='" . htmlspecialchars($itemtype::getFormURLWithID($items_id), ENT_QUOTES, 'UTF-8') . "'>"
            . htmlspecialchars((string) $itemtype::getFriendlyNameById($items_id), ENT_QUOTES, 'UTF-8') . "</a>";
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Étape 1 : crée le raccordement avec une sonde qui remplit les conditions, ou reprend celui qui
     * est en cours avec cette sonde.
     *
     * @return array ['id' => int, 'messages' => [[niveau, message]]]
     */
    public static function start(Entity $entity, int $agents_id): array {
        global $DB;

        $entities_id = (int) $entity->getID();
        $errors      = self::getBlockers($entity);
        $check       = ['blocking' => [], 'warnings' => []];
        $agent       = self::findEntityAgent($entities_id, $agents_id);
        if ($agent === null) {
            $errors[] = __('Sonde introuvable dans cette entité.', 'printgestion');
        } else {
            $check  = self::checkAgent($entity, $agent->fields);
            $errors = array_merge($errors, $check['blocking']);
        }
        if (!empty($errors) || $agent === null) {
            return ['id' => 0, 'messages' => array_map(static fn(string $error): array => ['error', $error], $errors)];
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'entities_id' => $entities_id,
                'agents_id'   => $agents_id,
                'status'      => [self::STATUS_OPEN, self::STATUS_CONFIGURED, self::STATUS_TRIGGERED],
            ],
            'ORDER'  => ['id DESC'],
            'LIMIT'  => 1,
        ])->current();
        if (is_array($existing)) {
            return ['id' => (int) $existing['id'], 'messages' => [[
                'info',
                sprintf(__('Un raccordement est déjà en cours avec cette sonde (n° %d) : il est repris.', 'printgestion'), (int) $existing['id']),
            ]]];
        }

        $racc = new self();
        $id   = (int) $racc->add([
            'entities_id' => $entities_id,
            'agents_id'   => $agents_id,
            'status'      => self::STATUS_OPEN,
            'users_id'    => (int) Session::getLoginUserID(),
        ]);
        if ($id <= 0) {
            return ['id' => 0, 'messages' => [['error', __('Raccordement non créé (erreur de base de données).', 'printgestion')]]];
        }
        $message = sprintf(
            __('Étape 1 validée : sonde « %1$s » (poste %2$s, GLPI Agent %3$s), dernier contact %4$s, TAG %5$s, découverte et inventaire réseau installés.', 'printgestion'),
            $agent->fields['name'],
            self::getHostName($agent->fields),
            self::getAgentVersion($agent->fields) ?: '—',
            Html::convDateTime((string) $agent->fields['last_contact']),
            $agent->fields['tag']
        );
        $racc->addLog(1, 'success', $message);
        foreach ($check['warnings'] as $warning) {
            $racc->addLog(1, 'warning', $warning);
        }
        return ['id' => $id, 'messages' => [['success', $message]]];
    }

    /**
     * Action POST de l'assistant (droit en modification et accès à l'entité vérifiés par la page) ;
     * renvoie l'adresse de retour. Chaque action sur un raccordement est tracée dans son journal.
     */
    /**
     * Raccordement créé sans assistant, à partir des adresses saisies dans la fenêtre d'installation, sur le PC.
     *
     * Le but est qu'un technicien reparte sans rien avoir à faire dans GLPI. Ce qui est créé ici est exactement ce
     * que l'assistant crée : un raccordement visible dans son écran, avec son journal étape par étape, donc
     * vérifiable et corrigible. Rien de caché, rien d'irréversible.
     *
     * Sans communauté SNMP, on s'arrête après les adresses : la configuration de collecte demande des identifiants,
     * et en inventer serait pire que de laisser un clic à faire.
     *
     * Appelé depuis une page sans session (le compte rendu du fichier d'installation) : aucune vérification de droit
     * ici, c'est la clé à usage unique qui a ouvert, et l'entité est celle pour laquelle le fichier a été fabriqué.
     *
     * @return array ['ok' => bool, 'id' => int, 'messages' => string[]]
     */
    /**
     * La sonde d'un PC, retrouvée par le nom de son ordinateur ; 0 si GLPI ne la connaît pas encore.
     *
     * Au moment où le fichier d'installation rend compte, il ne connaît rien d'autre de GLPI. La plus récemment
     * vue l'emporte si deux agents portent le même ordinateur.
     */
    public static function findAgentByComputer(int $entities_id, string $computer): int {
        global $DB;

        $nom = trim($computer);
        if ($nom === '') {
            return 0;
        }
        foreach ($DB->request([
            'SELECT'     => ['a.id'],
            'FROM'       => Agent::getTable() . ' AS a',
            'INNER JOIN' => [Computer::getTable() . ' AS c' => ['ON' => ['a' => 'items_id', 'c' => 'id']]],
            'WHERE'      => [
                'a.entities_id' => $entities_id,
                'a.itemtype'    => Computer::class,
                'c.name'        => $nom,
                'c.is_deleted'  => 0,
            ],
            'ORDER'      => ['a.last_contact DESC'],
            'LIMIT'      => 1,
        ]) as $row) {
            return (int) $row['id'];
        }
        return 0;
    }

    public static function createFromInstaller(int $entities_id, string $computer, string $ips_text, string $community): array {
        global $DB;

        $entity = new Entity();
        if (!$entity->getFromDB($entities_id)) {
            return ['ok' => false, 'id' => 0, 'messages' => ['Entité introuvable.']];
        }
        $nom = trim($computer);
        if ($nom === '') {
            return ['ok' => false, 'id' => 0, 'messages' => ['Nom du PC absent : sonde introuvable.']];
        }

        $agents_id = self::findAgentByComputer($entities_id, $nom);
        if ($agents_id === 0) {
            return ['ok' => false, 'id' => 0, 'messages' => [sprintf('Aucune sonde nommée « %s » dans cette entité : le raccordement reste à créer à la main.', $nom)]];
        }

        $parsed = self::parseIps($ips_text);
        if (!empty($parsed['errors']) || empty($parsed['ips'])) {
            return ['ok' => false, 'id' => 0, 'messages' => array_merge(
                ['Adresses refusées, rien n\'a été créé :'],
                $parsed['errors'] ?: ['aucune adresse lisible.']
            )];
        }

        $start = self::start($entity, $agents_id);
        if ($start['id'] === 0) {
            return ['ok' => false, 'id' => 0, 'messages' => array_map(static fn(array $m): string => $m[1], $start['messages'])];
        }
        $racc = new self();
        if (!$racc->getFromDB($start['id'])) {
            return ['ok' => false, 'id' => 0, 'messages' => ['Raccordement créé mais introuvable.']];
        }
        $messages = array_map(static fn(array $m): string => $m[1], $start['messages']);
        $racc->addLog(1, 'info', __('Étape 1 remplie par le fichier d\'installation, sur le PC.', 'printgestion'));

        // Réinstallation sur un PC qui a déjà un raccordement : start() l'a repris, et l'assistant fige les
        // adresses dès que la configuration de collecte existe. Les réécrire d'ici laisserait la plage IP de GLPI
        // Inventory en désaccord avec ce que le raccordement affiche.
        if ((string) $racc->fields['status'] !== self::STATUS_OPEN) {
            $connues = array_map(static fn(array $row): int => (int) $row['ip_num'], $racc->getIps());
            $voulues = array_map('intval', array_keys($parsed['ips']));
            sort($connues);
            sort($voulues);
            if ($connues !== $voulues) {
                $refus = sprintf(
                    __('Raccordement n° %d déjà configuré avec d\'autres adresses : celles saisies sur le PC sont ignorées, rien n\'est écrasé. À corriger dans l\'assistant.', 'printgestion'),
                    (int) $racc->getID()
                );
                $racc->addLog(2, 'warning', $refus);
                return ['ok' => false, 'id' => (int) $racc->getID(), 'messages' => array_merge($messages, [$refus]), 'triggered' => false];
            }
            // Mêmes adresses : rien à réécrire, et relancer la découverte est justement ce qu'on attend.
            $reprise = sprintf(
                __('Réinstallation sur le même PC, mêmes adresses : la découverte est relancée sur le raccordement n° %d.', 'printgestion'),
                (int) $racc->getID()
            );
            $racc->addLog(4, 'info', $reprise);
            return $racc->launchFromInstaller(array_merge($messages, [$reprise]));
        }

        if (!$racc->replaceIps($parsed['ips'])) {
            $racc->addLog(2, 'error', __('Adresses non enregistrées (erreur de base de données).', 'printgestion'));
            return ['ok' => false, 'id' => (int) $racc->getID(), 'messages' => array_merge($messages, ['Adresses non enregistrées.'])];
        }
        $resume = sprintf(
            _n('%1$d adresse déclarée depuis le PC (%2$s).', '%1$d adresses déclarées depuis le PC (%2$s).', count($parsed['ips']), 'printgestion'),
            count($parsed['ips']),
            self::summarizeIps(array_keys($parsed['ips']))
        );
        $racc->addLog(2, 'success', $resume);
        $messages[] = $resume;

        if ($community === '') {
            $attente = __('Communauté SNMP non saisie : la configuration de collecte reste à créer dans l\'assistant.', 'printgestion');
            $racc->addLog(3, 'info', $attente);
            return ['ok' => true, 'id' => (int) $racc->getID(), 'messages' => array_merge($messages, [$attente])];
        }

        // Configuration de collecte : la même que celle de l'étape 3 de l'assistant, avec les identifiants SNMP
        // saisis sur le PC (réutilisés s'ils existent déjà dans GLPI, créés sinon).
        $plan = PluginPrintgestionCollectsetup::plan($racc, [
            'credential_mode'  => 'new',
            // Les deux versions : l'imprimante d'un client n'expose parfois que SNMPv1, et personne n'est devant
            // GLPI pour s'en apercevoir — le technicien, lui, est déjà reparti.
            'snmpversion'      => 'both',
            'community'        => $community,
            'credential_name'  => sprintf(__('SNMP v2c « %s »', 'printgestion'), $community),
        ]);
        if (!empty($plan['errors'])) {
            foreach ($plan['errors'] as $error) {
                $racc->addLog(3, 'error', $error);
            }
            $racc->addLog(3, 'error', __('Configuration refusée : les adresses sont enregistrées, la collecte reste à créer dans l\'assistant.', 'printgestion'));
            return ['ok' => true, 'id' => (int) $racc->getID(), 'messages' => array_merge($messages, $plan['errors'])];
        }
        $result = PluginPrintgestionCollectsetup::apply($racc, $plan);
        foreach (array_merge($plan['notes'], array_map(static fn(array $e): string => $e[1], $result['events'])) as $note) {
            $messages[] = $note;
        }
        foreach ($result['events'] as [$level, $message]) {
            $racc->addLog(3, $level, $message);
        }
        if (!$result['ok']) {
            return ['ok' => false, 'id' => (int) $racc->getID(), 'messages' => $messages, 'triggered' => false];
        }
        $racc->addLog(3, 'success', __('Étape 3 validée : configuration de collecte créée depuis le fichier d\'installation.', 'printgestion'));

        return $racc->launchFromInstaller($messages);
    }

    /**
     * Lance la découverte au nom du fichier d'installation, et rend le résultat au format de createFromInstaller().
     *
     * C'est le geste qui manquait : sans lui le raccordement restait « configuré » et personne ne scannait — il
     * fallait rouvrir l'assistant pour un clic, alors que le technicien était déjà reparti. Le drapeau `triggered`
     * décide ensuite si le serveur demande au PC de réveiller son agent.
     *
     * @return array ['ok' => bool, 'id' => int, 'messages' => string[], 'triggered' => bool]
     */
    private function launchFromInstaller(array $messages): array {
        // apply() vient d'écrire les tâches créées dans le raccordement : on relit avant de les lire.
        if (!$this->getFromDB((int) $this->getID())) {
            return ['ok' => false, 'id' => (int) $this->getID(), 'messages' => $messages, 'triggered' => false];
        }
        $lancement = PluginPrintgestionCollectsetup::trigger($this);
        foreach ($lancement['events'] as [$level, $message]) {
            $this->addLog(4, $level, $message);
            $messages[] = $message;
        }
        if ($lancement['ok']) {
            $this->addLog(4, 'success', __('Étape 4 validée : découverte lancée depuis le fichier d\'installation.', 'printgestion'));
        }
        return ['ok' => $lancement['ok'], 'id' => (int) $this->getID(), 'messages' => $messages, 'triggered' => $lancement['ok']];
    }

    /** Description de la tâche automatique, dans l'écran des actions automatiques de GLPI. */
    public static function cronInfo($name) {
        return ['description' => __('Print Gestion : fait avancer les raccordements lancés (découverte terminée → relevé des niveaux)', 'printgestion')];
    }

    /**
     * Tâche automatique : reprend chaque raccordement lancé et fait le point, comme le ferait un technicien qui
     * ouvre son écran.
     *
     * C'est le chaînon qui manquait. Préparer le relevé des niveaux après la découverte n'arrivait que si
     * quelqu'un regardait : l'écran du raccordement, ou la fenêtre d'installation pendant ses quatre minutes de
     * surveillance. Une découverte un peu longue sur un /24, un technicien déjà reparti, et le raccordement
     * restait figé sur « en attente » avec tout de prêt.
     *
     * Bornes : seulement les raccordements **lancés**, et pendant **vingt-quatre heures** après le lancement —
     * pas les trente minutes de l'écran. Une sonde appelle GLPI au moins une fois par jour : la découverte d'un
     * /24 peut donc aboutir bien après que l'écran a cessé de vérifier. Au-delà d'une journée, c'est une affaire
     * d'humain : le raccordement s'abandonne ou se relance à la main.
     */
    public static function cronPrintgestionRaccordements($task = null) {
        global $DB;

        if (!PluginPrintgestionConfig::isFeatureEnabled('deploiement') || !PluginPrintgestionCollectsetup::isAvailable()) {
            return 0;
        }
        $limite = date('Y-m-d H:i:s', time() - DAY_TIMESTAMP);
        $faits  = 0;
        $racc   = new self();
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['status' => self::STATUS_TRIGGERED, ['date_triggered' => ['>=', $limite]]],
            'ORDER'  => ['date_triggered'],
            'LIMIT'  => 50,
        ]) as $ligne) {
            if (!$racc->getFromDB((int) $ligne['id'])) {
                continue;
            }
            $avant = $racc->getResultCounts();
            foreach (PluginPrintgestionCollectsetup::verify($racc)['events'] as [$niveau, $message]) {
                $racc->addLog(4, $niveau, $message);
            }
            $faits++;
            if ($task instanceof CronTask && $racc->getResultCounts() !== $avant) {
                $task->log(sprintf('Raccordement %d : état mis à jour.', (int) $ligne['id']));
            }
        }
        if ($task instanceof CronTask) {
            $task->addVolume($faits);
        }
        return $faits > 0 ? 1 : 0;
    }

    public static function processAction(array $post, Entity $entity, ?self $racc): string {
        $entities_id = (int) $entity->getID();
        // L'etape d'ou vient le formulaire : sans elle, enregistrer a l'etape 5 renverrait a l'etape 4.
        $vue         = (int) ($post['step'] ?? 0);
        $back        = $racc !== null ? self::getPageURL((int) $racc->getID(), null, $vue) : self::getPageURL(null, $entities_id);
        $status      = $racc !== null ? (string) $racc->fields['status'] : '';
        $flash       = static function (string $level, string $message): void {
            $types = ['success' => INFO, 'info' => INFO, 'warning' => WARNING, 'error' => ERROR];
            Session::addMessageAfterRedirect(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), false, $types[$level] ?? INFO);
        };
        $report      = static function (int $step, array $events) use ($racc, $flash): void {
            foreach ($events as [$level, $message]) {
                $racc?->addLog($step, $level, $message);
                $flash($level, $message);
            }
        };

        // Prérequis de GLPI Inventory manquants : l'assistant est arrêté, seul l'abandon reste possible.
        $prerequisites = PluginPrintgestionCollectsetup::getPrerequisites()['blocking'];
        if (!empty($prerequisites) && !isset($post['abandon'])) {
            $message = sprintf(__('Action refusée, rien n\'est fait : %s', 'printgestion'), implode(' ', $prerequisites));
            if ($racc !== null) {
                $report($racc->getCurrentStep(), [['error', $message]]);
            } else {
                $flash('error', $message);
            }
            return $back;
        }

        if (isset($post['request_status'])) {
            $agent = self::findEntityAgent($entities_id, $racc !== null ? (int) $racc->fields['agents_id'] : (int) ($post['agents_id'] ?? 0));
            if ($agent === null) {
                $flash('error', __('Sonde introuvable dans cette entité.', 'printgestion'));
            } else {
                $report(1, [PluginPrintgestionCollectsetup::requestStatus($agent)]);
            }
            return $back;
        }

        if (isset($post['start'])) {
            $result = self::start($entity, (int) ($post['agents_id'] ?? 0));
            foreach ($result['messages'] as [$level, $message]) {
                $flash($level, $message);
            }
            return $result['id'] > 0 ? self::getPageURL($result['id']) : $back;
        }

        if ($racc === null) {
            $flash('error', __('Raccordement introuvable.', 'printgestion'));
            return $back;
        }

        if (isset($post['save_ips'])) {
            if ($status !== self::STATUS_OPEN) {
                $flash('error', __('Adresses figées : la configuration de collecte est déjà créée, ou le raccordement est clos.', 'printgestion'));
                return $back;
            }
            $parsed = self::parseIps((string) ($post['ips'] ?? ''));
            $errors = $parsed['errors'];
            if (empty($errors) && empty($parsed['ips'])) {
                $errors[] = __('Aucune adresse saisie.', 'printgestion');
            }
            if (!empty($errors)) {
                $report(2, [['error', sprintf(__('Adresses refusées, rien n\'est enregistré : %s', 'printgestion'), implode(' ', $errors))]]);
                return $back;
            }
            $kept = PluginPrintgestionRaccordementdetail::snapshot($racc);
            if (!$racc->replaceIps($parsed['ips'])) {
                $report(2, [['error', __('Adresses non enregistrées : erreur de base de données (voir le journal PHP de GLPI).', 'printgestion')]]);
                return $back;
            }
            // Lieu, commentaire et contrat déjà déclarés : gardés pour les adresses qui restent.
            PluginPrintgestionRaccordementdetail::restore($racc, $kept);
            $count = count($parsed['ips']);
            $report(2, [['success', sprintf(
                _n('Étape 2 validée : %1$d adresse déclarée (%2$s), en attente jusqu\'à la configuration.', 'Étape 2 validée : %1$d adresses déclarées (%2$s), en attente jusqu\'à la configuration.', $count, 'printgestion'),
                $count,
                self::summarizeIps(array_keys($parsed['ips']))
            )]]);
            return $back;
        }

        if (isset($post['save_details'])) {
            if ($status === self::STATUS_ABANDONED) {
                $flash('error', __('Raccordement abandonné : plus rien n\'y est enregistré.', 'printgestion'));
                return $back;
            }
            $report(2, PluginPrintgestionRaccordementdetail::save($racc, $post)['events']);
            return $back;
        }

        if (isset($post['apply_details'])) {
            if (!in_array($status, [self::STATUS_TRIGGERED, self::STATUS_CLOSED], true)) {
                $flash('error', __('Étape 5 impossible : la découverte n\'est pas encore lancée et vérifiée.', 'printgestion'));
                return $back;
            }
            $report(5, PluginPrintgestionRaccordementdetail::apply($racc)['events']);
            return $back;
        }

        if (isset($post['configure'])) {
            if ($status !== self::STATUS_OPEN) {
                $flash('error', __('La configuration de collecte est déjà créée, ou le raccordement est clos.', 'printgestion'));
                return $back;
            }
            $agent_errors = $racc->getAgentBlocking($entity);
            if (!empty($agent_errors)) {
                $report(3, [['error', sprintf(__('Configuration refusée, la sonde ne remplit plus les conditions de l\'étape 1 : %s', 'printgestion'), implode(' ', $agent_errors))]]);
                return $back;
            }
            $plan = PluginPrintgestionCollectsetup::plan($racc, $post);
            if (!empty($plan['errors'])) {
                $report(3, array_map(static fn(string $error): array => ['error', $error], $plan['errors']));
                $report(3, [['error', __('Configuration refusée : rien n\'a été créé.', 'printgestion')]]);
                return $back;
            }
            $report(3, array_map(static fn(string $note): array => ['info', $note], $plan['notes']));
            $result = PluginPrintgestionCollectsetup::apply($racc, $plan);
            $report(3, $result['events']);
            if ($result['ok']) {
                $report(3, [['success', __('Étape 3 validée : configuration de collecte en place.', 'printgestion')]]);
                $report(3, [['info', PluginPrintgestionCollectfrequency::getJournalLine($entities_id)]]);
            }
            return $back;
        }

        if (isset($post['trigger'])) {
            if (!in_array($status, [self::STATUS_CONFIGURED, self::STATUS_TRIGGERED], true)) {
                $flash('error', __('Découverte impossible : la configuration de collecte n\'est pas créée, ou le raccordement est clos.', 'printgestion'));
                return $back;
            }
            $agent_errors = $racc->getAgentBlocking($entity);
            if (!empty($agent_errors)) {
                $report(4, [['error', sprintf(__('Découverte refusée, la sonde ne remplit plus les conditions de l\'étape 1 : %s', 'printgestion'), implode(' ', $agent_errors))]]);
                return $back;
            }
            try {
                $report(4, PluginPrintgestionCollectsetup::trigger($racc)['events']);
            } catch (RuntimeException $e) {
                // Garde-fou de la fréquence des relevés (structure de GLPI Inventory) : erreur franche, pas de page cassée.
                $flash('error', $e->getMessage());
            }
            return $back;
        }

        if (isset($post['adopt_printer'])) {
            // Une imprimante qui a répondu mais dont la fiche est ailleurs : on la ramène ici — restaurée si elle
            // était à la corbeille, puis rattachée à l'entité du raccordement. Par les classes natives, donc avec
            // l'historique de GLPI et les contrôles de ses hooks.
            $printers_id = (int) ($post['printers_id'] ?? 0);
            $entities_id = (int) $racc->fields['entities_id'];
            $printer     = new Printer();
            $connue      = $printers_id > 0 && countElementsInTable(self::IPS_TABLE, [
                'plugin_printgestion_raccordements_id' => (int) $racc->getID(),
                'itemtype'                             => Printer::class,
                'items_id'                             => $printers_id,
            ]) > 0;
            if (!$connue || !$printer->getFromDB($printers_id)) {
                // Jamais sur une imprimante que ce raccordement n'a pas trouvée : l'identifiant vient du formulaire.
                $flash('error', __('Imprimante inconnue de ce raccordement.', 'printgestion'));
                return $back;
            }
            $nom     = (string) $printer->fields['name'];
            $depuis  = Dropdown::getDropdownName('glpi_entities', (int) $printer->fields['entities_id']);
            $restaure = (int) $printer->fields['is_deleted'] === 1 ? $printer->restore(['id' => $printers_id]) : true;
            $deplace  = (int) $printer->fields['entities_id'] === $entities_id
                || $printer->update(['id' => $printers_id, 'entities_id' => $entities_id]);
            if (!$restaure || !$deplace) {
                PluginPrintgestionLogger::error('raccordement', sprintf('Imprimante %d non ramenée dans l\'entité %d.', $printers_id, $entities_id));
                $flash('error', __('GLPI a refusé de ramener cette imprimante : voir le journal Print Gestion.', 'printgestion'));
                return $back;
            }
            $racc->addLog(4, 'success', sprintf(
                __('Imprimante « %1$s » ramenée ici depuis « %2$s »%3$s.', 'printgestion'),
                $nom,
                $depuis,
                (int) $printer->fields['is_deleted'] === 1 ? __(' (elle était à la corbeille)', 'printgestion') : ''
            ));
            // Le relevé peut maintenant la prendre pour cible : on refait le point tout de suite.
            foreach ((PluginPrintgestionCollectsetup::verify($racc)['events'] ?? []) as [$level, $message]) {
                $racc->addLog(4, $level, $message);
            }
            $flash('success', sprintf(__('Imprimante « %s » ramenée dans cette entité.', 'printgestion'), $nom));
            return $back;
        }

        if (isset($post['clear_logs'])) {
            /** @var \DBmysql $DB */
            global $DB;

            // Le journal sert à comprendre ce qui s'est passé ; une fois compris, il encombre. On le vide, et on
            // le dit : la première ligne du nouveau journal est ce geste-là.
            $DB->delete(self::LOGS_TABLE, ['plugin_printgestion_raccordements_id' => (int) $racc->getID()]);
            $racc->addLog($racc->getCurrentStep(), 'info', sprintf(
                __('Journal vidé par %s.', 'printgestion'),
                getUserName((int) Session::getLoginUserID())
            ));
            $flash('info', __('Journal du raccordement vidé.', 'printgestion'));
            return $back;
        }

        if (isset($post['verify'])) {
            if ($status !== self::STATUS_TRIGGERED) {
                $flash('error', __('Rien à vérifier : la découverte n\'est pas lancée, ou le raccordement est clos.', 'printgestion'));
                return $back;
            }
            $before = $racc->getResultCounts();
            try {
                $report(4, PluginPrintgestionCollectsetup::verify($racc)['events']);
            } catch (RuntimeException $e) {
                $flash('error', $e->getMessage());
                return $back;
            }
            $after   = $racc->getResultCounts();
            $summary = sprintf(__('Vérification : %s.', 'printgestion'), self::formatCounts($after));
            if ($after != $before) {
                $level = !empty($after['wrong_entity']) ? 'error' : (($after['found'] ?? 0) === array_sum($after) ? 'success' : 'info');
                $racc->addLog(4, $level, $summary);
            }
            if (empty($post['auto'])) {
                $flash('info', $summary);
            }
            return $back;
        }

        if (isset($post['close'])) {
            if ($status !== self::STATUS_TRIGGERED) {
                $flash('error', __('Seul un raccordement dont la découverte est lancée peut être terminé.', 'printgestion'));
                return $back;
            }
            if (!$racc->update(['id' => (int) $racc->getID(), 'status' => self::STATUS_CLOSED])) {
                PluginPrintgestionLogger::error('raccordement', sprintf('Raccordement #%d : passage au statut terminé refusé.', $racc->getID()));
                $flash('error', __('Raccordement non terminé : la mise à jour a été refusée (voir le journal Print Gestion).', 'printgestion'));
                return $back;
            }
            $report(4, [['success', sprintf(__('Raccordement terminé. %s.', 'printgestion'), self::formatCounts($racc->getResultCounts()))]]);
            return $back;
        }

        if (isset($post['abandon'])) {
            if (!in_array($status, [self::STATUS_OPEN, self::STATUS_CONFIGURED, self::STATUS_TRIGGERED], true)) {
                $flash('error', __('Raccordement déjà clos.', 'printgestion'));
                return $back;
            }
            $step = $racc->getCurrentStep();
            if (!$racc->update(['id' => (int) $racc->getID(), 'status' => self::STATUS_ABANDONED])) {
                PluginPrintgestionLogger::error('raccordement', sprintf('Raccordement #%d : passage au statut abandonné refusé.', $racc->getID()));
                $flash('error', __('Raccordement non abandonné : la mise à jour a été refusée (voir le journal Print Gestion).', 'printgestion'));
                return $back;
            }
            $created = importArrayFromDB((string) $racc->fields['created_items']);
            $names   = [
                SNMPCredential::class                       => __('identifiants SNMP', 'printgestion'),
                'PluginGlpiinventoryIPRange'                => __('plage IP', 'printgestion'),
                'PluginGlpiinventoryIPRange_SNMPCredential' => __('liaison plage ↔ identifiants SNMP', 'printgestion'),
                'PluginGlpiinventoryTask'                   => __('tâche', 'printgestion'),
                'PluginGlpiinventoryTaskjob'                => __('job de tâche', 'printgestion'),
            ];
            $list    = [];
            foreach (is_array($created) ? $created : [] as $itemtype => $ids) {
                $list[] = sprintf('%s n° %s', $names[$itemtype] ?? $itemtype, implode(', ', array_map('intval', (array) $ids)));
            }
            $report($step, [['warning', empty($list)
                ? __('Raccordement abandonné. Rien n\'avait été créé côté serveur.', 'printgestion')
                : sprintf(__('Raccordement abandonné. Rien n\'est supprimé : les objets de collecte créés côté serveur restent en place (%s) ; l\'administrateur désactive la tâche si la collecte ne doit pas avoir lieu.', 'printgestion'), implode(' ; ', $list))]]);
            return $back;
        }

        $flash('error', __('Action inconnue.', 'printgestion'));
        return $back;
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    /** Assistant : un raccordement, ou l'étape 1 d'un nouveau raccordement pour l'entité. */
    public static function showWizard(Entity $entity, ?self $racc): void {
        $esc         = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $entities_id = (int) $entity->getID();
        $can_edit    = Session::haveRight(self::$rightname, UPDATE);

        echo "<div class='card mb-3'><div class='card-body d-flex flex-wrap align-items-center gap-3'>";
        echo "<h2 class='mb-0'>" . $esc($racc !== null ? sprintf(__('Raccordement n° %d', 'printgestion'), (int) $racc->getID()) : __('Nouveau raccordement', 'printgestion')) . "</h2>";
        echo "<span class='fs-3'>" . $esc($entity->fields['completename'] ?? $entity->fields['name']) . "</span>";
        if ($racc !== null) {
            [$label, $class] = self::getStatusLabels()[$racc->fields['status']] ?? [$racc->fields['status'], 'bg-secondary'];
            echo "<span class='badge {$class}'>" . $esc($label) . "</span>";
            echo "<span class='text-muted small'>" . $esc(sprintf(__('Créé le %1$s par %2$s', 'printgestion'), Html::convDateTime((string) $racc->fields['date_creation']), getUserName((int) $racc->fields['users_id']))) . "</span>";
        }
        echo "<div class='ms-auto d-flex flex-wrap gap-2'>";
        echo "<a class='btn btn-outline-secondary' href='" . $esc(Entity::getFormURLWithID($entities_id) . '&forcetab=' . urlencode('PluginPrintgestionAgentdeploy$1')) . "'><i class='ti ti-building me-1'></i>" . $esc(__('Onglet Déploiement Agent de l\'entité', 'printgestion')) . "</a>";
        echo "<a class='btn btn-outline-secondary' href='" . $esc(self::getPageURL()) . "'><i class='ti ti-list me-1'></i>" . $esc(__('Tous les raccordements', 'printgestion')) . "</a>";
        echo "</div></div></div>";

        // Prérequis de GLPI Inventory, tous vérifiés avant la première étape : s'il en manque un, l'assistant
        // s'arrête ici ; un raccordement existant garde son journal et son abandon.
        $prerequisites = PluginPrintgestionCollectsetup::getPrerequisites();
        self::showPrerequisites($prerequisites, true);
        if (!empty($prerequisites['blocking'])) {
            if ($racc !== null) {
                $racc->showLogs($can_edit);
                $racc->showAbandonForm($can_edit);
            }
            return;
        }

        // Pas encore de raccordement : il n'y a qu'une chose à faire, choisir la sonde.
        if ($racc === null) {
            self::showStep1($entity, null, $can_edit);
            return;
        }

        // Étape demandée par la page, bornée par ce que l'état des données permet d'ouvrir. Hors bornes (lien
        // vieilli, adresse tapée à la main), on retombe sur celle où il reste quelque chose à faire.
        $reachable  = $racc->getReachableStep();
        $asked      = (int) ($_GET['step'] ?? 0);
        $step       = ($asked >= 1 && $asked <= $reachable) ? $asked : $racc->getCurrentStep();
        self::$view = $step;

        self::showStepBar($racc, $step, $reachable);
        $racc->showRecap();

        if ($step === 1) {
            self::showStep1($entity, $racc, $can_edit);
        } elseif ($step === 2) {
            $racc->showStep2($can_edit);
        } elseif ($step === 3) {
            PluginPrintgestionRaccordementdetail::showDetails($racc, $can_edit);
        } elseif ($step === 5) {
            PluginPrintgestionRaccordementdetail::showStep5($racc, $can_edit);
        } else {
            // Étape 4 : configurer la collecte, puis lancer la découverte. Les deux tiennent dans la même étape
            // parce qu'il n'y a aucune décision à prendre entre elles : une fois la configuration créée, la seule
            // suite possible est de lancer la découverte.
            $racc->showStep3($can_edit);
            if ($racc->fields['status'] !== self::STATUS_OPEN) {
                $racc->showStep4($can_edit);
            }
        }

        // « Suivant » : l'étape d'après quand elle est ouvrable. Sans ce bouton, il faudrait deviner que la barre
        // du haut est cliquable.
        if ($step < $reachable) {
            echo "<div class='text-end mb-3'><a class='btn btn-primary' href='" . $esc(self::getPageURL((int) $racc->getID(), null, $step + 1)) . "'>"
                . $esc(self::getStepLabels()[$step + 1]) . "<i class='ti ti-arrow-right ms-1'></i></a></div>";
        }

        // Le journal avant l'abandon : on lit ce qui s'est passé bien plus souvent qu'on n'abandonne, et le
        // bouton rouge n'a rien à faire entre les deux.
        $racc->showLogs($can_edit);
        $racc->showAbandonForm($can_edit);
    }

    /** Les cinq étapes, dans l'ordre : un seul endroit où elles sont nommées. */
    public static function getStepLabels(): array {
        return [
            1 => __('Sonde', 'printgestion'),
            2 => __('Adresses des imprimantes', 'printgestion'),
            3 => __('Lieu, commentaire, contrat', 'printgestion'),
            4 => __('Configuration et découverte', 'printgestion'),
            5 => __('Application aux imprimantes', 'printgestion'),
        ];
    }

    /**
     * La barre 1 -> 5, qui est aussi la navigation : les étapes ouvrables sont des liens, les autres des libellés
     * éteints. C'est ce qui permet de revenir corriger un oubli sans tout réafficher.
     */
    private static function showStepBar(self $racc, int $step, int $reachable): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        echo "<ul class='steps steps-counter steps-blue mb-3'>";
        foreach (self::getStepLabels() as $number => $label) {
            $classes = 'step-item' . ($number === $step ? ' active' : '');
            if ($number <= $reachable) {
                echo "<li class='{$classes}'><a href='" . $esc(self::getPageURL((int) $racc->getID(), null, $number)) . "'>" . $esc($label) . "</a></li>";
            } else {
                echo "<li class='{$classes}'><span class='text-muted'>" . $esc($label) . "</span></li>";
            }
        }
        echo "</ul>";
    }

    /**
     * Une ligne de rappel de ce qui est déjà décidé : sonde et adresses.
     *
     * Une seule étape étant visible, ces deux faits ne sont plus à l'écran quand on travaille plus loin — et ce
     * sont justement ceux dont dépend tout le reste.
     */
    private function showRecap(): void {
        $esc   = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $parts = [];
        $agent = new Agent();
        if ($agent->getFromDB((int) $this->fields['agents_id'])) {
            $parts[] = "<i class='ti ti-device-desktop me-1'></i>" . $esc($agent->fields['name']);
        }
        $longs = array_map(static fn(array $row): int => (int) $row['ip_num'], $this->getIps());
        if (!empty($longs)) {
            $parts[] = "<i class='ti ti-printer me-1'></i>" . $esc(sprintf(
                _n('%1$d adresse : %2$s', '%1$d adresses : %2$s', count($longs), 'printgestion'),
                count($longs),
                self::summarizeIps($longs)
            ));
        }
        if (empty($parts)) {
            return;
        }
        echo "<div class='text-muted small mb-3 d-flex flex-wrap gap-3'><span>" . implode("</span><span>", $parts) . "</span></div>";
    }

    /**
     * Prérequis de GLPI Inventory : ce qui arrête l'assistant, ce qui est à vérifier ; $show_ok : une ligne
     * discrète quand tout est réglé (en tête de l'assistant).
     */
    public static function showPrerequisites(array $prerequisites, bool $show_ok): void {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $list = static fn(array $messages): string => "<ul class='mb-0'>" . implode('', array_map(static fn($m) => '<li>' . $esc($m) . '</li>', $messages)) . "</ul>";
        if (!empty($prerequisites['blocking'])) {
            // Technicien : l'état seul ; administrateur : la cause et où agir, dépliables.
            echo PluginPrintgestionUi::statusLine('error', __('Raccordement indisponible — contactez l\'administrateur', 'printgestion'), $list($prerequisites['blocking']));
        }
        if (!empty($prerequisites['warnings']) && PluginPrintgestionUi::isAdmin()) {
            echo PluginPrintgestionUi::statusLine('warning', __('Raccordement possible, points à vérifier', 'printgestion'), $list($prerequisites['warnings']));
        }
        if ($show_ok && empty($prerequisites['blocking']) && PluginPrintgestionUi::isAdmin()) {
            echo "<div data-pg-admin='1'>" . PluginPrintgestionUi::statusLine('ok', sprintf(__('Prérequis vérifiés (GLPI Inventory %s)', 'printgestion'), $prerequisites['version'])) . "</div>";
        }
    }

    /** « Abandonner le raccordement » : raccordement en cours, droit en modification. */
    private function showAbandonForm(bool $can_edit): void {
        if (!$can_edit || !in_array($this->fields['status'], [self::STATUS_OPEN, self::STATUS_CONFIGURED, self::STATUS_TRIGGERED], true)) {
            return;
        }
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $confirm = __('Abandonner ce raccordement ? Rien n\'est supprimé : le journal est gardé et la configuration de collecte déjà créée reste en place.', 'printgestion');
        echo "<form method='post' action='" . $esc(self::getPageURL()) . "' class='mb-3 text-end'>"
            . self::stepField() . Html::hidden('id', ['value' => (int) $this->getID()])
            . "<button type='submit' name='abandon' value='1' class='btn btn-outline-danger' onclick=\"return confirm(" . $esc(json_encode($confirm)) . ");\">"
            . "<i class='ti ti-player-stop me-1'></i>" . $esc(__('Abandonner le raccordement', 'printgestion')) . "</button>"
            . Html::closeForm(false);
    }

    private static function showStep1(Entity $entity, ?self $racc, bool $can_edit): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Sonde', 'printgestion')) . "</h3></div><div class='card-body'>";

        if ($racc !== null) {
            $agent = new Agent();
            if (!$agent->getFromDB((int) $racc->fields['agents_id'])) {
                echo "<div class='alert alert-danger mb-0'>" . $esc(__('La sonde de ce raccordement n\'existe plus dans GLPI.', 'printgestion')) . "</div>";
            } else {
                $open = !in_array($racc->fields['status'], [self::STATUS_CLOSED, self::STATUS_ABANDONED], true);
                self::showAgentTable($entity, [$agent->fields], $can_edit && $open, $racc, false);
            }
            echo "</div></div>";
            return;
        }

        $blockers = self::getBlockers($entity);
        if (!empty($blockers)) {
            echo "<div class='alert alert-danger'><strong>" . $esc(__('Raccordement impossible tant que ces points ne sont pas réglés :', 'printgestion')) . "</strong><ul class='mb-0'>";
            foreach ($blockers as $blocker) {
                echo "<li>" . $esc($blocker) . "</li>";
            }
            echo "</ul></div>";
        }
        $agents = PluginPrintgestionAgentdeploy::getEntityAgents((int) $entity->getID());
        if (empty($agents)) {
            echo "<p class='mb-0'>" . $esc(__('Aucune sonde dans cette entité. Installez GLPI Agent sur un PC du client avec le paquet de l\'onglet Déploiement Agent, puis revenez ici après son premier contact (quelques minutes).', 'printgestion')) . "</p>";
        } else {
            echo "<p class='text-muted'>" . $esc(__('Choisissez le PC du client qui interrogera les imprimantes : contact récent avec GLPI, découverte et inventaire réseau installés, TAG de cette entité.', 'printgestion')) . "</p>";
            self::showAgentTable($entity, $agents, $can_edit, null, empty($blockers));
        }
        echo "</div></div>";
    }

    /** Sondes et leurs conditions ; « Demander le statut », et « Raccorder avec cette sonde » pour un nouveau raccordement. */
    private static function showAgentTable(Entity $entity, array $agents, bool $can_edit, ?self $racc, bool $can_start): void {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $admin   = PluginPrintgestionUi::isAdmin();
        $contact = [
            'none'   => ['bg-red text-red-fg', __('Aucun', 'printgestion')],
            'silent' => ['bg-red text-red-fg', __('Muette', 'printgestion')],
            'old'    => ['bg-orange text-orange-fg', __('Pas dans l\'heure', 'printgestion')],
            'recent' => ['bg-green text-green-fg', __('Récent', 'printgestion')],
        ];
        echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0'><thead><tr>"
            . "<th>" . $esc(__('Sonde', 'printgestion')) . "</th><th>" . $esc(__('Poste', 'printgestion')) . "</th>"
            . ($admin ? "<th data-pg-admin='1'>" . $esc(__('Version', 'printgestion')) . "</th>" : '') . "<th>" . $esc(__('Dernier contact', 'printgestion')) . "</th>"
            . ($admin ? "<th data-pg-admin='1'>" . $esc(__('TAG', 'printgestion')) . "</th>" : '') . "<th>" . $esc(__('Conditions', 'printgestion')) . "</th><th></th></tr></thead><tbody>";
        foreach ($agents as $agent) {
            $check           = self::checkAgent($entity, $agent);
            [$class, $label] = $contact[$check['contact']];
            $agent_tag       = trim((string) ($agent['tag'] ?? ''));
            echo "<tr><td><a href='" . $esc(Agent::getFormURLWithID((int) $agent['id'])) . "'>" . $esc($agent['name']) . "</a></td>"
                . "<td>" . self::getHostHtml($agent) . "</td>"
                . ($admin ? "<td data-pg-admin='1'>" . $esc(self::getAgentVersion($agent) ?: '—') . "</td>" : '')
                . "<td class='text-nowrap'>" . $esc(empty($agent['last_contact']) ? '—' : Html::convDateTime((string) $agent['last_contact'])) . " <span class='badge {$class}'>" . $esc($label) . "</span></td>"
                . ($admin ? "<td data-pg-admin='1'>" . ($agent_tag !== '' ? "<code>" . $esc($agent_tag) . "</code>" : '—') . "</td>" : '') . "<td class='small'>";
            if (empty($check['blocking']) && empty($check['warnings'])) {
                echo "<span class='text-success'><i class='ti ti-circle-check me-1'></i>" . $esc(__('Prête', 'printgestion')) . "</span>";
            }
            foreach ($check['blocking'] as $message) {
                echo "<div class='text-danger'><i class='ti ti-alert-octagon me-1'></i>" . $esc($message) . "</div>";
            }
            foreach ($check['warnings'] as $message) {
                echo "<div class='text-warning'><i class='ti ti-alert-triangle me-1'></i>" . $esc($message) . "</div>";
            }
            echo "</td><td class='text-end text-nowrap'>";
            if ($can_edit) {
                echo "<form method='post' action='" . $esc(self::getPageURL()) . "' class='d-inline-flex gap-1'>";
                echo $racc !== null
                    ? self::stepField() . Html::hidden('id', ['value' => (int) $racc->getID()])
                    : Html::hidden('entities_id', ['value' => (int) $entity->getID()]) . Html::hidden('agents_id', ['value' => (int) $agent['id']]);
                echo "<button type='submit' name='request_status' value='1' class='btn btn-sm btn-outline-secondary'><i class='ti ti-refresh me-1'></i>" . $esc(__('Demander le statut', 'printgestion')) . "</button>";
                if ($racc === null) {
                    $ready = $can_start && empty($check['blocking']);
                    echo "<button type='submit' name='start' value='1' class='btn btn-sm btn-primary'" . ($ready ? '' : ' disabled') . "><i class='ti ti-player-play me-1'></i>" . $esc(__('Raccorder avec cette sonde', 'printgestion')) . "</button>";
                }
                echo Html::closeForm(false);
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
    }

    private function showStep2(bool $can_edit): void {
        $esc      = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $longs    = array_map(static fn(array $row): int => (int) $row['ip_num'], $this->getIps());
        $editable = $can_edit && $this->fields['status'] === self::STATUS_OPEN;
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Adresses des imprimantes', 'printgestion')) . "</h3></div><div class='card-body'>";
        if (!empty($longs)) {
            echo "<p>" . $esc(sprintf(_n('%1$d adresse déclarée : %2$s', '%1$d adresses déclarées : %2$s', count($longs), 'printgestion'), count($longs), self::summarizeIps($longs))) . "</p>";
        }
        if ($editable) {
            echo "<p class='text-muted small'>" . $esc(__('Rien n\'est appliqué aux imprimantes à cette étape : les adresses restent en attente jusqu\'à la création de la configuration (étape 4).', 'printgestion')) . "</p>";
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "'>" . self::stepField() . Html::hidden('id', ['value' => (int) $this->getID()]);
            echo "<label class='form-label' for='pg-racc-ips'>" . $esc(__('Adresses IP des imprimantes', 'printgestion')) . "</label>";
            echo "<textarea class='form-control font-monospace' id='pg-racc-ips' name='ips' rows='6' placeholder='192.168.1.20&#10;192.168.1.30-35&#10;192.168.1.0/24'>"
                . $esc(self::summarizeIps($longs, '-', "\n")) . "</textarea>";
            echo "<div class='form-hint'>" . $esc(sprintf(__('Une adresse par ligne, ou séparées par des virgules. Plage : 192.168.1.30-192.168.1.35 ou 192.168.1.30-35. Réseau : 192.168.1.0/24 (de /22 à /32). %d adresses au plus.', 'printgestion'), self::MAX_IPS)) . "</div>";
            echo "<button type='submit' name='save_ips' value='1' class='btn btn-primary mt-2'><i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer les adresses', 'printgestion')) . "</button>";
            echo Html::closeForm(false);
        } elseif (empty($longs)) {
            echo "<p class='text-muted mb-0'>" . $esc(__('Aucune adresse déclarée.', 'printgestion')) . "</p>";
        }
        echo "</div></div>";
    }

    /** Identifiants proposés : déjà liés à une plage de l'entité, sinon premiers v2c, sinon premiers. */
    private function getDefaultCredentialId(array $credentials): int {
        global $DB;

        if (empty($credentials)) {
            return 0;
        }
        if (PluginPrintgestionCollectsetup::isAvailable()) {
            $row = $DB->request([
                'SELECT'     => ['l.snmpcredentials_id'],
                'FROM'       => PluginGlpiinventoryIPRange_SNMPCredential::getTable() . ' AS l',
                'INNER JOIN' => [
                    PluginGlpiinventoryIPRange::getTable() . ' AS r' => ['ON' => ['l' => 'plugin_glpiinventory_ipranges_id', 'r' => 'id']],
                ],
                'WHERE'      => ['r.entities_id' => (int) $this->fields['entities_id'], 'l.snmpcredentials_id' => array_keys($credentials)],
                'ORDER'      => ['l.rank'],
                'LIMIT'      => 1,
            ])->current();
            if (is_array($row)) {
                return (int) $row['snmpcredentials_id'];
            }
        }
        foreach ($credentials as $id => $credential) {
            if ((string) $credential['snmpversion'] === '2') {
                return (int) $id;
            }
        }
        return (int) array_key_first($credentials);
    }

    private function showStep3(bool $can_edit): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Configuration de la collecte', 'printgestion')) . "</h3></div><div class='card-body'>";
        if ($this->fields['status'] !== self::STATUS_OPEN) {
            // Technicien : l'état ; administrateur : les objets créés ou réutilisés, repliés.
            ob_start();
            $this->showConfiguration();
            $objects = (string) ob_get_clean();
            echo PluginPrintgestionUi::statusLine('ok', empty($this->fields['date_configured'])
                ? __('Collecte configurée', 'printgestion')
                : sprintf(__('Collecte configurée le %s', 'printgestion'), Html::convDateTime((string) $this->fields['date_configured'])));
            echo PluginPrintgestionUi::adminDetails(__('Objets créés ou réutilisés', 'printgestion'), $objects);
            echo "</div></div>";
            return;
        }

        $credentials = PluginPrintgestionCollectsetup::getCredentials();
        $default     = $this->getDefaultCredentialId($credentials);
        $plan        = PluginPrintgestionCollectsetup::plan($this, ['credential_mode' => 'existing', 'snmpcredentials_id' => $default]);
        echo "<p class='text-muted'>" . $esc(__('Ce que l\'assistant va faire avec les identifiants SNMP proposés. Tout est revérifié au moment de créer ; rien n\'est créé si une vérification échoue.', 'printgestion')) . "</p>";
        self::showPlan($plan);

        if ($can_edit) {
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "'>" . self::stepField() . Html::hidden('id', ['value' => (int) $this->getID()]);
            echo "<div class='row g-3'><div class='col-lg-6'><div class='form-check'>"
                . "<input class='form-check-input' type='radio' name='credential_mode' id='pg-cred-existing' value='existing'" . (empty($credentials) ? ' disabled' : ' checked') . ">"
                . "<label class='form-check-label fw-bold' for='pg-cred-existing'>" . $esc(__('Identifiants SNMP existants', 'printgestion')) . "</label></div>";
            echo "<select class='form-select mt-1' name='snmpcredentials_id'>";
            foreach ($credentials as $id => $credential) {
                echo "<option value='" . (int) $id . "'" . ((int) $id === $default ? ' selected' : '') . ">"
                    . $esc($credential['name'] . ' (' . PluginPrintgestionCollectsetup::getVersionLabel($credential['snmpversion']) . ')') . "</option>";
            }
            echo "</select></div>";
            echo "<div class='col-lg-6'><div class='form-check'>"
                . "<input class='form-check-input' type='radio' name='credential_mode' id='pg-cred-new' value='new'" . (empty($credentials) ? ' checked' : '') . ">"
                . "<label class='form-check-label fw-bold' for='pg-cred-new'>" . $esc(__('Nouveaux identifiants (SNMP v1 ou v2c)', 'printgestion')) . "</label></div>"
                . "<div class='row g-2 mt-1'>"
                . "<div class='col-sm-5'><input class='form-control' name='credential_name' maxlength='64' placeholder='" . $esc(__('Nom', 'printgestion')) . "'></div>"
                // Les deux versions par défaut : beaucoup d'imprimantes n'exposent que SNMPv1, et GLPI Inventory
                // essaie les identifiants d'une plage dans l'ordre jusqu'à ce que l'un réponde.
                . "<div class='col-sm-3'><select class='form-select' name='snmpversion'><option value='both'>" . $esc(__('v2c et v1', 'printgestion'))
                . "</option><option value='2'>v2c</option><option value='1'>v1</option></select></div>"
                . "<div class='col-sm-4'><input class='form-control' type='password' name='community' maxlength='255' autocomplete='new-password' placeholder='" . $esc(__('Communauté', 'printgestion')) . "'></div>"
                . "</div><div class='form-hint'>" . $esc(__('Réutilisés tels quels s\'ils existent déjà.', 'printgestion'))
                . (PluginPrintgestionUi::isAdmin() ? " <span data-pg-admin='1'>" . $esc(__('SNMP v3 : à créer dans GLPI (Configuration → Identifiants SNMP), puis à choisir ici.', 'printgestion')) . "</span>" : '')
                . "</div></div></div>";
            echo "<button type='submit' name='configure' value='1' class='btn btn-primary mt-3'><i class='ti ti-settings-automation me-1'></i>" . $esc(__('Créer la configuration de collecte', 'printgestion')) . "</button>";
            echo Html::closeForm(false);
        }
        echo "</div></div>";
    }

    /** Plan de l'étape 3 : existant réutilisé, objets à créer, et ce qui bloque. */
    private static function showPlan(array $plan): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        if (!empty($plan['errors'])) {
            echo "<div class='alert alert-danger'><strong>" . $esc(__('Création impossible en l\'état :', 'printgestion')) . "</strong><ul class='mb-0'>";
            foreach ($plan['errors'] as $error) {
                echo "<li>" . $esc($error) . "</li>";
            }
            echo "</ul></div>";
        }
        foreach ($plan['notes'] as $note) {
            echo "<div class='alert alert-info'>" . $esc($note) . "</div>";
        }
        $badges = [
            'create' => ['bg-blue text-blue-fg', __('À créer', 'printgestion')],
            'reuse'  => ['bg-secondary text-secondary-fg', __('Existant, réutilisé', 'printgestion')],
            'enable' => ['bg-blue text-blue-fg', __('À activer', 'printgestion')],
            'active' => ['bg-secondary text-secondary-fg', __('Déjà actif', 'printgestion')],
        ];
        $line = static function (string $state, string $text) use ($esc, $badges): void {
            [$class, $label] = $badges[$state];
            echo "<li class='mb-1'><span class='badge {$class} me-2'>" . $esc($label) . "</span>" . $esc($text) . "</li>";
        };
        echo "<ul class='list-unstyled mb-3'>";
        if ($plan['credential'] !== null) {
            $line($plan['credential']['action'], sprintf(__('Identifiants SNMP « %s »', 'printgestion'), $plan['credential']['name']));
        }
        foreach ($plan['ranges'] as $range) {
            $line($range['action'], sprintf(__('Plage IP « %1$s » (%2$s – %3$s), dans cette entité', 'printgestion'), $range['name'], $range['ip_start'], $range['ip_end']));
            $line(!empty($range['linked']) ? 'reuse' : 'create', sprintf(__('Liaison de la plage « %s » aux identifiants SNMP', 'printgestion'), $range['name']));
        }
        foreach ($plan['modules'] as $modulename => $active) {
            $line($active ? 'active' : 'enable', sprintf(__('Module %s pour la sonde', 'printgestion'), $modulename));
        }
        foreach ($plan['tasks'] as $method => $task_plan) {
            foreach ($task_plan['reuse'] as $task_name) {
                $line('reuse', sprintf(__('%1$s : tâche « %2$s »', 'printgestion'), PluginPrintgestionCollectsetup::getMethodLabel($method), $task_name));
            }
            if ($task_plan['create'] !== null) {
                $line('create', sprintf(__('%1$s : tâche « %2$s », active, pour cette sonde', 'printgestion'), PluginPrintgestionCollectsetup::getMethodLabel($method), $task_plan['create']['name']));
            }
        }
        echo "</ul>";
    }

    /** Configuration en place : liens vers les objets GLPI Inventory, créés ou réutilisés. */
    private function showConfiguration(): void {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $created = importArrayFromDB((string) $this->fields['created_items']);
        $created = is_array($created) ? $created : [];
        if (empty($this->fields['date_configured'])) {
            echo "<p class='text-muted mb-0'>" . $esc(__('Aucune configuration de collecte créée.', 'printgestion')) . "</p>";
            return;
        }
        $item_html = static function (string $itemtype, int $id) use ($esc, $created): string {
            $item   = class_exists($itemtype) ? getItemForItemtype($itemtype) : false;
            $is_new = in_array($id, array_map('intval', (array) ($created[$itemtype] ?? [])), true);
            $html   = $item instanceof CommonDBTM && $item->getFromDB($id)
                ? "<a href='" . $esc($item->getLinkURL()) . "'>" . $esc($item->getName()) . "</a>"
                : $esc(sprintf(__('n° %d (supprimé ou plugin inactif)', 'printgestion'), $id));
            return $html . " <span class='badge " . ($is_new ? 'bg-blue text-blue-fg' : 'bg-secondary text-secondary-fg') . "'>"
                . $esc($is_new ? __('créé', 'printgestion') : __('réutilisé', 'printgestion')) . "</span>";
        };
        echo "<p class='text-muted'>" . $esc(sprintf(__('Configuration créée le %s.', 'printgestion'), Html::convDateTime((string) $this->fields['date_configured']))) . "</p><ul class='mb-0'>";
        echo "<li>" . $esc(__('Identifiants SNMP :', 'printgestion')) . ' ' . $item_html(SNMPCredential::class, (int) $this->fields['snmpcredentials_id']) . "</li>";
        foreach (PluginPrintgestionCollectsetup::getIds($this->fields['ipranges']) as $range_id) {
            echo "<li>" . $esc(__('Plage IP :', 'printgestion')) . ' ' . $item_html(PluginPrintgestionCollectsetup::RANGE_TYPE, $range_id) . "</li>";
        }
        foreach (array_keys(PluginPrintgestionCollectsetup::METHODS) as $method) {
            foreach (PluginPrintgestionCollectsetup::getTaskIds($this, $method) as $tasks_id) {
                echo "<li>" . $esc(PluginPrintgestionCollectsetup::getMethodLabel($method) . ' :') . ' ' . $item_html('PluginGlpiinventoryTask', $tasks_id) . "</li>";
            }
        }
        echo "</ul>";
    }

    private function showStep4(bool $can_edit): void {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $status  = (string) $this->fields['status'];
        $id      = (int) $this->getID();
        $counts  = $this->getResultCounts();
        $waiting = !empty($counts['waiting_discovery']) || !empty($counts['waiting_inventory']) || !empty($counts['pending']);
        $running = in_array($status, [self::STATUS_CONFIGURED, self::STATUS_TRIGGERED], true);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Découverte', 'printgestion')) . "</h3></div><div class='card-body'>";

        if ($status === self::STATUS_CONFIGURED) {
            echo "<p>" . $esc(__('La collecte est configurée. Lancez la découverte : la sonde recevra sa consigne, et GLPI tentera de la réveiller.', 'printgestion')) . "</p>";
        }
        if (!empty($this->fields['date_triggered'])) {
            $this->showProgress($can_edit);
        }
        if ($can_edit && $running) {
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "' class='d-flex flex-wrap gap-2 mb-3'>" . self::stepField() . Html::hidden('id', ['value' => $id]);
            echo "<button type='submit' name='trigger' value='1' class='btn " . ($status === self::STATUS_CONFIGURED ? 'btn-primary' : 'btn-outline-primary') . "'><i class='ti ti-radar me-1'></i>"
                . $esc($status === self::STATUS_CONFIGURED ? __('Lancer la découverte', 'printgestion') : __('Relancer la découverte', 'printgestion')) . "</button>";
            if ($status === self::STATUS_TRIGGERED) {
                echo "<button type='submit' name='verify' value='1' class='btn btn-primary'><i class='ti ti-refresh me-1'></i>" . $esc(__('Vérifier maintenant', 'printgestion')) . "</button>";
                echo "<button type='submit' name='close' value='1' class='btn btn-success'><i class='ti ti-check me-1'></i>" . $esc(__('Terminer le raccordement', 'printgestion')) . "</button>";
            }
            echo Html::closeForm(false);
        }
        if ($running) {
            global $CFG_GLPI;

            // Ce qu'attend quelqu'un qui n'est pas devant le PC : une echeance, pas un mode d'emploi sur place.
            $agent = new Agent();
            $known = $agent->getFromDB((int) $this->fields['agents_id']);
            $port  = $known ? ((int) $agent->fields['port'] ?: Agent::DEFAULT_PORT) : Agent::DEFAULT_PORT;
            $note  = "<p class='mb-1'>" . $esc(sprintf(
                __('Sur le PC sonde, ouvrez http://127.0.0.1:%d dans un navigateur et cliquez « Force an Inventory » : la consigne part aussitôt, sans attendre l\'échéance.', 'printgestion'),
                $port
            )) . "</p><p class='mb-0 text-muted small'>"
                . $esc(__('Il faut deux passages : la découverte, puis le relevé des niveaux dès que l\'assistant l\'annonce.', 'printgestion')) . "</p>";
            if (PluginPrintgestionUi::isAdmin()) {
                $note .= "<hr class='my-2'><p class='mb-0 text-muted small'>" . $esc(__('L\'attente maximale est le réglage natif « Fréquence d\'inventaire (en heures) » : ', 'printgestion'))
                    . "<a href='" . $esc($CFG_GLPI['root_doc'] . '/front/inventory.conf.php') . "'>" . $esc(__('Administration → Inventaire', 'printgestion')) . "</a>"
                    . $esc(__(' — il vaut pour tous les agents du serveur.', 'printgestion')) . "</p>";
            }
            echo PluginPrintgestionUi::statusLine('info', $known
                ? PluginPrintgestionCollectsetup::getPickupSentence($agent, __('la découverte', 'printgestion'))
                : __('La sonde prendra la consigne à son prochain contact avec GLPI.', 'printgestion'));
            echo PluginPrintgestionUi::foldedNote(__('Gagner l\'attente, si vous êtes devant le PC sonde', 'printgestion'), $note);
        }
        if (!empty($this->fields['date_triggered'])) {
            $this->showResults();
        }
        // Au-delà de la limite, plus de relance : un résultat franc et ce qu'il faut faire, plutôt qu'une attente sans fin.
        $timed_out = $status === self::STATUS_TRIGGERED && $waiting && !empty($this->fields['date_triggered'])
            && strtotime((string) $this->fields['date_triggered']) < strtotime(Session::getCurrentTime()) - self::VERIFY_LIMIT;
        if ($timed_out) {
            // Deux situations très différentes sous le même mot « en attente » : une adresse dont personne ne
            // répond, et une imprimante trouvée dont le relevé de niveaux n'est pas encore passé. Les confondre
            // envoyait chercher une panne de réseau inexistante, en contredisant la ligne du tableau juste en
            // dessous.
            $minutes = (int) (self::VERIFY_LIMIT / MINUTE_TIMESTAMP);
            $muettes = (int) ($counts['waiting_discovery'] ?? 0) + (int) ($counts['pending'] ?? 0);
            $niveaux = (int) ($counts['waiting_inventory'] ?? 0);
            if ($muettes > 0) {
                echo PluginPrintgestionUi::statusLine('error', sprintf(
                    _n('Vérification arrêtée après %1$d min : %2$d adresse toujours sans réponse', 'Vérification arrêtée après %1$d min : %2$d adresses toujours sans réponse', $muettes, 'printgestion'),
                    $minutes,
                    $muettes
                ));
                echo "<p class='mb-1'>" . $esc(__('Vérifier que le PC sonde est allumé et branché sur le réseau des imprimantes, que les adresses et la communauté SNMP sont les bonnes, puis « Relancer la découverte ». Si la sonde vient seulement de répondre : « Vérifier maintenant ».', 'printgestion')) . "</p>";
            }
            if ($niveaux > 0) {
                echo PluginPrintgestionUi::statusLine('info', sprintf(
                    _n('Vérification arrêtée après %1$d min : %2$d imprimante trouvée, son relevé de niveaux n\'est pas encore passé', 'Vérification arrêtée après %1$d min : %2$d imprimantes trouvées, leur relevé de niveaux n\'est pas encore passé', $niveaux, 'printgestion'),
                    $minutes,
                    $niveaux
                ));
                echo "<p class='mb-1'>" . $esc(__('Il n\'y a rien de cassé : le relevé est préparé et partira au prochain appel de la sonde. Pour ne pas attendre, ouvrir http://127.0.0.1:62354/now depuis le PC sonde, puis « Vérifier maintenant ».', 'printgestion')) . "</p>";
            }
        }
        if ($can_edit && $status === self::STATUS_TRIGGERED && $waiting && !$timed_out) {
            echo "<p class='text-muted small mt-2 mb-0'>" . $esc(sprintf(__('Vérification automatique toutes les 60 secondes tant que des résultats sont en attente, pendant %d minutes au plus.', 'printgestion'), (int) (self::VERIFY_LIMIT / MINUTE_TIMESTAMP))) . "</p>";
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "' id='pg-racc-autoverify' class='d-none'>"
                . self::stepField() . Html::hidden('id', ['value' => $id]) . Html::hidden('verify', ['value' => 1]) . Html::hidden('auto', ['value' => 1])
                . Html::closeForm(false);
            echo "<script>setTimeout(function () { var form = document.getElementById('pg-racc-autoverify'); if (form) { form.submit(); } }, 60000);</script>";
        }
        echo "</div></div>";
    }

    /** Avancement des tâches de la sonde depuis le déclenchement. */
    private function showProgress(bool $can_edit = false): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        if (!PluginPrintgestionCollectsetup::isAvailable()) {
            // Cause (plugin absent ou inactif) dans la carte Santé et l'onglet de l'entité : ici, l'état seul.
            echo PluginPrintgestionUi::statusLine('error', __('Avancement indisponible — contactez l\'administrateur', 'printgestion'));
            return;
        }
        $progress = PluginPrintgestionCollectsetup::getProgress($this);
        if ($progress['discovery_failed']) {
            [$icon, $class, $text] = ['ti-alert-octagon', 'text-danger', sprintf(__('Découverte en erreur : %s', 'printgestion'), implode(' ; ', $progress['discovery']['errors']) ?: $progress['discovery_message'])];
        } elseif ($progress['discovery_finished']) {
            [$icon, $class, $text] = ['ti-circle-check', 'text-success', sprintf(__('Découverte terminée : %s', 'printgestion'), $progress['discovery_message'])];
        } elseif ($progress['discovery']['pending'] > 0) {
            [$icon, $class, $text] = ['ti-hourglass', 'text-muted', __('Découverte préparée, pas encore rendue par la sonde.', 'printgestion')];
        } else {
            [$icon, $class, $text] = ['ti-alert-triangle', 'text-warning', __('Aucune découverte en attente ni rendue pour cette sonde : relancez-la.', 'printgestion')];
        }
        echo "<div class='mb-3'><div class='text-muted small mb-1'>" . $esc(sprintf(__('Découverte lancée le %s.', 'printgestion'), Html::convDateTime((string) $this->fields['date_triggered']))) . "</div>";
        echo "<div class='{$class}'><i class='ti {$icon} me-1'></i>" . $esc($text) . "</div>";
        $text = empty($this->fields['date_inventory_prepared'])
            ? __('Relevé des niveaux : préparé automatiquement à la vérification qui suit la fin de la découverte.', 'printgestion')
            : sprintf(
                __('Relevé des niveaux préparé le %1$s : %2$d équipement(s) relevé(s), %3$d en attente de la sonde.', 'printgestion'),
                Html::convDateTime((string) $this->fields['date_inventory_prepared']),
                count($progress['inventory']['done']),
                $progress['inventory']['pending']
            );
        echo "<div class='text-muted'><i class='ti ti-droplet me-1'></i>" . $esc($text) . "</div></div>";
    }

    /** Résultat adresse par adresse ; alerte appuyée si une imprimante est arrivée dans une autre entité. */
    private function showResults(): void {
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $ips    = $this->getIps();
        $labels = self::getResultLabels();
        $counts = $this->getResultCounts();

        echo "<div class='d-flex flex-wrap gap-2 mb-2'>";
        foreach ($labels as $result => [$label, $class]) {
            if (!empty($counts[$result])) {
                echo "<span class='badge {$class}'>" . $esc($label . ' : ' . (int) $counts[$result]) . "</span>";
            }
        }
        echo "</div>";

        if (!empty($counts['wrong_entity'])) {
            echo "<div class='alert alert-danger'><i class='ti ti-alert-octagon me-1'></i><strong>"
                . $esc(sprintf(_n('%d imprimante est arrivée dans une autre entité.', '%d imprimantes sont arrivées dans une autre entité.', (int) $counts['wrong_entity'], 'printgestion'), (int) $counts['wrong_entity']))
                . "</strong> " . $esc(__('C\'est irréversible par l\'assistant : les règles d\'affectation ne jouent qu\'au premier import, relancer la découverte ne les déplacera pas. Transférez chaque imprimante à la main (fiche de l\'imprimante, Actions → Transférer), puis corrigez la cause (TAG de l\'agent, règle d\'affectation) avant tout nouveau raccordement.', 'printgestion'))
                . "</div>";
        }

        $silent = array_filter($ips, static fn(array $row): bool => $row['result'] === 'no_snmp' && empty($row['itemtype']));
        $group  = count($silent) > self::GROUP_NO_RESPONSE;
        echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0'><thead><tr>"
            . "<th>" . $esc(__('Adresse', 'printgestion')) . "</th><th>" . $esc(__('Résultat', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Équipement', 'printgestion')) . "</th><th>" . $esc(__('Entité', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Niveaux', 'printgestion')) . "</th><th>" . $esc(__('Détail', 'printgestion')) . "</th></tr></thead><tbody>";
        foreach ($ips as $row) {
            if ($group && $row['result'] === 'no_snmp' && empty($row['itemtype'])) {
                continue;
            }
            [$label, $class] = $labels[$row['result']] ?? [$row['result'], 'bg-secondary text-secondary-fg'];
            $itemtype        = (string) ($row['itemtype'] ?? '');
            $items_id        = (int) $row['items_id'];
            $item            = '—';
            $levels          = '—';
            if ($items_id > 0 && is_a($itemtype, CommonDBTM::class, true)) {
                $item = "<a href='" . $esc($itemtype::getFormURLWithID($items_id)) . "'>" . $esc($itemtype::getFriendlyNameById($items_id)) . "</a>"
                    . " <span class='text-muted small'>" . $esc($itemtype::getTypeName(1)) . "</span>";
                if ($itemtype === Printer::class) {
                    $levels = (string) countElementsInTable('glpi_printers_cartridgeinfos', ['printers_id' => $items_id]);
                }
            }
            $entity = $row['items_entities_id'] === null ? '—' : $esc(Dropdown::getDropdownName('glpi_entities', (int) $row['items_entities_id']));
            // « Ramener ici » : le geste que le technicien ferait à la main (restaurer, puis changer l'entité).
            if ($row['result'] === 'wrong_entity' && $can_edit && $itemtype === Printer::class && $items_id > 0) {
                $entity .= "<form method='post' action='" . $esc(self::getPageURL()) . "' class='mt-1'>" . self::stepField()
                    . Html::hidden('id', ['value' => (int) $this->getID()])
                    . Html::hidden('printers_id', ['value' => $items_id])
                    . "<button type='submit' name='adopt_printer' value='1' class='btn btn-sm btn-outline-primary'>"
                    . "<i class='ti ti-arrow-back-up me-1'></i>" . $esc(__('Ramener ici', 'printgestion')) . "</button>"
                    . Html::closeForm(false) . "</form>";
            }
            echo "<tr" . ($row['result'] === 'wrong_entity' ? " class='table-danger'" : '') . ">"
                . "<td class='font-monospace'>" . $esc($row['ip']) . "</td><td><span class='badge {$class}'>" . $esc($label) . "</span></td>"
                . "<td>{$item}</td><td>{$entity}</td><td>" . $esc($levels) . "</td><td class='small'>" . $esc(self::getResultDetail($row)) . "</td></tr>";
        }
        if ($group) {
            [$label, $class] = $labels['no_snmp'];
            echo "<tr><td class='font-monospace small'>" . $esc(self::summarizeIps(array_map(static fn(array $row): int => (int) $row['ip_num'], $silent))) . "</td>"
                . "<td><span class='badge {$class}'>" . $esc($label) . "</span></td><td colspan='4' class='small'>"
                . $esc(sprintf(__('%d adresses sans aucune réponse : éteintes, inutilisées, SNMP désactivé ou autre communauté.', 'printgestion'), count($silent))) . "</td></tr>";
        }
        echo "</tbody></table></div>";
    }

    private static function getResultDetail(array $row): string {
        switch ($row['result']) {
            case 'found':
                return __('Dans la bonne entité, niveaux des consommables remontés.', 'printgestion');
            case 'no_levels':
                return __('Dans la bonne entité, mais l\'inventaire SNMP n\'a remonté aucun niveau : modèle mal reconnu, ou consommables non exposés en SNMP.', 'printgestion');
            case 'waiting_inventory':
                return __('Découverte faite ; niveaux attendus au prochain passage de la sonde.', 'printgestion');
            case 'waiting_discovery':
                return __('La sonde n\'a pas encore rendu la découverte.', 'printgestion');
            case 'wrong_entity':
                $ailleurs = new Printer();
                $corbeille = (string) ($row['itemtype'] ?? '') === Printer::class
                    && $ailleurs->getFromDB((int) $row['items_id'])
                    && (int) $ailleurs->fields['is_deleted'] === 1;
                return $corbeille
                    ? sprintf(__('L\'imprimante a répondu, mais sa fiche est dans « %s » ET dans la corbeille : tant qu\'elle y est, aucun relevé n\'est possible.', 'printgestion'), Dropdown::getDropdownName('glpi_entities', (int) $row['items_entities_id']))
                    : sprintf(__('L\'imprimante a répondu, mais sa fiche est dans « %s » : les relevés iront à cette entité-là.', 'printgestion'), Dropdown::getDropdownName('glpi_entities', (int) $row['items_entities_id']));
            case 'not_printer':
                return __('Répond en SNMP, mais ce n\'est pas une imprimante.', 'printgestion');
            case 'no_snmp':
                return ($row['itemtype'] ?? '') === Unmanaged::class
                    ? __('Répond sur le réseau, pas en SNMP avec ces identifiants (communauté, SNMP désactivé sur l\'imprimante).', 'printgestion')
                    : __('Aucune réponse : imprimante éteinte, mauvaise adresse, SNMP désactivé ou autre communauté.', 'printgestion');
        }
        return '';
    }

    /** Journal horodaté du raccordement. */
    private function showLogs(bool $can_edit = false): void {
        global $DB;

        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $levels = [
            'info'    => ['ti-info-circle', 'text-blue'],
            'success' => ['ti-circle-check', 'text-green'],
            'warning' => ['ti-alert-triangle', 'text-orange'],
            'error'   => ['ti-alert-octagon', 'text-red'],
        ];
        $users = [];
        // Construit d'abord, montre ensuite : le journal ne s'ouvre que lorsqu'on cherche ce qui s'est passé.
        ob_start();
        $entries = [];
        foreach ($DB->request([
            'FROM'  => self::LOGS_TABLE,
            'WHERE' => ['plugin_printgestion_raccordements_id' => (int) $this->getID()],
            'ORDER' => ['id'],
        ]) as $log) {
            $users_id          = (int) $log['users_id'];
            $users[$users_id] ??= $users_id > 0 ? getUserName($users_id) : '—';
            [$icon, $class]    = $levels[$log['level']] ?? $levels['info'];
            $entries[]         = [
                'date'  => Html::convDateTime((string) $log['date'], null, true),
                'user'  => $users[$users_id],
                'step'  => (int) $log['step'],
                'event' => "<i class='ti {$icon} {$class} me-1'></i>" . $esc($log['message']),
            ];
        }
        echo PluginPrintgestionUi::datatable(
            ['date' => __('Date', 'printgestion'), 'user' => __('Utilisateur', 'printgestion'), 'step' => __('Étape', 'printgestion'), 'event' => __('Événement', 'printgestion')],
            $entries,
            ['step' => 'integer', 'event' => 'raw_html']
        );
        if ($can_edit) {
            // Confirmation native de GLPI : le même geste que partout ailleurs dans l'interface.
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "' class='text-end mt-2'>" . self::stepField()
                . Html::hidden('id', ['value' => (int) $this->getID()])
                . "<button type='submit' name='clear_logs' value='1' class='btn btn-sm btn-outline-danger'"
                . " onclick='return confirm(" . json_encode(__('Vider le journal de ce raccordement ?', 'printgestion'), JSON_UNESCAPED_UNICODE) . ")'>"
                . "<i class='ti ti-eraser me-1'></i>" . $esc(__('Vider le journal', 'printgestion')) . "</button>"
                . Html::closeForm(false) . "</form>";
        }
        echo PluginPrintgestionUi::foldedNote(__('Journal du raccordement', 'printgestion'), (string) ob_get_clean());
    }

    /** Onglet Déploiement Agent de l'entité, bloc 3 : raccordements de l'entité et accès à l'assistant. */
    /**
     * Bloc « 3. Raccorder les imprimantes » de l'onglet Déploiement Agent de l'entité : les prérequis, puis UN
     * tableau, une ligne par sonde du client avec son dernier raccordement — plus « Raccordements en cours »
     * d'un côté et « Sondes rattachées » de l'autre pour les mêmes machines. L'historique complet est la liste
     * native, filtrée sur l'entité.
     */
    public static function showForEntity(Entity $entity): void {
        $esc           = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $id            = (int) $entity->getID();
        $prerequisites = PluginPrintgestionCollectsetup::getPrerequisites();
        $can_edit      = Session::haveRight(self::$rightname, UPDATE);
        echo "<div class='card mb-3'><div class='card-header d-flex align-items-center'><h3 class='card-title mb-0'>" . $esc(__('3. Raccorder les imprimantes', 'printgestion')) . "</h3>";
        if ($can_edit) {
            if (empty($prerequisites['blocking'])) {
                echo "<a class='btn btn-primary ms-auto' href='" . $esc(self::getPageURL(null, $id)) . "'><i class='ti ti-plug-connected me-1'></i>" . $esc(__('Nouveau raccordement', 'printgestion')) . "</a>";
            } else {
                echo "<button type='button' class='btn btn-primary ms-auto' disabled><i class='ti ti-plug-connected me-1'></i>" . $esc(__('Nouveau raccordement', 'printgestion')) . "</button>";
            }
        }
        echo "</div><div class='card-body'>";
        self::showPrerequisites($prerequisites, false);
        self::showEntityProbes($entity, $can_edit && empty($prerequisites['blocking']));
        $total = countElementsInTable(self::getTable(), ['entities_id' => $id]);
        if ($total > 0) {
            echo "<p class='mb-0 mt-3'><a href='" . $esc(self::getListURL($id)) . "'><i class='ti ti-history me-1'></i>"
                . $esc(sprintf(__('Historique des raccordements de ce client (%d)', 'printgestion'), $total)) . "</a></p>";
        }
        echo "</div></div>";
    }

    /** Dernier raccordement de chaque sonde d'une entité : agents_id => ligne. */
    private static function getLastByAgent(int $entities_id): array {
        global $DB;

        $last = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['entities_id' => $entities_id], 'ORDER' => ['id DESC']]) as $row) {
            $agents_id = (int) $row['agents_id'];
            if (!isset($last[$agents_id])) {
                $last[$agents_id] = $row;
            }
        }
        return $last;
    }

    /** Adresses par résultat de plusieurs raccordements : id => [résultat => nombre]. */
    private static function getResultCountsFor(array $ids): array {
        global $DB;

        $counts = [];
        if (empty($ids)) {
            return $counts;
        }
        foreach ($DB->request([
            'SELECT'  => ['plugin_printgestion_raccordements_id', 'result', 'COUNT' => 'id AS n'],
            'FROM'    => self::IPS_TABLE,
            'WHERE'   => ['plugin_printgestion_raccordements_id' => $ids],
            'GROUPBY' => ['plugin_printgestion_raccordements_id', 'result'],
        ]) as $row) {
            $counts[(int) $row['plugin_printgestion_raccordements_id']][(string) $row['result']] = (int) $row['n'];
        }
        return $counts;
    }

    /** Pastilles des résultats (hors adresses non vérifiées), ou « — ». */
    public static function getResultBadges(array $counts): string {
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $badges = '';
        foreach (self::getResultLabels() as $result => [$label, $class]) {
            if ($result !== 'pending' && !empty($counts[$result])) {
                $badges .= "<span class='badge {$class} me-1'>" . $esc($label . ' ' . (int) $counts[$result]) . "</span>";
            }
        }
        return $badges !== '' ? $badges : '—';
    }

    /**
     * Les sondes du client, une ligne chacune : contact, état, version et TAG (administrateur), dernier
     * raccordement (statut, résultats, reprise), imprimantes collectées. Une sonde est un Agent de GLPI : les
     * actions massives de l'Agent s'appliquent telles quelles, avec les droits de l'utilisateur sur cet objet.
     * Les colonnes de l'administrateur sont absentes de la page d'un technicien, pas cachées.
     */
    private static function showEntityProbes(Entity $entity, bool $can_start): void {
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $id     = (int) $entity->getID();
        $admin  = PluginPrintgestionUi::isAdmin();
        $agents = PluginPrintgestionAgentdeploy::getEntityAgents($id);
        echo "<h4 class='mt-3 mb-2'>" . $esc(__('Sondes de ce client', 'printgestion')) . "</h4>";
        if (empty($agents)) {
            echo "<p class='text-muted mb-0'>" . $esc(__('Aucune pour l\'instant : lancer le fichier du bloc 2 sur un PC du client, puis attendre son premier contact.', 'printgestion')) . "</p>";
            return;
        }
        $tag      = trim((string) ($entity->fields['tag'] ?? ''));
        $silent   = PluginPrintgestionCollect::getSilentDays();
        $last     = self::getLastByAgent($id);
        $counts   = self::getResultCountsFor(array_map(static fn(array $row): int => (int) $row['id'], $last));
        $coverage = PluginPrintgestionAgentsetting::getCoverage();
        $statuses = self::getStatusLabels();
        $versions = [
            'old'     => ['bg-red text-red-fg', __('Trop ancienne', 'printgestion')],
            'update'  => ['bg-orange text-orange-fg', __('À mettre à jour', 'printgestion')],
            'ok'      => ['bg-green text-green-fg', __('À jour', 'printgestion')],
            'unknown' => ['bg-secondary text-secondary-fg', __('Inconnue', 'printgestion')],
        ];
        $columns = ['probe' => __('Sonde', 'printgestion'), 'contact' => __('Dernier contact', 'printgestion'), 'state' => __('État', 'printgestion')];
        if ($admin) {
            $columns += ['version' => __('Version', 'printgestion'), 'tag' => __('TAG', 'printgestion')];
        }
        $columns += ['raccordement' => __('Raccordement', 'printgestion'), 'printers' => __('Imprimantes collectées', 'printgestion')];
        $entries = [];
        foreach ($agents as $agent) {
            $agents_id = (int) $agent['id'];
            $is_silent = $agent['last_contact'] === null || strtotime((string) $agent['last_contact']) < time() - $silent * DAY_TIMESTAMP;
            $network   = (int) $agent['use_module_network_discovery'] === 1 && (int) $agent['use_module_network_inventory'] === 1;
            $state     = $is_silent
                ? "<span class='badge bg-red text-red-fg'>" . $esc(__('Muette', 'printgestion')) . "</span>"
                : ($network
                    ? "<span class='badge bg-green text-green-fg'>" . $esc(__('Active', 'printgestion')) . "</span>"
                    : "<span class='badge bg-red text-red-fg'>" . $esc(__('À réinstaller avec l\'installeur de l\'entité', 'printgestion')) . "</span>");
            $racc = $last[$agents_id] ?? null;
            if ($racc !== null) {
                [$status_label, $status_class] = $statuses[$racc['status']] ?? [$racc['status'], 'bg-secondary text-secondary-fg'];
                $open = in_array($racc['status'], [self::STATUS_OPEN, self::STATUS_CONFIGURED, self::STATUS_TRIGGERED], true);
                $cell = "<a href='" . $esc(self::getPageURL((int) $racc['id'])) . "' class='me-1'>" . $esc(sprintf(__('n° %d', 'printgestion'), (int) $racc['id'])) . "</a>"
                    . "<span class='badge {$status_class} me-1'>" . $esc($status_label) . "</span>" . self::getResultBadges($counts[(int) $racc['id']] ?? [])
                    . ($open ? " <a href='" . $esc(self::getPageURL((int) $racc['id'])) . "'>" . $esc(__('Reprendre', 'printgestion')) . "</a>" : '');
            } else {
                $cell = "<span class='text-muted'>" . $esc(__('Aucun', 'printgestion')) . "</span>"
                    . ($can_start ? " <a href='" . $esc(self::getPageURL(null, $id)) . "'>" . $esc(__('Raccorder', 'printgestion')) . "</a>" : '');
            }
            $entry = [
                'itemtype'     => Agent::class,
                'id'           => $agents_id,
                'probe'        => "<a href='" . $esc(PluginPrintgestionAgentsetting::getPageURL($agents_id)) . "'>" . $esc($agent['name']) . "</a>",
                'contact'      => $agent['last_contact'] === null || $agent['last_contact'] === '' ? '—' : Html::convDateTime((string) $agent['last_contact']),
                'state'        => $state,
                'raccordement' => $cell,
                'printers'     => count($coverage[$agents_id] ?? []),
            ];
            if ($admin) {
                [$badge_class, $badge_label] = $versions[PluginPrintgestionCollect::getAgentVersionStatus((string) $agent['version_value'], PluginPrintgestionAgentsetting::getSettings($agents_id))];
                $agent_tag        = trim((string) $agent['tag']);
                $entry['version'] = $esc($agent['version_value'] !== '' ? $agent['version_value'] : '—') . " <span class='badge {$badge_class}'>" . $esc($badge_label) . "</span>";
                $entry['tag']     = ($agent_tag !== '' ? "<code>" . $esc($agent_tag) . "</code>" : '—')
                    . ($agent_tag !== $tag ? " <span class='badge bg-orange text-orange-fg'>" . $esc(__('≠ TAG de l\'entité', 'printgestion')) . "</span>" : '');
            }
            $entries[] = $entry;
        }
        echo PluginPrintgestionUi::datatable($columns, $entries, [
            'probe'        => 'raw_html',
            'state'        => 'raw_html',
            'version'      => 'raw_html',
            'tag'          => 'raw_html',
            'raccordement' => 'raw_html',
            'printers'     => 'integer',
        ], Agent::class, Session::haveRight(Agent::$rightname, UPDATE));
        // Clic droit sur ces lignes : la sonde, son raccordement en cours, son historique.
        PluginPrintgestionContextmenu::render(Agent::class);
    }

    /** Page « Raccordements » du module : la liste native de GLPI (recherche, tri, filtres, export, actions massives). */
    public static function showList(): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        echo "<p class='text-muted'>" . $esc(__('Un raccordement se lance depuis la fiche de l\'entité du client, onglet « Déploiement Agent », bloc 3. Ici, tous les raccordements de vos entités : filtrer par client, par sonde ou par statut ; clic droit sur une ligne pour l\'ouvrir.', 'printgestion')) . "</p>";
        $params           = Search::manageParams(self::class, $_GET);
        $params['target'] = self::getSearchURL();
        if (!isset($_GET['sort'])) {
            $params['sort']  = 2;
            $params['order'] = 'DESC';
        }
        echo "<div class='search_page row'><div class='col search-container' data-glpi-search-container>";
        Search::showList(self::class, $params, [2, 80, 3, 4, 9, 10, 6, 5]);
        echo "</div></div>";
        PluginPrintgestionContextmenu::render(self::class);
    }

    /**
     * Options de recherche natives : moteur de recherche de GLPI, export et actions massives du raccordement.
     *
     * Rien n'y est modifiable en masse : le statut et les dates suivent le déroulé de l'assistant, les forcer d'une
     * liste mentirait sur ce qui a été fait.
     */
    public function rawSearchOptions() {
        $table = self::getTable();
        $tab   = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(Session::getPluralNumber())];
        // Le numéro ouvre l'assistant (affichage « specific », voir getSpecificValueToDisplay).
        $tab[] = ['id' => '2', 'table' => $table, 'field' => 'id',
                  'name' => __('N°', 'printgestion'), 'datatype' => 'specific', 'massiveaction' => false];
        $tab[] = ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename',
                  'name' => Entity::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false];
        $tab[] = ['id' => '3', 'table' => Agent::getTable(), 'field' => 'name',
                  'name' => __('Sonde', 'printgestion'), 'datatype' => 'dropdown', 'massiveaction' => false];
        $tab[] = ['id' => '4', 'table' => $table, 'field' => 'status',
                  'name' => __('Statut', 'printgestion'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false];
        $tab[] = ['id' => '5', 'table' => User::getTable(), 'field' => 'name',
                  'name' => __('Par', 'printgestion'), 'datatype' => 'dropdown', 'right' => 'all', 'massiveaction' => false];
        $tab[] = ['id' => '6', 'table' => $table, 'field' => 'date_creation',
                  'name' => __('Créé le', 'printgestion'), 'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => '19', 'table' => $table, 'field' => 'date_mod',
                  'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => '7', 'table' => $table, 'field' => 'date_triggered',
                  'name' => __('Découverte lancée le', 'printgestion'), 'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => '8', 'table' => $table, 'field' => 'date_verified',
                  'name' => __('Vérifié le', 'printgestion'), 'datatype' => 'datetime', 'massiveaction' => false];
        // Adresses et résultats viennent de la table des adresses : deux sous-requêtes corrélées (« TABLE » est
        // remplacé par la table principale par le moteur), triables, sans recherche.
        $ips  = '`' . self::IPS_TABLE . '` WHERE `plugin_printgestion_raccordements_id` = TABLE.`id`';
        $sums = [];
        foreach (array_keys(self::getResultLabels()) as $result) {
            $sums[] = "CONCAT('" . $result . ":', SUM(`result` = '" . $result . "'))";
        }
        $tab[] = ['id' => '9', 'table' => $table, 'field' => 'id', 'name' => __('Adresses', 'printgestion'),
                  'datatype' => 'number', 'nosearch' => true, 'massiveaction' => false,
                  'computation' => '(SELECT COUNT(*) FROM ' . $ips . ')'];
        $tab[] = ['id' => '10', 'table' => $table, 'field' => 'id', 'name' => __('Résultats', 'printgestion'),
                  'datatype' => 'specific', 'nosearch' => true, 'nosort' => true, 'massiveaction' => false,
                  'computation' => "(SELECT CONCAT_WS(',', " . implode(', ', $sums) . ') FROM ' . $ips . ')'];

        return $tab;
    }

    static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            [$label, $class] = self::getStatusLabels()[(string) ($values[$field] ?? '')] ?? [(string) ($values[$field] ?? ''), 'bg-secondary text-secondary-fg'];
            return "<span class='badge {$class}'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</span>";
        }
        if ($field === 'id') {
            // Deux colonnes portent ce champ : le numéro (lien vers l'assistant) et les résultats calculés
            // (« found:2,no_snmp:1,… », option 10).
            if ((int) ($options['searchopt']['id'] ?? 0) === 10) {
                $counts = [];
                foreach (explode(',', (string) ($values[$field] ?? '')) as $part) {
                    [$result, $n] = array_pad(explode(':', $part, 2), 2, '0');
                    $counts[$result] = (int) $n;
                }
                return self::getResultBadges($counts);
            }
            $id = (int) ($values[$field] ?? 0);
            return $id > 0 ? "<a href='" . htmlspecialchars(self::getPageURL($id), ENT_QUOTES, 'UTF-8') . "'>" . $id . "</a>" : '';
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $options['display'] = false;
            $options['value']   = $values[$field] ?? '';
            return Dropdown::showFromArray($name, array_map(static fn(array $status): string => $status[0], self::getStatusLabels()), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Adresses et journal d'un raccordement purgé.
     *
     * Sans cela, une suppression — désormais possible en lot depuis la liste — laisserait en base des adresses et
     * des lignes de journal rattachées à un raccordement qui n'existe plus.
     */
    public function cleanDBonPurge() {
        global $DB;

        $DB->delete(self::IPS_TABLE, ['plugin_printgestion_raccordements_id' => (int) $this->getID()]);
        $DB->delete(self::LOGS_TABLE, ['plugin_printgestion_raccordements_id' => (int) $this->getID()]);
    }

    static function uninstall(Migration $migration) {
        global $DB;

        foreach ([self::LOGS_TABLE, self::IPS_TABLE, self::getTable()] as $table) {
            $DB->dropTable($table, true);
        }
        return true;
    }
}
