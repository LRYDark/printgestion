<?php
/**
 * PluginPrintgestionConfighealth — carte « Santé de la configuration », en tête de la configuration du plugin.
 *
 * Contrôles automatiques de l'environnement, lus à chaque affichage (rien à cocher) : ce qui est obligatoire (sans
 * lui, quelque chose ne marche pas) et ce qui est recommandé. Chaque ligne : vert ou rouge, ce qui casse, où
 * corriger. Un contrôle qui ne peut pas être automatisé le dit sur sa ligne. La carte ne bloque rien ; tout vert,
 * elle se replie en une ligne « Configuration : complète ». Réservée à l'administrateur (droit de configuration
 * du plugin, comme la page).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionConfighealth {

    const STATE_OK      = 'ok';
    const STATE_ERROR   = 'error';
    const STATE_MANUAL  = 'manual';
    /** Vérifiable en partie seulement : rien de faux, mais le réel ne l'a pas encore confirmé. */
    const STATE_PENDING = 'pending';

    /** Nom de la tâche témoin (CLI seulement, chaque minute) : sa dernière exécution prouve le cron système. */
    const WITNESS_TASK = 'PrintgestionTemoinCron';
    /** Fenêtre dans laquelle le témoin doit être passé (cron_limit tâches par passage : quelques minutes de retard possibles). */
    const WITNESS_WINDOW = 15 * MINUTE_TIMESTAMP;

    /**
     * Contrôles, dans l'ordre d'affichage.
     *
     * @return array[] ['key', 'group' (required|recommended), 'label', 'state', 'status' (état en une phrase),
     *                 'breaks' (ce qui casse), 'fix' (où corriger), 'url' (lien vers l'écran, ou ''), 'detail' (HTML)]
     */
    public static function getChecks(): array {
        global $CFG_GLPI;

        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $checks = [];

        // En tête : une URL fausse rend chaque agent déployé injoignable par GLPI, sans réparation à distance. Sa
        // syntaxe se vérifie d'ici ; qu'elle soit joignable depuis un réseau client, seul un agent qui remonte le prouve.
        // Limite connue : un agent prouve l'URL avec laquelle IL a été configuré, pas celle réglée aujourd'hui. Si
        // l'URL change et que d'anciens agents continuent de remonter (installés avec l'ancienne, encore valide), la
        // ligne passe au vert en prouvant l'ancienne valeur. Sans objet au pilote (aucun agent) ; à garder en tête
        // le jour où l'URL change avec des agents en place.
        $url_issue = PluginPrintgestionAgentdeploy::getApplicationUrlIssue();
        $confirmed = $url_issue === '' ? self::getUrlConfirmation((string) ($CFG_GLPI['url_base'] ?? '')) : null;
        $checks[]  = [
            'key'    => 'app_url',
            'group'  => 'required',
            'label'  => __('URL de l\'application GLPI', 'printgestion'),
            'state'  => $url_issue !== '' ? self::STATE_ERROR : ($confirmed['agent'] !== null ? self::STATE_OK : self::STATE_PENDING),
            'status' => $url_issue !== '' ? $url_issue : ($confirmed['agent'] !== null
                ? sprintf(__('%1$s — confirmée par un agent le %2$s (sonde %3$s).', 'printgestion'), $confirmed['url'], Html::convDateTime($confirmed['agent']['last_contact']), $confirmed['agent']['name'])
                : sprintf(__('%1$s — jamais confirmée : aucun agent n\'a encore remonté avec cette URL (en place depuis le %2$s).', 'printgestion'), $confirmed['url'], Html::convDateTime($confirmed['since']))),
            'breaks' => __('Un agent déployé avec une URL fausse ne contacte jamais GLPI et ne se répare pas à distance : il faut retourner sur le site. Le téléchargement des installeurs est bloqué tant qu\'elle est fausse.', 'printgestion'),
            'fix'    => __('Configuration → Générale, « URL de l\'application »', 'printgestion'),
            'url'    => Config::getFormURL(),
            'detail' => '',
        ];

        $inventory_on = (int) Config::getConfigurationValue('inventory', 'enabled_inventory') === 1;
        $checks[] = [
            'key'    => 'inventory',
            'group'  => 'required',
            'label'  => __('Inventaire GLPI activé', 'printgestion'),
            'state'  => $inventory_on ? self::STATE_OK : self::STATE_ERROR,
            'status' => $inventory_on ? __('Activé.', 'printgestion') : __('Désactivé.', 'printgestion'),
            'breaks' => __('Aucun inventaire n\'est reçu : ni sonde, ni imprimante, ni relevé de niveaux.', 'printgestion'),
            'fix'    => __('Administration → Inventaire, « Activer l\'inventaire »', 'printgestion'),
            'url'    => $CFG_GLPI['root_doc'] . '/front/inventory.conf.php',
            'detail' => '',
        ];

        $prerequisites = PluginPrintgestionCollectsetup::getPrerequisites();
        $inventory_ok  = empty($prerequisites['blocking']);
        $checks[] = [
            'key'    => 'glpiinventory',
            'group'  => 'required',
            'label'  => __('Plugin GLPI Inventory installé et actif', 'printgestion'),
            'state'  => $inventory_ok ? self::STATE_OK : self::STATE_ERROR,
            'status' => $inventory_ok
                ? sprintf(__('Actif, version %s.', 'printgestion'), $prerequisites['version'])
                : __('Absent, inactif ou inutilisable.', 'printgestion'),
            'breaks' => __('Aucune imprimante ne remonte en mode central : rien ne dit aux sondes quelles plages IP scanner.', 'printgestion'),
            'fix'    => __('Configuration → Plugins → Marketplace, rechercher « GLPI Inventory »', 'printgestion'),
            'url'    => Plugin::getSearchURL(),
            'detail' => implode('<br>', array_map($esc, array_merge($prerequisites['blocking'], $prerequisites['warnings']))),
        ];

        $cron = self::getCronStatus();
        $checks[] = [
            'key'    => 'cron',
            'group'  => 'required',
            'label'  => __('Actions automatiques du plugin en mode CLI avec un cron système', 'printgestion'),
            'state'  => $cron['ok'] ? self::STATE_OK : self::STATE_ERROR,
            'status' => $cron['status'],
            'breaks' => __('En mode Interne, les tâches ne tournent que quand quelqu\'un navigue : relevés irréguliers, alertes en retard, commandes non transmises jamais signalées. Sans cron système, rien ne tourne du tout.', 'printgestion'),
            'fix'    => __('Sur le serveur : cron système qui lance front/cron.php chaque minute, puis GLPI_SYSTEM_CRON dans config/local_define.php ; dans GLPI : Configuration → Actions automatiques, fiche de chaque tâche', 'printgestion'),
            'url'    => CronTask::getSearchURL(),
            'detail' => self::renderCronDetail($cron),
            'button' => $cron['switchable'] ? self::getSwitchTasksButton(count($cron['internal'])) : '',
        ];

        $xlsx_ok  = (bool) Document::isValidDoc('Gesconso.xlsx');
        $checks[] = [
            'key'    => 'xlsx',
            'group'  => 'required',
            'label'  => __('Type de document xlsx autorisé', 'printgestion'),
            'state'  => $xlsx_ok ? self::STATE_OK : self::STATE_ERROR,
            'status' => $xlsx_ok ? __('Autorisé.', 'printgestion') : __('Non autorisé.', 'printgestion'),
            'breaks' => __('Le fichier Gesconso ne peut pas être archivé, donc aucune commande ne part.', 'printgestion'),
            'fix'    => __('Configuration → Intitulés → Types de document, extension xlsx, « Autoriser l\'import » : Oui', 'printgestion'),
            'url'    => DocumentType::getSearchURL(),
            'detail' => '',
        ];

        // Les notifications natives du plugin (commande non transmise, demandes, sondes, contrats) n'existent qu'avec ce réglage.
        $notif_on = (int) ($CFG_GLPI['use_notifications'] ?? 0) === 1;
        $checks[] = [
            'key'    => 'notifications',
            'group'  => 'required',
            'label'  => __('Notifications GLPI activées', 'printgestion'),
            'state'  => $notif_on ? self::STATE_OK : self::STATE_ERROR,
            'status' => $notif_on ? __('Activées.', 'printgestion') : __('Désactivées.', 'printgestion'),
            'breaks' => __('Aucune notification native ne part : commande non transmise aux Achats, demandes d\'envoi, sondes et imprimantes muettes, alertes de contrat — sans qu\'aucun écran ne le dise.', 'printgestion'),
            'fix'    => __('Configuration → Notifications → Configuration des notifications, « Activer le suivi »', 'printgestion'),
            'url'    => $CFG_GLPI['root_doc'] . '/front/setup.notification.php',
            'detail' => '',
        ];

        $rule     = PluginPrintgestionAgentdeploy::getTagRuleStatus();
        $rule_ok  = $rule['active'] !== null;
        $checks[] = [
            'key'    => 'tag_rule',
            'group'  => 'required',
            'label'  => __('Règle d\'affectation par TAG présente et active', 'printgestion'),
            'state'  => $rule_ok ? self::STATE_OK : self::STATE_ERROR,
            'status' => $rule_ok
                ? __('Active.', 'printgestion')
                : (empty($rule['rules']) ? __('Absente.', 'printgestion') : __('Présente mais désactivée.', 'printgestion')),
            'breaks' => __('Les équipements arrivent dans la mauvaise entité, sans correction possible ensuite.', 'printgestion'),
            'fix'    => __('Bouton de cette carte', 'printgestion'),
            'url'    => '',
            'detail' => $esc(PluginPrintgestionAgentdeploy::describeTagRule($rule)),
            'button' => PluginPrintgestionAgentdeploy::getTagRuleButton($rule),
        ];

        // Non vérifiable automatiquement : acquittable (« J'ai vérifié »), et la ligne redevient visible d'elle-même au bout
        // de six mois — un rappel que personne ne peut éteindre finit ignoré, et la vraie alerte du jour avec lui.
        $ack = self::getKeyBackupAck();
        $checks[] = [
            'key'    => 'glpicrypt',
            'group'  => 'recommended',
            'label'  => __('Sauvegarde de config/glpicrypt.key avec la base', 'printgestion'),
            'state'  => $ack['fresh'] ? self::STATE_OK : self::STATE_MANUAL,
            'status' => $ack['fresh']
                ? sprintf(__('Vérifié le %1$s par %2$s.', 'printgestion'), Html::convDate($ack['date']), $ack['user'])
                : ($ack['date'] !== '' ? sprintf(__('Non vérifiable automatiquement — dernière vérification le %1$s par %2$s, il y a plus de six mois.', 'printgestion'), Html::convDate($ack['date']), $ack['user'])
                    : __('Non vérifiable automatiquement — jamais vérifié.', 'printgestion')),
            'breaks' => __('Sans ce fichier, les valeurs chiffrées sont définitivement perdues à la restauration : mots de passe LDAP et SMTP, secret GLS.', 'printgestion'),
            'fix'    => sprintf(__('Sauvegarde du serveur : fichier %s, dans le même jeu que la base de données', 'printgestion'), GLPI_CONFIG_DIR . '/glpicrypt.key'),
            'url'    => '',
            'detail' => '',
            'button' => $ack['fresh'] ? '' : self::getKeyBackupAckButton(),
        ];

        $log    = PluginPrintgestionLogger::getStatus();
        $log_ok = $log['dir_writable'] && $log['writable'];
        $checks[] = [
            'key'    => 'log',
            'group'  => 'recommended',
            'label'  => __('Journal du plugin inscriptible', 'printgestion'),
            'state'  => $log_ok ? self::STATE_OK : self::STATE_ERROR,
            'status' => $log_ok ? __('Inscriptible.', 'printgestion') : __('Non inscriptible : aucune trace ne sera écrite ici.', 'printgestion'),
            'breaks' => __('Sinon les erreurs sont perdues en silence.', 'printgestion'),
            'fix'    => sprintf(__('Serveur : droits d\'écriture du serveur web sur %s', 'printgestion'), $log['path']),
            'url'    => '',
            'detail' => $esc($log['exists']
                ? sprintf(__('%1$s octets, dernière écriture le %2$s.', 'printgestion'), number_format($log['size'], 0, ',', ' '), Html::convDateTime($log['modified']))
                : __('Fichier pas encore créé : aucune erreur ni avertissement journalisé depuis l\'installation, ou journal non inscriptible.', 'printgestion')),
        ];

        return $checks;
    }

    /** Acquittement de la sauvegarde de glpicrypt.key : mémo dérivé (date, auteur) dans la configuration GLPI du plugin. */
    const KEY_BACKUP_ACK_VALIDITY = 182 * DAY_TIMESTAMP;

    /** @return array ['date' => 'Y-m-d H:i:s'|'', 'user' => string, 'fresh' => bool] */
    public static function getKeyBackupAck(): array {
        $memo = Config::getConfigurationValues('plugin:printgestion', ['glpicrypt_checked_at', 'glpicrypt_checked_by']);
        $date = (string) ($memo['glpicrypt_checked_at'] ?? '');
        return [
            'date'  => $date,
            'user'  => (string) ($memo['glpicrypt_checked_by'] ?? ''),
            'fresh' => $date !== '' && strtotime($date) >= strtotime(Session::getCurrentTime()) - self::KEY_BACKUP_ACK_VALIDITY,
        ];
    }

    /** Enregistre l'acquittement (date de GLPI, nom de l'utilisateur connecté). */
    public static function acknowledgeKeyBackup(): void {
        Config::setConfigurationValues('plugin:printgestion', [
            'glpicrypt_checked_at' => Session::getCurrentTime(),
            'glpicrypt_checked_by' => getUserName((int) Session::getLoginUserID()),
        ]);
    }

    private static function getKeyBackupAckButton(): string {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        return "<button type='submit' name='ack_glpicrypt' value='1' data-pg-submit-once='1' class='btn btn-outline-primary' formnovalidate>"
            . "<i class='ti ti-checkbox me-1'></i>" . $esc(__('J\'ai vérifié', 'printgestion')) . "</button>";
    }

    /**
     * Tâches automatiques lues dans GLPI (glpi_crontasks), jamais redéfinies : état, mode, fréquence, dernière
     * exécution. Le plugin les affiche, dit si le mode est correct, et renvoie vers la fiche native de chaque tâche.
     *
     * @param string[] $names noms des tâches
     * @return array[] [['id', 'name', 'itemtype', 'state', 'mode', 'frequency', 'lastrun', 'description']]
     */
    public static function getTaskRows(array $names): array {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'itemtype', 'state', 'mode', 'frequency', 'lastrun'],
            'FROM'   => CronTask::getTable(),
            'WHERE'  => ['name' => $names],
            'ORDER'  => ['name'],
        ]) as $row) {
            $info = is_callable([$row['itemtype'], 'cronInfo']) ? (array) call_user_func([$row['itemtype'], 'cronInfo'], $row['name']) : [];
            $rows[] = $row + ['description' => (string) ($info['description'] ?? $row['name'])];
        }
        return $rows;
    }

    /** Fréquence lisible : « chaque minute », « toutes les 4 heures », « tous les 7 jours ». */
    public static function formatFrequency(int $seconds): string {
        if ($seconds > 0 && $seconds % DAY_TIMESTAMP === 0) {
            $n = intdiv($seconds, DAY_TIMESTAMP);
            return $n === 1 ? __('chaque jour', 'printgestion') : sprintf(__('tous les %d jours', 'printgestion'), $n);
        }
        if ($seconds > 0 && $seconds % HOUR_TIMESTAMP === 0) {
            $n = intdiv($seconds, HOUR_TIMESTAMP);
            return $n === 1 ? __('chaque heure', 'printgestion') : sprintf(__('toutes les %d heures', 'printgestion'), $n);
        }
        if ($seconds > 0 && $seconds % MINUTE_TIMESTAMP === 0) {
            $n = intdiv($seconds, MINUTE_TIMESTAMP);
            return $n === 1 ? __('chaque minute', 'printgestion') : sprintf(__('toutes les %d minutes', 'printgestion'), $n);
        }
        return sprintf(__('toutes les %s', 'printgestion'), Html::timestampToString($seconds, false));
    }

    /**
     * Encart en lecture seule d'une ou plusieurs tâches : une ligne par tâche et un lien « Configurer dans GLPI »
     * vers sa fiche. Une tâche en mode Interne (GLPI) est dite en toutes lettres, car elle ne partira pas de façon
     * fiable : rien ne se règle ici.
     */
    public static function renderTaskTable(array $rows): string {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        if (empty($rows)) {
            return "<p class='text-muted mb-0'>" . $esc(__('Tâche automatique introuvable : relancer la mise à jour du plugin (Configuration → Plugins).', 'printgestion')) . "</p>";
        }
        $html = "<table class='table table-sm mb-2'><thead><tr><th>" . $esc(__('Tâche automatique', 'printgestion')) . "</th><th>" . $esc(__('État', 'printgestion'))
            . "</th><th>" . $esc(__('Mode d\'exécution', 'printgestion')) . "</th><th>" . $esc(__('Fréquence', 'printgestion')) . "</th><th>" . $esc(__('Dernière exécution', 'printgestion')) . "</th><th></th></tr></thead><tbody>";
        $internal = false;
        foreach ($rows as $row) {
            $is_internal = (int) $row['mode'] === CronTask::MODE_INTERNAL;
            $internal    = $internal || ($is_internal && (int) $row['state'] !== CronTask::STATE_DISABLE);
            $html .= "<tr><td title='" . $esc($row['name']) . "'>" . $esc($row['description']) . "</td>"
                . "<td>" . $esc(CronTask::getStateName((int) $row['state'])) . "</td>"
                . "<td class='" . ($is_internal ? 'text-danger fw-bold' : '') . "'>" . $esc($is_internal ? __('Interne (GLPI)', 'printgestion') : __('CLI', 'printgestion')) . "</td>"
                . "<td>" . $esc(self::formatFrequency((int) $row['frequency'])) . "</td>"
                . "<td>" . $esc(!empty($row['lastrun']) ? Html::convDateTime((string) $row['lastrun']) : __('jamais', 'printgestion')) . "</td>"
                . "<td><a href='" . $esc(CronTask::getFormURLWithID((int) $row['id'])) . "'>" . $esc(__('Configurer dans GLPI', 'printgestion')) . "</a></td></tr>";
        }
        $html .= "</tbody></table>";
        if ($internal) {
            $html .= "<div class='alert alert-danger mb-0'>" . $esc(__('Mode d\'exécution « Interne » — les tâches automatiques ne partiront pas de façon fiable. Elles ne s\'exécutent que lorsqu\'un utilisateur navigue dans GLPI. Le mode CLI avec une tâche système est nécessaire.', 'printgestion')) . "</div>";
        }
        return $html;
    }

    /**
     * Preuve par le réel de l'URL de l'application : un agent portant le TAG d'une entité (donc déployé avec un
     * paquet du plugin) a contacté GLPI depuis que cette URL est en place. « Depuis quand » : mémo dérivé, dans la
     * configuration GLPI du plugin (contexte plugin:printgestion), réécrit seulement quand l'URL change — pas un
     * réglage, personne ne le saisit.
     *
     * @return array ['url' => string, 'since' => 'Y-m-d H:i:s', 'agent' => ?['name', 'last_contact']]
     */
    public static function getUrlConfirmation(string $url): array {
        global $DB;

        $memo  = Config::getConfigurationValues('plugin:printgestion', ['url_base_seen', 'url_base_seen_since']);
        $since = (string) ($memo['url_base_seen_since'] ?? '');
        if (($memo['url_base_seen'] ?? null) !== $url || $since === '') {
            $since = Session::getCurrentTime();
            Config::setConfigurationValues('plugin:printgestion', ['url_base_seen' => $url, 'url_base_seen_since' => $since]);
        }
        $agent = $DB->request([
            'SELECT'     => ['a.name', 'a.last_contact'],
            'FROM'       => 'glpi_agents AS a',
            'INNER JOIN' => ['glpi_entities AS e' => ['ON' => ['e' => 'tag', 'a' => 'tag']]],
            'WHERE'      => ['e.tag' => ['<>', ''], 'a.last_contact' => ['>=', $since]],
            'ORDER'      => ['a.last_contact DESC'],
            'LIMIT'      => 1,
        ])->current();
        return ['url' => $url, 'since' => $since, 'agent' => is_array($agent) ? $agent : null];
    }

    /**
     * Cron : jugé sur les tâches du plugin seulement (une carte rouge à cause de tâches qu'on ne possède pas est une
     * carte qu'on apprend à ignorer), plus `queuednotification` en lecture — les notifications natives du plugin en
     * dépendent. Le cron système est prouvé par la tâche témoin (mode CLI seulement, chaque minute) passée dans la
     * fenêtre, ou déclaré par GLPI_SYSTEM_CRON. Le plugin ne bascule rien tout seul : quand le cron système est
     * prouvé et que des tâches du plugin sont en mode Interne, un bouton propose la bascule — un clic explicite.
     *
     * @return array ['ok', 'status', 'proven', 'system_cron_declared', 'witness' => ?row, 'tasks' => rows,
     *               'internal' => noms en mode Interne, 'queue' => ?row, 'switchable' => bool]
     */
    public static function getCronStatus(): array {
        global $DB;

        $tasks    = [];
        $witness  = null;
        $internal = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'itemtype', 'state', 'mode', 'frequency', 'lastrun'],
            'FROM'   => CronTask::getTable(),
            'WHERE'  => ['itemtype' => ['LIKE', 'PluginPrintgestion%']],
            'ORDER'  => ['name'],
        ]) as $row) {
            $info = is_callable([$row['itemtype'], 'cronInfo']) ? (array) call_user_func([$row['itemtype'], 'cronInfo'], $row['name']) : [];
            $row += ['description' => (string) ($info['description'] ?? $row['name'])];
            if ($row['name'] === self::WITNESS_TASK) {
                $witness = $row;
                continue;
            }
            $tasks[] = $row;
            if ((int) $row['mode'] === CronTask::MODE_INTERNAL && (int) $row['state'] !== CronTask::STATE_DISABLE) {
                $internal[] = $row['description'];
            }
        }
        $queue    = $DB->request(['FROM' => CronTask::getTable(), 'WHERE' => ['name' => 'queuednotification']])->current() ?: null;
        $declared = defined('GLPI_SYSTEM_CRON') && GLPI_SYSTEM_CRON;
        $seen     = $witness !== null && !empty($witness['lastrun'])
            && strtotime((string) $witness['lastrun']) >= strtotime(Session::getCurrentTime()) - self::WITNESS_WINDOW;
        $proven   = $declared || $seen;

        if (!$proven) {
            $status = $witness === null
                ? __('Tâche témoin absente : relancer la mise à jour du plugin (Configuration → Plugins).', 'printgestion')
                : (empty($witness['lastrun'])
                    ? __('Aucun cron système détecté : la tâche témoin (mode CLI, chaque minute) n\'a jamais tourné. Rien ne partira de façon fiable.', 'printgestion')
                    : sprintf(__('Aucun cron système détecté : la tâche témoin n\'a pas tourné depuis le %s. Rien ne partira de façon fiable.', 'printgestion'), Html::convDateTime((string) $witness['lastrun'])));
        } elseif (!empty($internal)) {
            $status = sprintf(_n(
                'Mode Interne — rien ne partira de façon fiable. %d tâche du plugin à passer en CLI (cron système détecté).',
                'Mode Interne — rien ne partira de façon fiable. %d tâches du plugin à passer en CLI (cron système détecté).',
                count($internal),
                'printgestion'
            ), count($internal));
        } else {
            $status = sprintf(__('Cron système actif (%1$s), les %2$d tâches du plugin en CLI.', 'printgestion'),
                $declared && !$seen ? __('déclaré par GLPI_SYSTEM_CRON', 'printgestion') : sprintf(__('témoin passé le %s', 'printgestion'), Html::convDateTime((string) $witness['lastrun'])),
                count($tasks));
        }
        return [
            'ok'                   => $proven && empty($internal),
            'status'               => $status,
            'proven'               => $proven,
            'system_cron_declared' => $declared,
            'witness'              => $witness,
            'tasks'                => $tasks,
            'internal'             => $internal,
            'queue'                => $queue,
            'switchable'           => $proven && !empty($internal),
        ];
    }

    /** Détail du contrôle cron : GLPI_SYSTEM_CRON, les tâches du plugin une par une, le témoin, la file des notifications. */
    private static function renderCronDetail(array $cron): string {
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = "<p class='mb-2'>" . $esc(sprintf(__('GLPI_SYSTEM_CRON : %s', 'printgestion'), $cron['system_cron_declared']
            ? __('oui — GLPI crée ses tâches en CLI et les y bascule à sa mise à jour', 'printgestion')
            : __('non (config/local_define.php) — les nouvelles tâches naissent en mode Interne', 'printgestion'))) . "</p>";
        $rows = $cron['tasks'];
        if ($cron['witness'] !== null) {
            $rows[] = $cron['witness'];
        }
        $html .= self::renderTaskTable($rows);
        if (is_array($cron['queue'])) {
            $internal = (int) $cron['queue']['mode'] === CronTask::MODE_INTERNAL;
            $html .= "<p class='small mb-0 mt-2'>" . $esc(sprintf(
                __('Envoi des notifications natives (queuednotification, tâche de GLPI, lecture seule) : mode %s. Les notifications du plugin en dépendent.', 'printgestion'),
                $internal ? __('Interne (GLPI)', 'printgestion') : __('CLI', 'printgestion')
            )) . " <a href='" . $esc(CronTask::getFormURLWithID((int) $cron['queue']['id'])) . "'>" . $esc(__('Configurer dans GLPI', 'printgestion')) . "</a></p>";
        }
        return $html;
    }

    /** Bouton de bascule des tâches du plugin en CLI : clic explicite, confirmé, jamais automatique. */
    private static function getSwitchTasksButton(int $count): string {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $confirm = __('Passer les tâches automatiques de Print Gestion en mode CLI ? Les tâches de GLPI et des autres plugins ne sont pas touchées.', 'printgestion');
        return "<button type='submit' name='switch_plugin_tasks_cli' value='1' data-pg-submit-once='1' class='btn btn-primary' formnovalidate onclick=\"return confirm(" . $esc(json_encode($confirm)) . ");\">"
            . "<i class='ti ti-terminal-2 me-1'></i>" . $esc(sprintf(_n('Passer la tâche de Print Gestion en CLI', 'Passer les %d tâches de Print Gestion en CLI', $count, 'printgestion'), $count)) . "</button>";
    }

    /** Carte en tête de la configuration, dans le formulaire de la page (boutons envoyés à front/config.form.php). */
    public static function showCard(bool $canedit): void {
        if (!PluginPrintgestionUi::isAdmin()) {
            return;
        }
        $esc      = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $checks   = self::getChecks();
        $failed   = array_filter($checks, static fn(array $c) => $c['group'] === 'required' && $c['state'] === self::STATE_ERROR);
        // Repliée en une ligne seulement quand tout est vert ET que le réel a confirmé ce qu'il peut confirmer.
        // Repliée seulement quand rien n'est en erreur, en attente du réel, ni à acquitter.
        $complete = empty(array_filter($checks, static fn(array $c) => in_array($c['state'], [self::STATE_ERROR, self::STATE_PENDING, self::STATE_MANUAL], true)));

        $groups = [
            'required'    => __('Obligatoire', 'printgestion'),
            'recommended' => __('Recommandé', 'printgestion'),
        ];
        $icons = [
            self::STATE_OK     => ['ti-circle-check', 'text-success', __('Correct', 'printgestion')],
            self::STATE_ERROR  => ['ti-alert-octagon', 'text-danger', __('À corriger', 'printgestion')],
            self::STATE_MANUAL  => ['ti-help-circle', 'text-secondary', __('Non vérifiable automatiquement', 'printgestion')],
            self::STATE_PENDING => ['ti-clock', 'text-warning', __('Jamais confirmée par le réel', 'printgestion')],
        ];
        $body = '';
        foreach ($groups as $group => $title) {
            $body .= "<h4 class='mt-3 mb-2'>" . $esc($title) . "</h4><div class='list-group mb-2'>";
            foreach (array_filter($checks, static fn(array $c) => $c['group'] === $group) as $check) {
                [$icon, $color, $state_label] = $icons[$check['state']];
                $error = $check['state'] === self::STATE_ERROR;
                $fix   = $check['url'] !== ''
                    ? "<a href='" . $esc($check['url']) . "'>" . $esc($check['fix']) . "</a>"
                    : $esc($check['fix']);
                $body .= "<div class='list-group-item" . ($error && $group === 'required' ? ' list-group-item-danger' : '') . "' data-pg-health='" . $esc($check['key']) . "' data-pg-state='" . $esc($check['state']) . "'>"
                    . "<div class='d-flex align-items-start gap-2'><i class='ti {$icon} {$color} fs-2' title='" . $esc($state_label) . "' aria-label='" . $esc($state_label) . "'></i><div class='flex-fill'>"
                    . "<div><span class='fw-bold'>" . $esc($check['label']) . "</span> — " . $esc($check['status']) . "</div>"
                    . "<div class='" . ($error ? '' : 'text-muted ') . "small'>" . $esc($check['breaks']) . "</div>"
                    . "<div class='small'><i class='ti ti-tool me-1'></i>" . $fix . "</div>"
                    . ($check['detail'] !== '' ? "<div class='text-muted small mt-1'>" . $check['detail'] . "</div>" : '');
                // Bouton d'action : ligne en erreur, ou rappel manuel à acquitter.
                if (($error || $check['state'] === self::STATE_MANUAL) && ($check['button'] ?? '') !== '' && $canedit) {
                    $body .= "<div class='mt-2'>" . $check['button'] . "</div>";
                }
                if ($check['key'] === 'log' && $canedit) {
                    $body .= "<div class='mt-2'><button type='submit' name='test_log' value='1' class='btn btn-sm btn-outline-secondary' formnovalidate><i class='ti ti-file-check me-1'></i>"
                        . $esc(__('Écrire une entrée de test et la relire', 'printgestion')) . "</button></div>";
                }
                $body .= "</div></div></div>";
            }
            $body .= "</div>";
        }

        $info = "<p>" . $esc(__('Le mode d\'exécution des actions automatiques est le prérequis le plus souvent négligé, et le plus lourd de conséquences.', 'printgestion')) . "</p>"
            . "<p>" . $esc(__('En mode « GLPI », une action ne se lance que lorsqu\'un utilisateur ouvre une page : la nuit, le week-end ou sur un GLPI peu consulté, rien ne tourne. Les relevés deviennent irréguliers, les alertes arrivent en retard, et une commande non transmise aux Achats n\'est jamais signalée, sans qu\'aucun écran ne le montre.', 'printgestion')) . "</p>"
            . "<p class='mb-0'>" . $esc(__('En mode « CLI », le cron du serveur lance GLPI chaque minute (front/cron.php, avec l\'utilisateur du serveur web) et chaque action tourne à l\'heure prévue, que quelqu\'un navigue ou non. Les deux sont nécessaires : en mode CLI sans cron système, plus rien ne tourne du tout. Ce contrôle est vert quand aucune action active n\'est en mode GLPI et qu\'une action en mode CLI a tourné dans l\'heure.', 'printgestion')) . "</p>";

        echo "<div class='card mb-3' data-pg-admin='1' id='pg-config-health'><div class='card-header d-flex align-items-center'><h3 class='card-title mb-0'>"
            . $esc(__('Santé de la configuration', 'printgestion')) . "</h3><div class='ms-auto'>"
            . PluginPrintgestionUi::infoButton(__('Actions automatiques : mode CLI et cron système', 'printgestion'), $info) . "</div></div><div class='card-body'>";
        if ($complete) {
            echo PluginPrintgestionUi::statusLine('ok', __('Configuration : complète', 'printgestion'), $body);
        } else {
            if (!empty($failed)) {
                // Impossible à manquer, sans bloquer la page.
                // Ce qui casse, concrètement, d'après les lignes en défaut : lu tous les jours, chaque mot compte.
                $consequences = [
                    'app_url'       => __('la collecte ne fonctionnera pas', 'printgestion'),
                    'inventory'     => __('la collecte ne fonctionnera pas', 'printgestion'),
                    'glpiinventory' => __('la collecte ne fonctionnera pas', 'printgestion'),
                    'cron'          => __('rien ne partira de façon fiable', 'printgestion'),
                    'tag_rule'      => __('les équipements iront dans la mauvaise entité', 'printgestion'),
                    'xlsx'          => __('aucune commande ne partira', 'printgestion'),
                    'notifications' => __('aucune notification ne partira', 'printgestion'),
                ];
                $what = array_values(array_unique(array_filter(array_map(static fn(array $c) => $consequences[$c['key']] ?? '', $failed))));
                echo "<div class='alert alert-danger mb-2' role='alert'><i class='ti ti-alert-octagon me-1'></i><strong>" . $esc(sprintf(
                    _n('%1$d prérequis manquant : %2$s.', '%1$d prérequis manquants : %2$s.', count($failed), 'printgestion'),
                    count($failed),
                    implode(' ; ', $what)
                )) . "</strong> " . $esc(implode(', ', array_column($failed, 'label'))) . "</div>";
            }
            echo $body;
        }
        echo "</div></div>";
    }
}
