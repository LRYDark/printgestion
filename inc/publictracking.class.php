<?php
/**
 * PluginPrintgestionPublictracking — la vérification « en plus » : pour chaque expédition UPS ou GLS partie et pas
 * encore livrée, ce que disent les pages de suivi publiques d'UPS et de GLS France (Publicclient).
 *
 * Elle passe en dernier dans la tâche de suivi, après le suivi GLS par l'API et après MBE : elle ne lit donc que ce
 * qu'aucune autre source n'a encore constaté livré, et n'ajoute aucun appel quand tout est déjà su. Elle ne remplace
 * rien : l'API GLS, MBE et le BL signé font exactement ce qu'ils faisaient. Pour UPS, c'est la seule lecture du
 * transporteur lui-même.
 *
 * Ce qu'elle fait d'un verdict : une remise au destinataire devient un constat de livraison remis à Delivery, avec la
 * source du transporteur (« gls » ou « ups » : c'est l'événement du transporteur qu'on lit, par une autre porte ; le
 * détail du constat dit « suivi public »). Point relais, retour, anomalie, numéro inconnu : comptés dans le journal de
 * la tâche, rien n'est écrit. Elle n'a aucune colonne (le schéma de la 1.0.0 n'en ajoute pas sur une base installée) :
 * son état tient dans le contexte de configuration du plugin — interrupteur, dernier passage, santé par transporteur.
 *
 * Cadence et bornes, déduites (aucun réglage hormis l'interrupteur) :
 *   - un passage par heure au plus, même si la tâche est remise à la minute ;
 *   - expéditions parties depuis 30 jours au plus, 150 au plus par passage, les plus anciennes d'abord ;
 *   - un appel à la fois, une pause entre deux : ce sont des pages faites pour des personnes ;
 *   - un transporteur qui refuse l'accès (403, 429 ; la page UPS est gardée par un filtre anti-robots) est laissé de
 *     côté six heures, sans insister et sans chercher à passer ;
 *   - cinq échecs techniques consécutifs arrêtent ce transporteur pour le passage.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionPublictracking {

    const TABLE          = 'glpi_plugin_printgestion_expeditions';
    const CONFIG_CONTEXT = 'plugin:printgestion';
    /** Interrupteur de la carte « Vérification par les pages de suivi publiques ». Absent = activé. */
    const SETTING = 'public_tracking_enabled';

    const CARRIERS      = ['ups', 'gls'];
    /** Parties, pas encore livrées : une expédition livrée, posée ou annulée n'a plus rien à apprendre. */
    const LIVE_STATUSES = ['shipped', 'transit'];
    const LOOKUP_DAYS   = 30;
    const MAX_PER_PASS  = 150;
    const MIN_INTERVAL  = HOUR_TIMESTAMP;
    const PAUSE_MICROSECONDS = 500000;
    const BLOCK_DURATION     = 6 * HOUR_TIMESTAMP;
    const BREAKER_THRESHOLD  = 5;

    // ── Interrupteur ──────────────────────────────────────────────────────────

    public static function isEnabled(): bool {
        $value = Config::getConfigurationValue(self::CONFIG_CONTEXT, self::SETTING);
        return $value === null || $value === '' || (int) $value === 1;
    }

    public static function setEnabled(bool $enabled): void {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::SETTING => $enabled ? '1' : '0']);
    }

    // ── Tâche ─────────────────────────────────────────────────────────────────

    /**
     * Un passage : expéditions à vérifier, un appel par expédition (deux ou trois pour un code GLS à replier), constats
     * de livraison remis à Delivery en une fois.
     *
     * @param int|null $pause microsecondes entre deux appels (0 dans le harnais)
     * @return array ['checked', 'delivered', 'relay', 'returned', 'exception', 'not_found', 'invalid', 'requests' => int,
     *                'stopped' => '' | 'disabled' | 'too_soon' | « ups:blocked, gls:breaker »…]
     */
    public static function poll(?CronTask $task = null, ?PluginPrintgestionPublicclient $client = null, ?string $now = null, ?int $pause = null): array {
        $client ??= new PluginPrintgestionPublicclient();
        $now    ??= date('Y-m-d H:i:s');
        $pause  ??= self::PAUSE_MICROSECONDS;
        $stats    = ['checked' => 0, 'delivered' => 0, 'relay' => 0, 'returned' => 0, 'exception' => 0, 'not_found' => 0, 'invalid' => 0, 'requests' => 0, 'stopped' => ''];

        if (!self::isEnabled()) {
            $stats['stopped'] = 'disabled';
            return $stats;
        }
        if (!self::isDue($now)) {
            $stats['stopped'] = 'too_soon';
            return $stats;
        }
        $rows = self::candidates($now);
        if (empty($rows)) {
            return $stats;
        }
        Config::setConfigurationValues(self::CONFIG_CONTEXT, ['public_last_poll' => $now]);

        $stopped  = [];
        $failures = array_fill_keys(self::CARRIERS, 0);
        $last_ok  = [];
        $constats = [];
        foreach (self::CARRIERS as $carrier) {
            if (self::isBlocked($carrier, $now)) {
                $stopped[$carrier] = 'blocked';
            }
        }
        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $carrier = (string) $row['transport_carrier'];
            if (isset($stopped[$carrier])) {
                continue;
            }
            if ($pause > 0 && $client->getRequestCount() > 0) {
                usleep($pause);
            }
            try {
                $verdict = $carrier === 'ups' ? self::checkUps($client, $row) : self::checkGls($client, $row);
            } catch (PluginPrintgestionCarrierexception $e) {
                self::noteFailure($carrier, $e->getMessage(), $e->getKind(), $now);
                $last_ok[$carrier] = false;
                PluginPrintgestionLogger::warning('suivi_public', sprintf('Expédition #%d : %s', $id, $e->getMessage()));
                if ($e->getKind() === PluginPrintgestionCarrierexception::KIND_QUOTA) {
                    $stopped[$carrier] = 'blocked';
                } elseif ($e->isTechnical() && ++$failures[$carrier] >= self::BREAKER_THRESHOLD) {
                    $stopped[$carrier] = 'breaker';
                }
                continue;
            }
            if ($verdict === null) {
                $stats['invalid']++; // numéro qui n'a pas la forme du transporteur : rien n'est appelé
                continue;
            }
            $failures[$carrier] = 0;
            $last_ok[$carrier]  = true;
            $stats['checked']++;
            switch ($verdict['status']) {
                case PluginPrintgestionPublicclient::STATUS_DELIVERED:
                    $constats[] = [
                        'id'     => $id,
                        'source' => $carrier === 'ups' ? PluginPrintgestionDelivery::SOURCE_UPS : PluginPrintgestionDelivery::SOURCE_GLS,
                        'date'   => $verdict['date'],
                        'detail' => $verdict['detail'],
                    ];
                    break;
                case PluginPrintgestionPublicclient::STATUS_RETURNED:
                    // Un retour ne se constate pas en livraison, mais il demande quelqu'un : le seul verdict journalisé.
                    $stats['returned']++;
                    PluginPrintgestionLogger::warning('suivi_public', sprintf('Expédition #%d : retour à l\'expéditeur annoncé (%s)', $id, $verdict['detail']));
                    break;
                case PluginPrintgestionPublicclient::STATUS_RELAY:
                case PluginPrintgestionPublicclient::STATUS_EXCEPTION:
                case PluginPrintgestionPublicclient::STATUS_NOT_FOUND:
                    $stats[$verdict['status']]++;
                    break;
            }
        }
        foreach ($last_ok as $carrier => $ok) {
            if ($ok) {
                self::noteSuccess($carrier);
            }
        }
        $stats['requests']  = $client->getRequestCount();
        $stats['delivered'] = PluginPrintgestionDelivery::markDeliveredBatch($constats);
        $stats['stopped']   = implode(', ', array_map(static fn(string $c, string $r): string => $c . ':' . $r, array_keys($stopped), $stopped));

        if ($task !== null) {
            $task->addVolume($stats['checked']);
            $task->log(sprintf(
                __('Suivi public UPS et GLS : %1$d expédition(s) lue(s), %2$d livraison(s) constatée(s), %3$d en point relais, %4$d retour(s), %5$d anomalie(s), %6$d numéro(s) inconnu(s), %7$d numéro(s) mal formé(s), %8$d appel(s)%9$s.', 'printgestion'),
                $stats['checked'], $stats['delivered'], $stats['relay'], $stats['returned'], $stats['exception'],
                $stats['not_found'], $stats['invalid'], $stats['requests'],
                empty($stopped) ? '' : ' — ' . implode(' ; ', array_map(static fn(string $c, string $r): string => strtoupper($c) . ' : ' . self::describeStop($r), array_keys($stopped), $stopped))
            ));
        }
        return $stats;
    }

    public static function describeStop(string $reason): string {
        return match ($reason) {
            'disabled' => __('vérification par les pages de suivi publiques coupée dans la configuration', 'printgestion'),
            'too_soon' => __('déjà passée il y a moins d\'une heure', 'printgestion'),
            'blocked'  => __('accès refusé par le transporteur, laissé de côté quelques heures', 'printgestion'),
            'breaker'  => __('disjoncteur : cinq échecs techniques consécutifs, arrêt pour ce passage', 'printgestion'),
            default    => $reason,
        };
    }

    /** Le dernier passage est-il assez ancien ? */
    public static function isDue(string $now): bool {
        $last = (string) Config::getConfigurationValue(self::CONFIG_CONTEXT, 'public_last_poll');
        if ($last === '') {
            return true;
        }
        $stamp = strtotime($last);
        return $stamp === false || $stamp <= strtotime($now) - self::MIN_INTERVAL;
    }

    /**
     * Expéditions à vérifier : UPS ou GLS, parties et pas encore livrées, numéro saisi, parties depuis 30 jours au plus
     * (ou sans date de départ). Lecture seule, publique pour que le harnais la fasse tourner pour de vrai.
     *
     * @return array[] lignes (id, transport_carrier, transport_number, tracking_key)
     */
    public static function candidates(string $now): array {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'transport_carrier', 'transport_number', 'tracking_key'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'statut'            => self::LIVE_STATUSES,
                'transport_carrier' => self::CARRIERS,
                'transport_number'  => ['<>', ''], // exclut aussi NULL
                'OR'                => [
                    ['date_shipped' => ['>=', date('Y-m-d H:i:s', strtotime($now) - self::LOOKUP_DAYS * DAY_TIMESTAMP)]],
                    ['date_shipped' => null],
                ],
            ],
            'ORDER'  => ['date_shipped ASC', 'id ASC'],
            'LIMIT'  => self::MAX_PER_PASS,
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** UPS : un appel si le numéro saisi est un numéro UPS valide, rien sinon (null). */
    private static function checkUps(PluginPrintgestionPublicclient $client, array $row): ?array {
        $number = PluginPrintgestionPublicclient::normalizeUpsNumber((string) $row['transport_number']);
        return PluginPrintgestionPublicclient::isUpsNumber($number) ? $client->trackUps($number) : null;
    }

    /** GLS : les clés de glsKeys() l'une après l'autre, jusqu'à la première que GLS connaît ; null s'il n'y en a aucune. */
    private static function checkGls(PluginPrintgestionPublicclient $client, array $row): ?array {
        $verdict = null;
        foreach (self::glsKeys((string) $row['transport_number'], (string) ($row['tracking_key'] ?? '')) as $key) {
            $verdict = $client->trackGls($key);
            if ($verdict['status'] !== PluginPrintgestionPublicclient::STATUS_NOT_FOUND) {
                break;
            }
        }
        return $verdict;
    }

    /**
     * Clés GLS essayées, dans l'ordre : celle que l'API GLS a déjà validée (tracking_key), la saisie nettoyée, puis
     * son Track ID à 8 caractères quand la saisie est le code d'étiquette à 10 (même repli que Glsnumber, pour la
     * même raison : ne jamais tronquer d'emblée un numéro déjà correct). Doublons et formes impossibles écartés.
     *
     * @return string[]
     */
    public static function glsKeys(string $transport_number, string $tracking_key): array {
        $clean    = PluginPrintgestionGlsnumber::clean($transport_number);
        $keys     = [strtoupper(trim($tracking_key)), $clean];
        $fallback = PluginPrintgestionGlsnumber::fallback($clean);
        if ($fallback !== null) {
            $keys[] = $fallback['key'];
        }
        return array_values(array_unique(array_filter($keys, static fn(string $key): bool => preg_match('/^[A-Z0-9_]{5,20}$/', $key) === 1)));
    }

    // ── Mémo de santé, par transporteur ───────────────────────────────────────

    /** @return array ['last_success' => string, 'failures' => int, 'last_error' => string, 'blocked_until' => string] */
    public static function getMemo(string $carrier): array {
        $keys = self::memoKeys($carrier);
        $memo = Config::getConfigurationValues(self::CONFIG_CONTEXT, array_values($keys));
        return [
            'last_success'  => (string) ($memo[$keys['last_success']] ?? ''),
            'failures'      => (int) ($memo[$keys['failures']] ?? 0),
            'last_error'    => (string) ($memo[$keys['last_error']] ?? ''),
            'blocked_until' => (string) ($memo[$keys['blocked_until']] ?? ''),
        ];
    }

    /** Ce transporteur a-t-il refusé l'accès il y a moins de six heures ? */
    public static function isBlocked(string $carrier, ?string $now = null): bool {
        $until = self::getMemo($carrier)['blocked_until'];
        return $until !== '' && strtotime($until) > strtotime($now ?? date('Y-m-d H:i:s'));
    }

    public static function noteSuccess(string $carrier): void {
        $keys = self::memoKeys($carrier);
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            $keys['last_success']  => date('Y-m-d H:i:s'),
            $keys['failures']      => 0,
            $keys['last_error']    => '',
            $keys['blocked_until'] => '',
        ]);
    }

    /** Un échec de plus, avec sa cause courte ; un refus d'accès (« quota ») met le transporteur de côté six heures. */
    public static function noteFailure(string $carrier, string $message, string $kind, ?string $now = null): void {
        $keys   = self::memoKeys($carrier);
        $now  ??= date('Y-m-d H:i:s');
        $values = [
            $keys['failures']   => self::getMemo($carrier)['failures'] + 1,
            $keys['last_error'] => mb_substr($now . ' — ' . $message, 0, 255),
        ];
        if ($kind === PluginPrintgestionCarrierexception::KIND_QUOTA) {
            $values[$keys['blocked_until']] = date('Y-m-d H:i:s', strtotime($now) + self::BLOCK_DURATION);
        }
        Config::setConfigurationValues(self::CONFIG_CONTEXT, $values);
    }

    /** Efface le mémo des deux transporteurs et la date du dernier passage (harnais). L'interrupteur reste. */
    public static function resetMemo(): void {
        $values = ['public_last_poll' => ''];
        foreach (self::CARRIERS as $carrier) {
            $values += array_fill_keys(array_values(self::memoKeys($carrier)), '');
        }
        Config::setConfigurationValues(self::CONFIG_CONTEXT, $values);
    }

    private static function memoKeys(string $carrier): array {
        $carrier = in_array($carrier, self::CARRIERS, true) ? $carrier : 'gls';
        return [
            'last_success'  => "public_{$carrier}_last_success",
            'failures'      => "public_{$carrier}_failures",
            'last_error'    => "public_{$carrier}_last_error",
            'blocked_until' => "public_{$carrier}_blocked_until",
        ];
    }
}
