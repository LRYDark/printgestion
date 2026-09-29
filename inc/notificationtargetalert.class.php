<?php
/**
 * PluginPrintgestionNotificationTargetAlert — notification native de l'alerte toner (information commerciale).
 *
 * Événement toner_alert : un seul mail par passage de la tâche horaire, toutes les alertes du passage dedans
 * (objet vide, liste en option, comme les alertes de cartouches de GLPI). Destinataire par défaut : le rôle
 * Commercial du plugin. Envoi immédiat, résultat lu par PluginPrintgestionNotify::raise().
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionNotificationTargetAlert extends NotificationTarget {

    public function getEvents() {
        return ['toner_alert' => __('Alerte toner (information commerciale, un mail par passage)', 'printgestion')];
    }

    public function getEventsToSendImmediately(): array {
        return ['toner_alert'];
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
        $pending = array_values((array) ($options['pending'] ?? []));
        $tags    = PluginPrintgestionNotify::getDefaultTags();
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        if (count($pending) === 1) {
            // Envoi simple : balises unitaires détaillées.
            $row = $pending[0]['row'];
            $tags['##printgestion.printer##']   = (string) $row['printer_name'];
            $tags['##printgestion.client##']    = (string) $row['entity_name'];
            $tags['##printgestion.toner##']     = (string) $row['property'];
            $tags['##printgestion.level##']     = (string) $row['level'];
            $tags['##printgestion.days##']      = $row['days_remaining'] !== null ? (string) $row['days_remaining'] : 'N/A';
            $tags['##printgestion.cartridge##'] = (string) ($row['cartridge_type'] ?? $row['property']);
        } elseif (count($pending) > 1) {
            // Envoi multi : agrégats et liste détaillée, plafonnée.
            $max   = PluginPrintgestionExpedition::MAIL_LIST_MAX;
            $lines = [];
            foreach (array_slice($pending, 0, $max) as $p) {
                $row      = $p['row'];
                $days_txt = $row['days_remaining'] !== null ? ($row['days_remaining'] . ' j') : 'N/A';
                $lines[]  = '<strong>' . $esc($row['printer_name']) . '</strong> — ' . $esc($row['entity_name'])
                    . ' — ' . $esc($row['property']) . ' — ' . (int) $row['level'] . '% — ' . $esc($days_txt);
            }
            $clients   = array_values(array_unique(array_map(static fn($p) => (string) $p['row']['entity_name'], $pending)));
            $printers  = array_values(array_unique(array_map(static fn($p) => (string) $p['row']['printer_name'], $pending)));
            $level_min = min(array_map(static fn($p) => (int) $p['row']['level'], $pending));
            $days_vals = array_filter(array_map(static fn($p) => $p['row']['days_remaining'], $pending), static fn($d) => $d !== null);

            $tags['##printgestion.printer##']         = count($printers) > 1 ? sprintf(__('%d imprimantes', 'printgestion'), count($printers)) : (string) ($printers[0] ?? '');
            $tags['##printgestion.client##']          = count($clients) > 1 ? sprintf(__('%d clients', 'printgestion'), count($clients)) : (string) ($clients[0] ?? '');
            $tags['##printgestion.toner##']           = sprintf(__('%d toners bas', 'printgestion'), count($pending));
            $tags['##printgestion.level##']           = (string) $level_min;
            $tags['##printgestion.days##']            = !empty($days_vals) ? (string) min($days_vals) : 'N/A';
            $tags['##printgestion.cartridges_list##'] = PluginPrintgestionNotify::htmlList($lines, max(0, count($pending) - $max));
            $tags['##printgestion.count##']           = (string) count($pending);
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

        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notifications', 'WHERE' => ['itemtype' => PluginPrintgestionAlert::class]]) as $row) {
            (new Notification())->delete(['id' => (int) $row['id']], true);
        }
        return true;
    }
}
