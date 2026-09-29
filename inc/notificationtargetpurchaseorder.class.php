<?php
/**
 * PluginPrintgestionNotificationTargetPurchaseorder — notifications natives d'une commande de cartouches
 * (transmission aux Achats).
 *
 * Événements, envoyés tout de suite (résultat lu par PluginPrintgestionNotify::raise()) :
 *   - purchaseorder_sent         : la commande aux Achats, fichier Gesconso archivé joint (document de la
 *                                  commande), rôle Achats et auteur en copie ;
 *   - purchaseorder_planif       : une cartouche à expédier, au rôle Planification et à l'auteur ;
 *   - purchaseorder_planif_group : plusieurs cartouches à expédier, même fichier joint ;
 *   - purchaseorder_courtesy     : courtoisie client, à l'usager de chaque imprimante (un mail par contact,
 *                                  ses imprimantes listées).
 * Et, par la tâche horaire et la file d'attente ordinaire :
 *   - purchaseorder_not_sent     : commande enregistrée mais non transmise depuis plus de STALE_HOURS heures,
 *                                  administrateur de GLPI et auteur. Créée ACTIVE à l'installation : une commande
 *                                  bloquée ne doit pas dormir sans que personne le sache.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionNotificationTargetPurchaseorder extends NotificationTarget {

    const AUTHOR = 7301;

    public function getEvents() {
        return [
            'purchaseorder_sent'         => __('Commande transmise aux Achats (fichier Gesconso joint)', 'printgestion'),
            'purchaseorder_planif'       => __('Cartouche à expédier (planification)', 'printgestion'),
            'purchaseorder_planif_group' => __('Cartouches à expédier, envoi groupé (planification, fichier Gesconso joint)', 'printgestion'),
            'purchaseorder_courtesy'     => __('Courtoisie client : cartouche(s) en cours d\'envoi', 'printgestion'),
            'purchaseorder_not_sent'     => __('Commande non transmise aux Achats', 'printgestion'),
        ];
    }

    public function getEventsToSendImmediately(): array {
        return ['purchaseorder_sent', 'purchaseorder_planif', 'purchaseorder_planif_group', 'purchaseorder_courtesy'];
    }

    /** Sujet tel que le gabarit l'écrit, sans préfixe [GLPI] (la relance « non transmise » garde celui de GLPI). */
    public function getSubjectPrefix($event = '') {
        return $event === 'purchaseorder_not_sent' ? parent::getSubjectPrefix($event) : '';
    }

    public function addAdditionalTargets($event = '') {
        $this->addTarget(self::AUTHOR, __('Auteur de la commande', 'printgestion'));
        PluginPrintgestionNotify::addRoleTargets($this);
        $this->addTarget(PluginPrintgestionNotify::PRINTER_USER, __('Usager des imprimantes concernées (fiche imprimante)', 'printgestion'));
    }

    public function addSpecificTargets($data, $options) {
        if ((int) $data['items_id'] === self::AUTHOR) {
            if ($this->obj instanceof PluginPrintgestionPurchaseorder) {
                // Par son adresse, comme les rôles : l'auteur est en copie quelle que soit son entité.
                foreach (PluginPrintgestionAlert::resolveUsers([(int) $this->obj->fields['users_id']]) as $user) {
                    PluginPrintgestionNotify::addUser($this, $user, (int) ($data['notifications_id'] ?? 0));
                }
            }
            return;
        }
        PluginPrintgestionNotify::addSpecificRecipients($this, $data, $options);
    }

    public function addDataForTemplate($event, $options = []) {
        $order = $this->obj;
        if (!($order instanceof PluginPrintgestionPurchaseorder)) {
            return;
        }
        $f       = $order->fields;
        $sources = [
            PluginPrintgestionPurchaseorder::SOURCE_DIRECT => __('Commande directe', 'printgestion'),
            PluginPrintgestionPurchaseorder::SOURCE_EXPORT => __('Export de demandes', 'printgestion'),
        ];
        $this->data['##order.id##']       = (string) $order->getID();
        $this->data['##order.date##']     = Html::convDateTime((string) $f['date_creation']);
        $this->data['##order.hours##']    = (string) max(0, (int) floor((time() - strtotime((string) $f['date_creation'])) / HOUR_TIMESTAMP));
        $this->data['##order.author##']   = getUserName((int) $f['users_id']);
        $this->data['##order.source##']   = $sources[(string) $f['source']] ?? (string) $f['source'];
        $this->data['##order.lines##']    = (string) (int) $f['nb_lines'];
        $this->data['##order.attempts##'] = (string) (int) $f['attempts'];
        $this->data['##order.error##']    = (string) ($f['last_error'] ?? '');
        $this->data['##order.url##']      = rtrim((string) ($GLOBALS['CFG_GLPI']['url_base'] ?? ''), '/') . PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_expeditions.php';

        // Balises du plugin : les lignes de la commande (gardées avec elle), ou celles du contact (courtoisie).
        $rows = array_values((array) ($options['rows'] ?? (json_decode((string) ($f['mail_rows'] ?? ''), true) ?: [])));
        $tags = PluginPrintgestionNotify::getDefaultTags();
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        switch ($event) {
            case 'purchaseorder_sent':
                $tags = array_merge($tags, PluginPrintgestionExpedition::buildPurchaseBalises($rows));
                break;

            case 'purchaseorder_planif':
            case 'purchaseorder_planif_group':
                if (count($rows) === 1) {
                    // Une cartouche : détail unitaire client / imprimante / cartouche, contrat de l'imprimante.
                    $r             = $rows[0];
                    $contract_name = '';
                    $contracts_id  = PluginPrintgestionContractrate::getContractIdForPrinter((int) ($r['printers_id'] ?? 0));
                    if ($contracts_id > 0) {
                        $contract = new Contract();
                        if ($contract->getFromDB($contracts_id)) {
                            $contract_name = (string) $contract->fields['name'];
                        }
                    }
                    $tags = array_merge($tags, [
                        '##printgestion.printer##'   => (string) ($r['printer_name'] ?? ''),
                        '##printgestion.client##'    => (string) ($r['client'] ?? ''),
                        '##printgestion.toner##'     => (string) ($r['property'] ?? ''),
                        '##printgestion.level##'     => (string) (int) ($r['level'] ?? 0),
                        '##printgestion.days##'      => isset($r['days']) && $r['days'] !== null ? (string) (int) $r['days'] : 'N/A',
                        '##printgestion.cartridge##' => (string) ($r['cartridge_name'] ?? ''),
                        '##printgestion.contract##'  => $contract_name,
                        '##printgestion.count##'     => '1',
                    ]);
                } else {
                    // Plusieurs cartouches : client précisé sur chaque ligne, liste plafonnée, détail dans le fichier joint.
                    $max   = PluginPrintgestionExpedition::MAIL_LIST_MAX;
                    $lines = [];
                    foreach (array_slice($rows, 0, $max) as $r) {
                        $lines[] = '<strong>' . $esc($r['cartridge_name'] ?? '') . '</strong> — ' . $esc($r['client'] ?? '') . ' — ' . $esc($r['printer_name'] ?? '');
                    }
                    $tags = array_merge($tags, PluginPrintgestionExpedition::buildPurchaseBalises($rows), [
                        '##printgestion.cartridges_list##' => PluginPrintgestionNotify::htmlList(
                            $lines,
                            max(0, count($rows) - $max),
                            count($rows) > $max ? sprintf(__('… et %d autres — détail complet dans le fichier Excel joint', 'printgestion'), count($rows) - $max) : ''
                        ),
                        '##printgestion.contract##'        => '',
                        '##printgestion.days##'            => 'N/A',
                    ]);
                }
                break;

            case 'purchaseorder_courtesy':
                $printers = [];
                $clients  = [];
                $lines    = [];
                foreach ($rows as $r) {
                    $pname      = (string) ($r['printer_name'] ?? '');
                    $printers[] = $pname;
                    $client     = trim((string) ($r['client'] ?? ''));
                    if ($client !== '') {
                        $clients[$client] = $client;
                    }
                    $lines[] = '<strong>' . $esc($pname) . '</strong>';
                }
                $tags = array_merge($tags, [
                    '##printgestion.printer##'       => implode(', ', $printers),
                    '##printgestion.client##'        => implode(', ', array_values($clients)),
                    '##printgestion.printers_list##' => PluginPrintgestionNotify::htmlList($lines),
                    '##printgestion.count##'         => (string) count($rows),
                ]);
                break;
        }
        $this->data = array_merge($this->data, $tags);

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags() {
        foreach ([
            'order.id'       => __('ID'),
            'order.date'     => __('Enregistrée le', 'printgestion'),
            'order.hours'    => __('Heures sans transmission', 'printgestion'),
            'order.author'   => __('Auteur', 'printgestion'),
            'order.source'   => __('Origine', 'printgestion'),
            'order.lines'    => __('Nombre de lignes', 'printgestion'),
            'order.attempts' => __('Tentatives d\'envoi', 'printgestion'),
            'order.error'    => __('Dernière erreur', 'printgestion'),
            'order.url'      => __('URL'),
        ] as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        PluginPrintgestionNotify::addTags($this);
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }

    /** Crée, s'ils manquent, le gabarit et la notification « commande non transmise » (active). Idempotent. */
    public static function install(): void {
        global $DB;

        $itemtype = PluginPrintgestionPurchaseorder::class;
        if (countElementsInTable('glpi_notifications', ['itemtype' => $itemtype, 'event' => 'purchaseorder_not_sent']) > 0) {
            return;
        }
        $name    = 'Print Gestion - Commande non transmise aux Achats';
        $subject = '[Print Gestion] Commande non transmise aux Achats depuis ##order.hours## h (##order.lines## ligne(s))';
        $html    = '<p><strong>Une commande de cartouches est enregistrée dans GLPI mais n\'a pas été transmise aux Achats.</strong></p>'
            . '<p>Les cartouches restent verrouillées : sans renvoi, elles ne seront ni commandées ni proposées à nouveau.</p>'
            . '<p>Enregistrée le ##order.date## par ##order.author## (##order.source##, ##order.lines## ligne(s)) — ##order.attempts## tentative(s), dernière erreur : ##order.error##</p>'
            . '<p><a href="##order.url##">Ouvrir l\'écran Expéditions et cliquer « Renvoyer aux Achats »</a></p>';

        $existing     = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notificationtemplates', 'WHERE' => ['itemtype' => $itemtype, 'name' => $name], 'LIMIT' => 1])->current();
        $templates_id = is_array($existing) ? (int) $existing['id'] : 0;
        if ($templates_id <= 0) {
            $templates_id = (int) (new NotificationTemplate())->add(['name' => $name, 'itemtype' => $itemtype, 'comment' => PluginPrintgestionNotify::COMMENT, 'css' => '']);
            (new NotificationTemplateTranslation())->add([
                'notificationtemplates_id' => $templates_id,
                'language'                 => '',
                'subject'                  => $subject,
                'content_text'             => trim(html_entity_decode(strip_tags(str_replace('</p>', "\n", $html)))),
                'content_html'             => $html,
            ]);
        }
        $notifications_id = (int) (new Notification())->add([
            'name'         => $name,
            'entities_id'  => 0,
            'itemtype'     => $itemtype,
            'event'        => 'purchaseorder_not_sent',
            'comment'      => PluginPrintgestionNotify::COMMENT,
            'is_recursive' => 1,
            'is_active'    => 1,
        ]);
        (new Notification_NotificationTemplate())->add([
            'notifications_id'         => $notifications_id,
            'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
            'notificationtemplates_id' => $templates_id,
        ]);
        foreach ([Notification::GLOBAL_ADMINISTRATOR, self::AUTHOR] as $target) {
            (new NotificationTarget())->add(['notifications_id' => $notifications_id, 'type' => Notification::USER_TYPE, 'items_id' => $target]);
        }
    }

    static function uninstall(Migration $migration) {
        global $DB;

        $itemtype = PluginPrintgestionPurchaseorder::class;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notifications', 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            (new Notification())->delete(['id' => (int) $row['id']], true);
        }
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notificationtemplates', 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            (new NotificationTemplate())->delete(['id' => (int) $row['id']], true);
        }
        return true;
    }
}
