<?php
/**
 * PluginPrintgestionContractalert — alertes de contrat NATIVES de GLPI (fin de contrat,
 * préavis) : état et activation en un clic depuis la configuration du plugin.
 *
 * Rien n'est réimplémenté : l'activation règle ce que GLPI utilise déjà — action
 * automatique « contract », alertes de contrat de l'entité racine (héritées par les
 * sous-entités qui n'ont pas leur propre réglage), notifications natives des contrats.
 * La configuration globale des notifications GLPI n'est jamais modifiée : si elle est
 * désactivée, c'est signalé.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionContractalert extends CommonGLPI {

    static $rightname = 'config';

    /** Délai d'alerte appliqué à l'entité racine si aucun n'est réglé (jours). */
    const DEFAULT_BEFORE_DAYS = 30;

    static function getTypeName($nb = 0) {
        return __('Alertes de contrat natives', 'printgestion');
    }

    /** État actuel de chaque maillon des alertes de contrat. */
    public static function getStatus(): array {
        global $CFG_GLPI;

        $cron      = new CronTask();
        $has_cron  = $cron->getFromDBbyName('Contract', 'contract');
        $root      = new Entity();
        $has_root  = $root->getFromDB(0);

        return [
            'notifications_enabled' => (bool) ($CFG_GLPI['use_notifications'] ?? false),
            'cron_exists'           => $has_cron,
            'cron_active'           => $has_cron && (int) $cron->fields['state'] !== CronTask::STATE_DISABLE,
            'entity_alert'          => $has_root && (int) $root->fields['use_contracts_alert'] === 1,
            'before_days'           => $has_root ? (int) $root->fields['send_contracts_alert_before_delay'] : 0,
            'notifications_active'  => countElementsInTable('glpi_notifications', ['itemtype' => 'Contract', 'is_active' => 1]),
            'notifications_total'   => countElementsInTable('glpi_notifications', ['itemtype' => 'Contract']),
        ];
    }

    /**
     * Active les maillons désactivés (droit GLPI config UPDATE), par update() natif : action
     * automatique, alertes de l'entité racine (délai 30 jours s'il n'y en a pas), notifications
     * des contrats.
     *
     * @return array ['done' => string[], 'errors' => string[]]
     */
    public static function activate(): array {
        global $DB;

        $out    = ['done' => [], 'errors' => []];
        $status = self::getStatus();

        if (!$status['notifications_enabled']) {
            $out['errors'][] = __('Les notifications GLPI sont désactivées globalement (Configuration → Notifications) : aucune alerte ne partira tant qu\'elles ne sont pas activées.', 'printgestion');
        }

        $cron = new CronTask();
        if (!$status['cron_exists']) {
            $out['errors'][] = __('Action automatique « contract » introuvable.', 'printgestion');
        } elseif (!$status['cron_active'] && $cron->getFromDBbyName('Contract', 'contract')) {
            if ($cron->update(['id' => (int) $cron->getID(), 'state' => CronTask::STATE_WAITING])) {
                $out['done'][] = __('Action automatique « contract » activée.', 'printgestion');
            } else {
                $out['errors'][] = __('Activation de l\'action automatique « contract » refusée.', 'printgestion');
            }
        }

        if (!$status['entity_alert'] || $status['before_days'] <= 0) {
            $input = ['id' => 0, 'use_contracts_alert' => 1];
            if ($status['before_days'] <= 0) {
                $input['send_contracts_alert_before_delay'] = self::DEFAULT_BEFORE_DAYS;
            }
            if ((new Entity())->update($input)) {
                $out['done'][] = sprintf(__('Alertes de contrat activées sur l\'entité racine (délai : %d jours).', 'printgestion'), $input['send_contracts_alert_before_delay'] ?? $status['before_days']);
            } else {
                $out['errors'][] = __('Activation des alertes de contrat sur l\'entité racine refusée.', 'printgestion');
            }
        }

        $activated = 0;
        $refused   = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_notifications', 'WHERE' => ['itemtype' => 'Contract', 'is_active' => 0]]) as $row) {
            if ((new Notification())->update(['id' => (int) $row['id'], 'is_active' => 1])) {
                $activated++;
            } else {
                $refused++;
            }
        }
        if ($refused > 0) {
            $out['errors'][] = sprintf(__('%d notification(s) de contrat non activée(s) : mise à jour refusée par GLPI.', 'printgestion'), $refused);
        }
        if ($activated > 0) {
            $out['done'][] = sprintf(__('%d notification(s) de contrat activée(s).', 'printgestion'), $activated);
        }
        if ($status['notifications_total'] === 0) {
            $out['errors'][] = __('Aucune notification native de contrat n\'existe : à créer dans Configuration → Notifications.', 'printgestion');
        }

        return $out;
    }

    /** Carte de la configuration du plugin (bouton réservé au droit GLPI config UPDATE). */
    public static function showConfigCard(): void {
        $esc    = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $status = self::getStatus();
        $badge  = static fn(bool $ok) => $ok
            ? "<span class='badge bg-green text-green-fg'>" . $esc(__('Actif', 'printgestion')) . "</span>"
            : "<span class='badge bg-red text-red-fg'>" . $esc(__('Inactif', 'printgestion')) . "</span>";

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(self::getTypeName()) . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small'>" . $esc(__('Alertes GLPI de fin de contrat et de préavis : aucune réimplémentation, le bouton règle les réglages natifs (action automatique, entité racine, notifications des contrats).', 'printgestion')) . "</p>";
        echo "<ul class='list-unstyled mb-3'>"
            . "<li>" . $badge($status['notifications_enabled']) . ' ' . $esc(__('Notifications GLPI (configuration globale)', 'printgestion')) . "</li>"
            . "<li>" . $badge($status['cron_active']) . ' ' . $esc(__('Action automatique « contract »', 'printgestion')) . "</li>"
            . "<li>" . $badge($status['entity_alert']) . ' ' . $esc(sprintf(__('Alertes de contrat de l\'entité racine (délai : %d jours)', 'printgestion'), $status['before_days'])) . "</li>"
            . "<li>" . $badge($status['notifications_active'] > 0) . ' ' . $esc(sprintf(__('Notifications de contrat actives : %1$d / %2$d', 'printgestion'), $status['notifications_active'], $status['notifications_total'])) . "</li>"
            . "</ul>";
        if (Session::haveRight('config', UPDATE)) {
            echo "<button type='submit' name='activate_contract_alerts' value='1' class='btn btn-outline-primary'>"
                . "<i class='ti ti-bell-ringing me-1'></i>" . $esc(__('Activer les alertes de contrat natives', 'printgestion')) . "</button>";
        }
        echo "</div></div>";
    }
}
