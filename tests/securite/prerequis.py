"""Prérequis GLPI Inventory : une version plus ancienne bloque, une version plus récente avertit seulement ; et une
colonne attendue qui disparaît de sa table des tâches produit une erreur visible, jamais un silence.

La version réelle ne se simule pas en base (GLPI désactive un plugin dont la version enregistrée diffère de ses
fichiers) : la règle est vérifiée sur PluginPrintgestionCollectsetup::checkVersion(), celle qu'appelle l'assistant,
puis sur la version réellement installée sur l'instance de test.
"""
import json
import sys

import donnees as d
import lib
from lib import section, verifier


def controle(version):
    sortie = lib.php_glpi(f"echo json_encode(PluginPrintgestionCollectsetup::checkVersion({json.dumps(version)}));")
    resultat = json.loads(sortie)
    return (len(resultat["blocking"]), len(resultat["warnings"]))


def main():
    d.verifier_instance()
    section("Borne de version de GLPI Inventory")
    verifier("1.5.9 (plus ancienne que le minimum) : bloquant", controle("1.5.9"), (1, 0))
    verifier("1.6.0 (minimum) : accepté sans remarque", controle("1.6.0"), (0, 0))
    verifier("1.6.10 (version validée) : accepté sans remarque", controle("1.6.10"), (0, 0))
    verifier("1.6.12 (plus récente que la validée) : avertissement seulement", controle("1.6.12"), (0, 1))
    verifier("1.7.0 (nouvelle série) : avertissement seulement, jamais bloquant", controle("1.7.0"), (0, 1))
    installee = json.loads(lib.php_glpi("echo json_encode(PluginPrintgestionCollectsetup::getPrerequisites());"))
    verifier(f"version installée sur l'instance de test ({installee['version']}) : aucun blocage", installee["blocking"], [])

    section("2. Colonne de GLPI Inventory disparue : erreur visible, jamais un silence")
    lib.connecter_admin()
    erreur = lib.php_glpi("echo CronTaskLog::STATE_ERROR;").strip()
    dernier = lambda: lib.valeur("SELECT l.state FROM glpi_crontasklogs l JOIN glpi_crontasks t ON t.id = l.crontasks_id "  # noqa: E731
                                 "WHERE t.name = 'PrintgestionCollectSchedule' ORDER BY l.id DESC LIMIT 1")
    tailles = lib.tailles_journaux()
    lib.sql("ALTER TABLE glpi_plugin_glpiinventory_tasks CHANGE datetime_start datetime_start_pgtest timestamp NULL DEFAULT NULL;")
    try:
        lib.tache("PrintgestionCollectSchedule")
        journal = lib.journal_depuis(tailles, "printgestion.log")
        verifier("tâche automatique en erreur (état natif de GLPI) et journal du plugin explicite",
                 (dernier() == erreur, "n'a plus la colonne « datetime_start »" in journal), (True, True))
        _, page, _ = lib.WEB.get("/ajax/common.tabs.php?_target=%2Ffront%2Fentity.form.php&_itemtype=Entity&_glpi_tab=PluginPrintgestionAgentdeploy%241&id=" + str(d.CLIENT_A), ajax=True)
        lib.constat("onglet de l'entité (administrateur) : « Fréquence des relevés hors service », page servie",
                    lib.ok_ko("hors service" in page and "Rattachement" in page))
    finally:
        lib.sql("ALTER TABLE glpi_plugin_glpiinventory_tasks CHANGE datetime_start_pgtest datetime_start timestamp NULL DEFAULT NULL;")
    lib.tache("PrintgestionCollectSchedule")
    verifier("colonne rétablie : la tâche repasse en fonctionnement normal", dernier() != erreur, True)
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
