<?php
/**
 * PluginPrintgestionMbeclient — client de l'API SOAP MBE France (e-link), lecture seule.
 *
 * MBE n'est pas un transporteur : c'est l'intermédiaire par lequel les cartouches du stock partent chez le client,
 * confiées à GLS ou à UPS. Il n'entre donc pas dans la liste des transporteurs : le transporteur réel et son numéro
 * se saisissent comme aujourd'hui, MBE se retrouvera par ce numéro. Cette version ne fait que deux choses : garder
 * les identifiants et « Tester la connexion ». Rien n'est appelé sans identifiants.
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
    /** Longueur maximale d'un identifiant ou d'une passphrase : chiffrés (GLPIKey), ils doivent tenir dans varchar(255). */
    const MAX_CREDENTIAL_LENGTH = 150;

    /** Mémo de santé (glpi_configs, contexte du plugin) : jamais un secret. */
    const MEMO_CONTEXT = 'plugin:printgestion';
    const MEMO_KEYS    = ['mbe_last_success', 'mbe_failures', 'mbe_last_error', 'mbe_last_kind'];

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
     * « Tester la connexion » : ShipmentsListV3Request sur les sept derniers jours, page 1. Le résultat est compté
     * puis jeté ; le message ne porte jamais un identifiant.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public function testConnection(): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => __('Identifiant ou passphrase MBE non saisis.', 'printgestion')];
        }
        try {
            $container = $this->call('ShipmentsListV3Request', [
                'DateFrom' => date('Y-m-d', time() - self::TEST_WINDOW_DAYS * DAY_TIMESTAMP),
                'DateTo'   => date('Y-m-d'),
                'Page'     => '1',
            ]);
        } catch (PluginPrintgestionCarrierexception $e) {
            self::noteFailure($e->getMessage(), $e->getKind());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        self::noteSuccess();
        return ['ok' => true, 'message' => sprintf(
            __('Connexion MBE établie : %1$d expédition(s) sur les %2$d derniers jours (page 1 sur %3$d), lues puis jetées.', 'printgestion'),
            $container['shipment_count'],
            self::TEST_WINDOW_DAYS,
            max(1, $container['total_pages'])
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
        foreach ($fields as $name => $value) {
            $text($container, (string) $name, (string) $value);
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
        $xpath      = new DOMXPath($doc);
        $containers = $xpath->query("//*[local-name()='" . $operation . "Response']/*[local-name()='RequestContainer']");
        if ($containers === false || $containers->length === 0) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, sprintf(
                __('Réponse MBE sans %sResponse/RequestContainer : structure inattendue.', 'printgestion'),
                $operation
            ));
        }
        $container = $containers->item(0);
        $value = static function (string $name) use ($xpath, $container): string {
            $nodes = $xpath->query("./*[local-name()='" . $name . "']", $container);
            return ($nodes !== false && $nodes->length > 0) ? trim((string) $nodes->item(0)->textContent) : '';
        };
        $status = $value('Status');
        $errors = trim((string) preg_replace('/\s+/u', ' ', $value('Errors')));
        if (strtoupper($status) !== 'OK') {
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

    /** @return array ['last_success' => string, 'failures' => int, 'last_error' => string, 'last_kind' => string] */
    public static function getMemo(): array {
        $memo = Config::getConfigurationValues(self::MEMO_CONTEXT, self::MEMO_KEYS);
        return [
            'last_success' => (string) ($memo['mbe_last_success'] ?? ''),
            'failures'     => (int) ($memo['mbe_failures'] ?? 0),
            'last_error'   => (string) ($memo['mbe_last_error'] ?? ''),
            'last_kind'    => (string) ($memo['mbe_last_kind'] ?? ''),
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
