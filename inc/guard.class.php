<?php
/**
 * PluginPrintgestionGuard — verrous anti-double-envoi.
 *
 * Pour une machine (l'imprimante, et toute imprimante de la même entité portant le même n° de série)
 * et un emplacement toner (propriété SNMP), trois verrous empêchent de proposer ou
 * de passer une nouvelle commande :
 *
 *  1. ENVOI EN COURS — un envoi n'est ni posé ni annulé (commandé, expédié,
 *     en transit, livré non posé), sans borne de temps. Jamais
 *     contournable : la cartouche existe déjà. Si elle a été détectée posée sur une
 *     autre machine (alerte « mauvaise imprimante » non résolue), le message renvoie
 *     vers la réattribution, qui clôt l'envoi et libère cette machine.
 *     Une ligne de demande d'envoi proposée ou validée, pas encore exportée, bloque de
 *     la même façon (motif « demande ») : une demande validée mais pas encore partie
 *     bloque autant qu'une expédition.
 *  2. GARDE — une pose a été détectée ou confirmée sur cette machine et cet
 *     emplacement il y a moins de guard_days jours. La garde part de la POSE, où
 *     qu'elle ait eu lieu : une machine qui reçoit la cartouche destinée à une autre
 *     est protégée dès la détection.
 *  3. TICKET — un ticket non résolu lié à la machine a été ouvert il y a moins de
 *     guard_ticket_days jours (0 = verrou désactivé).
 *
 * Contournement « consommation anormale » : verrous 2 et 3 uniquement, quand le
 * niveau mesuré de l'emplacement est inférieur ou égal à guard_bypass_level %.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionGuard {

    const REASON_IN_PROGRESS = 'in_progress';
    const REASON_GUARD       = 'guard';
    const REASON_TICKET      = 'ticket';
    const REASON_DEMANDE     = 'demande';

    /**
     * Évalue les verrous d'une liste d'emplacements, en requêtes groupées.
     *
     * @param array $slots   [['printers_id' => int, 'property' => string, 'level' => ?int], ...]
     *                       level : niveau mesuré (%) ou null s'il n'est pas mesurable.
     * @param array $options ['exclude_demandes_id' => int] : lignes de cette demande ignorées
     *                       (contrôle d'une demande par rapport à tout le reste).
     * @return array Clé "printers_id|property" => verrou, ou null si l'emplacement est libre.
     *               Verrou : ['reason', 'blocking', 'bypassed', 'message', 'until',
     *                         'expeditions_id', 'tickets_id', 'demandes_id'].
     */
    public static function evaluate(array $slots, array $options = []): array {
        global $DB;

        $out         = [];
        $printer_ids = [];
        foreach ($slots as $slot) {
            $pid  = (int) ($slot['printers_id'] ?? 0);
            $prop = (string) ($slot['property'] ?? '');
            if ($pid <= 0 || $prop === '') {
                continue;
            }
            $out[$pid . '|' . $prop] = null;
            $printer_ids[$pid]       = $pid;
        }
        if (empty($printer_ids)) {
            return $out;
        }

        $config       = PluginPrintgestionConfig::getInstance();
        $guard_days   = max(0, (int) ($config->fields['guard_days'] ?? 5));
        $bypass_level = max(0, min(100, (int) ($config->fields['guard_bypass_level'] ?? 10)));
        $ticket_days  = max(0, (int) ($config->fields['guard_ticket_days'] ?? 10));

        // Machine = l'imprimante + les imprimantes de la même entité portant le même n° de série.
        $machines = self::resolveMachines(array_values($printer_ids));
        $all_ids  = array_values(array_unique(array_merge(...array_values($machines))));

        // ── Verrou 1 : envois en cours (le plus récent par imprimante et emplacement) ──
        $in_progress = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'printers_id', 'toner_property', 'statut'],
            'FROM'   => 'glpi_plugin_printgestion_expeditions',
            'WHERE'  => [
                'printers_id' => $all_ids,
                'statut'      => PluginPrintgestionExpedition::ACTIVE_STATUSES,
            ],
            'ORDER'  => ['id DESC'],
        ]) as $exp) {
            $k = $exp['printers_id'] . '|' . $exp['toner_property'];
            if (!isset($in_progress[$k])) {
                $in_progress[$k] = $exp;
            }
        }

        // Envois dont la cartouche a été détectée posée sur une autre machine.
        $elsewhere = [];
        $exp_ids   = array_values(array_map(static fn($e) => (int) $e['id'], $in_progress));
        if (!empty($exp_ids)) {
            foreach ($DB->request([
                'SELECT'    => ['a.expeditions_id', 'p.name AS detected_name'],
                'FROM'      => 'glpi_plugin_printgestion_alerts AS a',
                'LEFT JOIN' => [
                    'glpi_printers AS p' => ['ON' => ['a' => 'detected_printers_id', 'p' => 'id']],
                ],
                'WHERE'     => [
                    'a.alert_type'     => 'wrong_printer',
                    'a.is_resolved'    => 0,
                    'a.expeditions_id' => $exp_ids,
                ],
                'ORDER'     => ['a.date_alert DESC'],
            ]) as $alert) {
                $eid = (int) $alert['expeditions_id'];
                if (!isset($elsewhere[$eid])) {
                    $elsewhere[$eid] = (string) ($alert['detected_name'] ?? '?');
                }
            }
        }

        // Lignes de demande d'envoi ouvertes : proposées ou validées, pas encore exportées
        // (la plus récente par imprimante et emplacement).
        $open_lines    = [];
        $line_criteria = [
            'SELECT' => ['plugin_printgestion_demandes_id', 'printers_id', 'toner_property', 'statut'],
            'FROM'   => PluginPrintgestionDemandeline::getTable(),
            'WHERE'  => [
                'printers_id' => $all_ids,
                'statut'      => PluginPrintgestionDemande::OPEN_STATUSES,
            ],
            'ORDER'  => ['id DESC'],
        ];
        $exclude_demandes_id = (int) ($options['exclude_demandes_id'] ?? 0);
        if ($exclude_demandes_id > 0) {
            $line_criteria['WHERE'][] = ['NOT' => ['plugin_printgestion_demandes_id' => $exclude_demandes_id]];
        }
        foreach ($DB->request($line_criteria) as $line) {
            $k = $line['printers_id'] . '|' . $line['toner_property'];
            if (!isset($open_lines[$k])) {
                $open_lines[$k] = $line;
            }
        }

        // ── Verrou 2 : dernière pose détectée ou confirmée dans la fenêtre de garde ──
        $last_install = [];
        if ($guard_days > 0) {
            $cutoff = date('Y-m-d H:i:s', time() - $guard_days * DAY_TIMESTAMP);

            // Poses détectées (hausse de niveau) — pas les lignes d'amorçage.
            foreach ($DB->request([
                'SELECT'  => ['printers_id', 'toner_property', new \QueryExpression('MAX(`date_install`) AS `last_date`')],
                'FROM'    => 'glpi_plugin_printgestion_cartridge_history',
                'WHERE'   => [
                    'printers_id'  => $all_ids,
                    'is_detected'  => 1,
                    'date_install' => ['>=', $cutoff],
                ],
                'GROUPBY' => ['printers_id', 'toner_property'],
            ]) as $row) {
                self::keepLatest($last_install, $row['printers_id'] . '|' . $row['toner_property'], (string) $row['last_date']);
            }

            // Poses confirmées manuellement ou constatées à la réattribution.
            foreach ($DB->request([
                'SELECT'  => ['printers_id', 'toner_property', new \QueryExpression('MAX(`date_installed`) AS `last_date`')],
                'FROM'    => 'glpi_plugin_printgestion_expeditions',
                'WHERE'   => [
                    'printers_id'    => $all_ids,
                    'statut'         => PluginPrintgestionExpedition::STATUS_INSTALLED,
                    'date_installed' => ['>=', $cutoff],
                ],
                'GROUPBY' => ['printers_id', 'toner_property'],
            ]) as $row) {
                self::keepLatest($last_install, $row['printers_id'] . '|' . $row['toner_property'], (string) $row['last_date']);
            }
        }

        // ── Verrou 3 : ticket non résolu récent lié à la machine ──
        $tickets = [];
        if ($ticket_days > 0) {
            foreach ($DB->request([
                'SELECT'     => ['it.items_id', 't.id', 't.date'],
                'FROM'       => 'glpi_items_tickets AS it',
                'INNER JOIN' => [
                    'glpi_tickets AS t' => ['ON' => ['it' => 'tickets_id', 't' => 'id']],
                ],
                'WHERE'      => [
                    'it.itemtype'  => Printer::class,
                    'it.items_id'  => $all_ids,
                    't.is_deleted' => 0,
                    't.status'     => Ticket::getNotSolvedStatusArray(),
                    't.date'       => ['>=', date('Y-m-d H:i:s', time() - $ticket_days * DAY_TIMESTAMP)],
                ],
                'ORDER'      => ['t.date DESC'],
            ]) as $ticket) {
                $pid = (int) $ticket['items_id'];
                if (!isset($tickets[$pid])) {
                    $tickets[$pid] = $ticket;
                }
            }
        }

        $now = date('Y-m-d H:i:s');
        foreach ($slots as $slot) {
            $pid  = (int) ($slot['printers_id'] ?? 0);
            $prop = (string) ($slot['property'] ?? '');
            if ($pid <= 0 || $prop === '') {
                continue;
            }
            $key      = $pid . '|' . $prop;
            $ids      = $machines[$pid] ?? [$pid];
            $level    = (isset($slot['level']) && $slot['level'] !== null) ? (int) $slot['level'] : null;
            $bypassed = $level !== null && $level <= $bypass_level;

            // 1. Envoi en cours : jamais contournable.
            $exp = null;
            foreach ($ids as $id) {
                if (isset($in_progress[$id . '|' . $prop])) {
                    $exp = $in_progress[$id . '|' . $prop];
                    break;
                }
            }
            if ($exp !== null) {
                $eid = (int) $exp['id'];
                $message = isset($elsewhere[$eid])
                    ? sprintf(
                        __('La cartouche de l\'envoi #%1$d a été détectée posée sur %2$s : réattribuez l\'expédition (écran Expéditions, alertes prioritaires) pour libérer cette machine.', 'printgestion'),
                        $eid,
                        $elsewhere[$eid]
                    )
                    : sprintf(
                        __('Envoi #%1$d en cours (%2$s) : pas de nouvelle commande avant la pose.', 'printgestion'),
                        $eid,
                        self::statusLabel((string) $exp['statut'])
                    );
                $out[$key] = self::lock(self::REASON_IN_PROGRESS, false, $message, null, $eid, null);
                continue;
            }

            // 1 bis. Ligne de demande d'envoi ouverte : jamais contournable non plus.
            $line = null;
            foreach ($ids as $id) {
                if (isset($open_lines[$id . '|' . $prop])) {
                    $line = $open_lines[$id . '|' . $prop];
                    break;
                }
            }
            if ($line !== null) {
                $did     = (int) $line['plugin_printgestion_demandes_id'];
                $message = sprintf(
                    __('Demande d\'envoi #%1$d %2$s : pas de nouvelle commande tant qu\'elle n\'est ni exportée ni annulée.', 'printgestion'),
                    $did,
                    (string) $line['statut'] === PluginPrintgestionDemande::STATUS_VALIDATED
                        ? __('validée, pas encore exportée', 'printgestion')
                        : __('proposée, en attente de validation', 'printgestion')
                );
                $out[$key] = self::lock(self::REASON_DEMANDE, false, $message, null, null, null, $did);
                continue;
            }

            // 2. Garde après la dernière pose.
            $last = null;
            foreach ($ids as $id) {
                $candidate = $last_install[$id . '|' . $prop] ?? null;
                if ($candidate !== null && ($last === null || $candidate > $last)) {
                    $last = $candidate;
                }
            }
            if ($last !== null) {
                $until = date('Y-m-d H:i:s', strtotime($last) + $guard_days * DAY_TIMESTAMP);
                if ($until > $now) {
                    $message = $bypassed
                        ? sprintf(
                            __('Garde après pose (jusqu\'au %1$s) contournée : niveau %2$d %% ≤ %3$d %% (consommation anormale).', 'printgestion'),
                            Html::convDateTime($until),
                            $level,
                            $bypass_level
                        )
                        : sprintf(
                            __('Pose détectée le %1$s : garde jusqu\'au %2$s (contournable si le niveau descend à %3$d %%).', 'printgestion'),
                            Html::convDateTime($last),
                            Html::convDateTime($until),
                            $bypass_level
                        );
                    $out[$key] = self::lock(self::REASON_GUARD, $bypassed, $message, $until, null, null);
                    continue;
                }
            }

            // 3. Ticket récent sur la machine.
            $ticket = null;
            foreach ($ids as $id) {
                if (isset($tickets[$id]) && ($ticket === null || $tickets[$id]['date'] > $ticket['date'])) {
                    $ticket = $tickets[$id];
                }
            }
            if ($ticket !== null) {
                $until   = date('Y-m-d H:i:s', strtotime((string) $ticket['date']) + $ticket_days * DAY_TIMESTAMP);
                $message = $bypassed
                    ? sprintf(
                        __('Ticket #%1$d ouvert sur la machine, verrou contourné : niveau %2$d %% ≤ %3$d %% (consommation anormale).', 'printgestion'),
                        (int) $ticket['id'],
                        $level,
                        $bypass_level
                    )
                    : sprintf(
                        __('Ticket #%1$d ouvert le %2$s sur la machine : pas de nouvelle commande jusqu\'au %3$s (contournable si le niveau descend à %4$d %%).', 'printgestion'),
                        (int) $ticket['id'],
                        Html::convDateTime((string) $ticket['date']),
                        Html::convDateTime($until),
                        $bypass_level
                    );
                $out[$key] = self::lock(self::REASON_TICKET, $bypassed, $message, $until, null, (int) $ticket['id']);
            }
        }

        return $out;
    }

    /**
     * Comme evaluate(), avec le niveau mesuré lu côté serveur dans l'inventaire GLPI —
     * jamais une valeur transmise par le navigateur. Pour valider une commande.
     */
    public static function evaluateLive(array $slots, array $options = []): array {
        global $DB;

        $printer_ids = array_values(array_unique(array_filter(array_map(
            static fn($s) => (int) ($s['printers_id'] ?? 0),
            $slots
        ))));
        $levels = [];
        if (!empty($printer_ids)) {
            // Niveaux lisibles (sentinelles écartées, règles appliquées), jamais du navigateur.
            foreach (PluginPrintgestionSnmpadapter::getLevels($printer_ids) as $pid => $properties) {
                foreach ($properties as $property => $parsed) {
                    $levels[$pid . '|' . $property] = $parsed['usable'] ? (int) $parsed['value'] : null;
                }
            }
        }

        foreach ($slots as &$slot) {
            $slot['level'] = $levels[(int) ($slot['printers_id'] ?? 0) . '|' . (string) ($slot['property'] ?? '')] ?? null;
        }
        unset($slot);

        return self::evaluate($slots, $options);
    }

    /**
     * Imprimante => [elle-même + imprimantes actives de la même entité portant le même n° de série].
     * Sert aussi à la proposition automatique (lignes de demande annulées récemment).
     *
     * Même n° de série dans deux entités : jamais la même machine pour les verrous (un envoi d'un client
     * ne bloque pas la commande d'un autre, et ses références ne sont pas affichées chez l'autre). Signalé
     * à l'administrateur dans « Contrôle de la remontée » (numéros de série en double).
     */
    public static function resolveMachines(array $printer_ids): array {
        global $DB;

        $machines  = [];
        $serial_of = [];
        $entity_of = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'serial', 'entities_id'],
            'FROM'   => 'glpi_printers',
            'WHERE'  => ['id' => $printer_ids],
        ]) as $printer) {
            $pid             = (int) $printer['id'];
            $machines[$pid]  = [$pid];
            $entity_of[$pid] = (int) $printer['entities_id'];
            if (trim((string) $printer['serial']) !== '') {
                $serial_of[$pid] = (string) $printer['serial'];
            }
        }

        if (!empty($serial_of)) {
            $by_serial = [];
            foreach ($DB->request([
                'SELECT' => ['id', 'serial', 'entities_id'],
                'FROM'   => 'glpi_printers',
                'WHERE'  => [
                    'serial'      => array_values(array_unique($serial_of)),
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ],
            ]) as $printer) {
                $by_serial[(string) $printer['serial'] . '|' . (int) $printer['entities_id']][] = (int) $printer['id'];
            }
            foreach ($serial_of as $pid => $serial) {
                $machines[$pid] = array_values(array_unique(array_merge([$pid], $by_serial[$serial . '|' . $entity_of[$pid]] ?? [])));
            }
        }

        foreach ($printer_ids as $pid) {
            $machines[(int) $pid] = $machines[(int) $pid] ?? [(int) $pid];
        }
        return $machines;
    }

    private static function keepLatest(array &$dates, string $key, string $date): void {
        if ($date !== '' && (!isset($dates[$key]) || $date > $dates[$key])) {
            $dates[$key] = $date;
        }
    }

    private static function lock(
        string $reason,
        bool $bypassed,
        string $message,
        ?string $until,
        ?int $expeditions_id,
        ?int $tickets_id,
        ?int $demandes_id = null
    ): array {
        return [
            'reason'         => $reason,
            'blocking'       => !$bypassed,
            'bypassed'       => $bypassed,
            'message'        => $message,
            'until'          => $until,
            'expeditions_id' => $expeditions_id,
            'tickets_id'     => $tickets_id,
            'demandes_id'    => $demandes_id,
        ];
    }

    private static function statusLabel(string $statut): string {
        $labels = [
            'pending'     => __('commandé, en attente d\'expédition', 'printgestion'),
            'shipped'     => __('expédié', 'printgestion'),
            'transit'     => __('en transit', 'printgestion'),
            'delivered'   => __('livré, pose non constatée', 'printgestion'),
        ];
        return $labels[$statut] ?? $statut;
    }
}
