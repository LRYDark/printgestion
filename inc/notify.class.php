<?php
/**
 * PluginPrintgestionNotify — les mails du plugin sont des notifications natives de GLPI.
 *
 * Chaque circuit (alerte toner, commande aux Achats, planification, courtoisie client, suivi de colis, rappel
 * d'installation) est un événement d'un objet du plugin : sa notification, son gabarit et ses destinataires se
 * règlent dans Configuration → Notifications, comme pour tout GLPI (NotificationTarget, NotificationTemplate,
 * QueuedNotification). Ce que le plugin garde, parce que ses écrans en dépendent :
 *   - ses rôles (Planification, Achats, Commercial), proposés comme destinataires « Rôle … (Print Gestion) » et
 *     résolus au moment de l'envoi depuis Configuration → Print Gestion → Rôles & notifications ;
 *   - l'envoi immédiat, avec un résultat connu de l'appelant : les messages passent par la file d'attente native,
 *     partent tout de suite (getEventsToSendImmediately()) et raise() relit la file juste après ;
 *   - le renvoi à la main : un message que GLPI n'a pas pu remettre est retiré de la file — elle le renverrait
 *     d'elle-même plus tard, et une commande partirait deux fois — et l'appelant garde sa commande « non
 *     transmise », son alerte « non envoyée », son rappel pour le prochain passage ;
 *   - la pièce jointe par les documents natifs : le fichier Gesconso archivé est un Document rattaché à la
 *     commande, la notification l'attache (attach_documents) ;
 *   - le regroupement par passage (alerte toner, rappel) : un événement sur un objet vide, la liste en option,
 *     comme les alertes de cartouches de GLPI (NotificationTargetCartridgeItem).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionNotify {

    /** Destinataires spécifiques du plugin (Notification::USER_TYPE) : identifiants réservés au plugin. */
    const ROLE_PLANIF     = 7311;
    const ROLE_ACHAT      = 7312;
    const ROLE_COMMERCIAL = 7313;
    const PRINTER_USER    = 7314;

    const ROLES = [self::ROLE_PLANIF => 'planif', self::ROLE_ACHAT => 'achat', self::ROLE_COMMERCIAL => 'commercial'];

    const COMMENT = 'Created by plugin printgestion';

    /** @var array<int, string[]> langues des gabarits d'une notification, par identifiant de notification */
    private static array $languages = [];

    /** Gabarit (champ de configuration) → objet dont il notifie les événements. */
    public static function getTemplateTypes(): array {
        return [
            'gabarit_planif'       => PluginPrintgestionPurchaseorder::class,
            'gabarit_planif_group' => PluginPrintgestionPurchaseorder::class,
            'gabarit_achat'        => PluginPrintgestionPurchaseorder::class,
            'gabarit_courtoisie'   => PluginPrintgestionPurchaseorder::class,
            'gabarit_commercial'   => PluginPrintgestionAlert::class,
            'gabarit_suivi'        => PluginPrintgestionExpedition::class,
            'gabarit_rappel'       => PluginPrintgestionExpedition::class,
        ];
    }

    /**
     * Les circuits : événement → objet, nom de la notification, gabarits par ordre de préférence (le premier
     * choisi dans la configuration), destinataires posés à la création, pièces jointes. Un circuit était actif
     * quand son gabarit était choisi : la notification est créée dans le même état.
     */
    public static function getCircuits(): array {
        $author = PluginPrintgestionNotificationTargetPurchaseorder::AUTHOR;
        return [
            'toner_alert' => [
                'itemtype' => PluginPrintgestionAlert::class,
                'name'     => 'Print Gestion - Alerte toner (commercial)',
                'label'    => __('Alerte toner : information commerciale, un mail par passage de la tâche', 'printgestion'),
                'gabarits' => ['gabarit_commercial'],
                'targets'  => [self::ROLE_COMMERCIAL],
                'attach'   => NotificationSetting::ATTACH_NO_DOCUMENT,
            ],
            'purchaseorder_sent' => [
                'itemtype' => PluginPrintgestionPurchaseorder::class,
                'name'     => 'Print Gestion - Commande aux Achats',
                'label'    => __('Commande aux Achats, fichier Gesconso joint, auteur en copie', 'printgestion'),
                'gabarits' => ['gabarit_achat'],
                'targets'  => [self::ROLE_ACHAT, $author],
                'attach'   => NotificationSetting::ATTACH_ALL_DOCUMENTS,
                'always'   => true,
            ],
            'purchaseorder_planif' => [
                'itemtype' => PluginPrintgestionPurchaseorder::class,
                'name'     => 'Print Gestion - Cartouche à expédier (planification)',
                'label'    => __('Cartouche à expédier : planification, auteur en copie (une seule cartouche)', 'printgestion'),
                'gabarits' => ['gabarit_planif'],
                'targets'  => [self::ROLE_PLANIF, $author],
                'attach'   => NotificationSetting::ATTACH_NO_DOCUMENT,
            ],
            'purchaseorder_planif_group' => [
                'itemtype' => PluginPrintgestionPurchaseorder::class,
                'name'     => 'Print Gestion - Cartouches à expédier, envoi groupé (planification)',
                'label'    => __('Cartouches à expédier : planification, auteur en copie, fichier Gesconso joint (plusieurs cartouches)', 'printgestion'),
                'gabarits' => ['gabarit_planif_group', 'gabarit_planif'],
                'targets'  => [self::ROLE_PLANIF, $author],
                'attach'   => NotificationSetting::ATTACH_ALL_DOCUMENTS,
            ],
            'purchaseorder_courtesy' => [
                'itemtype' => PluginPrintgestionPurchaseorder::class,
                'name'     => 'Print Gestion - Courtoisie client',
                'label'    => __('Courtoisie client : cartouche(s) en cours d\'envoi, à l\'usager de chaque imprimante', 'printgestion'),
                'gabarits' => ['gabarit_courtoisie'],
                'targets'  => [self::PRINTER_USER],
                'attach'   => NotificationSetting::ATTACH_NO_DOCUMENT,
            ],
            'expedition_shipped' => [
                'itemtype'    => PluginPrintgestionExpedition::class,
                'name'        => 'Print Gestion - Suivi de colis (commercial)',
                'label'       => __('Expédition partie : transporteur et numéro de suivi au commercial', 'printgestion'),
                'gabarits'    => ['gabarit_suivi'],
                'active_from' => 'gabarit_commercial',
                'targets'     => [self::ROLE_COMMERCIAL],
                'attach'      => NotificationSetting::ATTACH_NO_DOCUMENT,
            ],
            'expedition_reminder' => [
                'itemtype' => PluginPrintgestionExpedition::class,
                'name'     => 'Print Gestion - Rappel installation cartouche',
                'label'    => __('Rappel : cartouches expédiées non installées, un mail par passage de la tâche', 'printgestion'),
                'gabarits' => ['gabarit_rappel'],
                'targets'  => 'reminder',
                'attach'   => NotificationSetting::ATTACH_NO_DOCUMENT,
            ],
        ];
    }

    /** Libellé d'un circuit, pour les messages. */
    public static function getLabel(string $event): string {
        return (string) (self::getCircuits()[$event]['label'] ?? $event);
    }

    /** Vrai si la notification de ce circuit existe et est active. */
    public static function isActive(string $itemtype, string $event): bool {
        self::ensureInstalled();
        return countElementsInTable('glpi_notifications', ['itemtype' => $itemtype, 'event' => $event, 'is_active' => 1]) > 0;
    }

    /** @var bool|null vérification faite pour cette requête */
    private static ?bool $installed = null;

    /**
     * Les notifications des circuits sont créées par « Mettre à jour » (plugin_printgestion_install()). Si le plugin
     * a été mis à jour par copie des fichiers sans passer par l'écran des plugins, aucune n'existe encore : même
     * geste à la première utilisation, une fois, idempotent — une commande ne doit pas partir en « non transmise »
     * faute de notification. Une notification supprimée ensuite par l'administrateur n'est pas recréée ici
     * (seulement par « Mettre à jour ») : pour couper un circuit, la désactiver.
     */
    private static function ensureInstalled(): void {
        if (self::$installed !== null) {
            return;
        }
        self::$installed = true;
        try {
            if (countElementsInTable('glpi_notifications', ['comment' => self::COMMENT, 'event' => array_keys(self::getCircuits())]) > 0) {
                return;
            }
            self::ensureSchema();
            if (function_exists('plugin_printgestion_create_templates')) {
                plugin_printgestion_create_templates();
            }
            self::install();
            PluginPrintgestionLogger::info('notifications', 'Notifications natives des circuits mail créées à la première utilisation (plugin mis à jour sans « Mettre à jour »).');
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('notifications', 'Notifications natives des circuits mail non créées.', $e);
        }
    }

    // ── Destinataires ─────────────────────────────────────────────────────────

    /** Les trois rôles du plugin, proposés comme destinataires de la notification. */
    public static function addRoleTargets(NotificationTarget $target): void {
        $target->addTarget(self::ROLE_PLANIF, __('Rôle Planification (Print Gestion)', 'printgestion'));
        $target->addTarget(self::ROLE_ACHAT, __('Rôle Achats (Print Gestion)', 'printgestion'));
        $target->addTarget(self::ROLE_COMMERCIAL, __('Rôle Commercial (Print Gestion)', 'printgestion'));
    }

    /**
     * Destinataires d'une cible spécifique du plugin : les utilisateurs d'un rôle, ou les usagers des imprimantes
     * passés en option (courtoisie : $options['recipients']).
     */
    public static function addSpecificRecipients(NotificationTarget $target, array $data, array $options): void {
        $items_id         = (int) ($data['items_id'] ?? 0);
        $notifications_id = (int) ($data['notifications_id'] ?? 0);
        if (isset(self::ROLES[$items_id])) {
            foreach (PluginPrintgestionAlert::resolveUsersForRole(self::ROLES[$items_id]) as $user) {
                self::addUser($target, $user, $notifications_id);
            }
            return;
        }
        if ($items_id === self::PRINTER_USER) {
            foreach ((array) ($options['recipients'] ?? []) as $user) {
                self::addUser($target, (array) $user, $notifications_id);
            }
        }
    }

    /**
     * Ajoute un utilisateur GLPI par son adresse, au type « utilisateur GLPI » (liens vers GLPI, pièces jointes),
     * sans le filtre d'entité que GLPI applique aux destinataires donnés par identifiant : les rôles du plugin sont
     * une liste d'adresses réglée par l'administrateur, comme avant.
     */
    public static function addUser(NotificationTarget $target, array $user, int $notifications_id): void {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') {
            return;
        }
        $target->addToRecipientsList([
            'email'    => $email,
            'name'     => (string) ($user['name'] ?? ''),
            'language' => self::pickLanguage($notifications_id, (string) ($user['language'] ?? '')),
            'usertype' => NotificationTarget::GLPI_USER,
        ]);
    }

    /**
     * Langue du destinataire si son gabarit la connaît (ou possède une traduction par défaut), sinon la première
     * traduction disponible : un destinataire dont la langue n'a pas de traduction recevait le gabarit français,
     * il continue de le recevoir.
     */
    public static function pickLanguage(int $notifications_id, string $preferred): string {
        global $DB, $CFG_GLPI;

        if ($preferred === '') {
            $preferred = (string) ($CFG_GLPI['language'] ?? 'fr_FR');
        }
        if (!isset(self::$languages[$notifications_id])) {
            $languages = [];
            foreach ($DB->request([
                'SELECT'     => ['t.language'],
                'FROM'       => 'glpi_notificationtemplatetranslations AS t',
                'INNER JOIN' => [
                    'glpi_notifications_notificationtemplates AS nt' => ['ON' => ['nt' => 'notificationtemplates_id', 't' => 'notificationtemplates_id']],
                ],
                'WHERE'      => ['nt.notifications_id' => $notifications_id, 'nt.mode' => Notification_NotificationTemplate::MODE_MAIL],
                'ORDER'      => ['t.id'],
            ]) as $row) {
                $languages[] = (string) $row['language'];
            }
            self::$languages[$notifications_id] = $languages;
        }
        $languages = self::$languages[$notifications_id];
        if ($languages === [] || in_array($preferred, $languages, true) || in_array('', $languages, true)) {
            return $preferred;
        }
        return $languages[0];
    }

    // ── Balises ───────────────────────────────────────────────────────────────

    /** Balises du plugin : les mêmes que les gabarits ont toujours connues. */
    public static function getTagLabels(): array {
        return [
            'printgestion.printer'         => _n('Imprimante', 'Imprimantes', 1, 'printgestion'),
            'printgestion.client'          => __('Client (entité)', 'printgestion'),
            'printgestion.toner'           => __('Toner', 'printgestion'),
            'printgestion.level'           => __('Niveau (%)', 'printgestion'),
            'printgestion.days'            => __('Jours restants', 'printgestion'),
            'printgestion.cartridge'       => __('Cartouche', 'printgestion'),
            'printgestion.stock'           => __('Stock (toujours vide, gardé pour les gabarits existants)', 'printgestion'),
            'printgestion.contract'        => __('Contrat', 'printgestion'),
            'printgestion.carrier'         => __('Transporteur', 'printgestion'),
            'printgestion.tracking'        => __('Numéro de suivi', 'printgestion'),
            'printgestion.cartridges_list' => __('Liste détaillée des cartouches (HTML)', 'printgestion'),
            'printgestion.printers_list'   => __('Liste des imprimantes (HTML)', 'printgestion'),
            'printgestion.count'           => __('Nombre', 'printgestion'),
            'printgestion.glpi_url'        => __('URL de GLPI', 'printgestion'),
        ];
    }

    /** Toutes les balises à vide (une balise non fournie ressort vide), l'URL de GLPI renseignée. */
    public static function getDefaultTags(): array {
        global $CFG_GLPI;

        $tags = [];
        foreach (array_keys(self::getTagLabels()) as $tag) {
            $tags['##' . $tag . '##'] = '';
        }
        $tags['##printgestion.glpi_url##'] = (string) ($CFG_GLPI['url_base'] ?? '');
        return $tags;
    }

    /** Déclare les balises du plugin dans l'onglet « Balises disponibles » du gabarit. */
    public static function addTags(NotificationTarget $target): void {
        foreach (self::getTagLabels() as $tag => $label) {
            $target->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true, 'lang' => false]);
        }
    }

    /** Liste HTML détaillée, plafonnée : chaque valeur est échappée ici, la liste est rendue telle quelle. */
    public static function htmlList(array $lines, int $hidden = 0, string $more = ''): string {
        $list = '<ul>';
        foreach ($lines as $line) {
            $list .= '<li>' . $line . '</li>';
        }
        if ($hidden > 0) {
            $list .= '<li>' . htmlspecialchars($more !== '' ? $more : sprintf(__('… et %d autres', 'printgestion'), $hidden), ENT_QUOTES, 'UTF-8') . '</li>';
        }
        return $list . '</ul>';
    }

    // ── Émission ──────────────────────────────────────────────────────────────

    /**
     * Émet un événement et dit s'il est parti. Les messages sont envoyés tout de suite (file d'attente native,
     * envoi immédiat) ; la file est relue juste après : un message parti porte sa date d'envoi, un message que
     * GLPI n'a pas pu remettre est retiré de la file — elle le renverrait d'elle-même, et l'appelant renvoie déjà
     * lui-même (« Renvoyer aux Achats », prochain passage de la tâche) — et l'erreur du serveur mail est rendue.
     *
     * @return array ['ok' => bool, 'sent' => int, 'error' => string,
     *                'reason' => ''|'disabled'|'inactive'|'no_recipient'|'failed'|'exception']
     */
    public static function raise(CommonDBTM $item, string $event, array $options = []): array {
        global $DB, $CFG_GLPI;

        $out      = ['ok' => false, 'sent' => 0, 'error' => '', 'reason' => ''];
        $itemtype = $item::class;
        if ((int) ($CFG_GLPI['use_notifications'] ?? 0) !== 1 || (int) ($CFG_GLPI['notifications_mailing'] ?? 0) !== 1) {
            $out['reason'] = 'disabled';
            $out['error']  = __('notifications par mail désactivées dans GLPI (Configuration → Notifications)', 'printgestion');
            return $out;
        }
        if (!self::isActive($itemtype, $event)) {
            $out['reason'] = 'inactive';
            $out['error']  = sprintf(__('notification « %s » inactive (Configuration → Notifications)', 'printgestion'), self::getLabel($event));
            return $out;
        }
        $before   = (int) ($DB->request(['SELECT' => ['MAX' => 'id AS max_id'], 'FROM' => 'glpi_queuednotifications'])->current()['max_id'] ?? 0);
        $messages = count($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []);
        try {
            NotificationEvent::raiseEvent($event, $item, $options);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('notifications', sprintf('Événement %s (%s) : erreur pendant l\'émission.', $event, $itemtype), $e);
            $out['reason'] = 'exception';
            $out['error']  = __('erreur technique pendant l\'émission (détail dans le journal printgestion)', 'printgestion');
        }
        $errors  = self::takeErrorMessages($messages);
        $pending = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_queuednotifications',
            'WHERE' => ['id' => ['>', $before], 'itemtype' => $itemtype, 'event' => $event],
        ]) as $row) {
            if ($row['sent_time'] !== null) {
                $out['sent']++;
            } else {
                $pending[] = (int) $row['id'];
            }
        }
        if ($out['sent'] === 0 && $pending === []) {
            if ($out['error'] === '') {
                $out['reason'] = 'no_recipient';
                $out['error']  = sprintf(__('notification « %s » sans destinataire', 'printgestion'), self::getLabel($event));
            }
            return $out;
        }
        if ($pending !== []) {
            $queue = new QueuedNotification();
            foreach ($pending as $id) {
                $queue->delete(['id' => $id], true);
            }
            if ($out['error'] === '') {
                $out['reason'] = 'failed';
                $out['error']  = $errors !== []
                    ? implode(' ; ', array_unique($errors))
                    : __('échec d\'envoi (détail dans files/_log/mail-error.log)', 'printgestion');
            }
            return $out;
        }
        $out['ok'] = true;
        return $out;
    }

    /** Erreurs d'envoi que GLPI a posées en message de session pendant l'émission : reprises, retirées. */
    private static function takeErrorMessages(int $known): array {
        $all = $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [];
        if (!is_array($all) || count($all) <= $known) {
            return [];
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] = array_slice($all, 0, $known);
        $out = [];
        foreach (array_slice($all, $known) as $message) {
            $text = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', ' — ', (string) $message)), ENT_QUOTES, 'UTF-8'));
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }

    /** Rattache un document à l'objet s'il ne l'est pas déjà : c'est ce lien que la notification attache. */
    public static function ensureDocumentLink(CommonDBTM $item, int $documents_id): void {
        if ($documents_id <= 0 || (int) $item->getID() <= 0) {
            return;
        }
        $criteria = ['documents_id' => $documents_id, 'itemtype' => $item::class, 'items_id' => (int) $item->getID()];
        if (countElementsInTable('glpi_documents_items', $criteria) > 0) {
            return;
        }
        (new Document_Item())->add($criteria);
    }

    // ── Configuration : état des circuits ─────────────────────────────────────

    /** Pour chaque circuit : sa notification (id, nom, active), son gabarit, ses destinataires en clair. */
    public static function describeCircuits(): array {
        global $DB;

        self::ensureInstalled();
        $out       = [];
        $instances = [];
        foreach (self::getCircuits() as $event => $circuit) {
            $row = $DB->request([
                'SELECT'    => ['n.id', 'n.name', 'n.is_active', 't.id AS templates_id', 't.name AS template'],
                'FROM'      => 'glpi_notifications AS n',
                'LEFT JOIN' => [
                    'glpi_notifications_notificationtemplates AS nt' => [
                        'ON' => ['nt' => 'notifications_id', 'n' => 'id', ['AND' => ['nt.mode' => Notification_NotificationTemplate::MODE_MAIL]]],
                    ],
                    'glpi_notificationtemplates AS t' => ['ON' => ['t' => 'id', 'nt' => 'notificationtemplates_id']],
                ],
                'WHERE'     => ['n.itemtype' => $circuit['itemtype'], 'n.event' => $event],
                'ORDER'     => ['n.id'],
                'LIMIT'     => 1,
            ])->current();
            $targets = [];
            if (is_array($row)) {
                if (!array_key_exists($circuit['itemtype'], $instances)) {
                    $instances[$circuit['itemtype']] = NotificationTarget::getInstanceByType($circuit['itemtype'], $event);
                }
                $labels = $instances[$circuit['itemtype']] ? $instances[$circuit['itemtype']]->notification_targets_labels : [];
                foreach ($DB->request(['FROM' => 'glpi_notificationtargets', 'WHERE' => ['notifications_id' => (int) $row['id']], 'ORDER' => ['id']]) as $target) {
                    $label     = $labels[(int) $target['type']][(int) $target['items_id']] ?? sprintf('%d/%d', (int) $target['type'], (int) $target['items_id']);
                    $targets[] = ((int) ($target['is_exclusion'] ?? 0) === 1 ? '− ' : '') . $label;
                }
            }
            $out[$event] = ['label' => $circuit['label'], 'notification' => is_array($row) ? $row : null, 'targets' => $targets];
        }
        return $out;
    }

    // ── Installation ──────────────────────────────────────────────────────────

    /** Colonne du gabarit de suivi de colis (nouveau gabarit) : avant la création des gabarits, qui l'écrit. */
    public static function ensureSchema(): void {
        PluginPrintgestionSchema::ensureColumn(
            'glpi_plugin_printgestion_configs',
            'gabarit_suivi',
            'int ' . DBConnection::getDefaultPrimaryKeySignOption() . ' NULL DEFAULT NULL'
        );
    }

    /**
     * Crée, si elles manquent, les notifications des circuits (idempotent, jamais modifiées ensuite) : gabarit
     * choisi dans la configuration (sinon le gabarit par défaut du même nom), même état actif qu'avant (gabarit
     * choisi = circuit actif), destinataires d'avant (rôles du plugin, auteur, usager de l'imprimante).
     */
    public static function install(): void {
        global $DB;

        $cfg = $DB->request(['FROM' => 'glpi_plugin_printgestion_configs', 'WHERE' => ['id' => 1]])->current();
        if (!is_array($cfg)) {
            return;
        }
        $definitions = plugin_printgestion_template_definitions();
        foreach (self::getCircuits() as $event => $circuit) {
            if (countElementsInTable('glpi_notifications', ['itemtype' => $circuit['itemtype'], 'event' => $event]) > 0) {
                continue;
            }
            $templates_id = 0;
            $active       = !empty($circuit['always']);
            foreach ($circuit['gabarits'] as $field) {
                $configured = (int) ($cfg[$field] ?? 0);
                $found      = self::findTemplate($configured, (string) ($definitions[$field]['name'] ?? ''));
                if ($found > 0 && $templates_id === 0) {
                    $templates_id = $found;
                }
                if ($configured > 0) {
                    $active = true;
                }
            }
            if (isset($circuit['active_from']) && (int) ($cfg[$circuit['active_from']] ?? 0) > 0) {
                $active = true;
            }
            if ($templates_id <= 0) {
                PluginPrintgestionLogger::warning('notifications', sprintf('Notification « %s » non créée : gabarit introuvable.', $circuit['name']));
                continue;
            }
            if ($event === 'expedition_shipped') {
                // Le suivi de colis partait avec le gabarit « Information client toner » : s'il a été personnalisé,
                // le nouveau gabarit de suivi reprend ce texte, et rien ne change pour le commercial.
                self::copyCustomizedTemplate((int) ($cfg['gabarit_commercial'] ?? 0), $templates_id, (array) ($definitions['gabarit_commercial'] ?? []));
            }
            $notifications_id = (int) (new Notification())->add([
                'name'             => $circuit['name'],
                'entities_id'      => 0,
                'itemtype'         => $circuit['itemtype'],
                'event'            => $event,
                'comment'          => self::COMMENT,
                'is_recursive'     => 1,
                'is_active'        => $active ? 1 : 0,
                'attach_documents' => $circuit['attach'],
            ]);
            if ($notifications_id <= 0) {
                continue;
            }
            (new Notification_NotificationTemplate())->add([
                'notifications_id'         => $notifications_id,
                'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
                'notificationtemplates_id' => $templates_id,
            ]);
            $targets = $circuit['targets'] === 'reminder'
                ? self::getReminderTargets((string) ($cfg['reminder_recipients'] ?? 'both'))
                : $circuit['targets'];
            foreach ($targets as $items_id) {
                (new NotificationTarget())->add(['notifications_id' => $notifications_id, 'type' => Notification::USER_TYPE, 'items_id' => (int) $items_id]);
            }
        }
    }

    /** Gabarit choisi dans la configuration s'il existe encore, sinon celui du plugin qui porte ce nom. */
    private static function findTemplate(int $configured, string $name): int {
        global $DB;

        if ($configured > 0 && countElementsInTable('glpi_notificationtemplates', ['id' => $configured]) > 0) {
            return $configured;
        }
        if ($name === '') {
            return 0;
        }
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_notificationtemplates',
            'WHERE'  => ['name' => $name, 'comment' => self::COMMENT],
            'ORDER'  => ['id'],
            'LIMIT'  => 1,
        ])->current();
        return is_array($row) ? (int) $row['id'] : 0;
    }

    /** Destinataires du rappel d'installation, tels que réglés avant (planification, commercial, les deux). */
    private static function getReminderTargets(string $mode): array {
        return match ($mode) {
            'planif'     => [self::ROLE_PLANIF],
            'commercial' => [self::ROLE_COMMERCIAL],
            default      => [self::ROLE_PLANIF, self::ROLE_COMMERCIAL],
        };
    }

    /** Copie la traduction d'un gabarit personnalisé (différent de son texte par défaut) dans un autre gabarit. */
    private static function copyCustomizedTemplate(int $from, int $to, array $definition): void {
        global $DB;

        if ($from <= 0 || $to <= 0 || $from === $to || $definition === []) {
            return;
        }
        $source = $DB->request([
            'FROM'  => 'glpi_notificationtemplatetranslations',
            'WHERE' => ['notificationtemplates_id' => $from],
            'ORDER' => ['language DESC'],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($source)) {
            return;
        }
        $same = (string) $source['subject'] === (string) ($definition['subject'] ?? '')
            && (string) $source['content_html'] === (string) ($definition['html'] ?? '');
        if ($same) {
            return;
        }
        $DB->update(
            'glpi_notificationtemplatetranslations',
            ['subject' => $source['subject'], 'content_text' => $source['content_text'], 'content_html' => $source['content_html']],
            ['notificationtemplates_id' => $to]
        );
    }
}
