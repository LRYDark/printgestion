<?php
/**
 * PluginPrintgestionReminder — tâches cron GLPI (pattern plugin Gestion).
 *
 * 3 tâches :
 *   - PrintgestionSnapshotReadings : snapshot + détection changements cartouches
 *   - PrintgestionCheckAlerts      : calcul alertes intelligent + envoi mails + rappels installation
 *   - PrintgestionTrackingUpdate   : (Phase 3) maj suivi transporteurs + BL plugin Gestion
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionReminder extends CommonDBTM {

    static $rightname = 'plugin_printgestion_dashboard';

    static function getTypeName($nb = 0) {
        return _n('Tâche cron Print Gestion', 'Tâches cron Print Gestion', $nb, 'printgestion');
    }

    static function cronInfo($name) {
        switch ($name) {
            case 'PrintgestionSnapshotReadings':
                return ['description' => __('Print Gestion - Snapshot relevés toner + détection cartouches', 'printgestion')];
            case 'PrintgestionCheckAlerts':
                return ['description' => __('Print Gestion - Calcul et envoi des alertes toner', 'printgestion')];
            case 'PrintgestionTrackingUpdate':
                return ['description' => __('Print Gestion - Mise à jour suivi transporteurs + BL', 'printgestion')];
        }
        return [];
    }

    /**
     * Cron 1 : snapshot SNMP + détection changements cartouches + bootstrap natif.
     */
    static function cronPrintgestionSnapshotReadings(CronTask $task = null) {
        if (!PluginPrintgestionConfig::isFeatureEnabled('toner')) {
            return 0; // module désactivé → aucune ressource consommée
        }
        $inserted   = PluginPrintgestionTonerreading::snapshotAllPrinters();
        $bootstrap  = PluginPrintgestionCartridgehistory::bootstrapNativeCartridges();
        $detected   = PluginPrintgestionCartridgehistory::detectChanges();

        if ($task !== null) {
            $task->addVolume($inserted + $bootstrap + $detected);
            $task->log("Snapshots: {$inserted} — Bootstrap natif: {$bootstrap} — Changements: {$detected}");
        }

        // Purge > 160 jours (couvre largement la fenêtre 30j + audit historique)
        PluginPrintgestionTonerreading::purgeOld(160);

        return ($inserted > 0 || $bootstrap > 0 || $detected > 0) ? 1 : 0;
    }

    /**
     * Cron 2 : calcul alertes + envoi mails + rappels installation +
     * auto-réassignation des alertes wrong_printer anciennes.
     */
    static function cronPrintgestionCheckAlerts(CronTask $task = null) {
        if (!PluginPrintgestionConfig::isFeatureEnabled('toner')) {
            return 0; // module désactivé
        }
        $alerts_sent    = PluginPrintgestionAlert::sendPendingAlerts();
        $reminders_sent = PluginPrintgestionExpedition::sendInstallReminders();
        $reassigned     = PluginPrintgestionExpedition::autoReassignStaleWrongPrinterAlerts();

        // Invalide le cache dashboard alerts — force un recalcul frais au prochain fetch
        PluginPrintgestionAlert::invalidateCache();
        // Matérialise les alertes pour le tableau Search natif (Phase 3).
        $materialized = PluginPrintgestionAlertview::rebuild();

        if ($task !== null) {
            $task->addVolume($alerts_sent + $reminders_sent + $reassigned);
            $task->log("Alertes: {$alerts_sent} — Rappels: {$reminders_sent} — "
                . "Réassignations auto: {$reassigned}");
        }

        return ($alerts_sent > 0 || $reminders_sent > 0 || $reassigned > 0) ? 1 : 0;
    }

    /**
     * Cron 3 : MAJ suivi transporteurs + BL signés (plugin Gestion).
     */
    static function cronPrintgestionTrackingUpdate(CronTask $task = null) {
        if (!PluginPrintgestionConfig::isFeatureEnabled('toner')) {
            return 0; // module désactivé
        }
        $updates = 0;

        // 1. Plugin Gestion : BL signé → expédition delivered
        $updates += PluginPrintgestionTracking::syncDeliveredFromGestion();

        // 2. API transporteurs (UPS / GLS / Chronopost)
        $updates += PluginPrintgestionTracking::refreshFromCarriers();

        if ($task !== null) {
            $task->addVolume($updates);
            $task->log("Expéditions mises à jour: {$updates}");
        }

        return $updates > 0 ? 1 : 0;
    }

    /**
     * Enregistre les crons à l'installation du plugin.
     */
    static function install(Migration $migration) {
        CronTask::Register(
            self::class,
            'PrintgestionSnapshotReadings',
            DAY_TIMESTAMP,
            ['state' => CronTask::STATE_WAITING]
        );
        CronTask::Register(
            self::class,
            'PrintgestionCheckAlerts',
            HOUR_TIMESTAMP,
            ['state' => CronTask::STATE_WAITING]
        );
        CronTask::Register(
            self::class,
            'PrintgestionTrackingUpdate',
            4 * HOUR_TIMESTAMP,
            ['state' => CronTask::STATE_WAITING]
        );
        return true;
    }

    static function uninstall(Migration $migration) {
        foreach (['PrintgestionSnapshotReadings', 'PrintgestionCheckAlerts', 'PrintgestionTrackingUpdate'] as $cron) {
            $task = new CronTask();
            if ($task->getFromDBbyName(self::class, $cron)) {
                $task->delete(['id' => $task->getID()]);
            }
        }
        return true;
    }
}
