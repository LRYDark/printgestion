<?php
/**
 * PluginPrintgestionMbetracking — retrouver une expédition chez MBE, puis lire ce que MBE dit de sa livraison.
 *
 * MBE n'est pas un transporteur : c'est l'intermédiaire par lequel les cartouches partent, confiées à GLS ou à UPS.
 * Son intérêt ici est double : il porte le numéro de BL de la commande, et il sait si le colis est remis même quand
 * le transporteur n'a pas d'intégration (UPS).
 *
 * ── Appariement : une fois par passage, pas une fois par expédition ───────────────────────────────────────────────
 *
 * L'API MBE n'offre **aucun filtre** sur le numéro de BL ni sur le numéro transporteur : seul `MBEMasterTrackings`
 * filtre, et on ne l'a pas encore. Chercher une expédition revient donc à balayer une fenêtre de dates page par page
 * et à comparer ici. Fait par expédition, cela coûterait jusqu'à trente appels chacune. Fait une fois par passage,
 * cela coûte trente appels pour TOUTES les expéditions à apparier : la fenêtre est lue une fois, indexée par numéro
 * de BL et par numéro transporteur, et confrontée à tout ce qui attend. La référence trouvée est écrite dans
 * `mbe_master_tracking` et ne sera plus jamais cherchée.
 *
 * L'appariement essaie **le numéro de BL d'abord** (le lien métier : c'est la même commande), puis le numéro
 * transporteur (le filet, quand personne n'a saisi le BL dans les notes MBE).
 *
 * ── Décision : MBE ne suffit jamais seul ──────────────────────────────────────────────────────────────────────────
 *
 * Le résumé `TrackingStatus` de MBE reste `WAITING_DELIVERY` plusieurs jours après une remise déjà publiée par le
 * transporteur. Croire MBE seul ferait passer pour « en transit » des colis livrés depuis une semaine. Chaque statut
 * MBE est donc recoupé avec le suivi GLS **déjà rangé sur l'expédition** par le passage GLS de la même tâche : aucun
 * appel de plus, et l'événement du transporteur l'emporte. `DELIVEREDPS` (remis en point relais) ne compte pas comme
 * une livraison : quelqu'un doit encore aller chercher le colis.
 *
 * Rien n'est écrit ici que `mbe_master_tracking`. Le statut de l'expédition appartient à Delivery, qui garde la
 * hiérarchie des preuves et interdit les rétrogradations.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionMbetracking {

    const TABLE = 'glpi_plugin_printgestion_expeditions';

    /**
     * Expéditions interrogées : parties, pas encore livrées. Une expédition déjà livrée, posée ou annulée n'a plus
     * rien à apprendre de MBE — et « livrée » ne se rétrograde pas.
     */
    const LIVE_STATUSES = ['shipped', 'transit'];

    /** Fenêtre de dates balayée pour l'appariement, et nombre de pages au plus par passage. */
    const LOOKUP_DAYS = 30;
    const MAX_PAGES   = 30;
    /** Expéditions rafraîchies au plus par passage : chacune coûte un appel. */
    const MAX_REFRESH = 150;
    /**
     * Délai minimal entre deux passages MBE : deux par jour suffisent, et le quota de 500 appels ne supporterait pas
     * un passage par heure. Le garde-fou est ici, pas dans la fréquence de la tâche : la tâche appartient à GLPI et
     * quelqu'un peut la remettre à l'heure sans savoir ce que cela coûte chez MBE. Onze heures, pas douze : deux
     * passages quotidiens ne doivent pas se manquer d'une minute.
     */
    const MIN_INTERVAL = 11 * HOUR_TIMESTAMP;

    /** Le seul statut GLS qui vaut livraison : remis au destinataire. DELIVEREDPS est un point relais, pas une remise. */
    const GLS_DELIVERED = 'DELIVERED';

    /**
     * Un passage : appariement de ce qui ne l'est pas, puis lecture des statuts, puis constats de livraison.
     *
     * @return array ['matched' => int, 'checked' => int, 'delivered' => int, 'requests' => int,
     *                'stopped' => '' | 'no_keys' | 'budget' | 'breaker' | 'auth']
     */
    public static function poll(?CronTask $task = null, ?PluginPrintgestionMbeclient $client = null, ?string $now = null): array {
        $client ??= new PluginPrintgestionMbeclient();
        $now    ??= date('Y-m-d H:i:s');
        $stats    = ['matched' => 0, 'checked' => 0, 'delivered' => 0, 'requests' => 0, 'stopped' => ''];

        if (!$client->isConfigured()) {
            $stats['stopped'] = 'no_keys';
            return $stats;
        }
        if (PluginPrintgestionMbeclient::quotaRemaining() <= 0) {
            $stats['stopped'] = 'budget';
            return $stats;
        }
        if (!self::isDue($now)) {
            $stats['stopped'] = 'too_soon';
            return $stats;
        }
        self::notePoll($now);

        $stats = self::matchPending($client, $now, $stats);
        if ($stats['stopped'] === '') {
            $stats = self::refreshKnown($client, $stats);
        }

        if ($task !== null) {
            $task->addVolume($stats['delivered']);
            $task->log(sprintf(
                __('Suivi MBE : %1$d expédition(s) appariée(s), %2$d statut(s) lu(s), %3$d livraison(s) constatée(s), %4$d appel(s)%5$s.', 'printgestion'),
                $stats['matched'],
                $stats['checked'],
                $stats['delivered'],
                $stats['requests'],
                $stats['stopped'] !== '' ? ' — ' . self::describeStop($stats['stopped']) : ''
            ));
        }
        return $stats;
    }

    /** Le dernier passage est-il assez ancien ? Aucun appel MBE avant. */
    public static function isDue(string $now): bool {
        $last = (string) Config::getConfigurationValue(PluginPrintgestionMbeclient::MEMO_CONTEXT, 'mbe_last_poll');
        if ($last === '') {
            return true;
        }
        $stamp = strtotime($last);
        return $stamp === false || $stamp <= strtotime($now) - self::MIN_INTERVAL;
    }

    /** Date du passage en cours : posée avant les appels, pour qu'un passage en échec ne reparte pas dans l'heure. */
    private static function notePoll(string $now): void {
        Config::setConfigurationValues(PluginPrintgestionMbeclient::MEMO_CONTEXT, ['mbe_last_poll' => $now]);
    }

    public static function describeStop(string $reason): string {
        return match ($reason) {
            'no_keys'  => __('identifiants MBE non saisis', 'printgestion'),
            'too_soon' => __('déjà passé il y a moins de onze heures : deux passages par jour suffisent', 'printgestion'),
            'budget'   => __('80 % du quota quotidien MBE consommés : la suite attendra demain', 'printgestion'),
            'auth'     => __('identifiants MBE refusés : réessayer ne change rien', 'printgestion'),
            'breaker'  => __('disjoncteur : cinq échecs techniques consécutifs, arrêt du cycle', 'printgestion'),
            default    => $reason,
        };
    }

    // ── Appariement ───────────────────────────────────────────────────────────

    /**
     * Balaie la fenêtre une seule fois et écrit la référence MBE de chaque expédition reconnue. Sans expédition à
     * apparier, aucun appel n'est fait.
     */
    private static function matchPending(PluginPrintgestionMbeclient $client, string $now, array $stats): array {
        global $DB;

        $pending = self::pendingExpeditions($now);
        if (empty($pending)) {
            return $stats;
        }
        $from = date('Y-m-d', strtotime($now) - self::LOOKUP_DAYS * DAY_TIMESTAMP);
        // Deux jours d'avance : MBE date l'expédition, pas le scan, et les fuseaux ne sont pas garantis.
        $to   = date('Y-m-d', strtotime($now) + 2 * DAY_TIMESTAMP);

        $by_bl      = [];
        $by_courier = [];
        $pages      = 1;
        for ($page = 1; $page <= min($pages, self::MAX_PAGES); $page++) {
            try {
                $listing = $client->listShipments($from, $to, $page);
            } catch (PluginPrintgestionCarrierexception $e) {
                $stats['stopped'] = self::stopReason($e);
                PluginPrintgestionMbeclient::noteFailure($e->getMessage(), $e->getKind());
                PluginPrintgestionLogger::warning('mbe', sprintf('Appariement MBE interrompu page %d : %s', $page, $e->getMessage()));
                break;
            }
            $stats['requests']++;
            $pages = max(1, $listing['total_pages']);
            // Fenêtre plus large que ce qu'on accepte de lire : les expéditions des pages suivantes ne seront pas
            // appariées ce passage. Dit une fois, à la première page, plutôt que découvert des semaines plus tard.
            if ($page === 1 && $pages > self::MAX_PAGES) {
                PluginPrintgestionLogger::warning('mbe', sprintf(
                    'Appariement MBE : %1$d pages sur la fenêtre, %2$d lues au plus. Les expéditions au-delà attendront un prochain passage.',
                    $pages,
                    self::MAX_PAGES
                ));
            }
            foreach ($listing['shipments'] as $shipment) {
                $ref = trim((string) ($shipment['mbe_master_tracking'] ?? '')) ?: trim((string) ($shipment['mbe_tracking'] ?? ''));
                if ($ref === '') {
                    continue;
                }
                if ($shipment['bl_number'] !== '') {
                    $by_bl[$shipment['bl_number']] ??= $ref;
                }
                foreach ($shipment['courier_trackings'] as $courier) {
                    $by_courier[$courier] ??= $ref;
                }
            }
        }
        if (empty($by_bl) && empty($by_courier)) {
            return $stats;
        }

        foreach ($pending as $expedition) {
            $ref = '';
            foreach ($expedition['bl_numbers'] as $bl) {
                if (isset($by_bl[$bl])) {
                    $ref = $by_bl[$bl];
                    break;
                }
            }
            $matched_on = 'BL';
            if ($ref === '' && $expedition['courier'] !== '' && isset($by_courier[$expedition['courier']])) {
                $ref        = $by_courier[$expedition['courier']];
                $matched_on = __('numéro transporteur', 'printgestion');
            }
            if ($ref === '') {
                continue; // pas encore chez MBE, ou BL non saisi dans ses notes : le prochain passage réessaiera
            }
            if ($DB->update(self::TABLE, ['mbe_master_tracking' => mb_substr($ref, 0, 32)], ['id' => $expedition['id']])) {
                $stats['matched']++;
                PluginPrintgestionLogger::warning('mbe', sprintf(
                    'Expédition #%d rapprochée de l\'expédition MBE %s (par %s).',
                    $expedition['id'],
                    $ref,
                    $matched_on
                ));
            }
        }
        return $stats;
    }

    /**
     * Expéditions parties, non livrées, sans référence MBE, dont l'envoi est assez récent pour être dans la fenêtre.
     * Chacune vient avec ses numéros de BL (le principal et ceux de la table de liaison) et son numéro transporteur.
     *
     * Lecture seule, publique pour que le harnais puisse faire tourner ces deux requêtes : elles se sont déjà
     * trompées en silence (un IN sur NULL, une clause ON mal formée) sans qu'aucun test ne les exécute.
     *
     * @return array[] [['id' => int, 'bl_numbers' => string[], 'courier' => string]]
     */
    public static function pendingExpeditions(string $now): array {
        global $DB;

        $floor = date('Y-m-d H:i:s', strtotime($now) - self::LOOKUP_DAYS * DAY_TIMESTAMP);
        $rows  = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'transport_number', 'date_shipped'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'statut'       => self::LIVE_STATUSES,
                'date_shipped' => ['>=', $floor],
                // Pas de « IN ('', NULL) » : en SQL, IN ne rapproche jamais une ligne NULL, et c'est la valeur de
                // toutes les expéditions qui n'ont pas encore été rapprochées. Les deux cas se disent séparément.
                'OR'           => [
                    ['mbe_master_tracking' => null],
                    ['mbe_master_tracking' => ''],
                ],
            ],
            'ORDER'  => ['date_shipped DESC', 'id DESC'],
        ]) as $row) {
            $rows[(int) $row['id']] = [
                'id'         => (int) $row['id'],
                'bl_numbers' => [],
                'courier'    => PluginPrintgestionMbeclient::normalizeTracking((string) ($row['transport_number'] ?? '')),
            ];
        }
        if (empty($rows) || !PluginPrintgestionTracking::isGestionUsable()) {
            return array_values($rows);
        }

        // Numéros de BL de ces expéditions : le principal (colonne héritée) et ceux de la table de liaison.
        $ids = array_keys($rows);
        foreach ([
            ['glpi_plugin_printgestion_expeditions AS e', 'e.id'],
            ['glpi_plugin_printgestion_expedition_bls AS e', 'e.expeditions_id'],
        ] as [$table, $key]) {
            foreach ($DB->request([
                'SELECT'     => [$key . ' AS expeditions_id', 's.bl_number'],
                'FROM'       => $table,
                // Les deux tables portent le BL dans la même colonne : la jointure s'écrit pareil pour les deux.
                'INNER JOIN' => ['glpi_plugin_gestion_surveys AS s' => ['ON' => ['e' => 'bl_surveys_id', 's' => 'id']]],
                'WHERE'      => [$key => $ids, 's.bl_number' => ['<>', '']],
            ]) as $row) {
                $bl = self::normalizeBl((string) $row['bl_number']);
                $id = (int) $row['expeditions_id'];
                if ($bl !== '' && isset($rows[$id]) && !in_array($bl, $rows[$id]['bl_numbers'], true)) {
                    $rows[$id]['bl_numbers'][] = $bl;
                }
            }
        }
        return array_values($rows);
    }

    /**
     * Numéro de BL comparable au format rendu par Mbeclient::parseBlNumber() : majuscules, sans séparateur, préfixé
     * BL s'il n'est que chiffres. Les zéros de tête sont conservés — BL007 et BL7 ne sont pas le même document.
     */
    public static function normalizeBl(string $raw): string {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', trim($raw)));
        if ($clean === '') {
            return '';
        }
        return ctype_digit($clean) ? 'BL' . $clean : $clean;
    }

    // ── Lecture et décision ───────────────────────────────────────────────────

    /** Lit le statut des expéditions dont la référence MBE est connue, recoupe avec GLS, et constate les livraisons. */
    private static function refreshKnown(PluginPrintgestionMbeclient $client, array $stats): array {
        global $DB;

        $constats = [];
        $failures = 0;
        $reste    = (int) ($DB->request([
            'COUNT' => 'total',
            'FROM'  => self::TABLE,
            'WHERE' => ['statut' => self::LIVE_STATUSES, 'mbe_master_tracking' => ['<>', '']],
        ])->current()['total'] ?? 0);
        if ($reste > self::MAX_REFRESH) {
            PluginPrintgestionLogger::warning('mbe', sprintf(
                'Suivi MBE : %1$d expéditions à relire, %2$d au plus par passage. Les plus anciennes attendront le passage suivant.',
                $reste,
                self::MAX_REFRESH
            ));
        }
        foreach ($DB->request([
            'SELECT' => ['id', 'mbe_master_tracking', 'transport_carrier', 'tracking_status'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'statut'              => self::LIVE_STATUSES,
                'mbe_master_tracking' => ['<>', ''],
            ],
            'ORDER'  => ['date_shipped DESC', 'id DESC'],
            'LIMIT'  => self::MAX_REFRESH,
        ]) as $row) {
            if (PluginPrintgestionMbeclient::quotaRemaining() <= 0) {
                $stats['stopped'] = 'budget';
                break;
            }
            try {
                $mbe = $client->trackByMbeRef((string) $row['mbe_master_tracking']);
            } catch (PluginPrintgestionCarrierexception $e) {
                PluginPrintgestionMbeclient::noteFailure($e->getMessage(), $e->getKind());
                // Identifiants refusés ou budget épuisé : le passage s'arrête là. Ne pas passer par isTechnical(),
                // qui range un refus d'identifiants parmi les erreurs techniques — on réessaierait cinq fois un mot
                // de passe faux, alors que réessayer ne change rien. stopReason() sait déjà ce qui est sans espoir.
                $stop = self::stopReason($e);
                if ($stop !== '') {
                    $stats['stopped'] = $stop;
                    break;
                }
                $failures++;
                PluginPrintgestionLogger::warning('mbe', sprintf('Expédition #%d : statut MBE illisible (%s).', $row['id'], $e->getMessage()));
                if ($failures >= PluginPrintgestionMbeclient::BREAKER_THRESHOLD) {
                    $stats['stopped'] = 'breaker';
                    break;
                }
                continue;
            }
            $stats['requests']++;
            $stats['checked']++;
            $failures = 0;

            $verdict = self::reconcile($mbe, (string) ($row['transport_carrier'] ?? ''), (string) ($row['tracking_status'] ?? ''));
            if ($verdict['delivered']) {
                $constats[] = [
                    'id'     => (int) $row['id'],
                    'source' => $verdict['source'],
                    'date'   => $verdict['date'],
                    'detail' => $verdict['detail'],
                ];
            }
        }
        $stats['delivered'] = PluginPrintgestionDelivery::markDeliveredBatch($constats);
        return $stats;
    }

    /**
     * Le double contrôle : MBE dit ce qu'il sait, le transporteur ce qu'il a fait, et c'est le terrain qui tranche.
     *
     * MBE peut rester `WAITING_DELIVERY` des jours après une remise : son « en transit » ne vaut donc pas preuve du
     * contraire. Le statut GLS lu ici est celui **déjà rangé sur l'expédition** par le passage GLS de la même tâche,
     * qui tourne avant : pas un appel de plus. Seul `DELIVERED` compte — `DELIVEREDPS` laisse le colis en point
     * relais, personne ne l'a encore.
     *
     * @param array  $mbe         retour de Mbeclient::trackByMbeRef()
     * @param string $carrier     transporteur saisi sur l'expédition ('gls', 'ups', …)
     * @param string $gls_status  code de statut GLS mémorisé sur l'expédition, vide si aucun
     * @return array ['delivered' => bool, 'source' => string, 'date' => ?string, 'detail' => string]
     */
    public static function reconcile(array $mbe, string $carrier, string $gls_status): array {
        $mbe_status = (string) ($mbe['status'] ?? PluginPrintgestionMbeclient::DELIVERY_UNKNOWN);
        $mbe_raw    = trim((string) ($mbe['raw'] ?? ''));
        $gls_status = strtoupper(trim($gls_status));
        $gls_says   = strtolower(trim($carrier)) === 'gls' && $gls_status === self::GLS_DELIVERED;

        if ($gls_says) {
            // Le transporteur a publié la remise : elle prime, que MBE soit à jour ou non.
            return [
                'delivered' => true,
                'source'    => PluginPrintgestionDelivery::SOURCE_GLS,
                'date'      => null, // la date exacte de l'événement vit dans les colonnes de suivi GLS
                'detail'    => sprintf(
                    __('GLS : %1$s ; MBE : %2$s.', 'printgestion'),
                    self::GLS_DELIVERED,
                    $mbe_raw !== '' ? $mbe_raw : __('sans statut', 'printgestion')
                ),
            ];
        }
        if ($mbe_status === PluginPrintgestionMbeclient::DELIVERY_DELIVERED) {
            $signed = trim((string) ($mbe['delivered_to'] ?? ''));
            return [
                'delivered' => true,
                'source'    => PluginPrintgestionDelivery::SOURCE_MBE,
                'date'      => $mbe['delivered_at'] ?? null,
                'detail'    => sprintf(
                    __('MBE : %1$s%2$s.', 'printgestion'),
                    $mbe_raw !== '' ? $mbe_raw : 'DELIVERED',
                    $signed !== '' ? sprintf(__(', signé %s', 'printgestion'), $signed) : ''
                ),
            ];
        }
        // Partiellement livré, en transit, anomalie, inconnu : rien n'est constaté. Le retard, lui, sera signalé par
        // l'alerte de livraison en retard — jamais par un statut inventé.
        return ['delivered' => false, 'source' => '', 'date' => null, 'detail' => ''];
    }

    /** Raison d'arrêt tirée de la forme de l'erreur : identifiants refusés et budget épuisé arrêtent le passage. */
    private static function stopReason(PluginPrintgestionCarrierexception $e): string {
        return match ($e->getKind()) {
            PluginPrintgestionCarrierexception::KIND_AUTH   => 'auth',
            PluginPrintgestionCarrierexception::KIND_BUDGET => 'budget',
            PluginPrintgestionCarrierexception::KIND_QUOTA  => 'budget',
            default                                        => '',
        };
    }
}
