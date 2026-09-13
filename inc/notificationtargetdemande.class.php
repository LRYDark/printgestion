<?php
/**
 * PluginPrintgestionNotificationTargetDemande — notifications natives GLPI des demandes
 * d'envoi (NotificationTarget, événements, file d'attente QueuedNotification).
 *
 * Événements :
 *   - demande_proposed : demande créée ou complétée par la proposition automatique ;
 *   - demande_stale    : relance d'une demande qui traîne (proposée sans validation ou
 *                        validée sans export depuis demande_reminder_days jours) ;
 *   - demande_exported : fichier Gesconso envoyé aux Achats.
 * Destinataires : ceux de GLPI (administrateurs, profils, groupes), choisis dans
 * Configuration → Notifications. Les notifications sont créées INACTIVES à l'installation :
 * à activer une fois les destinataires choisis.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionNotificationTargetDemande extends NotificationTarget {

    public function getEvents() {
        return [
            'demande_proposed' => __('Demande d\'envoi proposée', 'printgestion'),
            'demande_stale'    => __('Demande d\'envoi en attente (relance)', 'printgestion'),
            'demande_exported' => __('Demande d\'envoi exportée vers les Achats', 'printgestion'),
        ];
    }

    public function addDataForTemplate($event, $options = []) {
        $demande = $this->obj;
        if (!($demande instanceof PluginPrintgestionDemande)) {
            return;
        }

        $f       = $demande->fields;
        $events  = $this->getAllEvents();
        $statut  = (string) $f['statut'];
        $since   = (string) (($statut === PluginPrintgestionDemande::STATUS_VALIDATED ? $f['date_validate'] : null) ?: $f['date_creation']);

        $this->data['##demande.action##']        = $events[$event] ?? $event;
        $this->data['##demande.id##']            = (string) $demande->getID();
        $this->data['##demande.name##']          = (string) $f['name'];
        $this->data['##demande.entity##']        = Dropdown::getDropdownName('glpi_entities', (int) $f['entities_id']);
        $this->data['##demande.site##']          = (int) $f['locations_id'] > 0 ? Dropdown::getDropdownName('glpi_locations', (int) $f['locations_id']) : '';
        $this->data['##demande.status##']        = PluginPrintgestionDemande::getStatusLabels()[$statut] ?? $statut;
        $this->data['##demande.deliverymode##']  = PluginPrintgestionDemande::getDeliveryModeLabels()[(string) $f['delivery_mode']] ?? '';
        $this->data['##demande.contact##']       = (string) $f['contact'];
        $this->data['##demande.comment##']       = (string) $f['delivery_comment'];
        $this->data['##demande.datecreation##']  = Html::convDateTime((string) $f['date_creation']);
        $this->data['##demande.datevalidate##']  = Html::convDateTime((string) $f['date_validate']);
        $this->data['##demande.validator##']     = (int) $f['users_id_validate'] > 0 ? getUserName((int) $f['users_id_validate']) : '';
        $this->data['##demande.days##']          = $since !== '' ? (string) max(0, (int) floor((time() - strtotime($since)) / DAY_TIMESTAMP)) : '';
        $this->data['##demande.url##']           = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? '',
            PluginPrintgestionDemande::class . '_' . $demande->getID()
        );

        $lines = $demande->getLines();
        $this->data['##demande.lines.count##'] = (string) count($lines);
        foreach ($lines as $line) {
            $this->data['lines'][] = [
                '##line.printer##'   => (string) ($line['printer_name'] ?? ('#' . $line['printers_id'])),
                '##line.toner##'     => (string) $line['toner_property'],
                '##line.cartridge##' => trim((string) $line['cartridge_ref'] . ' — ' . (string) $line['cartridge_name'], ' —'),
                '##line.quantity##'  => (string) (int) $line['quantity'],
                '##line.contract##'  => (int) $line['is_under_contract'] === 1 ? __('Sous contrat', 'printgestion') : __('Hors contrat', 'printgestion'),
                '##line.status##'    => PluginPrintgestionDemande::getStatusLabels()[(string) $line['statut']] ?? (string) $line['statut'],
            ];
        }

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags() {
        $tags = [
            'demande.action'       => _n('Event', 'Events', 1),
            'demande.id'           => __('ID'),
            'demande.name'         => __('Nom', 'printgestion'),
            'demande.entity'       => Entity::getTypeName(1),
            'demande.site'         => __('Site de livraison', 'printgestion'),
            'demande.status'       => __('Statut', 'printgestion'),
            'demande.deliverymode' => __('Mode de livraison', 'printgestion'),
            'demande.contact'      => __('Contact de livraison', 'printgestion'),
            'demande.comment'      => __('Commentaire de livraison', 'printgestion'),
            'demande.datecreation' => __('Proposée le', 'printgestion'),
            'demande.datevalidate' => __('Validée le', 'printgestion'),
            'demande.validator'    => __('Validée par', 'printgestion'),
            'demande.days'         => __('Jours d\'attente', 'printgestion'),
            'demande.url'          => __('URL'),
            'demande.lines.count'  => __('Nombre de lignes', 'printgestion'),
            'line.printer'         => _n('Imprimante', 'Imprimantes', 1, 'printgestion'),
            'line.toner'           => __('Toner', 'printgestion'),
            'line.cartridge'       => __('Cartouche', 'printgestion'),
            'line.quantity'        => __('Quantité', 'printgestion'),
            'line.contract'        => __('Contrat', 'printgestion'),
            'line.status'          => __('Statut de la ligne', 'printgestion'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        $this->addTagToList([
            'tag'     => 'lines',
            'label'   => PluginPrintgestionDemandeline::getTypeName(Session::getPluralNumber()),
            'foreach' => true,
        ]);

        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }

    // ── Installation ──────────────────────────────────────────────────────────

    /** Gabarits par défaut : événement => [nom, sujet, corps HTML]. */
    private static function getDefaultTemplates(): array {
        $lines = '<ul>##FOREACHlines##<li>##line.printer## — ##line.toner## — ##line.cartridge## × ##line.quantity## (##line.contract##)</li>##ENDFOREACHlines##</ul>';
        $head  = '<p><strong>##demande.name##</strong> (demande n° ##demande.id##) — ##demande.status##</p>'
            . '<p>Client : ##demande.entity## — Site : ##demande.site## — ##demande.deliverymode##</p>';
        $link  = '<p><a href="##demande.url##">Ouvrir la demande dans GLPI</a></p>';

        return [
            'demande_proposed' => [
                'Print Gestion - Demande d\'envoi proposée',
                '[Print Gestion] Demande d\'envoi à valider — ##demande.entity## (##demande.lines.count## ligne(s))',
                '<p>Une demande d\'envoi de consommables a été proposée à partir des alertes toner et attend votre validation.</p>' . $head . $lines . $link,
            ],
            'demande_stale' => [
                'Print Gestion - Demande d\'envoi en attente',
                '[Print Gestion] Relance : demande n° ##demande.id## ##demande.status## depuis ##demande.days## jour(s)',
                '<p>Cette demande d\'envoi attend depuis ##demande.days## jour(s) : tant qu\'elle n\'est ni exportée ni annulée, ses cartouches ne peuvent pas être commandées.</p>' . $head . $lines . $link,
            ],
            'demande_exported' => [
                'Print Gestion - Demande d\'envoi exportée',
                '[Print Gestion] Demande n° ##demande.id## envoyée aux Achats — ##demande.entity##',
                '<p>La demande d\'envoi a été exportée vers les Achats (fichier Gesconso archivé sur la demande).</p>' . $head . $lines . $link,
            ],
        ];
    }

    /**
     * Crée, s'ils manquent, les gabarits et les notifications (INACTIVES, destinataire par
     * défaut : administrateur de l'entité). Idempotent : rien n'est modifié s'ils existent.
     */
    public static function install(): void {
        global $DB;

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach (self::getDefaultTemplates() as $event => [$name, $subject, $html]) {
            if (countElementsInTable('glpi_notifications', ['itemtype' => PluginPrintgestionDemande::class, 'event' => $event]) > 0) {
                continue;
            }

            $existing     = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_notificationtemplates',
                'WHERE'  => ['itemtype' => PluginPrintgestionDemande::class, 'name' => $name],
                'LIMIT'  => 1,
            ])->current();
            $templates_id = is_array($existing) ? (int) $existing['id'] : 0;
            if ($templates_id <= 0) {
                $template     = new NotificationTemplate();
                $templates_id = (int) $template->add([
                    'name'     => $name,
                    'itemtype' => PluginPrintgestionDemande::class,
                    'date_mod' => $now,
                    'comment'  => 'Created by plugin printgestion',
                    'css'      => '',
                ]);
                (new NotificationTemplateTranslation())->add([
                    'notificationtemplates_id' => $templates_id,
                    'language'                 => '',
                    'subject'                  => $subject,
                    'content_text'             => trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>'], "\n", $html)))),
                    'content_html'             => $html,
                ]);
            }

            $notification     = new Notification();
            $notifications_id = (int) $notification->add([
                'name'         => $name,
                'entities_id'  => 0,
                'itemtype'     => PluginPrintgestionDemande::class,
                'event'        => $event,
                'comment'      => 'Created by plugin printgestion — choisir les destinataires puis activer.',
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
                'items_id'         => Notification::ENTITY_ADMINISTRATOR,
            ]);
        }
    }

    static function uninstall(Migration $migration) {
        global $DB;

        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notifications', 'WHERE' => ['itemtype' => PluginPrintgestionDemande::class]]) as $row) {
            (new Notification())->delete(['id' => (int) $row['id']], true);
        }
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notificationtemplates', 'WHERE' => ['itemtype' => PluginPrintgestionDemande::class]]) as $row) {
            (new NotificationTemplate())->delete(['id' => (int) $row['id']], true);
        }
        return true;
    }
}
