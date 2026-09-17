"""Commandes et tâches : jamais de succès annoncé sur un échec, jamais de commande envoyée sans être enregistrée.

1. Tâche PrintgestionProposeDemandes : un groupe client/site en échec (panne simulée par un déclencheur sur la base
   de test) termine la tâche en « Erreur d'exécution », le détail est journalisé, les autres groupes sont proposés.
2. Commande directe, serveur mail en panne : commande ENREGISTRÉE (expédition, fichier archivé, verrou) mais « non
   transmise », signalée ; recommander est refusé ; notification native au-delà de 4 h, une seule fois ; renvoi du
   même fichier une fois le serveur rétabli ; un second renvoi n'envoie rien.
3. Export de demandes validées, serveur mail en panne : demande exportée, transmission en échec, renvoi.
"""
import sys
import time

import config

import donnees as d
import lib
from lib import WEB, constat, lignes, ok_ko, section, sql, valeur, verifier

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


EXP = "glpi_plugin_printgestion_expeditions"
ORDRES = "glpi_plugin_printgestion_purchaseorders"


def smtp(port):
    sql(f"UPDATE glpi_configs SET value = '{port}' WHERE context = 'core' AND name = 'smtp_port';")


def mails_achats(depuis):
    return [(m["sujet"], m["fichiers"]) for m in lib.mails_depuis(depuis) if "Commande cartouches" in m["sujet"]]


def commander():
    ligne = valeur(f"SELECT id FROM glpi_plugin_printgestion_alertview WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack'")
    WEB.action_de_masse("PluginPrintgestionAlertview", [ligne], "pg_order", "PluginPrintgestionAlertview")
    return WEB.messages()


def renvoyer(ordre):
    WEB.post(config.FRONT + "/purchaseorder.form.php", [("id", str(ordre)), ("resend", "1")])
    return WEB.messages()


def scenario_commande_non_transmise():
    section("2. Commande directe, serveur mail en panne : enregistrée, signalée, renvoyée")
    lib.connecter_admin()
    sql(f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';")
    lib.tache("PrintgestionCheckAlerts")
    ordres_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {ORDRES}"))
    smtp(2599)
    try:
        depuis = time.time()
        message = commander()
        time.sleep(1)
        ordre = lignes(f"SELECT id, status, attempts, documents_id FROM {ORDRES} WHERE id > {ordres_avant}")
        envois = valeur(f"SELECT COUNT(*) FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack'")
        verifier("commande enregistrée malgré la panne : une expédition, une transmission en échec avec son fichier archivé",
                 (envois, [(o[1], o[2], o[3] != "0") for o in ordre]), ("1", [("failed", "1", True)]))
        verifier("aucun mail aux Achats, message « ENREGISTRÉE mais NON TRANSMISE »", (mails_achats(depuis), "NON TRANSMISE" in message), ([], True))
        if not ordre:
            return
        ordre_id = int(ordre[0][0])
        _, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
        constat("écran Expéditions : carte « Commandes non transmises aux Achats », « Renvoyer aux Achats » et délai de notification affiché (4 h)",
                ok_ko("Commandes non transmises aux Achats" in page and "Renvoyer aux Achats" in page and "4 h après" in page))
        commander()
        verifier("recommander la même cartouche : refusé par le verrou, aucune seconde expédition",
                 valeur(f"SELECT COUNT(*) FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack'"), "1")

        sql(f"UPDATE {ORDRES} SET date_creation = date_creation - INTERVAL 5 HOUR WHERE id = {ordre_id};")  # heure de GLPI, pas NOW() de la base
        file_avant = int(valeur("SELECT IFNULL(MAX(id), 0) FROM glpi_queuednotifications"))
        requete = ("SELECT recipient FROM glpi_queuednotifications WHERE id > {} AND itemtype = 'PluginPrintgestionPurchaseorder' "
                   f"AND items_id = {ordre_id} ORDER BY recipient")
        lib.tache("PrintgestionCheckAlerts")
        premier = [r[0] for r in lignes(requete.format(file_avant))]
        file_milieu = int(valeur("SELECT IFNULL(MAX(id), 0) FROM glpi_queuednotifications"))
        lib.tache("PrintgestionCheckAlerts")
        second = [r[0] for r in lignes(requete.format(file_milieu))]
        verifier("non transmise depuis plus de 4 h : notification native à l'administrateur et à l'auteur, rien au passage suivant",
                 (premier, second, valeur(f"SELECT date_notified IS NOT NULL FROM {ORDRES} WHERE id = {ordre_id}")),
                 (["admin@exemple.test", "glpi@exemple.test"], [], "1"))

        message = renvoyer(ordre_id)
        verifier("renvoi serveur toujours en panne : échec signalé, commande toujours enregistrée",
                 (valeur(f"SELECT CONCAT(status, '/', attempts) FROM {ORDRES} WHERE id = {ordre_id}"), "en échec" in message), ("failed/2", True))

        # Réponse du serveur mail rendue telle quelle par GLPI : un nom d'hôte contenant une balise doit ressortir
        # échappé. Le lecteur de messages retire les balises puis désencode : « <b>pgtest » n'y survit qu'échappé
        # (PHP tronque l'hôte au premier « / », d'où une balise ouvrante seule dans le message).
        sql("UPDATE glpi_configs SET value = '<b>pgtest</b>.exemple.test' WHERE context = 'core' AND name = 'smtp_host';")
        try:
            message = renvoyer(ordre_id)
        finally:
            sql("UPDATE glpi_configs SET value = '127.0.0.1' WHERE context = 'core' AND name = 'smtp_host';")
        constat("erreur du serveur mail affichée échappée (nom d'hôte avec balise)", ok_ko("<b>pgtest" in message), message[:160])

        smtp(config.SMTP_PORT)
        nom = valeur(f"SELECT filename FROM glpi_documents WHERE id = {ordre[0][3]}")
        depuis = time.time()
        message = renvoyer(ordre_id)
        time.sleep(1)
        mails = mails_achats(depuis)
        verifier("serveur rétabli, « Renvoyer aux Achats » : transmise, un mail avec le fichier archivé sous son nom d'origine",
                 (valeur(f"SELECT status FROM {ORDRES} WHERE id = {ordre_id}"), [f for _, f in mails]), ("sent", [[nom]]))
        depuis = time.time()
        renvoyer(ordre_id)
        time.sleep(1)
        verifier("second renvoi : rien n'est renvoyé", (mails_achats(depuis), valeur(f"SELECT attempts FROM {ORDRES} WHERE id = {ordre_id}")), ([], "4"))  # 4 : dont le renvoi du contrôle d'échappement
        _, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
        constat("carte des commandes non transmises disparue une fois transmise", ok_ko("Commandes non transmises aux Achats" not in page))
    finally:
        smtp(config.SMTP_PORT)
        documents = [o[0] for o in lignes(f"SELECT documents_id FROM {ORDRES} WHERE id > {ordres_avant}")]
        sql(f"DELETE FROM glpi_queuednotifications WHERE itemtype = 'PluginPrintgestionPurchaseorder';"
            f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';"
            f"DELETE FROM {ORDRES} WHERE id > {ordres_avant};")
        if documents:
            sql(f"DELETE FROM glpi_documents_items WHERE documents_id IN ({', '.join(documents)}); DELETE FROM glpi_documents WHERE id IN ({', '.join(documents)});")
        lib.tache("PrintgestionCheckAlerts")


def scenario_export_non_transmis():
    section("3. Export de demandes validées, serveur mail en panne")
    lib.connecter_admin()
    ordres_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {ORDRES}"))
    demandes_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {DEMANDES}"))
    try:
        sql(f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';"
            f"INSERT INTO {DEMANDES} (name, entities_id, locations_id, statut, delivery_mode, users_id_validate, date_validate, date_creation, date_mod) "
            f"VALUES ('Demande test transmission', {d.RACINE}, {d.LIEU}, 'validated', 'direct', {d.ADMIN_ID}, NOW(), NOW(), NOW());")
        demande = int(valeur(f"SELECT MAX(id) FROM {DEMANDES} WHERE name = 'Demande test transmission'"))
        sql(f"INSERT INTO {LIGNES} (plugin_printgestion_demandes_id, printers_id, toner_property, cartridgeitems_id, quantity, is_under_contract, "
            "contracts_id, level_at_proposal, statut, date_creation, date_mod, entities_id) "
            f"VALUES ({demande}, {d.IMP_BAS}, 'tonerblack', {d.CARTOUCHE_NOIR}, 1, 1, {d.CONTRAT_RACINE}, 12, 'validated', NOW(), NOW(), {d.RACINE});")
        smtp(2599)
        WEB.post(config.FRONT + "/demande.export.php", [("demandes[]", demande), ("send", "1")])
        message = WEB.messages()
        ordre = lignes(f"SELECT id, status, source FROM {ORDRES} WHERE id > {ordres_avant}")
        verifier("export enregistré malgré la panne : demande exportée, transmission « export » en échec, message affiché",
                 (valeur(f"SELECT statut FROM {DEMANDES} WHERE id = {demande}"), [(o[1], o[2]) for o in ordre], "NON TRANSMISE" in message),
                 ("exported", [("failed", "export")], True))
        _, page, _ = WEB.get(config.FRONT + "/demande.php")
        constat("écran Demandes d'envoi : carte des commandes non transmises", ok_ko("Commandes non transmises aux Achats" in page))
        if ordre:
            smtp(config.SMTP_PORT)
            depuis = time.time()
            renvoyer(int(ordre[0][0]))
            time.sleep(1)
            verifier("renvoi une fois le serveur rétabli : transmis, un mail aux Achats",
                     (valeur(f"SELECT status FROM {ORDRES} WHERE id = {ordre[0][0]}"), len(mails_achats(depuis))), ("sent", 1))
    finally:
        smtp(config.SMTP_PORT)
        documents = [o[0] for o in lignes(f"SELECT documents_id FROM {ORDRES} WHERE id > {ordres_avant}")]
        sql(f"DELETE FROM glpi_queuednotifications WHERE itemtype IN ('PluginPrintgestionPurchaseorder', 'PluginPrintgestionDemande');"
            f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';"
            f"DELETE FROM {ORDRES} WHERE id > {ordres_avant};"
            f"DELETE FROM {LIGNES} WHERE plugin_printgestion_demandes_id > {demandes_avant}; DELETE FROM {DEMANDES} WHERE id > {demandes_avant};")
        if documents:
            sql(f"DELETE FROM glpi_documents_items WHERE documents_id IN ({', '.join(documents)}); DELETE FROM glpi_documents WHERE id IN ({', '.join(documents)});")
        lib.tache("PrintgestionCheckAlerts")


def main():
    d.verifier_instance()
    for scenario in (scenario_proposition, scenario_commande_non_transmise, scenario_export_non_transmis):
        try:
            scenario()
        except Exception as erreur:  # constat du test, la suite continue
            constat(f"{scenario.__name__} interrompu", "NON CONCLUANT", repr(erreur)[:300])
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
