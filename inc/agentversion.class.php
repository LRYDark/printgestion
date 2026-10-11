<?php
/**
 * PluginPrintgestionAgentversion — une sonde passée à une autre version de GLPI Agent relève-t-elle encore ?
 *
 * Le contrôle de compatibilité (PluginPrintgestionAgentcompat) dit ce qui a changé dans l'agent ; seul le terrain dit
 * si cela marche. Ici, une ligne par sonde : sa version, et quand elle change, la version d'avant, la date du
 * changement et la date de son dernier relevé réussi à ce moment-là. Ensuite, à chaque calcul de la vue des sondes
 * (toutes les heures, Agentview::rebuild()) :
 *   - un relevé réussi plus récent que celui d'avant le changement : la nouvelle version est confirmée, et validée
 *     pour le contrôle de compatibilité ;
 *   - aucun relevé réussi au-delà du délai (deux passages attendus, un jour au moins) : avertissement au journal,
 *     ligne rouge dans la carte « Santé de la configuration » et dans la page « Installeur ». La cause n'est pas
 *     certaine (PC éteint, imprimante changée…) ; la coïncidence avec la mise à jour, elle, est dite.
 * Une sonde jamais relevée avant sa mise à jour n'a rien à comparer : elle n'est ni confirmée ni signalée.
 *
 * Les alertes natives des sondes (Agentalert : sonde sans contact, imprimantes qui ne remontent plus, notifiées par
 * mail) continuent de jouer à côté ; ce suivi ajoute la cause probable, plus tôt.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionAgentversion {

    const TABLE = 'glpi_plugin_printgestion_agentversions';

    /** Changements montrés : en attente ou en défaut, et confirmés depuis moins de SHOWN_DAYS jours. */
    const SHOWN_DAYS = 60;

    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_WAITING   = 'waiting';
    const STATUS_SILENT    = 'silent';
    const STATUS_UNPROVEN  = 'unproven';

    private static bool $table_checked = false;

    public static function ensureTable(): void {
        if (self::$table_checked) {
            return;
        }
        PluginPrintgestionSchema::createIfMissing(self::TABLE);
        self::$table_checked = true;
    }

    /** Délai laissé à une sonde mise à jour pour réussir un relevé : deux passages attendus, un jour au moins (heures). */
    public static function getDelayHours(int $entities_id): int {
        $frequency = (int) PluginPrintgestionCollectfrequency::getForEntity($entities_id)['hours'];
        return max(24, 2 * max($frequency, PluginPrintgestionCollectfrequency::getContactHours()));
    }

    private static function agentName(int $agents_id): string {
        $name = (string) Dropdown::getDropdownName(Agent::getTable(), $agents_id);
        return $name !== '' && $name !== '&nbsp;' ? $name : sprintf(__('Agent n° %d', 'printgestion'), $agents_id);
    }

    /**
     * Suit les sondes : appelé à chaque calcul de la vue des sondes, avec ce qu'il vient d'en calculer.
     *
     * @param array $agents agents_id => ['version' (« 1.20 »), 'is_probe' (bool), 'entities_id', 'last_inventory' (?string)]
     * @return array ['tracked', 'changed', 'confirmed', 'warned']
     */
    public static function track(array $agents): array {
        global $DB;

        self::ensureTable();
        $stats = ['tracked' => 0, 'changed' => 0, 'confirmed' => 0, 'warned' => 0];
        $known = [];
        foreach ($DB->request(['FROM' => self::TABLE]) as $row) {
            $known[(int) $row['agents_id']] = $row;
        }
        $now = date('Y-m-d H:i:s');

        foreach ($agents as $agents_id => $agent) {
            $agents_id = (int) $agents_id;
            $version   = (string) ($agent['version'] ?? '');
            if ($agents_id <= 0 || empty($agent['is_probe']) || !PluginPrintgestionAgentsetting::isValidVersion($version)) {
                continue;
            }
            $stats['tracked']++;
            $row  = $known[$agents_id] ?? null;
            $last = isset($agent['last_inventory']) && $agent['last_inventory'] !== null ? (string) $agent['last_inventory'] : null;

            // Première fois vue : point de départ, rien à juger.
            if ($row === null) {
                $DB->insert(self::TABLE, ['agents_id' => $agents_id, 'version' => $version, 'date_mod' => $now]);
                continue;
            }

            // Version changée : on retient d'où elle vient, quand, et le dernier relevé réussi à cet instant.
            if ((string) $row['version'] !== $version) {
                $DB->update(self::TABLE, [
                    'version'          => $version,
                    'previous_version' => (string) $row['version'],
                    'date_change'      => $now,
                    'inventory_before' => $last,
                    'date_confirmed'   => null,
                    'date_warned'      => null,
                    'date_mod'         => $now,
                ], ['id' => (int) $row['id']]);
                $stats['changed']++;
                PluginPrintgestionLogger::info('agentversion', sprintf(
                    'Sonde %1$s (n° %2$d) : GLPI Agent %3$s → %4$s. Relevé réussi attendu sous %5$d h.',
                    self::agentName($agents_id),
                    $agents_id,
                    (string) $row['version'],
                    $version,
                    self::getDelayHours((int) ($agent['entities_id'] ?? 0))
                ));
                continue;
            }

            if ($row['date_change'] === null || $row['date_confirmed'] !== null) {
                continue;
            }

            // Un relevé réussi après le changement : la version est prouvée sur le terrain.
            if ($last !== null && ($row['inventory_before'] === null || $last > (string) $row['inventory_before'])) {
                $DB->update(self::TABLE, ['date_confirmed' => $now, 'date_mod' => $now], ['id' => (int) $row['id']]);
                $stats['confirmed']++;
                $name = self::agentName($agents_id);
                PluginPrintgestionLogger::info('agentversion', sprintf('Sonde %1$s (n° %2$d) : relevé réussi en GLPI Agent %3$s (%4$s).', $name, $agents_id, $version, $last));
                PluginPrintgestionAgentcompat::markValidated($version, sprintf('relevé réussi de la sonde %1$s (n° %2$d) le %3$s', $name, $agents_id, $last));
                continue;
            }

            // Délai dépassé sans relevé, alors que la sonde relevait avant : signalé une fois par changement.
            if ($row['date_warned'] === null && $row['inventory_before'] !== null
                && strtotime((string) $row['date_change']) + self::getDelayHours((int) ($agent['entities_id'] ?? 0)) * HOUR_TIMESTAMP <= time()) {
                $DB->update(self::TABLE, ['date_warned' => $now, 'date_mod' => $now], ['id' => (int) $row['id']]);
                $stats['warned']++;
                PluginPrintgestionLogger::warning('agentversion', sprintf(
                    'Sonde %1$s (n° %2$d) passée de GLPI Agent %3$s à %4$s le %5$s : aucun relevé réussi depuis (dernier : %6$s). La nouvelle version est peut-être en cause.',
                    self::agentName($agents_id),
                    $agents_id,
                    (string) $row['previous_version'],
                    $version,
                    (string) $row['date_change'],
                    (string) $row['inventory_before']
                ));
            }
        }
        return $stats;
    }

    /** État d'un changement de version. */
    public static function status(array $row): string {
        if (!empty($row['date_confirmed'])) {
            return self::STATUS_CONFIRMED;
        }
        if (!empty($row['date_warned'])) {
            return self::STATUS_SILENT;
        }
        return empty($row['inventory_before']) ? self::STATUS_UNPROVEN : self::STATUS_WAITING;
    }

    /**
     * Changements de version des sondes, avec la sonde, son entité et son dernier relevé.
     *
     * @param string $filter 'shown' (page « Installeur ») ou 'silent' (sans relevé depuis, carte de santé)
     */
    public static function getChanges(string $filter = 'shown'): array {
        global $DB;

        self::ensureTable();
        PluginPrintgestionAgentview::ensureTable();
        $where = [['NOT' => ['v.date_change' => null]]];
        if ($filter === 'silent') {
            $where[] = ['NOT' => ['v.date_warned' => null]];
            $where[] = ['v.date_confirmed' => null];
        } else {
            $where[] = ['OR' => [
                ['v.date_confirmed' => null],
                ['v.date_confirmed' => ['>', date('Y-m-d H:i:s', time() - self::SHOWN_DAYS * DAY_TIMESTAMP)]],
            ]];
        }
        $rows = [];
        foreach ($DB->request([
            'SELECT'    => ['v.*', 'a.name AS agent_name', 'a.entities_id', 'a.last_contact', 'e.completename AS entity', 'av.last_network_inventory'],
            'FROM'      => self::TABLE . ' AS v',
            'LEFT JOIN' => [
                Agent::getTable() . ' AS a'                         => ['ON' => ['v' => 'agents_id', 'a' => 'id']],
                'glpi_entities AS e'                                => ['ON' => ['a' => 'entities_id', 'e' => 'id']],
                PluginPrintgestionAgentview::getTable() . ' AS av' => ['ON' => ['v' => 'agents_id', 'av' => 'agents_id']],
            ],
            'WHERE'     => $where,
            'ORDER'     => ['v.date_change DESC'],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Une phrase par changement : carte de santé et journal de lecture. */
    public static function describe(array $row): string {
        $name    = (string) ($row['agent_name'] ?? '') !== '' ? (string) $row['agent_name'] : sprintf(__('Agent n° %d', 'printgestion'), (int) $row['agents_id']);
        $head    = sprintf(
            __('Sonde %1$s (%2$s) : GLPI Agent %3$s → %4$s le %5$s', 'printgestion'),
            $name,
            (string) ($row['entity'] ?? ''),
            (string) $row['previous_version'],
            (string) $row['version'],
            Html::convDateTime((string) $row['date_change'])
        );
        $last    = empty($row['last_network_inventory']) ? __('jamais', 'printgestion') : Html::convDateTime((string) $row['last_network_inventory']);
        $contact = empty($row['last_contact']) ? __('jamais', 'printgestion') : Html::convDateTime((string) $row['last_contact']);
        return $head . ' — ' . match (self::status($row)) {
            self::STATUS_CONFIRMED => sprintf(__('relevé réussi depuis (le %s).', 'printgestion'), $last),
            self::STATUS_SILENT    => sprintf(__('aucun relevé réussi depuis (dernier : %1$s ; dernier contact : %2$s). La nouvelle version est peut-être en cause.', 'printgestion'), $last, $contact),
            self::STATUS_UNPROVEN  => __('jamais relevée avant sa mise à jour : rien à comparer.', 'printgestion'),
            default                => sprintf(
                __('relevé réussi attendu avant le %s.', 'printgestion'),
                Html::convDateTime(date('Y-m-d H:i:s', strtotime((string) $row['date_change']) + self::getDelayHours((int) ($row['entities_id'] ?? 0)) * HOUR_TIMESTAMP))
            ),
        };
    }

    /** Section « Sur le terrain » de la carte de compatibilité : tableau natif des changements de version des sondes. */
    public static function renderField(): string {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $rows = self::getChanges();
        if ($rows === []) {
            return "<p class='text-muted small mb-0'>" . $esc(__('Aucune sonde n\'a changé de version de GLPI Agent depuis le début du suivi. Chaque sonde est suivie dès le calcul de la vue des sondes (toutes les heures) : quand sa version change, le plugin attend un relevé réussi et le signale s\'il ne vient pas.', 'printgestion')) . "</p>";
        }
        $badges  = [
            self::STATUS_CONFIRMED => ['bg-green text-green-fg', __('Relevé réussi', 'printgestion')],
            self::STATUS_WAITING   => ['bg-blue text-blue-fg', __('En attente d\'un relevé', 'printgestion')],
            self::STATUS_SILENT    => ['bg-red text-red-fg', __('Aucun relevé depuis', 'printgestion')],
            self::STATUS_UNPROVEN  => ['bg-secondary text-secondary-fg', __('Rien à comparer', 'printgestion')],
        ];
        $entries = [];
        foreach ($rows as $row) {
            [$class, $label] = $badges[self::status($row)];
            $name      = (string) ($row['agent_name'] ?? '') !== '' ? (string) $row['agent_name'] : sprintf(__('Agent n° %d', 'printgestion'), (int) $row['agents_id']);
            $entries[] = [
                'probe'   => "<a href='" . $esc(Agent::getFormURLWithID((int) $row['agents_id'])) . "'>" . $esc($name) . "</a>",
                'entity'  => (string) ($row['entity'] ?? ''),
                'version' => sprintf('%s → %s', (string) $row['previous_version'], (string) $row['version']),
                'date'    => Html::convDateTime((string) $row['date_change']),
                'state'   => "<span class='badge {$class}'>" . $esc($label) . "</span> <span class='small text-muted'>" . $esc(self::describe($row)) . "</span>",
            ];
        }
        return PluginPrintgestionUi::datatable([
            'probe'   => __('Sonde', 'printgestion'),
            'entity'  => Entity::getTypeName(1),
            'version' => __('Version de GLPI Agent', 'printgestion'),
            'date'    => __('Changée le', 'printgestion'),
            'state'   => __('Relevé après la mise à jour', 'printgestion'),
        ], $entries, ['probe' => 'raw_html', 'state' => 'raw_html']);
    }
}
