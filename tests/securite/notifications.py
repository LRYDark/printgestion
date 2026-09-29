"""Notifications natives des circuits mail : créées à l'installation dans l'état d'avant, envoyées tout de suite avec
un résultat connu, fichier Gesconso joint, rôles du plugin comme destinataires.

1. Les sept notifications du plugin (type, événement, gabarit lié du bon type, pièces jointes, destinataires
   « Rôle … », auteur, usager) ; l'écran de configuration les liste.
2. Commande directe, serveur mail en marche : un mail aux Achats avec le fichier archivé, sujet sans préfixe
   [GLPI], ligne de file marquée envoyée, fichier rattaché à la commande, commande « transmise ».
3. Serveur mail en panne : commande « non transmise » avec l'erreur du serveur, aucune ligne laissée en attente
   dans la file (le plugin garde la main sur le renvoi) ; renvoi une fois le serveur rétabli.
4. Alerte toner : la tâche horaire émet un seul événement toner_alert, alertes marquées envoyées ; notification
   désactivée : rien ne part, alertes non marquées.
"""
import sys
import time

import config
import donnees as d
import lib
from lib import WEB, constat, lignes, ok_ko, section, sql, valeur, verifier

NOTIFS = "glpi_notifications"
FILE = "glpi_queuednotifications"
ORDRES = "glpi_plugin_printgestion_purchaseorders"
EXP = "glpi_plugin_printgestion_expeditions"
ALERTES = "glpi_plugin_printgestion_alerts"
ROLE_PLANIF, ROLE_ACHAT, ROLE_COMMERCIAL, USAGER, AUTEUR = 7311, 7312, 7313, 7314, 7301

ATTENDUES = {
    # (itemtype, événement) : (pièces jointes, destinataires)
    ("PluginPrintgestionAlert", "toner_alert"): ("0", {ROLE_COMMERCIAL}),
    ("PluginPrintgestionPurchaseorder", "purchaseorder_sent"): ("1", {ROLE_ACHAT, AUTEUR}),
    ("PluginPrintgestionPurchaseorder", "purchaseorder_planif"): ("0", {ROLE_PLANIF, AUTEUR}),
    ("PluginPrintgestionPurchaseorder", "purchaseorder_planif_group"): ("1", {ROLE_PLANIF, AUTEUR}),
    ("PluginPrintgestionPurchaseorder", "purchaseorder_courtesy"): ("0", {USAGER}),
    ("PluginPrintgestionExpedition", "expedition_shipped"): ("0", {ROLE_COMMERCIAL}),
    ("PluginPrintgestionExpedition", "expedition_reminder"): ("0", {ROLE_PLANIF, ROLE_COMMERCIAL}),
}


def notification(itemtype, evenement):
    rangs = lignes(f"SELECT n.id, n.is_active, n.attach_documents, t.itemtype FROM {NOTIFS} n "
                   "LEFT JOIN glpi_notifications_notificationtemplates nt ON nt.notifications_id = n.id AND nt.mode = 'mailing' "
                   "LEFT JOIN glpi_notificationtemplates t ON t.id = nt.notificationtemplates_id "
                   f"WHERE n.itemtype = '{itemtype}' AND n.event = '{evenement}' ORDER BY n.id LIMIT 1")
    return rangs[0] if rangs else None


def smtp(port):
    sql(f"UPDATE glpi_configs SET value = '{port}' WHERE context = 'core' AND name = 'smtp_port';")


def commander():
    ligne = valeur(f"SELECT id FROM glpi_plugin_printgestion_alertview WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack'")
    WEB.action_de_masse("PluginPrintgestionAlertview", [ligne], "pg_order", "PluginPrintgestionAlertview")
    return WEB.messages()


def scenario_installation():
    section("1. Sept notifications natives, dans l'état d'avant")
    lib.connecter_admin()
    for (itemtype, evenement), (pieces, cibles) in ATTENDUES.items():
        n = notification(itemtype, evenement)
        if n is None:
            constat(f"{evenement} : notification présente", "KO", "absente")
            continue
        vues = {int(c[0]) for c in lignes(f"SELECT items_id FROM glpi_notificationtargets WHERE notifications_id = {n[0]} AND type = 1")}
        verifier(f"{evenement} : active, gabarit du type {itemtype}, pièces jointes {pieces}, destinataires {sorted(cibles)}",
                 (n[1], n[3], n[2], cibles <= vues), ("1", itemtype, pieces, True))
    verifier("gabarits du plugin : tous d'un type du plugin (plus de Ticket)",
             valeur("SELECT COUNT(*) FROM glpi_notificationtemplates WHERE comment = 'Created by plugin printgestion' AND itemtype NOT LIKE 'PluginPrintgestion%'"), "0")
    statut, page, _ = WEB.get(config.FRONT + "/config.form.php")
    constat("configuration : tableau « Notifications du plugin » avec les sept circuits, plus de bouton « Qui est notifié ? »",
            ok_ko(statut == 200 and "Notifications du plugin" in page and page.count("Print Gestion - ") >= 7 and "Qui est notifié" not in page), f"HTTP {statut}")
    n = notification("PluginPrintgestionPurchaseorder", "purchaseorder_sent")
    if n is not None:
        statut, page, _ = WEB.get(f"/front/notification.form.php?id={n[0]}")
        constat("fiche native de la notification : le rôle Achats du plugin proposé comme destinataire",
                ok_ko(statut == 200 and "Rôle Achats (Print Gestion)" in page), f"HTTP {statut}")


def scenario_commande():
    section("2. Commande directe : mail aux Achats par la notification native, fichier archivé joint")
    lib.connecter_admin()
    sql(f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';")
    lib.tache("PrintgestionCheckAlerts")
    ordres_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {ORDRES}"))
    file_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {FILE}"))
    smtp(config.SMTP_PORT)
    try:
        depuis = time.time()
        message = commander()
        time.sleep(1)
        ordre = lignes(f"SELECT id, status, documents_id FROM {ORDRES} WHERE id > {ordres_avant}")
        if not ordre:
            constat("commande enregistrée", "KO", message[:200])
            return
        ordre_id, statut, document = int(ordre[0][0]), ordre[0][1], int(ordre[0][2])
        nom = valeur(f"SELECT filename FROM glpi_documents WHERE id = {document}")
        mails = [m for m in lib.mails_depuis(depuis) if "Commande cartouches" in m["sujet"]]
        verifier("commande transmise : un mail aux Achats, fichier archivé joint sous son nom, sujet sans préfixe [GLPI]",
                 (statut, [(m["fichiers"], m["sujet"].startswith("[GLPI]")) for m in mails]), ("sent", [([nom], False)]))
        file = lignes(f"SELECT sent_time IS NOT NULL, is_deleted, recipient FROM {FILE} WHERE id > {file_avant} "
                      f"AND itemtype = 'PluginPrintgestionPurchaseorder' AND event = 'purchaseorder_sent' AND items_id = {ordre_id}")
        verifier("file d'attente native : la ligne du mail, envoyée (date d'envoi posée, classée), au rôle Achats",
                 file, [["1", "1", "admin@exemple.test"]])
        verifier("fichier Gesconso rattaché à la commande (document de la commande)",
                 valeur(f"SELECT COUNT(*) FROM glpi_documents_items WHERE itemtype = 'PluginPrintgestionPurchaseorder' AND items_id = {ordre_id} AND documents_id = {document}"), "1")
        constat("message : commande transmise (pas « NON TRANSMISE »)", ok_ko("NON TRANSMISE" not in message), message[:160])
    finally:
        documents = [o[0] for o in lignes(f"SELECT documents_id FROM {ORDRES} WHERE id > {ordres_avant}")]
        sql(f"DELETE FROM {FILE} WHERE itemtype = 'PluginPrintgestionPurchaseorder' AND id > {file_avant};"
            f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';"
            f"DELETE FROM {ORDRES} WHERE id > {ordres_avant};")
        if documents:
            sql(f"DELETE FROM glpi_documents_items WHERE documents_id IN ({', '.join(documents)}); DELETE FROM glpi_documents WHERE id IN ({', '.join(documents)});")
        lib.tache("PrintgestionCheckAlerts")


def scenario_panne():
    section("3. Serveur mail en panne : non transmise, rien laissé dans la file, renvoi")
    lib.connecter_admin()
    sql(f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';")
    lib.tache("PrintgestionCheckAlerts")
    ordres_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {ORDRES}"))
    file_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {FILE}"))
    smtp(2599)
    try:
        depuis = time.time()
        message = commander()
        time.sleep(1)
        ordre = lignes(f"SELECT id, status, last_error FROM {ORDRES} WHERE id > {ordres_avant}")
        if not ordre:
            constat("commande enregistrée malgré la panne", "KO", message[:200])
            return
        ordre_id = int(ordre[0][0])
        en_attente = valeur(f"SELECT COUNT(*) FROM {FILE} WHERE id > {file_avant} AND itemtype = 'PluginPrintgestionPurchaseorder' "
                            "AND sent_time IS NULL AND is_deleted = 0")
        verifier("commande « non transmise » avec l'erreur du serveur, message « NON TRANSMISE », aucun mail",
                 (ordre[0][1], ordre[0][2] not in (None, "", "NULL"), "NON TRANSMISE" in message,
                  [m for m in lib.mails_depuis(depuis) if "Commande cartouches" in m["sujet"]]), ("failed", True, True, []))
        verifier("aucune ligne laissée en attente dans la file native : GLPI ne renverra pas de lui-même, le renvoi reste au plugin",
                 en_attente, "0")
        smtp(config.SMTP_PORT)
        depuis = time.time()
        WEB.post(config.FRONT + "/purchaseorder.form.php", [("id", str(ordre_id)), ("resend", "1")])
        time.sleep(1)
        verifier("serveur rétabli, « Renvoyer aux Achats » : transmise, un mail",
                 (valeur(f"SELECT status FROM {ORDRES} WHERE id = {ordre_id}"),
                  len([m for m in lib.mails_depuis(depuis) if "Commande cartouches" in m["sujet"]])), ("sent", 1))
    finally:
        smtp(config.SMTP_PORT)
        documents = [o[0] for o in lignes(f"SELECT documents_id FROM {ORDRES} WHERE id > {ordres_avant}")]
        sql(f"DELETE FROM {FILE} WHERE itemtype = 'PluginPrintgestionPurchaseorder' AND id > {file_avant};"
            f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_BAS} AND toner_property = 'tonerblack';"
            f"DELETE FROM {ORDRES} WHERE id > {ordres_avant};")
        if documents:
            sql(f"DELETE FROM glpi_documents_items WHERE documents_id IN ({', '.join(documents)}); DELETE FROM glpi_documents WHERE id IN ({', '.join(documents)});")
        lib.tache("PrintgestionCheckAlerts")


def scenario_alerte():
    section("4. Alerte toner : un seul événement par passage, rien si la notification est inactive")
    lib.connecter_admin()
    n = notification("PluginPrintgestionAlert", "toner_alert")
    if n is None:
        constat("notification toner_alert présente", "KO", "absente")
        return
    niveau = valeur(f"SELECT value FROM glpi_printers_cartridgeinfos WHERE printers_id = {d.IMP_A1} AND property = 'tonerblack'")
    releve = lignes(f"SELECT id, level_percent FROM glpi_plugin_printgestion_toner_readings WHERE printers_id = {d.IMP_A1} "
                    "AND property_name = 'tonerblack' ORDER BY reading_date DESC LIMIT 1")[0]
    alertes_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {ALERTES}"))
    file_avant = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {FILE}"))
    smtp(config.SMTP_PORT)
    try:
        sql(f"UPDATE glpi_printers_cartridgeinfos SET value = '5' WHERE printers_id = {d.IMP_A1} AND property = 'tonerblack';"
            f"UPDATE glpi_plugin_printgestion_toner_readings SET level_percent = 5 WHERE id = {releve[0]};"
            f"DELETE FROM {ALERTES} WHERE printers_id = {d.IMP_A1} AND toner_property = 'tonerblack' AND alert_type = 'low_toner';"
            f"DELETE FROM {EXP} WHERE printers_id = {d.IMP_A1} AND toner_property = 'tonerblack';")
        depuis = time.time()
        lib.tache("PrintgestionCheckAlerts")
        time.sleep(1)
        evenements = valeur(f"SELECT COUNT(DISTINCT create_time) FROM {FILE} WHERE id > {file_avant} AND itemtype = 'PluginPrintgestionAlert' AND event = 'toner_alert'")
        envoyees = valeur(f"SELECT COUNT(*) FROM {FILE} WHERE id > {file_avant} AND itemtype = 'PluginPrintgestionAlert' AND event = 'toner_alert' AND sent_time IS NOT NULL")
        marquee = valeur(f"SELECT mail_sent FROM {ALERTES} WHERE id > {alertes_avant} AND printers_id = {d.IMP_A1} AND toner_property = 'tonerblack' ORDER BY id DESC LIMIT 1")
        mails = [m for m in lib.mails_depuis(depuis) if "Alerte toner" in m["sujet"]]
        verifier("un seul événement toner_alert, envoyé au rôle Commercial, alerte marquée envoyée, un mail « Alerte toner »",
                 (evenements, envoyees != "0", marquee, len(mails)), ("1", True, "1", 1))

        sql(f"UPDATE {NOTIFS} SET is_active = 0 WHERE id = {n[0]};"
            f"DELETE FROM {ALERTES} WHERE printers_id = {d.IMP_A1} AND toner_property = 'tonerblack' AND alert_type = 'low_toner';")
        file_milieu = int(valeur(f"SELECT IFNULL(MAX(id), 0) FROM {FILE}"))
        lib.tache("PrintgestionCheckAlerts")
        verifier("notification inactive : aucun événement, l'alerte reste « non envoyée » pour un prochain passage",
                 (valeur(f"SELECT COUNT(*) FROM {FILE} WHERE id > {file_milieu} AND itemtype = 'PluginPrintgestionAlert'"),
                  valeur(f"SELECT mail_sent FROM {ALERTES} WHERE printers_id = {d.IMP_A1} AND toner_property = 'tonerblack' AND alert_type = 'low_toner' ORDER BY id DESC LIMIT 1")),
                 ("0", "0"))
    finally:
        sql(f"UPDATE {NOTIFS} SET is_active = 1 WHERE id = {n[0]};"
            f"UPDATE glpi_printers_cartridgeinfos SET value = {lib.q(niveau)} WHERE printers_id = {d.IMP_A1} AND property = 'tonerblack';"
            f"UPDATE glpi_plugin_printgestion_toner_readings SET level_percent = {releve[1]} WHERE id = {releve[0]};"
            f"DELETE FROM {ALERTES} WHERE id > {alertes_avant};"
            f"DELETE FROM {FILE} WHERE id > {file_avant} AND itemtype = 'PluginPrintgestionAlert';")
        lib.tache("PrintgestionCheckAlerts")


def main():
    d.verifier_instance()
    for scenario in (scenario_installation, scenario_commande, scenario_panne, scenario_alerte):
        try:
            scenario()
        except Exception as erreur:  # constat du test, la suite continue
            constat(f"{scenario.__name__} interrompu", "NON CONCLUANT", repr(erreur)[:300])
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
