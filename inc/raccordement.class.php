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

    public static function getPageURL(?int $id = null, ?int $entities_id = null): string {
        $url = PLUGIN_PRINTGESTION_WEBDIR . '/front/raccordement.php';
        if ($id !== null) {
            return $url . '?id=' . $id;
        }
        return $entities_id !== null ? $url . '?entities_id=' . $entities_id : $url;
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
    private function replaceIps(array $ips): bool {
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

    public function getCurrentStep(): int {
        if (in_array($this->fields['status'], [self::STATUS_CONFIGURED, self::STATUS_TRIGGERED, self::STATUS_CLOSED], true)
            || !empty($this->fields['date_configured'])) {
            return 4;
        }
        return countElementsInTable(self::IPS_TABLE, ['plugin_printgestion_raccordements_id' => (int) $this->getID()]) > 0 ? 3 : 2;
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
    public static function processAction(array $post, Entity $entity, ?self $racc): string {
        $entities_id = (int) $entity->getID();
        $back        = $racc !== null ? self::getPageURL((int) $racc->getID()) : self::getPageURL(null, $entities_id);
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
                $report(3, [['success', __('Étape 3 validée : configuration de collecte en place dans GLPI Inventory.', 'printgestion')]]);
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
            $report(4, PluginPrintgestionCollectsetup::trigger($racc)['events']);
            return $back;
        }

        if (isset($post['verify'])) {
            if ($status !== self::STATUS_TRIGGERED) {
                $flash('error', __('Rien à vérifier : la découverte n\'est pas lancée, ou le raccordement est clos.', 'printgestion'));
                return $back;
            }
            $before = $racc->getResultCounts();
            $report(4, PluginPrintgestionCollectsetup::verify($racc)['events']);
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
            $racc->update(['id' => (int) $racc->getID(), 'status' => self::STATUS_CLOSED]);
            $report(4, [['success', sprintf(__('Raccordement terminé. %s.', 'printgestion'), self::formatCounts($racc->getResultCounts()))]]);
            return $back;
        }

        if (isset($post['abandon'])) {
            if (!in_array($status, [self::STATUS_OPEN, self::STATUS_CONFIGURED, self::STATUS_TRIGGERED], true)) {
                $flash('error', __('Raccordement déjà clos.', 'printgestion'));
                return $back;
            }
            $step = $racc->getCurrentStep();
            $racc->update(['id' => (int) $racc->getID(), 'status' => self::STATUS_ABANDONED]);
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
                ? __('Raccordement abandonné. Rien n\'avait été créé dans GLPI Inventory.', 'printgestion')
                : sprintf(__('Raccordement abandonné. Rien n\'est supprimé : les objets créés dans GLPI Inventory restent en place (%s) ; désactivez la tâche dans GLPI Inventory si la collecte ne doit pas avoir lieu.', 'printgestion'), implode(' ; ', $list))]]);
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
        $step        = $racc !== null ? $racc->getCurrentStep() : 1;

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
                $racc->showAbandonForm($can_edit);
                $racc->showLogs();
            }
            return;
        }

        // Étape 5 (lieu, commentaire, contrat) dès que la découverte est lancée.
        if ($racc !== null && in_array($racc->fields['status'], [self::STATUS_TRIGGERED, self::STATUS_CLOSED], true)) {
            $step = 5;
        }
        echo "<ul class='steps steps-counter steps-blue mb-3'>";
        foreach ([
            1 => __('Sonde présente', 'printgestion'),
            2 => __('Imprimantes', 'printgestion'),
            3 => __('Configuration de la collecte', 'printgestion'),
            4 => __('Déclenchement et vérification', 'printgestion'),
            5 => __('Lieu, commentaire, contrat', 'printgestion'),
        ] as $number => $label) {
            echo "<li class='step-item" . ($number === $step ? ' active' : '') . "'>" . $esc($label) . "</li>";
        }
        echo "</ul>";

        self::showStep1($entity, $racc, $can_edit);
        if ($racc === null) {
            return;
        }
        $racc->showStep2($can_edit);
        PluginPrintgestionRaccordementdetail::showDetails($racc, $can_edit);
        if ($step >= 3) {
            $racc->showStep3($can_edit);
        }
        if ($step >= 4) {
            $racc->showStep4($can_edit);
        }
        if ($step >= 5) {
            PluginPrintgestionRaccordementdetail::showStep5($racc, $can_edit);
        }
        $racc->showAbandonForm($can_edit);
        $racc->showLogs();
    }

    /**
     * Prérequis de GLPI Inventory : ce qui arrête l'assistant, ce qui est à vérifier ; $show_ok : une ligne
     * discrète quand tout est réglé (en tête de l'assistant).
     */
    public static function showPrerequisites(array $prerequisites, bool $show_ok): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        if (!empty($prerequisites['blocking'])) {
            echo "<div class='alert alert-danger'><strong>" . $esc(__('Assistant arrêté : prérequis de GLPI Inventory manquants. Rien ne peut être fait tant qu\'ils ne sont pas réglés :', 'printgestion')) . "</strong><ul class='mb-0'>";
            foreach ($prerequisites['blocking'] as $message) {
                echo "<li>" . $esc($message) . "</li>";
            }
            echo "</ul></div>";
        }
        if (!empty($prerequisites['warnings'])) {
            echo "<div class='alert alert-warning'><strong>" . $esc(__('À vérifier :', 'printgestion')) . "</strong><ul class='mb-0'>";
            foreach ($prerequisites['warnings'] as $message) {
                echo "<li>" . $esc($message) . "</li>";
            }
            echo "</ul></div>";
        }
        if ($show_ok && empty($prerequisites['blocking'])) {
            echo "<p class='text-muted small'><i class='ti ti-circle-check text-success me-1'></i>" . $esc(sprintf(__('Prérequis de GLPI Inventory vérifiés : version %s, prise en charge ; tâche automatique « taskscheduler » programmée.', 'printgestion'), $prerequisites['version'])) . "</p>";
        }
    }

    /** « Abandonner le raccordement » : raccordement en cours, droit en modification. */
    private function showAbandonForm(bool $can_edit): void {
        if (!$can_edit || !in_array($this->fields['status'], [self::STATUS_OPEN, self::STATUS_CONFIGURED, self::STATUS_TRIGGERED], true)) {
            return;
        }
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $confirm = __('Abandonner ce raccordement ? Rien n\'est supprimé : le journal est gardé et les objets créés dans GLPI Inventory restent en place.', 'printgestion');
        echo "<form method='post' action='" . $esc(self::getPageURL()) . "' class='mb-3 text-end'>"
            . Html::hidden('id', ['value' => (int) $this->getID()])
            . "<button type='submit' name='abandon' value='1' class='btn btn-outline-danger' onclick=\"return confirm(" . $esc(json_encode($confirm)) . ");\">"
            . "<i class='ti ti-player-stop me-1'></i>" . $esc(__('Abandonner le raccordement', 'printgestion')) . "</button>"
            . Html::closeForm(false);
    }

    private static function showStep1(Entity $entity, ?self $racc, bool $can_edit): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('1. Sonde présente', 'printgestion')) . "</h3></div><div class='card-body'>";

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
        $contact = [
            'none'   => ['bg-red text-red-fg', __('Aucun', 'printgestion')],
            'silent' => ['bg-red text-red-fg', __('Muette', 'printgestion')],
            'old'    => ['bg-orange text-orange-fg', __('Pas dans l\'heure', 'printgestion')],
            'recent' => ['bg-green text-green-fg', __('Récent', 'printgestion')],
        ];
        echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0'><thead><tr>"
            . "<th>" . $esc(__('Sonde', 'printgestion')) . "</th><th>" . $esc(__('Poste', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Version', 'printgestion')) . "</th><th>" . $esc(__('Dernier contact', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('TAG', 'printgestion')) . "</th><th>" . $esc(__('Conditions', 'printgestion')) . "</th><th></th></tr></thead><tbody>";
        foreach ($agents as $agent) {
            $check           = self::checkAgent($entity, $agent);
            [$class, $label] = $contact[$check['contact']];
            $agent_tag       = trim((string) ($agent['tag'] ?? ''));
            echo "<tr><td><a href='" . $esc(Agent::getFormURLWithID((int) $agent['id'])) . "'>" . $esc($agent['name']) . "</a></td>"
                . "<td>" . self::getHostHtml($agent) . "</td>"
                . "<td>" . $esc(self::getAgentVersion($agent) ?: '—') . "</td>"
                . "<td class='text-nowrap'>" . $esc(empty($agent['last_contact']) ? '—' : Html::convDateTime((string) $agent['last_contact'])) . " <span class='badge {$class}'>" . $esc($label) . "</span></td>"
                . "<td>" . ($agent_tag !== '' ? "<code>" . $esc($agent_tag) . "</code>" : '—') . "</td><td class='small'>";
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
                    ? Html::hidden('id', ['value' => (int) $racc->getID()])
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
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('2. Imprimantes à raccorder', 'printgestion')) . "</h3></div><div class='card-body'>";
        if (!empty($longs)) {
            echo "<p>" . $esc(sprintf(_n('%1$d adresse déclarée : %2$s', '%1$d adresses déclarées : %2$s', count($longs), 'printgestion'), count($longs), self::summarizeIps($longs))) . "</p>";
        }
        if ($editable) {
            echo "<p class='text-muted small'>" . $esc(__('Rien n\'est appliqué aux imprimantes à cette étape : les adresses restent en attente jusqu\'à la création de la configuration (étape 3).', 'printgestion')) . "</p>";
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "'>" . Html::hidden('id', ['value' => (int) $this->getID()]);
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
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('3. Configuration de la collecte (GLPI Inventory)', 'printgestion')) . "</h3></div><div class='card-body'>";
        if ($this->fields['status'] !== self::STATUS_OPEN) {
            $this->showConfiguration();
            echo "</div></div>";
            return;
        }

        $credentials = PluginPrintgestionCollectsetup::getCredentials();
        $default     = $this->getDefaultCredentialId($credentials);
        $plan        = PluginPrintgestionCollectsetup::plan($this, ['credential_mode' => 'existing', 'snmpcredentials_id' => $default]);
        echo "<p class='text-muted'>" . $esc(__('Ce que l\'assistant va faire avec les identifiants SNMP proposés. Tout est revérifié au moment de créer ; rien n\'est créé si une vérification échoue.', 'printgestion')) . "</p>";
        self::showPlan($plan);

        if ($can_edit) {
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "'>" . Html::hidden('id', ['value' => (int) $this->getID()]);
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
                . "<div class='col-sm-3'><select class='form-select' name='snmpversion'><option value='2'>v2c</option><option value='1'>v1</option></select></div>"
                . "<div class='col-sm-4'><input class='form-control' type='password' name='community' maxlength='255' autocomplete='new-password' placeholder='" . $esc(__('Communauté', 'printgestion')) . "'></div>"
                . "</div><div class='form-hint'>" . $esc(__('Réutilisés tels quels s\'ils existent déjà. SNMP v3 : à créer dans GLPI (Configuration → Identifiants SNMP), puis à choisir ici.', 'printgestion')) . "</div></div></div>";
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
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('4. Déclenchement et vérification', 'printgestion')) . "</h3></div><div class='card-body'>";

        if ($status === self::STATUS_CONFIGURED) {
            echo "<p>" . $esc(__('La collecte est configurée. Lancez la découverte : GLPI Inventory prépare le job de la sonde, puis GLPI tente de la réveiller.', 'printgestion')) . "</p>";
        }
        if (!empty($this->fields['date_triggered'])) {
            $this->showProgress();
        }
        if ($can_edit && $running) {
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "' class='d-flex flex-wrap gap-2 mb-3'>" . Html::hidden('id', ['value' => $id]);
            echo "<button type='submit' name='trigger' value='1' class='btn " . ($status === self::STATUS_CONFIGURED ? 'btn-primary' : 'btn-outline-primary') . "'><i class='ti ti-radar me-1'></i>"
                . $esc($status === self::STATUS_CONFIGURED ? __('Lancer la découverte', 'printgestion') : __('Relancer la découverte', 'printgestion')) . "</button>";
            if ($status === self::STATUS_TRIGGERED) {
                echo "<button type='submit' name='verify' value='1' class='btn btn-primary'><i class='ti ti-refresh me-1'></i>" . $esc(__('Vérifier maintenant', 'printgestion')) . "</button>";
                echo "<button type='submit' name='close' value='1' class='btn btn-success'><i class='ti ti-check me-1'></i>" . $esc(__('Terminer le raccordement', 'printgestion')) . "</button>";
            }
            echo Html::closeForm(false);
        }
        if ($running) {
            $agent     = new Agent();
            $port      = $agent->getFromDB((int) $this->fields['agents_id']) ? ((int) $agent->fields['port'] ?: Agent::DEFAULT_PORT) : Agent::DEFAULT_PORT;
            $frequency = max(1, (int) (new \Glpi\Inventory\Conf())->inventory_frequency);
            echo "<div class='alert alert-info'><strong>" . $esc(__('Sur place, si GLPI ne joint pas la sonde', 'printgestion')) . "</strong> "
                . $esc(sprintf(__('(cas normal derrière la box du client) : sur le PC sonde, ouvrez http://127.0.0.1:%1$d dans un navigateur et cliquez « Force an Inventory ». Il faut deux passages : la découverte, puis le relevé des niveaux dès que l\'assistant l\'annonce. Sans ce geste, chaque passage attend le prochain contact de l\'agent (jusqu\'à %2$d h).', 'printgestion'), $port, $frequency))
                . "</div>";
        }
        if (!empty($this->fields['date_triggered'])) {
            $this->showResults();
        }
        if ($can_edit && $status === self::STATUS_TRIGGERED && $waiting) {
            echo "<p class='text-muted small mt-2 mb-0'>" . $esc(__('Vérification automatique toutes les 60 secondes tant que des résultats sont en attente.', 'printgestion')) . "</p>";
            echo "<form method='post' action='" . $esc(self::getPageURL()) . "' id='pg-racc-autoverify' class='d-none'>"
                . Html::hidden('id', ['value' => $id]) . Html::hidden('verify', ['value' => 1]) . Html::hidden('auto', ['value' => 1])
                . Html::closeForm(false);
            echo "<script>setTimeout(function () { var form = document.getElementById('pg-racc-autoverify'); if (form) { form.submit(); } }, 60000);</script>";
        }
        echo "</div></div>";
    }

    /** Avancement des tâches de la sonde depuis le déclenchement. */
    private function showProgress(): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        if (!PluginPrintgestionCollectsetup::isAvailable()) {
            echo "<div class='alert alert-warning'>" . $esc(__('Plugin GLPI Inventory absent ou inactif : avancement indisponible.', 'printgestion')) . "</div>";
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
                return sprintf(__('Arrivée dans « %s » : transfert manuel nécessaire.', 'printgestion'), Dropdown::getDropdownName('glpi_entities', (int) $row['items_entities_id']));
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
    private function showLogs(): void {
        global $DB;

        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $levels = [
            'info'    => ['ti-info-circle', 'text-blue'],
            'success' => ['ti-circle-check', 'text-green'],
            'warning' => ['ti-alert-triangle', 'text-orange'],
            'error'   => ['ti-alert-octagon', 'text-red'],
        ];
        $users = [];
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(__('Journal du raccordement', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<div class='table-responsive'><table class='table table-sm mb-0'><thead><tr>"
            . "<th>" . $esc(__('Date', 'printgestion')) . "</th><th>" . $esc(__('Utilisateur', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Étape', 'printgestion')) . "</th><th>" . $esc(__('Événement', 'printgestion')) . "</th></tr></thead><tbody>";
        foreach ($DB->request([
            'FROM'  => self::LOGS_TABLE,
            'WHERE' => ['plugin_printgestion_raccordements_id' => (int) $this->getID()],
            'ORDER' => ['id'],
        ]) as $log) {
            $users_id          = (int) $log['users_id'];
            $users[$users_id] ??= $users_id > 0 ? getUserName($users_id) : '—';
            [$icon, $class]    = $levels[$log['level']] ?? $levels['info'];
            echo "<tr><td class='text-nowrap'>" . $esc(Html::convDateTime((string) $log['date'], null, true)) . "</td>"
                . "<td class='text-nowrap'>" . $esc($users[$users_id]) . "</td><td>" . (int) $log['step'] . "</td>"
                . "<td><i class='ti {$icon} {$class} me-1'></i>" . $esc($log['message']) . "</td></tr>";
        }
        echo "</tbody></table></div></div></div>";
    }

    /** Onglet Déploiement Agent de l'entité, bloc 3 : raccordements de l'entité et accès à l'assistant. */
    public static function showForEntity(Entity $entity): void {
        global $DB;

        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $id  = (int) $entity->getID();
        // Prérequis de GLPI Inventory manquants : pas d'accès à un nouveau raccordement, raison affichée.
        $prerequisites = PluginPrintgestionCollectsetup::getPrerequisites();
        echo "<div class='card mb-3'><div class='card-header d-flex align-items-center'><h3 class='card-title mb-0'>" . $esc(__('3. Raccorder les imprimantes', 'printgestion')) . "</h3>";
        if (Session::haveRight(self::$rightname, UPDATE)) {
            if (empty($prerequisites['blocking'])) {
                echo "<a class='btn btn-primary ms-auto' href='" . $esc(self::getPageURL(null, $id)) . "'><i class='ti ti-plug-connected me-1'></i>" . $esc(__('Nouveau raccordement', 'printgestion')) . "</a>";
            } else {
                echo "<button type='button' class='btn btn-primary ms-auto' disabled title='" . $esc(__('Prérequis de GLPI Inventory manquants : voir ci-dessous.', 'printgestion')) . "'><i class='ti ti-plug-connected me-1'></i>" . $esc(__('Nouveau raccordement', 'printgestion')) . "</button>";
            }
        }
        echo "</div><div class='card-body'>";
        self::showPrerequisites($prerequisites, false);
        echo "<p class='text-muted small'>" . $esc(__('Assistant pas à pas, sur place : sonde présente, adresses des imprimantes, configuration de la collecte dans GLPI Inventory, puis vérification adresse par adresse avant de partir.', 'printgestion')) . "</p>";
        $rows = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['entities_id' => $id],
            'ORDER' => ['id DESC'],
            'LIMIT' => 10,
        ]), false);
        if (empty($rows)) {
            echo "<p class='text-muted mb-0'>" . $esc(__('Aucun raccordement pour cette entité.', 'printgestion')) . "</p>";
        } else {
            self::showTable($rows, false);
        }
        echo "</div></div>";
    }

    /** Page « Raccordements » du module : raccordements des entités de l'utilisateur. */
    public static function showList(): void {
        global $DB;

        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $rows = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => getEntitiesRestrictCriteria(self::getTable(), 'entities_id'),
            'ORDER' => ['id DESC'],
            'LIMIT' => 300,
        ]), false);
        echo "<div class='card'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(self::getTypeName(Session::getPluralNumber())) . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted'>" . $esc(__('Un raccordement se lance depuis la fiche de l\'entité du client, onglet « Déploiement Agent », bloc 3.', 'printgestion')) . "</p>";
        if (empty($rows)) {
            echo "<p class='mb-0'>" . $esc(__('Aucun raccordement.', 'printgestion')) . "</p>";
        } else {
            self::showTable($rows, true);
        }
        echo "</div></div>";
    }

    /** Raccordements : numéro, entité, sonde, statut, adresses, résultats, création. */
    private static function showTable(array $rows, bool $with_entity): void {
        global $DB;

        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $ids    = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $counts = [];
        foreach ($DB->request([
            'SELECT'  => ['plugin_printgestion_raccordements_id', 'result', 'COUNT' => 'id AS n'],
            'FROM'    => self::IPS_TABLE,
            'WHERE'   => ['plugin_printgestion_raccordements_id' => $ids],
            'GROUPBY' => ['plugin_printgestion_raccordements_id', 'result'],
        ]) as $row) {
            $counts[(int) $row['plugin_printgestion_raccordements_id']][$row['result']] = (int) $row['n'];
        }
        $agents = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => Agent::getTable(),
            'WHERE'  => ['id' => array_values(array_unique(array_map(static fn(array $row): int => (int) $row['agents_id'], $rows)))],
        ]) as $agent) {
            $agents[(int) $agent['id']] = (string) $agent['name'];
        }
        $statuses = self::getStatusLabels();
        $labels   = self::getResultLabels();
        echo "<div class='table-responsive'><table class='table table-sm table-hover align-middle mb-0'><thead><tr>"
            . "<th>" . $esc(__('N°', 'printgestion')) . "</th>" . ($with_entity ? "<th>" . $esc(__('Entité', 'printgestion')) . "</th>" : '')
            . "<th>" . $esc(__('Sonde', 'printgestion')) . "</th><th>" . $esc(__('Statut', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Adresses', 'printgestion')) . "</th><th>" . $esc(__('Résultats', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Créé le', 'printgestion')) . "</th><th>" . $esc(__('Par', 'printgestion')) . "</th></tr></thead><tbody>";
        foreach ($rows as $row) {
            $id                            = (int) $row['id'];
            [$status_label, $status_class] = $statuses[$row['status']] ?? [$row['status'], 'bg-secondary text-secondary-fg'];
            $badges                        = '';
            foreach ($labels as $result => [$label, $class]) {
                if ($result !== 'pending' && !empty($counts[$id][$result])) {
                    $badges .= "<span class='badge {$class} me-1'>" . $esc($label . ' ' . $counts[$id][$result]) . "</span>";
                }
            }
            echo "<tr><td><a href='" . $esc(self::getPageURL($id)) . "'>" . $id . "</a></td>"
                . ($with_entity ? "<td>" . $esc(Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id'])) . "</td>" : '')
                . "<td>" . $esc($agents[(int) $row['agents_id']] ?? sprintf(__('n° %d', 'printgestion'), (int) $row['agents_id'])) . "</td>"
                . "<td><span class='badge {$status_class}'>" . $esc($status_label) . "</span></td>"
                . "<td>" . (int) array_sum($counts[$id] ?? []) . "</td><td>" . ($badges !== '' ? $badges : '—') . "</td>"
                . "<td class='text-nowrap'>" . $esc(Html::convDateTime((string) $row['date_creation'])) . "</td>"
                . "<td>" . $esc(getUserName((int) $row['users_id'])) . "</td></tr>";
        }
        echo "</tbody></table></div>";
    }

    static function uninstall(Migration $migration) {
        global $DB;

        foreach ([self::LOGS_TABLE, self::IPS_TABLE, self::getTable()] as $table) {
            $DB->doQuery('DROP TABLE IF EXISTS `' . $table . '`');
        }
        return true;
    }
}
