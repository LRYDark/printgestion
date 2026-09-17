"""Harnais de sécurité : contrôle à l'exécution avec trois comptes (client restreint, Self-Service, central sans droit du plugin).

Constats OK / KO / À NOTER / NON CONCLUANT ; tout ce qui est créé ou modifié est remis en place.
1. XSS « Réattribuer » : noms d'imprimante avec apostrophe, espace et « = » ; « </script> » dans un n° de suivi.
2. Cloisonnement : compte restreint à Client test A face aux objets de Client test B : écrans, export, points
   d'entrée qui écrivent, action de masse native, « Créer Print ».
3. Points d'entrée sans droit : compte Self-Service de Client test A, puis compte central sans aucun droit du plugin
   (droits natifs d'un Super-Admin), sur tous les fichiers ajax/ et front/, en GET et en POST ; tables comparées.
4. Écritures déclenchées par un GET.
5. Échappement du mail « expédiée » envoyé au commercial.
6. Clés API transporteurs : chiffrées en base, jamais réaffichées.
7. Dossier tests/ du plugin : jamais servi par le serveur web.
8. Verrou anti-double-envoi : un n° de série partagé avec un autre client ne bloque rien et ne révèle rien.
"""
import hashlib
import json
import os
import re
import sys
import time
import urllib.parse

import config
import donnees as d
import lib
from lib import WEB, constat, lignes, ok_ko, q, refus, section, sql, valeur

XSS_NOM_1 = "x' onmouseover=alert(1) y"
XSS_NOM_2 = "z' onfocus=alert(2) autofocus= w"
XSS_SUIVI = "</script><script>window.__pgxss=1</script>"
XSS_TONER = "</ScRiPt\n><script>window.__pgxss=2</script>"
MAIL_NOM = 'Imprimante <b>gras</b> & "guillemets"'
MAIL_SUIVI = "<img src=x onerror=alert(3)>"
CLE_TEST = "CLE-API-INVENTEE-0001"
EXP = "glpi_plugin_printgestion_expeditions"
DROITS_PLUGIN = ("plugin_printgestion_expedition", "plugin_printgestion_dashboard", "plugin_printgestion_contrats", "plugin_printgestion_billing",
                 "plugin_printgestion_validation", "plugin_printgestion_deploiement", "plugin_printgestion_sage", "plugin_printgestion_config")
PROFIL_SELF_SERVICE, PROFIL_SUPER_ADMIN, PROFIL_ADMIN = 1, 4, 6

CTX = lib.Contexte()
IDS = {}
NOMS = {}


def etat_expedition(expedition):
    return lignes(f"SELECT printers_id, statut, IFNULL(transport_number, 'NULL'), IFNULL(bl_surveys_id, 'NULL') FROM {EXP} WHERE id = {expedition}")


def mise_en_place():
    section("Mise en place")
    lib.connecter_admin()
    for imprimante in d.IMPRIMANTES_A:
        NOMS[imprimante] = valeur(f"SELECT name FROM glpi_printers WHERE id = {imprimante}")
    IDS["EA"] = CTX.expedition(d.IMP_A1, "test_xss", XSS_SUIVI)
    IDS["EA2"] = CTX.expedition(d.IMP_A2, "test_envoi", "SUIVI-A-VISIBLE")
    IDS["EA3"] = CTX.expedition(d.IMP_A2, "test_mail")
    IDS["EB"] = CTX.expedition(d.IMP_B, "test_b", "SUIVI-B-ORIGINE")
    IDS["AL1"] = CTX.alerte_mauvaise_imprimante(d.IMP_A2, d.IMP_A1, IDS["EA"])
    IDS["ALB"] = CTX.alerte_mauvaise_imprimante(d.IMP_B, d.IMP_B, IDS["EB"])
    droits_a = dict.fromkeys(DROITS_PLUGIN, 0)
    droits_a.update({"plugin_printgestion_expedition": 3, "plugin_printgestion_dashboard": 3, "plugin_printgestion_contrats": 7,
                     "plugin_printgestion_billing": 5, "plugin_printgestion_validation": 3, "plugin_printgestion_deploiement": 3,
                     "plugin_printgestion_sage": 3})
    profil_a = CTX.profil(PROFIL_ADMIN, "Profil test client A (droits du plugin)", droits_a)
    profil_sans = CTX.profil(PROFIL_SUPER_ADMIN, "Profil test sans droit du plugin", dict.fromkeys(DROITS_PLUGIN, 0))
    CTX.utilisateur("test-client-a", profil_a, d.CLIENT_A)
    CTX.utilisateur("test-self-service", PROFIL_SELF_SERVICE, d.CLIENT_A)
    CTX.utilisateur("test-sans-droit", profil_sans, d.RACINE)
    print(f"    envois {IDS} ; profils {CTX.crees['profils']} ; comptes {CTX.crees['utilisateurs']}")


# ── 1. XSS ───────────────────────────────────────────────────────────────────

def scenario_xss():
    section("1. XSS « Réattribuer »")
    lib.connecter_admin()
    sql(f"UPDATE glpi_printers SET name = {q(XSS_NOM_1)} WHERE id = {d.IMP_A1}; UPDATE glpi_printers SET name = {q(XSS_NOM_2)} WHERE id = {d.IMP_A2};"
        f"UPDATE {EXP} SET toner_property = {q(XSS_TONER)} WHERE id = {IDS['EA3']};")
    statut, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
    analyse = lib.Balises()
    analyse.feed(page)
    formulaires = [a for balise, a in analyse.balises if balise == "form" and "data-pg-confirm" in a]
    injectes = [(balise, nom, v) for balise, a in analyse.balises for nom, v in a.items() if nom.startswith("on") and v and "alert(" in v]
    constat("carte « Alertes prioritaires » avec le bouton « Réattribuer » affichée", "OK" if formulaires else "NON CONCLUANT", f"HTTP {statut}, {len(formulaires)} formulaire(s)")
    constat("aucun attribut on* créé par un nom d'imprimante (apostrophe, espace, « = »)", ok_ko(not injectes), str(injectes[:3]) if injectes else "")
    constat("confirmation : les deux noms intacts dans data-pg-confirm, comme texte",
            ok_ko(any(XSS_NOM_1 in a["data-pg-confirm"] and XSS_NOM_2 in a["data-pg-confirm"] for a in formulaires)))
    constat("attribut encodé dans la page (&#039; pour l'apostrophe)", ok_ko("x&#039; onmouseover=alert(1) y" in page))
    constat("texte du bouton « Réattribuer à … » échappé", ok_ko("Réattribuer à z&#039; onfocus=alert(2) autofocus= w" in page))
    evasions = [s.strip() for s in analyse.scripts if s.strip() in ("window.__pgxss=1", "window.__pgxss=2")]
    constat("carte JSON du menu clic droit : « </script> » d'un n° de suivi ou d'un toner reste dans les données", ok_ko(not evasions),
            f"balise script fermée, script exécuté : {evasions}" if evasions else "")
    bloc = re.search(r'<script type="application/json" id="pg-exp-data">(.*?)</script>', page, re.S)
    if bloc is None:
        constat("bloc JSON pg-exp-data présent", "KO")
    else:
        relu = json.loads(bloc.group(1))
        intact = relu.get(str(IDS["EA"]), {}).get("tracking") == XSS_SUIVI and relu.get(str(IDS["EA3"]), {}).get("property") == XSS_TONER
        constat("données du menu clic droit : bloc JSON non exécuté, valeurs intactes une fois relues", ok_ko(intact and "<" not in bloc.group(1)))
    constat("tableau natif : n° de suivi échappé", ok_ko("&lt;/script&gt;&lt;script&gt;window.__pgxss=1&lt;/script&gt;" in page))


# ── 2. Cloisonnement ─────────────────────────────────────────────────────────

def modification_de_masse(expedition, val):
    # Au stade « process », GLPI ne sépare plus « Classe:action » : le sous-formulaire natif envoie action=update.
    return WEB.action_de_masse("PluginPrintgestionExpedition", [expedition], "update", "MassiveAction",
                               [("id_field", "PluginPrintgestionExpedition:7"), ("search_options[PluginPrintgestionExpedition]", "7"),
                                ("field", "transport_number"), ("transport_number", val)])


def scenario_cloisonnement():
    section("2. Cloisonnement par entité")
    b, pb, ea, ea2, eb, alb = d.CLIENT_B, d.IMP_B, IDS["EA"], IDS["EA2"], IDS["EB"], IDS["ALB"]
    CTX.connecter("test-client-a")
    marqueurs = ("TST-B-01", "Client test B", "SUIVI-B-ORIGINE")
    for libelle, chemin in (
        ("écran Expéditions (cartes, compteurs, tableau, carte JSON)", "/dashboard_expeditions.php"),
        ("écran Alertes toner", "/dashboard_alerts.php"),
        ("écran Coût à la page", "/dashboard_billing.php"),
        ("accueil du module", "/index.php"),
        ("demandes d'envoi", "/demande.php"),
        ("contrôle de la remontée", "/collect.php"),
        ("sondes", "/sondes.php"),
        ("raccordements", "/raccordement.php"),
    ):
        statut, page, _ = WEB.get(config.FRONT + chemin)
        fuites = [m for m in marqueurs if m in page]
        constat(f"{libelle} : rien de Client test B", "KO" if fuites else ("OK" if statut == 200 else "NON CONCLUANT"),
                f"HTTP {statut}" + (f", trouvé {fuites}" if fuites else ""))
        if chemin == "/dashboard_expeditions.php":
            constat("écran Expéditions : données de Client test A affichées (témoin)", ok_ko("SUIVI-A-VISIBLE" in page))
    statut, contenu = WEB.telecharger(config.AJAX + f"/export_excel.php?entities_id={b}")
    constat("export Excel avec l'entities_id de Client test B : aucune ligne de Client test B", ok_ko(statut == 200 and "TST-B-01" not in lib.texte_xlsx(contenu)), f"HTTP {statut}")

    def inchange(libelle, etat, action):
        avant = etat()
        statut = action()
        constat(libelle, ok_ko(etat() == avant), f"HTTP {statut}")

    inchange("update_expedition sur l'envoi de Client test B : refusé, rien d'écrit", lambda: etat_expedition(eb),
             lambda: WEB.post(config.AJAX + "/update_expedition.php", [("id", eb), ("action", "ship"), ("carrier", "ups"), ("tracking", "DEPUIS-A")])[0])
    inchange("edit_expedition sur l'envoi de Client test B : refusé, rien d'écrit", lambda: etat_expedition(eb),
             lambda: WEB.post(config.AJAX + "/edit_expedition.php", [("expedition_id", eb), ("statut", "shipped"), ("carrier", "ups"), ("tracking", "DEPUIS-A")], ajax=True)[0])
    inchange("reassign_expedition vers l'imprimante de Client test B : refusé", lambda: etat_expedition(ea),
             lambda: WEB.post(config.AJAX + "/reassign_expedition.php", [("expedition_id", ea), ("new_printers_id", pb)])[0])
    inchange("resolve_alert sur une alerte de Client test B : refusé", lambda: valeur(f"SELECT is_resolved FROM glpi_plugin_printgestion_alerts WHERE id = {alb}"),
             lambda: WEB.post(config.AJAX + "/resolve_alert.php", [("alert_id", alb)])[0])
    inchange("printer_thresholds sur l'imprimante de Client test B : refusé",
             lambda: valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_printer_thresholds WHERE printers_id = {pb}"),
             lambda: WEB.post(config.AJAX + "/printer_thresholds.php", [("printers_id", pb), ("threshold_level", "1")], ajax=True)[0])
    statut, page, _ = WEB.get(config.AJAX + "/printer_costs.php", [("printers_id", pb)], ajax=True)
    constat("printer_costs de l'imprimante de Client test B : refusé", ok_ko(refus(statut, page)), f"HTTP {statut}")
    statut, page, _ = WEB.get(config.AJAX + "/expedition_bls.php", [("expedition_id", eb)], ajax=True)
    constat("expedition_bls de l'envoi de Client test B : refusé", ok_ko(refus(statut, page)), f"HTTP {statut}")

    WEB.post(config.AJAX + "/update_expedition.php", [("id", ea2), ("action", "ship"), ("carrier", "gls"), ("tracking", "SUIVI-A-1"), ("bl_surveys_id", "987654")])
    enregistre = valeur(f"SELECT IFNULL(bl_surveys_id, 'NULL') FROM {EXP} WHERE id = {ea2}")
    constat("update_expedition : bl_surveys_id d'un BL inexistant refusé", ok_ko(enregistre != "987654"), f"bl_surveys_id enregistré : {enregistre}")

    modification_de_masse(ea2, "MASSE-A")
    temoin = valeur(f"SELECT transport_number FROM {EXP} WHERE id = {ea2}") == "MASSE-A"
    modification_de_masse(eb, "MASSE-DEPUIS-A")
    modifie = valeur(f"SELECT transport_number FROM {EXP} WHERE id = {eb}") == "MASSE-DEPUIS-A"
    if not temoin:
        constat("action de masse native « Modifier » sur les envois", "NON CONCLUANT", "témoin sur Client test A sans effet")
    else:
        constat("action de masse native « Modifier » : envoi de Client test B non modifiable par son identifiant", ok_ko(not modifie),
                "n° de suivi de l'envoi de Client test B remplacé" if modifie else "")

    WEB.post(config.FRONT + "/print.form.php", [("add", "1"), ("contract_mode", "new"), ("printer_mode", "new"), ("entities_id", str(b)),
                                               ("c_name", "TST-CONTRAT-HORS-PERIMETRE"), ("c_duration", "12"), ("p_name", "TST-IMPRIMANTE-HORS-PERIMETRE")])
    crees = lignes("SELECT 'contrat', id, entities_id FROM glpi_contracts WHERE name = 'TST-CONTRAT-HORS-PERIMETRE' "
                   "UNION ALL SELECT 'imprimante', id, entities_id FROM glpi_printers WHERE name = 'TST-IMPRIMANTE-HORS-PERIMETRE'")
    for genre, ident, _ in crees:
        CTX.crees["contrats" if genre == "contrat" else "imprimantes"].append(int(ident))
    constat("« Créer Print » : entities_id posté = Client test B, rien créé", ok_ko(not crees), f"créés : {crees}" if crees else "")
    WEB.post(config.FRONT + "/print.form.php", [("add", "1"), ("contract_mode", "new"), ("printer_mode", "existing"), ("printers_id", str(pb)),
                                               ("c_name", "TST-CONTRAT-LIAISON"), ("c_duration", "12")])
    lies = lignes("SELECT id, entities_id FROM glpi_contracts WHERE name = 'TST-CONTRAT-LIAISON'")
    for ident, _ in lies:
        CTX.crees["contrats"].append(int(ident))
    liaisons = valeur(f"SELECT COUNT(*) FROM glpi_contracts_items ci JOIN glpi_contracts c ON c.id = ci.contracts_id WHERE c.name = 'TST-CONTRAT-LIAISON' AND ci.items_id = {pb}")
    constat("« Créer Print » : imprimante de Client test B (printers_id posté) jamais liée", ok_ko(liaisons == "0"),
            f"contrat créé {lies}, liaisons {liaisons}" if lies else "")
    sql(f"INSERT INTO glpi_locations (entities_id, name, completename, level, locations_id, date_creation, date_mod) VALUES ({b}, 'Lieu test B', 'Lieu test B', 1, 0, NOW(), NOW());")
    lieu_b = valeur("SELECT MAX(id) FROM glpi_locations WHERE name = 'Lieu test B'")
    WEB.post(config.FRONT + "/print.form.php", [("add", "1"), ("contract_mode", "new"), ("printer_mode", "new"), ("entities_id", str(d.SITE_A1)),
                                               ("c_name", "TST-CONTRAT-LIEU-B"), ("c_duration", "12"), ("p_name", "TST-IMPRIMANTE-LIEU-B"), ("locations_id", lieu_b)])
    crees = lignes("SELECT 'contrat', id FROM glpi_contracts WHERE name = 'TST-CONTRAT-LIEU-B' UNION ALL SELECT 'imprimante', id FROM glpi_printers WHERE name = 'TST-IMPRIMANTE-LIEU-B'")
    for genre, ident in crees:
        CTX.crees["contrats" if genre == "contrat" else "imprimantes"].append(int(ident))
    constat("« Créer Print » : lieu de Client test B refusé, rien créé", ok_ko(not crees), f"créés : {crees}" if crees else "")
    WEB.post(config.FRONT + "/print.form.php", [("add", "1"), ("contract_mode", "new"), ("printer_mode", "new"), ("entities_id", str(d.SITE_A1)),
                                               ("c_name", "TST-CONTRAT-TEMOIN"), ("c_duration", "12"), ("p_name", "TST-IMPRIMANTE-TEMOIN")])
    crees = lignes("SELECT 'contrat', id, entities_id FROM glpi_contracts WHERE name = 'TST-CONTRAT-TEMOIN' "
                   "UNION ALL SELECT 'imprimante', id, entities_id FROM glpi_printers WHERE name = 'TST-IMPRIMANTE-TEMOIN'")
    for genre, ident, _ in crees:
        CTX.crees["contrats" if genre == "contrat" else "imprimantes"].append(int(ident))
    constat("témoin « Créer Print » : contrat et imprimante créés et liés dans une sous-entité du compte (Site test A1)",
            ok_ko(sorted(r[2] for r in crees) == [str(d.SITE_A1)] * 2), str(crees))
    sql(f"DELETE FROM glpi_locations WHERE id = {lieu_b};")

    # Import Sage (compte de Client test A avec le droit « Référentiel Sage ») : jamais de lien vers une entité hors périmètre.
    fichier = "Code client;Intitulé\nTSTCLI01;CLIENT TEST RACINE\nTSTCLI98;SITE TEST A2\nTSTCLI99;CLIENT TEST B\n".encode("utf-8")
    statut, _, entetes = WEB.envoyer_fichier(config.FRONT + "/sageimport.php", [("analyze", "1"), ("type", "clients")], "file", "clients-test.csv", fichier)
    jeton = re.search(r"preview=([0-9a-f]+)", (entetes.get("Location") if entetes else "") or "")
    if jeton is None:
        constat("import Sage : fichier de test analysé", "NON CONCLUANT", f"HTTP {statut}")
    else:
        _, apercu, _ = WEB.get(config.FRONT + f"/sageimport.php?preview={jeton.group(1)}")
        constat("import Sage, prévisualisation : aucune entité de Client test B proposée ni nommée", ok_ko("Client test B" not in apercu and "Site test A2" in apercu),
                "suggestion « même nom » attendue pour Site test A2 (témoin)")
        empreinte = lambda code: hashlib.sha1(code.encode()).hexdigest()  # noqa: E731
        mappings_avant = valeur("SELECT COUNT(*) FROM glpi_plugin_printgestion_entitysageclients")
        WEB.post(config.FRONT + "/sageimport.php", [("apply", "1"), ("token", jeton.group(1)),
                                                   ("link_code[" + empreinte("TSTCLI99") + "]", "TSTCLI99"), ("link[" + empreinte("TSTCLI99") + "]", str(b))])
        constat("import Sage : lien vers Client test B refusé, rien écrit",
                ok_ko(valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_entitysageclients WHERE entities_id = {b}") == "0"
                      and valeur("SELECT COUNT(*) FROM glpi_plugin_printgestion_sageclients WHERE code = 'TSTCLI99'") == "0"))
        WEB.post(config.FRONT + "/sageimport.php", [("apply", "1"), ("token", jeton.group(1)),
                                                   ("link_code[" + empreinte("TSTCLI98") + "]", "TSTCLI98"), ("link[" + empreinte("TSTCLI98") + "]", str(d.SITE_A2))])
        constat("témoin import Sage : lien vers Site test A2 (périmètre du compte) enregistré",
                ok_ko(valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_entitysageclients WHERE entities_id = {d.SITE_A2}") == "1"), f"liaisons {mappings_avant} → "
                + str(valeur("SELECT COUNT(*) FROM glpi_plugin_printgestion_entitysageclients")))
        sql(f"DELETE FROM glpi_plugin_printgestion_entitysageclients WHERE entities_id IN ({d.SITE_A2}, {b});"
            "DELETE FROM glpi_plugin_printgestion_sageclients WHERE code IN ('TSTCLI98', 'TSTCLI99');"
            "DELETE FROM glpi_plugin_printgestion_sageimports WHERE filename = 'clients-test.csv';"
            "UPDATE glpi_plugin_printgestion_sageclients SET is_in_last_import = 1 WHERE code = 'TSTCLI01';")


# ── 3. Points d'entrée sans droit ────────────────────────────────────────────

def empreintes_tables():
    tables = [r[0] for r in lignes("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'glpi\\_plugin\\_printgestion\\_%'")]
    return {t: (lignes(f"CHECKSUM TABLE `{t}`") or [["", ""]])[0][1] for t in tables}


def scenario_sans_droit():
    section("3. Points d'entrée sans droit")
    fichiers = [(dossier, nom) for dossier in ("ajax", "front") for nom in sorted(os.listdir(os.path.join(config.PLUGIN_DIR, dossier))) if nom.endswith(".php")]
    sources = ""
    for base, _, noms in os.walk(config.PLUGIN_DIR):
        if any(part in base for part in ("/vendor", "/.git", "/tests")):
            continue
        for nom in noms:
            if nom.endswith((".php", ".js", ".twig")):
                with open(os.path.join(base, nom), encoding="utf-8", errors="replace") as fichier:
                    sources += f"\n@@{os.path.join(base, nom)}@@\n" + fichier.read()
    orphelins = []
    for dossier, nom in fichiers:
        propre = f"@@{os.path.join(config.PLUGIN_DIR, dossier, nom)}@@"
        autres = re.sub(re.escape(propre) + r".*?(?=\n@@|\Z)", "", sources, flags=re.S)
        if nom not in autres and nom[:-4] not in autres:
            orphelins.append(f"{dossier}/{nom}")
    constat("fichiers ajax/ et front/ tous référencés (aucun orphelin)", ok_ko(not orphelins), ", ".join(orphelins))

    parametres = [("id", str(IDS["EA"])), ("expedition_id", str(IDS["EA"])), ("printers_id", str(d.IMP_A1)), ("entities_id", str(d.CLIENT_A)),
                  ("agents_id", str(d.AGENT_RECENT)), ("alert_id", str(IDS["AL1"])), ("contracts_id", str(d.CONTRAT_A)),
                  ("cartridgeitems_id", str(d.CARTOUCHE_NOIR)), ("os", "windows")]
    en_post = [("action", "ship"), ("carrier", "ups"), ("tracking", "SANS-DROIT"), ("statut", "shipped"), ("new_printers_id", str(d.IMP_A2)), ("add", "1"),
               ("update", "1"), ("add_rate", "1"), ("type_cout", "nb"), ("rate", "0.123"), ("save_bindings", "1"), ("save_frequency", "1"),
               ("frequency", "hourly"), ("modifier", "2"), ("recompute_alerts", "1"), ("threshold_level", "1"), ("bls", '["1"]'),
               ("save_agent_settings", "1"), ("auto_update", "0"), ("start", "1"), ("send", "1")]
    try:
        for compte, description in (("test-self-service", "Self-Service de Client test A"), ("test-sans-droit", "central, droits natifs de Super-Admin, aucun droit du plugin")):
            CTX.connecter(compte)
            avant = empreintes_tables()
            atteints = []
            for dossier, nom in fichiers:
                chemin = f"{config.PLUGIN}/{dossier}/{nom}"
                for methode, champs in (("GET", parametres), ("POST", parametres + en_post)):
                    envoi = WEB.post if methode == "POST" else WEB.get
                    statut, page, entetes = envoi(chemin, champs, ajax=dossier == "ajax")
                    destination = (entetes.get("Location") if entetes else "") or ""
                    refuse = refus(statut, page) or (statut in (301, 302, 303) and re.search(r"login|index\.php|central\.php|helpdesk", destination) is not None)
                    if not refuse:
                        atteints.append(f"{methode} {dossier}/{nom} → {statut}" + (f" vers {urllib.parse.urlparse(destination).path}" if destination else ""))
            apres = empreintes_tables()
            modifiees = [t for t in apres if apres[t] != avant.get(t)]
            constat(f"{compte} ({description}) : aucun point d'entrée du plugin utilisable", ok_ko(not atteints), " ; ".join(atteints))
            constat(f"{compte} : aucune table du plugin modifiée", ok_ko(not modifiees), ", ".join(modifiees))
            if compte == "test-sans-droit":
                _, page_contrat, _ = WEB.get(f"/front/contract.form.php?id={d.CONTRAT_A}")
                _, page_cartouche, _ = WEB.get(f"/front/cartridgeitem.form.php?id={d.CARTOUCHE_NOIR}")
                constat("test-sans-droit : onglets « Tarifs » du contrat et « Print Gestion » de la cartouche absents",
                        ok_ko("PluginPrintgestionContractrate$1" not in page_contrat and "PluginPrintgestionCartridgesnmp$1" not in page_cartouche
                              and "Tarifs Print Gestion" not in page_contrat))
        lib.connecter_admin()
        WEB.post(config.FRONT + "/contractrate.form.php", [("contracts_id", str(d.CONTRAT_A)), ("add_rate", "1"), ("type_cout", "color"), ("rate", "0.123"), ("actif", "1")])
        constat("témoin : compte avec les droits natif et du plugin, tarif ajouté",
                ok_ko(valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_contractrates WHERE contracts_id = {d.CONTRAT_A} AND rate = 0.123") == "1"))
        tarif_racine = valeur(f"SELECT MIN(id) FROM glpi_plugin_printgestion_contractrates WHERE contracts_id = {d.CONTRAT_RACINE}")
        tarifs_avant = valeur("SELECT COUNT(*) FROM glpi_plugin_printgestion_contractrates")
        WEB.post(config.FRONT + "/contractrate.form.php", [("contracts_id", str(d.CONTRAT_A)), ("delete_rate", tarif_racine)])
        message = WEB.messages()
        constat("suppression d'un tarif d'un autre contrat : rien supprimé, jamais « Tarif supprimé » annoncé",
                ok_ko(valeur("SELECT COUNT(*) FROM glpi_plugin_printgestion_contractrates") == tarifs_avant and "Tarif supprimé" not in message and "introuvable" in message), message[:120])
    finally:
        sql("DELETE FROM glpi_plugin_printgestion_contractrates WHERE rate = 0.123;")


# ── 4. Écritures en GET ──────────────────────────────────────────────────────

def scenario_ecritures_get():
    section("4. Écritures en GET")
    lib.connecter_admin()
    empreinte = lambda: (lignes("CHECKSUM TABLE glpi_plugin_printgestion_billing_view")[0][1], valeur("SELECT IFNULL(MAX(id), 0) FROM glpi_plugin_printgestion_billing_view"))  # noqa: E731
    avant = empreinte()
    statut, _, _ = WEB.get(config.FRONT + "/dashboard_billing.php")
    apres = empreinte()
    constat("dashboard_billing.php en GET : lignes de l'utilisateur recalculées dans la table matérialisée", "À NOTER" if avant != apres else "OK",
            f"HTTP {statut}, table {'réécrite' if avant != apres else 'inchangée'} (période, entité et vue lues dans la requête ; pas de jeton en GET)")
    historique = lambda: valeur(f"SELECT COUNT(*) FROM glpi_logs WHERE itemtype = 'Entity' AND items_id = {d.CLIENT_A}")  # noqa: E731
    lib.creer_regle_tag()  # rattachement complet : sans règle active, le téléchargement est bloqué
    try:
        avant = historique()
        WEB.telecharger(config.FRONT + f"/agentdeploy.download.php?entities_id={d.CLIENT_A}&os=windows")
        apres = historique()
    finally:
        lib.supprimer_regles_tag()
    constat("agentdeploy.download.php en GET : téléchargement tracé dans l'historique de l'entité", "À NOTER" if apres != avant else "OK", f"{avant} → {apres} ligne(s)")
    etat = lambda: (valeur(f"SELECT COUNT(*) FROM glpi_plugin_printgestion_printer_thresholds WHERE printers_id = {d.IMP_A1}"), etat_expedition(IDS["EA"]))  # noqa: E731
    avant = etat()
    statut_s, _, _ = WEB.get(config.AJAX + "/printer_thresholds.php", [("printers_id", str(d.IMP_A1)), ("threshold_level", "1")], ajax=True)
    statut_e, _, _ = WEB.get(config.AJAX + "/update_expedition.php", [("id", IDS["EA"]), ("action", "ship"), ("carrier", "ups"), ("tracking", "ECRIT-EN-GET")])
    constat("printer_thresholds et update_expedition en GET : rien d'écrit", ok_ko(etat() == avant), f"HTTP {statut_s} et {statut_e}")

    # Fiche d'une demande d'envoi : l'avancement des expéditions n'est jamais reporté en GET.
    exp_expediee = CTX.expedition(d.IMP_A1, "test_demande_get", "SUIVI-DEMANDE", statut="shipped")
    exp_attente = CTX.expedition(d.IMP_A2, "test_demande_post")
    sql("INSERT INTO glpi_plugin_printgestion_demandes (name, entities_id, locations_id, statut, delivery_mode, date_creation, date_mod) "
        f"VALUES ('Demande test GET', {d.CLIENT_A}, 0, 'exported', 'direct', NOW(), NOW());")
    demande = int(valeur("SELECT MAX(id) FROM glpi_plugin_printgestion_demandes WHERE name = 'Demande test GET'"))
    CTX.crees["demandes"].append(demande)
    for exp, imp in ((exp_expediee, d.IMP_A1), (exp_attente, d.IMP_A2)):
        sql("INSERT INTO glpi_plugin_printgestion_demandelines (plugin_printgestion_demandes_id, printers_id, toner_property, cartridgeitems_id, quantity, is_under_contract, "
            f"contracts_id, level_at_proposal, statut, expeditions_id, date_creation, date_mod, entities_id) VALUES ({demande}, {imp}, 'tonerblack', {d.CARTOUCHE_NOIR}, 1, 0, 0, 20, "
            f"'exported', {exp}, NOW(), NOW(), {d.CLIENT_A});")
    statut_ligne = lambda exp: valeur(f"SELECT statut FROM glpi_plugin_printgestion_demandelines WHERE expeditions_id = {exp}")  # noqa: E731
    statut, page, _ = WEB.get(config.FRONT + f"/demande.form.php?id={demande}")
    constat("demande.form.php en GET : aucune ligne mise à jour, retard signalé avec « Actualiser les statuts »",
            ok_ko(statut_ligne(exp_expediee) == "exported" and "Actualiser les statuts" in page), f"HTTP {statut}, ligne {statut_ligne(exp_expediee)}")
    WEB.post(config.FRONT + "/demande.form.php", [("id", str(demande)), ("sync_statuses", "1")])
    constat("demande.form.php en POST « Actualiser les statuts » : ligne passée à « expédiée »", ok_ko(statut_ligne(exp_expediee) == "shipped"), statut_ligne(exp_expediee))
    WEB.post(config.AJAX + "/update_expedition.php", [("id", exp_attente), ("action", "ship"), ("carrier", "ups"), ("tracking", "SUIVI-DEMANDE-2")])
    constat("update_expedition (POST) : demande rattachée mise à jour aussitôt", ok_ko(statut_ligne(exp_attente) == "shipped"), statut_ligne(exp_attente))


# ── 5. Mails ─────────────────────────────────────────────────────────────────

def scenario_mail():
    section("5. Échappement des mails")
    lib.connecter_admin()
    sql(f"UPDATE glpi_printers SET name = {q(MAIL_NOM)} WHERE id = {d.IMP_A2};")
    depuis = time.time() - 1
    WEB.post(config.AJAX + "/update_expedition.php", [("id", IDS["EA3"]), ("action", "ship"), ("carrier", "ups"), ("tracking", MAIL_SUIVI)])
    time.sleep(1.5)
    mails = lib.mails_depuis(depuis)
    if not mails:
        constat("mail « expédiée » au commercial", "NON CONCLUANT", "aucun mail capturé")
        return
    corps, sujet = mails[-1]["html"], mails[-1]["sujet"]
    if "gras" not in corps:
        constat("nom d'imprimante dans le corps HTML", "NON CONCLUANT", "nom absent du gabarit")
    else:
        constat("nom d'imprimante échappé dans le corps HTML (<b>, &, guillemets)",
                ok_ko("<b>gras</b>" not in corps and "&lt;b&gt;gras&lt;/b&gt; &amp; &quot;guillemets&quot;" in corps))
    if "onerror" not in corps:
        constat("n° de suivi dans le corps HTML", "NON CONCLUANT", "n° de suivi absent du gabarit")
    else:
        constat("n° de suivi échappé dans le corps HTML", ok_ko("<img src=x" not in corps and "&lt;img src=x onerror=alert(3)&gt;" in corps))
    constat("sujet sur une seule ligne", ok_ko("\n" not in sujet and "\r" not in sujet), sujet[:90])


# ── 6. Clés API ──────────────────────────────────────────────────────────────

def scenario_cles_api():
    section("6. Clés API transporteurs")
    lib.connecter_admin()
    sortie = lib.php_glpi("$c = new PluginPrintgestionConfig(); $c->getFromDB(1); $f = $c->fields; PluginPrintgestionConfig::unsetUndisclosedFields($f); "
                          "echo json_encode([array_values(array_intersect(array_keys($f), ['api_ups', 'api_gls', 'api_chronopost'])), array_key_exists('api_gls', $c->fields)]);")
    constat("API REST : les colonnes de clés sont retirées de la réponse (undisclosedFields), la lecture interne garde les siennes",
            ok_ko(json.loads(sortie) == [[], True]), sortie.strip())
    colonnes = [r[0] for r in lignes("SHOW COLUMNS FROM glpi_plugin_printgestion_configs")]
    choix = ", ".join(f"IFNULL(`{c}`, 'NULL')" for c in colonnes)
    lire = lambda: dict(zip(colonnes, (lib.decoder(v) for v in lignes(f"SELECT {choix} FROM glpi_plugin_printgestion_configs WHERE id = 1")[0])))  # noqa: E731
    avant = lire()
    onglet = "/ajax/common.tabs.php?_target=%2Ffront%2Fconfig.form.php&_itemtype=Config&_glpi_tab=PluginPrintgestionConfig%241&id=1"
    try:
        statut, page, _ = WEB.get(onglet, ajax=True)
        formulaire = lib.Formulaire("printgestion/front/config.form.php")
        formulaire.feed(page)
        champs = [(n, v) for n, v in formulaire.champs if n not in ("_glpi_csrf_token", "api_ups", "update")]
        if len(champs) < 5:
            constat("formulaire de configuration lu", "NON CONCLUANT", f"HTTP {statut}, {len(champs)} champ(s)")
            return
        WEB.post(config.FRONT + "/config.form.php", champs + [("api_ups", CLE_TEST), ("update", "1")])
        stockee = valeur("SELECT IFNULL(api_ups, 'NULL') FROM glpi_plugin_printgestion_configs WHERE id = 1") or ""
        constat("clé enregistrée chiffrée, jamais en clair en base", ok_ko(stockee not in ("NULL", "", CLE_TEST) and CLE_TEST not in stockee))
        statut, page, _ = WEB.get(onglet, ajax=True)
        constat("clé jamais réaffichée : absente de la page, texte d'aide générique", ok_ko(CLE_TEST not in page and "Clé enregistrée" in page))
        apres = lire()
        autres = {c: (avant[c], apres[c]) for c in colonnes if c not in ("api_ups", "date_mod") and avant[c] != apres[c]}
        constat("enregistrement du formulaire : autres réglages inchangés (contrôle du test)", "OK" if not autres else "À NOTER", str(autres)[:300])
    finally:
        apres = lire()
        remise = ", ".join(f"`{c}` = {q(avant[c])}" for c in colonnes if c != "id" and apres[c] != avant[c])
        if remise:
            sql(f"UPDATE glpi_plugin_printgestion_configs SET {remise} WHERE id = 1;")


# ── 8. Verrou anti-double-envoi ──────────────────────────────────────────────

def verrou(imprimante):
    sortie = lib.php_glpi(f"echo json_encode(PluginPrintgestionGuard::evaluate([['printers_id' => {imprimante}, 'property' => 'tonerblack', 'level' => null]]));")
    return json.loads(sortie).get(f"{imprimante}|tonerblack")


def scenario_verrou():
    section("8. Verrou anti-double-envoi : n° de série restreint à l'entité")
    lib.connecter_admin()
    serie_b = valeur(f"SELECT serial FROM glpi_printers WHERE id = {d.IMP_B}")
    serie_a2 = valeur(f"SELECT serial FROM glpi_printers WHERE id = {d.IMP_A2}")
    serie_a1 = valeur(f"SELECT serial FROM glpi_printers WHERE id = {d.IMP_A1}")
    try:
        sql(f"UPDATE glpi_printers SET serial = '{serie_a1}' WHERE id = {d.IMP_B};")
        exp_b = CTX.expedition(d.IMP_B, "tonerblack", "SUIVI-VERROU-B", statut="shipped")
        lock = verrou(d.IMP_A1)
        constat("même n° de série chez Client test B avec un envoi en cours : aucune commande de Client test A bloquée, aucune référence de B",
                ok_ko(lock is None or f"#{exp_b}" not in (lock.get("message") or "")), str(lock)[:200] if lock else "")
        sql(f"UPDATE glpi_printers SET serial = '{serie_a1}' WHERE id = {d.IMP_A2};")
        exp_a = CTX.expedition(d.IMP_A2, "tonerblack", "SUIVI-VERROU-A", statut="shipped")
        lock = verrou(d.IMP_A1)
        constat("témoin : même n° de série dans la même entité, envoi en cours sur le doublon : commande bloquée",
                ok_ko(lock is not None and lock.get("reason") == "in_progress" and lock.get("expeditions_id") == exp_a), str(lock)[:200])
        _, page, _ = WEB.get(config.FRONT + "/collect.php")
        carte = page[page.find("Numéros de série en double"):][:4000]
        constat("signal administrateur : doublon entre entités signalé dans « Contrôle de la remontée »",
                ok_ko(serie_a1 in carte and "Entités différentes" in carte))
    finally:
        sql(f"UPDATE glpi_printers SET serial = '{serie_b}' WHERE id = {d.IMP_B}; UPDATE glpi_printers SET serial = '{serie_a2}' WHERE id = {d.IMP_A2};")


# ── 7. Dossier tests/ non servi ──────────────────────────────────────────────

def scenario_tests_non_servis():
    section("7. Dossier tests/ jamais servi par le web")
    racine = config.PLUGIN_DIR
    chemins = []
    for base, _, noms in os.walk(os.path.join(racine, "tests")):
        chemins += [os.path.relpath(os.path.join(base, n), racine) for n in noms if not n.startswith(".")]
    if not chemins:
        constat("dossier tests/ présent dans le plugin déployé sur l'instance", "NON CONCLUANT", "rien à vérifier")
        return
    anonyme = lib.Session()
    for session, qui in ((anonyme, "sans session"), (WEB, "session administrateur")):
        if session is WEB:
            lib.connecter_admin()
        servis = []
        for relatif in sorted(chemins):
            with open(os.path.join(racine, relatif), "rb") as fichier:
                extrait = fichier.read(400).strip()[:80]
            for prefixe in ("/plugins/printgestion/", "/marketplace/printgestion/", "/plugins/printgestion/public/../"):
                statut, contenu, _ = session.brut("GET", prefixe + relatif, octets=True)
                if statut == 200 and extrait and extrait in contenu:
                    servis.append(f"{prefixe}{relatif}")
        constat(f"{len(chemins)} fichiers de tests/ demandés {qui} : aucun servi, aucun exécuté", ok_ko(not servis), ", ".join(servis[:5]))
    statut, contenu, _ = WEB.brut("GET", "/plugins/printgestion/tests/simulations/gestion/front/SageApi.php")
    constat("simulation de l'API Sage jamais exécutable par URL", ok_ko(statut != 200), f"HTTP {statut}")


def main():
    d.verifier_instance()
    try:
        mise_en_place()
        for scenario in (scenario_xss, scenario_cloisonnement, scenario_sans_droit, scenario_ecritures_get, scenario_mail, scenario_cles_api, scenario_tests_non_servis, scenario_verrou):
            try:
                scenario()
            except Exception as erreur:  # constat du test, la suite continue
                constat(f"{scenario.__name__} interrompu", "NON CONCLUANT", repr(erreur)[:300])
    finally:
        section("Remise en place")
        lib.connecter_admin()
        for imprimante, nom in NOMS.items():
            sql(f"UPDATE glpi_printers SET name = {q(nom)} WHERE id = {imprimante};")
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
