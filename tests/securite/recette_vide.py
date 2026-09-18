"""Recette sur une base fraîchement installée : le plugin vient d'être réinstallé par installation.py, ses tables sont
vides (les objets natifs du jeu de données, entités, imprimantes, contrats, existent, comme sur un GLPI réel).
1. Administrateur : chaque page, point d'entrée (GET puis POST), onglet du plugin sur les fiches natives et fiches
   natives : ni erreur 5xx, ni marqueur d'erreur PHP ou SQL, ni page blanche, ni ligne de journal.
2. Technicien (tous les droits du plugin en lecture, configuration exclue), accès direct par l'URL : idem ; la
   configuration est refusée proprement.
3. Chaque tâche automatique sur base vide : aucune en erreur, aucune erreur PHP ou SQL.
Dernier fichier de la suite, après installation.py ; retire les données natives d'inventaire du jeu de test (cartouches,
compteurs) pour voir les écrans tels qu'un GLPI neuf les montre : l'instance ressort à reconstruire.
"""
import os
import sys
import urllib.parse

import config
import donnees as d
import lib
import parcours
import taches_lib
from lib import WEB, constat, section, verifier

CTX = lib.Contexte()


def onglet(cible, itemtype, classe, ident):
    return "/ajax/common.tabs.php?" + urllib.parse.urlencode({"_target": cible, "_itemtype": itemtype, "_glpi_tab": classe + "$1", "id": ident})


def parcourir(anomalies):
    pages = sorted(n for n in os.listdir(os.path.join(config.PLUGIN_DIR, "front")) if n.endswith(".php"))
    ajax = sorted(n for n in os.listdir(os.path.join(config.PLUGIN_DIR, "ajax")) if n.endswith(".php"))
    for nom in pages:
        parcours.controler(anomalies, "GET", f"{config.FRONT}/{nom}")
        if nom.endswith(".form.php") or nom in ("demande.export.php", "sageimport.php"):
            parcours.controler(anomalies, "POST", f"{config.FRONT}/{nom}")
    for nom in ajax:
        parcours.controler(anomalies, "GET", f"{config.AJAX}/{nom}", ajax=True)
        parcours.controler(anomalies, "POST", f"{config.AJAX}/{nom}", ajax=True)
    onglets = [
        onglet("/front/entity.form.php", "Entity", "PluginPrintgestionAgentdeploy", d.CLIENT_A),
        onglet("/front/config.form.php", "Config", "PluginPrintgestionConfig", 1),
        onglet("/front/printer.form.php", "Printer", "PluginPrintgestionPrinterThresholdsTab", d.IMP_A1),
        onglet("/front/printer.form.php", "Printer", "PluginPrintgestionPrinterCostsTab", d.IMP_A1),
        onglet("/front/cartridgeitem.form.php", "CartridgeItem", "PluginPrintgestionCartridgesnmp", d.CARTOUCHE_NOIR),
        onglet("/front/contract.form.php", "Contract", "PluginPrintgestionContractrate", d.CONTRAT_A),
        onglet("/front/agent.form.php", "Agent", "PluginPrintgestionAgentsetting", d.AGENT_RECENT),
        onglet("/front/profile.form.php", "Profile", "PluginPrintgestionProfile", 6),
    ]
    for chemin in onglets:
        parcours.controler(anomalies, "GET", chemin, ajax=True)
    for chemin in (f"/front/printer.form.php?id={d.IMP_A1}", f"/front/contract.form.php?id={d.CONTRAT_A}", f"/front/entity.form.php?id={d.CLIENT_A}",
                   f"/front/agent.form.php?id={d.AGENT_RECENT}", f"/front/cartridgeitem.form.php?id={d.CARTOUCHE_NOIR}"):
        parcours.controler(anomalies, "GET", chemin)
    return 2 * len(ajax) + len(pages) + len(onglets) + 5


def main():
    d.verifier_instance()
    vide = int(lib.valeur("SELECT COUNT(*) FROM glpi_plugin_printgestion_expeditions")) + int(lib.valeur("SELECT COUNT(*) FROM glpi_plugin_printgestion_toner_readings"))
    verifier("base vierge du plugin (aucune expédition, aucun relevé)", vide, 0)
    try:
        section("1. Administrateur, base vide : chaque écran, point d'entrée et onglet")
        lib.connecter_admin()
        anomalies = []
        nb = parcourir(anomalies)
        constat(f"{nb} requêtes : aucune erreur, aucune page blanche", lib.ok_ko(not anomalies), " | ".join(anomalies[:10]))
        # Un GLPI neuf n'a ni cartouches inventoriées ni compteurs de pages : ces données natives du jeu de test nourrissent les
        # alertes et le coût même sans le plugin. Retirées ici (dernier fichier de la suite, instance reconstruite avant chaque passe).
        lib.sql("DELETE FROM glpi_printers_cartridgeinfos; DELETE FROM glpi_printerlogs; DELETE FROM glpi_plugin_printgestion_alertview; "
                "DELETE FROM glpi_plugin_printgestion_toner_readings; DELETE FROM glpi_plugin_printgestion_billing_view;")
        lib.php_glpi("PluginPrintgestionBilling::invalidateCache();")  # le coût à la page est mis en cache dix minutes
        for page, marqueur in (("dashboard_alerts.php", "Aucune alerte pour l"), ("dashboard_expeditions.php", "Aucune expédition pour l"), ("dashboard_billing.php", "Aucun coût calculé")):
            _, html, _ = WEB.get(config.FRONT + "/" + page)
            constat(f"{page} sans données : dit par quoi commencer, avec ses liens, au lieu d'un tableau vide",
                    lib.ok_ko("data-pg-empty" in html and marqueur in html and "btn btn-sm btn-outline-primary" in html[html.find("data-pg-empty"):]))

        section("2. Technicien, base vide, accès direct par l'URL")
        droits = {"plugin_printgestion_contrats": 1, "plugin_printgestion_dashboard": 1, "plugin_printgestion_expedition": 1, "plugin_printgestion_validation": 1,
                  "plugin_printgestion_deploiement": 1, "plugin_printgestion_sage": 1, "plugin_printgestion_billing": 1, "plugin_printgestion_config": 0}
        profil = CTX.profil(6, "Profil test recette (lecture)", droits)
        CTX.utilisateur("test-recette", profil, d.RACINE)
        CTX.connecter("test-recette")
        anomalies = []
        nb = parcourir(anomalies)
        constat(f"{nb} requêtes en accès direct : aucune erreur, aucune page blanche", lib.ok_ko(not anomalies), " | ".join(anomalies[:10]))
        statut, page, _ = WEB.get(config.FRONT + "/config.form.php")
        constat("configuration refusée proprement au technicien (accès direct)", lib.ok_ko(statut in (302, 403) or "Accès refusé" in page or "Access denied" in page), f"HTTP {statut}")

        section("3. Toutes les tâches automatiques, base vide")
        resultats, journal = taches_lib.passer_toutes()
        for tache, (erreur, messages) in resultats.items():
            verifier(f"{tache} : terminée sans erreur ({messages[:90] or 'sans message'})", erreur, False)
        verifier("aucune erreur PHP ou SQL pendant les passages", journal.strip()[:200], "")
    finally:
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
