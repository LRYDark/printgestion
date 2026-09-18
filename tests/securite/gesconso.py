"""Commande aux Achats : jamais sans le fichier Gesconso joint.

1. Type de document .xlsx refusé par GLPI (glpi_documenttypes.is_uploadable = 0), commande directe (action de masse
   « Commander ») : refusée, aucun envoi, aucun document, aucun mail aux Achats, cause affichée.
2. Même cas, export de demandes validées (« Envoyer aux Achats ») : refusé, demande restée validée, aucun mail.
3. Type .xlsx autorisé : commande passée, mail aux Achats avec le fichier Gesconso .xlsx joint.
4. Ce qui mérite d'être vu avant l'envoi : désignation sans segment vide pour une imprimante sans lieu ; décompte des
   lignes « adresse non reconnue » et « sans lieu » sur l'écran « Envoyer aux Achats » et dans le sous-formulaire
   « Commander », liste derrière « voir » ; rien de tout cela pour une ligne sans réserve.
Remet en place le type de document et supprime ce qu'il crée.
"""
import json
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
        pieces = [contenu for m in lib.mails_depuis(depuis) if "Commande cartouches" in m["sujet"] for nom, contenu in m["pieces"].items() if nom.endswith(".xlsx")]
        texte = lib.texte_xlsx(pieces[0]) if pieces else ""
        verifier("fichier joint ouvert : les neuf en-têtes Gesconso et la ligne de la cartouche (client, intitulé, référence)",
                 [e in texte for e in ("Devis", "Intitule Client", "Intitule Livraison", "Consommable", "Designation", "Quantite", "Prix", "Fournisseur", "Complement livraison",
                                       "TSTCLI01", "Adresse test 1", "TST-REF-N01")], [True] * 12)

        section("4. À voir avant l'envoi : décompte, pas de blocage")
        def ligne(cle, imprimante):
            return {"key": cle, "label": f"Imprimante {imprimante}", "printers_id": imprimante, "cartridgeitems_id": d.CARTOUCHE_NOIR, "quantity": 1,
                    "unit_price": "10.00", "under_contract": False, "date": "2026-09-18 12:00:00", "complement": ""}
        lignes_test = [ligne("p8", d.IMP_A1), ligne("p13", d.IMP_C), ligne("p14", d.IMP_SITE_C1), ligne("p15", d.IMP_D)]
        prepare = json.loads(lib.php_glpi("echo json_encode(PluginPrintgestionGesconso::prepare(json_decode(" + json.dumps(json.dumps(lignes_test)) + ", true)), JSON_UNESCAPED_UNICODE);"))
        verifier("imprimante sans lieu : désignation « n° série # cartouche », sans segment vide",
                 prepare["rows"]["p13"]["designation"], "TSTSN0013 # Toner test noir A")
        verifier("code et intitulé lus sur la même entité : imprimante en sous-entité (Site test C1) → TSTCLIC / « Livraison test C », comme l'imprimante de TSTCLIC",
                 ((prepare["rows"]["p14"]["client"], prepare["rows"]["p14"]["livraison"]), (prepare["rows"]["p13"]["client"], prepare["rows"]["p13"]["livraison"])),
                 (("TSTCLIC", "Livraison test C"), ("TSTCLIC", "Livraison test C")))
        verifier("imprimante de Client test A : code et intitulé de la racine TSTCLI01, qui porte le code (pas les commentaires de Client test A)",
                 (prepare["rows"]["p8"]["client"], prepare["rows"]["p8"]["livraison"]), ("TSTCLI01", "Adresse test 1"))
        verifier("entité porteuse d'un code sans commentaire (TSTCLID) : ligne bloquée, le message nomme l'entité porteuse et son code",
                 ("p15" in prepare["errors"], "TSTCLID" in " ".join(prepare["errors"].get("p15", [])), "porte le code client TSTCLID" in " ".join(prepare["errors"].get("p15", []))), (True, True, True))
        verifier("notices typées : adresse non reconnue (intitulé de TSTCLIC absent du référentiel) et lieu absent, sur les lignes concernées",
                 (sorted(prepare["notices"]["address"]), sorted(prepare["notices"]["location"])), (["p13", "p14"], ["p13", "p14", "p8"]))
        sql("INSERT INTO glpi_plugin_printgestion_demandes (name, entities_id, locations_id, statut, delivery_mode, users_id_validate, date_validate, date_creation, date_mod) "
            f"VALUES ('Demande test notices', {d.CLIENT_C}, 0, 'validated', 'direct', {d.ADMIN_ID}, NOW(), NOW(), NOW());")
        demande = int(valeur("SELECT MAX(id) FROM glpi_plugin_printgestion_demandes WHERE name = 'Demande test notices'"))
        CTX.crees["demandes"].append(demande)
        sql("INSERT INTO glpi_plugin_printgestion_demandelines (plugin_printgestion_demandes_id, printers_id, toner_property, cartridgeitems_id, quantity, is_under_contract, "
            "contracts_id, level_at_proposal, statut, date_creation, date_mod, entities_id) "
            f"VALUES ({demande}, {d.IMP_C}, 'tonerblack', {d.CARTOUCHE_NOIR}, 1, 0, 0, 12, 'validated', NOW(), NOW(), {d.CLIENT_C});")
        _, page, _ = WEB.get(config.FRONT + f"/demande.export.php?demandes[]={demande}")
        verifier("écran « Envoyer aux Achats » (imprimante de TSTCLIC) : décompte des deux réserves, liste derrière « voir », demande cochable",
                 ("1 ligne avec une adresse de livraison non reconnue" in page, "1 ligne sans lieu sur l" in page,
                  f"Demande #{demande}" in page[page.find("data-pg-notices"):], f"value='{demande}' checked" in page), (True, True, True, True))
        verifier("la confirmation d'envoi rappelle le décompte", "non reconnue" in page[page.find("window.confirm"):page.find("window.confirm") + 400], True)
        # Imprimante 10 : même modèle que la 1 (cartouche résolue), sans lieu, intitulé de son entité absent du référentiel.
        alerte_10 = valeur(f"SELECT id FROM glpi_plugin_printgestion_alertview WHERE printers_id = {d.IMP_SITE_A1} AND toner_property = 'tonerblack'")
        _, sous_formulaire, _ = WEB.post("/ajax/dropdownMassiveAction.php", [("action", "PluginPrintgestionAlertview:pg_order"), (f"items[PluginPrintgestionAlertview][{alerte_10}]", alerte_10),
                                                                              ("is_deleted", "0")], ajax=True)
        verifier("sous-formulaire « Commander », imprimante sans lieu (intitulé de la racine, connu) : décompte de la seule réserve avant le clic",
                 ("adresse de livraison non reconnue" in sous_formulaire, "1 ligne sans lieu sur l" in sous_formulaire,
                  "sera refusée" in sous_formulaire), (False, True, False))
        alerte_1 = valeur(f"SELECT id FROM glpi_plugin_printgestion_alertview WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack'")
        _, sous_formulaire, _ = WEB.post("/ajax/dropdownMassiveAction.php", [("action", "PluginPrintgestionAlertview:pg_order"), (f"items[PluginPrintgestionAlertview][{alerte_1}]", alerte_1),
                                                                              ("is_deleted", "0")], ajax=True)
        verifier("sous-formulaire « Commander », imprimante avec lieu et adresse connue : aucune réserve affichée",
                 ("data-pg-notices" in sous_formulaire, "Envoyer la commande" in sous_formulaire), (False, True))
    finally:
        for ident, autorise in xlsx:
            sql(f"UPDATE glpi_documenttypes SET is_uploadable = {autorise} WHERE id = {ident};")
        sql(f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';"
            f"DELETE FROM glpi_documents_items WHERE documents_id > {documents_avant}; DELETE FROM glpi_documents WHERE id > {documents_avant};")
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
