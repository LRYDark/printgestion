"""Onglets « Sondes » et « Imprimantes collectées » : deux listes natives sur des vues matérialisées.

Les vues se recalculent sur demande et à l'affichage ; leurs colonnes s'ajoutent aux listes natives de GLPI
(Administration > Agents, Parc > Imprimantes) comme à celles du module. L'ancien lien « collect.php?state= »
devient le filtre natif de l'onglet des imprimantes. Le technicien (droit Déploiement en lecture) voit les
listes sans action massive ni colonne réservée.
"""
import json
import re
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql, valeur

CTX = lib.Contexte()


def configuration(page):
    bloc = re.search(r'<script type="application/json" id="pg-ctx-config">(.*?)</script>', page, re.S)
    if not bloc:
        return None, []
    cfg = json.loads(bloc.group(1))
    return cfg.get("itemtype"), [i["key"] for i in cfg.get("items", [])]


def main():
    lib.connecter_admin()
    try:
        section("1. Vues matérialisées : recalcul, contenu")
        statut, page, _ = WEB.post(config.FRONT + "/sondes.php", [("recompute_views", "1")])
        collect = int(valeur("SELECT COUNT(*) FROM glpi_plugin_printgestion_collectviews") or 0)
        agents = int(valeur("SELECT COUNT(*) FROM glpi_plugin_printgestion_agentviews") or 0)
        sonde = valeur(f"SELECT is_probe FROM glpi_plugin_printgestion_agentviews WHERE agents_id = {d.AGENT_RECENT}")
        constat("« Recalculer maintenant » remplit les deux vues ; l'agent avec inventaire réseau est une sonde",
                ok_ko(statut in (200, 302) and collect > 0 and agents > 0 and sonde == "1"),
                f"HTTP {statut}, {collect} imprimante(s), {agents} agent(s), sonde {sonde}")
        etat = valeur(f"SELECT state FROM glpi_plugin_printgestion_collectviews WHERE printers_id = {d.IMP_A1}")
        constat("chaque imprimante porte un état de collecte connu",
                ok_ko(etat in ("no_inventory", "stale", "no_level", "ok")), str(etat))

        section("2. L'écran : tuiles, prérequis, deux vues natives")
        statut, page, _ = WEB.get(config.FRONT + "/sondes.php")
        itemtype, cles = configuration(page)
        constat("onglet Sondes : liste native, tuiles, clic droit",
                ok_ko(statut == 200 and "data-glpi-search-container" in page and "Sondes sans contact" in page
                      and itemtype == "PluginPrintgestionSonde" and set(cles) == {"open-agent", "open-raccordement", "history"}),
                f"HTTP {statut}, {itemtype} {cles}")
        constat("aucune action massive sur ce type dérivé", ok_ko("massiveactions-control" not in page))
        statut, page, _ = WEB.get(config.FRONT + "/collect.php")
        itemtype, cles = configuration(page)
        constat("onglet Imprimantes collectées : état de la collecte, tuiles, prérequis, analyses de l'administrateur, clic droit",
                ok_ko(statut == 200 and "data-glpi-search-container" in page and "État de la collecte" in page and "Muette" in page
                      and "prérequis" in page and "Numéros de série en double" in page and itemtype == "PluginPrintgestionPrintercollect"
                      and set(cles) == {"open-printer", "open-agent"}),
                f"HTTP {statut}, {itemtype} {cles}")
        statut, page, _ = WEB.get(config.FRONT + "/collect.php?criteria[0][field]=74011&criteria[0][searchtype]=equals&criteria[0][value]=" + str(etat) + "&reset=reset")
        constat("filtre natif par état : l'imprimante de cet état est listée",
                ok_ko(statut == 200 and f"printer.form.php?id={d.IMP_A1}" in page), f"HTTP {statut}")
        statut, page, en_tetes = WEB.brut("GET", config.FRONT + "/collect.php?state=stale")
        cible = en_tetes.get("Location", "") if en_tetes else ""
        constat("l'ancien lien « ?state= » devient le filtre natif de l'onglet Imprimantes collectées",
                ok_ko(statut in (301, 302) and "collect.php" in cible and "74011" in cible and "stale" in cible), f"HTTP {statut} → {cible[:120]}")

        section("3. Les mêmes colonnes dans les listes natives de GLPI")
        statut, page, _ = WEB.get("/front/agent.php?criteria[0][field]=74001&criteria[0][searchtype]=equals&criteria[0][value]=1&reset=reset")
        constat("Administration > Agents : filtre « Sonde Print Gestion = oui » accepté",
                ok_ko(statut == 200 and "Sonde Print Gestion" in page and "AGENT-TEST-RECENT" in page), f"HTTP {statut}")
        statut, page, _ = WEB.get("/front/printer.php?criteria[0][field]=74011&criteria[0][searchtype]=equals&criteria[0][value]=" + str(etat) + "&reset=reset")
        constat("Parc > Imprimantes : filtre « État de la collecte » accepté",
                ok_ko(statut == 200 and "État de la collecte" in page), f"HTTP {statut}")
        statut, page, _ = WEB.get(config.AJAX + f"/rowcontext.php?itemtype=PluginPrintgestionPrintercollect&ids={d.IMP_A1}", ajax=True)
        try:
            ligne = json.loads(page).get("rows", {}).get(str(d.IMP_A1), {})
        except ValueError:
            ligne = {}
        constat("contexte d'une imprimante collectée : sa fiche", ok_ko(statut == 200 and ligne.get("printer_url", "").endswith(f"printer.form.php?id={d.IMP_A1}")), f"HTTP {statut}, {str(ligne)[:160]}")

        section("4. Technicien : les listes, sans action massive ni colonne réservée")
        profil = CTX.profil(6, "Profil test supervision (Déploiement en lecture)", {"plugin_printgestion_deploiement": 1, "plugin_printgestion_config": 0})
        CTX.utilisateur("test-supervision-tech", profil, d.CLIENT_A)
        CTX.connecter("test-supervision-tech")
        for page_nom in ("sondes.php", "collect.php"):
            statut, page, _ = WEB.get(config.FRONT + "/" + page_nom)
            constat(f"{page_nom} : ouverte par le technicien, liste native, rien de réservé",
                    ok_ko(statut == 200 and "data-glpi-search-container" in page and "massiveactions-control" not in page
                          and "data-pg-admin" not in page and "Numéros de série en double" not in page and "recompute_views" not in page),
                    f"HTTP {statut}")
        lib.connecter_admin()
    finally:
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
