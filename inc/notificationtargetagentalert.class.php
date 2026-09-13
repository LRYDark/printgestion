<?php
/**
 * PluginPrintgestionNotificationTargetAgentalert — notifications natives GLPI des alertes de sondes
 * (NotificationTarget, événements, file d'attente QueuedNotification).
 *
 * Événements :
 *   - agent_silent   : sonde GLPI Agent sans contact depuis silent_days jours ;
 *   - printer_silent : imprimantes d'une entité qui ne remontent plus alors que leur sonde contacte GLPI (une
 *                      notification par entité et par passage de la tâche, avec la liste des imprimantes).
 * Émises seulement si le réglage correspondant est activé dans Configuration > Inventaire > Agent cleanup.
 * Destinataires : ceux de GLPI, choisis dans Configuration > Notifications. Notifications créées INACTIVES à
 * l'installation, destinataire par défaut l'administrateur GLPI — pas l'administrateur de l'entité, qui peut
 * être le client.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionNotificationTargetAgentalert extends NotificationTarget {

    public function getEvents() {
        return [
            PluginPrintgestionAgentalert::TYPE_AGENT   => __('Sonde GLPI Agent sans contact', 'printgestion'),
            PluginPrintgestionAgentalert::TYPE_PRINTER => __('Imprimantes qui ne remontent plus (sonde active)', 'printgestion'),
        ];
    }

    public function addDataForTemplate($event, $options = []) {
        global $CFG_GLPI, $DB;

        $alert = $this->obj;
        if (!($alert instanceof PluginPrintgestionAgentalert)) {
            return;
        }

        $f       = $alert->fields;
        $events  = $this->getAllEvents();
        $agent   = new Agent();
        $known   = (int) $f['agents_id'] > 0 && $agent->getFromDB((int) $f['agents_id']);
        $base    = rtrim((string) $CFG_GLPI['url_base'], '/') . '/plugins/printgestion/front';
        $reasons = PluginPrintgestionAgentalert::getReasonLabels();

        $this->data['##alert.action##']      = $events[$event] ?? $event;
        $this->data['##alert.entity##']      = Dropdown::getDropdownName('glpi_entities', (int) $f['entities_id']);
        $this->data['##alert.days##']        = (string) PluginPrintgestionCollect::getSilentDays();
        $this->data['##alert.datebegin##']   = empty($f['date_begin']) ? '' : Html::convDateTime((string) $f['date_begin']);
        $this->data['##agent.name##']        = $known ? (string) $agent->fields['name'] : sprintf(__('Agent n° %d', 'printgestion'), (int) $f['agents_id']);
        $this->data['##agent.version##']     = $known ? PluginPrintgestionAgentsetting::getAgentVersion($agent->fields) : '';
        $this->data['##agent.lastcontact##'] = $known && !empty($agent->fields['last_contact']) ? Html::convDateTime((string) $agent->fields['last_contact']) : __('jamais', 'printgestion');
        $this->data['##agent.host##']        = $known ? PluginPrintgestionAgentsetting::getHostName($agent->fields) : '';
        $this->data['##agent.url##']         = $known ? $base . '/sondes.php?id=' . (int) $agent->getID() : $base . '/sondes.php';
        $this->data['##collect.url##']       = $base . '/collect.php';

        $this->data['printers'] = [];
        if ($event === PluginPrintgestionAgentalert::TYPE_PRINTER) {
            $ids  = array_values(array_filter(array_map('intval', (array) ($options['alerts_ids'] ?? [$alert->getID()]))));
            $rows = empty($ids) ? [] : iterator_to_array($DB->request([
                'SELECT'    => ['al.printers_id', 'al.reason', 'al.date_begin', 'p.name', 'ag.name AS agent_name'],
                'FROM'      => PluginPrintgestionAgentalert::getTable() . ' AS al',
                'LEFT JOIN' => [
                    'glpi_printers AS p' => ['ON' => ['al' => 'printers_id', 'p' => 'id']],
                    'glpi_agents AS ag'  => ['ON' => ['al' => 'agents_id', 'ag' => 'id']],
                ],
                'WHERE'     => ['al.id' => $ids, 'al.type' => PluginPrintgestionAgentalert::TYPE_PRINTER],
                'ORDER'     => ['p.name'],
            ]), false);
            $ips = [];
            if (!empty($rows)) {
                foreach ($DB->request([
                    'SELECT'   => ['mainitems_id', 'name'],
                    'DISTINCT' => true,
                    'FROM'     => 'glpi_ipaddresses',
                    'WHERE'    => ['mainitemtype' => Printer::class, 'mainitems_id' => array_column($rows, 'printers_id'), 'version' => 4, 'is_deleted' => 0],
                ]) as $ip) {
                    $ips[(int) $ip['mainitems_id']][] = (string) $ip['name'];
                }
            }
            foreach ($rows as $row) {
                $printers_id              = (int) $row['printers_id'];
                $this->data['printers'][] = [
                    '##printer.name##'          => (string) ($row['name'] ?? ('#' . $printers_id)),
                    '##printer.ip##'            => implode(', ', $ips[$printers_id] ?? []),
                    '##printer.reason##'        => $reasons[(string) $row['reason']] ?? (string) $row['reason'],
                    '##printer.lastinventory##' => (string) $row['reason'] === PluginPrintgestionCollect::STATE_NO_INVENTORY || empty($row['date_begin'])
                        ? __('aucun', 'printgestion')
                        : Html::convDateTime((string) $row['date_begin']),
                    '##printer.probe##'         => (string) ($row['agent_name'] ?? ''),
                    '##printer.url##'           => $this->formatURL($options['additionnaloption']['usertype'] ?? '', 'Printer_' . $printers_id),
                ];
            }
        }
        $this->data['##printers.count##'] = (string) count($this->data['printers']);

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags() {
        $tags = [
            'alert.action'          => _n('Event', 'Events', 1),
            'alert.entity'          => Entity::getTypeName(1),
            'alert.days'            => __('Délai en jours', 'printgestion'),
            'alert.datebegin'       => __('Depuis le', 'printgestion'),
            'agent.name'            => __('Sonde', 'printgestion'),
            'agent.version'         => __('Version de GLPI Agent', 'printgestion'),
            'agent.lastcontact'     => __('Dernier contact', 'printgestion'),
            'agent.host'            => __('PC hôte', 'printgestion'),
            'agent.url'             => __('URL de la sonde', 'printgestion'),
            'collect.url'           => __('URL du contrôle de la remontée', 'printgestion'),
            'printers.count'        => __('Nombre d\'imprimantes', 'printgestion'),
            'printer.name'          => _n('Imprimante', 'Imprimantes', 1, 'printgestion'),
            'printer.ip'            => __('Adresse IP', 'printgestion'),
            'printer.reason'        => __('Motif', 'printgestion'),
            'printer.lastinventory' => __('Dernier inventaire réseau', 'printgestion'),
            'printer.probe'         => __('Sonde de l\'imprimante', 'printgestion'),
            'printer.url'           => __('URL de l\'imprimante', 'printgestion'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        $this->addTagToList([
            'tag'     => 'printers',
            'label'   => _n('Imprimante', 'Imprimantes', Session::getPluralNumber(), 'printgestion'),
            'foreach' => true,
        ]);

        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }

    // ── Installation ──────────────────────────────────────────────────────────

    /** Gabarits par défaut : événement => [nom, sujet, corps HTML]. */
    private static function getDefaultTemplates(): array {
        return [
            PluginPrintgestionAgentalert::TYPE_AGENT => [
                'Print Gestion - Sonde GLPI Agent sans contact',
                '[Print Gestion] Sonde sans contact : ##agent.name## (##alert.entity##)',
                '<p>La sonde <strong>##agent.name##</strong> de ##alert.entity## ne contacte plus GLPI depuis le ##agent.lastcontact## (seuil : ##alert.days## jours).</p>'
                . '<p>PC hôte : ##agent.host## — GLPI Agent ##agent.version##</p>'
                . '<p>Tant qu\'elle ne contacte pas GLPI, les imprimantes de ce site ne sont plus inventoriées et aucune alerte toner ne peut partir. Causes habituelles : PC éteint, remplacé ou débranché du réseau, service GLPI Agent arrêté, accès au serveur GLPI bloqué.</p>'
                . '<p><a href="##agent.url##">Ouvrir la sonde dans GLPI</a></p>',
            ],
            PluginPrintgestionAgentalert::TYPE_PRINTER => [
                'Print Gestion - Imprimantes qui ne remontent plus',
                '[Print Gestion] ##printers.count## imprimante(s) ne remontent plus : ##alert.entity##',
                '<p>Chez ##alert.entity##, ces imprimantes ne remontent plus alors que leur sonde contacte GLPI (seuil : ##alert.days## jours) :</p>'
                . '<ul>##FOREACHprinters##<li><a href="##printer.url##">##printer.name##</a> (##printer.ip##) — ##printer.reason## — dernier inventaire réseau : ##printer.lastinventory## — sonde ##printer.probe##</li>##ENDFOREACHprinters##</ul>'
                . '<p>Causes habituelles : imprimante éteinte ou remplacée, adresse IP changée, SNMP désactivé ou communauté modifiée. Une imprimante qui ne remonte plus ne déclenche aucune alerte toner.</p>'
                . '<p><a href="##collect.url##">Contrôle de la remontée</a></p>',
            ],
        ];
    }

    /** Version texte d'un gabarit : liens écrits « libellé : adresse », un paragraphe ou un élément de liste par ligne. */
    private static function toText(string $html): string {
        $text = (string) preg_replace('/<a href="([^"]+)">(.*?)<\/a>/', '$2 : $1', $html);
        return trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>'], "\n", $text))));
    }

    /**
     * Crée, s'ils manquent, les gabarits et les notifications (INACTIVES, destinataire par défaut :
     * administrateur GLPI). Idempotent : rien n'est modifié s'ils existent.
     */
    public static function install(): void {
        global $DB;

        $itemtype = PluginPrintgestionAgentalert::class;
        $now      = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach (self::getDefaultTemplates() as $event => [$name, $subject, $html]) {
            if (countElementsInTable('glpi_notifications', ['itemtype' => $itemtype, 'event' => $event]) > 0) {
                continue;
            }

            $existing     = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_notificationtemplates',
                'WHERE'  => ['itemtype' => $itemtype, 'name' => $name],
                'LIMIT'  => 1,
            ])->current();
            $templates_id = is_array($existing) ? (int) $existing['id'] : 0;
            if ($templates_id <= 0) {
                $template     = new NotificationTemplate();
                $templates_id = (int) $template->add([
                    'name'     => $name,
                    'itemtype' => $itemtype,
                    'date_mod' => $now,
                    'comment'  => 'Created by plugin printgestion',
                    'css'      => '',
                ]);
                (new NotificationTemplateTranslation())->add([
                    'notificationtemplates_id' => $templates_id,
                    'language'                 => '',
                    'subject'                  => $subject,
                    'content_text'             => self::toText($html),
                    'content_html'             => $html,
                ]);
            }

            $notification     = new Notification();
            $notifications_id = (int) $notification->add([
                'name'         => $name,
                'entities_id'  => 0,
                'itemtype'     => $itemtype,
                'event'        => $event,
                'comment'      => 'Created by plugin printgestion — choisir les destinataires, activer, puis activer l\'envoi dans Configuration > Inventaire > Agent cleanup.',
                'is_recursive' => 1,
                'is_active'    => 0,
                'date_mod'     => $now,
            ]);
            (new Notification_NotificationTemplate())->add([
                'notifications_id'         => $notifications_id,
                'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
                'notificationtemplates_id' => $templates_id,
            ]);
            (new NotificationTarget())->add([
                'notifications_id' => $notifications_id,
                'type'             => Notification::USER_TYPE,
                'items_id'         => Notification::GLOBAL_ADMINISTRATOR,
            ]);
        }
    }

    static function uninstall(Migration $migration) {
        global $DB;

        $itemtype = PluginPrintgestionAgentalert::class;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notifications', 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            (new Notification())->delete(['id' => (int) $row['id']], true);
        }
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notificationtemplates', 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            (new NotificationTemplate())->delete(['id' => (int) $row['id']], true);
        }
        return true;
    }
}
