<?php
/**
 * PluginPrintgestionCollectfrequency — fréquence des relevés d'imprimantes par entité (module Collecte SNMP /
 * Déploiement Agent).
 *
 * Pourquoi côté serveur : GLPI Agent installé en service (EXECMODE=1 sous Windows, démon sous Linux et macOS) suit la
 * fréquence d'inventaire globale de GLPI, reçue dans la réponse CONTACT, et le cœur n'offre aucun point d'extension
 * pour la changer agent par agent ; TASK_FREQUENCY et ses modificateurs du MSI ne valent qu'en mode tâche Windows
 * (EXECMODE=2), et aucune clé de conf.d ou de local.cfg ne règle cet intervalle.
 *
 * Fonctionnement : la fréquence d'une entité (héritée de l'entité parente, sinon quotidienne) s'applique aux tâches
 * GLPI Inventory créées ou reprises par l'assistant de raccordement (découverte et inventaire réseau). Après chaque
 * relevé terminé, la date de début de la tâche est repoussée à « fin du relevé + fréquence » : GLPI Inventory ne
 * prépare aucun job avant cette date et annule ceux qu'un agent demanderait plus tôt. Une fois la date passée, le
 * job part au premier contact de la sonde : jamais plus souvent que la fréquence d'inventaire globale de GLPI.
 * La date de début est écrite directement dans la table de GLPI Inventory : il refuse toute modification d'une tâche
 * active, et la désactiver annule ses jobs préparés. Une tâche avec une date de fin (plage réglée à la main dans GLPI
 * Inventory) n'est pas touchée. Sans la tâche automatique, les tâches restent sans date de début : relevé à chaque
 * contact, jamais moins souvent.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionCollectfrequency extends CommonDBTM {

    static $rightname = 'plugin_printgestion_deploiement';

    /** Unités et bornes des modificateurs (mêmes bornes que TASK_HOURLY_MODIFIER et TASK_DAILY_MODIFIER du MSI). */
    const UNITS = ['hourly' => [1, 23], 'daily' => [1, 365]];

    const DEFAULT_FREQUENCY = 'daily';
    const DEFAULT_MODIFIER  = 1;

    /** Fréquence effective par entité, calculée une fois par requête. */
    private static array $cache = [];

    static function getTypeName($nb = 0) {
        return __('Fréquence des relevés', 'printgestion');
    }

    public static function getFormURL($full = true) {
        return PLUGIN_PRINTGESTION_WEBDIR . '/front/collectfrequency.php';
    }

    public static function getLabel(string $frequency, int $modifier): string {
        if ($frequency === 'hourly') {
            return $modifier === 1 ? __('toutes les heures', 'printgestion') : sprintf(__('toutes les %d heures', 'printgestion'), $modifier);
        }
        return $modifier === 1 ? __('tous les jours', 'printgestion') : sprintf(__('tous les %d jours', 'printgestion'), $modifier);
    }

    /** Intervalle en heures auquel les agents contactent GLPI (Administration > Inventaire, réglage global). */
    public static function getContactHours(): int {
        $hours = (int) Config::getConfigurationValue('inventory', 'inventory_frequency');
        return $hours > 0 ? $hours : 24;
    }

    /**
     * Fréquence d'une entité : réglée sur elle, sinon sur l'entité parente la plus proche, sinon quotidienne.
     *
     * @return array ['frequency', 'modifier', 'hours', 'source' => entity|parent|default, 'source_entities_id']
     */
    public static function getForEntity(int $entities_id): array {
        global $DB;

        if (isset(self::$cache[$entities_id])) {
            return self::$cache[$entities_id];
        }
        $chain = array_merge([$entities_id], array_map('intval', array_values(getAncestorsOf(Entity::getTable(), $entities_id))));
        $row   = $DB->request([
            'SELECT'     => ['f.entities_id', 'f.frequency', 'f.modifier'],
            'FROM'       => self::getTable() . ' AS f',
            'INNER JOIN' => ['glpi_entities AS e' => ['ON' => ['f' => 'entities_id', 'e' => 'id']]],
            'WHERE'      => ['f.entities_id' => $chain],
            'ORDER'      => ['e.level DESC'],
            'LIMIT'      => 1,
        ])->current();

        $frequency = is_array($row) && isset(self::UNITS[$row['frequency']]) ? (string) $row['frequency'] : self::DEFAULT_FREQUENCY;
        [$min, $max] = self::UNITS[$frequency];
        $modifier  = is_array($row) ? max($min, min($max, (int) $row['modifier'])) : self::DEFAULT_MODIFIER;
        return self::$cache[$entities_id] = [
            'frequency'          => $frequency,
            'modifier'           => $modifier,
            'hours'              => $frequency === 'hourly' ? $modifier : $modifier * 24,
            'source'             => !is_array($row) ? 'default' : ((int) $row['entities_id'] === $entities_id ? 'entity' : 'parent'),
            'source_entities_id' => is_array($row) ? (int) $row['entities_id'] : 0,
        ];
    }

    private static function getSourceLabel(array $current): string {
        return match ($current['source']) {
            'entity' => __('réglée sur cette entité', 'printgestion'),
            'parent' => sprintf(__('héritée de %s', 'printgestion'), Dropdown::getDropdownName(Entity::getTable(), $current['source_entities_id'])),
            default  => __('valeur par défaut', 'printgestion'),
        };
    }

    /** Seuil « muette » d'une imprimante de l'entité : délai global, porté à la fréquence de l'entité plus un jour. */
    public static function getSilentDaysForEntity(int $entities_id): int {
        return max(PluginPrintgestionCollect::getSilentDays(), (int) ceil(self::getForEntity($entities_id)['hours'] / 24) + 1);
    }

    /** Ligne de la note d'une page des paquets d'installation. */
    public static function getPackageLine(int $entities_id): string {
        $current = self::getForEntity($entities_id);
        return sprintf(
            __('Fréquence des relevés d\'imprimantes de ce client : %s. Elle se règle dans GLPI (onglet « Déploiement Agent » de l\'entité) et se change sans réinstaller l\'agent.', 'printgestion'),
            self::getLabel($current['frequency'], $current['modifier'])
        );
    }

    /** Ligne du journal d'un raccordement. */
    public static function getJournalLine(int $entities_id): string {
        $current = self::getForEntity($entities_id);
        return sprintf(
            __('Fréquence des relevés retenue pour ce site : %1$s (%2$s) ; GLPI contacte les agents toutes les %3$d h.', 'printgestion'),
            self::getLabel($current['frequency'], $current['modifier']),
            self::getSourceLabel($current),
            self::getContactHours()
        );
    }

    /**
     * Enregistre la fréquence d'une entité (frequency : hourly, daily ou inherit ; modifier), la trace dans
     * l'historique de l'entité et le journal des raccordements concernés, puis l'applique aux tâches.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function saveForEntity(Entity $entity, array $input): array {
        global $DB;

        $entities_id = (int) $entity->getID();
        $frequency   = (string) ($input['frequency'] ?? '');
        $modifier    = (int) ($input['modifier'] ?? 0);
        if ($frequency === 'inherit') {
            if ($entities_id === 0) {
                return ['ok' => false, 'message' => __('L\'entité racine n\'a pas d\'entité parente.', 'printgestion')];
            }
        } elseif (!isset(self::UNITS[$frequency])) {
            return ['ok' => false, 'message' => __('Fréquence inconnue : rien n\'est enregistré.', 'printgestion')];
        } elseif ($modifier < self::UNITS[$frequency][0] || $modifier > self::UNITS[$frequency][1]) {
            return ['ok' => false, 'message' => $frequency === 'hourly'
                ? __('Nombre d\'heures entre 1 et 23 : rien n\'est enregistré.', 'printgestion')
                : __('Nombre de jours entre 1 et 365 : rien n\'est enregistré.', 'printgestion')];
        }

        // Raccordements de l'entité et des sous-entités, dont la fréquence effective peut changer.
        $sons   = array_map('intval', array_values(getSonsOf(Entity::getTable(), $entities_id)));
        $raccs  = iterator_to_array($DB->request([
            'SELECT' => ['id', 'entities_id'],
            'FROM'   => PluginPrintgestionRaccordement::getTable(),
            'WHERE'  => ['entities_id' => $sons],
        ]), false);
        $before = [];
        foreach (array_unique(array_merge([$entities_id], array_map('intval', array_column($raccs, 'entities_id')))) as $id) {
            $before[$id] = self::getForEntity($id);
        }

        $row = new self();
        $has = $row->getFromDBByCrit(['entities_id' => $entities_id]);
        if ($frequency === 'inherit') {
            $ok = !$has || $row->delete(['id' => (int) $row->getID()], true);
        } else {
            $values = ['frequency' => $frequency, 'modifier' => $modifier, 'users_id' => (int) Session::getLoginUserID()];
            $ok     = $has
                ? $row->update(['id' => (int) $row->getID()] + $values)
                : $row->add(['entities_id' => $entities_id] + $values) > 0;
        }
        self::$cache = [];
        if (!$ok) {
            return ['ok' => false, 'message' => __('Fréquence des relevés non enregistrée.', 'printgestion')];
        }

        $after    = self::getForEntity($entities_id);
        $old      = self::getLabel($before[$entities_id]['frequency'], $before[$entities_id]['modifier']);
        $new      = self::getLabel($after['frequency'], $after['modifier']);
        $user     = getUserName((int) Session::getLoginUserID());
        Log::history($entities_id, Entity::class, [0, '', sprintf(
            __('Fréquence des relevés d\'imprimantes : %1$s → %2$s (%3$s).', 'printgestion'),
            $old,
            $new,
            self::getSourceLabel($after)
        )], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        foreach ($raccs as $data) {
            $was = $before[(int) $data['entities_id']];
            $now = self::getForEntity((int) $data['entities_id']);
            if ($was['hours'] === $now['hours'] && $was['source'] === $now['source']) {
                continue;
            }
            $racc = new PluginPrintgestionRaccordement();
            if ($racc->getFromDB((int) $data['id'])) {
                $racc->addLog(3, 'info', sprintf(
                    __('Fréquence des relevés changée par %1$s : %2$s → %3$s (%4$s).', 'printgestion'),
                    $user,
                    self::getLabel($was['frequency'], $was['modifier']),
                    self::getLabel($now['frequency'], $now['modifier']),
                    self::getSourceLabel($now)
                ));
            }
        }
        $stats = self::applySchedule($sons);

        $message = sprintf(__('Fréquence des relevés de « %1$s » : %2$s (%3$s).', 'printgestion'), $entity->fields['completename'] ?? $entity->fields['name'], $new, self::getSourceLabel($after));
        if ($after['hours'] < self::getContactHours()) {
            $message .= ' ' . sprintf(__('GLPI ne contacte les agents que toutes les %d h : les relevés ne seront pas plus fréquents.', 'printgestion'), self::getContactHours());
        }
        if ($stats['running'] > 0) {
            $message .= ' ' . __('Une tâche en cours d\'exécution sera replanifiée à la fin de son relevé.', 'printgestion');
        }
        return ['ok' => true, 'message' => $message];
    }

    // ── Planification des tâches GLPI Inventory ───────────────────────────────

    /**
     * Tâches GLPI Inventory créées ou reprises par l'assistant (tous raccordements), avec leurs jobs.
     *
     * @param ?int[] $entities_ids seulement les tâches de ces entités ; null : toutes
     * @return array tasks_id => ['id', 'name', 'entities_id', 'datetime_start', 'datetime_end', 'jobs' => int[]]
     */
    public static function getManagedTasks(?array $entities_ids = null): array {
        global $DB;

        if (!PluginPrintgestionCollectsetup::isAvailable()) {
            return [];
        }
        $ids = [];
        foreach ($DB->request(['SELECT' => ['discovery_tasks', 'inventory_tasks'], 'FROM' => PluginPrintgestionRaccordement::getTable()]) as $row) {
            foreach (['discovery_tasks', 'inventory_tasks'] as $field) {
                foreach (importArrayFromDB((string) $row[$field]) as $tasks_id) {
                    $ids[(int) $tasks_id] = true;
                }
            }
        }
        unset($ids[0]);
        if (empty($ids)) {
            return [];
        }
        $where = ['id' => array_keys($ids)];
        if ($entities_ids !== null) {
            if (empty($entities_ids)) {
                return [];
            }
            $where['entities_id'] = $entities_ids;
        }
        $tasks = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'entities_id', 'datetime_start', 'datetime_end'],
            'FROM'   => PluginGlpiinventoryTask::getTable(),
            'WHERE'  => $where,
            'ORDER'  => ['name'],
        ]) as $row) {
            $tasks[(int) $row['id']] = $row + ['jobs' => []];
        }
        if (!empty($tasks)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'plugin_glpiinventory_tasks_id'],
                'FROM'   => PluginGlpiinventoryTaskjob::getTable(),
                'WHERE'  => ['plugin_glpiinventory_tasks_id' => array_keys($tasks)],
            ]) as $row) {
                $tasks[(int) $row['plugin_glpiinventory_tasks_id']]['jobs'][] = (int) $row['id'];
            }
        }
        return $tasks;
    }

    /**
     * Applique la fréquence de chaque entité à ses tâches : date de début repoussée à « fin du dernier relevé +
     * fréquence », effacée quand le relevé est dû. Une tâche en cours d'exécution ou avec une date de fin n'est pas
     * touchée.
     *
     * @param ?int[] $entities_ids seulement les tâches de ces entités ; null : toutes
     * @return array ['tasks', 'deferred' (date posée), 'released' (date effacée), 'running', 'manual']
     */
    public static function applySchedule(?array $entities_ids = null): array {
        global $DB;

        $stats = ['tasks' => 0, 'deferred' => 0, 'released' => 0, 'running' => 0, 'manual' => 0];
        foreach (self::getManagedTasks($entities_ids) as $tasks_id => $task) {
            $stats['tasks']++;
            if ($task['datetime_end'] !== null) {
                $stats['manual']++;
                continue;
            }
            if (empty($task['jobs'])) {
                continue;
            }
            // Relevé transmis à la sonde et pas encore rendu : on attend sa fin.
            $running = countElementsInTable(PluginGlpiinventoryTaskjobstate::getTable(), [
                'plugin_glpiinventory_taskjobs_id' => $task['jobs'],
                'state'                            => [PluginGlpiinventoryTaskjobstate::SERVER_HAS_SENT_DATA, PluginGlpiinventoryTaskjobstate::AGENT_HAS_SENT_DATA],
            ]);
            if ($running > 0) {
                $stats['running']++;
                continue;
            }
            $last = $DB->request([
                'SELECT'     => [new QueryExpression('MAX(' . $DB->quoteName('l.date') . ') AS ' . $DB->quoteName('last_end'))],
                'FROM'       => PluginGlpiinventoryTaskjoblog::getTable() . ' AS l',
                'INNER JOIN' => [PluginGlpiinventoryTaskjobstate::getTable() . ' AS s' => ['ON' => ['l' => 'plugin_glpiinventory_taskjobstates_id', 's' => 'id']]],
                'WHERE'      => [
                    's.plugin_glpiinventory_taskjobs_id' => $task['jobs'],
                    's.state'                            => [PluginGlpiinventoryTaskjobstate::FINISHED, PluginGlpiinventoryTaskjobstate::IN_ERROR],
                ],
            ])->current();

            $desired = null;
            if (!empty($last['last_end'])) {
                $next = strtotime((string) $last['last_end']) + self::getForEntity((int) $task['entities_id'])['hours'] * HOUR_TIMESTAMP;
                if ($next > time()) {
                    $desired = date('Y-m-d H:i:s', $next);
                }
            }
            $current = $task['datetime_start'] !== null ? (string) $task['datetime_start'] : null;
            if ($desired === null && ($current === null || strtotime($current) <= time())) {
                continue;
            }
            if ($desired !== null && $current !== null && abs(strtotime($desired) - strtotime($current)) < MINUTE_TIMESTAMP) {
                continue;
            }
            // Écriture directe : GLPI Inventory refuse de modifier une tâche active et annule ses jobs à la désactivation.
            $DB->update(PluginGlpiinventoryTask::getTable(), ['datetime_start' => $desired], ['id' => $tasks_id]);
            $desired === null ? $stats['released']++ : $stats['deferred']++;
        }
        return $stats;
    }

    /** Date de début effacée (déclenchement de l'assistant) : les jobs peuvent être préparés tout de suite. */
    public static function releaseTasks(array $tasks_ids): void {
        global $DB;

        $tasks_ids = array_values(array_filter(array_map('intval', $tasks_ids)));
        if (empty($tasks_ids) || !PluginPrintgestionCollectsetup::isAvailable()) {
            return;
        }
        $DB->update(PluginGlpiinventoryTask::getTable(), ['datetime_start' => null], ['id' => $tasks_ids, 'datetime_end' => null]);
    }

    public static function cronInfo($name) {
        return ['description' => __('Print Gestion : fréquence des relevés d\'imprimantes par entité (tâches GLPI Inventory)', 'printgestion')];
    }

    /** Tâche automatique (toutes les 15 minutes) : applique la fréquence de chaque entité à ses tâches. */
    public static function cronPrintgestionCollectSchedule($task = null) {
        if (!PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
            return 0;
        }
        $stats = self::applySchedule();
        if ($task instanceof CronTask) {
            $task->addVolume($stats['deferred'] + $stats['released']);
            $task->log(sprintf(
                'Tâches : %d — date de début repoussée : %d — relevé dû : %d — en cours : %d — date de fin réglée à la main : %d',
                $stats['tasks'],
                $stats['deferred'],
                $stats['released'],
                $stats['running'],
                $stats['manual']
            ));
        }
        return $stats['deferred'] + $stats['released'] > 0 ? 1 : 0;
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    /** Onglet « Déploiement Agent » de l'entité : fréquence des relevés, modifiable avant de générer le paquet. */
    public static function showForEntity(Entity $entity): void {
        $esc         = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $entities_id = (int) $entity->getID();
        $current     = self::getForEntity($entities_id);
        $contact     = self::getContactHours();

        echo "<div class='border rounded p-3 mb-3'><div class='fw-bold mb-1'><i class='ti ti-clock me-1'></i>" . $esc(__('Fréquence des relevés d\'imprimantes', 'printgestion')) . "</div>";
        echo "<p class='mb-2'>" . $esc(sprintf(__('%1$s (%2$s).', 'printgestion'), ucfirst(self::getLabel($current['frequency'], $current['modifier'])), self::getSourceLabel($current))) . "</p>";
        if (Session::haveRight(self::$rightname, UPDATE)) {
            echo "<form method='post' action='" . $esc(self::getFormURL()) . "' class='row g-2 align-items-end mb-2'>" . Html::hidden('entities_id', ['value' => $entities_id]);
            echo "<div class='col-sm-5 col-lg-4'><select class='form-select' name='frequency'>";
            foreach (['daily' => __('Tous les N jours', 'printgestion'), 'hourly' => __('Toutes les N heures', 'printgestion')] as $value => $label) {
                echo "<option value='{$value}'" . ($current['source'] === 'entity' && $current['frequency'] === $value ? ' selected' : '') . ">" . $esc($label) . "</option>";
            }
            if ($entities_id > 0) {
                echo "<option value='inherit'" . ($current['source'] !== 'entity' ? ' selected' : '') . ">" . $esc(__('Comme l\'entité parente', 'printgestion')) . "</option>";
            }
            echo "</select></div>";
            echo "<div class='col-sm-3 col-lg-2'><input type='number' class='form-control' name='modifier' min='1' max='365' value='" . (int) $current['modifier'] . "' aria-label='" . $esc(__('N', 'printgestion')) . "'></div>";
            echo "<div class='col-sm-4 col-lg-3'><button type='submit' name='save_frequency' value='1' class='btn btn-outline-primary'><i class='ti ti-device-floppy me-1'></i>" . $esc(__('Enregistrer', 'printgestion')) . "</button></div>";
            echo Html::closeForm(false);
        }
        if ($current['hours'] < $contact) {
            echo "<div class='alert alert-warning mb-2'>" . $esc(sprintf(__('GLPI ne contacte les agents que toutes les %d h (Administration > Inventaire, fréquence d\'inventaire) : les relevés ne seront pas plus fréquents. Réglez-la à 1 heure pour permettre des relevés plus rapprochés.', 'printgestion'), $contact)) . "</div>";
        }
        echo "<p class='text-muted small mb-1'>" . $esc(sprintf(
            __('Appliquée par GLPI aux tâches de découverte et d\'inventaire réseau des raccordements de cette entité : rien à régler sur la sonde, rien à réinstaller pour la changer. Au plus souvent, la fréquence d\'inventaire de GLPI (%d h), à laquelle les agents le contactent. Une imprimante n\'est dite muette qu\'après %d jours sans relevé.', 'printgestion'),
            $contact,
            self::getSilentDaysForEntity($entities_id)
        )) . "</p>";
        $tasks = self::getManagedTasks([$entities_id]);
        if (!empty($tasks)) {
            echo "<ul class='small mb-0'>";
            foreach ($tasks as $task) {
                $start = $task['datetime_start'] !== null ? strtotime((string) $task['datetime_start']) : null;
                echo "<li>" . $esc(sprintf(
                    __('« %1$s » : %2$s', 'printgestion'),
                    $task['name'],
                    $task['datetime_end'] !== null
                        ? __('plage de dates réglée à la main dans GLPI Inventory, fréquence non appliquée', 'printgestion')
                        : ($start !== null && $start > time()
                            ? sprintf(__('prochain relevé pas avant le %s', 'printgestion'), Html::convDateTime(date('Y-m-d H:i:s', $start)))
                            : __('relevé au prochain contact de la sonde', 'printgestion'))
                )) . "</li>";
            }
            echo "</ul>";
        }
        echo "</div>";
    }

    static function uninstall(Migration $migration) {
        global $DB;

        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, 'PrintgestionCollectSchedule')) {
            $task->delete(['id' => (int) $task->getID()]);
        }
        $DB->doQuery('DROP TABLE IF EXISTS `' . self::getTable() . '`');
        return true;
    }
}
