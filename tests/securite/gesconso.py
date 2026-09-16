"""Commande aux Achats : jamais sans le fichier Gesconso joint.

1. Type de document .xlsx refusé par GLPI (glpi_documenttypes.is_uploadable = 0), commande directe (action de masse
   « Commander ») : refusée, aucun envoi, aucun document, aucun mail aux Achats, cause affichée.
2. Même cas, export de demandes validées (« Envoyer aux Achats ») : refusé, demande restée validée, aucun mail.
3. Type .xlsx autorisé : commande passée, mail aux Achats avec le fichier Gesconso .xlsx joint.
Remet en place le type de document et supprime ce qu'il crée.
"""
import sys
import time

import config
import donnees as d
import lib
from lib import WEB, lignes, section, sql, valeur, verifier

EXP = "glpi_plugin_printgestion_expeditions"
CTX = lib.Contexte()


def commander():
    ligne = valeur(f"SELECT id FROM glpi_plugin_printgestion_alertview WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack'")
    WEB.action_de_masse("PluginPrintgestionAlertview", [ligne], "pg_order", "PluginPrintgestionAlertview")


def repartir():
    """Aucun envoi en cours sur l'imprimante au noir bas, alertes recalculées : chaque scénario part du même état."""
    sql(f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';")
    lib.tache("PrintgestionCheckAlerts")


def mails_achats(depuis):
    return [(m["sujet"], m["fichiers"]) for m in lib.mails_depuis(depuis) if "Commande cartouches" in m["sujet"]]


def etat():
    return {"envois": valeur(f"SELECT COUNT(*) FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack'"),
            "documents": valeur("SELECT COUNT(*) FROM glpi_documents")}


def main():
    d.verifier_instance()
    xlsx = lignes("SELECT id, is_uploadable FROM glpi_documenttypes WHERE ext = 'xlsx'")
    documents_avant = valeur("SELECT IFNULL(MAX(id), 0) FROM glpi_documents")
    try:
        lib.connecter_admin()

        section("1. Commande directe, .xlsx refusé par GLPI")
        sql("UPDATE glpi_documenttypes SET is_uploadable = 0 WHERE ext = 'xlsx';")
        repartir()
        avant, depuis = etat(), time.time()
        commander()
        message = WEB.messages()
        time.sleep(1)
        verifier("commande refusée : aucun envoi, aucun document", etat(), avant)
        verifier("aucun mail aux Achats", mails_achats(depuis), [])
        verifier("cause affichée (type de document .xlsx non autorisé)", "xlsx" in message.lower() and "pas autoris" in message.lower(), True)

        section("2. Export de demandes validées, .xlsx refusé par GLPI")
        repartir()
        sql("INSERT INTO glpi_plugin_printgestion_demandes (name, entities_id, locations_id, statut, delivery_mode, users_id_validate, date_validate, date_creation, date_mod) "
            f"VALUES ('Demande test Gesconso', {d.RACINE}, {d.LIEU}, 'validated', 'direct', {d.ADMIN_ID}, NOW(), NOW(), NOW());")
        demande = int(valeur("SELECT MAX(id) FROM glpi_plugin_printgestion_demandes WHERE name = 'Demande test Gesconso'"))
        CTX.crees["demandes"].append(demande)
        sql("INSERT INTO glpi_plugin_printgestion_demandelines (plugin_printgestion_demandes_id, printers_id, toner_property, cartridgeitems_id, quantity, is_under_contract, "
            "contracts_id, level_at_proposal, statut, date_creation, date_mod, entities_id) "
            f"VALUES ({demande}, {d.IMP_BAS}, 'tonerblack', {d.CARTOUCHE_NOIR}, 1, 1, {d.CONTRAT_RACINE}, 12, 'validated', NOW(), NOW(), {d.RACINE});")
        avant, depuis = etat(), time.time()
        WEB.post(config.FRONT + "/demande.export.php", [("demandes[]", demande), ("send", "1")])
        message = WEB.messages()
        time.sleep(1)
        verifier("export refusé : demande restée validée, aucun envoi, aucun document",
                 (valeur(f"SELECT statut FROM glpi_plugin_printgestion_demandes WHERE id = {demande}"), etat()), ("validated", avant))
        verifier("aucun mail aux Achats (export)", mails_achats(depuis), [])
        verifier("cause affichée (export)", "xlsx" in message.lower() and "pas autoris" in message.lower(), True)

        section("3. Commande directe, .xlsx autorisé")
        # La demande validée du scénario 2 bloquerait la commande (verrou anti-double-envoi) : retirée d'abord.
        sql(f"UPDATE glpi_documenttypes SET is_uploadable = 1 WHERE ext = 'xlsx'; DELETE FROM glpi_plugin_printgestion_demandelines WHERE plugin_printgestion_demandes_id = {demande};"
            f"DELETE FROM glpi_plugin_printgestion_demandes WHERE id = {demande};")
        repartir()
        avant, depuis = etat(), time.time()
        commander()
        time.sleep(1)
        mails = mails_achats(depuis)
        print(f"    message : {WEB.messages()[:300]}")
        apres = etat()
        verifier("commande passée : un envoi et un document archivé",
                 (int(apres["envois"]) - int(avant["envois"]), int(apres["documents"]) - int(avant["documents"])), (1, 1))
        verifier("mail aux Achats avec le fichier Gesconso .xlsx joint", any(any((f or "").endswith(".xlsx") for f in fichiers) for _, fichiers in mails), True)
    finally:
        for ident, autorise in xlsx:
            sql(f"UPDATE glpi_documenttypes SET is_uploadable = {autorise} WHERE id = {ident};")
        sql(f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';"
            f"DELETE FROM glpi_documents_items WHERE documents_id > {documents_avant}; DELETE FROM glpi_documents WHERE id > {documents_avant};")
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
