<?php
/**
 * PluginPrintgestionPublicclient — lecture des pages de suivi publiques d'UPS et de GLS France : le JSON que chaque
 * site appelle lui-même pour montrer un suivi au destinataire, sans compte ni clé.
 *
 * Pourquoi, en plus de l'API GLS et de MBE : pour ne rien oublier. MBE reste « en attente de livraison » des jours
 * après une remise, l'API GLS demande des clés, et UPS n'a aucune autre intégration. Ces deux pages voient ce que voit
 * le destinataire, le jour même. Elles s'ajoutent sans rien remplacer : Publictracking ne les lit que pour les
 * expéditions qu'aucune autre source n'a encore constatées livrées.
 *
 * Ce ne sont pas des API publiées : ni contrat, ni documentation, ni garantie. Le jour où UPS ou GLS change sa page,
 * la lecture échoue (exception typée, journalisée, carte Santé au rouge) ; elle ne conclut jamais rien par défaut.
 * La page UPS est en plus gardée par un filtre anti-robots qui peut refuser l'accès (403 « Access Denied ») : on ne le
 * contourne pas, on s'arrête (exception « quota », Publictracking laisse alors UPS de côté quelques heures).
 *
 * Formes relevées le 19/10/2026 sur des envois réels (deux UPS, deux GLS), jamais recopiés dans le dépôt :
 *
 *   UPS  GET  Track/GetLookupData : pose le cookie X-XSRF-TOKEN-ST (session anonyme, gardée le temps d'un passage,
 *             jamais écrite nulle part) ;
 *        POST Track/GetStatus, un numéro par appel, en-tête X-XSRF-TOKEN. Deux numéros dans un appel, ou un numéro à
 *             la clé de contrôle fausse : statusCode 402 « Invalid Request » — d'où le contrôle de la clé avant tout
 *             appel. Réponse : statusCode « 200 », trackDetails[] avec isDelivered, packageStatusType (D livré,
 *             X anomalie), isDeliveredToUAP (déposé en UPS Access Point), isPickedUpByCustomer, receivedBy,
 *             shipToAddressLblKey, shipmentProgressActivities[] (gmtDate et gmtTime en UTC, milestoneName.nameKey).
 *             Numéro inconnu : errorCode renseigné.
 *   GLS  GET  consignee-ws/api/v1/command/public/codes/{Track ID} : 8 caractères en majuscules (le code à 10 de
 *             l'étiquette et la minuscule rendent 404, d'où le repli de Glsnumber). Réponse : colis.statutColis,
 *             colis.relaisGlsColis (« 0 » ou vide hors point relais), evenements[] avec typeEvenement,
 *             statutEvenement, datereference (heure de Paris sans décalage, « 2026-10-19 09:06:17.0 »),
 *             relaisglsEvenement, nomSignataire. Remise : STATUT LTV ou LIV ; le type LIV n'est qu'un passage du
 *             livreur, qui peut échouer (type LIV, statut PBA : non livré). Code inconnu : 404 en texte brut.
 *
 * Règle du point relais, la même que pour l'API GLS (DELIVEREDPS) : un colis déposé en point relais ou en UPS Access
 * Point n'est pas livré, personne ne l'a encore. Un retour à l'expéditeur non plus.
 *
 * Le transport HTTP est une fonction injectable : Guzzle (proxy de GLPI) en production, des réponses inventées dans
 * le harnais. Rien n'est écrit en base ici.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionPublicclient {

    const UPS_LOOKUP_URL = 'https://webapis.ups.com/track/api/Track/GetLookupData?loc=en_FR';
    const UPS_STATUS_URL = 'https://webapis.ups.com/track/api/Track/GetStatus?loc=en_FR';
    const UPS_ORIGIN     = 'https://www.ups.com';
    const UPS_CLIENT_URL = 'https://www.ups.com/track?loc=en_FR&requester=ST/trackdetails';
    /** Cookie qui porte le jeton anti-falsification de la session anonyme UPS. */
    const UPS_TOKEN_COOKIE = 'X-XSRF-TOKEN-ST';
    const GLS_URL = 'https://public.infra-prod.prod.cloud.fr.gls-group.com/consignee-ws/api/v1/command/public/codes/';
    /** Les dates GLS France arrivent sans décalage, et le jalon UPS en heure locale du destinataire. */
    const FRANCE_TIMEZONE = 'Europe/Paris';
    /** Le plugin se présente pour ce qu'il est : aucun déguisement en navigateur. */
    const USER_AGENT = 'JCD-PrintGestion/1.0';
    const CONNECT_TIMEOUT = 5;
    const TIMEOUT         = 15;

    /** « Tester la connexion » : un numéro UPS inventé à la clé de contrôle juste, et un code GLS inventé — tous deux inconnus. */
    const UPS_TEST_NUMBER = '1ZTEST000000000005';
    const GLS_TEST_CODE   = 'TESTPG00';

    /** Verdicts, les mêmes pour les deux transporteurs. Seul « delivered » fait constater une livraison. */
    const STATUS_DELIVERED = 'delivered';
    const STATUS_RELAY     = 'relay';
    const STATUS_RETURNED  = 'returned';
    const STATUS_EXCEPTION = 'exception';
    const STATUS_TRANSIT   = 'transit';
    const STATUS_NOT_FOUND = 'not_found';

    /**
     * STATUTS GLS France qui valent remise (statutColis ou statutEvenement, jamais typeEvenement). LTV et LIV sont
     * relevés sur des envois réels ; les quatre autres sont ceux que le hub JCD retient déjà, pas observés ici. Le
     * point relais ne se déduit jamais d'un code : il se lit dans relaisGlsColis et relaisglsEvenement.
     */
    const GLS_DELIVERED_CODES = ['LIV', 'LTV', 'LTL', 'LIL', 'LIT', 'LTT'];
    /** Codes GLS France d'anomalie, repris du hub JCD : comptés, jamais décisifs. */
    const GLS_EXCEPTION_CODES = ['PBP', 'PBA', 'SIN', 'NLI', 'NLK'];

    /** @var callable fn(string $method, string $url, array $headers, ?string $body): ['status' => int, 'body' => string, 'headers' => array] */
    private $transport;
    /** Session anonyme UPS du passage (en-tête Cookie et jeton) : jamais écrite, oubliée avec l'objet. */
    private ?array $ups_session = null;
    /** Requêtes envoyées par cet objet, c'est-à-dire pendant un passage de la tâche. */
    private int $requests = 0;

    public function __construct(?callable $transport = null) {
        $this->transport = $transport ?? [$this, 'sendWithGuzzle'];
    }

    public function getRequestCount(): int {
        return $this->requests;
    }

    // ── Numéros ───────────────────────────────────────────────────────────────

    /** Numéro UPS comparable : majuscules, sans espace ni séparateur. */
    public static function normalizeUpsNumber(string $raw): string {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    /**
     * Numéro « 1Z » bien formé, clé de contrôle comprise. UPS répond 402 à un numéro dont la clé est fausse, et ce
     * refus ressemble à celui d'une requête qu'il ne comprend plus : contrôlé ici avant tout appel, un 402 sur un
     * numéro bien formé ne veut plus dire qu'une chose, la page UPS a changé.
     *
     * Clé : sur les quinze caractères qui suivent « 1Z », chaque lettre ramenée à un chiffre ((code ASCII − 63) mod 10),
     * rangs impairs additionnés, rangs pairs additionnés et doublés ; la clé complète la somme à la dizaine.
     */
    public static function isUpsNumber(string $number): bool {
        if (preg_match('/^1Z[A-Z0-9]{16}$/', $number) !== 1) {
            return false;
        }
        $sum = 0;
        foreach (str_split(substr($number, 2, 15)) as $i => $char) {
            $value = ctype_digit($char) ? (int) $char : (ord($char) - 63) % 10;
            $sum  += $i % 2 === 0 ? $value : 2 * $value;
        }
        return (string) ((10 - $sum % 10) % 10) === $number[17];
    }

    // ── Appels ────────────────────────────────────────────────────────────────

    /**
     * Ce que la page de suivi UPS dit d'un numéro : un appel, plus l'ouverture de la session au premier numéro du
     * passage. Une session refusée (401, 403 sans page de blocage) est rouverte une fois.
     *
     * @return array verdict ['status' => STATUS_*, 'date' => ?string, 'detail' => string, 'raw' => string]
     * @throws PluginPrintgestionCarrierexception
     */
    public function trackUps(string $number): array {
        $number = self::normalizeUpsNumber($number);
        if (!self::isUpsNumber($number)) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, sprintf(__('Numéro UPS mal formé (« %s ») : pas d\'appel.', 'printgestion'), $number));
        }
        $body = json_encode([
            'Locale'               => 'en_FR',
            'TrackingNumber'       => [$number],
            'isBarcodeScanned'     => false,
            'AssociatedBcdnNumber' => null,
            'ClientUrl'            => self::UPS_CLIENT_URL,
            'Requester'            => 'st/trackdetails',
            'returnToValue'        => '',
        ], JSON_UNESCAPED_SLASHES);
        for ($attempt = 1; ; $attempt++) {
            $session  = $this->upsSession();
            $response = $this->send('UPS', 'POST', self::UPS_STATUS_URL, [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
                'Origin'       => self::UPS_ORIGIN,
                'X-XSRF-TOKEN' => $session['token'],
                'Cookie'       => $session['cookie'],
            ], $body);
            if ($attempt === 1 && in_array($response['status'], [401, 403], true) && !self::isBlockPage($response['body'])) {
                $this->ups_session = null; // session expirée : une nouvelle, une seule fois
                continue;
            }
            break;
        }
        self::assertHttp('UPS', $response);
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, __('Réponse UPS illisible (pas un JSON) : la page de suivi a peut-être changé.', 'printgestion'));
        }
        return self::parseUps($data, $number);
    }

    /**
     * Ce que la page de suivi GLS France dit d'un code : un appel. Un code inconnu (404) est un verdict, pas une erreur.
     *
     * @return array verdict ['status' => STATUS_*, 'date' => ?string, 'detail' => string, 'raw' => string]
     * @throws PluginPrintgestionCarrierexception
     */
    public function trackGls(string $code): array {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z0-9_]{5,20}$/', $code) !== 1) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, sprintf(__('Numéro GLS mal formé (« %s ») : pas d\'appel.', 'printgestion'), mb_substr($code, 0, 40)));
        }
        $response = $this->send('GLS', 'GET', self::GLS_URL . rawurlencode($code), ['Accept' => 'application/json'], null);
        if ($response['status'] === 404) {
            return self::verdict(self::STATUS_NOT_FOUND, null, __('GLS (suivi public) : numéro inconnu.', 'printgestion'), 'GLS:404');
        }
        self::assertHttp('GLS', $response);
        $data = json_decode($response['body'], true);
        if (!is_array($data) || !is_array($data['colis'] ?? null)) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, __('Réponse GLS sans « colis » : la page de suivi a peut-être changé.', 'printgestion'));
        }
        return self::parseGls($data, $code);
    }

    /**
     * « Tester la connexion » : une session UPS ouverte et une réponse UPS comprise (numéro inventé, que UPS dit
     * inconnu), une réponse GLS reçue (code inventé, que GLS dit inconnu). Une phrase par page ; rien n'est enregistré
     * hormis le mémo de santé de chacune.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public function testConnection(): array {
        $ok    = true;
        $lines = [];
        foreach (['ups' => 'UPS', 'gls' => 'GLS'] as $carrier => $name) {
            try {
                $verdict = $carrier === 'ups' ? $this->trackUps(self::UPS_TEST_NUMBER) : $this->trackGls(self::GLS_TEST_CODE);
            } catch (PluginPrintgestionCarrierexception $e) {
                $ok = false;
                PluginPrintgestionPublictracking::noteFailure($carrier, $e->getMessage(), $e->getKind());
                $lines[] = sprintf(__('%1$s : %2$s', 'printgestion'), $name, $e->getMessage());
                continue;
            }
            PluginPrintgestionPublictracking::noteSuccess($carrier);
            $lines[] = $verdict['status'] === self::STATUS_NOT_FOUND
                ? sprintf(__('%s : page de suivi jointe et réponse comprise (numéro de test inconnu, comme attendu).', 'printgestion'), $name)
                : sprintf(__('%1$s : page de suivi jointe, réponse comprise (numéro de test lu « %2$s »).', 'printgestion'), $name, $verdict['status']);
        }
        return ['ok' => $ok, 'message' => implode(' ', $lines)];
    }

    // ── Lecture des réponses ──────────────────────────────────────────────────

    /**
     * Verdict d'une réponse UPS pour un numéro. Retour à l'expéditeur avant tout (un retour « livré » est livré chez
     * nous) ; puis remise, sauf dépôt en UPS Access Point pas encore retiré ; puis anomalie ; sinon en cours.
     *
     * @throws PluginPrintgestionCarrierexception statusCode autre que 200 (la requête n'est plus comprise), structure inattendue
     */
    public static function parseUps(array $data, string $number): array {
        $code = (string) ($data['statusCode'] ?? '');
        if ($code !== '200') {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, sprintf(__('UPS refuse la requête (statusCode %s) : la page de suivi a peut-être changé.', 'printgestion'), $code !== '' ? $code : '?'));
        }
        if (!is_array($data['trackDetails'] ?? null)) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, __('Réponse UPS sans « trackDetails » : la page de suivi a peut-être changé.', 'printgestion'));
        }
        $detail = null;
        foreach ($data['trackDetails'] as $candidate) {
            if (is_array($candidate) && in_array($number, [strtoupper((string) ($candidate['trackingNumber'] ?? '')), strtoupper((string) ($candidate['requestedTrackingNumber'] ?? ''))], true)) {
                $detail = $candidate;
                break;
            }
        }
        // Numéro inconnu : UPS rend une seule entrée, sans numéro, avec son code d'erreur. Elle ne sert qu'à ce code.
        $error = $detail ?? (count($data['trackDetails']) === 1 && is_array(reset($data['trackDetails'])) ? reset($data['trackDetails']) : []);
        if ($detail === null || !empty($detail['errorCode']) || !empty($detail['errorText'])) {
            return self::verdict(self::STATUS_NOT_FOUND, null, __('UPS (suivi public) : numéro inconnu.', 'printgestion'), 'UPS:' . (string) (($error['errorCode'] ?? '') ?: 'INCONNU'));
        }
        $type      = strtoupper(trim((string) ($detail['packageStatusType'] ?? '')));
        $milestone = is_array($detail['currentMilestone'] ?? null) ? $detail['currentMilestone'] : [];
        $key       = strtolower(trim((string) ($milestone['nameKey'] ?? '')));
        $returned  = ($detail['shipToAddressLblKey'] ?? '') === 'cms.stapp.returnedTo' || ($detail['shipToAddress']['isReturnAddress'] ?? false) === true;
        $delivered = ($detail['isDelivered'] ?? false) === true || $type === 'D' || $key === 'cms.stapp.delivered';
        $relay     = ($detail['isDeliveredToUAP'] ?? false) === true && ($detail['isPickedUpByCustomer'] ?? false) !== true;
        $status    = match (true) {
            $returned                                      => self::STATUS_RETURNED,
            $delivered                                     => $relay ? self::STATUS_RELAY : self::STATUS_DELIVERED,
            $type === 'X' || str_contains($key, 'exception') => self::STATUS_EXCEPTION,
            default                                        => self::STATUS_TRANSIT,
        };
        $label = self::text($detail['packageStatus'] ?? '');
        $text  = sprintf(__('UPS (suivi public) : %s', 'printgestion'), $label !== '' ? $label : ($type !== '' ? $type : __('sans statut', 'printgestion')));
        $received = self::text($detail['receivedBy'] ?? '');
        if ($status === self::STATUS_DELIVERED && $received !== '') {
            $text .= sprintf(__(', reçu par %s', 'printgestion'), $received);
        } elseif ($status === self::STATUS_RELAY) {
            $text .= __(', déposé en UPS Access Point', 'printgestion');
        }
        return self::verdict(
            $status,
            $status === self::STATUS_DELIVERED ? self::upsDeliveryDate($detail, $milestone) : null,
            $text . '.',
            'UPS:' . ($type !== '' ? $type : '?') . '/' . (string) ($detail['packageStatusCode'] ?? '')
        );
    }

    /**
     * Verdict d'une réponse GLS France.
     *
     * Le TYPE d'un événement dit ce qui s'est passé (LIV : passage du livreur, TRV : mise en tournée, REC : arrivée en
     * agence…) ; seul son STATUT dit le résultat (LTV, LIV : livré ; PBA : non livré, adresse à préciser). Un passage
     * peut échouer : type LIV, statut PBA. Et c'est l'état actuel qui compte — statut du colis, ou de son dernier
     * événement — jamais un événement passé suivi d'autre chose. Lire le type, ou le premier événement venu, a fait
     * passer « livré » un colis non livré puis revenu en agence (constaté le 21/10/2026) ; Delivery ne revenant jamais
     * sur « livrée », cette lecture doit être juste du premier coup.
     *
     * Un point relais sur le colis ou sur l'événement de remise en fait un dépôt, pas une livraison.
     */
    public static function parseGls(array $data, string $code): array {
        $colis   = is_array($data['colis'] ?? null) ? $data['colis'] : [];
        $summary = strtoupper(trim((string) ($colis['statutColis'] ?? '')));
        $events  = [];
        foreach ((array) ($data['evenements'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $events[] = [
                'type'   => strtoupper(trim((string) ($event['typeEvenement'] ?? ''))),
                'statut' => strtoupper(trim((string) ($event['statutEvenement'] ?? ''))),
                'date'   => self::glsDate((string) ($event['datereference'] ?? '')) ?? self::glsDate((string) ($event['datecreation'] ?? '')),
                'chrono' => (int) ($event['chronoEvenement'] ?? 0),
                'relay'  => self::isGlsRelay($event['relaisglsEvenement'] ?? ''),
                'signed' => self::text($event['nomSignataire'] ?? ''),
            ];
        }
        // Du plus récent au plus ancien : date de référence, puis ordre chronologique de GLS (l'ordre reçu ne fait pas foi).
        usort($events, static fn(array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']) ?: $b['chrono'] <=> $a['chrono']);
        $current   = (string) ($events[0]['statut'] ?? '');
        $delivered = in_array($summary, self::GLS_DELIVERED_CODES, true) || in_array($current, self::GLS_DELIVERED_CODES, true);
        $exception = !$delivered && (in_array($summary, self::GLS_EXCEPTION_CODES, true) || in_array($current, self::GLS_EXCEPTION_CODES, true));
        // Remise : l'événement au statut de livraison le plus récent, de préférence le passage du livreur (type LIV).
        $remise = null;
        if ($delivered) {
            foreach ($events as $event) {
                if (!in_array($event['statut'], self::GLS_DELIVERED_CODES, true)) {
                    continue;
                }
                if ($remise === null || ($event['type'] === 'LIV' && $remise['type'] !== 'LIV')) {
                    $remise = $event;
                }
            }
        }
        $relay  = self::isGlsRelay($colis['relaisGlsColis'] ?? '') || ($remise['relay'] ?? false) === true;
        $status = match (true) {
            $delivered => $relay ? self::STATUS_RELAY : self::STATUS_DELIVERED,
            $exception => self::STATUS_EXCEPTION,
            default    => self::STATUS_TRANSIT,
        };
        // Code qui fonde le verdict : statut du colis d'abord, sinon celui du dernier événement.
        $codes  = $delivered ? self::GLS_DELIVERED_CODES : ($exception ? self::GLS_EXCEPTION_CODES : []);
        $shown  = $codes !== [] ? (in_array($summary, $codes, true) ? $summary : $current) : ($summary !== '' ? $summary : $current);
        $text   = sprintf(__('GLS (suivi public) : %s', 'printgestion'), $shown !== '' ? $shown : __('sans statut', 'printgestion'));
        $signed = (string) ($remise['signed'] ?? '') !== '' ? (string) $remise['signed'] : self::text($colis['nomSignataireColis'] ?? '');
        if ($status === self::STATUS_DELIVERED && $signed !== '') {
            $text .= sprintf(__(', signé %s', 'printgestion'), $signed);
        } elseif ($status === self::STATUS_RELAY) {
            $text .= __(', déposé en point relais', 'printgestion');
        } elseif ($status === self::STATUS_EXCEPTION && $shown === 'PBA') {
            $text .= __(', non livré : adresse à préciser', 'printgestion');
        }
        return self::verdict(
            $status,
            $status === self::STATUS_DELIVERED ? ($remise['date'] ?? null) : null,
            $text . '.',
            'GLS:' . ($shown !== '' ? $shown : '?')
        );
    }

    /** Point relais GLS : un identifiant de relais renseigné. « 0 » et vide disent qu'il n'y en a pas. */
    private static function isGlsRelay($value): bool {
        return is_scalar($value) && ltrim(trim((string) $value), '0') !== '';
    }

    /**
     * Date de la remise UPS : l'activité « Delivered » la plus récente, donnée en UTC ; à défaut le jalon courant, en
     * heure locale du destinataire avec le décalage que donne la réponse. Rendue à l'heure du serveur.
     */
    private static function upsDeliveryDate(array $detail, array $milestone): ?string {
        $best = null;
        foreach ((array) ($detail['shipmentProgressActivities'] ?? []) as $activity) {
            if (!is_array($activity) || (string) ($activity['milestoneName']['nameKey'] ?? '') !== 'cms.stapp.delivered') {
                continue;
            }
            $day  = (string) ($activity['gmtDate'] ?? '');
            $time = (string) ($activity['gmtTime'] ?? '');
            if (preg_match('/^\d{8}$/', $day) !== 1 || preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) !== 1) {
                continue;
            }
            $date = DateTime::createFromFormat('!Ymd H:i:s', $day . ' ' . $time, new DateTimeZone('UTC'));
            if ($date !== false) {
                $local = self::toServerTime($date);
                $best  = ($best === null || $local > $best) ? $local : $best;
            }
        }
        if ($best !== null) {
            return $best;
        }
        $day    = trim((string) ($milestone['date'] ?? ''));
        $time   = trim((string) ($milestone['time'] ?? ''));
        $offset = (string) ($detail['shipmentGMTInfo']['shipToGMTOffset'] ?? '');
        if (preg_match('~^\d{2}/\d{2}/\d{4}$~', $day) !== 1 || preg_match('/^\d{1,2}:\d{2}$/', $time) !== 1) {
            return null;
        }
        $zone = new DateTimeZone(preg_match('/^[+-]\d{2}:\d{2}$/', $offset) === 1 ? $offset : self::FRANCE_TIMEZONE);
        $date = DateTime::createFromFormat('!d/m/Y G:i', $day . ' ' . $time, $zone);
        return $date === false ? null : self::toServerTime($date);
    }

    /** Date GLS (« 2026-10-19 09:06:17.0 », heure de Paris) à l'heure du serveur ; null si vide, illisible ou nulle (0001-01-01). */
    private static function glsDate(string $value): ?string {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}:\d{2})/', trim($value), $m) !== 1 || (int) $m[1] < 2000) {
            return null;
        }
        $date = DateTime::createFromFormat('!Y-m-d H:i:s', "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}", new DateTimeZone(self::FRANCE_TIMEZONE));
        return $date === false ? null : self::toServerTime($date);
    }

    private static function toServerTime(DateTime $date): string {
        return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** Texte d'un champ de réponse : scalaire seulement, entités HTML décodées (« UPS Standard&#174; »). */
    private static function text($value): string {
        return is_scalar($value) ? trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    private static function verdict(string $status, ?string $date, string $detail, string $raw): array {
        return ['status' => $status, 'date' => $date, 'detail' => mb_substr($detail, 0, 255), 'raw' => mb_substr($raw, 0, 40)];
    }

    // ── Transport ─────────────────────────────────────────────────────────────

    /** Session anonyme UPS : cookies posés par la page d'amorce, dont le jeton. Une par objet, donc par passage. */
    private function upsSession(): array {
        if ($this->ups_session !== null) {
            return $this->ups_session;
        }
        $response = $this->send('UPS', 'GET', self::UPS_LOOKUP_URL, ['Accept' => 'application/json', 'Origin' => self::UPS_ORIGIN], null);
        self::assertHttp('UPS', $response);
        $cookies = [];
        foreach ($response['headers'] as $name => $values) {
            if (strtolower((string) $name) !== 'set-cookie') {
                continue;
            }
            foreach ((array) $values as $line) {
                if (preg_match('/^\s*([^=;\s]+)=([^;]*)/', (string) $line, $m) === 1) {
                    $cookies[$m[1]] = $m[2];
                }
            }
        }
        $token = rawurldecode((string) ($cookies[self::UPS_TOKEN_COOKIE] ?? ''));
        if ($token === '') {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, __('Session anonyme UPS non obtenue : pas de jeton dans la réponse (la page de suivi a peut-être changé).', 'printgestion'));
        }
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        return $this->ups_session = ['token' => $token, 'cookie' => implode('; ', $pairs)];
    }

    /** Page « Access Denied » du filtre anti-robots, rendue en HTML à la place du JSON. */
    private static function isBlockPage(string $body): bool {
        return stripos($body, 'Access Denied') !== false;
    }

    /**
     * Codes HTTP d'une réponse qui n'est pas un verdict. 403 et 429 : le site refuse l'accès pour le moment (filtre
     * anti-robots, trop d'appels) — exception « quota », l'appelant laisse ce transporteur de côté sans insister.
     *
     * @throws PluginPrintgestionCarrierexception
     */
    private static function assertHttp(string $name, array $response): void {
        $status = (int) $response['status'];
        if ($status === 200) {
            return;
        }
        if ($status === 403 || $status === 429) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_QUOTA, sprintf(__('%1$s refuse l\'accès à sa page de suivi (HTTP %2$d%3$s) : plus d\'appel pendant quelques heures.', 'printgestion'), $name, $status, self::isBlockPage($response['body']) ? __(', filtre anti-robots', 'printgestion') : ''));
        }
        if ($status >= 500) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_SERVER, sprintf(__('%1$s indisponible (HTTP %2$d).', 'printgestion'), $name, $status));
        }
        throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_REQUEST, sprintf(__('Réponse inattendue de %1$s (HTTP %2$d) : la page de suivi a peut-être changé.', 'printgestion'), $name, $status));
    }

    private function send(string $name, string $method, string $url, array $headers, ?string $body): array {
        $headers += ['User-Agent' => self::USER_AGENT];
        $this->requests++;
        try {
            $response = ($this->transport)($method, $url, $headers, $body);
        } catch (PluginPrintgestionCarrierexception $e) {
            throw $e;
        } catch (Throwable $e) {
            // Jamais le message brut : il peut porter l'URL et les en-têtes, donc le jeton de session.
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_NETWORK, sprintf(__('%1$s injoignable (%2$s).', 'printgestion'), $name, (new ReflectionClass($e))->getShortName()));
        }
        if (!is_array($response) || !isset($response['status'])) {
            throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_NETWORK, sprintf(__('%s injoignable (réponse vide).', 'printgestion'), $name));
        }
        return [
            'status'  => (int) $response['status'],
            'body'    => (string) ($response['body'] ?? ''),
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
        ];
    }

    /** Transport de production : Guzzle avec le proxy réglé dans GLPI, TLS vérifié, sans suivre les redirections. */
    private function sendWithGuzzle(string $method, string $url, array $headers, ?string $body): array {
        $client   = PluginPrintgestionHttp::guzzle(['connect_timeout' => self::CONNECT_TIMEOUT, 'timeout' => self::TIMEOUT, 'http_errors' => false, 'allow_redirects' => false]);
        $response = $client->request($method, $url, ['headers' => $headers, 'body' => $body]);
        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody(), 'headers' => $response->getHeaders()];
    }
}
