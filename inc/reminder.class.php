<?php
/**
 * PluginPrintgestionReminder — tâches cron GLPI (pattern plugin Gestion).
 *
 * 4 tâches :
 *   - PrintgestionSnapshotReadings : snapshot + détection changements cartouches
 *   - PrintgestionCheckAlerts      : calcul alertes intelligent + envoi mails + rappels installation
 *   - PrintgestionTrackingUpdate   : (Phase 3) maj suivi transporteurs + BL plugin Gestion
 *   - PrintgestionProposeDemandes  : demandes d'envoi proposées à partir des alertes
 *                                    (enregistrée désactivée)
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionReminder extends CommonGLPI {

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
                return ['description' => __('Print Gestion - Suivi des colis GLS (et rattrapage des BL signés du plugin Gestion)', 'printgestion')];
            case 'PrintgestionProposeDemandes':
                return ['description' => __('Print Gestion - Proposition des demandes d\'envoi à partir des alertes toner', 'printgestion')];
            case 'PrintgestionTemoinCron':
                return ['description' => __('Print Gestion - Témoin du cron système : ne fait rien d\'autre que dater son passage (mode CLI seulement)', 'printgestion')];
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
     * Cron 2 : calcul alertes + envoi mails + rappels installation. Aucune réattribution
     * automatique « mauvaise imprimante » : elle reste une décision humaine.
     */
    static function cronPrintgestionCheckAlerts(CronTask $task = null) {
        if (!PluginPrintgestionConfig::isFeatureEnabled('toner')) {
            return 0; // module désactivé
        }
        // BL signés du plugin Gestion → expéditions livrées. Ici, dans la tâche horaire, et non dans celle des
        // transporteurs : c'est du SQL local, sans réseau ni quota, rien ne justifie de lui imposer la cadence des
        // appels aux transporteurs. Les écrans Expéditions et Alertes le font déjà à leur ouverture ; ce passage
        // garantit une heure au pire, y compris là où personne n'ouvre d'écran.
        $delivered = PluginPrintgestionTracking::onGestionBlSigned();

        $alerts_sent    = PluginPrintgestionAlert::sendPendingAlerts();
        $reminders_sent = PluginPrintgestionExpedition::sendInstallReminders();
        // Avancement des expéditions (poses détectées, réattributions) → demandes d'envoi.
        PluginPrintgestionDemande::syncFromExpeditions();
        // Commandes enregistrées mais non transmises aux Achats depuis plus de STALE_HOURS heures : notification.
        $not_sent = PluginPrintgestionPurchaseorder::notifyStale();
        if ($task !== null && $delivered > 0) {
            $task->log(sprintf('BL signés repris : %d expédition(s) passée(s) en « livrée ».', $delivered));
        }
        if ($task !== null && $not_sent['stale'] > 0) {
            $task->log(sprintf(
                'Commandes non transmises aux Achats depuis plus de %d h : %d — notifiées : %d — non notifiées : %d',
                PluginPrintgestionPurchaseorder::STALE_HOURS,
                $not_sent['stale'],
                $not_sent['notified'],
                $not_sent['errors']
            ));
        }
        // Relance native des demandes qui traînent (proposées ou validées non exportées).
        $demande_reminders = PluginPrintgestionDemande::sendReminders();
        if ($task !== null && $demande_reminders > 0) {
            $task->log("Relances de demandes d'envoi : {$demande_reminders}");
        }

        // Invalide le cache dashboard alerts — force un recalcul frais au prochain fetch
        PluginPrintgestionAlert::invalidateCache();
        // Matérialise les alertes pour le tableau Search natif (Phase 3).
        $materialized = PluginPrintgestionAlertview::rebuild();
        // Vues « Sondes » et « Imprimantes collectées » (état de la collecte, sondes) : même cadence.
        if (PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
            PluginPrintgestionCollectview::rebuild();
        }

        if ($task !== null) {
            $task->addVolume($alerts_sent + $reminders_sent);
            $task->log("Alertes: {$alerts_sent} — Rappels: {$reminders_sent}");
        }

        return ($alerts_sent > 0 || $reminders_sent > 0) ? 1 : 0;
    }

    /**
     * Témoin du cron système. Née en mode CLI, sans autre mode possible, toutes les minutes : sans cron système
     * elle ne tourne jamais et ne coûte rien ; avec, sa dernière exécution prouve en quelques minutes que le cron
     * système passe (carte « Santé de la configuration »). Elle ne fait rien d'autre.
     */
    static function cronPrintgestionTemoinCron(CronTask $task = null) {
        if ($task !== null) {
            $task->log(__('Passage du cron système constaté.', 'printgestion'));
        }
        return 1;
    }

    /**
     * Cron 3 : les deux suivis transporteur, GLS puis MBE, et le rattrapage des BL signés du plugin Gestion.
     *
     * L'ordre compte. GLS passe d'abord et range ce que le transporteur a publié sur l'expédition ; MBE passe ensuite
     * et **relit ce statut** pour recouper le sien, qui reste `WAITING_DELIVERY` des jours après une remise déjà
     * faite. Le double contrôle ne coûte donc aucun appel de plus : il lit ce que le passage précédent vient d'écrire.
     *
     * Le passage en « livrée » sur BL signé ne dépend pas de ce cron : le plugin Gestion appelle Print Gestion
     * directement à la signature. Le rattrapage reste pour ce qu'aucun clic ne déclenche — BL importé déjà signé,
     * `signed` basculé directement en base, appel direct en échec.
     *
     * Cadence : deux passages par jour suffisent aux deux suivis (fréquence enregistrée à douze heures). GLS s'en
     * tient à son quota, MBE garde en plus son propre délai minimal entre deux passages : remettre la tâche à l'heure
     * dans GLPI n'épuise pas le quota MBE de 500 appels par jour.
     */
    static function cronPrintgestionTrackingUpdate(CronTask $task = null) {
        if (!PluginPrintgestionConfig::isFeatureEnabled('toner')) {
            return 0; // module désactivé
        }

        // 1. Dernier filet pour les BL signés : les écrans les reprennent à leur ouverture, et la tâche horaire
        //    les rattrape déjà. Ce passage ne sert qu'à ne rien laisser derrière.
        $updates = PluginPrintgestionTracking::onGestionBlSigned();

        // 2. Suivi des colis GLS : range ce que GLS publie, et constate une remise au destinataire (DELIVERED).
        $gls = PluginPrintgestionGlstracking::poll($task);

        // 3. Suivi MBE : apparie ce qui ne l'est pas (par n° de BL, puis par n° transporteur), lit son statut et le
        //    recoupe avec celui que GLS vient d'écrire.
        $mbe = PluginPrintgestionMbetracking::poll($task);

        if ($task !== null) {
            $task->log(sprintf(
                'BL signés rattrapés : %d — livraisons constatées : %d par GLS, %d par MBE.',
                $updates,
                $gls['delivered'],
                $mbe['delivered']
            ));
        }
        return ($updates + $gls['checked'] + $mbe['checked'] + $mbe['matched']) > 0 ? 1 : 0;
    }

    /**
     * Cron 4 : demandes d'envoi PROPOSÉES à partir des alertes toner, regroupées par
     * client et site de livraison (PluginPrintgestionDemande::proposeFromAlerts()).
     * Enregistrée désactivée : une ligne proposée bloque la commande de sa cartouche
     * depuis l'écran des alertes jusqu'à son export ou son annulation — à activer une
     * fois l'export des demandes validées en service.
     * Un groupe en échec termine la tâche en erreur (exception), jamais en succès.
     */
    static function cronPrintgestionProposeDemandes(?CronTask $task = null) {
        if (!PluginPrintgestionConfig::isFeatureEnabled('toner')) {
            return 0; // module désactivé
        }
        $stats = PluginPrintgestionDemande::proposeFromAlerts();

        if ($task !== null) {
            $task->addVolume($stats['lines_added']);
            $task->log(sprintf(
                'Demandes créées : %d — Lignes proposées : %d (dont référence non résolue : %d) — Écartés (annulés < %d j) : %d — À surveiller en attente : %d — Groupes en échec : %d',
                $stats['demandes_created'],
                $stats['lines_added'],
                $stats['unresolved'],
                PluginPrintgestionDemande::RECENT_CANCEL_DAYS,
                $stats['recently_cancelled'],
                $stats['deferred'],
                $stats['failed_groups']
            ));
        }

        // Un groupe en échec n'est jamais un passage normal : les groupes réussis restent enregistrés (une
        // transaction par groupe), mais la tâche se termine en « Erreur d'exécution », avec la notification
        // native d'erreur de tâche. Détail de chaque groupe dans le journal printgestion.
        if ($stats['failed_groups'] > 0) {
            throw new RuntimeException(sprintf(
                'Proposition des demandes d\'envoi : %d groupe(s) client/site en échec, aucune ligne créée pour eux (détail : journal printgestion). %d ligne(s) proposée(s) pour les autres groupes.',
                $stats['failed_groups'],
                $stats['lines_added']
            ));
        }

        return $stats['lines_added'] > 0 ? 1 : 0;
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
            // Valeur initiale seulement, ensuite réglée dans GLPI : deux passages par jour suffisent aux deux suivis
            // (GLS et MBE), et le quota MBE de 500 appels par jour ne supporterait pas un passage horaire.
            12 * HOUR_TIMESTAMP,
            ['state' => CronTask::STATE_WAITING]
        );
        // Horaire, enregistrée DÉSACTIVÉE (voir cronPrintgestionProposeDemandes).
        // Témoin du cron système : CLI seulement (aucune bascule possible), chaque minute.
        CronTask::Register(
            self::class,
            'PrintgestionTemoinCron',
            MINUTE_TIMESTAMP,
            ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL, 'allowmode' => CronTask::MODE_EXTERNAL]
        );
        // Register() ne modifie pas une tâche existante : l'état choisi par
        // l'administrateur est conservé aux mises à jour.
        CronTask::Register(
            self::class,
            'PrintgestionProposeDemandes',
            HOUR_TIMESTAMP,
            ['state' => CronTask::STATE_DISABLE]
        );
        // Déploiement Agent : dernière version de GLPI Agent publiée sur GitHub.
        CronTask::Register(
            'PluginPrintgestionAgentsetting',
            'PrintgestionCheckAgentVersion',
            WEEK_TIMESTAMP,
            ['state' => CronTask::STATE_WAITING]
        );
        // Déploiement Agent : sondes sans contact et imprimantes qui ne remontent plus.
        CronTask::Register(
            'PluginPrintgestionAgentalert',
            'PrintgestionSilentProbes',
            DAY_TIMESTAMP,
            ['state' => CronTask::STATE_WAITING]
        );
        // Déploiement Agent : fréquence des relevés d'imprimantes par entité (tâches GLPI Inventory).
        CronTask::Register(
            'PluginPrintgestionCollectfrequency',
            'PrintgestionCollectSchedule',
            15 * MINUTE_TIMESTAMP,
            ['state' => CronTask::STATE_WAITING]
        );
        // Déploiement Agent : raccordements lancés — découverte terminée, relevé des niveaux à préparer.
        // Sans elle, cette suite n'arrivait que si quelqu'un ouvrait l'écran du raccordement.
        CronTask::Register(
            'PluginPrintgestionRaccordement',
            'PrintgestionRaccordements',
            10 * MINUTE_TIMESTAMP,
            ['state' => CronTask::STATE_WAITING]
        );
        return true;
    }

    static function uninstall(Migration $migration) {
        foreach (['PrintgestionSnapshotReadings', 'PrintgestionCheckAlerts', 'PrintgestionTrackingUpdate', 'PrintgestionProposeDemandes'] as $cron) {
            $task = new CronTask();
            if ($task->getFromDBbyName(self::class, $cron)) {
                $task->delete(['id' => $task->getID()]);
            }
        }
        return true;
    }
}
