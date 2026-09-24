"""Écrans du module Collecte SNMP / Déploiement Agent selon le profil.

Le technicien (droit Déploiement, sans droit de configuration du plugin) voit l'état et l'action, rien d'autre : aucun
élément réservé (attribut data-pg-admin) et aucun détail technique (commande msiexec, propriétés du MSI, noms de
règles ou de plugin, chemins de menu, numéros de version) n'arrive dans sa page, même replié. L'administrateur
(témoin) les reçoit. Toujours visibles pour tous : TAG déjà utilisé par une autre entité. Action « Créer le TAG » :
TAG proposé depuis le nom, refusé s'il est déjà porté par une autre entité. Règle d'affectation par TAG : créée par un
administrateur seulement, une seule, structure native vérifiée par le moteur de règles de GLPI ; bouton visible (hors
chevron) tant qu'elle manque, et créée du même clic que le TAG quand c'est l'administrateur qui crée le TAG. Une règle
présente mais désactivée compte comme absente : état rouge, bouton « Activer la règle ». Téléchargement de l'installeur
bloqué (écran et URL directe) tant que le TAG manque ou que la règle est absente ou désactivée.
"""
import base64
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import zipfile
from html.parser import HTMLParser

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


class BoutonVisible(HTMLParser):
    """Repère un bouton nommé hors de tout bloc replié ou réservé (chevron, fenêtre « i », data-pg-admin)."""
    VIDES = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "source", "track", "wbr"}

    def __init__(self, nom):
        super().__init__()
        self.nom, self.pile, self.visible, self.cache = nom, [], False, False

    def handle_starttag(self, tag, attrs):
        attributs = dict(attrs)
        classes = (attributs.get("class") or "").split()
        replie = "data-pg-admin" in attributs or "collapse" in classes or "modal" in classes
        if tag == "button" and attributs.get("name") == self.nom:
            if any(self.pile) or replie:
                self.cache = True
            else:
                self.visible = True
        if tag not in self.VIDES:
            self.pile.append(replie)

    def handle_endtag(self, tag):
        if tag not in self.VIDES and self.pile:
            self.pile.pop()


def bouton_visible(page, nom):
    analyse = BoutonVisible(nom)
    analyse.feed(page)
    return analyse.visible and not analyse.cache


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
    regles = lambda: int(valeur("SELECT COUNT(DISTINCT r.id) FROM glpi_rules r JOIN glpi_ruleactions a ON a.rules_id = r.id "  # noqa: E731
                                "WHERE r.sub_type = 'RuleImportEntity' AND a.field = '_affect_entity_by_tag'"))
    # Environnement « correct » le temps du test : actions automatiques en CLI avec une exécution récente (sinon
    # l'onglet dit, à raison, que rien ne remontera). État d'origine rétabli à la fin.
    cron = lib.lignes("SELECT id, mode, IFNULL(lastrun, 'NULL') FROM glpi_crontasks")
    sql("UPDATE glpi_crontasks SET mode = 2 WHERE itemtype LIKE 'PluginPrintgestion%';")
    sql("UPDATE glpi_crontasks SET lastrun = " + lib.q(lib.php_glpi("echo date('Y-m-d H:i:s');").strip()) + " WHERE name = 'PrintgestionTemoinCron';")  # heure de GLPI
    etat_glpiinventory = valeur("SELECT state FROM glpi_plugins WHERE directory = 'glpiinventory'")
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
        constat("onglet de l'entité : aucun réglage de fréquence pour le technicien (ni champ, ni bouton, ni texte)",
                ok_ko("name='frequency'" not in page and "save_frequency" not in page and "Relevés des imprimantes" not in page and "Fréquence" not in page))
        WEB.post(config.FRONT + "/collectfrequency.php", [("entities_id", str(d.CLIENT_A)), ("frequency", "hourly"), ("modifier", "2"), ("save_frequency", "1")])
        constat("technicien : enregistrement direct de la fréquence refusé",
                ok_ko(valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_collectfrequencies WHERE entities_id = {d.CLIENT_A} AND frequency = 'hourly'") == "0"))
        # Retirer une sonde est un droit à part (PURGE sur le droit Déploiement) : le fichier de retrait supprime
        # aussi dans GLPI, il ne se télécharge donc pas avec le seul droit d'installer.
        constat("technicien sans le droit « Retirer une sonde » : aucun bouton de retrait dans l'onglet",
                ok_ko("windows-retrait" not in page))
        statut_retrait, _ = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows-retrait")
        constat("technicien sans ce droit : l'URL du fichier de retrait est refusée",
                ok_ko(statut_retrait not in (200, 302)), f"HTTP {statut_retrait}")
        profil_retrait = CTX.profil(6, "Profil test technicien (Déploiement et retrait)",
                                    {"plugin_printgestion_deploiement": 3 | 16, "plugin_printgestion_config": 0})
        CTX.utilisateur("test-technicien-retrait", profil_retrait, d.CLIENT_A)
        CTX.connecter("test-technicien-retrait")
        statut_avec, octets_avec = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows-retrait")
        _, page_retrait, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("technicien avec ce droit : boutons de retrait, et fichier servi avec ses trois choix",
                ok_ko(statut_avec == 200 and "windows-retrait" in page_retrait
                      and "RadioButton" in octets_avec.decode("utf-8", "replace")),
                f"HTTP {statut_avec}")

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
                ok_ko("Créer le TAG" not in page and "contactez l" in lib.texte(page).lower()))
        lib.connecter_admin()
        profil_tag = CTX.profil(6, "Profil test technicien (Déploiement et entité)", {"plugin_printgestion_deploiement": 3, "plugin_printgestion_config": 0, "entity": 3})
        CTX.utilisateur("test-technicien-entite", profil_tag, d.CLIENT_A)
        CTX.connecter("test-technicien-entite")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.SITE_A2), ajax=True)
        constat("technicien avec le droit de modifier l'entité : « Créer le TAG » proposé avec le nom normalisé (SITETESTA2), sans détail réservé",
                ok_ko("Créer le TAG" in page and "value='SITETESTA2'" in page and not trouves(page)), ", ".join(trouves(page)))
        constat("technicien : ni bouton de création de la règle, ni libellé « et la règle »",
                ok_ko("create_tag_rule" not in page and "et la règle" not in page))
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.SITE_A2)), ("tag", tag_a), ("create_tag", "1")])
        message = WEB.messages()
        constat("TAG déjà porté par une autre entité : refusé",
                ok_ko(valeur(f"SELECT IFNULL(tag, '') FROM glpi_entities WHERE id = {d.SITE_A2}") == "" and "déjà utilisé" in message), message[:120])
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.SITE_A2)), ("tag", "SITETESTA2"), ("create_tag", "1")])
        message = WEB.messages()
        constat("TAG proposé : enregistré sur l'entité, règle non créée par le technicien et absence signalée",
                ok_ko(valeur(f"SELECT tag FROM glpi_entities WHERE id = {d.SITE_A2}") == "SITETESTA2" and regles() == 0
                      and "administrateur doit la créer" in message), message[:160])

        section("5. Règle d'affectation par TAG : administrateur, clic explicite, une seule règle")
        constat("instance de test : aucune règle d'affectation par TAG au départ", "OK" if regles() == 0 else "NON CONCLUANT", str(regles()))
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.CLIENT_A)), ("create_tag_rule", "1")])
        constat("technicien (même avec le droit de modifier l'entité) : création refusée", ok_ko(regles() == 0), WEB.messages()[:120])
        lib.connecter_admin()
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("administrateur : bouton « Créer la règle d'affectation par TAG » visible hors chevron, avec confirmation",
                ok_ko(bouton_visible(page, "create_tag_rule") and "confirm(" in page))
        _, page, _ = WEB.get(config.FRONT + "/agentdeploy.php")
        constat("page Installeur, carte Prérequis : même bouton visible pour l'administrateur", ok_ko(bouton_visible(contenu(page), "create_tag_rule")))
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.CLIENT_A)), ("create_tag_rule", "1")])
        regle = lib.lignes("SELECT r.id, r.is_active, c.criteria, c.`condition`, c.pattern, a.action_type, a.value FROM glpi_rules r "
                           "JOIN glpi_rulecriterias c ON c.rules_id = r.id JOIN glpi_ruleactions a ON a.rules_id = r.id "
                           "WHERE r.sub_type = 'RuleImportEntity' AND a.field = '_affect_entity_by_tag'")
        constat("clic de l'administrateur : une règle active, critère tag /^(.*)$/ (expression régulière), action regex_result #0",
                ok_ko(len(regle) == 1 and regle[0][1:] == ["1", "tag", "6", "/^(.*)$/", "regex_result", "#0"]), str(regle))
        affectee = lib.php_glpi("$c = new RuleImportEntityCollection(); $out = $c->processAllRules(['tag' => 'CLIENT-TEST-A'], [], []); echo $out['entities_id'] ?? 'aucune';")
        constat("moteur de règles natif : un inventaire au TAG CLIENT-TEST-A est affecté à Client test A", ok_ko(affectee.strip() == str(d.CLIENT_A)), affectee.strip())
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.CLIENT_A)), ("create_tag_rule", "1")])
        message = WEB.messages()
        constat("second clic : aucune seconde règle, état de la règle existante rendu", ok_ko(regles() == 1 and "active, position" in message), message[:140])
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("règle présente : plus de bouton de création", ok_ko("create_tag_rule" not in page))

        section("6. « Créer le TAG » par l'administrateur sans règle : TAG et règle du même clic")
        for (ident,) in lib.lignes("SELECT DISTINCT r.id FROM glpi_rules r JOIN glpi_ruleactions a ON a.rules_id = r.id "
                                   "WHERE r.sub_type = 'RuleImportEntity' AND a.field = '_affect_entity_by_tag'"):
            lib.php_glpi(f"(new RuleImportEntity())->delete(['id' => {int(ident)}], true);")
        sql(f"UPDATE glpi_entities SET tag = '' WHERE id = {d.SITE_A2};")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.SITE_A2), ajax=True)
        constat("administrateur : un seul bouton « Créer le TAG et la règle d'affectation », avec confirmation",
                ok_ko("Créer le TAG et la règle d" in page and "confirm(" in page and "create_tag_rule" not in page))
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.SITE_A2)), ("tag", "SITETESTA2"), ("create_tag", "1")])
        message = WEB.messages()
        constat("clic : TAG enregistré et règle générique créée, les deux annoncés",
                ok_ko(valeur(f"SELECT tag FROM glpi_entities WHERE id = {d.SITE_A2}") == "SITETESTA2" and regles() == 1
                      and "Règle créée" in message), message[:160])
        affectee = lib.php_glpi("$c = new RuleImportEntityCollection(); $out = $c->processAllRules(['tag' => 'SITETESTA2'], [], []); echo $out['entities_id'] ?? 'aucune';")
        constat("moteur de règles natif : une autre entité (TAG CLIENT-TEST-A) utilise la même règle", ok_ko(affectee.strip() == str(d.SITE_A2)
                and lib.php_glpi("$c = new RuleImportEntityCollection(); $out = $c->processAllRules(['tag' => 'CLIENT-TEST-A'], [], []); echo $out['entities_id'] ?? 'aucune';").strip() == str(d.CLIENT_A)),
                affectee.strip())
        sql(f"UPDATE glpi_entities SET tag = '' WHERE id = {d.SITE_A2};")
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.SITE_A2)), ("tag", "SITETESTA2"), ("create_tag", "1")])
        constat("règle existante : nouveau TAG sans seconde règle", ok_ko(regles() == 1 and valeur(f"SELECT tag FROM glpi_entities WHERE id = {d.SITE_A2}") == "SITETESTA2"))

        section("7. Règle présente mais désactivée : comptée comme absente")
        actives = lambda: int(valeur("SELECT COUNT(DISTINCT r.id) FROM glpi_rules r JOIN glpi_ruleactions a ON a.rules_id = r.id "  # noqa: E731
                                     "WHERE r.sub_type = 'RuleImportEntity' AND a.field = '_affect_entity_by_tag' AND r.is_active = 1"))
        sql("UPDATE glpi_rules r JOIN glpi_ruleactions a ON a.rules_id = r.id SET r.is_active = 0 "
            "WHERE r.sub_type = 'RuleImportEntity' AND a.field = '_affect_entity_by_tag';")
        desactivee = "Règle d&#039;affectation présente mais désactivée — les équipements n&#039;iront pas dans la bonne entité"
        for compte in ("test-technicien", "test-technicien-entite"):
            CTX.connecter(compte)
            _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
            constat(f"{compte} : état rouge « règle présente mais désactivée », sans bouton d'activation ni détail réservé",
                    ok_ko(desactivee in page and "Rattachement : correct" not in page and "activate_tag_rule" not in page and not trouves(page)),
                    ", ".join(trouves(page)))
        sql(f"UPDATE glpi_entities SET tag = '' WHERE id = {d.SITE_A2};")
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.SITE_A2)), ("tag", "SITETESTA2"), ("create_tag", "1")])
        message = WEB.messages()
        constat("technicien qui crée un TAG : règle laissée désactivée, « l'administrateur doit l'activer »",
                ok_ko(actives() == 0 and "administrateur doit l" in message and "activer" in message), message[:160])
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.CLIENT_A)), ("activate_tag_rule", "1")])
        constat("technicien : activation refusée", ok_ko(actives() == 0), WEB.messages()[:120])
        lib.connecter_admin()
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("administrateur : même état rouge, bouton « Activer la règle » visible hors chevron avec confirmation, pas de bouton de création",
                ok_ko(desactivee in page and bouton_visible(page, "activate_tag_rule") and "confirm(" in page and "create_tag_rule" not in page))
        _, page, _ = WEB.get(config.FRONT + "/agentdeploy.php")
        constat("page Installeur, carte Prérequis : « Activer la règle » visible, prérequis non corrects",
                ok_ko(bouton_visible(contenu(page), "activate_tag_rule") and "Prérequis : corrects" not in page))
        sql(f"UPDATE glpi_entities SET tag = '' WHERE id = {d.SITE_A2};")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.SITE_A2), ajax=True)
        constat("administrateur, entité sans TAG : « Créer le TAG et activer la règle d'affectation »",
                ok_ko("Créer le TAG et activer la règle d" in page and "activate_tag_rule" not in page))
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.SITE_A2)), ("tag", "SITETESTA2"), ("create_tag", "1")])
        message = WEB.messages()
        constat("clic : TAG enregistré et règle activée, sans seconde règle",
                ok_ko(actives() == 1 and regles() == 1 and "Règle activée" in message), message[:160])
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("règle active : plus de bouton, rattachement correct", ok_ko("activate_tag_rule" not in page and "create_tag_rule" not in page and "Rattachement : correct" in page))
        sql("UPDATE glpi_rules r JOIN glpi_ruleactions a ON a.rules_id = r.id SET r.is_active = 0 "
            "WHERE r.sub_type = 'RuleImportEntity' AND a.field = '_affect_entity_by_tag';")
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.CLIENT_A)), ("activate_tag_rule", "1")])
        message = WEB.messages()
        affectee = lib.php_glpi("$c = new RuleImportEntityCollection(); $out = $c->processAllRules(['tag' => 'CLIENT-TEST-A'], [], []); echo $out['entities_id'] ?? 'aucune';")
        constat("bouton « Activer la règle » : règle active, moteur natif de nouveau opérant",
                ok_ko(actives() == 1 and "Règle activée" in message and affectee.strip() == str(d.CLIENT_A)), f"{message[:100]} / {affectee.strip()}")

        section("8. Téléchargement bloqué tant que le rattachement est incomplet")
        historique = lambda entite: int(valeur(f"SELECT COUNT(*) FROM glpi_logs WHERE itemtype = 'Entity' AND items_id = {entite}"))  # noqa: E731
        telecharger = lambda entite: WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={entite}&os=windows")  # noqa: E731
        # Le bouton Windows sert un fichier unique (un .bat) : c'est son premier mot qui dit qu'un paquet est parti.
        servi = lambda octets: octets.startswith(b"@echo off")  # noqa: E731
        bloque, lien = "Configuration incomplète — le déploiement est bloqué", "agentdeploy.download.php"
        sql("UPDATE glpi_rules r JOIN glpi_ruleactions a ON a.rules_id = r.id SET r.is_active = 0 "
            "WHERE r.sub_type = 'RuleImportEntity' AND a.field = '_affect_entity_by_tag';")
        CTX.connecter("test-technicien")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("règle désactivée, technicien : « déploiement bloqué … Contactez l'administrateur », aucun lien de téléchargement",
                ok_ko(bloque in page and "sans correction possible ensuite. Contactez l" in lib.texte(page) and lien not in page and not trouves(page)),
                ", ".join(trouves(page)))
        avant = historique(d.CLIENT_A)
        statut, octets = telecharger(d.CLIENT_A)
        constat("technicien, URL directe : aucun paquet, aucun téléchargement tracé", ok_ko(not servi(octets) and historique(d.CLIENT_A) == avant),
                f"HTTP {statut}, {len(octets)} octets")
        lib.connecter_admin()
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("administrateur : même blocage, bouton « Activer la règle » juste après, sans « Contactez l'administrateur »",
                ok_ko(bloque in page and lien not in page and bouton_visible(page, "activate_tag_rule") and page.count("name='activate_tag_rule'") == 1
                      and page.find(bloque) < page.find("activate_tag_rule") and "Contactez l" not in lib.texte(page)))
        statut, octets = telecharger(d.CLIENT_A)
        constat("administrateur, URL directe : bloqué aussi", ok_ko(not servi(octets) and historique(d.CLIENT_A) == avant), f"HTTP {statut}")
        lib.supprimer_regles_tag()
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("règle absente : même blocage, bouton « Créer la règle d'affectation par TAG »",
                ok_ko(bloque in page and lien not in page and bouton_visible(page, "create_tag_rule")))
        statut, octets = telecharger(d.CLIENT_A)
        constat("règle absente, URL directe : bloqué", ok_ko(not servi(octets) and historique(d.CLIENT_A) == avant), f"HTTP {statut}")
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.CLIENT_A)), ("create_tag_rule", "1")])
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("règle créée : blocage levé, liens de téléchargement présents", ok_ko(bloque not in page and lien in page))
        statut, octets = telecharger(d.CLIENT_A)
        constat("rattachement complet : fichier Windows servi et tracé (pas de faux blocage)", ok_ko(servi(octets) and historique(d.CLIENT_A) == avant + 1),
                f"HTTP {statut}, {len(octets)} octets")
        commande = octets.decode("utf-8", "replace") if servi(octets) else ""
        constat("SERVER= du fichier : URL de l'application GLPI, racine comprise, jamais le chemin du plugin GLPI Inventory",
                ok_ko(f'SERVER="{d.CORE["url_base"]}/"' in commande and "glpiinventory" not in commande),
                commande[commande.find("SERVER="):][:160])
        sql(f"UPDATE glpi_entities SET tag = '' WHERE id = {d.SITE_A2};")
        CTX.connecter("test-technicien-entite")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.SITE_A2), ajax=True)
        constat("TAG absent, technicien qui peut le créer : blocage, formulaire « Créer le TAG » à côté, sans « Contactez l'administrateur »",
                ok_ko(bloque in page and lien not in page and page.find(bloque) < page.find("name='create_tag'") and "Contactez l" not in lib.texte(page)))
        avant = historique(d.SITE_A2)
        statut, octets = telecharger(d.SITE_A2)
        constat("TAG absent, URL directe : bloqué", ok_ko(not servi(octets) and historique(d.SITE_A2) == avant), f"HTTP {statut}")
        CTX.connecter("test-technicien")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.SITE_A2), ajax=True)
        constat("TAG absent, technicien sans droit : blocage et « Contactez l'administrateur »",
                ok_ko(bloque in page and lien not in page and "Contactez l" in lib.texte(page) and "name='create_tag'" not in page))

        section("9. URL de l'application vide, locale ou sans schéma : téléchargement bloqué, écran et accès direct")
        avant = historique(d.CLIENT_A)
        for url, cas in (("", "vide"), ("http://localhost", "locale"), ("http://127.0.0.1/glpi", "locale (127.0.0.1)"), ("glpi.exemple.test", "sans schéma")):
            sql(f"UPDATE glpi_configs SET value = {lib.q(url)} WHERE context = 'core' AND name = 'url_base';")
            _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
            statut, octets = telecharger(d.CLIENT_A)
            constat(f"URL {cas} : « déploiement bloqué », « Contactez l'administrateur », aucun lien, accès direct refusé",
                    ok_ko(bloque in page and "Contactez l" in lib.texte(page) and lien not in page and not servi(octets) and historique(d.CLIENT_A) == avant
                          and not trouves(page)), f"HTTP {statut} ; {', '.join(trouves(page))}")
        sql(f"UPDATE glpi_configs SET value = {lib.q(d.CORE['url_base'])} WHERE context = 'core' AND name = 'url_base';")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        statut, octets = telecharger(d.CLIENT_A)
        constat("URL rétablie : lien présent, paquet servi", ok_ko(bloque not in page and lien in page and servi(octets)), f"HTTP {statut}")

        section("10. L'onglet Entité intègre l'environnement : rouge sans bloquer quand rien ne remontera")
        rien = "Rien ne remontera pour l&#039;instant — contactez l&#039;administrateur"
        constat("environnement correct : aucune ligne « Rien ne remontera »", ok_ko(rien not in page))
        sql("UPDATE glpi_plugins SET state = 4 WHERE directory = 'glpiinventory';")
        CTX.connecter("test-technicien")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("GLPI Inventory désactivé, technicien : ligne rouge « Rien ne remontera… contactez l'administrateur », téléchargement toujours possible, aucun détail réservé",
                ok_ko(rien in page and lien in page and bloque not in page and not trouves(page)), ", ".join(trouves(page)))
        lib.connecter_admin()
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("administrateur : même ligne, détail replié avec la cause et le lien vers l'écran GLPI",
                ok_ko(rien in page and "data-pg-admin" in page and "Marketplace" in page and "config.form.php" not in page[page.find(rien):page.find(rien) + 200]))
        sql("UPDATE glpi_crontasks SET mode = 1 WHERE name = 'PrintgestionCheckAlerts';")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("tâche du plugin en mode Interne : cause listée aussi (Actions automatiques)", ok_ko(rien in page and "crontask" in page))
        sql("UPDATE glpi_crontasks SET mode = 2 WHERE name = 'PrintgestionCheckAlerts';")
        sql(f"UPDATE glpi_plugins SET state = {etat_glpiinventory} WHERE directory = 'glpiinventory';")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("environnement rétabli : ligne disparue", ok_ko(rien not in page))

        section("11. Vérification du raccordement : limite de temps, état franc")
        heure = lambda decalage: lib.php_glpi(f"echo date('Y-m-d H:i:s', time() + ({decalage}));").strip()  # noqa: E731  (heure de GLPI, pas NOW() de la base)
        sql(f"INSERT INTO glpi_plugin_printgestion_raccordements (entities_id, agents_id, status, users_id, date_creation, date_mod, date_configured, date_triggered) "
            f"VALUES ({d.CLIENT_A}, {agent}, 'triggered', 2, NOW(), NOW(), NOW(), {lib.q(heure(-31 * 60))});")
        racc = int(valeur("SELECT MAX(id) FROM glpi_plugin_printgestion_raccordements"))
        sql(f"INSERT INTO glpi_plugin_printgestion_raccordementips (plugin_printgestion_raccordements_id, ip, ip_num) VALUES ({racc}, '192.0.2.31', INET_ATON('192.0.2.31'));")
        arretee = "Vérification arrêtée après 30 min : 1 adresse toujours sans réponse"
        _, page, _ = WEB.get(config.FRONT + f"/raccordement.php?id={racc}")
        constat("31 min après le déclenchement, administrateur : « Vérification arrêtée après 30 min », quoi faire, plus de relance automatique",
                ok_ko(arretee in page and "Relancer la découverte" in page and "pg-racc-autoverify" not in page))
        CTX.connecter("test-technicien")
        _, page, _ = WEB.get(config.FRONT + f"/raccordement.php?id={racc}")
        constat("technicien : même état, sans détail réservé", ok_ko(arretee in page and not trouves(page)), ", ".join(trouves(page)))
        lib.connecter_admin()
        sql(f"UPDATE glpi_plugin_printgestion_raccordements SET date_triggered = {lib.q(heure(-60))} WHERE id = {racc};")
        _, page, _ = WEB.get(config.FRONT + f"/raccordement.php?id={racc}")
        constat("1 min après le déclenchement : relance automatique en place, pas d'arrêt", ok_ko("Vérification arrêtée" not in page and "pg-racc-autoverify" in page))
        CTX.connecter("test-technicien")
        for statut_racc, libelle in (("configured", "collecte configurée, avant déclenchement"), ("open", "ouvert, à l'étape 3 (identifiants SNMP)")):
            sql(f"UPDATE glpi_plugin_printgestion_raccordements SET status = '{statut_racc}', date_configured = {'NOW()' if statut_racc == 'configured' else 'NULL'} WHERE id = {racc};")
            _, page, _ = WEB.get(config.FRONT + f"/raccordement.php?id={racc}")
            constat(f"raccordement {libelle}, technicien : aucun détail réservé", ok_ko(not trouves(page)), ", ".join(trouves(page)))
        lib.connecter_admin()
        sql(f"DELETE FROM glpi_plugin_printgestion_raccordementips WHERE plugin_printgestion_raccordements_id = {racc}; "
            f"DELETE FROM glpi_plugin_printgestion_raccordementlogs WHERE plugin_printgestion_raccordements_id = {racc}; "
            f"DELETE FROM glpi_plugin_printgestion_raccordements WHERE id = {racc};")

        section("11 bis. Assistant : une étape à la fois, sélecteurs natifs, lignes cliquables")
        sql(f"INSERT INTO glpi_plugin_printgestion_raccordements (entities_id, agents_id, status, users_id, date_creation, date_mod) "
            f"VALUES ({d.CLIENT_A}, {agent}, 'open', 2, NOW(), NOW());")
        pas = int(valeur("SELECT MAX(id) FROM glpi_plugin_printgestion_raccordements"))
        sql(f"INSERT INTO glpi_plugin_printgestion_raccordementips (plugin_printgestion_raccordements_id, ip, ip_num) VALUES ({pas}, '192.0.2.41', INET_ATON('192.0.2.41'));")

        # Une seule etape ouverte : les cartes des autres etapes ne doivent pas etre dans la page.
        _, p3, _ = WEB.get(config.FRONT + f"/raccordement.php?id={pas}&step=3")
        constat("étape 3 demandée : seule sa carte est rendue, pas celle des adresses ni celle de la configuration",
                ok_ko("Lieu, commentaire et contrat" in p3
                      and "Adresses IP des imprimantes" not in p3
                      and "Créer la configuration de collecte" not in p3))
        _, p2, _ = WEB.get(config.FRONT + f"/raccordement.php?id={pas}&step=2")
        constat("étape 2 demandée : la saisie des adresses, et rien du lieu",
                ok_ko("Adresses IP des imprimantes" in p2 and "Enregistrer lieux, commentaires et contrats" not in p2))

        # La barre est la navigation, et chaque formulaire renvoie l'etape ou l'on travaille.
        constat("la barre 1 → 5 mène à chaque étape ouvrable",
                ok_ko(all(f"raccordement.php?id={pas}&step={n}" in p3 for n in (1, 2, 3, 4))))
        constat("l'étape voyage dans les formulaires : enregistrer ne renvoie pas ailleurs",
                ok_ko("name=\"step\"" in p3 or "name='step'" in p3))

        # Hors bornes : on retombe sur une etape ouvrable, jamais d'erreur.
        statut_loin, p_loin, _ = WEB.get(config.FRONT + f"/raccordement.php?id={pas}&step=99")
        constat("étape hors bornes : la page s'ouvre quand même sur une étape utile",
                ok_ko(statut_loin == 200 and "steps-counter" in p_loin), f"HTTP {statut_loin}")
        _, p5, _ = WEB.get(config.FRONT + f"/raccordement.php?id={pas}&step=5")
        constat("étape 5 avant toute découverte : refusée, on reste sur une étape qui a du sens",
                ok_ko("Application aux imprimantes" not in p5 or "Appliquer à" not in p5))

        # Les selecteurs de GLPI, et non plus un champ texte et un <select> maison.
        constat("lieu et contrat : les sélecteurs natifs de GLPI, plus un champ texte de chemin",
                ok_ko("locations_id" in p3 and "contracts_id" in p3 and "default[location]" not in p3))

        # Le journal ne se deplie plus tout seul.
        constat("le journal du raccordement est replié", ok_ko("<details" in p3 and "Journal du raccordement" in p3))

        # Le mot qui faisait croire a une panne.
        _, liste, _ = WEB.get(config.FRONT + "/raccordement.php")
        constat("liste des raccordements : gabarit natif, toute la ligne ouvre le raccordement",
                ok_ko("pg-datatable" in liste))
        sql(f"DELETE FROM glpi_plugin_printgestion_raccordementips WHERE plugin_printgestion_raccordements_id = {pas}; "
            f"DELETE FROM glpi_plugin_printgestion_raccordementlogs WHERE plugin_printgestion_raccordements_id = {pas}; "
            f"DELETE FROM glpi_plugin_printgestion_raccordements WHERE id = {pas};")

        # Un agent joignable ou non ne change rien a ce que GLPI sait faire : le texte ne doit plus dire l'inverse.
        source_cs = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "inc", "collectsetup.class.php"), encoding="utf-8").read()
        constat("« pas de réponse » en avertissement : supprimé du code",
                ok_ko("pas de réponse. GLPI ne joint pas la sonde" not in source_cs))
        constat("sonde jamais vue : c'est le seul cas resté en avertissement",
                ok_ko("n\\'a encore jamais contacté GLPI : vérifier que l\\'agent tourne" in source_cs))
        constat("réveil manqué : une échéance, plus un déplacement",
                ok_ko("getPickupSentence" in source_cs and "cliquez « Force an Inventory » pour lancer" not in source_cs))

        section("11 ter. Actions massives, vignettes alignées, découverte lancée")
        source_ra = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "inc", "raccordement.class.php"), encoding="utf-8").read()
        constat("le fichier d'installation ne s'arrête plus à la configuration : il lance la découverte",
                ok_ko("Collectsetup::trigger($racc)" in source_ra and "'triggered' => $lancement['ok']" in source_ra))
        constat("un raccordement supprimé emporte ses adresses et son journal",
                ok_ko("function cleanDBonPurge" in source_ra))
        source_fr = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "front", "agentreport.php"), encoding="utf-8").read()
        constat("le serveur demande au PC de réveiller son agent quand la découverte est armée",
                ok_ko("$reponse = 'RUN';" in source_fr))
        source_ad = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "inc", "agentdeploy.class.php"), encoding="utf-8").read()
        constat("le réveil passe par l'interface locale de l'agent, jamais par le serveur",
                ok_ko("'http://127.0.0.1:' . Agent::DEFAULT_PORT . '/now'" in source_ad))

        # Les trois listes portent les cases a cocher de GLPI, et les vignettes ne se fabriquent plus a la main.
        for libelle, adresse, attendu in (
            ("sondes", config.FRONT + "/sondes.php", "printgestionSondesStatsBar"),
            ("raccordements", config.FRONT + "/raccordement.php", "massive_action_checkbox"),
            ("contrôle de la remontée", config.FRONT + "/collect.php", "massive_action_checkbox"),
        ):
            _, page_liste, _ = WEB.get(adresse)
            constat(f"liste « {libelle} » : rendue avec {attendu}", ok_ko(attendu in page_liste))
        _, page_sondes, _ = WEB.get(config.FRONT + "/sondes.php")
        constat("sondes : cases à cocher et barre d'actions massives du gabarit natif",
                ok_ko("massive_action_checkbox" in page_sondes and "pg-datatable" in page_sondes))
        # La barre naît cachée : ouvrir le menu sans rien avoir coché ne donnerait qu'une liste d'actions vide.
        source_css = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "public", "css", "printgestion.css"), encoding="utf-8").read()
        constat("la barre d'actions naît cachée, révélée au premier élément coché",
                ok_ko(".pg-datatable:not(.pg-coche) > .mb-2:first-child" in source_css))
        # Plus aucun assembleur maison de liste : le gabarit components/datatable.html.twig du cœur.
        inc = os.path.join(config.GLPI_DIR, "plugins", "printgestion", "inc")
        sources = {n: io.open(os.path.join(inc, n), encoding="utf-8").read() for n in os.listdir(inc) if n.endswith(".php")}
        constat("listes d'objets : gabarit natif, plus d'assembleurs massiveOpen/massiveBox",
                ok_ko("components/datatable.html.twig" in sources["ui.class.php"]
                      and not any("massiveOpen(" in t or "massiveBox(" in t for t in sources.values())))
        # Tables et tâches de GLPI : par leurs classes, jamais écrites à la main.
        constat("cartouches natives : par la classe Cartridge, plus d'écriture directe dans glpi_cartridges",
                ok_ko(not any("insert('glpi_cartridges'" in t or "update('glpi_cartridges'" in t for t in sources.values())
                      and "new Cartridge()" in sources["cartridgehistory.class.php"]))
        constat("tâches automatiques : par CronTask (update, resetState), plus d'écriture directe dans glpi_crontasks",
                ok_ko(not any("update(CronTask::getTable()" in t for t in sources.values())
                      and "resetState()" in sources["confighealth.class.php"]))
        constat("SQL brut : seuls restent la création des tables et l'upsert groupé des relevés",
                ok_ko(sum(t.count("doQuery(") for t in sources.values()) == 2
                      and "DROP TABLE" not in "".join(sources.values()) and "TRUNCATE" not in "".join(sources.values())))
        constat("raccordement : options de recherche natives",
                ok_ko("function rawSearchOptions" in sources["raccordement.class.php"]))
        source_ui = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "inc", "ui.class.php"), encoding="utf-8").read()
        constat("la sélection mémorisée par GLPI est oubliée à chaque rendu de liste",
                ok_ko("unset($_SESSION['glpimassiveactionselected'][$itemtype]);" in source_ui))
        constat("sondes : plus de rangée de cartes fabriquée à la main",
                ok_ko("row row-cards" not in page_sondes))
        constat("le réglage « Statut GLPI des PC sondes » a quitté l'écran de consultation",
                ok_ko("save_probe_state" not in page_sondes))
        _, page_installeur, _ = WEB.get(config.FRONT + "/agentdeploy.php")
        constat("le réglage « Statut GLPI des PC sondes » est passé avec les autres réglages",
                ok_ko("save_probe_state" in page_installeur))

        section("11 quater. Fenêtre unique, journal, modules de la sonde")
        source_ad = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "inc", "agentdeploy.class.php"), encoding="utf-8").read()
        source_cs = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "inc", "collectsetup.class.php"), encoding="utf-8").read()
        source_fr = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "front", "agentreport.php"), encoding="utf-8").read()
        _, octets_win = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        fichier_win = octets_win.decode("utf-8", "replace")
        constat("Windows : PowerShell lancé sans console, et plus aucun « pause » après",
                ok_ko("-WindowStyle Hidden" in fichier_win and 'if not "%RC%"=="0" pause' not in fichier_win))
        constat("Windows : une seule fenêtre — plus de fenêtre d'avancement séparée ni de boîte de message",
                ok_ko("$avance = New-Object" not in fichier_win and "MessageBox]::Show" not in fichier_win and "function PageEtapes" in fichier_win))
        constat("Windows : le journal de l'installation, dans le dossier temporaire",
                ok_ko("PrintGestion\\installation-" in fichier_win))
        constat("Windows : l'étape « Premier contact » attend que l'agent local ait fini son passage",
                ok_ko('"contact" "encours"' in fichier_win and "waiting" in fichier_win))
        lignes_rem = [l for l in fichier_win.splitlines() if l.lower().startswith(("rem ", "title "))]
        constat("Windows : aucun < > | & dans un rem ou un title (cmd les exécuterait)",
                ok_ko(not any(c in l for l in lignes_rem for c in "<>|&")), " / ".join(l for l in lignes_rem if any(c in l for c in "<>|&")))
        _, octets_ret = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows-retrait")
        fichier_ret = octets_ret.decode("utf-8", "replace")
        constat("retrait Windows : même fenêtre (confirmation, étapes, résultat) et même journal",
                ok_ko("function PageEtapes" in fichier_ret and "retrait-" in fichier_ret and "-WindowStyle Hidden" in fichier_ret))
        constat("la communauté SNMP n'est jamais écrite dans le journal",
                ok_ko("jamais ecrite dans ce journal" in fichier_win and 'Journal ("Communaute SNMP : " + $communaute' not in fichier_win))
        constat("macOS : la fenêtre Cocoa existe et le fichier de l'entité la recopie",
                ok_ko(os.path.exists(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "resources", "macos-fenetre.js"))
                      and "PRINTGESTION_JS" in source_ad and "launchctl asuser" in source_ad))
        constat("Linux : zenity sous le compte de la personne connectée, jamais en root",
                ok_ko('sudo -u "$SUDO_USER" env DISPLAY=' in source_ad))
        constat("modules de la sonde : le profil « imprimantes » est posé dès le compte rendu",
                ok_ko("applyPrinterProbeProfile($sonde)" in source_fr and "'NETWORKDISCOVERY'     => true" in source_cs
                      and "'DEPLOY'               => false" in source_cs and "'INVENTORY'            => true" in source_cs))
        constat("GLPI dit au PC quand il ne connaît pas encore la sonde (NOAGENT)",
                ok_ko("$reponse = 'NOAGENT';" in source_fr))

        section("12. Contenu réel des paquets : fichiers attendus, réglages exacts, aucun secret")
        lib.connecter_admin()
        secret = re.compile(r"(?i)\b(password|passwd|pwd|token|community|communaute|secret|api_?key|client_?secret|authorization|bearer)\s*=")
        # logger, logfile, logfile-maxsize : le journal de l'agent, dans un fichier connu, pour le dépannage.
        cles_agent = {"server", "tag", "tasks", "httpd-trust", "snmp-retries", "logger", "logfile", "logfile-maxsize"}
        url = d.CORE["url_base"] + "/"
        with open(os.path.join(config.GLPI_DIR, "files", "_plugins", "printgestion", "agent", "installer.json"), encoding="utf-8") as fichier:
            sha_msi = json.load(fichier)["sha256"]
        _, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows-zip")
        with zipfile.ZipFile(io.BytesIO(octets)) as paquet:
            noms = sorted(paquet.namelist())
            textes = {n: paquet.read(n).decode("utf-8", "replace") for n in noms if n.lower().endswith((".txt", ".bat", ".cmd", ".ps1"))}
            msi = [n for n in noms if n.lower().endswith(".msi")]
            sha = hashlib.sha256(paquet.read(msi[0])).hexdigest() if msi else ""
        base = {"INSTALLER-GLPI-AGENT.bat", "LISEZMOI.txt", "fichiers/commande-cmd.txt", "fichiers/fenetre-installation.ps1"}
        maj = {"fichiers/glpi-agent-update.cmd"}
        constat("Windows : le MSI officiel (empreinte de installer.json) et les fichiers attendus, rien d'autre",
                ok_ko(len(msi) == 1 and sha == sha_msi and set(noms) - set(msi) in (base, base | maj)), ", ".join(noms))
        # Un seul fichier exécutable dans le dossier : celui qu'on lance. Le .cmd de mise à jour est une donnée,
        # copiée vers %ProgramData% par le .bat et lancée ensuite par la tâche planifiée.
        constat("Windows : un seul .bat dans le paquet — celui qu'on lance",
                ok_ko(len([n for n in noms if n.lower().endswith(".bat")]) == 1), ", ".join(noms))
        # À la racine du ZIP : le fichier à lancer et le mode d'emploi, rien d'autre. Le reste est une donnée,
        # rangée dans « fichiers/ » — un dossier qui montre six éléments dont un seul se lance ne dit pas lequel.
        racine = sorted(n for n in noms if "/" not in n.strip("/"))
        constat("Windows : à la racine du ZIP, seulement le fichier à lancer et le mode d'emploi",
                ok_ko(set(racine) == {"INSTALLER-GLPI-AGENT.bat", "LISEZMOI.txt"}), ", ".join(racine))
        bat_zip = textes.get("INSTALLER-GLPI-AGENT.bat", "")
        fenetre = textes.get("fichiers/fenetre-installation.ps1", "")
        # La fenêtre pose la question, la console reste en repli : sans PowerShell, l'installation doit aboutir quand
        # même, et « non » doit rester la réponse par défaut dans les deux cas.
        constat("Windows : le .bat lance la fenêtre, et garde la question en console en repli",
                ok_ko("fenetre-installation.ps1" in bat_zip and "-STA -File" in bat_zip and "choice /C ON /T 20 /D N" in bat_zip
                      and 'if not defined PGMAJ' in bat_zip))
        constat("Windows : dans la fenêtre, la mise à jour automatique est décochée par défaut",
                ok_ko("$maj.Checked = $false" in fenetre and "ShowDialog" in fenetre and '"ANNULE"' in fenetre))
        proprietes = dict(re.findall(r' ([A-Z_]+)="([^"]*)"', bat_zip))
        constat("Windows : propriétés MSI exactement SERVER, TAG, ADDLOCAL, HTTPD_TRUST, SNMP_RETRIES, RUNNOW, EXECMODE, QUICKINSTALL ; SERVER, TAG et HTTPD_TRUST justes",
                ok_ko(set(proprietes) == {"SERVER", "TAG", "ADDLOCAL", "HTTPD_TRUST", "SNMP_RETRIES", "RUNNOW", "EXECMODE", "QUICKINSTALL"}
                      and proprietes.get("SERVER") == url and proprietes.get("TAG") == "CLIENT-TEST-A" and proprietes.get("HTTPD_TRUST", "").startswith("127.0.0.1/32")), str(proprietes))
        fuites = [n for n, t in textes.items() if secret.search(t)]
        constat("Windows : aucun mot de passe, communauté, jeton ni clé dans les fichiers texte", ok_ko(not fuites), ", ".join(fuites))

        section("12 bis. Fichiers uniques : un par système, chacun sa clé de récupération")
        # Ce que les trois boutons servent : un seul fichier, qui va chercher l'installeur officiel sur ce serveur
        # avec une clé à usage unique. Les deux scripts shell sont donnés à « sh -n » : c'est la seule vérification de
        # syntaxe possible sans installer pour de vrai, et elle porte sur le fichier réel.
        empreintes = {}
        for asset, fichier in (("windows", "installer.json"), ("linux", "installer-linux.json"),
                               ("macos-arm64", "installer-macos-arm64.json"), ("macos-x86_64", "installer-macos-x86_64.json")):
            with open(os.path.join(config.GLPI_DIR, "files", "_plugins", "printgestion", "agent", fichier), encoding="utf-8") as descr:
                empreintes[asset] = json.load(descr)["sha256"]
        for systeme, libelle, amorce in (("windows", "Windows", "@echo off"), ("linux", "Linux", "#!/bin/sh"), ("macos", "macOS", "#!/bin/sh")):
            statut, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os={systeme}")
            texte = octets.decode("utf-8", "replace")
            liens = re.findall(r"agentpull\.php\?t=[a-f0-9]{48}(?:&a=[a-zA-Z0-9_-]+)?", texte)
            jetons = {lien.split("t=")[1].split("&")[0] for lien in liens}
            constat(f"{libelle} : un seul fichier servi, qui commence par « {amorce} », avec une seule clé de 48 hexadécimaux",
                    ok_ko(statut == 200 and texte.startswith(amorce) and len(jetons) == 1 and len(liens) == (2 if systeme == "macos" else 1)),
                    f"HTTP {statut}, {len(octets)} octets, {len(liens)} lien(s), {len(jetons)} clé(s)")
            constat(f"{libelle} : le TAG du client et l'empreinte attendue sont dedans, aucun mot de passe",
                    ok_ko("CLIENT-TEST-A" in texte and not secret.search(texte)
                          and all(empreintes[a] in texte for a in (["windows"] if systeme == "windows" else ["linux"] if systeme == "linux" else ["macos-arm64", "macos-x86_64"]))))
            if systeme == "windows":
                # La moitié cmd est lue dans la page de code du poste : un accent y serait illisible. Elle doit aussi
                # s'arrêter avant le marqueur, sinon cmd essaierait d'exécuter du PowerShell ligne par ligne.
                avant_ps, _, apres_ps = texte.partition("#PG-POWERSHELL")
                ascii_ok = True
                try:
                    avant_ps.encode("ascii")
                except UnicodeEncodeError:
                    ascii_ok = False
                constat("Windows : marqueur unique, partie cmd en ASCII pur arrêtée avant lui, fenêtre avec la case décochée",
                        ok_ko(texte.count("#PG-POWERSHELL") == 1 and ascii_ok and "exit /b 0" in avant_ps
                              and "-WindowStyle Hidden" in avant_ps
                              and "-STA -File" in avant_ps and "$maj.Checked = $false" in apres_ps
                              and "[Security.Cryptography.SHA256]" in apres_ps))
                proprietes_seul = dict(re.findall(r' ([A-Z_]+)="([^"]*)"', apres_ps))
                constat("Windows : mêmes propriétés MSI que l'archive, SERVER et TAG justes",
                        ok_ko(set(proprietes_seul) == {"SERVER", "TAG", "ADDLOCAL", "HTTPD_TRUST", "SNMP_RETRIES", "RUNNOW", "EXECMODE", "QUICKINSTALL"}
                              and proprietes_seul.get("SERVER") == url and proprietes_seul.get("TAG") == "CLIENT-TEST-A"), str(proprietes_seul))
            else:
                chemin = os.path.join(tempfile.gettempdir(), f"pg-verif-{systeme}.sh")
                with open(chemin, "w", encoding="utf-8", newline="\n") as script:
                    script.write(texte)
                analyse = subprocess.run(["sh", "-n", chemin], capture_output=True, text=True) if shutil.which("sh") else None
                os.unlink(chemin)
                constat(f"{libelle} : le script est accepté par « sh -n » (syntaxe du shell)",
                        ok_ko(analyse is None or analyse.returncode == 0),
                        "sh absent du poste de test" if analyse is None else (analyse.stdout + analyse.stderr).strip()[:200])
                attendus = ["pg_etape", "pg_empreinte", "sudo sh", "pg_journal"] + (
                    ["uname -m", "installer -pkg", "launchctl", "osascript -l JavaScript", "PRINTGESTION_JS"] if systeme == "macos"
                    else ["perl ", "id -u", "--forms", "--progress"])
                manquants = [m for m in attendus if m not in texte]
                constat(f"{libelle} : fenêtre, empreinte vérifiée et installation dans le même fichier", ok_ko(not manquants), ", ".join(manquants))
            # Tout l'intérêt de la clé : le PC d'un client n'a aucun compte GLPI. Session neuve, sans cookie.
            attendu = empreintes["windows"] if systeme == "windows" else empreintes["linux"] if systeme == "linux" else empreintes["macos-arm64"]
            lien = [l for l in liens if "macos-arm64" in l or systeme != "macos"][0]
            anonyme = lib.Session()
            statut_cle, telecharge = anonyme.telecharger(config.FRONT + "/" + lien)
            constat(f"{libelle} : la clé sert le vrai installeur officiel sans être connecté",
                    ok_ko(statut_cle == 200 and hashlib.sha256(telecharge).hexdigest() == attendu), f"HTTP {statut_cle}, {len(telecharge)} octets")
            statut_rejeu, _ = anonyme.telecharger(config.FRONT + "/" + lien)
            constat(f"{libelle} : une seule fois — le second appel est refusé", ok_ko(statut_rejeu == 404), f"HTTP {statut_rejeu}")
        section("12 bis 2. Versions : des menus, plus des champs libres")
        # Le mémo est écrit ici : la vérification ne dépend d'aucun accès à GitHub depuis l'instance de test.
        memo = lambda liste: sql(  # noqa: E731
            "DELETE FROM glpi_configs WHERE context = 'plugin:printgestion' "
            "AND name IN ('agent_published_versions', 'agent_published_versions_at'); "
            "INSERT INTO glpi_configs (context, name, value) VALUES "
            f"('plugin:printgestion', 'agent_published_versions', '{liste}'), "
            "('plugin:printgestion', 'agent_published_versions_at', UNIX_TIMESTAMP());")
        memo('["1.20","1.19","1.18"]')
        sql("UPDATE glpi_plugin_printgestion_configs SET agent_version = '1.19' WHERE id = 1;")
        _, page, _ = WEB.get(config.FRONT + "/agentdeploy.php")
        balises = lib.Balises()
        balises.feed(page)
        selects = {a.get("name"): a for t, a in balises.balises if t == "select"}
        options = re.findall(r"<select[^>]*name='agent_version'[^>]*>(.*?)</select>", page, re.S)
        libre = lambda nom: f"<input type='text' class='form-control' name='{nom}'" in page  # noqa: E731
        constat("version des agents : un menu déroulant, plus un champ libre, et une seule version pour tout",
                ok_ko("agent_version" in selects and not libre("agent_version")
                      and "agent_update_target" not in page), ", ".join(sorted(selects)))
        constat("le menu propose les versions publiées, la valeur réglée est sélectionnée",
                ok_ko(len(options) == 1 and "1.20" in options[0] and "1.18" in options[0]
                      and "value='1.19' selected" in options[0]))
        # Une version épinglée qui a disparu de GitHub ne doit pas être perdue en silence : tout un parc basculerait.
        sql("UPDATE glpi_plugin_printgestion_configs SET agent_version = '1.11' WHERE id = 1;")
        _, page, _ = WEB.get(config.FRONT + "/agentdeploy.php")
        servie = re.findall(r"<select[^>]*name='agent_version'[^>]*>(.*?)</select>", page, re.S)
        constat("une version réglée mais absente de la liste reste proposée et sélectionnée",
                ok_ko(len(servie) == 1 and "value='1.11' selected" in servie[0]))
        # Liste jamais récupérée : le champ libre revient, plutôt qu'un menu vide qui empêcherait tout changement.
        memo("[]")
        _, page, _ = WEB.get(config.FRONT + "/agentdeploy.php")
        constat("liste non récupérée : le champ libre revient, avec la raison",
                ok_ko(f"<input type='text' class='form-control' name='agent_version'" in page
                      and "<select" not in page[page.find("agent_version") - 300:page.find("agent_version")]
                      and "GitHub injoignable" in lib.texte(page)))
        memo('["1.20","1.19","1.18"]')
        sql("UPDATE glpi_plugin_printgestion_configs SET agent_version = '' WHERE id = 1;")

        # La carte tiroir « Prochains paquets… » a disparu : chaque réglage est là où il sert.
        _, page, _ = WEB.get(config.FRONT + "/agentdeploy.php")
        constat("page Installeur : la carte fourre-tout a disparu, ses réglages sont dans les paramètres",
                ok_ko("Prochains paquets" not in page and "name='agent_update_default'" in page
                      and "name='agent_probe_states_id'" not in page
                      and page.count("name='save_settings'") == 1 and "save_update_defaults" not in page))
        # La saisie de secours ne s'affiche que si GitHub n'a pas répondu : sinon elle invite à remplir pour rien.
        sql("UPDATE glpi_plugin_printgestion_configs SET agent_latest_source = 'github', agent_latest_version = '1.19' WHERE id = 1;")
        _, sans, _ = WEB.get(config.FRONT + "/agentdeploy.php")
        sql("UPDATE glpi_plugin_printgestion_configs SET agent_latest_source = '', agent_latest_version = '' WHERE id = 1;")
        _, avec, _ = WEB.get(config.FRONT + "/agentdeploy.php")
        constat("dernière version à la main : cachée quand GitHub répond, proposée sinon",
                ok_ko("name='agent_latest_version'" not in sans and "name='agent_latest_version'" in avec))
        sql("UPDATE glpi_plugin_printgestion_configs SET agent_latest_source = 'github', agent_latest_version = '1.19' WHERE id = 1;")
        # Le statut des PC sondes est un réglage : il se règle avec les autres, sur la page de l'installeur.
        constat("statut des PC sondes : réglé sur la page « Installeur GLPI Agent », avec les autres réglages",
                ok_ko("name='agent_probe_states_id'" in avec and "name='save_probe_state'" in avec))

        section("12 bis 3. Retrait d'une sonde, saisie des adresses, raccordement créé tout seul")
        # Retrait : un fichier par système, qui défait ce que l'installation a posé.
        retraits = {}
        for systeme, libelle in (("windows", "Windows"), ("linux", "Linux"), ("macos", "macOS")):
            statut, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os={systeme}-retrait")
            retraits[systeme] = octets.decode("utf-8", "replace")
            constat(f"retrait {libelle} : fichier servi", ok_ko(statut == 200 and len(octets) > 200), f"HTTP {statut}, {len(octets)} octets")
        constat("retrait Windows : tâches planifiées, fichiers du plugin, puis désinstallation par le registre",
                ok_ko("schtasks /Delete" in retraits["windows"] and "PrintGestion" in retraits["windows"]
                      and "msiexec.exe" in retraits["windows"] and "Uninstall" in retraits["windows"]))
        constat("retrait Linux : cron, configuration, puis le gestionnaire de paquets de la distribution",
                ok_ko("cron.monthly" in retraits["linux"] and "90-printgestion.cfg" in retraits["linux"]
                      and "apt-get -y remove glpi-agent" in retraits["linux"] and "zypper" in retraits["linux"]))
        constat("retrait macOS : service arrêté, dossier retiré, paquet oublié",
                ok_ko("launchctl bootout" in retraits["macos"] and "/Applications/GLPI-Agent" in retraits["macos"]
                      and "pkgutil --forget" in retraits["macos"]))
        for systeme in ("linux", "macos"):
            chemin = os.path.join(tempfile.gettempdir(), f"pg-retrait-{systeme}.sh")
            with open(chemin, "w", encoding="utf-8", newline="\n") as fichier:
                fichier.write(retraits[systeme])
            analyse = subprocess.run(["sh", "-n", chemin], capture_output=True, text=True) if shutil.which("sh") else None
            os.unlink(chemin)
            constat(f"retrait {systeme} : script accepté par « sh -n »",
                    ok_ko(analyse is None or analyse.returncode == 0),
                    "sh absent du poste de test" if analyse is None else (analyse.stdout + analyse.stderr).strip()[:200])
        fuites = [s for s, t in retraits.items() if secret.search(t)]
        constat("retraits : aucun identifiant dedans", ok_ko(not fuites), ", ".join(fuites))
        # Trois choix exclusifs pour GLPI — rien, la sonde, tout — le premier pris par défaut, et le rang choisi
        # part avec le compte rendu (gl=1 ou gl=2).
        # Un fichier encore ouvert ne doit pas laisser un dossier derrière : on ferme, puis on réessaie.
        constat("retrait Windows : ce qui tient un fichier est fermé, et la suppression est retentée",
                ok_ko("function Fermer($dossier)" in retraits["windows"]
                      and "$essai -lt 3 -and -not $efface" in retraits["windows"]
                      and "StartsWith($dossier" in retraits["windows"]))
        constat("retrait Windows : trois boutons radio, le premier coché, et le rang envoyé au serveur",
                ok_ko("System.Windows.Forms.RadioButton" in retraits["windows"]
                      and "$r.Checked = ($rang -eq 0)" in retraits["windows"]
                      and '$gl = "&gl=" + $script:niveau' in retraits["windows"]))
        constat("retrait Linux : liste à choix unique (zenity n'a pas de case), premier choix pris",
                ok_ko("--radiolist" in retraits["linux"] and "TRUE 0 " in retraits["linux"]
                      and 'pg_gl="&gl=$PG_GLPI"' in retraits["linux"]))
        constat("retrait macOS : trois choix dans la fenêtre Cocoa, relus dans les réponses",
                ok_ko('"choix_glpi"' in retraits["macos"] and 'grep "^glpi=" "$PG_DIR/reponses"' in retraits["macos"]
                      and 'pg_gl="&gl=$PG_GLPI"' in retraits["macos"]))
        constat("retraits : la fenêtre dit ce que GLPI a répondu (supprimée, tout supprimé, introuvable, refusée)",
                ok_ko(all("PURGE OK" in t and "PURGE ABSENT" in t and "PURGE REFUSE" in t and "PURGE TOTAL" in t
                          for t in retraits.values())))
        # Un double clic ne doit pas lancer deux fois le même travail : verrou commun à l'installation et au retrait.
        installs = {}
        for systeme in ("windows", "linux", "macos"):
            _, octets_i = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os={systeme}")
            installs[systeme] = octets_i.decode("utf-8", "replace")
        verrou = lambda texte, systeme: ("PrintGestion-GLPI-Agent" in texte and "Mutex" in texte) if systeme == "windows" \
            else ("if ! pg_prendre" in texte and "pg_liberer" in texte)  # noqa: E731
        sans_verrou = [f"{quoi} {systeme}" for quoi, fichiers in (("retrait", retraits), ("installation", installs))
                       for systeme, texte in fichiers.items() if not verrou(texte, systeme)]
        constat("une seule exécution à la fois : verrou dans les six fichiers, installation et retrait",
                ok_ko(not sans_verrou), ", ".join(sans_verrou))

        # La fenêtre demande les adresses, et le fichier sait les rapporter.
        _, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        unique = octets.decode("utf-8", "replace")
        constat("fenêtre Windows : deux champs pour les adresses et la communauté SNMP, rapportés au serveur",
                ok_ko("$ips = New-Object System.Windows.Forms.TextBox" in unique
                      and '$snmp.Text = "public"' in unique
                      and '"&ips=" + [Uri]::EscapeDataString($adresses)' in unique
                      and '"&snmp=" + [Uri]::EscapeDataString($communaute)' in unique))
        constat("fenêtre Windows : un menu de fréquence, fermé, avec « une fois par jour » choisi d'avance",
                ok_ko("$freq = New-Object System.Windows.Forms.ComboBox" in unique
                      and '$freq.DropDownStyle = "DropDownList"' in unique
                      and unique.count("$freq.Items.Add(") == 6
                      and "$freq.SelectedIndex = 3" in unique
                      and "daily:30" in unique))
        # Sans GLPI Inventory, le scan est confié à la ToolBox native de l'agent : plus de tâche planifiée maison.
        constat("fichier Windows : sait confier le scan local à la ToolBox de l'agent si le serveur le demande",
                ok_ko('if ($reponse -like "SCAN *")' in unique and "toolbox.yaml" in unique
                      and "toolbox-plugin.local" in unique and "forbid_not_trusted = yes" in unique
                      and "target: server0" in unique and "glpi-netdiscovery" not in unique
                      and "glpi-injector" not in unique))
        # Beaucoup d'imprimantes n'exposent que SNMPv1 : les deux versions sont posées, v2c d'abord.
        constat("fichier Windows : la ToolBox essaie v2c puis v1, avec la même communauté",
                ok_ko("snmpversion: v2c" in unique and "snmpversion: v1" in unique
                      and unique.index("snmpversion: v2c") < unique.index("snmpversion: v1")
                      and unique.count("printgestion-snmp") >= 3))
        constat("fichier Windows : toolbox.yaml réservé à SYSTEM et aux administrateurs (il porte la communauté)",
                ok_ko("/inheritance:r /grant:r *S-1-5-18:F *S-1-5-32-544:F" in unique))
        constat("fichier Windows : l'ancienne tâche de scan maison est retirée",
                ok_ko("glpi-scan-imprimantes.cmd" in unique and "schtasks /Delete" in unique))
        constat("fichier Windows : le chemin du journal de l'agent est donné à la fin",
                ok_ko("Journal de l agent" in unique))
        # Aucune panne muette : avant la fenêtre, une boîte de message ; un script illisible, arrêté par le lanceur.
        constat("fichier Windows : une erreur avant la fenêtre s'affiche quand même (Secours)",
                ok_ko("function Secours($texte)" in unique and "$script:fenetre_prete = $true" in unique
                      and "} else { Secours $texte }" in unique))
        constat("fichier Windows : le lanceur analyse le script avant de le lancer",
                ok_ko("Parser]::ParseFile($env:PGPS" in unique and "if errorlevel 2 exit /b 1" in unique))
        source_psq = io.open(os.path.join(config.GLPI_DIR, "plugins", "printgestion", "inc", "agentdeploy.class.php"), encoding="utf-8").read()
        constat("psQuote double aussi les apostrophes typographiques",
                ok_ko(r'"\u{2019}\u{2019}"' in source_psq))
        # PowerShell part masqué : Windows masque aussi le premier affichage de la fenêtre. Le deuxième est respecté.
        constat("fichier Windows : la fenêtre est affichée deux fois, sinon elle reste invisible",
                ok_ko("$f.Show(); $f.Hide(); $f.Show()" in unique and source_psq.count("$f.Show(); $f.Hide(); $f.Show()") == 2
                      and "'$f.Show()'," not in source_psq))
        # Qui pilote le scan : le choix n'existe que si GLPI Inventory est là ; sinon une phrase le dit, et le
        # mode local est le seul possible. Dans les deux cas, le compte rendu porte le mode.
        pilote = valeur("SELECT IFNULL(state, 0) FROM glpi_plugins WHERE directory = 'glpiinventory'") == "1"
        # Le suivi : la fenêtre attend et annonce les imprimantes trouvées, au lieu de laisser le technicien
        # repartir sans savoir. L'adresse du suivi vient du serveur, jamais du fichier.
        constat("fichier Windows : la fenêtre attend le résultat de la découverte et le nomme",
                ok_ko("function Suivre($url)" in unique and '$lignes[0] -like "TROUVE *"' in unique
                      and "$script:noms" in unique))
        constat("fenêtre Windows : le mode de scan part avec le compte rendu",
                ok_ko('"&mode=" + $script:pilotage' in unique))
        constat("fenêtre Windows : choix du pilotage présent avec GLPI Inventory, phrase d'explication sinon",
                ok_ko(("Qui pilote le scan" in unique) if pilote else ("n'est pas installé sur ce serveur" in unique)),
                "GLPI Inventory actif" if pilote else "GLPI Inventory absent")
        cle_rendu = re.search(r"agentreport\.php\?t=([a-f0-9]{48})", unique)

        # Raccordement créé tout seul : la sonde de test est rattachée à un ordinateur, puis un PC rend compte.
        sql(f"INSERT INTO glpi_computers (name, entities_id, is_deleted) VALUES ('PG-SONDE-AUTO', {d.CLIENT_A}, 0);")
        pc_id = int(valeur("SELECT id FROM glpi_computers WHERE name = 'PG-SONDE-AUTO'"))
        sql(f"UPDATE glpi_agents SET itemtype = 'Computer', items_id = {pc_id} WHERE id = {agent};")
        anonyme = lib.Session()
        # Sans communauté : la création s'arrête après les adresses, donc rien n'est créé dans le plugin voisin.
        statut_rendu, _ = anonyme.telecharger(
            config.FRONT + f"/agentreport.php?t={cle_rendu.group(1) if cle_rendu else 'x'}&maj=1&pc=PG-SONDE-AUTO&ips=192.168.55.10-12&snmp=")
        racc_id = valeur(f"SELECT id FROM glpi_plugin_printgestion_raccordements WHERE agents_id = {agent} ORDER BY id DESC LIMIT 1")
        ips_count = valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_raccordementips WHERE plugin_printgestion_raccordements_id = {racc_id}") if racc_id else "0"
        constat("adresses déclarées depuis le PC : raccordement créé dans GLPI, avec ses trois adresses",
                ok_ko(statut_rendu in (200, 204) and racc_id != "" and ips_count == "3"),
                f"HTTP {statut_rendu}, raccordement {racc_id or '—'}, {ips_count} adresse(s)")
        # Mode local demandé depuis le PC : aucun raccordement créé, et le serveur renvoie la plage à scanner.
        _, octets_local = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        cle_local = re.search(r"agentreport\.php\?t=([a-f0-9]{48})", octets_local.decode("utf-8", "replace"))
        avant_racc = valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_raccordements WHERE agents_id = {agent}")
        _, corps_local = lib.Session().telecharger(
            config.FRONT + f"/agentreport.php?t={cle_local.group(1) if cle_local else 'x'}&maj=1&pc=PG-SONDE-AUTO"
            + "&ips=192.168.55.20-22&snmp=public&mode=local")
        apres_racc = valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_raccordements WHERE agents_id = {agent}")
        constat("scan local demandé depuis le PC : rien n'est créé dans GLPI, la plage est renvoyée à l'agent",
                ok_ko(corps_local.startswith(b"SCAN ") and apres_racc == avant_racc),
                f"réponse {corps_local[:32]!r}, raccordements {avant_racc} → {apres_racc}")
        # Le suivi : la réponse porte une adresse que le PC interroge pendant l'installation. Elle répond sans
        # session, ne dit que le compte et les noms, et une clé inventée n'ouvre rien.
        adresse = re.search(r"(/plugins/printgestion/front/agentprogress\.php\?t=[a-f0-9]{48})", corps_local.decode("utf-8", "replace"))
        statut_suivi, corps_suivi = lib.Session().telecharger(adresse.group(1)) if adresse else (0, b"")
        # En mode local il n'y a pas de raccordement : le suivi doit quand même nommer ce que la sonde a fait
        # entrer, sinon la fenêtre d'installation reste muette jusqu'au bout de son délai.
        constat("suivi : il sait répondre sans raccordement (mode local)",
                ok_ko("glpi_rulematchedlogs" in io.open(os.path.join(config.PLUGIN_DIR, "front", "agentprogress.php"), encoding="utf-8").read()))
        constat("compte rendu : une adresse de suivi est donnée au PC, et elle répond",
                ok_ko(adresse is not None and statut_suivi == 200
                      and (corps_suivi.startswith(b"ATTENTE") or corps_suivi.startswith(b"TROUVE") or corps_suivi.startswith(b"AUCUNE"))),
                f"HTTP {statut_suivi}, réponse {corps_suivi[:24]!r}")
        statut_faux, _ = lib.Session().telecharger(config.FRONT + "/agentprogress.php?t=" + "0" * 48)
        constat("suivi : une clé inventée n'ouvre rien", ok_ko(statut_faux not in (200, 302)), f"HTTP {statut_faux}")
        # Communauté saisie sur le PC : deux identifiants SNMP sont posés (v2c puis v1) et liés à la plage, car une
        # imprimante qui n'expose que SNMPv1 resterait muette avec le seul v2c.
        _, octets_snmp = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        cle_snmp = re.search(r"agentreport\.php\?t=([a-f0-9]{48})", octets_snmp.decode("utf-8", "replace"))
        lib.Session().telecharger(
            config.FRONT + f"/agentreport.php?t={cle_snmp.group(1) if cle_snmp else 'x'}&maj=1&pc=PG-SONDE-AUTO"
            + "&ips=192.168.56.10-12&snmp=pg-essai-snmp")
        versions = lib.lignes("SELECT snmpversion FROM glpi_snmpcredentials WHERE community = 'pg-essai-snmp' AND is_deleted = 0 ORDER BY snmpversion")
        constat("communauté déclarée depuis le PC : identifiants SNMP v2c ET v1 créés",
                ok_ko(len(versions) == 2 and {v[0] for v in versions} == {"1", "2"}),
                " / ".join(str(v) for v in versions) or "aucun")
        racc_snmp = valeur(f"SELECT id FROM glpi_plugin_printgestion_raccordements WHERE agents_id = {agent} ORDER BY id DESC LIMIT 1")
        liens = valeur("SELECT COUNT(*) FROM glpi_plugin_glpiinventory_ipranges_snmpcredentials l "
                       "JOIN glpi_snmpcredentials c ON c.id = l.snmpcredentials_id WHERE c.community = 'pg-essai-snmp'")
        constat("les deux identifiants sont liés à la plage, dans l'ordre", ok_ko(liens == "2"), f"{liens} liaison(s)")
        journal = valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_raccordementlogs WHERE plugin_printgestion_raccordements_id = {racc_id}") if racc_id else "0"
        constat("le raccordement automatique laisse son journal, comme celui de l'assistant",
                ok_ko(int(journal or 0) >= 2), f"{journal} ligne(s)")
        # La fréquence choisie sur le PC devient celle de l'entité — et c'est elle qui décide du délai au-delà
        # duquel une imprimante est dite « ne remonte plus ». Un parc relevé chaque mois ne doit pas être signalé
        # en retard au bout de trois jours.
        freq_avant = valeur(f"SELECT CONCAT(frequency, ':', modifier) FROM glpi_plugin_printgestion_collectfrequencies WHERE entities_id = {d.CLIENT_A}")
        _, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        cle_freq = re.search(r"agentreport\\.php\\?t=([a-f0-9]{48})", octets.decode("utf-8", "replace"))
        lib.Session().telecharger(config.FRONT + f"/agentreport.php?t={cle_freq.group(1) if cle_freq else 'x'}&maj=1&pc=PG-SONDE-AUTO&freq=daily:30")
        freq_apres = valeur(f"SELECT CONCAT(frequency, ':', modifier) FROM glpi_plugin_printgestion_collectfrequencies WHERE entities_id = {d.CLIENT_A}")
        constat("fréquence choisie sur le PC : enregistrée pour l'entité, donc le seuil d'alerte la suit",
                ok_ko(freq_apres == "daily:30"), f"avant {freq_avant or '—'}, après {freq_apres or '—'}")
        sql(f"DELETE FROM glpi_plugin_printgestion_collectfrequencies WHERE entities_id = {d.CLIENT_A};")
        racc_freq = valeur(f"SELECT id FROM glpi_plugin_printgestion_raccordements WHERE agents_id = {agent} ORDER BY id DESC LIMIT 1")
        if racc_freq and racc_freq != racc_id:
            sql(f"DELETE FROM glpi_plugin_printgestion_raccordementips WHERE plugin_printgestion_raccordements_id = {racc_freq}; "
                f"DELETE FROM glpi_plugin_printgestion_raccordementlogs WHERE plugin_printgestion_raccordements_id = {racc_freq}; "
                f"DELETE FROM glpi_plugin_printgestion_raccordements WHERE id = {racc_freq};")
        if racc_id:
            sql(f"DELETE FROM glpi_plugin_printgestion_raccordementips WHERE plugin_printgestion_raccordements_id = {racc_id}; "
                f"DELETE FROM glpi_plugin_printgestion_raccordementlogs WHERE plugin_printgestion_raccordements_id = {racc_id}; "
                f"DELETE FROM glpi_plugin_printgestion_raccordements WHERE id = {racc_id};")
        sql(f"UPDATE glpi_agents SET items_id = 0 WHERE id = {agent}; DELETE FROM glpi_computers WHERE id = {pc_id};")

        # Retrait, case cochée : le PC rend compte, et GLPI supprime la sonde avec ce que le plugin a créé pour elle.
        # Sonde et ordinateur dédiés : le reste de la suite continue de travailler sur les siens.
        def sonde_jetable(suffixe):
            sql(f"INSERT INTO glpi_computers (name, entities_id, is_deleted) VALUES ('PG-SONDE-{suffixe}', {d.CLIENT_A}, 0);")
            pc = int(valeur(f"SELECT id FROM glpi_computers WHERE name = 'PG-SONDE-{suffixe}'"))
            sql("INSERT INTO glpi_agents (deviceid, entities_id, name, agenttypes_id, last_contact, version, useragent, tag, locked, "
                f"itemtype, items_id, use_module_network_inventory, use_module_network_discovery) VALUES ('agent-test-{suffixe.lower()}', "
                f"{d.CLIENT_A}, 'PG-SONDE-{suffixe}', 1, NOW(), '1.19', 'GLPI-Agent_v1.19', {lib.q(tag_a)}, 0, 'Computer', {pc}, 1, 1);")
            sonde = int(valeur(f"SELECT id FROM glpi_agents WHERE deviceid = 'agent-test-{suffixe.lower()}'"))
            sql("INSERT INTO glpi_plugin_printgestion_agentsettings (agents_id, auto_update, users_id, date_creation, date_mod) "
                f"VALUES ({sonde}, 1, 0, NOW(), NOW());")
            return pc, sonde

        def cle_de_retrait():
            _, octets_r = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows-retrait")
            trouve = re.search(r"agentreport\.php\?t=([a-f0-9]{48})", octets_r.decode("utf-8", "replace"))
            return trouve.group(1) if trouve else "x"

        pc_purge, sonde_purge = sonde_jetable("PURGE")
        statut_purge, corps_purge = lib.Session().telecharger(
            config.FRONT + f"/agentreport.php?t={cle_de_retrait()}&maj=0&off=1&gl=1&pc=PG-SONDE-PURGE")
        reste = {
            "sonde": valeur(f"SELECT COUNT(*) FROM glpi_agents WHERE id = {sonde_purge}"),
            "réglages": valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_agentsettings WHERE agents_id = {sonde_purge}"),
            "ordinateur": valeur(f"SELECT COUNT(*) FROM glpi_computers WHERE id = {pc_purge} AND is_deleted = 0"),
        }
        constat("case cochée : la sonde est supprimée de GLPI, ses réglages avec, et la fiche de l'ordinateur reste",
                ok_ko(b"PURGE OK" in corps_purge and reste["sonde"] == "0" and reste["réglages"] == "0" and reste["ordinateur"] == "1"),
                f"HTTP {statut_purge}, réponse {corps_purge[:16]!r}, " + ", ".join(f"{q} {v}" for q, v in reste.items()))
        sql(f"DELETE FROM glpi_computers WHERE id = {pc_purge};")

        # « Tout supprimer » (gl=2) : la sonde, les imprimantes qu'elle a fait entrer dans GLPI, et la fiche de
        # l'ordinateur. Une imprimante entrée par une AUTRE sonde reste, ainsi que ses relevés.
        pc_total, sonde_total = sonde_jetable("TOTAL")
        imprimantes = {}
        for nom, sonde_source in (("PG-IMP-SONDE", sonde_total), ("PG-IMP-AUTRE", agent)):
            sql(f"INSERT INTO glpi_printers (name, entities_id, is_deleted) VALUES ({lib.q(nom)}, {d.CLIENT_A}, 0);")
            imprimantes[nom] = int(valeur(f"SELECT id FROM glpi_printers WHERE name = {lib.q(nom)}"))
            sql("INSERT INTO glpi_rulematchedlogs (date, items_id, itemtype, agents_id, method) VALUES "
                f"(NOW(), {imprimantes[nom]}, 'Printer', {sonde_source}, 'netinventory');")
            sql("INSERT INTO glpi_plugin_printgestion_toner_readings (printers_id, property_name, level_percent, reading_date, entities_id) "
                f"VALUES ({imprimantes[nom]}, 'tonerblack', 50, NOW(), {d.CLIENT_A});")
        _, corps_total = lib.Session().telecharger(
            config.FRONT + f"/agentreport.php?t={cle_de_retrait()}&maj=0&off=1&gl=2&pc=PG-SONDE-TOTAL")
        apres = {
            "sonde": valeur(f"SELECT COUNT(*) FROM glpi_agents WHERE id = {sonde_total}"),
            "ordinateur": valeur(f"SELECT COUNT(*) FROM glpi_computers WHERE id = {pc_total}"),
            "imprimante de la sonde": valeur(f"SELECT COUNT(*) FROM glpi_printers WHERE id = {imprimantes['PG-IMP-SONDE']}"),
            "relevés de celle-ci": valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_toner_readings WHERE printers_id = {imprimantes['PG-IMP-SONDE']}"),
            "imprimante d'une autre sonde": valeur(f"SELECT COUNT(*) FROM glpi_printers WHERE id = {imprimantes['PG-IMP-AUTRE']}"),
            "relevés de l'autre": valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_toner_readings WHERE printers_id = {imprimantes['PG-IMP-AUTRE']}"),
        }
        # Le quatrième nombre est celui des objets de collecte créés par le raccordement (aucun sur cette sonde
        # jetable, qui n'en a pas) : la réponse doit quand même le porter.
        constat("tout supprimer : sonde, son imprimante et ses relevés, et la fiche de l'ordinateur partent",
                ok_ko(b"PURGE TOTAL 1 1 1 0" in corps_total and apres["sonde"] == "0" and apres["ordinateur"] == "0"
                      and apres["imprimante de la sonde"] == "0" and apres["relevés de celle-ci"] == "0"),
                f"réponse {corps_total[:24]!r}, " + ", ".join(f"{q} {v}" for q, v in apres.items()))
        constat("tout supprimer : l'imprimante d'une autre sonde et ses relevés restent",
                ok_ko(apres["imprimante d'une autre sonde"] == "1" and apres["relevés de l'autre"] == "1"))
        sql(f"DELETE FROM glpi_plugin_printgestion_toner_readings WHERE printers_id IN ({imprimantes['PG-IMP-SONDE']}, {imprimantes['PG-IMP-AUTRE']}); "
            f"DELETE FROM glpi_rulematchedlogs WHERE items_id IN ({imprimantes['PG-IMP-SONDE']}, {imprimantes['PG-IMP-AUTRE']}) AND itemtype = 'Printer'; "
            f"DELETE FROM glpi_printers WHERE id IN ({imprimantes['PG-IMP-SONDE']}, {imprimantes['PG-IMP-AUTRE']}); "
            f"DELETE FROM glpi_computers WHERE id = {pc_total};")

        # La clé d'un fichier d'INSTALLATION ne porte pas ce droit : gl=1 ne supprime alors rien, et le dit.
        pc_refus, sonde_refus = sonde_jetable("REFUS")
        _, octets_inst = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        cle_inst = re.search(r"agentreport\.php\?t=([a-f0-9]{48})", octets_inst.decode("utf-8", "replace"))
        _, corps_refus = lib.Session().telecharger(
            config.FRONT + f"/agentreport.php?t={cle_inst.group(1) if cle_inst else 'x'}&maj=0&off=1&gl=1&pc=PG-SONDE-REFUS")
        constat("clé sans ce droit : la sonde reste, et le PC reçoit « refusé »",
                ok_ko(b"PURGE REFUSE" in corps_refus and valeur(f"SELECT COUNT(*) FROM glpi_agents WHERE id = {sonde_refus}") == "1"),
                f"réponse {corps_refus[:16]!r}")
        sql(f"DELETE FROM glpi_plugin_printgestion_agentsettings WHERE agents_id = {sonde_refus}; "
            f"DELETE FROM glpi_agents WHERE id = {sonde_refus}; DELETE FROM glpi_computers WHERE id = {pc_refus};")

        section("12 ter. Règle du serveur, et compte rendu de ce que l'installation a fait")
        # Réglage par défaut : le serveur impose. La fenêtre n'a donc aucune case à cocher, et la tâche est posée.
        _, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        impose = octets.decode("utf-8", "replace")
        constat("serveur qui impose : pas de case dans la fenêtre, et le fichier sait quand même poser la tâche",
                ok_ko("$maj_imposee = $true" in impose and "$maj = $null" in impose and "CheckBox" not in impose
                      and "schtasks" in impose and "glpi-agent-update.cmd" in impose))
        pull = re.search(r"agentpull\.php\?t=([a-f0-9]{48})", impose)
        rendu = re.search(r"agentreport\.php\?t=([a-f0-9]{48})", impose)
        constat("deux clés distinctes dans le fichier : une pour l'installeur, une pour le compte rendu",
                ok_ko(pull is not None and rendu is not None and pull.group(1) != rendu.group(1)))
        # Chaque clé pour son usage — et un usage refusé ne la brûle pas, sinon une simple URL suffirait à rendre
        # inutilisable le fichier d'une intervention en cours.
        anonyme = lib.Session()
        cle_pull = pull.group(1) if pull else "x"
        cle_rendu = rendu.group(1) if rendu else "x"
        st_croise_a, _ = anonyme.telecharger(config.FRONT + f"/agentpull.php?t={cle_rendu}")
        st_croise_b, _ = anonyme.telecharger(config.FRONT + f"/agentreport.php?t={cle_pull}&maj=1&pc=PG-ESSAI")
        avant_hist = int(valeur(f"SELECT COUNT(*) FROM glpi_logs WHERE itemtype = 'Entity' AND items_id = {d.CLIENT_A}"))
        st_rendu, _ = anonyme.telecharger(config.FRONT + f"/agentreport.php?t={cle_rendu}&maj=1&pc=PG-ESSAI")
        st_rejeu, _ = anonyme.telecharger(config.FRONT + f"/agentreport.php?t={cle_rendu}&maj=1&pc=PG-ESSAI")
        constat("chaque clé pour son usage ; un usage croisé est refusé sans brûler la clé ; le compte rendu ne passe qu'une fois",
                ok_ko(st_croise_a == 404 and st_croise_b == 404 and st_rendu == 204 and st_rejeu == 404),
                f"croisés {st_croise_a}/{st_croise_b}, rendu {st_rendu}, rejeu {st_rejeu}")
        apres_hist = int(valeur(f"SELECT COUNT(*) FROM glpi_logs WHERE itemtype = 'Entity' AND items_id = {d.CLIENT_A}"))
        trace = valeur(f"SELECT new_value FROM glpi_logs WHERE itemtype = 'Entity' AND items_id = {d.CLIENT_A} ORDER BY id DESC LIMIT 1")
        constat("le compte rendu laisse une ligne dans l'historique de l'entité, sans auteur",
                ok_ko(apres_hist == avant_hist + 1 and "PG-ESSAI" in trace and "posée" in trace), trace[:120])
        # Et le compte rendu se lit dans la configuration du plugin, rangé comme les clés.
        rapports = valeur("SELECT value FROM glpi_configs WHERE context = 'plugin:printgestion' AND name = 'agent_install_reports'")
        decode = base64.b64decode(rapports).decode("utf-8", "replace") if rapports else ""
        constat("le compte rendu est gardé pour la fiche de la sonde", ok_ko('"pc":"PG-ESSAI"' in decode and '"scheduled":true' in decode), decode[-160:])

        # Serveur qui laisse le choix : la case revient, décochée, et la question en console reste en repli.
        sql("UPDATE glpi_plugin_printgestion_configs SET agent_update_default = 0 WHERE id = 1;")
        _, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        au_choix = octets.decode("utf-8", "replace")
        _, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=linux")
        au_choix_linux = octets.decode("utf-8", "replace")
        sql("UPDATE glpi_plugin_printgestion_configs SET agent_update_default = 1 WHERE id = 1;")
        constat("serveur qui laisse le choix : la case revient décochée, et la question en console reste en repli",
                ok_ko("$maj_imposee = $false" in au_choix and "$maj.Checked = $false" in au_choix
                      and "choice /C ON /T 20 /D N" in au_choix and "schtasks" in au_choix))
        constat("Linux, même règle : la question est posée au lieu d'être imposée",
                ok_ko("pg_maj=non" in au_choix_linux and "[o/N]" in au_choix_linux
                      and "PRINTGESTION_CRON" in au_choix_linux))

        # Une clé Windows ne doit pas ouvrir le paquet macOS : le fichier demandé doit être dans ce qu'elle promet.
        statut, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        cle_win = re.search(r"agentpull\.php\?t=([a-f0-9]{48})", octets.decode("utf-8", "replace"))
        anonyme = lib.Session()
        statut_autre, _ = anonyme.telecharger(config.FRONT + f"/agentpull.php?t={cle_win.group(1) if cle_win else 'x'}&a=macos-arm64")
        statut_faux, _ = anonyme.telecharger(config.FRONT + "/agentpull.php?t=" + "0" * 48)
        statut_vide, _ = anonyme.telecharger(config.FRONT + "/agentpull.php")
        constat("Clé de récupération : elle n'ouvre que le fichier de son système, et une clé inventée ou absente ne donne rien",
                ok_ko(statut_autre == 404 and statut_faux == 404 and statut_vide == 404),
                f"HTTP {statut_autre} / {statut_faux} / {statut_vide}")

        # Consigne : deux actions, et l'intention dans l'URL. Sans elle, la page refuse — poser une tâche planifiée
        # par défaut serait agir sans qu'on l'ait demandé.
        statut_sans, _ = WEB.telecharger(config.FRONT + f"/sonde.consigne.php?agents_id={agent}&os=windows")
        _, poser = WEB.telecharger(config.FRONT + f"/sonde.consigne.php?agents_id={agent}&os=windows&maj=1")
        _, retirer = WEB.telecharger(config.FRONT + f"/sonde.consigne.php?agents_id={agent}&os=windows&maj=0")
        lire = lambda octets: {n: paquet.read(n).decode("utf-8", "replace")  # noqa: E731
                               for paquet in [zipfile.ZipFile(io.BytesIO(octets))] for n in paquet.namelist()}
        contenu_poser = lire(poser) if poser.startswith(b"PK") else {}
        contenu_retirer = lire(retirer) if retirer.startswith(b"PK") else {}
        bat_poser = contenu_poser.get("consigne-mise-a-jour.bat", "")
        bat_retirer = contenu_retirer.get("consigne-mise-a-jour.bat", "")
        constat("consigne sans intention : refusée",
                ok_ko(statut_sans == 404), f"HTTP {statut_sans}")
        constat("consigne « poser » : crée la tâche et emporte le script qu'elle lancera",
                ok_ko("/Create /TN" in bat_poser and "glpi-agent-update.cmd" in contenu_poser))
        constat("consigne « retirer » : supprime la tâche, sans emporter de script",
                ok_ko("/Delete /TN" in bat_retirer and "/Create /TN" not in bat_retirer
                      and "glpi-agent-update.cmd" not in contenu_retirer))
        _, maintenant = WEB.telecharger(config.FRONT + f"/sonde.consigne.php?agents_id={agent}&os=windows&maj=now")
        statut_invente, _ = WEB.telecharger(config.FRONT + f"/sonde.consigne.php?agents_id={agent}&os=windows&maj=plus-tard")
        contenu_maintenant = lire(maintenant) if maintenant.startswith(b"PK") else {}
        bat_maintenant = contenu_maintenant.get("mise-a-jour-maintenant.bat", "")
        # Mettre à jour maintenant et automatiser sont deux décisions : ce fichier ne doit toucher à aucune tâche.
        constat("consigne « maintenant » : lance la mise à jour tout de suite, sans toucher à la tâche planifiée",
                ok_ko("call \"%ProgramData%\\PrintGestion\\glpi-agent-update.cmd\"" in bat_maintenant
                      and "glpi-agent-update.cmd" in contenu_maintenant
                      and "/Create /TN" not in bat_maintenant and "/Delete /TN" not in bat_maintenant
                      and "type " in bat_maintenant))
        constat("consigne : une action inventée est refusée", ok_ko(statut_invente == 404), f"HTTP {statut_invente}")
        # La version Linux est un script : le poste de test sait dire si sa syntaxe tient.
        for action, nom in (("1", "poser"), ("0", "retirer"), ("now", "maintenant")):
            _, script = WEB.telecharger(config.FRONT + f"/sonde.consigne.php?agents_id={agent}&os=linux&maj={action}")
            texte = script.decode("utf-8", "replace")
            chemin = os.path.join(tempfile.gettempdir(), f"pg-consigne-{action}.sh")
            with open(chemin, "w", encoding="utf-8", newline="\n") as fichier:
                fichier.write(texte)
            analyse = subprocess.run(["sh", "-n", chemin], capture_output=True, text=True) if shutil.which("sh") else None
            os.unlink(chemin)
            constat(f"consigne Linux « {nom} » : script accepté par « sh -n »",
                    ok_ko(texte.startswith("#!/bin/sh") and (analyse is None or analyse.returncode == 0)),
                    "sh absent du poste de test" if analyse is None else (analyse.stdout + analyse.stderr).strip()[:200])
        fuites = [n for n, t in {**contenu_poser, **contenu_retirer, **contenu_maintenant}.items() if secret.search(t)]
        constat("consignes : aucun identifiant ni clé dedans", ok_ko(not fuites), ", ".join(fuites))

        _, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=linux-targz")
        with tarfile.open(fileobj=io.BytesIO(octets), mode="r:gz") as archive:
            membres = {m.name.split("/", 1)[1]: archive.extractfile(m).read() for m in archive.getmembers() if m.isfile() and "/" in m.name}
        script = membres.get("installer-glpi-agent.sh", b"").decode("utf-8", "replace")
        lisez = membres.get("LISEZMOI.txt", b"").decode("utf-8", "replace")
        commande = membres.get("commande.txt", b"").decode("utf-8", "replace")
        # server et tag sont passés en options à l'installeur officiel ; le agent.cfg partiel ne porte que snmp-retries.
        options = dict(re.findall(r'--([a-z-]+)="([^"]*)"', commande))
        bloc = re.search(r"<<'PRINTGESTION_EOF'\n(.*?)\nPRINTGESTION_EOF", script, re.S)
        cles = set(re.findall(r"^\s*([a-z-]+)\s*=", bloc.group(1) if bloc else "", re.M))
        installeur = [n for n in membres if n.endswith(".pl")]
        constat("Linux : installeur officiel, installer-glpi-agent.sh, commande.txt et LISEZMOI.txt ; options exactement type=network, server, tag, httpd-trust (server et tag justes) ; agent.cfg = snmp-retries et journal seulement ; aucun secret",
                ok_ko(len(installeur) == 1 and set(membres) == {installeur[0], "installer-glpi-agent.sh", "commande.txt", "LISEZMOI.txt"}
                      and "--type=network" in commande and set(options) == {"server", "tag", "httpd-trust"} and options["server"] == url and options["tag"] == "CLIENT-TEST-A"
                      and options["httpd-trust"].startswith("127.0.0.1/32") and bloc is not None and cles <= cles_agent
                      and f'--server="{url}"' in script and not any(secret.search(t) for t in (script, lisez, commande))),
                f"{sorted(membres)} ; options {options} ; clés {sorted(cles)}")

        _, octets = WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=macos-zip")
        with zipfile.ZipFile(io.BytesIO(octets)) as paquet:
            noms = sorted(paquet.namelist())
            cfg = paquet.read("local.cfg").decode("utf-8", "replace") if "local.cfg" in noms else ""
            lisez = paquet.read("LISEZMOI.txt").decode("utf-8", "replace") if "LISEZMOI.txt" in noms else ""
        pkgs = [n for n in noms if n.endswith(".pkg")]
        cles = set(re.findall(r"^\s*([a-z-]+)\s*=", cfg, re.M))
        constat("macOS : les deux paquets officiels, local.cfg et LISEZMOI.txt ; local.cfg = server, tag, tasks, httpd-trust, snmp-retries, journal seulement, server juste, aucun secret",
                ok_ko(len(pkgs) == 2 and set(noms) == set(pkgs) | {"local.cfg", "LISEZMOI.txt"} and cles == cles_agent and f"server = {url}" in cfg
                      and not secret.search(cfg) and not secret.search(lisez)), f"{noms} ; clés {sorted(cles)}")
    finally:
        for ident, mode, lastrun in cron:
            sql(f"UPDATE glpi_crontasks SET mode = {mode}, lastrun = {'NULL' if lastrun == 'NULL' else lib.q(lastrun)} WHERE id = {ident};")
        sql(f"UPDATE glpi_plugins SET state = {etat_glpiinventory} WHERE directory = 'glpiinventory';")
        sql(f"UPDATE glpi_configs SET value = {lib.q(d.CORE['url_base'])} WHERE context = 'core' AND name = 'url_base';")
        lib.supprimer_regles_tag()
        sql(f"DELETE FROM glpi_agents WHERE id = {agent};")
        for entite, tag in tags.items():
            sql(f"UPDATE glpi_entities SET tag = {lib.q(tag) if tag else 'NULL'} WHERE id = {entite};")
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
