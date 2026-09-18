<?php
/**
 * PluginPrintgestionGlsclient — client de l'API GLS Track And Trace V1 (spécification OpenAPI 1.0.7).
 *
 *   Jeton : POST oauth2/v1/token (version 1, pas 2), Basic base64(clientId:clientSecret), client_credentials ;
 *           gardé en cache GLPI, chiffré (GLPIKey), pour expires_in moins une marge.
 *   Suivi : GET track-and-trace-v1/tracking/simple/trackids/{clés séparées par des virgules, dix au plus}
 *           ?showEvents=true&showLinks=false, Authorization: Bearer, Accept-Language: FR.
 *   Un seul environnement (la spécification dit que production et bac à sable sont identiques), des constantes,
 *   aucun réglage d'URL. Le chemin du point d'entrée tient dans TRACK_PATH : « references/ » si le test de Joris
 *   sur le multi-colis le justifie.
 *
 * Trois formes d'erreur, trois traitements :
 *   - parcels[].errorCode dans un HTTP 200 : rendu tel quel à l'appelant (E_404_01 = repli de normalisation,
 *     E_500_01 = échec technique pour le disjoncteur, jamais géré ici) ;
 *   - ErrorResponseDTO (type, title, status, detail) sur 400 / 404 globaux : exception « request » ;
 *   - ErrorResponseApigeeDTO (fault.faultstring) sur 401 / 403 et 429 : exception « auth » (jeton oublié) ou
 *     « quota » (arrêt pour la journée).
 *
 * Le secret et le jeton ne sont jamais dans un message, un journal ni une page. Le transport HTTP est une fonction
 * injectable : Guzzle (réglages de proxy de GLPI) en production, des réponses inventées dans le harnais.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionGlsclient implements PluginPrintgestionCarrierclient {

    const TOKEN_URL  = 'https://api.gls-group.net/oauth2/v1/token';
    const BASE_URL   = 'https://api.gls-group.net/track-and-trace-v1/';
    /** Seul point d'entrée de suivi utilisé. */
    const TRACK_PATH = 'tracking/simple/trackids/';
    const LANGUAGE   = 'FR';

    /** Quota GLS par défaut, et part qu'on s'autorise à consommer par jour. */
    const DAILY_QUOTA      = 500;
    const QUOTA_STOP_RATIO = 0.8;
    /** Échecs techniques consécutifs avant d'arrêter le cycle. */
    const BREAKER_THRESHOLD = 5;
    /** Marge retirée à expires_in. */
    const TOKEN_MARGIN = 60;
    const TOKEN_CACHE_KEY = 'printgestion_gls_token';
    const CONNECT_TIMEOUT = 5;
    const TIMEOUT         = 15;

    /** Mémo de santé et de quota (glpi_configs, contexte du plugin) : jamais un secret. */
    const MEMO_CONTEXT = 'plugin:printgestion';
    const MEMO_KEYS    = ['gls_last_success', 'gls_failures', 'gls_last_error', 'gls_quota_day', 'gls_quota_count', 'gls_quota_blocked_day'];

    /** @var callable fn(string $method, string $url, array $headers, ?string $body): ['status' => int, 'body' => string] */
    private $transport;
    private ?string $token = null;
    private int $token_expires_at = 0;

    public function __construct(?callable $transport = null) {
        $this->transport = $transport ?? [$this, 'sendWithGuzzle'];
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    public static function hasKeys(): bool {
        $config = PluginPrintgestionConfig::getInstance();
        return trim((string) ($config->fields['gls_client_id'] ?? '')) !== ''
            && (string) ($config->fields['gls_client_secret'] ?? '') !== '';
    }

    public function isConfigured(): bool {
        return self::hasKeys();
    }

    // ── Appels ────────────────────────────────────────────────────────────────

    public function testConnection(): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => __('Identifiant ou secret GLS non saisis.', 'printgestion')];
        }
        try {
            $this->requestToken(false);
        } catch (PluginPrintgestionCarrierexception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => __('Connexion établie : jeton obtenu (et jeté).', 'printgestion')];
    }

    public function track(array $keys): array {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), static fn(string $k) => $k !== '')));
        if (empty($keys)) {
            return [];
        }
        if (count($keys) > PluginPrintgestionGlsnumber::MAX_PER_REQUEST) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, sprintf(
                __('%1$d clés dans une requête : GLS en accepte %2$d au plus.', 'printgestion'),
                count($keys),
                PluginPrintgestionGlsnumber::MAX_PER_REQUEST
            ));
        }
        if (!$this->isConfigured()) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_AUTH, __('Identifiant ou secret GLS non saisis.', 'printgestion'));
        }
        $token = $this->requestToken(true);
        $url   = self::BASE_URL . self::TRACK_PATH . implode(',', array_map('rawurlencode', $keys)) . '?showEvents=true&showLinks=false';
        self::countRequest();
        $response = $this->send('GET', $url, [
            'Authorization'   => 'Bearer ' . $token,
            'Accept'          => 'application/json',
            'Accept-Language' => self::LANGUAGE,
        ], null);
        $status = (int) $response['status'];
        if ($status === 200) {
            $data = json_decode((string) $response['body'], true);
            if (!is_array($data) || !array_key_exists('parcels', $data) || !is_array($data['parcels'])) {
                throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, __('Réponse GLS sans « parcels » : structure inattendue.', 'printgestion'));
            }
            return array_values(array_filter($data['parcels'], 'is_array'));
        }
        if ($status === 401 || $status === 403) {
            $this->forgetToken();
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_AUTH, sprintf(__('Jeton refusé par GLS (HTTP %1$d%2$s).', 'printgestion'), $status, self::apigeeDetail($response['body'])));
        }
        if ($status === 429) {
            // Arrêt pour la journée, pas pour le cycle : noté ici, là où le fait est connu.
            self::noteQuotaBlocked();
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_QUOTA, sprintf(__('Quota GLS dépassé (HTTP 429%s) : plus d\'appel aujourd\'hui.', 'printgestion'), self::apigeeDetail($response['body'])));
        }
        if ($status === 400 || $status === 404) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, sprintf(__('Requête refusée par GLS (HTTP %1$d%2$s).', 'printgestion'), $status, self::problemDetail($response['body'])));
        }
        throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, sprintf(__('GLS indisponible (HTTP %d).', 'printgestion'), $status));
    }

    // ── Jeton ─────────────────────────────────────────────────────────────────

    /** Jeton d'accès : en mémoire, sinon dans le cache GLPI (chiffré), sinon demandé. Jamais rendu à l'extérieur. */
    private function requestToken(bool $use_cache): string {
        global $GLPI_CACHE;

        if ($use_cache) {
            if ($this->token !== null && $this->token_expires_at > time()) {
                return $this->token;
            }
            $cached = isset($GLPI_CACHE) ? $GLPI_CACHE->get(self::TOKEN_CACHE_KEY) : null;
            if (is_array($cached) && (int) ($cached['expires_at'] ?? 0) > time() && (string) ($cached['token'] ?? '') !== '') {
                $token = (string) (new GLPIKey())->decrypt((string) $cached['token']);
                if ($token !== '') {
                    $this->token            = $token;
                    $this->token_expires_at = (int) $cached['expires_at'];
                    return $token;
                }
            }
        }
        $config   = PluginPrintgestionConfig::getInstance();
        $id       = trim((string) ($config->fields['gls_client_id'] ?? ''));
        $secret   = PluginPrintgestionConfig::getSecret('gls_client_secret');
        if ($id === '' || $secret === '') {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_AUTH, __('Identifiant ou secret GLS non saisis, ou secret indéchiffrable.', 'printgestion'));
        }
        $response = $this->send('POST', self::TOKEN_URL, [
            'Authorization' => 'Basic ' . base64_encode($id . ':' . $secret),
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Accept'        => 'application/json',
        ], 'grant_type=client_credentials');
        unset($secret);
        $status = (int) $response['status'];
        if ($status === 401 || $status === 403) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_AUTH, sprintf(__('Identifiant ou secret GLS refusé (HTTP %1$d%2$s).', 'printgestion'), $status, self::apigeeDetail($response['body'])));
        }
        if ($status === 429) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_QUOTA, __('Quota GLS dépassé sur la demande de jeton (HTTP 429).', 'printgestion'));
        }
        if ($status !== 200) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, sprintf(__('Jeton GLS non obtenu (HTTP %d).', 'printgestion'), $status));
        }
        $data  = json_decode((string) $response['body'], true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($token === '') {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, __('Jeton GLS non obtenu : réponse sans access_token.', 'printgestion'));
        }
        $ttl = max(1, (int) ($data['expires_in'] ?? 0) - self::TOKEN_MARGIN);
        $this->token            = $token;
        $this->token_expires_at = time() + $ttl;
        if ($use_cache && isset($GLPI_CACHE)) {
            $GLPI_CACHE->set(self::TOKEN_CACHE_KEY, ['token' => (new GLPIKey())->encrypt($token), 'expires_at' => $this->token_expires_at], $ttl);
        }
        return $token;
    }

    public function forgetToken(): void {
        global $GLPI_CACHE;
        $this->token            = null;
        $this->token_expires_at = 0;
        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->delete(self::TOKEN_CACHE_KEY);
        }
    }

    // ── Transport ─────────────────────────────────────────────────────────────

    private function send(string $method, string $url, array $headers, ?string $body): array {
        try {
            $response = ($this->transport)($method, $url, $headers, $body);
        } catch (PluginPrintgestionCarrierexception $e) {
            throw $e;
        } catch (Throwable $e) {
            // Jamais le message brut : il peut porter l'URL du jeton avec ce que le transport y a mis.
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_NETWORK, sprintf(__('GLS injoignable (%s).', 'printgestion'), (new ReflectionClass($e))->getShortName()));
        }
        if (!is_array($response) || !isset($response['status'])) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_NETWORK, __('GLS injoignable (réponse vide).', 'printgestion'));
        }
        return ['status' => (int) $response['status'], 'body' => (string) ($response['body'] ?? '')];
    }

    /** Transport de production : Guzzle avec le proxy réglé dans GLPI, sans exception sur les codes HTTP. */
    private function sendWithGuzzle(string $method, string $url, array $headers, ?string $body): array {
        $client   = Toolbox::getGuzzleClient(['connect_timeout' => self::CONNECT_TIMEOUT, 'timeout' => self::TIMEOUT, 'http_errors' => false]);
        $response = $client->request($method, $url, ['headers' => $headers, 'body' => $body]);
        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    /** « fault.faultstring » d'une erreur Apigee (authentification, quota), pour le message ; rien d'autre. */
    private static function apigeeDetail(string $body): string {
        $data = json_decode($body, true);
        $text = is_array($data) ? trim((string) ($data['fault']['faultstring'] ?? '')) : '';
        return $text === '' ? '' : ' : ' . mb_substr($text, 0, 120);
    }

    /** « title » et « detail » d'un ErrorResponseDTO, pour le message. */
    private static function problemDetail(string $body): string {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return '';
        }
        $text = trim(implode(' — ', array_filter([(string) ($data['title'] ?? ''), (string) ($data['detail'] ?? '')])));
        return $text === '' ? '' : ' : ' . mb_substr($text, 0, 160);
    }

    // ── Mémo de santé et de quota ─────────────────────────────────────────────

    public static function getMemo(): array {
        $memo = Config::getConfigurationValues(self::MEMO_CONTEXT, self::MEMO_KEYS);
        $today = date('Y-m-d');
        return [
            'last_success'  => (string) ($memo['gls_last_success'] ?? ''),
            'failures'      => (int) ($memo['gls_failures'] ?? 0),
            'last_error'    => (string) ($memo['gls_last_error'] ?? ''),
            'quota_count'   => (string) ($memo['gls_quota_day'] ?? '') === $today ? (int) ($memo['gls_quota_count'] ?? 0) : 0,
            'quota_blocked' => (string) ($memo['gls_quota_blocked_day'] ?? '') === $today,
        ];
    }

    /** Requêtes de suivi encore permises aujourd'hui (80 % du quota, moins ce qui est consommé ; 0 après un 429). */
    public static function quotaRemaining(): int {
        $memo = self::getMemo();
        if ($memo['quota_blocked']) {
            return 0;
        }
        return max(0, (int) floor(self::DAILY_QUOTA * self::QUOTA_STOP_RATIO) - $memo['quota_count']);
    }

    /** Une requête de suivi de plus aujourd'hui (les demandes de jeton ne comptent pas dans ce quota). */
    public static function countRequest(): void {
        $memo = Config::getConfigurationValues(self::MEMO_CONTEXT, ['gls_quota_day', 'gls_quota_count']);
        $today = date('Y-m-d');
        $count = (string) ($memo['gls_quota_day'] ?? '') === $today ? (int) ($memo['gls_quota_count'] ?? 0) : 0;
        Config::setConfigurationValues(self::MEMO_CONTEXT, ['gls_quota_day' => $today, 'gls_quota_count' => $count + 1]);
    }

    public static function noteSuccess(): void {
        Config::setConfigurationValues(self::MEMO_CONTEXT, ['gls_last_success' => date('Y-m-d H:i:s'), 'gls_failures' => 0, 'gls_last_error' => '']);
    }

    /** Un échec technique de plus, avec sa cause courte (jamais un secret). */
    public static function noteFailure(string $message): void {
        $memo = Config::getConfigurationValues(self::MEMO_CONTEXT, ['gls_failures']);
        Config::setConfigurationValues(self::MEMO_CONTEXT, [
            'gls_failures'   => (int) ($memo['gls_failures'] ?? 0) + 1,
            'gls_last_error' => mb_substr(date('Y-m-d H:i:s') . ' — ' . $message, 0, 255),
        ]);
    }

    public static function noteQuotaBlocked(): void {
        Config::setConfigurationValues(self::MEMO_CONTEXT, ['gls_quota_blocked_day' => date('Y-m-d')]);
    }

    /** Efface le mémo (retrait des clés, harnais). */
    public static function resetMemo(): void {
        Config::setConfigurationValues(self::MEMO_CONTEXT, array_fill_keys(self::MEMO_KEYS, ''));
    }
}
