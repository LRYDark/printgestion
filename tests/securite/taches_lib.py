"""Aides communes aux recettes : tâches automatiques du plugin et état de leur dernier passage."""
import lib
from lib import lignes, valeur

TACHES = ["PrintgestionSnapshotReadings", "PrintgestionCheckAlerts", "PrintgestionTrackingUpdate", "PrintgestionProposeDemandes",
          "PrintgestionTemoinCron", "PrintgestionCheckAgentVersion", "PrintgestionSilentProbes", "PrintgestionCollectSchedule",
          "PrintgestionEntityScope"]


def dernier_passage(tache):
    """États (3 = erreur) et messages du dernier passage de la tâche."""
    debut = valeur("SELECT MAX(l.id) FROM glpi_crontasklogs l JOIN glpi_crontasks t ON t.id = l.crontasks_id "
                   f"WHERE t.name = '{tache}' AND l.crontasklogs_id = 0")
    if not debut or debut == "NULL":
        return [], ""
    rangs = lignes(f"SELECT state, content FROM glpi_crontasklogs WHERE id = {debut} OR crontasklogs_id = {debut} ORDER BY id")
    return [int(r[0]) for r in rangs], " | ".join(lib.decoder(r[1]) for r in rangs)


def passer_toutes():
    """Force chaque tâche du plugin ; rend {tâche: (en erreur ?, messages)} et les journaux PHP/SQL écrits pendant."""
    tailles = lib.tailles_journaux()
    resultats = {}
    for tache in TACHES:
        lib.tache(tache)
        etats, messages = dernier_passage(tache)
        resultats[tache] = (3 in etats, messages[:160])
    journal = lib.journal_depuis(tailles, "php-errors.log") + lib.journal_depuis(tailles, "sql-errors.log")
    return resultats, journal
