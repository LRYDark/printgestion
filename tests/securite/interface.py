"""Écrans du module Collecte SNMP / Déploiement Agent selon le profil.

Le technicien (droit Déploiement, sans droit de configuration du plugin) voit l'état et l'action, rien d'autre : aucun
élément réservé (attribut data-pg-admin) et aucun détail technique (commande msiexec, propriétés du MSI, noms de
règles ou de plugin, chemins de menu, numéros de version) n'arrive dans sa page, même replié. L'administrateur
(témoin) les reçoit. Toujours visibles pour tous : TAG déjà utilisé par une autre entité. Action « Créer le TAG » :
TAG proposé depuis le nom, refusé s'il est déjà porté par une autre entité.
"""
import re
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql, valeur

ONGLET_ENTITE = "/ajax/common.tabs.php?_target=%2Ffront%2Fentity.form.php&_itemtype=Entity&_glpi_tab=PluginPrintgestionAgentdeploy%241&id={}"
ONGLET_AGENT = "/ajax/common.tabs.php?_target=%2Ffront%2Fagent.form.php&_itemtype=Agent&_glpi_tab=PluginPrintgestionAgentsetting%241&id={}"
# Détails techniques jamais montrés à un technicien.
MARQUEURS = [
    ("attribut data-pg-admin", r"data-pg-admin"),
    ("commande msiexec", r"msiexec"),
    ("propriétés du MSI", r"HTTPD_TRUST|ADDLOCAL|SNMP_RETRIES|QUICKINSTALL|feat_NETINV"),
    ("fichier de configuration de l'agent", r"local\.cfg|90-printgestion\.cfg"),
    ("nom de règle ou de critère", r"Entity from TAG|Entité depuis TAG|Inventory tag|Tag d'inventaire|Tag d&#039;inventaire"),
    ("plugin ou tâche GLPI Inventory", r"GLPI Inventory|glpiinventory|taskscheduler"),
    ("chemin de menu", r"(Configuration|Administration) (→|&gt;|>) "),
    ("numéro de version de l'agent", r"GLPI Agent \d|GLPI-Agent-\d|\b1\.19\b"),
    ("empreinte de fichier", r"SHA-256"),
]
CTX = lib.Contexte()


def contenu(page):
    """Contenu propre à l'écran : la page complète sans le menu et l'en-tête de GLPI (onglet AJAX : tel quel)."""
    debut = page.find("<main")
    return page[debut:page.rfind("</main>")] if debut >= 0 else page


def trouves(page):
    return [nom for nom, motif in MARQUEURS if re.search(motif, contenu(page))]


def ecrans(agent):
    return [
        ("onglet Déploiement Agent de l'entité", ONGLET_ENTITE.format(d.CLIENT_A), True),
        ("page Installeur GLPI Agent", config.FRONT + "/agentdeploy.php", False),
        ("page Contrôle de la remontée", config.FRONT + "/collect.php", False),
        ("page Sondes", config.FRONT + "/sondes.php", False),
        ("fiche d'une sonde", config.FRONT + f"/sondes.php?id={agent}", False),
        ("onglet Print Gestion de la fiche Agent", ONGLET_AGENT.format(agent), True),
        ("page Raccordements", config.FRONT + "/raccordement.php", False),
        ("assistant de raccordement (nouveau)", config.FRONT + f"/raccordement.php?entities_id={d.CLIENT_A}", False),
    ]


def main():
    d.verifier_instance()
    tag_a = valeur(f"SELECT IFNULL(tag, '') FROM glpi_entities WHERE id = {d.CLIENT_A}")
    tags = {e: valeur(f"SELECT IFNULL(tag, '') FROM glpi_entities WHERE id = {e}") for e in (d.CLIENT_B, d.SITE_A2)}
    sql("INSERT INTO glpi_agents (deviceid, entities_id, name, agenttypes_id, last_contact, version, useragent, tag, locked, itemtype, items_id, "
        f"use_module_network_inventory, use_module_network_discovery) VALUES ('agent-test-interface', {d.CLIENT_A}, 'AGENT-TEST-INTERFACE', 1, NOW(), "
        "'1.19', 'GLPI-Agent_v1.19', 'CLIENT-TEST-A', 0, 'Computer', 0, 1, 1);")
    agent = int(valeur("SELECT id FROM glpi_agents WHERE deviceid = 'agent-test-interface'"))
    try:
        lib.connecter_admin()
        droits = {"plugin_printgestion_deploiement": 3, "plugin_printgestion_config": 0}
        profil = CTX.profil(6, "Profil test technicien (Déploiement seul)", droits)
        CTX.utilisateur("test-technicien", profil, d.CLIENT_A)

        section("1. Technicien : état et action seulement")
        CTX.connecter("test-technicien")
        for libelle, chemin, ajax in ecrans(agent):
            statut, page, _ = WEB.get(chemin, ajax=ajax)
            if statut != 200:
                constat(f"{libelle} : ouverte par le technicien", "NON CONCLUANT", f"HTTP {statut}")
                continue
            constat(f"{libelle} : aucun détail réservé à l'administrateur", ok_ko(not trouves(page)), ", ".join(trouves(page)))
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        visible = lib.texte(page).strip()
        constat("onglet de l'entité : quelques lignes seulement pour le technicien", ok_ko(len(visible) < 900), f"{len(visible)} caractères : {visible[:200]}")

        section("2. Administrateur (témoin) : détails présents, repliés")
        lib.connecter_admin()
        for libelle, chemin, ajax in ecrans(agent)[:3]:
            _, page, _ = WEB.get(chemin, ajax=ajax)
            constat(f"{libelle} : détails de l'administrateur présents", ok_ko("data-pg-admin" in page and "collapse" in page))
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("onglet de l'entité : commande msiexec dans la fenêtre « i » de l'administrateur", ok_ko("msiexec" in page and "modal" in page))

        section("3. Toujours visible : TAG déjà utilisé par une autre entité")
        sql(f"UPDATE glpi_entities SET tag = {lib.q(tag_a)} WHERE id = {d.CLIENT_B};")
        CTX.connecter("test-technicien")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("technicien : avertissement « TAG déjà utilisé » affiché sans dépliage", ok_ko("déjà utilisé par l" in lib.texte(page) and not trouves(page)),
                ", ".join(trouves(page)))
        sql(f"UPDATE glpi_entities SET tag = {lib.q(tags[d.CLIENT_B])} WHERE id = {d.CLIENT_B};")

        section("4. Action « Créer le TAG » : droit natif de modifier l'entité")
        sql(f"UPDATE glpi_entities SET tag = '' WHERE id = {d.SITE_A2};")
        CTX.connecter("test-technicien")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.SITE_A2), ajax=True)
        constat("technicien sans droit de modifier l'entité : pas de « Créer le TAG », « contactez l'administrateur »",
                ok_ko("Créer le TAG" not in page and "contactez l" in lib.texte(page)))
        lib.connecter_admin()
        profil_tag = CTX.profil(6, "Profil test technicien (Déploiement et entité)", {"plugin_printgestion_deploiement": 3, "plugin_printgestion_config": 0, "entity": 3})
        CTX.utilisateur("test-technicien-entite", profil_tag, d.CLIENT_A)
        CTX.connecter("test-technicien-entite")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.SITE_A2), ajax=True)
        constat("technicien avec le droit de modifier l'entité : « Créer le TAG » proposé avec le nom normalisé (SITETESTA2), sans détail réservé",
                ok_ko("Créer le TAG" in page and "value='SITETESTA2'" in page and not trouves(page)), ", ".join(trouves(page)))
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.SITE_A2)), ("tag", tag_a), ("create_tag", "1")])
        message = WEB.messages()
        constat("TAG déjà porté par une autre entité : refusé",
                ok_ko(valeur(f"SELECT IFNULL(tag, '') FROM glpi_entities WHERE id = {d.SITE_A2}") == "" and "déjà utilisé" in message), message[:120])
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.SITE_A2)), ("tag", "SITETESTA2"), ("create_tag", "1")])
        constat("TAG proposé : enregistré sur l'entité", ok_ko(valeur(f"SELECT tag FROM glpi_entities WHERE id = {d.SITE_A2}") == "SITETESTA2"))
    finally:
        sql(f"DELETE FROM glpi_agents WHERE id = {agent};")
        for entite, tag in tags.items():
            sql(f"UPDATE glpi_entities SET tag = {lib.q(tag) if tag else 'NULL'} WHERE id = {entite};")
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
