"""Relevé manuel d'une imprimante sans sonde : niveaux et compteurs saisis, écrits là où l'inventaire les écrit.

Le relevé passe par les classes natives (cartouches d'inventaire, journal des compteurs, compteur de la fiche),
laisse une trace du plugin, pose le relevé du plugin à la date dite et recalcule les alertes de l'imprimante ;
le bouton apparaît dans les onglets natifs « Cartouches » et « Compteurs de pages » sans toucher à GLPI. Refusé
sans le droit Alertes toner en modification, hors du périmètre, en GET, ou avec un niveau hors 0-100.
"""
import json
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql, valeur

ONGLET = "/ajax/common.tabs.php?_target=%2Ffront%2Fprinter.form.php&_itemtype=Printer&_glpi_tab={}%241&id={}"
CTX = lib.Contexte()


def poster(imp, champs):
    statut, page, _ = WEB.post(config.AJAX + "/manual_reading.php", [("printers_id", str(imp))] + champs, ajax=True)
    try:
        return statut, json.loads(page)
    except ValueError:
        return statut, {}


def main():
    lib.connecter_admin()
    imp = CTX.imprimante("Imprimante test relevé manuel", d.CLIENT_A)
    try:
        section("1. Le bouton dans les onglets natifs, sans toucher à GLPI")
        for onglet in ("Cartridge", "PrinterLog"):
            statut, page, _ = WEB.get(ONGLET.format(onglet, imp), ajax=True)
            constat(f"onglet natif {onglet} : bouton « Saisir un relevé manuel » et fenêtre",
                    ok_ko(statut == 200 and "data-pg-manual-open" in page and "pg-manual-modal" in page), f"HTTP {statut}")

        section("2. Un relevé : tables natives, trace, relevé du plugin, alertes")
        statut, reponse = poster(imp, [("levels[tonerblack]", "8"), ("levels[tonercyan]", ""), ("counters[total_pages]", "12340"),
                                       ("counters[color_pages]", "340"), ("counters[scanned]", "77"), ("comment", "mail du client")])
        niveau = valeur(f"SELECT value FROM glpi_printers_cartridgeinfos WHERE printers_id = {imp} AND property = 'tonerblack'")
        cyan = valeur(f"SELECT COUNT(*) FROM glpi_printers_cartridgeinfos WHERE printers_id = {imp} AND property = 'tonercyan'")
        journal = lib.lignes(f"SELECT total_pages, bw_pages, color_pages, scanned FROM glpi_printerlogs WHERE itemtype = 'Printer' AND items_id = {imp}")
        compteur = valeur(f"SELECT last_pages_counter FROM glpi_printers WHERE id = {imp}")
        trace = lib.lignes(f"SELECT levels, total_pages, color_pages, comment, users_id FROM glpi_plugin_printgestion_manualreadings WHERE printers_id = {imp}")
        releve = valeur(f"SELECT level_percent FROM glpi_plugin_printgestion_toner_readings WHERE printers_id = {imp} AND property_name = 'tonerblack' ORDER BY reading_date DESC LIMIT 1")
        constat("niveau noir écrit dans les cartouches d'inventaire de GLPI, le cyan vide non touché",
                ok_ko(statut == 200 and reponse.get("ok") is True and niveau == "8" and cyan == "0"), f"HTTP {statut}, {reponse}, noir {niveau}, cyan {cyan}")
        constat("compteurs dans le journal natif (total, noir = total - couleur, couleur, scans) et sur la fiche",
                ok_ko(journal == [["12340", "12000", "340", "77"]] and compteur == "12340"), f"{journal}, fiche {compteur}")
        constat("trace du plugin : niveaux, compteurs, commentaire, auteur",
                ok_ko(len(trace) == 1 and '"tonerblack":8' in trace[0][0] and trace[0][1] == "12340" and trace[0][2] == "340" and trace[0][3] == "mail du client" and trace[0][4] == str(d.ADMIN_ID)),
                str(trace))
        constat("relevé du plugin posé au niveau saisi", ok_ko(releve == "8"), str(releve))
        alerte = valeur(f"SELECT status FROM glpi_plugin_printgestion_alertview WHERE printers_id = {imp} AND toner_property = 'tonerblack'")
        constat("alertes de l'imprimante recalculées : toner noir à 8 % en alerte", ok_ko(alerte in ("critical", "watch")), str(alerte))
        statut, page, _ = WEB.get(ONGLET.format("Cartridge", imp), ajax=True)
        constat("l'onglet montre le suivi manuel et l'historique", ok_ko("Suivi manuel" in page and "mail du client" in page))
        statut, page, _ = WEB.get(f"/front/printer.form.php?id={imp}")
        constat("fiche : carte « Informations d'inventaire manuel » avec le dernier relevé et le bouton",
                ok_ko(statut == 200 and "Informations d'inventaire manuel" in page and "mail du client" in page and "data-pg-manual-open" in page), f"HTTP {statut}")

        section("3. La sonde a raison : un inventaire postérieur rend le relevé manuel caduc")
        sql("INSERT INTO glpi_rulematchedlogs (date, items_id, itemtype, rules_id, agents_id, method) "
            f"VALUES (DATE_ADD(NOW(), INTERVAL 1 MINUTE), {imp}, 'Printer', 0, {d.AGENT_RECENT}, 'netinventory');")
        lib.tache("PrintgestionSnapshotReadings")
        depasse = valeur(f"SELECT superseded FROM glpi_plugin_printgestion_manualreadings WHERE printers_id = {imp}")
        restant = valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_toner_readings WHERE printers_id = {imp} AND property_name = 'tonerblack' AND source = 'manual'")
        constat("relevé marqué dépassé, son relevé du plugin retiré (la valeur native reste à la sonde)",
                ok_ko(depasse == "1" and restant == "0"), f"dépassé {depasse}, relevés manuels restants {restant}")
        statut, page, _ = WEB.get(ONGLET.format("Cartridge", imp), ajax=True)
        constat("l'onglet dit que le relevé est dépassé par la sonde", ok_ko("dépassé par la sonde" in page))

        section("4. Refus")
        statut, reponse = poster(imp, [("levels[tonerblack]", "150")])
        constat("niveau hors 0-100 : refusé", ok_ko(reponse.get("ok") is False), str(reponse)[:120])
        statut, reponse = poster(imp, [("levels[tonerblack]", "")])
        constat("rien à enregistrer : refusé", ok_ko(reponse.get("ok") is False), str(reponse)[:120])
        statut, page, _ = WEB.get(config.AJAX + f"/manual_reading.php?printers_id={imp}&levels[tonerblack]=3", ajax=True)
        constat("GET : refusé, rien d'écrit", ok_ko(lib.refus(statut, page) and valeur(f"SELECT value FROM glpi_printers_cartridgeinfos WHERE printers_id = {imp} AND property = 'tonerblack'") == "8"), f"HTTP {statut}")
        profil = CTX.profil(6, "Profil test relevé (lecture des alertes)", {"plugin_printgestion_dashboard": 1})
        CTX.utilisateur("test-releve-lecture", profil, d.CLIENT_A)
        CTX.connecter("test-releve-lecture")
        statut, reponse = poster(imp, [("levels[tonerblack]", "3")])
        constat("lecture seule : refusé", ok_ko(statut == 403), f"HTTP {statut}")
        statut, page, _ = WEB.get(ONGLET.format("Cartridge", imp), ajax=True)
        constat("lecture seule : l'historique se lit, pas de bouton", ok_ko("data-pg-manual-open" not in page and "Suivi manuel" in page), f"HTTP {statut}")
        profil_b = CTX.profil(6, "Profil test relevé (client B)", {"plugin_printgestion_dashboard": 3})
        CTX.utilisateur("test-releve-b", profil_b, d.CLIENT_B)
        CTX.connecter("test-releve-b")
        statut, reponse = poster(imp, [("levels[tonerblack]", "3")])
        constat("autre client : refusé", ok_ko(statut == 403), f"HTTP {statut}")
        lib.connecter_admin()
    finally:
        sql(f"DELETE FROM glpi_rulematchedlogs WHERE itemtype = 'Printer' AND items_id = {imp};"
            f"DELETE FROM glpi_plugin_printgestion_toner_readings WHERE printers_id = {imp};"
            f"DELETE FROM glpi_plugin_printgestion_manualreadings WHERE printers_id = {imp};"
            f"DELETE FROM glpi_printers_cartridgeinfos WHERE printers_id = {imp};"
            f"DELETE FROM glpi_printerlogs WHERE itemtype = 'Printer' AND items_id = {imp};"
            f"DELETE FROM glpi_plugin_printgestion_alertview WHERE printers_id = {imp};")
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
