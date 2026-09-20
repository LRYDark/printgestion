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
    /** Tâche des deux suivis transporteur (GLS et MBE) : deux passages par jour lui suffisent, pas moins. */
    const TRACKING_TASK = 'PrintgestionTrackingUpdate';
    /** Au-delà, le suivi des colis décroche : une fréquence plus longue est signalée, jamais corrigée. */
    const TRACKING_MAX_FREQUENCY = 12 * HOUR_TIMESTAMP;
    /** Tâche dangereuse laissée volontairement désactivée tant que le flux d'export n'est pas validé. */
    const MANUAL_ENABLE_TASK = 'PrintgestionProposeDemandes';
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
            'breaks' => __('Aucune imprimante ne remonte : rien ne dit aux sondes quelles plages IP scanner.', 'printgestion'),
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
            'fix'    => __('Boutons de cette carte pour le mode et l\'état des tâches ; sur le serveur : cron système qui lance front/cron.php chaque minute, lui seul ne se règle pas d\'ici', 'printgestion'),
            'url'    => CronTask::getSearchURL(),
            'detail' => self::renderCronDetail($cron),
            'button' => self::getCronButtons($cron),
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
        // Suivi GLS : quatre lignes pour l'administrateur (clés, dernier appel réussi, échecs consécutifs, quota du jour).
        // Sans clés, rien ne manque : le plugin fonctionne sans suivi, et le technicien ne voit rien de tout cela.
        $gls_keys = PluginPrintgestionGlsclient::hasKeys();
        $gls      = PluginPrintgestionGlsclient::getMemo();
        $gls_date = (string) (PluginPrintgestionConfig::getInstance()->fields['gls_secret_date'] ?? '');
        if (!$gls_keys) {
            $gls_state  = self::STATE_OK;
            $gls_status = __('Clés non saisies : pas de suivi GLS, le plugin fonctionne sans.', 'printgestion');
        } elseif ($gls['failures'] >= PluginPrintgestionGlsclient::BREAKER_THRESHOLD) {
            $gls_state  = self::STATE_ERROR;
            $gls_status = sprintf(__('%d échecs techniques consécutifs : le suivi n\'avance plus (%s).', 'printgestion'), $gls['failures'], $gls['last_error'] !== '' ? $gls['last_error'] : __('sans détail', 'printgestion'));
        } elseif ($gls['last_success'] === '') {
            $gls_state  = self::STATE_PENDING;
            $gls_status = __('Clés saisies, aucun appel réussi encore : attendre le prochain passage de la tâche, ou « Tester la connexion ».', 'printgestion');
        } else {
            $gls_state  = self::STATE_OK;
            $gls_status = sprintf(__('Dernier appel réussi le %s.', 'printgestion'), Html::convDateTime($gls['last_success']));
        }
        $gls_lines = [
            $gls_keys
                ? ($gls_date !== '' ? sprintf(__('Clés : saisies, secret défini le %s.', 'printgestion'), Html::convDate($gls_date)) : __('Clés : saisies.', 'printgestion'))
                : __('Clés : non saisies.', 'printgestion'),
            $gls['last_success'] !== ''
                ? sprintf(__('Dernier appel réussi : %s.', 'printgestion'), Html::convDateTime($gls['last_success']))
                : __('Dernier appel réussi : jamais.', 'printgestion'),
            sprintf(__('Échecs consécutifs : %d%s.', 'printgestion'), $gls['failures'], $gls['last_error'] !== '' ? ' (' . $gls['last_error'] . ')' : ''),
            sprintf(__('Quota consommé aujourd\'hui : %1$d requête(s) sur %2$d, arrêt à %3$d%4$s.', 'printgestion'), $gls['quota_count'], PluginPrintgestionGlsclient::DAILY_QUOTA,
                (int) floor(PluginPrintgestionGlsclient::DAILY_QUOTA * PluginPrintgestionGlsclient::QUOTA_STOP_RATIO),
                $gls['quota_blocked'] ? ' — ' . __('quota dépassé (429) : plus d\'appel aujourd\'hui', 'printgestion') : ''),
        ];
        $checks[] = [
            'key'    => 'gls',
            'group'  => 'recommended',
            'label'  => __('Suivi des colis GLS', 'printgestion'),
            'state'  => $gls_state,
            'status' => $gls_status,
            'breaks' => __('Sans lui, une expédition GLS ne montre que le numéro saisi ; rien d\'autre ne change.', 'printgestion'),
            'fix'    => __('Carte « Suivi GLS » de cette page : Client ID et Client Secret, puis « Tester la connexion »', 'printgestion'),
            'url'    => '',
            'detail' => implode('<br>', array_map($esc, $gls_lines)),
        ];

        // MBE (intermédiaire de transport) : identifiants et dernier appel. Sans identifiants, rien ne manque. Un refus
        // d'identifiants (401/403) passe au rouge tout de suite : réessayer ne le soigne pas.
        $mbe_keys = PluginPrintgestionMbeclient::hasKeys();
        $mbe      = PluginPrintgestionMbeclient::getMemo();
        $mbe_date = (string) (PluginPrintgestionConfig::getInstance()->fields['mbe_secret_date'] ?? '');
        if (!$mbe_keys) {
            $mbe_state  = self::STATE_OK;
            $mbe_status = __('Identifiants non saisis : aucun appel MBE, le plugin fonctionne sans.', 'printgestion');
        } elseif ($mbe['last_kind'] === PluginPrintgestionCarrierexception::KIND_AUTH) {
            $mbe_state  = self::STATE_ERROR;
            $mbe_status = sprintf(__('Identifiants ou droits refusés par MBE (%s) : corriger l\'identifiant ou la passphrase, réessayer ne change rien.', 'printgestion'), $mbe['last_error']);
        } elseif ($mbe['failures'] >= PluginPrintgestionMbeclient::BREAKER_THRESHOLD) {
            $mbe_state  = self::STATE_ERROR;
            $mbe_status = sprintf(__('%d échecs consécutifs : MBE n\'est plus appelé (%s).', 'printgestion'), $mbe['failures'], $mbe['last_error'] !== '' ? $mbe['last_error'] : __('sans détail', 'printgestion'));
        } elseif ($mbe['last_success'] === '') {
            $mbe_state  = self::STATE_PENDING;
            $mbe_status = __('Identifiants saisis, aucun appel réussi encore : « Tester la connexion ».', 'printgestion');
        } else {
            $mbe_state  = self::STATE_OK;
            $mbe_status = sprintf(__('Dernier appel réussi le %s.', 'printgestion'), Html::convDateTime($mbe['last_success']));
        }
        $mbe_lines = [
            $mbe_keys
                ? ($mbe_date !== '' ? sprintf(__('Identifiants : saisis, passphrase définie le %s.', 'printgestion'), Html::convDate($mbe_date)) : __('Identifiants : saisis.', 'printgestion'))
                : __('Identifiants : non saisis.', 'printgestion'),
            $mbe['last_success'] !== ''
                ? sprintf(__('Dernier appel réussi : %s.', 'printgestion'), Html::convDateTime($mbe['last_success']))
                : __('Dernier appel réussi : jamais.', 'printgestion'),
            sprintf(__('Échecs consécutifs : %d%s.', 'printgestion'), $mbe['failures'], $mbe['last_error'] !== '' ? ' (' . $mbe['last_error'] . ')' : ''),
        ];
        $checks[] = [
            'key'    => 'mbe',
            'group'  => 'recommended',
            'label'  => __('Identifiants MBE (intermédiaire de transport)', 'printgestion'),
            'state'  => $mbe_state,
            'status' => $mbe_status,
            'breaks' => __('Sans eux, rien ne manque : cette version n\'appelle MBE que par « Tester la connexion ».', 'printgestion'),
            'fix'    => __('Carte « MBE » de cette page : identifiant et passphrase API, puis « Tester la connexion »', 'printgestion'),
            'url'    => '',
            'detail' => implode('<br>', array_map($esc, $mbe_lines)),
        ];

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
     * fenêtre, ou déclaré par GLPI_SYSTEM_CRON. Le plugin ne corrige rien tout seul, et ne groupe rien : chaque
     * correction a son bouton (passer en CLI, activer, débloquer, déclarer le cron système, activer la proposition
     * automatique), un clic explicite et confirmé, pour n'appliquer que ce qui a été décidé — passer en CLI sans
     * activer, activer sans passer en CLI, et ainsi de suite.
     *
     * @return array ['ok', 'status', 'proven', 'seen', 'system_cron_declared', 'witness' => ?row, 'tasks' => rows,
     *               'propose' => ?row (tâche à décision métier), 'internal' => actives en mode Interne,
     *               'not_cli' => tout ce qu'un clic peut passer en CLI, 'inactive' => désactivées à réactiver,
     *               'blocked' => coincées « en cours d'exécution », 'local_define' => état de config/local_define.php,
     *               'queue' => ?row]
     */
    public static function getCronStatus(): array {
        global $DB;

        // Tâches coincées « en cours d'exécution » : GLPI sait les repérer, le plugin n'en reprend que les siennes.
        $zombie_ids = [];
        foreach (CronTask::getZombieCronTasks() as $row) {
            if (str_starts_with((string) $row['itemtype'], 'PluginPrintgestion')) {
                $zombie_ids[(int) $row['id']] = true;
            }
        }

        $tasks    = [];
        $witness  = null;
        $propose  = null;
        $internal = [];
        $not_cli  = [];
        $inactive = [];
        $blocked  = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'itemtype', 'state', 'mode', 'allowmode', 'frequency', 'lastrun'],
            'FROM'   => CronTask::getTable(),
            'WHERE'  => ['itemtype' => ['LIKE', 'PluginPrintgestion%']],
            'ORDER'  => ['name'],
        ]) as $row) {
            $info = is_callable([$row['itemtype'], 'cronInfo']) ? (array) call_user_func([$row['itemtype'], 'cronInfo'], $row['name']) : [];
            $row += ['description' => (string) ($info['description'] ?? $row['name'])];
            // La tâche à décision métier (proposition automatique de demandes) a son propre bouton : elle ne compte
            // ni dans les désactivées à réactiver, ni dans les débloquables.
            $manual = $row['name'] === self::MANUAL_ENABLE_TASK;
            if ((int) $row['mode'] === CronTask::MODE_INTERNAL && (int) $row['state'] !== CronTask::STATE_DISABLE) {
                $internal[] = $row['description'];
            }
            // Ce qu'un clic peut passer en CLI : une tâche désactivée comprise (son mode comptera le jour où elle
            // sera activée), jamais une tâche qui n'accepte pas le mode CLI (allowmode) — le témoin, par exemple.
            if (
                (int) $row['mode'] !== CronTask::MODE_EXTERNAL
                && ((int) $row['allowmode'] & CronTask::MODE_EXTERNAL) !== 0
            ) {
                $not_cli[] = $row['description'];
            }
            if (!$manual && (int) $row['state'] === CronTask::STATE_DISABLE) {
                $inactive[] = $row['description'];
            }
            if (!$manual && isset($zombie_ids[(int) $row['id']])) {
                $blocked[] = $row['description'];
            }
            if ($manual) {
                $propose = $row;
            }
            if ($row['name'] === self::WITNESS_TASK) {
                $witness = $row;
                continue;
            }
            $tasks[] = $row;
        }
        $queue    = $DB->request(['FROM' => CronTask::getTable(), 'WHERE' => ['name' => 'queuednotification']])->current() ?: null;
        $declared = defined('GLPI_SYSTEM_CRON') && GLPI_SYSTEM_CRON;
        $seen     = $witness !== null && !empty($witness['lastrun'])
            && strtotime((string) $witness['lastrun']) >= strtotime(Session::getCurrentTime()) - self::WITNESS_WINDOW;
        $proven   = $declared || $seen;

        // L'état se lit d'un trait : le cron système d'abord, puis ce qui reste à corriger d'ici, correction par
        // correction — une phrase par bouton. Et quand tout est réglé ici alors que le cron du serveur manque
        // encore, la ligne le dit en toutes lettres : plus aucun clic de cette carte ne la fera passer au vert.
        $parts = [];
        if (!$proven) {
            $parts[] = $witness === null
                ? __('Tâche témoin absente : relancer la mise à jour du plugin (Configuration → Plugins).', 'printgestion')
                : (empty($witness['lastrun'])
                    ? __('Aucun cron système détecté : la tâche témoin (mode CLI, chaque minute) n\'a jamais tourné. Rien ne partira de façon fiable.', 'printgestion')
                    : sprintf(__('Aucun cron système détecté : la tâche témoin n\'a pas tourné depuis le %s. Rien ne partira de façon fiable.', 'printgestion'), Html::convDateTime((string) $witness['lastrun'])));
        }
        if (!empty($internal)) {
            // Comptées ici : les tâches ACTIVES en mode Interne, celles qui cassent. Le bouton, lui, annonce son propre
            // compte : il bascule aussi les désactivées, dont le mode comptera le jour où elles seront activées. Deux
            // nombres différents parce qu'ils mesurent deux choses différentes, et chacun dit laquelle.
            $parts[] = sprintf(_n(
                'Mode Interne — rien ne partira de façon fiable. %d tâche active du plugin est en mode Interne.',
                'Mode Interne — rien ne partira de façon fiable. %d tâches actives du plugin sont en mode Interne.',
                count($internal),
                'printgestion'
            ), count($internal));
        }
        if (!empty($inactive)) {
            $parts[] = sprintf(_n(
                '%d tâche de Print Gestion est désactivée et doit être réactivée.',
                '%d tâches de Print Gestion sont désactivées et doivent être réactivées.',
                count($inactive),
                'printgestion'
            ), count($inactive));
        }
        if (!empty($blocked)) {
            $parts[] = sprintf(_n(
                '%d tâche de Print Gestion est coincée « en cours d\'exécution » et doit être débloquée.',
                '%d tâches de Print Gestion sont coincées « en cours d\'exécution » et doivent être débloquées.',
                count($blocked),
                'printgestion'
            ), count($blocked));
        }
        if (!$proven && empty($internal) && empty($inactive) && empty($blocked)) {
            $parts[] = __('Rien à corriger d\'ici : plus aucune tâche active du plugin en mode Interne, plus aucune désactivée ni bloquée. Il ne manque que le cron du serveur, qu\'aucun bouton de cette carte ne peut créer.', 'printgestion');
        }
        if ($parts === []) {
            $parts[] = sprintf(__('Cron système actif (%1$s), les %2$d tâches du plugin en CLI.', 'printgestion'),
                $declared && !$seen ? __('déclaré par GLPI_SYSTEM_CRON', 'printgestion') : sprintf(__('témoin passé le %s', 'printgestion'), Html::convDateTime((string) $witness['lastrun'])),
                count($tasks));
        }
        return [
            'ok'                   => $proven && empty($internal) && empty($inactive) && empty($blocked),
            'status'               => implode(' ', $parts),
            'proven'               => $proven,
            'seen'                 => $seen,
            'system_cron_declared' => $declared,
            'witness'              => $witness,
            'tasks'                => $tasks,
            'propose'              => $propose,
            'internal'             => $internal,
            'not_cli'              => $not_cli,
            'inactive'             => $inactive,
            'blocked'              => $blocked,
            'local_define'         => self::getLocalDefineStatus(),
            'queue'                => $queue,
        ];
    }

    /**
     * Fichier des constantes locales de GLPI (config/local_define.php, chargé au démarrage) : où il est, s'il existe,
     * si le serveur web peut l'écrire. Sans droit d'écriture, la déclaration du cron système se fait à la main et le
     * bouton n'est pas proposé : un bouton qui ne peut pas aboutir vaut moins que la ligne à recopier.
     *
     * @return array ['path' => string, 'exists' => bool, 'writable' => bool]
     */
    public static function getLocalDefineStatus(): array {
        $path = GLPI_CONFIG_DIR . '/local_define.php';
        return [
            'path'     => $path,
            'exists'   => file_exists($path),
            'writable' => file_exists($path) ? is_writable($path) : is_writable(GLPI_CONFIG_DIR),
        ];
    }

    /**
     * Détail du contrôle cron : GLPI_SYSTEM_CRON, les tâches du plugin une par une, le témoin, la file des
     * notifications, et — tant que le témoin n'est pas passé — la ligne exacte à installer sur le serveur, écrite en
     * clair pour être copiée. C'est le seul point de la carte qu'aucun bouton ne peut corriger.
     */
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
        // Les deux suivis transporteur demandent deux passages par jour : une fréquence plus longue est signalée là où
        // la tâche est affichée. La fréquence appartient à GLPI, le plugin ne la corrige pas. Plus court ne sert à
        // rien et ne gêne pas : MBE garde son propre délai minimal entre deux passages, GLS son quota.
        foreach ($cron['tasks'] as $row) {
            if ($row['name'] === self::TRACKING_TASK && (int) $row['frequency'] > self::TRACKING_MAX_FREQUENCY) {
                $html .= "<p class='text-warning small mb-0 mt-2'><i class='ti ti-alert-triangle me-1'></i>" . $esc(sprintf(
                    __('Le suivi des colis demande deux passages par jour (toutes les 12 heures) : la fréquence réglée dans GLPI (%s) est plus longue. Elle se règle dans la fiche de la tâche, le plugin ne la corrige pas.', 'printgestion'),
                    self::formatFrequency((int) $row['frequency'])
                )) . "</p>";
            }
        }
        if (!$cron['seen']) {
            $html .= "<p class='small mb-0 mt-2'>" . $esc(__('Cron du serveur à installer une fois, avec l\'utilisateur du serveur web :', 'printgestion'))
                . " <code>" . $esc('* * * * * php ' . GLPI_ROOT . '/front/cron.php') . "</code></p>";
            if (!$cron['system_cron_declared'] && !$cron['local_define']['writable']) {
                $html .= "<p class='small mb-0 mt-2'>" . $esc(sprintf(
                    __('%1$s n\'est pas inscriptible par le serveur web : la déclaration du cron système se fait à la main, en y ajoutant la ligne %2$s.', 'printgestion'),
                    $cron['local_define']['path'],
                    "define('GLPI_SYSTEM_CRON', true);"
                )) . "</p>";
            }
        }
        return $html;
    }

    /**
     * Boutons de la ligne « actions automatiques » : une correction par bouton, chacun affiché seulement s'il a
     * quelque chose à corriger, chacun confirmé, chacun disant ce qu'il ne touche pas. Rien n'est groupé — passer en
     * CLI sans activer, activer sans passer en CLI, débloquer seul, déclarer le cron système seul : l'administrateur
     * choisit son réglage. Les tâches de GLPI et des autres plugins ne sont jamais modifiées.
     */
    private static function getCronButtons(array $cron): string {
        global $CFG_GLPI;

        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        // Ces actions ne soumettent pas le grand formulaire de configuration : son HTML historique est reconstruit
        // autour de tableaux et certains navigateurs dissocient le jeton CSRF placé tout en bas du bouton.
        // Le JS crée un petit POST autonome avec le jeton CSRF standalone de l'en-tête GLPI.
        $action = $CFG_GLPI['root_doc'] . '/plugins/printgestion/front/config.form.php';
        $make   = static fn(string $name, string $icon, string $label, string $confirm, string $class): string =>
            // Pas de data-pg-submit-once : ce bouton ne soumet pas le formulaire (POST autonome construit en JS),
            // et le gestionnaire de ce POST le désactive déjà lui-même.
            "<button type='button' data-pg-post-action='" . $esc($name) . "' data-pg-post-url='" . $esc($action)
            . "' class='btn " . $esc($class) . "'"
            . " onclick=\"return confirm(" . $esc(json_encode($confirm)) . ");\">"
            . "<i class='ti " . $esc($icon) . " me-1'></i>" . $esc($label) . "</button>";

        $buttons = [];
        if (!empty($cron['not_cli'])) {
            $count     = count($cron['not_cli']);
            $buttons[] = $make('cron_switch_cli', 'ti-terminal-2',
                sprintf(_n('Passer la tâche en mode CLI', 'Passer les %d tâches en mode CLI', $count, 'printgestion'), $count),
                $cron['proven']
                    ? sprintf(_n('Passer %d tâche de Print Gestion en mode CLI ? Son état (active ou désactivée) n\'est pas touché : ce bouton ne change que le mode d\'exécution. Les tâches de GLPI et des autres plugins ne sont jamais modifiées.',
                        'Passer %d tâches de Print Gestion en mode CLI ? Leur état (active ou désactivée) n\'est pas touché : ce bouton ne change que le mode d\'exécution. Les tâches de GLPI et des autres plugins ne sont jamais modifiées.',
                        $count, 'printgestion'), $count)
                    : __('Aucun cron système n\'est prouvé : en mode CLI, ces tâches ne tourneront plus du tout tant que le cron du serveur (front/cron.php chaque minute) n\'est pas en place. Continuer ? Leur état (active ou désactivée) n\'est pas touché, et les tâches de GLPI comme celles des autres plugins ne sont jamais modifiées.', 'printgestion'),
                $cron['proven'] ? 'btn-primary' : 'btn-outline-primary');
        }
        if (!empty($cron['inactive'])) {
            $count     = count($cron['inactive']);
            $buttons[] = $make('cron_enable_tasks', 'ti-player-play',
                sprintf(_n('Activer la tâche désactivée', 'Activer les %d tâches désactivées', $count, 'printgestion'), $count),
                sprintf(_n('Activer %d tâche désactivée de Print Gestion ? Son mode d\'exécution n\'est pas touché : ce bouton ne change que l\'état. La proposition automatique de demandes d\'envoi a son propre bouton et n\'est pas activée ici.',
                    'Activer %d tâches désactivées de Print Gestion ? Leur mode d\'exécution n\'est pas touché : ce bouton ne change que l\'état. La proposition automatique de demandes d\'envoi a son propre bouton et n\'est pas activée ici.',
                    $count, 'printgestion'), $count),
                'btn-primary');
        }
        if (!empty($cron['blocked'])) {
            $count     = count($cron['blocked']);
            $buttons[] = $make('cron_unblock_tasks', 'ti-lock-open',
                sprintf(_n('Débloquer la tâche bloquée', 'Débloquer les %d tâches bloquées', $count, 'printgestion'), $count),
                sprintf(_n('Remettre en attente %d tâche de Print Gestion coincée « en cours d\'exécution » ? Un passage interrompu la laisse dans cet état et rien ne repart. Ni son mode ni sa fréquence ne sont touchés.',
                    'Remettre en attente %d tâches de Print Gestion coincées « en cours d\'exécution » ? Un passage interrompu les laisse dans cet état et rien ne repart. Ni leur mode ni leur fréquence ne sont touchés.',
                    $count, 'printgestion'), $count),
                'btn-outline-primary');
        }
        // Déclaration du cron système : réglage natif de GLPI, écrit dans son fichier de constantes locales. Proposé
        // seulement s'il manque, si le fichier est inscriptible et à qui a le droit de configuration de GLPI. Il dit
        // à GLPI qu'un cron système existe, il ne le crée pas : la confirmation le dit sans détour.
        if (
            !$cron['system_cron_declared'] && !$cron['seen']
            && $cron['local_define']['writable'] && Session::haveRight('config', UPDATE)
        ) {
            $buttons[] = $make('cron_declare_system', 'ti-server-cog',
                __('Déclarer le cron système (GLPI_SYSTEM_CRON)', 'printgestion'),
                sprintf(__('Écrire define(\'GLPI_SYSTEM_CRON\', true); dans %s ? À ne déclarer QUE si le cron du serveur lance bien front/cron.php chaque minute : ce réglage dit à GLPI qu\'un cron système existe, il ne le crée pas. Déclaré à tort, ce contrôle passera au vert alors que rien ne tournera, et plus rien ne le signalera. Le fichier n\'est pas réécrit : la ligne est remplacée si elle existe, ajoutée sinon, après une copie horodatée.', 'printgestion'), $cron['local_define']['path']),
                'btn-outline-danger');
        }
        // La proposition automatique des demandes d'envoi n'a pas de bouton ici : ce n'est pas une correction, c'est
        // un choix de fonctionnement. Son interrupteur est dans les réglages, carte « Proposition automatique des
        // demandes d'envoi ». Elle reste affichée dans le tableau ci-dessous, en lecture, comme les autres tâches.
        return $buttons === [] ? '' : "<div class='d-flex flex-wrap gap-2'>" . implode('', $buttons) . "</div>";
    }

    /**
     * Passe en mode CLI les tâches du plugin qui l'acceptent (allowmode), sans toucher à leur état : « passer en
     * CLI » et « activer » sont deux décisions. Les tâches de GLPI et des autres plugins ne sont jamais modifiées.
     *
     * @return int nombre de tâches passées en mode CLI
     */
    public static function switchTasksToCli(): int {
        global $DB;

        $done = 0;
        foreach ($DB->request([
            'SELECT' => ['id', 'mode', 'allowmode'],
            'FROM'   => CronTask::getTable(),
            'WHERE'  => ['itemtype' => ['LIKE', 'PluginPrintgestion%']],
        ]) as $row) {
            if (
                (int) $row['mode'] !== CronTask::MODE_EXTERNAL
                && ((int) $row['allowmode'] & CronTask::MODE_EXTERNAL) !== 0
            ) {
                $DB->update(CronTask::getTable(), ['mode' => CronTask::MODE_EXTERNAL], ['id' => (int) $row['id']]);
                $done++;
            }
        }
        return $done;
    }

    /**
     * Réactive les tâches désactivées du plugin, sans toucher à leur mode d'exécution. La proposition automatique de
     * demandes d'envoi a son propre bouton : elle n'est jamais activée ici.
     *
     * @return int nombre de tâches réactivées
     */
    public static function enableTasks(): int {
        global $DB;

        $done = 0;
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => CronTask::getTable(),
            'WHERE'  => [
                'itemtype' => ['LIKE', 'PluginPrintgestion%'],
                'state'    => CronTask::STATE_DISABLE,
                'NOT'      => ['name' => self::MANUAL_ENABLE_TASK],
            ],
        ]) as $row) {
            $DB->update(CronTask::getTable(), ['state' => CronTask::STATE_WAITING], ['id' => (int) $row['id']]);
            $done++;
        }
        return $done;
    }

    /**
     * Remet en attente les tâches du plugin coincées « en cours d'exécution » : un passage interrompu les y laisse et
     * rien ne repart. Le repérage est celui de GLPI (CronTask::getZombieCronTasks), limité aux tâches du plugin, la
     * proposition automatique de demandes exceptée.
     *
     * @return int nombre de tâches débloquées
     */
    public static function unblockTasks(): int {
        global $DB;

        $done = 0;
        foreach (CronTask::getZombieCronTasks() as $row) {
            if (
                str_starts_with((string) $row['itemtype'], 'PluginPrintgestion')
                && $row['name'] !== self::MANUAL_ENABLE_TASK
            ) {
                $DB->update(CronTask::getTable(), ['state' => CronTask::STATE_WAITING], ['id' => (int) $row['id']]);
                $done++;
            }
        }
        return $done;
    }

    /**
     * État de la seule tâche à décision métier, lu tout seul : la carte de réglage en a besoin sans avoir à
     * recalculer tout l'état du cron.
     *
     * @return array|null ['id' => int, 'state' => int, 'lastrun' => ?string], null si elle n'est pas enregistrée
     */
    public static function getProposeTask(): ?array {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'state', 'lastrun'],
            'FROM'   => CronTask::getTable(),
            'WHERE'  => ['name' => self::MANUAL_ENABLE_TASK],
        ])->current();
        return is_array($row) ? $row : null;
    }

    /**
     * Allume ou coupe la seule tâche à décision métier : la proposition automatique de demandes d'envoi. Jamais
     * touchée par un autre bouton ni par une mise à jour du plugin — à chaque passage, elle crée des demandes sans
     * que personne les demande, et chaque ligne proposée bloque la commande de sa cartouche jusqu'à son export ou
     * son annulation.
     *
     * Couper n'efface rien : les demandes déjà proposées restent, et gardent leur verrou.
     *
     * @param bool $enabled vrai pour allumer, faux pour couper
     * @return bool vrai si l'état a changé
     */
    public static function setProposeTask(bool $enabled): bool {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'state'],
            'FROM'   => CronTask::getTable(),
            'WHERE'  => ['name' => self::MANUAL_ENABLE_TASK],
        ])->current();
        if (!is_array($row)) {
            return false;
        }
        $wanted = $enabled ? CronTask::STATE_WAITING : CronTask::STATE_DISABLE;
        // Une tâche en cours d'exécution qu'on rallume repasse en attente : c'est le même état cible.
        if ((int) $row['state'] === $wanted) {
            return false;
        }
        $DB->update(CronTask::getTable(), ['state' => $wanted], ['id' => (int) $row['id']]);
        return true;
    }

    /**
     * Déclare le cron système dans config/local_define.php : define('GLPI_SYSTEM_CRON', true). Ce réglage dit à GLPI
     * qu'un cron système existe, il ne le crée pas — déclaré à tort, le contrôle passe au vert alors que rien ne
     * tourne, et le témoin reste la seule preuve par le réel. Le fichier n'est jamais réécrit en entier : la ligne
     * est remplacée si elle existe, ajoutée sinon, et une copie horodatée est faite avant toute modification.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function declareSystemCron(): array {
        $line = "define('GLPI_SYSTEM_CRON', true);";
        if (defined('GLPI_SYSTEM_CRON') && GLPI_SYSTEM_CRON) {
            return ['ok' => true, 'message' => __('Le cron système est déjà déclaré (GLPI_SYSTEM_CRON).', 'printgestion')];
        }
        $state = self::getLocalDefineStatus();
        if (!$state['writable']) {
            return ['ok' => false, 'message' => sprintf(__('%1$s n\'est pas inscriptible par le serveur web : ajouter la ligne %2$s à la main.', 'printgestion'), $state['path'], $line)];
        }
        $stamp  = '// ' . sprintf(__('Ajouté par Print Gestion le %s : un cron système lance front/cron.php sur ce serveur.', 'printgestion'), Session::getCurrentTime());
        $regexp = '/^[ \t]*(?:\/\/[ \t]*)?define\s*\(\s*([\'"])GLPI_SYSTEM_CRON\1.*$/m';
        if (!$state['exists']) {
            $content = "<?php\n\n{$stamp}\n{$line}\n";
        } else {
            $content = (string) file_get_contents($state['path']);
            $backup  = $state['path'] . '.bak-' . date('YmdHis');
            if (!copy($state['path'], $backup)) {
                return ['ok' => false, 'message' => sprintf(__('Copie de sauvegarde de %s impossible : rien n\'a été modifié.', 'printgestion'), $state['path'])];
            }
            if (preg_match($regexp, $content) === 1) {
                $content = (string) preg_replace($regexp, $line, $content, 1);
            } elseif (($close = strrpos($content, '?>')) !== false) {
                // Fichier terminé par une balise de fermeture : la ligne s'insère avant, jamais après.
                $content = substr($content, 0, $close) . "{$stamp}\n{$line}\n" . substr($content, $close);
            } else {
                $content = rtrim($content, " \t\n\r") . "\n\n{$stamp}\n{$line}\n";
            }
        }
        if (file_put_contents($state['path'], $content) === false) {
            return ['ok' => false, 'message' => sprintf(__('Écriture de %s impossible : rien n\'a été modifié.', 'printgestion'), $state['path'])];
        }
        // Le fichier est chargé au démarrage de GLPI : la constante ne changera qu'à la requête suivante (celle du
        // retour). Sans invalidation, un cache d'opcode peut servir l'ancienne version plus longtemps.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($state['path'], true);
        }
        return ['ok' => true, 'message' => sprintf(__('Cron système déclaré dans %s. Le témoin reste la seule preuve qu\'il tourne vraiment : s\'il ne passe pas dans les prochaines minutes, le cron du serveur n\'appelle pas front/cron.php.', 'printgestion'), $state['path'])];
    }

    /** Carte en tête de la configuration, dans le formulaire de la page (boutons envoyés à front/config.form.php). */
    public static function showCard(bool $canedit): void {
        if (!PluginPrintgestionUi::isAdmin()) {
            return;
        }
        $esc      = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $checks   = self::getChecks();
        $failed   = array_filter($checks, static fn(array $c) => $c['group'] === 'required' && $c['state'] === self::STATE_ERROR);
        // Le détail est toujours replié derrière un chevron (demande de Joris : la carte est trop longue). Ce qui doit
        // être vu l'est sans clic : la bannière rouge si un obligatoire manque, et une ligne qui compte ce qui reste à
        // corriger, à confirmer par le réel ou à acquitter. Tout vert : « Configuration : complète ».
        $counts = [self::STATE_ERROR => 0, self::STATE_PENDING => 0, self::STATE_MANUAL => 0];
        foreach ($checks as $check) {
            if (isset($counts[$check['state']])) {
                $counts[$check['state']]++;
            }
        }
        $complete = array_sum($counts) === 0;

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
                // Boutons d'action : ils ne sont remplis que quand il y a quelque chose à corriger ou à
                // acquitter, et la ligne des actions automatiques en propose un par correction.
                if (($check['button'] ?? '') !== '' && $canedit) {
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
            $parts = [];
            if ($counts[self::STATE_ERROR] > 0) {
                $parts[] = sprintf(_n('%d à corriger', '%d à corriger', $counts[self::STATE_ERROR], 'printgestion'), $counts[self::STATE_ERROR]);
            }
            if ($counts[self::STATE_PENDING] > 0) {
                $parts[] = sprintf(_n('%d jamais confirmé par le réel', '%d jamais confirmés par le réel', $counts[self::STATE_PENDING], 'printgestion'), $counts[self::STATE_PENDING]);
            }
            if ($counts[self::STATE_MANUAL] > 0) {
                $parts[] = sprintf(_n('%d à acquitter', '%d à acquitter', $counts[self::STATE_MANUAL], 'printgestion'), $counts[self::STATE_MANUAL]);
            }
            $total = array_sum($counts);
            echo PluginPrintgestionUi::statusLine(
                $counts[self::STATE_ERROR] > 0 ? 'error' : 'warning',
                sprintf(_n('%1$d point à voir : %2$s', '%1$d points à voir : %2$s', $total, 'printgestion'), $total, implode(', ', $parts)),
                $body
            );
        }
        echo "</div></div>";
    }
}
