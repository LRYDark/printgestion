"""Commandes et tâches : jamais de succès annoncé sur un échec.

1. Tâche PrintgestionProposeDemandes : un groupe client/site en échec (panne simulée par un déclencheur sur la base
   de test) termine la tâche en « Erreur d'exécution », le détail est journalisé, les autres groupes sont proposés.
"""
import sys

import donnees as d
import lib
from lib import lignes, section, sql, valeur, verifier

DEMANDES = "glpi_plugin_printgestion_demandes"
LIGNES = "glpi_plugin_printgestion_demandelines"


def dernier_passage(tache):
    """État (3 = erreur) et messages du dernier passage de la tâche."""
    debut = valeur("SELECT MAX(l.id) FROM glpi_crontasklogs l JOIN glpi_crontasks t ON t.id = l.crontasks_id "
                   f"WHERE t.name = '{tache}' AND l.crontasklogs_id = 0")
    rangs = lignes(f"SELECT state, content FROM glpi_crontasklogs WHERE id = {debut} OR crontasklogs_id = {debut} ORDER BY id")
    return [int(r[0]) for r in rangs], " | ".join(lib.decoder(r[1]) for r in rangs)


def scenario_proposition():
    section("1. Tâche de proposition : un groupe en échec n'est jamais un succès")
    lib.connecter_admin()
    niveaux = {imp: valeur(f"SELECT value FROM glpi_printers_cartridgeinfos WHERE printers_id = {imp} AND property = 'tonerblack'")
               for imp in (d.IMP_A1, d.IMP_SITE_A1)}
    # Le niveau d'alerte est celui du dernier relevé du plugin : relevé du jour abaissé, remis en place à la fin.
    releves = {imp: lignes(f"SELECT id, level_percent FROM glpi_plugin_printgestion_toner_readings WHERE printers_id = {imp} "
                           "AND property_name = 'tonerblack' ORDER BY reading_date DESC LIMIT 1")[0] for imp in (d.IMP_A1, d.IMP_SITE_A1)}
    demandes_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {DEMANDES}"))
    etat_tache = valeur("SELECT state FROM glpi_crontasks WHERE name = 'PrintgestionProposeDemandes'")
    try:
        sql(f"UPDATE glpi_printers_cartridgeinfos SET value = '5' WHERE printers_id IN ({d.IMP_A1}, {d.IMP_SITE_A1}) AND property = 'tonerblack';"
            "UPDATE glpi_crontasks SET state = 1 WHERE name = 'PrintgestionProposeDemandes';"
            f"UPDATE glpi_plugin_printgestion_toner_readings SET level_percent = 5 WHERE id IN ({', '.join(r[0] for r in releves.values())});"
            "DROP TRIGGER IF EXISTS pgtest_panne_demande;")
        sql("DELIMITER //\n"
            f"CREATE TRIGGER pgtest_panne_demande BEFORE INSERT ON {DEMANDES} FOR EACH ROW "
            f"BEGIN IF NEW.entities_id = {d.CLIENT_A} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'panne simulee'; END IF; END //\n"
            "DELIMITER ;")
        lib.tache("PrintgestionCheckAlerts")
        tailles = lib.tailles_journaux()
        lib.tache("PrintgestionProposeDemandes")
        etats, messages = dernier_passage("PrintgestionProposeDemandes")
        journal = lib.journal_depuis(tailles, "printgestion.log")
        verifier("tâche terminée en « Erreur d'exécution » (état 3), bilan « Groupes en échec : 1 » conservé",
                 (3 in etats, "Groupes en échec : 1" in messages), (True, True))
        verifier("journal printgestion : groupe en échec nommé (entité Client test A)",
                 f"entité #{d.CLIENT_A}" in journal and "Proposition automatique annulée" in journal, True)
        verifier("autre groupe (Site test A1) proposé malgré l'échec, rien pour Client test A",
                 (valeur(f"SELECT COUNT(*) FROM {DEMANDES} WHERE id > {demandes_avant} AND entities_id = {d.SITE_A1}") != "0",
                  valeur(f"SELECT COUNT(*) FROM {DEMANDES} WHERE id > {demandes_avant} AND entities_id = {d.CLIENT_A}")), (True, "0"))
    finally:
        sql("DROP TRIGGER IF EXISTS pgtest_panne_demande;"
            f"DELETE FROM {LIGNES} WHERE plugin_printgestion_demandes_id > {demandes_avant}; DELETE FROM {DEMANDES} WHERE id > {demandes_avant};"
            f"UPDATE glpi_crontasks SET state = {etat_tache} WHERE name = 'PrintgestionProposeDemandes';")
        for ident, niveau in releves.values():
            sql(f"UPDATE glpi_plugin_printgestion_toner_readings SET level_percent = {niveau} WHERE id = {ident};")
        for imp, val in niveaux.items():
            sql(f"UPDATE glpi_printers_cartridgeinfos SET value = {lib.q(val)} WHERE printers_id = {imp} AND property = 'tonerblack';")
        lib.tache("PrintgestionCheckAlerts")


def main():
    d.verifier_instance()
    scenario_proposition()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
