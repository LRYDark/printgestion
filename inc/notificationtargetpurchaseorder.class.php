<?php
/**
 * PluginPrintgestionNotificationTargetPurchaseorder — notification native d'une commande enregistrée mais non
 * transmise aux Achats depuis plus de PluginPrintgestionPurchaseorder::STALE_HOURS heures.
 *
 * Événement : purchaseorder_not_sent. Destinataires par défaut : administrateur de GLPI et auteur de la
 * commande. Créée ACTIVE à l'installation : une commande bloquée ne doit pas dormir sans que personne le sache.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionNotificationTargetPurchaseorder extends NotificationTarget {

    const AUTHOR = 7301;

    public function getEvents() {
        return ['purchaseorder_not_sent' => __('Commande non transmise aux Achats', 'printgestion')];
    }

    public function addAdditionalTargets($event = '') {
        $this->addTarget(self::AUTHOR, __('Auteur de la commande', 'printgestion'));
    }

    public function addSpecificTargets($data, $options) {
        if ((int) $data['items_id'] === self::AUTHOR && $this->obj instanceof PluginPrintgestionPurchaseorder) {
            $this->addUserByField('users_id');
        }
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
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }

    /** Crée, s'ils manquent, le gabarit et la notification (active). Idempotent. */
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
            $templates_id = (int) (new NotificationTemplate())->add(['name' => $name, 'itemtype' => $itemtype, 'comment' => 'Created by plugin printgestion', 'css' => '']);
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
            'comment'      => 'Created by plugin printgestion',
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
