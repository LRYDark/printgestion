<?php
/**
 * PluginPrintgestionNotificationTargetExpedition — notifications natives des expéditions.
 *
 * Événements :
 *   - expedition_shipped  : une expédition vient de partir (transporteur, numéro de suivi), information au rôle
 *                           Commercial ;
 *   - expedition_reminder : cartouches expédiées et toujours pas posées après le délai — un seul mail par
 *                           passage de la tâche (objet vide, liste en option), aux rôles Planification et/ou
 *                           Commercial selon le réglage repris à la création de la notification.
 * Envoi immédiat, résultat lu par PluginPrintgestionNotify::raise().
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionNotificationTargetExpedition extends NotificationTarget {

    public function getEvents() {
        return [
            'expedition_shipped'  => __('Expédition partie : transporteur et numéro de suivi (information commerciale)', 'printgestion'),
            'expedition_reminder' => __('Rappel : cartouches expédiées non installées (un mail par passage)', 'printgestion'),
        ];
    }

    public function getEventsToSendImmediately(): array {
        return ['expedition_shipped', 'expedition_reminder'];
    }

    /** Sujet tel que le gabarit l'écrit, sans préfixe [GLPI] : les sujets des mails du plugin ne changent pas. */
    public function getSubjectPrefix($event = '') {
        return '';
    }

    public function addAdditionalTargets($event = '') {
        PluginPrintgestionNotify::addRoleTargets($this);
    }

    public function addSpecificTargets($data, $options) {
        PluginPrintgestionNotify::addSpecificRecipients($this, $data, $options);
    }

    public function addDataForTemplate($event, $options = []) {
        $tags = PluginPrintgestionNotify::getDefaultTags();
        $esc  = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        if ($event === 'expedition_shipped' && $this->obj instanceof PluginPrintgestionExpedition && (int) $this->obj->getID() > 0) {
            $exp          = $this->obj->fields;
            $printer_name = '';
            $entity_name  = '';
            $printer      = new Printer();
            if ($printer->getFromDB((int) $exp['printers_id'])) {
                $printer_name = (string) $printer->fields['name'];
                $entity       = new Entity();
                if ($entity->getFromDB((int) $printer->fields['entities_id'])) {
                    $entity_name = (string) $entity->fields['completename'];
                }
            }
            $tags['##printgestion.printer##']  = $printer_name;
            $tags['##printgestion.client##']   = $entity_name;
            $tags['##printgestion.toner##']    = (string) $exp['toner_property'];
            $tags['##printgestion.carrier##']  = strtoupper((string) $exp['transport_carrier']);
            $tags['##printgestion.tracking##'] = (string) $exp['transport_number'];
        } elseif ($event === 'expedition_reminder') {
            // Digest : un seul mail listant toutes les cartouches en retard.
            $items = array_values((array) ($options['items'] ?? []));
            $max   = PluginPrintgestionExpedition::MAIL_LIST_MAX;
            $lines = [];
            foreach (array_slice($items, 0, $max) as $it) {
                $lines[] = '<strong>' . $esc($it['printer']) . '</strong> — ' . $esc($it['client']) . ' — ' . $esc($it['property'])
                    . ' — ' . $esc(sprintf(__('expédiée il y a %d j', 'printgestion'), (int) $it['days']));
            }
            $clients  = array_values(array_unique(array_map(static fn($it) => (string) $it['client'], $items)));
            $printers = array_values(array_unique(array_map(static fn($it) => (string) $it['printer'], $items)));
            $days     = array_map(static fn($it) => (int) $it['days'], $items);

            $tags['##printgestion.cartridges_list##'] = PluginPrintgestionNotify::htmlList($lines, max(0, count($items) - $max));
            $tags['##printgestion.count##']           = (string) count($items);
            $tags['##printgestion.printer##']         = count($printers) > 1 ? sprintf(__('%d imprimantes', 'printgestion'), count($printers)) : (string) ($printers[0] ?? '');
            $tags['##printgestion.client##']          = count($clients) > 1 ? sprintf(__('%d clients', 'printgestion'), count($clients)) : (string) ($clients[0] ?? '');
            $tags['##printgestion.days##']            = $days !== [] ? (string) max($days) : '';
        }
        $this->data = array_merge($this->data, $tags);
    }

    public function getTags() {
        PluginPrintgestionNotify::addTags($this);
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }

    static function uninstall(Migration $migration) {
        global $DB;

        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notifications', 'WHERE' => ['itemtype' => PluginPrintgestionExpedition::class]]) as $row) {
            (new Notification())->delete(['id' => (int) $row['id']], true);
        }
        return true;
    }
}
