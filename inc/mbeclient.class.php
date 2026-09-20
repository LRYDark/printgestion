<?php
/**
 * PluginPrintgestionMbeclient — client de l'API SOAP MBE France (e-link), lecture seule.
 *
 * MBE n'est pas un transporteur : c'est l'intermédiaire par lequel les cartouches du stock partent chez le client,
 * confiées à GLS ou à UPS. Il n'entre donc pas dans la liste des transporteurs : le transporteur réel et son numéro
 * se saisissent comme aujourd'hui, MBE se retrouve par ce numéro ou par le numéro de BL. Rien n'est appelé sans
 * identifiants.
 *
 * Deux lectures, et deux seulement :
 *
 *   - `listShipments()` (ShipmentsListV3Request) : une page d'expéditions d'une fenêtre de dates, avec les références
 *     MBE, les numéros transporteur et les Notes — d'où se tire le numéro de BL. L'API **n'offre aucun filtre** sur le
 *     BL ni sur le numéro transporteur : seul `MBEMasterTrackings` filtre. Trouver une expédition par son BL se fait
 *     donc en balayant la fenêtre page par page et en comparant ici. C'est cher, et c'est pour cela que Mbetracking
 *     balaie UNE fois par passage pour TOUTES les expéditions à apparier, jamais une fois par expédition.
 *   - `trackByMbeRef()` (TrackingRequest) : le statut d'une expédition dont on connaît déjà la référence MBE.
 *
 * Quota : 500 appels par jour, arrêt à 80 % (le même garde-fou que GLS, compté dans le même mémo de configuration).
 * Le résumé `TrackingStatus` de MBE reste `WAITING_DELIVERY` plusieurs jours après une livraison déjà publiée par le
 * transporteur : il ne suffit jamais à lui seul, Mbetracking le recoupe avec le suivi GLS.
 *
 *   Adresse : POST https://api.mbeonline.fr/ws (pas /ws/ws), SOAP 1.1, Content-Type text/xml, SOAPAction vide.
 *   Authentification : HTTP Basic identifiant:passphrase ET le bloc <Credentials> du conteneur de requête.
 *   Enveloppe : construite avec DOM, valeurs en nœuds texte. Seul le nom de l'opération porte le préfixe du
 *   namespace MBE (http://www.onlinembe.eu/ws/) ; les éléments internes restent sans préfixe — préfixés, MBE répond
 *   500 (NullPointerException). InternalReferenceID unique par appel. Adresse et système : des constantes, aucun
 *   réglage. Pas d'extension soap : Guzzle (proxy de GLPI, TLS vérifié, jamais désactivé) et DOM suffisent.
 *
 * Trois formes d'erreur, trois traitements : 401/403 = identifiants ou droits (« auth », ne se soigne pas en
 * réessayant) ; 500 = requête refusée (« request », le plus souvent le format) ; réseau et autres 5xx = technique
 * (« network », « server »). Status ≠ OK dans une réponse 200 = « request » avec le texte des Errors.
 *
 * Jamais un identifiant, une passphrase, un en-tête Authorization ni un lien signé (S3, X-Amz-…) dans un message,
 * un journal ou une page : mask() passe sur tout texte rendu. Le transport HTTP est une fonction injectable :
 * Guzzle en production, des réponses inventées dans le harnais.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionMbeclient {

    const ENDPOINT       = 'https://api.mbeonline.fr/ws';
    const NAMESPACE_MBE  = 'http://www.onlinembe.eu/ws/';
    const NAMESPACE_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    const SYSTEM         = 'FR';
    const CONNECT_TIMEOUT = 15;
    const TIMEOUT         = 30;
    /** « Tester la connexion » : la liste des expéditions des sept derniers jours, première page, lue puis jetée. */
    const TEST_WINDOW_DAYS = 7;
    /** Échecs consécutifs à partir desquels la carte Santé passe au rouge (même seuil que GLS). */
    const BREAKER_THRESHOLD = 5;
    /** Quota quotidien d'appels MBE, et part au-delà de laquelle on s'arrête pour garder de la marge. */
    const DAILY_QUOTA      = 500;
    const QUOTA_STOP_RATIO = 0.8;

    /** Statuts de livraison lus chez MBE, ramenés à quatre mots (plus « unknown »). */
    const DELIVERY_DELIVERED = 'delivered';
    const DELIVERY_PARTIAL   = 'partial';
    const DELIVERY_TRANSIT   = 'in_transit';
    const DELIVERY_EXCEPTION = 'exception';
    const DELIVERY_UNKNOWN   = 'unknown';
    /** Longueur maximale d'un identifiant ou d'une passphrase : chiffrés (GLPIKey), ils doivent tenir dans varchar(255). */
    const MAX_CREDENTIAL_LENGTH = 150;

    /** Mémo de santé (glpi_configs, contexte du plugin) : jamais un secret. */
    const MEMO_CONTEXT = 'plugin:printgestion';
    const MEMO_KEYS    = ['mbe_last_success', 'mbe_failures', 'mbe_last_error', 'mbe_last_kind',
        'mbe_quota_day', 'mbe_quota_count', 'mbe_quota_blocked_day', 'mbe_last_poll'];

    /** @var callable fn(string $method, string $url, array $headers, ?string $body): ['status' => int, 'body' => string] */
    private $transport;
    /** Valeurs à masquer dans tout texte rendu (identifiant, passphrase), connues le temps d'un appel. */
    private array $secrets = [];

    public function __construct(?callable $transport = null) {
        $this->transport = $transport ?? [$this, 'sendWithGuzzle'];
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    public static function hasKeys(): bool {
        $config = PluginPrintgestionConfig::getInstance();
        return (string) ($config->fields['mbe_username'] ?? '') !== ''
            && (string) ($config->fields['mbe_passphrase'] ?? '') !== '';
    }

    public function isConfigured(): bool {
        return self::hasKeys();
    }

    /** Identifiant API déchiffré, pour l'écran de configuration : ce n'est pas un secret, la passphrase l'est. */
    public static function getUsername(): string {
        return PluginPrintgestionConfig::getSecret('mbe_username');
    }

    // ── Appels ────────────────────────────────────────────────────────────────

    /**
     * « Tester la connexion » : la liste des sept derniers jours, page 1, lue puis jetée. Le message ne porte jamais
     * un identifiant.
     *
     * Il passe par `listShipments()`, donc par la **même lecture de XML** que le suivi : ce bouton ne prouve pas
     * seulement que MBE accepte les identifiants, il prouve que la réponse est comprise. C'est pour cela qu'il compte
     * aussi les expéditions dont les notes portent un numéro de BL lisible — sans ce numéro, l'appariement d'une
     * expédition retombe sur le numéro transporteur, et le dire ici évite de chercher ailleurs.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public function testConnection(): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => __('Identifiant ou passphrase MBE non saisis.', 'printgestion')];
        }
        try {
            $listing = $this->listShipments(
                date('Y-m-d', time() - self::TEST_WINDOW_DAYS * DAY_TIMESTAMP),
                date('Y-m-d'),
                1
            );
        } catch (PluginPrintgestionCarrierexception $e) {
            self::noteFailure($e->getMessage(), $e->getKind());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $count = count($listing['shipments']);
        $bls   = count(array_filter($listing['shipments'], static fn(array $s): bool => $s['bl_number'] !== ''));
        return ['ok' => true, 'message' => sprintf(
            __('Connexion MBE établie et réponse comprise : %1$d expédition(s) sur les %2$d derniers jours (page 1 sur %3$d), dont %4$d avec un numéro de BL lisible dans les notes. Lues puis jetées.', 'printgestion'),
            $count,
            self::TEST_WINDOW_DAYS,
            max(1, $listing['total_pages']),
            $bls
        )];
    }

    /**
     * Un appel SOAP : l'opération, ses champs (dans l'ordre), et le RequestContainer de la réponse lu.
     *
     * @return array ['status' => string, 'errors' => string, 'page' => int, 'total_pages' => int,
     *                'shipment_count' => int, 'container' => DOMElement]
     * @throws PluginPrintgestionCarrierexception
     */
    public function call(string $operation, array $fields): array {
        $username   = PluginPrintgestionConfig::getSecret('mbe_username');
        $passphrase = PluginPrintgestionConfig::getSecret('mbe_passphrase');
        if ($username === '' || $passphrase === '') {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_AUTH, __('Identifiant ou passphrase MBE non saisis, ou indéchiffrables.', 'printgestion'));
        }
        $this->secrets = [$username, $passphrase];
        $xml      = self::envelope($operation, $username, $passphrase, $fields);
        $response = $this->send('POST', self::ENDPOINT, [
            'Content-Type'  => 'text/xml; charset=utf-8',
            'SOAPAction'    => '""',
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $passphrase),
        ], $xml);
        unset($passphrase, $xml);
        $status = $response['status'];
        if ($status === 401 || $status === 403) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_AUTH, sprintf(
                __('Identifiant ou passphrase MBE refusés, ou compte sans droit d\'accès (HTTP %d) : réessayer ne change rien, corriger les identifiants.', 'printgestion'),
                $status
            ));
        }
        if ($status === 500) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, sprintf(
                __('Requête refusée par MBE (HTTP 500%s) : le format de la requête est en cause, pas les identifiants.', 'printgestion'),
                str_contains($response['body'], 'NullPointerException') ? ', NullPointerException' : ''
            ));
        }
        if ($status >= 500) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, sprintf(__('MBE indisponible (HTTP %d).', 'printgestion'), $status));
        }
        if ($status !== 200) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, sprintf(__('Réponse inattendue de MBE (HTTP %d).', 'printgestion'), $status));
        }
        return $this->parse($operation, $response['body']);
    }

    // ── Enveloppe et lecture ──────────────────────────────────────────────────

    /**
     * Enveloppe SOAP 1.1 construite avec DOM : le préfixe sur la seule opération, les éléments internes sans
     * namespace, les valeurs en nœuds texte (jamais concaténées). Ordre : System, Credentials, InternalReferenceID,
     * puis les champs de l'opération dans l'ordre donné.
     */
    public static function envelope(string $operation, string $username, string $passphrase, array $fields): string {
        $doc  = new DOMDocument('1.0', 'UTF-8');
        $env  = $doc->createElementNS(self::NAMESPACE_SOAP, 'soapenv:Envelope');
        $doc->appendChild($env);
        $body = $doc->createElementNS(self::NAMESPACE_SOAP, 'soapenv:Body');
        $env->appendChild($body);
        $op   = $doc->createElementNS(self::NAMESPACE_MBE, 'mbe:' . $operation);
        $body->appendChild($op);
        $container = $doc->createElement('RequestContainer');
        $op->appendChild($container);
        $text = static function (DOMNode $parent, string $name, string $value) use ($doc): void {
            $node = $doc->createElement($name);
            $node->appendChild($doc->createTextNode($value));
            $parent->appendChild($node);
        };
        $text($container, 'System', self::SYSTEM);
        $credentials = $doc->createElement('Credentials');
        $container->appendChild($credentials);
        $text($credentials, 'Username', $username);
        $text($credentials, 'Passphrase', $passphrase);
        $text($container, 'InternalReferenceID', self::internalReference());
        // Un champ dont la valeur est un tableau se répète : MBE attend <TrackingMBE>a</TrackingMBE><TrackingMBE>b…,
        // pas une liste séparée par des virgules.
        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                $text($container, (string) $name, (string) $one);
            }
        }
        return (string) $doc->saveXML();
    }

    /** Référence interne unique par appel : horodatage UTC et six caractères aléatoires. */
    public static function internalReference(): string {
        return 'GLPI-PG-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    /**
     * RequestContainer de la réponse : Status, Errors, Page, TotalPages et le nombre de ShipmentFullInfo. Les
     * requêtes XPath ignorent les préfixes (local-name()), la réponse n'est jamais chargée avec accès réseau.
     */
    private function parse(string $operation, string $body): array {
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = trim($body) !== '' && $doc->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, __('Réponse MBE illisible (pas un XML).', 'printgestion'));
        }
        $xpath = new DOMXPath($doc);
        // Chaque opération a sa forme, et on la vérifie : une réponse 200 qui ne ressemble à rien ne doit jamais
        // passer pour une réussite. ShipmentsListV3Request répond dans <OpérationResponse><RequestContainer> ;
        // TrackingRequest, non — sa réponse porte directement des <TrackingResult>, sans conteneur ni Status. Une
        // réponse qui n'a ni l'un ni l'autre est refusée, pas devinée.
        $containers = $xpath->query("//*[local-name()='" . $operation . "Response']/*[local-name()='RequestContainer']");
        $container  = ($containers !== false && $containers->length > 0) ? $containers->item(0) : null;
        if ($container === null && $operation === 'TrackingRequest') {
            $results   = $xpath->query("//*[local-name()='TrackingResult']");
            $container = ($results !== false && $results->length > 0) ? $doc->documentElement : null;
        }
        if (!$container instanceof DOMNode) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, sprintf(
                __('Réponse MBE sans %sResponse/RequestContainer : structure inattendue.', 'printgestion'),
                $operation
            ));
        }
        $value = static function (string $name) use ($xpath, $container): string {
            $nodes = $xpath->query("./*[local-name()='" . $name . "']", $container);
            return ($nodes !== false && $nodes->length > 0) ? trim((string) $nodes->item(0)->textContent) : '';
        };
        $status = $value('Status');
        $errors = trim((string) preg_replace('/\s+/u', ' ', $value('Errors')));
        // Status absent : réponse sans conteneur (TrackingRequest). Présent et différent de OK : MBE a refusé.
        if ($status !== '' && strtoupper($status) !== 'OK') {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, $this->mask(sprintf(
                __('MBE répond « %1$s »%2$s.', 'printgestion'),
                $status !== '' ? $status : '?',
                $errors !== '' ? ' : ' . mb_substr($errors, 0, 160) : ''
            )));
        }
        $shipments = $xpath->query(".//*[local-name()='ShipmentFullInfo']", $container);
        return [
            'status'         => $status,
            'errors'         => $errors,
            'page'           => (int) $value('Page'),
            'total_pages'    => (int) $value('TotalPages'),
            'shipment_count' => $shipments === false ? 0 : $shipments->length,
            'container'      => $container,
        ];
    }

    // ── Lectures ──────────────────────────────────────────────────────────────

    /**
     * Une page d'expéditions d'une fenêtre de dates. L'appel est compté dans le quota du jour.
     *
     * @return array ['page' => int, 'total_pages' => int, 'shipments' => array[]] où chaque expédition porte
     *               'mbe_master_tracking', 'mbe_tracking', 'courier_trackings' (numéros transporteur, maître et
     *               colis), 'bl_number' (tiré des Notes), 'notes', 'description'
     * @throws PluginPrintgestionCarrierexception
     */
    public function listShipments(string $date_from, string $date_to, int $page = 1): array {
        self::assertQuota();
        // Compté avant l'appel, pas après : MBE reçoit et compte la requête même quand elle échoue. Compter les
        // seules réussites sous-estimerait la consommation, et laisserait dépasser les 500 du jour.
        self::countRequest();
        $result = $this->call('ShipmentsListV3Request', [
            'DateFrom' => $date_from,
            'DateTo'   => $date_to,
            'Page'     => (string) max(1, $page),
        ]);
        self::noteSuccess();

        $container = $result['container'];
        $xpath     = new DOMXPath($container->ownerDocument ?? new DOMDocument());
        $shipments = [];
        $nodes     = $xpath->query(".//*[local-name()='ShipmentFullInfo']", $container);
        foreach (($nodes === false ? [] : $nodes) as $node) {
            $shipments[] = self::readShipment($xpath, $node);
        }
        return [
            'page'        => $result['page'] > 0 ? $result['page'] : max(1, $page),
            'total_pages' => max(1, $result['total_pages']),
            'shipments'   => $shipments,
        ];
    }

    /**
     * Statut d'une expédition dont la référence MBE est connue. L'appel est compté dans le quota du jour.
     *
     * Le résumé de MBE est en retard sur le terrain : `WAITING_DELIVERY` peut durer des jours après une remise déjà
     * publiée par le transporteur. Ce que cette méthode renvoie ne décide donc rien à elle seule.
     *
     * @return array ['status' => DELIVERY_*, 'raw' => string, 'description' => string, 'delivered_at' => ?string,
     *                'delivered_to' => string, 'courier_tracking' => string]
     * @throws PluginPrintgestionCarrierexception
     */
    public function trackByMbeRef(string $mbe_ref): array {
        $mbe_ref = trim($mbe_ref);
        if ($mbe_ref === '') {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, __('Référence MBE vide.', 'printgestion'));
        }
        self::assertQuota();
        // Compté avant l'appel : voir listShipments().
        self::countRequest();
        $result = $this->call('TrackingRequest', ['TrackingMBE' => [$mbe_ref]]);
        self::noteSuccess();

        $container = $result['container'];
        $xpath     = new DOMXPath($container->ownerDocument ?? new DOMDocument());
        $results   = $xpath->query(".//*[local-name()='TrackingResult']", $container);
        $context   = ($results !== false && $results->length > 0) ? $results->item(0) : $container;
        $text      = static function (string $name) use ($xpath, $context): string {
            $nodes = $xpath->query(".//*[local-name()='" . $name . "']", $context);
            return ($nodes !== false && $nodes->length > 0) ? trim((string) $nodes->item(0)->textContent) : '';
        };
        $raw  = $text('TrackingStatus');
        $date = $text('DeliveryDate');
        return [
            'status'           => self::mapDeliveryStatus($raw),
            'raw'              => $raw,
            'description'      => $text('Description'),
            // MBE ne donne que le jour : midi plutôt que minuit, pour ne pas antidater d'une demi-journée.
            'delivered_at'     => $date !== '' ? $date . ' 12:00:00' : null,
            'delivered_to'     => $text('DeliverySign'),
            'courier_tracking' => $text('CourierTracking'),
        ];
    }

    /** Une expédition de la liste, réduite à ce dont l'appariement et la décision ont besoin. */
    private static function readShipment(DOMXPath $xpath, DOMNode $shipment): array {
        $first = static function (string $name, DOMNode $context) use ($xpath): string {
            $nodes = $xpath->query(".//*[local-name()='" . $name . "']", $context);
            return ($nodes !== false && $nodes->length > 0) ? trim((string) $nodes->item(0)->textContent) : '';
        };
        $child = static function (string $name) use ($xpath, $shipment): DOMNode {
            $nodes = $xpath->query("./*[local-name()='" . $name . "']", $shipment);
            return ($nodes !== false && $nodes->length > 0) ? $nodes->item(0) : $shipment;
        };
        $tracking = $child('TrackingInfo');
        $info     = $child('ShipmentInfo');
        $notes    = $first('Notes', $tracking);

        $couriers = [];
        $nodes    = $xpath->query(".//*[local-name()='CourierMasterTrk' or local-name()='CourierPackageTracking']", $tracking);
        foreach (($nodes === false ? [] : $nodes) as $node) {
            $value = self::normalizeTracking((string) $node->textContent);
            if ($value !== '') {
                $couriers[$value] = $value;
            }
        }
        return [
            'mbe_master_tracking' => $first('MasterTrackingMBE', $tracking),
            'mbe_tracking'        => $first('TrackingMBE', $tracking),
            'courier_trackings'   => array_values($couriers),
            'bl_number'           => self::parseBlNumber($notes),
            'notes'               => $notes,
            'description'         => $first('Description', $info),
        ];
    }

    /**
     * Numéro de BL porté par une expédition MBE. Il n'a pas de champ à lui : il est saisi à la main dans les Notes,
     * par convention « BL » suivi du numéro, dans n'importe quelle casse et avec ou sans séparateur — blxxxxxx,
     * BLxxxxxx, Bl-xxxxxx, BL_xxxxxx, BL xxxxxx. Rendu en majuscules et sans séparateur (BLXXXXXX) pour être
     * comparable. Chaîne vide si personne ne l'a saisi : ce n'est pas une clé garantie, c'est une convention.
     */
    public static function parseBlNumber(string $notes): string {
        if (preg_match('/\bBL\s*[-_]?\s*(\d+)\b/iu', $notes, $m) !== 1) {
            return '';
        }
        return 'BL' . $m[1];
    }

    /** Numéro de suivi comparable : majuscules, sans espace ni caractère de contrôle. */
    public static function normalizeTracking(string $value): string {
        return strtoupper((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', '', trim($value)));
    }

    /** Statut de livraison MBE ramené à un mot. Tout ce qui n'est pas connu est « unknown », jamais « livré ». */
    public static function mapDeliveryStatus(string $raw): string {
        return match (strtoupper(trim($raw))) {
            'DELIVERED'           => self::DELIVERY_DELIVERED,
            'PARTIALLY_DELIVERED' => self::DELIVERY_PARTIAL,
            'WAITING_DELIVERY'    => self::DELIVERY_TRANSIT,
            'EXCEPTION'           => self::DELIVERY_EXCEPTION,
            default               => self::DELIVERY_UNKNOWN,
        };
    }

    // ── Quota ─────────────────────────────────────────────────────────────────

    /** Appels encore permis aujourd'hui (0 si la marge est atteinte). */
    public static function quotaRemaining(): int {
        $memo = self::getMemo();
        if ($memo['quota_blocked']) {
            return 0;
        }
        return max(0, (int) floor(self::DAILY_QUOTA * self::QUOTA_STOP_RATIO) - $memo['quota_count']);
    }

    /** @throws PluginPrintgestionCarrierexception si le budget du jour est épuisé */
    public static function assertQuota(): void {
        if (self::quotaRemaining() <= 0) {
            throw new PluginPrintgestionCarrierexception(
                PluginPrintgestionCarrierexception::KIND_BUDGET,
                __('Budget quotidien MBE atteint : la suite attendra demain.', 'printgestion')
            );
        }
    }

    /** Un appel de plus aujourd'hui. */
    public static function countRequest(): void {
        $memo  = Config::getConfigurationValues(self::MEMO_CONTEXT, ['mbe_quota_day', 'mbe_quota_count']);
        $today = date('Y-m-d');
        $count = (string) ($memo['mbe_quota_day'] ?? '') === $today ? (int) ($memo['mbe_quota_count'] ?? 0) : 0;
        Config::setConfigurationValues(self::MEMO_CONTEXT, ['mbe_quota_day' => $today, 'mbe_quota_count' => $count + 1]);
    }

    /** Aucun identifiant, passphrase, en-tête Basic ni lien signé dans un texte rendu. */
    public function mask(string $text): string {
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }
        $text = (string) preg_replace('/Basic\s+[A-Za-z0-9+\/=]+/', 'Basic ***', $text);
        $text = (string) preg_replace('/https?:\/\/[^\s"\'<>]*X-Amz-[^\s"\'<>]*/i', '<lien signé masqué>', $text);
        return (string) preg_replace('/(X-Amz-(?:Credential|Signature|Security-Token))=[^&\s"\'<>]*/i', '$1=***', $text);
    }

    // ── Transport ─────────────────────────────────────────────────────────────

    private function send(string $method, string $url, array $headers, ?string $body): array {
        try {
            $response = ($this->transport)($method, $url, $headers, $body);
        } catch (PluginPrintgestionCarrierexception $e) {
            throw $e;
        } catch (Throwable $e) {
            // Jamais le message brut : il peut porter l'URL et ce que le transport y a mis.
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_NETWORK, sprintf(__('MBE injoignable (%s).', 'printgestion'), (new ReflectionClass($e))->getShortName()));
        }
        if (!is_array($response) || !isset($response['status'])) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_NETWORK, __('MBE injoignable (réponse vide).', 'printgestion'));
        }
        return ['status' => (int) $response['status'], 'body' => (string) ($response['body'] ?? '')];
    }

    /** Transport de production : Guzzle avec le proxy réglé dans GLPI, TLS vérifié, sans suivre les redirections. */
    private function sendWithGuzzle(string $method, string $url, array $headers, ?string $body): array {
        $client   = Toolbox::getGuzzleClient(['connect_timeout' => self::CONNECT_TIMEOUT, 'timeout' => self::TIMEOUT, 'http_errors' => false, 'allow_redirects' => false]);
        $response = $client->request($method, $url, ['headers' => $headers, 'body' => $body]);
        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    // ── Mémo de santé ─────────────────────────────────────────────────────────

    /**
     * @return array ['last_success' => string, 'failures' => int, 'last_error' => string, 'last_kind' => string,
     *                'quota_count' => int (remis à zéro chaque jour), 'quota_blocked' => bool]
     */
    public static function getMemo(): array {
        $memo  = Config::getConfigurationValues(self::MEMO_CONTEXT, self::MEMO_KEYS);
        $today = date('Y-m-d');
        return [
            'last_success'  => (string) ($memo['mbe_last_success'] ?? ''),
            'failures'      => (int) ($memo['mbe_failures'] ?? 0),
            'last_error'    => (string) ($memo['mbe_last_error'] ?? ''),
            'last_kind'     => (string) ($memo['mbe_last_kind'] ?? ''),
            'quota_count'   => (string) ($memo['mbe_quota_day'] ?? '') === $today ? (int) ($memo['mbe_quota_count'] ?? 0) : 0,
            'quota_blocked' => (string) ($memo['mbe_quota_blocked_day'] ?? '') === $today,
        ];
    }

    public static function noteSuccess(): void {
        Config::setConfigurationValues(self::MEMO_CONTEXT, ['mbe_last_success' => date('Y-m-d H:i:s'), 'mbe_failures' => 0, 'mbe_last_error' => '', 'mbe_last_kind' => '']);
    }

    /** Un échec de plus, avec sa cause courte et sa forme (auth, request, network, server) ; jamais un secret. */
    public static function noteFailure(string $message, string $kind): void {
        $memo = Config::getConfigurationValues(self::MEMO_CONTEXT, ['mbe_failures']);
        Config::setConfigurationValues(self::MEMO_CONTEXT, [
            'mbe_failures'   => (int) ($memo['mbe_failures'] ?? 0) + 1,
            'mbe_last_error' => mb_substr(date('Y-m-d H:i:s') . ' — ' . $message, 0, 255),
            'mbe_last_kind'  => $kind,
        ]);
    }

    /** Efface le mémo (retrait des identifiants, harnais). */
    public static function resetMemo(): void {
        Config::setConfigurationValues(self::MEMO_CONTEXT, array_fill_keys(self::MEMO_KEYS, ''));
    }
}
