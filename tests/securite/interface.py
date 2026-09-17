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
import io
import re
import sys
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
    sql("UPDATE glpi_crontasks SET mode = 2; UPDATE glpi_crontasks SET lastrun = NOW() WHERE name = 'queuednotification';")
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
        constat("technicien, URL directe : aucun paquet, aucun téléchargement tracé", ok_ko(not octets.startswith(b"PK") and historique(d.CLIENT_A) == avant),
                f"HTTP {statut}, {len(octets)} octets")
        lib.connecter_admin()
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("administrateur : même blocage, bouton « Activer la règle » juste après, sans « Contactez l'administrateur »",
                ok_ko(bloque in page and lien not in page and bouton_visible(page, "activate_tag_rule") and page.count("name='activate_tag_rule'") == 1
                      and page.find(bloque) < page.find("activate_tag_rule") and "Contactez l" not in lib.texte(page)))
        statut, octets = telecharger(d.CLIENT_A)
        constat("administrateur, URL directe : bloqué aussi", ok_ko(not octets.startswith(b"PK") and historique(d.CLIENT_A) == avant), f"HTTP {statut}")
        lib.supprimer_regles_tag()
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("règle absente : même blocage, bouton « Créer la règle d'affectation par TAG »",
                ok_ko(bloque in page and lien not in page and bouton_visible(page, "create_tag_rule")))
        statut, octets = telecharger(d.CLIENT_A)
        constat("règle absente, URL directe : bloqué", ok_ko(not octets.startswith(b"PK") and historique(d.CLIENT_A) == avant), f"HTTP {statut}")
        WEB.post(config.FRONT + "/agentdeploy.php", [("entities_id", str(d.CLIENT_A)), ("create_tag_rule", "1")])
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("règle créée : blocage levé, liens de téléchargement présents", ok_ko(bloque not in page and lien in page))
        statut, octets = telecharger(d.CLIENT_A)
        constat("rattachement complet : paquet Windows servi et tracé (pas de faux blocage)", ok_ko(octets.startswith(b"PK") and historique(d.CLIENT_A) == avant + 1),
                f"HTTP {statut}, {len(octets)} octets")
        commande = ""
        if octets.startswith(b"PK"):
            with zipfile.ZipFile(io.BytesIO(octets)) as paquet:
                commande = paquet.read("commande-cmd.txt").decode("utf-8", "replace")
        constat("SERVER= du paquet : URL de l'application GLPI, racine comprise, jamais le chemin du plugin GLPI Inventory",
                ok_ko(f'SERVER="{d.CORE["url_base"]}/"' in commande and "glpiinventory" not in commande), commande[:160])
        sql(f"UPDATE glpi_entities SET tag = '' WHERE id = {d.SITE_A2};")
        CTX.connecter("test-technicien-entite")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.SITE_A2), ajax=True)
        constat("TAG absent, technicien qui peut le créer : blocage, formulaire « Créer le TAG » à côté, sans « Contactez l'administrateur »",
                ok_ko(bloque in page and lien not in page and page.find(bloque) < page.find("name='create_tag'") and "Contactez l" not in lib.texte(page)))
        avant = historique(d.SITE_A2)
        statut, octets = telecharger(d.SITE_A2)
        constat("TAG absent, URL directe : bloqué", ok_ko(not octets.startswith(b"PK") and historique(d.SITE_A2) == avant), f"HTTP {statut}")
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
                    ok_ko(bloque in page and "Contactez l" in lib.texte(page) and lien not in page and not octets.startswith(b"PK") and historique(d.CLIENT_A) == avant
                          and not trouves(page)), f"HTTP {statut} ; {', '.join(trouves(page))}")
        sql(f"UPDATE glpi_configs SET value = {lib.q(d.CORE['url_base'])} WHERE context = 'core' AND name = 'url_base';")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        statut, octets = telecharger(d.CLIENT_A)
        constat("URL rétablie : lien présent, paquet servi", ok_ko(bloque not in page and lien in page and octets.startswith(b"PK")), f"HTTP {statut}")

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
        sql("UPDATE glpi_crontasks SET mode = 1 WHERE name = 'queuednotification';")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("action automatique en mode GLPI : cause listée aussi (Actions automatiques)", ok_ko(rien in page and "crontask" in page))
        sql("UPDATE glpi_crontasks SET mode = 2 WHERE name = 'queuednotification';")
        sql(f"UPDATE glpi_plugins SET state = {etat_glpiinventory} WHERE directory = 'glpiinventory';")
        _, page, _ = WEB.get(ONGLET_ENTITE.format(d.CLIENT_A), ajax=True)
        constat("environnement rétabli : ligne disparue", ok_ko(rien not in page))

        section("11. Vérification du raccordement : limite de temps, état franc")
        heure = lambda decalage: lib.php_glpi(f"echo date('Y-m-d H:i:s', time() + ({decalage}));").strip()  # noqa: E731  (heure de GLPI, pas NOW() de la base)
        sql(f"INSERT INTO glpi_plugin_printgestion_raccordements (entities_id, agents_id, status, users_id, date_creation, date_mod, date_configured, date_triggered) "
            f"VALUES ({d.CLIENT_A}, {agent}, 'triggered', 2, NOW(), NOW(), NOW(), {lib.q(heure(-31 * 60))});")
        racc = int(valeur("SELECT MAX(id) FROM glpi_plugin_printgestion_raccordements"))
        sql(f"INSERT INTO glpi_plugin_printgestion_raccordementips (plugin_printgestion_raccordements_id, ip, ip_num) VALUES ({racc}, '10.99.0.31', INET_ATON('10.99.0.31'));")
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
