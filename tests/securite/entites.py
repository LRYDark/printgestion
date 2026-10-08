"""Entité des données du plugin : règle de transfert, tâche quotidienne, lignes orphelines.

Règle : les données techniques (relevés, rendements, historique des cartouches, seuils, mises en veille) suivent
l'imprimante ; les données commerciales (expéditions, alertes, liaisons BL, demandes et leurs lignes) gardent
l'entité de leur création.
1. Transfert d'une imprimante de Client test A vers Client test B : un compte de B ne voit ni ne modifie aucune
   expédition, alerte, demande ni liaison BL de A ; un compte de A les garde ; les données techniques suivent.
2. Tâche PrintgestionEntityScope : corrige un écart sur une table technique, ne touche pas aux tables commerciales.
3. Ligne orpheline (imprimante purgée, entité indéterminée) : invisible du compte client, listée pour l'administrateur.
"""
import json
import re
import subprocess
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, lignes, ok_ko, refus, section, sql, valeur, verifier

CTX = lib.Contexte()
EXP, ALERTES, LIENS = "glpi_plugin_printgestion_expeditions", "glpi_plugin_printgestion_alerts", "glpi_plugin_printgestion_expedition_bls"
SUIVI_A = "SUIVI-TRANSFERT-A"
DEMANDE_A = "Demande test transfert A"


def transferer(imprimante, entite):
    lib.transferer_imprimante(imprimante, entite)


def portee(table, ident):
    return tuple(lignes(f"SELECT entities_id, is_recursive FROM {table} WHERE id = {ident}")[0])


def scenario_transfert():
    section("1. Transfert d'une imprimante de Client test A vers Client test B")
    imp = d.IMP_A2
    lib.connecter_admin()
    bl_a = CTX.bl("BLTSTTRA001", d.CLIENT_A)
    exp = CTX.expedition(imp, "test_transfert", SUIVI_A, statut="shipped")
    sql(f"INSERT INTO {LIENS} (expeditions_id, bl_surveys_id, date_creation, entities_id, is_recursive) VALUES ({exp}, {bl_a}, NOW(), {d.CLIENT_A}, 0);")
    lien = int(valeur(f"SELECT id FROM {LIENS} WHERE expeditions_id = {exp}"))
    alerte = CTX.alerte_mauvaise_imprimante(imp, d.IMP_A1, exp)
    sql("INSERT INTO glpi_plugin_printgestion_demandes (name, entities_id, locations_id, statut, delivery_mode, date_creation, date_mod) "
        f"VALUES ('{DEMANDE_A}', {d.CLIENT_A}, 0, 'proposed', 'direct', NOW(), NOW());")
    demande = int(valeur(f"SELECT MAX(id) FROM glpi_plugin_printgestion_demandes WHERE name = '{DEMANDE_A}'"))
    CTX.crees["demandes"].append(demande)
    sql("INSERT INTO glpi_plugin_printgestion_demandelines (plugin_printgestion_demandes_id, printers_id, toner_property, cartridgeitems_id, quantity, "
        f"is_under_contract, contracts_id, level_at_proposal, statut, date_creation, date_mod, entities_id) VALUES ({demande}, {imp}, 'tonerblack', {d.CARTOUCHE_NOIR}, 1, 1, "
        f"{d.CONTRAT_A}, 35, 'proposed', NOW(), NOW(), {d.CLIENT_A});")
    ligne = int(valeur(f"SELECT MAX(id) FROM glpi_plugin_printgestion_demandelines WHERE plugin_printgestion_demandes_id = {demande}"))
    WEB.post(config.AJAX + "/printer_thresholds.php", [("printers_id", str(imp)), ("threshold_level", "15"), ("threshold_days", "")], ajax=True)
    sql(f"INSERT INTO glpi_plugin_printgestion_toner_readings (printers_id, property_name, level_percent, reading_date, entities_id, is_recursive) "
        f"VALUES ({imp}, 'test_transfert', 35, CURDATE() - INTERVAL 3 DAY, {d.CLIENT_A}, 0);")
    droits = {"plugin_printgestion_expedition": 3, "plugin_printgestion_dashboard": 3, "plugin_printgestion_validation": 3}
    profil = CTX.profil(6, "Profil test entités (droits du plugin)", droits)
    CTX.utilisateur("test-client-a", profil, d.CLIENT_A)
    CTX.utilisateur("test-client-b", profil, d.CLIENT_B)

    transferer(imp, d.CLIENT_B)
    a = (str(d.CLIENT_A), "0")
    verifier("en base : expédition, liaison BL, alerte, demande et ligne de demande restées chez Client test A",
             [portee(EXP, exp), portee(LIENS, lien), portee(ALERTES, alerte), portee("glpi_plugin_printgestion_demandes", demande),
              portee("glpi_plugin_printgestion_demandelines", ligne)], [a] * 5)
    verifier("en base : seuil et relevés de l'imprimante passés chez Client test B",
             (lignes(f"SELECT DISTINCT entities_id FROM glpi_plugin_printgestion_printer_thresholds WHERE printers_id = {imp}"),
              lignes(f"SELECT DISTINCT entities_id FROM glpi_plugin_printgestion_toner_readings WHERE printers_id = {imp}")),
             ([[str(d.CLIENT_B)]], [[str(d.CLIENT_B)]]))

    CTX.connecter("test-client-b")
    marqueurs = (SUIVI_A, DEMANDE_A, "BLTSTTRA001")
    for libelle, chemin in (("écran Expéditions", "/dashboard_expeditions.php"), ("écran Alertes toner", "/dashboard_alerts.php"),
                            ("accueil du module", "/index.php"), ("demandes d'envoi", "/demande.php")):
        statut, page, _ = WEB.get(config.FRONT + chemin)
        fuites = [m for m in marqueurs if m in page]
        constat(f"compte de B, {libelle} : rien de l'historique de A", "KO" if fuites else ("OK" if statut == 200 else "NON CONCLUANT"),
                f"HTTP {statut}" + (f", trouvé {fuites}" if fuites else ""))
        if chemin == "/dashboard_expeditions.php":
            bloc = re.search(r'<script type="application/json" id="pg-exp-data">(.*?)</script>', page, re.S)
            constat("compte de B : expédition de A absente des données du menu clic droit", ok_ko(bloc is None or str(exp) not in json.loads(bloc.group(1))))
            constat("compte de B : alerte « mauvaise imprimante » de A absente", ok_ko(re.search(rf'name="alert_id"[^>]*value="{alerte}"', page) is None))
    statut, page, _ = WEB.get(config.FRONT + f"/expedition.form.php?id={exp}")
    constat("compte de B : fiche de l'expédition de A refusée", ok_ko(statut != 200 or SUIVI_A not in page), f"HTTP {statut}")
    statut, page, _ = WEB.get(config.FRONT + f"/demande.form.php?id={demande}")
    constat("compte de B : fiche de la demande de A refusée", ok_ko(statut != 200 or DEMANDE_A not in page), f"HTTP {statut}")
    statut, page, _ = WEB.get(config.AJAX + "/expedition_bls.php", [("expedition_id", str(exp))], ajax=True)
    constat("compte de B : liaisons BL de l'expédition de A refusées", ok_ko(refus(statut, page) and "BLTSTTRA001" not in page), f"HTTP {statut}")
    statut, page, _ = WEB.get(config.AJAX + "/search_bls.php", [("q", "BLTSTTRA"), ("expedition_id", str(exp))], ajax=True)
    constat("compte de B : recherche de BL sur l'expédition de A refusée", ok_ko(refus(statut, page) and "BLTSTTRA001" not in page), f"HTTP {statut}")
    avant = (lignes(f"SELECT statut, IFNULL(transport_number, ''), IFNULL(bl_surveys_id, 0) FROM {EXP} WHERE id = {exp}"),
             lignes(f"SELECT bl_surveys_id FROM {LIENS} WHERE expeditions_id = {exp}"), valeur(f"SELECT is_resolved FROM {ALERTES} WHERE id = {alerte}"))
    WEB.post(config.AJAX + "/update_expedition.php", [("id", exp), ("action", "cancel")])
    WEB.post(config.AJAX + "/edit_expedition.php", [("expedition_id", exp), ("statut", "cancelled"), ("carrier", "ups"), ("tracking", "DEPUIS-B")], ajax=True)
    WEB.post(config.AJAX + "/link_bls.php", [("expedition_id", str(exp)), ("bls", json.dumps([]))], ajax=True)
    WEB.post(config.AJAX + "/resolve_alert.php", [("alert_id", alerte)])
    WEB.action_de_masse("PluginPrintgestionExpedition", [exp], "update", "MassiveAction",
                        [("id_field", "PluginPrintgestionExpedition:7"), ("search_options[PluginPrintgestionExpedition]", "7"), ("field", "transport_number"), ("transport_number", "MASSE-DEPUIS-B")])
    apres = (lignes(f"SELECT statut, IFNULL(transport_number, ''), IFNULL(bl_surveys_id, 0) FROM {EXP} WHERE id = {exp}"),
             lignes(f"SELECT bl_surveys_id FROM {LIENS} WHERE expeditions_id = {exp}"), valeur(f"SELECT is_resolved FROM {ALERTES} WHERE id = {alerte}"))
    verifier("compte de B : expédition, liaisons BL et alerte de A non modifiables (update, edit, link_bls, resolve_alert, action de masse)", apres, avant)

    CTX.connecter("test-client-a")
    statut, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
    constat("compte de A : son expédition toujours affichée après le transfert (témoin)", ok_ko(SUIVI_A in page), f"HTTP {statut}")
    statut, page, _ = WEB.get(config.AJAX + "/expedition_bls.php", [("expedition_id", str(exp))], ajax=True)
    lus = [int(b["id"]) for b in json.loads(page).get("bls", [])] if statut == 200 else []
    verifier("compte de A : liaison BL de son expédition toujours lue", (statut, bl_a in lus), (200, True))
    statut, page, _ = WEB.get(config.FRONT + f"/demande.form.php?id={demande}")
    constat("compte de A : sa demande toujours accessible", ok_ko(statut == 200 and DEMANDE_A in page), f"HTTP {statut}")

    lib.connecter_admin()
    transferer(imp, d.CLIENT_A)
    verifier("retour chez Client test A : historique commercial inchangé, données techniques revenues",
             (portee(EXP, exp), lignes(f"SELECT DISTINCT entities_id FROM glpi_plugin_printgestion_toner_readings WHERE printers_id = {imp}")), (a, [[str(d.CLIENT_A)]]))
    return exp


def scenario_tache(exp):
    section("2. Tâche quotidienne : tables techniques seulement")
    lib.connecter_admin()
    sql(f"UPDATE {EXP} SET entities_id = {d.SITE_A2} WHERE id = {exp};"
        f"UPDATE glpi_plugin_printgestion_toner_readings SET entities_id = 999 WHERE printers_id = {d.IMP_BAS} ORDER BY id LIMIT 1;")
    tailles = lib.tailles_journaux()
    message = lib.tache("PrintgestionEntityScope")
    journal = lib.journal_depuis(tailles, "printgestion.log")
    verifier("écart sur une table technique corrigé et nommé", ("toner_readings : 1" in message,
             valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_toner_readings WHERE entities_id = 999")), (True, "0"))
    verifier("journal : erreur « Entité de lignes corrigée » sur la seule table technique",
             (bool(re.search(r"Entité de lignes corrigée.*toner_readings : 1", journal)), "expeditions" in journal), (True, False))
    verifier("expédition dont l'entité diffère de son imprimante : ignorée, pas « réparée »", portee(EXP, exp), (str(d.SITE_A2), "0"))
    sql(f"UPDATE {EXP} SET entities_id = {d.CLIENT_A} WHERE id = {exp};")


def scenario_orphelines():
    section("3. Ligne orpheline : imprimante purgée, entité indéterminée")
    lib.connecter_admin()
    imp = CTX.imprimante("TST-A-PURGEE", d.CLIENT_A)
    orpheline = CTX.expedition(imp, "test_orpheline", "SUIVI-ORPHELINE")
    connue = CTX.expedition(imp, "test_connue", "SUIVI-CONNUE")
    # État laissé par une imprimante purgée avant que l'entité ne soit renseignée : entité racine, non récursive.
    sql(f"UPDATE {EXP} SET entities_id = 0, is_recursive = 0 WHERE id = {orpheline}; DELETE FROM glpi_printers WHERE id = {imp};")
    message = lib.tache("PrintgestionEntityScope")
    constat("tâche : lignes orphelines signalées", ok_ko("Lignes orphelines" in message), message[:160])
    onglet = "/ajax/common.tabs.php?_target=%2Ffront%2Fconfig.form.php&_itemtype=Config&_glpi_tab=PluginPrintgestionConfig%241&id=1"
    _, page, _ = WEB.get(onglet, ajax=True)
    # Blancs réduits avant de découper : le gabarit natif des tableaux est très indenté.
    carte = " ".join(page[page.find("Lignes sans objet de rattachement"):].split())[:3000]
    constat("configuration du plugin (administrateur) : expédition orpheline listée par son identifiant",
            ok_ko("glpi_plugin_printgestion_expeditions" in carte and f"#{orpheline}" in carte), "")
    constat("ligne dont l'entité est connue (imprimante purgée, entité Client test A) : non listée", ok_ko(f"#{connue}" not in carte))
    CTX.connecter("test-client-a")
    statut, page, _ = WEB.get(config.FRONT + f"/expedition.form.php?id={orpheline}")
    constat("compte de Client test A : expédition orpheline invisible", ok_ko(statut != 200 or "SUIVI-ORPHELINE" not in page), f"HTTP {statut}")
    _, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
    constat("compte de Client test A : absente de l'écran Expéditions", ok_ko("SUIVI-ORPHELINE" not in page))
    lib.connecter_admin()
    sql(f"UPDATE {EXP} SET entities_id = {d.CLIENT_A} WHERE id = {orpheline};")


def main():
    d.verifier_instance()
    # Le transfert natif (configuration « complete ») copie les types de cartouche compatibles dans l'entité de
    # destination : copies retirées à la fin.
    cartouches_avant = int(valeur("SELECT IFNULL(MAX(id), 0) FROM glpi_cartridgeitems"))
    try:
        exp = None
        for scenario in (scenario_transfert, scenario_tache, scenario_orphelines):
            try:
                if scenario is scenario_tache:
                    if exp is not None:
                        scenario(exp)
                elif scenario is scenario_transfert:
                    exp = scenario()
                else:
                    scenario()
            except Exception as erreur:  # constat du test, la suite continue
                constat(f"{scenario.__name__} interrompu", "NON CONCLUANT", repr(erreur)[:300])
    finally:
        lib.connecter_admin()
        for imprimante, entite in ((d.IMP_A2, d.CLIENT_A), (d.IMP_SITE_A2, d.SITE_A2)):
            if valeur(f"SELECT entities_id FROM glpi_printers WHERE id = {imprimante}") != str(entite):
                transferer(imprimante, entite)
        sql(f"DELETE FROM glpi_plugin_printgestion_printer_thresholds WHERE printers_id = {d.IMP_A2};"
            "DELETE FROM glpi_plugin_printgestion_toner_readings WHERE property_name = 'test_transfert';"
            f"DELETE FROM glpi_cartridgeitems_printermodels WHERE cartridgeitems_id > {cartouches_avant};"
            f"DELETE FROM glpi_plugin_printgestion_cartridge_snmp WHERE cartridgeitems_id > {cartouches_avant};"
            f"DELETE FROM glpi_cartridgeitems WHERE id > {cartouches_avant};")
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
