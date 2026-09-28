"""Raccordements en liste native, et un seul tableau de sondes dans l'onglet Déploiement Agent de l'entité.

La page « Raccordements » du module est la liste native de GLPI : colonnes calculées « Adresses » et « Résultats »,
numéro en lien vers l'assistant, filtre par sonde, menu clic droit. L'onglet de l'entité montre une ligne par sonde
avec son dernier raccordement (statut, résultats, reprise) et un lien vers l'historique ; la fréquence des relevés
est le bloc 4, pour l'administrateur seulement. Le technicien (droit Déploiement en lecture) voit le tableau sans
les colonnes de l'administrateur.
"""
import json
import re
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql, valeur

ONGLET_ENTITE = "/ajax/common.tabs.php?_target=%2Ffront%2Fentity.form.php&_itemtype=Entity&_glpi_tab=PluginPrintgestionAgentdeploy%241&id={}"
CTX = lib.Contexte()


def configuration(page):
    bloc = re.search(r'<script type="application/json" id="pg-ctx-config">(.*?)</script>', page, re.S)
    if not bloc:
        return None, []
    cfg = json.loads(bloc.group(1))
    return cfg.get("itemtype"), [i["key"] for i in cfg.get("items", [])]


def main():
    lib.connecter_admin()
    sql("INSERT INTO glpi_agents (deviceid, entities_id, name, agenttypes_id, last_contact, version, useragent, tag, locked, itemtype, items_id, "
        f"use_module_network_inventory, use_module_network_discovery) VALUES ('agent-test-onglet', {d.CLIENT_A}, 'AGENT-TEST-ONGLET', 1, NOW(), "
        "'1.20', 'GLPI-Agent_v1.20', 'CLIENT-TEST-A', 0, 'Computer', 0, 1, 1);")
    agent = int(valeur("SELECT id FROM glpi_agents WHERE deviceid = 'agent-test-onglet'"))
    sql("INSERT INTO glpi_plugin_printgestion_raccordements (entities_id, agents_id, status, users_id, date_creation, date_mod) "
        f"VALUES ({d.CLIENT_A}, {agent}, 'open', {d.ADMIN_ID}, NOW(), NOW());")
    racc = int(valeur(f"SELECT MAX(id) FROM glpi_plugin_printgestion_raccordements WHERE agents_id = {agent}"))
    sql("INSERT INTO glpi_plugin_printgestion_raccordementips (plugin_printgestion_raccordements_id, ip, ip_num, result) VALUES "
        f"({racc}, '192.0.2.10', 3221225994, 'found'), ({racc}, '192.0.2.11', 3221225995, 'no_snmp');")
    try:
        section("1. Page Raccordements : la liste native")
        statut, page, _ = WEB.get(config.FRONT + "/raccordement.php")
        itemtype, cles = configuration(page)
        constat("liste native, colonnes Adresses et Résultats, numéro en lien vers l'assistant",
                ok_ko(statut == 200 and "data-glpi-search-container" in page and "Adresses" in page and "Résultats" in page
                      and f"raccordement.php?id={racc}" in page and "Trouvée 1" in page and "Pas de réponse SNMP 1" in page),
                f"HTTP {statut}")
        constat("menu clic droit : ouvrir le raccordement, ouvrir la sonde",
                ok_ko(itemtype == "PluginPrintgestionRaccordement" and set(cles) == {"open-raccordement", "open-agent"}), f"{itemtype} {cles}")
        _, page, _ = WEB.get(config.FRONT + f"/raccordement.php?criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]={agent}&reset=reset")
        _, autre, _ = WEB.get(config.FRONT + f"/raccordement.php?criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]={d.AGENT_RECENT}&reset=reset")
        constat("filtre par sonde : le raccordement de cette sonde, et pas d'une autre",
                ok_ko(f"raccordement.php?id={racc}" in page and f"raccordement.php?id={racc}" not in autre))

        section("2. Onglet de l'entité : une ligne par sonde, avec son raccordement")
        statut, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        itemtype, cles = configuration(page)
        constat("un seul tableau « Sondes de ce client » : plus de « Raccordements en cours » ni de « Sondes rattachées »",
                ok_ko(statut == 200 and "Sondes de ce client" in page and "Raccordements en cours" not in page and "Sondes rattachées" not in page), f"HTTP {statut}")
        constat("la ligne de la sonde porte son raccordement en cours, à reprendre",
                ok_ko("AGENT-TEST-ONGLET" in page and "En cours" in page and "Reprendre" in page and f"raccordement.php?id={racc}" in page))
        constat("lien vers l'historique des raccordements du client, filtré sur l'entité",
                ok_ko("Historique des raccordements de ce client" in page and "criteria" in page and f"value%5D={d.CLIENT_A}" in page.replace("[", "%5B").replace("]", "%5D")))
        constat("bloc « 4. Fréquence des relevés » pour l'administrateur", ok_ko("4. Fréquence des relevés" in page))
        constat("menu clic droit sur les sondes : sonde, raccordement en cours, historique",
                ok_ko(itemtype == "Agent" and set(cles) == {"open-agent", "open-raccordement", "history"}), f"{itemtype} {cles}")
        statut, page, _ = WEB.get(config.AJAX + f"/rowcontext.php?itemtype=Agent&ids={agent}", ajax=True)
        try:
            ligne = json.loads(page).get("rows", {}).get(str(agent), {})
        except ValueError:
            ligne = {}
        constat("contexte de la sonde : raccordement en cours et historique",
                ok_ko(statut == 200 and ligne.get("has_open_raccordement") is True and ligne.get("raccordement_url", "").endswith(f"?id={racc}")
                      and ligne.get("has_raccordements") is True and "criteria" in ligne.get("history_url", "")),
                f"HTTP {statut}, {str(ligne)[:200]}")

        section("3. Technicien : le tableau sans les colonnes de l'administrateur")
        profil = CTX.profil(6, "Profil test onglet (Déploiement en lecture)", {"plugin_printgestion_deploiement": 1, "plugin_printgestion_config": 0})
        CTX.utilisateur("test-onglet-tech", profil, d.CLIENT_A)
        CTX.connecter("test-onglet-tech")
        statut, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("le technicien voit ses sondes et leur raccordement, sans réglage ni colonne réservés",
                ok_ko(statut == 200 and "Sondes de ce client" in page and "AGENT-TEST-ONGLET" in page and "data-pg-admin" not in page
                      and "4. Fréquence des relevés" not in page and "Nouveau raccordement" not in page),
                f"HTTP {statut}")
        statut, page, _ = WEB.get(config.FRONT + "/raccordement.php")
        constat("le technicien lit la liste native des raccordements de son client",
                ok_ko(statut == 200 and f"raccordement.php?id={racc}" in page), f"HTTP {statut}")
        lib.connecter_admin()
    finally:
        sql(f"DELETE FROM glpi_plugin_printgestion_raccordementips WHERE plugin_printgestion_raccordements_id = {racc};"
            f"DELETE FROM glpi_plugin_printgestion_raccordementlogs WHERE plugin_printgestion_raccordements_id = {racc};"
            f"DELETE FROM glpi_plugin_printgestion_raccordements WHERE id = {racc};"
            f"DELETE FROM glpi_agents WHERE id = {agent};")
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
