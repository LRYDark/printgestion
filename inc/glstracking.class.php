<?php
/**
 * PluginPrintgestionGlstracking — suivi des colis GLS, rangé sur l'expédition qui porte déjà le transporteur et le
 * numéro saisis (colonnes tracking_*, aucun objet parallèle) et rafraîchi par la tâche automatique, jamais au
 * chargement d'une page.
 *
 * Ce que le suivi ne fait pas : il ne change jamais le statut d'une expédition, ne ferme jamais le cycle
 * anti-doublon, ne décide de rien. Il s'affiche.
 *
 * Cadence, entièrement déduite (aucun réglage) :
 *   - une ligne est interrogée au plus une fois par heure, les plus anciennement interrogées d'abord ;
 *   - par paquets de dix, en s'arrêtant avant 80 % du quota quotidien (ce qui reste attend l'heure suivante) ;
 *   - un statut final (DELIVERED, CANCELED, FINAL) n'est plus jamais interrogé ;
 *   - un numéro que GLS ne reconnaît pas (E_404_01, après le repli de normalisation) est réessayé au plus tôt
 *     24 h plus tard, trois cycles, puis affiché « non reconnu » définitivement ;
 *   - un colis sans mouvement depuis 30 jours passe « sans nouvelles » et n'est plus interrogé ;
 *   - cinq échecs techniques consécutifs (réseau, 5xx, jeton refusé, E_500_01) arrêtent le cycle ; un 429 arrête
 *     la journée.
 *
 * Le code de statut fait foi (énumération fermée de la spécification) ; le libellé français d'événement ne sert
 * qu'à l'affichage. Un code hors énumération est traité comme non final et journalisé.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionGlstracking {

    /** Codes de statut de la spécification (énumération fermée) et libellé affiché. */
    const STATUS_LABELS = [
        'PLANNEDPICKUP' => 'Enlèvement planifié',
        'INPICKUP'      => 'En cours d\'enlèvement',
        'NOTPICKEDUP'   => 'Non enlevé',
        'PREADVICE'     => 'Annoncé à GLS',
        'INTRANSIT'     => 'En transit',
        'INDELIVERY'    => 'En cours de livraison',
        'INWAREHOUSE'   => 'En entrepôt',
        'DELIVEREDPS'   => 'Livré en point relais',
        'DELIVERED'     => 'Livré',
        'NOTDELIVERED'  => 'Non livré',
        'CANCELED'      => 'Annulé',
        'FINAL'         => 'Clos',
    ];
    /** Codes finaux, en dur : on cesse d'interroger. DELIVEREDPS n'en est pas un (quelqu'un doit aller chercher le colis). */
    const FINAL_STATUSES = ['DELIVERED', 'CANCELED', 'FINAL'];
    /** Anomalies : quelqu'un doit agir. Les deux seuls statuts à signaler. */
    const ANOMALY_STATUSES = ['NOTPICKEDUP', 'NOTDELIVERED'];
    /**
     * Avancement d'un colis, du moins avancé au plus avancé : le statut de tête d'un envoi multi-colis est le plus
     * bas (une commande partie en trois colis n'est pas arrivée tant que le troisième traîne). Anomalies d'abord.
     * Un code non répertorié se place au milieu, non final.
     */
    const STATUS_RANK = [
        'NOTPICKEDUP' => 0, 'NOTDELIVERED' => 0, 'PLANNEDPICKUP' => 1, 'INPICKUP' => 2, 'PREADVICE' => 3, 'INWAREHOUSE' => 4,
        'INTRANSIT' => 5, 'INDELIVERY' => 6, 'DELIVEREDPS' => 7, 'DELIVERED' => 8, 'CANCELED' => 9, 'FINAL' => 9,
    ];
    /** Pluriels du décompte multi-colis (« 2 livrés ») ; sinon le libellé en minuscule. */
    const STATUS_PLURALS = [
        'DELIVERED' => 'livrés', 'DELIVEREDPS' => 'livrés en point relais', 'NOTDELIVERED' => 'non livrés', 'NOTPICKEDUP' => 'non enlevés',
        'CANCELED' => 'annulés', 'FINAL' => 'clos', 'PLANNEDPICKUP' => 'enlèvements planifiés',
    ];

    /** État de la ligne de suivi (tracking_state). */
    const STATE_NONE         = '';
    const STATE_TRACKED      = 'tracked';
    const STATE_UNKNOWN      = 'unknown';
    const STATE_UNRECOGNIZED = 'unrecognized';
    const STATE_SILENT       = 'silent';
    const STATE_FINAL        = 'final';

    const POLL_INTERVAL      = HOUR_TIMESTAMP;
    const UNKNOWN_RETRY      = DAY_TIMESTAMP;
    const UNKNOWN_MAX_CYCLES = 3;
    const SILENT_DAYS        = 30;
    /** Expéditions encore vivantes : une expédition posée ou annulée n'est plus interrogée (cela ne juge pas du colis). */
    const LIVE_EXPEDITION_STATUSES = ['pending', 'shipped', 'transit', 'delivered'];

    const TABLE = 'glpi_plugin_printgestion_expeditions';

    // ── Catalogue ─────────────────────────────────────────────────────────────

    public static function isFinal(string $status): bool {
        return in_array($status, self::FINAL_STATUSES, true);
    }

    public static function isKnownStatus(string $status): bool {
        return array_key_exists($status, self::STATUS_LABELS);
    }

    public static function rank(string $status): int {
        return self::STATUS_RANK[$status] ?? 5;
    }

    /** « 2 livrés », « 1 en cours de livraison » : le nombre et le libellé accordé. */
    public static function countLabel(string $status, int $count): string {
        $label = mb_strtolower(self::describeStatus($status)['label']);
        if ($count > 1 && isset(self::STATUS_PLURALS[$status])) {
            $label = __(self::STATUS_PLURALS[$status], 'printgestion');
        }
        return $count . ' ' . $label;
    }

    /**
     * La description de l'événement n'est affichée que si elle apporte quelque chose au libellé du statut :
     * « Colis en cours de livraison » sous « En cours de livraison » ne dit rien de plus ; « Destinataire absent »
     * sous « Non livré », si.
     */
    public static function eventAddsInformation(string $label, string $description): bool {
        $norm = static fn(string $s) => ' ' . PluginPrintgestionSageimport::normalizeLabel($s) . ' ';
        $rest = str_replace(trim($norm($label)), ' ', $norm($description));
        $rest = (string) preg_replace('/\b(colis|le|la|les|l|votre|vos|est|a|ete|en|de|du|des|un|une|au|aux|par|pour)\b/', ' ', $rest);
        return strlen((string) preg_replace('/[^a-z0-9]/', '', $rest)) > 3;
    }

    /**
     * Libellé et niveau d'un code : 'final', 'anomaly', 'relay' (point relais), 'progress', 'unknown'.
     */
    public static function describeStatus(string $status): array {
        if (!self::isKnownStatus($status)) {
            return ['label' => __('Statut GLS non répertorié', 'printgestion'), 'level' => 'unknown'];
        }
        $level = 'progress';
        if (self::isFinal($status)) {
            $level = $status === 'CANCELED' ? 'canceled' : 'final';
        } elseif (in_array($status, self::ANOMALY_STATUSES, true)) {
            $level = 'anomaly';
        } elseif ($status === 'DELIVEREDPS') {
            $level = 'relay';
        }
        return ['label' => __(self::STATUS_LABELS[$status], 'printgestion'), 'level' => $level];
    }

    // ── Tâche ─────────────────────────────────────────────────────────────────

    /**
     * Un passage : lignes à interroger, appels par paquets, résultats rangés. Aucune écriture hors des colonnes
     * tracking_* et du mémo de santé.
     *
     * @return array ['checked' => lignes mises à jour, 'requests' => requêtes GLS, 'stopped' => '' | 'no_keys' | 'quota' | 'budget' | 'breaker']
     */
    public static function poll(?CronTask $task = null, ?PluginPrintgestionCarrierclient $client = null, ?string $now = null): array {
        global $DB;

        $client ??= new PluginPrintgestionGlsclient();
        $now    ??= date('Y-m-d H:i:s');
        $stats    = ['checked' => 0, 'requests' => 0, 'stopped' => ''];
        if (!$client->isConfigured()) {
            $stats['stopped'] = 'no_keys';
            return $stats;
        }
        if (PluginPrintgestionGlsclient::quotaRemaining() <= 0) {
            $stats['stopped'] = PluginPrintgestionGlsclient::getMemo()['quota_blocked'] ? 'quota' : 'budget';
            return $stats;
        }
        $by_key = self::candidatesByKey($now);
        if (empty($by_key)) {
            return $stats;
        }
        $cycle_failures = 0;
        $query = static function (array $keys) use ($client, &$stats, &$cycle_failures): array {
            if (PluginPrintgestionGlsclient::quotaRemaining() <= 0) {
                throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_BUDGET, __('Budget quotidien atteint : la suite attendra le prochain passage.', 'printgestion'));
            }
            try {
                $parcels = $client->track($keys);
            } catch (PluginPrintgestionCarrierexception $e) {
                if ($e->isTechnical()) {
                    $cycle_failures++;
                    PluginPrintgestionGlsclient::noteFailure($e->getMessage());
                    PluginPrintgestionLogger::warning('gls', sprintf('Appel GLS en échec (%s) : %s', $e->getKind(), $e->getMessage()));
                    if ($cycle_failures >= PluginPrintgestionGlsclient::BREAKER_THRESHOLD) {
                        throw new PluginPrintgestionCarrierexception(PluginPrintgestionCarrierexception::KIND_BREAKER, sprintf(__('%d échecs techniques consécutifs : arrêt du cycle.', 'printgestion'), $cycle_failures));
                    }
                }
                throw $e;
            }
            $stats['requests']++;
            $cycle_failures = 0;
            PluginPrintgestionGlsclient::noteSuccess();
            return $parcels;
        };
        foreach (array_chunk(array_keys($by_key), PluginPrintgestionGlsnumber::MAX_PER_REQUEST) as $chunk) {
            try {
                $results = PluginPrintgestionGlsnumber::lookup($chunk, $query);
            } catch (PluginPrintgestionCarrierexception $e) {
                if (in_array($e->getKind(), [PluginPrintgestionCarrierexception::KIND_BREAKER, PluginPrintgestionCarrierexception::KIND_BUDGET, PluginPrintgestionCarrierexception::KIND_QUOTA], true)) {
                    $stats['stopped'] = $e->getKind();
                    break;
                }
                continue; // échec technique isolé : ce paquet attendra l'heure suivante
            }
            foreach ($results as $key => $result) {
                if ($result['error'] === 'E_500_01') {
                    // Panne côté GLS sur ce colis : échec technique, ligne laissée telle quelle.
                    $cycle_failures++;
                    PluginPrintgestionGlsclient::noteFailure(sprintf('E_500_01 sur la clé %s', $key));
                    if ($cycle_failures >= PluginPrintgestionGlsclient::BREAKER_THRESHOLD) {
                        $stats['stopped'] = PluginPrintgestionCarrierexception::KIND_BREAKER;
                        break 2;
                    }
                    continue;
                }
                foreach ($by_key[$key] as $expedition) {
                    self::applyResult($expedition, $result, $now);
                    $stats['checked']++;
                }
            }
        }
        if ($task !== null) {
            $task->addVolume($stats['checked']);
            $task->log(sprintf(__('Suivi GLS : %1$d expédition(s) mise(s) à jour, %2$d requête(s)%3$s.', 'printgestion'), $stats['checked'], $stats['requests'],
                $stats['stopped'] !== '' ? ' — ' . self::describeStop($stats['stopped']) : ''));
        }
        return $stats;
    }

    public static function describeStop(string $reason): string {
        return match ($reason) {
            'no_keys' => __('clés GLS non saisies', 'printgestion'),
            'quota'   => __('quota GLS dépassé (429) : plus d\'appel aujourd\'hui', 'printgestion'),
            'budget'  => __('80 % du quota quotidien consommés : la suite attendra demain', 'printgestion'),
            'breaker' => __('disjoncteur : cinq échecs techniques consécutifs, arrêt du cycle', 'printgestion'),
            default   => $reason,
        };
    }

    /**
     * Lignes à interroger maintenant, regroupées par clé d'interrogation (clé mémorisée, sinon saisie nettoyée).
     * Marque au passage « sans nouvelles » les colis sans mouvement depuis 30 jours.
     */
    private static function candidatesByKey(string $now): array {
        global $DB;

        $by_key = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'transport_number', 'tracking_key', 'tracking_state', 'tracking_event_date', 'tracking_checked_at', 'tracking_failures'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'transport_carrier' => 'gls',
                'transport_number'  => ['<>', ''], // exclut aussi NULL (NULL <> '' n'est pas vrai)
                'statut'            => self::LIVE_EXPEDITION_STATUSES,
                'tracking_state'    => [self::STATE_NONE, self::STATE_TRACKED, self::STATE_UNKNOWN],
                'OR'                => [
                    ['tracking_checked_at' => null],
                    ['tracking_checked_at' => ['<=', date('Y-m-d H:i:s', strtotime($now) - self::POLL_INTERVAL)]],
                ],
            ],
            'ORDER'  => ['tracking_checked_at ASC', 'id ASC'],
        ]) as $row) {
            $state = (string) $row['tracking_state'];
            if ($state === self::STATE_UNKNOWN && $row['tracking_checked_at'] !== null
                && strtotime((string) $row['tracking_checked_at']) > strtotime($now) - self::UNKNOWN_RETRY) {
                continue; // numéro non reconnu : pas avant 24 h
            }
            if ($state === self::STATE_TRACKED && $row['tracking_event_date'] !== null
                && strtotime((string) $row['tracking_event_date']) < strtotime($now) - self::SILENT_DAYS * DAY_TIMESTAMP) {
                $DB->update(self::TABLE, ['tracking_state' => self::STATE_SILENT, 'tracking_checked_at' => $now], ['id' => (int) $row['id']]);
                continue;
            }
            $key = trim((string) $row['tracking_key']);
            if ($key === '') {
                $key = PluginPrintgestionGlsnumber::clean((string) $row['transport_number']);
            }
            if ($key === '') {
                continue;
            }
            $by_key[$key][] = $row;
        }
        return $by_key;
    }

    /** Range le résultat d'une clé sur une expédition. */
    private static function applyResult(array $expedition, array $result, string $now): void {
        global $DB;

        $id     = (int) $expedition['id'];
        $update = ['tracking_checked_at' => $now];
        if ($result['error'] === null && !empty($result['parcels'])) {
            $parcels = self::summarizeParcels($result['parcels']);
            $parcel  = self::headParcel($result['parcels']);
            $status  = strtoupper(trim((string) ($parcel['status'] ?? '')));
            $event   = self::lastEvent($parcel);
            if (!self::isKnownStatus($status)) {
                PluginPrintgestionLogger::warning('gls', sprintf('Expédition #%d : code de statut GLS non répertorié « %s », traité comme non final.', $id, $status));
            }
            if (!empty($result['fell_back']) && $result['suffix'] !== null && (string) $expedition['tracking_key'] !== (string) $result['key']) {
                PluginPrintgestionLogger::warning('gls', sprintf('Expédition #%d : numéro raccourci au Track ID « %s » (suffixe « %s » conservé à part).', $id, $result['key'], $result['suffix']));
            }
            $update += [
                'tracking_key'         => mb_substr((string) $result['key'], 0, 32),
                'tracking_suffix'      => $result['suffix'] !== null ? mb_substr((string) $result['suffix'], 0, 8) : null,
                'tracking_status'      => mb_substr($status, 0, 32),
                'tracking_label'       => mb_substr($event['description'], 0, 255),
                'tracking_event_date'  => $event['date'],
                'tracking_event_place' => mb_substr($event['place'], 0, 255),
                'tracking_failures'    => 0,
                'tracking_state'       => self::isFinal($status) ? self::STATE_FINAL : self::STATE_TRACKED,
                'tracking_parcels'     => json_encode($parcels, JSON_UNESCAPED_UNICODE),
            ];
        } elseif ($result['error'] === PluginPrintgestionGlsnumber::ERROR_NOT_FOUND) {
            $failures = (int) $expedition['tracking_failures'] + 1;
            $update  += [
                'tracking_key'      => mb_substr((string) $result['key'], 0, 32),
                'tracking_failures' => $failures,
                'tracking_state'    => $failures >= self::UNKNOWN_MAX_CYCLES ? self::STATE_UNRECOGNIZED : self::STATE_UNKNOWN,
            ];
            if ($failures >= self::UNKNOWN_MAX_CYCLES) {
                PluginPrintgestionLogger::warning('gls', sprintf('Expédition #%d : numéro « %s » non reconnu par GLS après %d cycles, plus interrogé.', $id, $result['key'], $failures));
            }
        } else {
            // Réponse sans entrée pour cette clé, codes mêlés, E_400_… : anomalie, ligne inchangée, réessai dans une heure.
            PluginPrintgestionLogger::warning('gls', sprintf('Expédition #%d : réponse GLS inexploitable pour la clé « %s » (%s).', $id, $result['key'], (string) $result['error']));
        }
        $DB->update(self::TABLE, $update, ['id' => $id]);
    }

    /**
     * Colis de tête d'un envoi multi-colis : le moins avancé (STATUS_RANK), et à rang égal le plus récent. Une
     * commande partie en trois colis n'est pas arrivée tant que le troisième traîne ; une anomalie passe devant tout.
     */
    private static function headParcel(array $parcels): array {
        usort($parcels, static function (array $a, array $b): int {
            $ra = self::rank(strtoupper((string) ($a['status'] ?? '')));
            $rb = self::rank(strtoupper((string) ($b['status'] ?? '')));
            return $ra <=> $rb ?: strcmp((string) ($b['statusDateTime'] ?? ''), (string) ($a['statusDateTime'] ?? ''));
        });
        return $parcels[0];
    }

    /** Les colis d'une clé, un par entrée (numéro GLS, code, date et lieu du dernier événement), du moins avancé au plus avancé. */
    private static function summarizeParcels(array $parcels): array {
        $out = [];
        foreach ($parcels as $parcel) {
            $event = self::lastEvent($parcel);
            $out[] = [
                'unitno' => (string) ($parcel['unitno'] ?? ''),
                'status' => strtoupper(trim((string) ($parcel['status'] ?? ''))),
                'date'   => $event['date'],
                'place'  => $event['place'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => self::rank($a['status']) <=> self::rank($b['status']) ?: strcmp((string) $b['date'], (string) $a['date']));
        return $out;
    }

    /** Dernier événement (date, lieu, description) : événements triés sur eventDateTime, jamais sur leur ordre. */
    private static function lastEvent(array $parcel): array {
        $events = array_values(array_filter((array) ($parcel['events'] ?? []), 'is_array'));
        usort($events, static fn(array $a, array $b) => strcmp((string) ($b['eventDateTime'] ?? ''), (string) ($a['eventDateTime'] ?? '')));
        $last = $events[0] ?? [];
        $date = (string) ($last['eventDateTime'] ?? $parcel['statusDateTime'] ?? '');
        return [
            'date'        => self::toLocalDateTime($date),
            'place'       => trim(implode(' ', array_filter([(string) ($last['postalCode'] ?? ''), (string) ($last['city'] ?? ''), (string) ($last['country'] ?? '')]))),
            'description' => trim((string) ($last['description'] ?? '')),
        ];
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    /**
     * La ligne de suivi d'une expédition : une pastille, le libellé, la date du dernier événement ; derrière un
     * chevron, le lieu, le numéro interrogé (s'il diffère de la saisie) et la dernière interrogation. Le numéro
     * affiché ailleurs reste la saisie brute. Jamais le code brut, ni le point d'entrée, ni le nombre d'échecs.
     *
     * Chaîne vide : sans clés saisies (l'écran est exactement celui d'avant le suivi), transporteur autre que
     * GLS, ou rien de collecté encore. Même rendu pour tous les profils : rien ici n'est un diagnostic.
     *
     * @param array $exp Ligne de glpi_plugin_printgestion_expeditions (transport_* et tracking_*).
     */
    public static function renderLine(array $exp, string $id): string {
        if (!PluginPrintgestionGlsclient::hasKeys() || (string) ($exp['transport_carrier'] ?? '') !== 'gls') {
            return '';
        }
        $esc   = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $state = (string) ($exp['tracking_state'] ?? '');
        if (!in_array($state, [self::STATE_TRACKED, self::STATE_FINAL, self::STATE_SILENT, self::STATE_UNRECOGNIZED], true)) {
            return '';
        }
        $details = [];
        $raw     = PluginPrintgestionGlsnumber::clean((string) ($exp['transport_number'] ?? ''));
        $key     = (string) ($exp['tracking_key'] ?? '');
        if ($key !== '' && $key !== $raw) {
            $details[] = sprintf(__('Numéro interrogé : %1$s%2$s', 'printgestion'), $key,
                (string) ($exp['tracking_suffix'] ?? '') !== '' ? sprintf(__(' (suffixe %s conservé)', 'printgestion'), $exp['tracking_suffix']) : '');
        }
        if (!empty($exp['tracking_checked_at'])) {
            $details[] = sprintf(__('Dernière interrogation : %s', 'printgestion'), Html::convDateTime((string) $exp['tracking_checked_at']));
        }
        if ($state === self::STATE_UNRECOGNIZED) {
            $badge = 'bg-secondary';
            $text  = $esc(__('Numéro non reconnu par GLS', 'printgestion'));
        } else {
            $status   = (string) ($exp['tracking_status'] ?? '');
            $describe = self::describeStatus($status);
            $badge    = match ($describe['level']) {
                'final'    => 'bg-success',
                'relay'    => 'bg-azure',
                'anomaly'  => 'bg-danger',
                'canceled' => 'bg-secondary',
                'unknown'  => 'bg-secondary',
                default    => 'bg-primary',
            };
            $label = $describe['label'];
            if ($state === self::STATE_SILENT) {
                $badge = 'bg-secondary';
                $label = sprintf(__('Sans nouvelles depuis 30 jours (%s)', 'printgestion'), $label);
            }
            $parcels = json_decode((string) ($exp['tracking_parcels'] ?? ''), true);
            $parcels = is_array($parcels) ? array_values(array_filter($parcels, 'is_array')) : [];
            if (count($parcels) > 1 && $state !== self::STATE_SILENT) {
                // Plusieurs colis : le décompte, jamais un seul colis qui cacherait les autres ; la tête = le moins avancé.
                $counts = [];
                foreach ($parcels as $p) {
                    $counts[(string) $p['status']] = ($counts[(string) $p['status']] ?? 0) + 1;
                }
                uksort($counts, static fn(string $a, string $b): int => self::rank($a) <=> self::rank($b));
                $parts = [];
                foreach ($counts as $code => $n) {
                    $parts[] = self::countLabel($code, $n);
                }
                $text = '<strong' . ($describe['level'] === 'anomaly' ? " class='text-danger'" : '') . '>'
                    . $esc(sprintf(__('%d colis', 'printgestion'), count($parcels))) . '</strong> — ' . $esc(implode(', ', $parts));
                foreach ($parcels as $p) {
                    $details[] = trim(sprintf('%s — %s%s%s', $p['unitno'] !== '' ? $p['unitno'] : __('colis', 'printgestion'),
                        self::describeStatus((string) $p['status'])['label'],
                        !empty($p['date']) ? ' — ' . Html::convDateTime((string) $p['date']) : '',
                        !empty($p['place']) ? ' — ' . $p['place'] : ''));
                }
            } else {
                $text = '<strong' . ($describe['level'] === 'anomaly' ? " class='text-danger'" : '') . '>' . $esc($label) . '</strong>';
                $event = trim((string) ($exp['tracking_label'] ?? ''));
                // La description n'est affichée que si elle ajoute quelque chose au statut.
                if ($event !== '' && self::eventAddsInformation($label, $event)) {
                    $text .= ' — ' . $esc($event);
                }
            }
            if (!empty($exp['tracking_event_date'])) {
                $text .= ' — ' . $esc(Html::convDateTime((string) $exp['tracking_event_date']));
            }
            if ($describe['level'] === 'anomaly') {
                $text .= " <span class='text-danger'>" . $esc(__('(à signaler aux Achats)', 'printgestion')) . '</span>';
            }
            if ((string) ($exp['tracking_event_place'] ?? '') !== '' && count($parcels) <= 1) {
                array_unshift($details, sprintf(__('Lieu : %s', 'printgestion'), $exp['tracking_event_place']));
            }
        }
        $target = $esc('pg-gls-' . $id);
        $html   = "<div class='pg-gls small' data-pg-gls='" . $esc($state) . "'><span class='badge {$badge} me-1' style='width:.7em;height:.7em;padding:0;border-radius:50%;display:inline-block'></span>" . $text;
        if (!empty($details)) {
            $html .= " <a class='small text-muted' data-bs-toggle='collapse' href='#{$target}' role='button' aria-expanded='false' aria-controls='{$target}'>"
                . "<i class='ti ti-chevron-down'></i></a><div class='collapse text-muted' id='{$target}'>"
                . implode('<br>', array_map($esc, $details)) . '</div>';
        }
        return $html . '</div>';
    }

    /** La ligne de suivi d'une expédition par son identifiant (une lecture). */
    public static function renderLineFor(int $expeditions_id): string {
        if (!PluginPrintgestionGlsclient::hasKeys()) {
            return '';
        }
        $expedition = new PluginPrintgestionExpedition();
        if (!$expedition->getFromDB($expeditions_id)) {
            return '';
        }
        return self::renderLine($expedition->fields, (string) $expeditions_id);
    }

    /** ISO 8601 avec décalage (« 2024-10-07T10:46:14+0200 ») → heure du serveur ; null si illisible. */
    public static function toLocalDateTime(string $iso): ?string {
        if (trim($iso) === '') {
            return null;
        }
        try {
            return (new DateTime($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}
