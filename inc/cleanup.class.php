<?php

/**
 * Ce que Print Gestion avait créé pour un objet part avec lui.
 *
 * Branché sur le hook natif `item_purge` : que la suppression vienne de la liste des sondes, de la tâche native
 * « Cleanoldagents », de la fiche d'une imprimante ou du fichier de retrait lancé sur un PC, le nettoyage est le
 * même. Avant, ces lignes restaient en base, rattachées à un identifiant qui n'existait plus.
 *
 * Ce qui n'est **pas** touché, volontairement : les expéditions et les lignes de demandes d'envoi. Elles racontent
 * ce qui a été livré à un client, avec leurs documents et leurs numéros de suivi ; elles gardent leur valeur quand
 * le matériel n'est plus là, et une comptabilité ne s'efface pas parce qu'une imprimante s'en va.
 */
class PluginPrintgestionCleanup
{
    /**
     * Une sonde supprimée : ses réglages, ses alertes, et ses raccordements — avec leurs adresses et leur journal,
     * par `Raccordement::cleanDBonPurge()`, puisqu'on passe par la classe et non par la table.
     */
    public static function forAgent(CommonDBTM $agent): void
    {
        global $DB;

        $id = (int) $agent->getID();
        if ($id <= 0) {
            return;
        }
        try {
            $DB->delete(PluginPrintgestionAgentsetting::getTable(), ['agents_id' => $id]);
            $DB->delete(PluginPrintgestionAgentalert::getTable(), ['agents_id' => $id]);
            $racc = new PluginPrintgestionRaccordement();
            foreach ($racc->find(['agents_id' => $id]) as $row) {
                $racc->delete(['id' => (int) $row['id']], true);
            }
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('cleanup', sprintf('Sonde %d supprimée : ses éléments Print Gestion n\'ont pas tous été effacés.', $id), $e);
        }
    }

    /**
     * Une imprimante supprimée : tout ce que le plugin a relevé ou calculé sur elle.
     *
     * Les tables matérialisées (vue des alertes, coût à la page) sont refaites par leurs tâches automatiques ; on
     * les vide quand même tout de suite, sinon la liste montrerait une imprimante qui n'existe plus jusqu'au
     * prochain passage.
     *
     * @return int nombre de lignes effacées, pour le compte rendu
     */
    public static function forPrinter(CommonDBTM $printer): int
    {
        global $DB;

        $id = (int) $printer->getID();
        if ($id <= 0) {
            return 0;
        }
        $efface = 0;
        foreach ([
            PluginPrintgestionAlert::getTable(),
            'glpi_plugin_printgestion_alert_snoozes',
            PluginPrintgestionAlertview::getTable(),
            PluginPrintgestionBilling::getTable(),
            PluginPrintgestionCartridgehistory::getTable(),
            'glpi_plugin_printgestion_historical_yields',
            'glpi_plugin_printgestion_printer_thresholds',
            PluginPrintgestionTonerreading::getTable(),
        ] as $table) {
            try {
                if (!$DB->tableExists($table)) {
                    continue;
                }
                $DB->delete($table, ['printers_id' => $id]);
                $efface += (int) $DB->affectedRows();
            } catch (Throwable $e) {
                PluginPrintgestionLogger::error('cleanup', sprintf('Imprimante %1$d supprimée : la table %2$s n\'a pas été nettoyée.', $id, $table), $e);
            }
        }
        return $efface;
    }
}
