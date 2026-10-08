"""Carte « Santé de la configuration » : contrôles automatiques de l'environnement, en tête de la configuration.

Chaque contrôle obligatoire est mis en défaut puis rétabli : ligne rouge, bannière impossible à manquer, page non
bloquée (le formulaire reste là). Actions automatiques : vert seulement sans action active en mode GLPI ET avec une
action en mode CLI lancée dans l'heure ; chaque correction a son bouton et n'agit que sur elle (enregistrer les tâches
du plugin absentes de GLPI, passer en mode CLI sans toucher à l'état, activer sans toucher au mode, débloquer, déclarer
le cron système, activer la proposition automatique des demandes), et le cron du serveur reste le seul point qu'aucun
bouton ne corrige. Préparation des inventaires réseau : pour information tant qu'aucune tâche de collecte n'est gérée.
Sauvegarde de glpicrypt.key : ligne « non vérifiable automatiquement »,
jamais une case à cocher. Tout vert : une ligne « Configuration : complète », détail replié. Profils : lecture seule
du droit de configuration → carte sans bouton ; sans ce droit → pas de carte.
"""
import glob
import io
import os
import re
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql, valeur

LOCAL_DEFINE = os.path.join(config.GLPI_DIR, "config", "local_define.php")
LOCAL_DEFINE_AVANT = None
LIAISON_GESTION_AVANT = None
# Tâche du plugin retirée de glpi_crontasks le temps d'un contrôle (celle qui manquait en production), et sa ligne
# d'origine (colonnes, valeurs) pour la remettre telle quelle : même identifiant, mêmes réglages.
TACHE_ABSENTE = "itemtype = 'PluginPrintgestionRaccordement' AND name = 'PrintgestionRaccordements'"
TACHE_RETIREE = None
ONGLET = "/ajax/common.tabs.php?_target=%2Ffront%2Fconfig.form.php&_itemtype=Config&_glpi_tab=PluginPrintgestionConfig%241&id=1"
CTX = lib.Contexte()


def carte():
    _, page, _ = WEB.get(ONGLET, ajax=True)
    etats = dict(re.findall(r"data-pg-health='(\w+)' data-pg-state='(\w+)'", page))
    return page, etats


def bandeau(page):
    return "alert alert-danger" in page and "prérequis manquant" in page


def balise_bouton(page, nom):
    """Balise <button …> complète qui porte ce nom : les attributs posés par GLPI le sont avant le nom."""
    pos = page.find(f"name=\"{nom}\"")
    if pos < 0:
        pos = page.find(f"name='{nom}'")
    if pos < 0:
        return ""
    debut = page.rfind("<button", 0, pos)
    fin = page.find(">", pos)
    return page[debut:fin + 1] if debut >= 0 and fin > 0 else ""


def lire_local_define():
    return io.open(LOCAL_DEFINE, encoding="utf-8").read() if os.path.exists(LOCAL_DEFINE) else ""


def restaurer_local_define():
    """Remet config/local_define.php dans son état d'avant la passe et efface les copies horodatées du plugin."""
    if LOCAL_DEFINE_AVANT is None:
        if os.path.exists(LOCAL_DEFINE):
            os.remove(LOCAL_DEFINE)
    else:
        io.open(LOCAL_DEFINE, "w", encoding="utf-8", newline="\n").write(LOCAL_DEFINE_AVANT)
    for copie in glob.glob(LOCAL_DEFINE + ".bak-*"):
        os.remove(copie)


def remettre_tache():
    """Remet la tâche retirée de glpi_crontasks dans son état d'avant : celle que le bouton a recréée est effacée, la
    ligne d'origine réinsérée avec son identifiant (son journal d'exécution s'y rattache de nouveau)."""
    global TACHE_RETIREE
    if TACHE_RETIREE is None:
        return
    colonnes, valeurs = TACHE_RETIREE
    sql(f"DELETE FROM glpi_crontasks WHERE {TACHE_ABSENTE}; "
        f"INSERT INTO glpi_crontasks ({', '.join(f'`{c}`' for c in colonnes)}) VALUES ({', '.join(lib.q(v) for v in valeurs)});")
    TACHE_RETIREE = None


def main():
    d.verifier_instance()
    global LOCAL_DEFINE_AVANT, LIAISON_GESTION_AVANT, TACHE_RETIREE
    LOCAL_DEFINE_AVANT = lire_local_define() if os.path.exists(LOCAL_DEFINE) else None
    liaison = valeur("SELECT value FROM glpi_configs WHERE context = 'plugin:printgestion' AND name = 'gestion_link_enabled'")
    LIAISON_GESTION_AVANT = liaison if liaison != "" else None
    cron = lib.lignes("SELECT id, mode, state, IFNULL(lastrun, 'NULL') FROM glpi_crontasks")
    inventaire = valeur("SELECT value FROM glpi_configs WHERE context = 'inventory' AND name = 'enabled_inventory'")
    xlsx = lib.lignes("SELECT id, is_uploadable FROM glpi_documenttypes WHERE ext = 'xlsx'")
    etat_glpiinventory = valeur("SELECT state FROM glpi_plugins WHERE directory = 'glpiinventory'")
    # Une sonde inventée portant le TAG d'une entité et vue à l'instant : l'URL de l'application est « confirmée ».
    sql("INSERT INTO glpi_agents (deviceid, entities_id, name, agenttypes_id, last_contact, version, useragent, tag, locked, itemtype, items_id, "
        f"use_module_network_inventory, use_module_network_discovery) VALUES ('agent-test-sante', {d.CLIENT_A}, 'SONDE-TEST-SANTE', 1, NOW(), "
        "'1.19', 'GLPI-Agent_v1.19', 'CLIENT-TEST-A', 0, 'Computer', 0, 1, 1);")
    agent = int(valeur("SELECT id FROM glpi_agents WHERE deviceid = 'agent-test-sante'"))
    try:
        lib.connecter_admin()

        section("1. Position et contenu")
        page, etats = carte()
        constat("carte « Santé de la configuration » en tête, avant les modules", ok_ko(0 <= page.find("Santé de la configuration") < page.find("Activation des modules")))
        constat("13 contrôles : 9 obligatoires, 4 recommandés (GLS, MBE, journal, clé), l'URL de l'application en tête, la préparation des inventaires réseau juste après les actions automatiques",
                ok_ko(list(etats) == ["app_url", "inventory", "glpiinventory", "dependances", "cron", "collect_prep", "xlsx", "notifications", "tag_rule", "glpicrypt", "gls", "mbe", "log"]), str(etats))
        # Déploiement et GLPI Inventory actifs sur l'instance de test, mais aucun raccordement piloté : rien à préparer.
        constat("préparation des inventaires réseau sans tâche de collecte : « Aucune tâche de collecte », pour information",
                ok_ko(etats.get("collect_prep") == "info" and "Aucune tâche de collecte." in page), str(etats.get("collect_prep")))
        constat("détail replié par défaut : bannière, puis une ligne « N points à voir : … à corriger, … à acquitter », le reste derrière le chevron",
                ok_ko("points à voir" in page and "à corriger" in page and "à acquitter" in page
                      and page.find("points à voir") < page.find("class='collapse'", page.find("points à voir")) < page.find("data-pg-health=")))
        sql("DELETE FROM glpi_configs WHERE context = 'plugin:printgestion' AND name IN ('glpicrypt_checked_at', 'glpicrypt_checked_by');")
        page, etats = carte()
        constat("glpicrypt.key : « non vérifiable automatiquement — jamais vérifié », bouton « J'ai vérifié », rien à cocher",
                ok_ko(etats.get("glpicrypt") == "manual" and "jamais vérifié" in page and "ack_glpicrypt" in page
                      and "type='checkbox'" not in page[page.find("pg-config-health"):page.find("Activation des modules")]))
        constat("bannière : le nombre de prérequis manquants et ce qui casse, une seule fois, sans « quelque chose ne marche pas »",
                ok_ko("prérequis manquant" in page and "quelque chose ne marche pas" not in page and ("ne fonctionnera pas" in page or "ne partira" in page or "mauvaise entité" in page)))
        constat("mode CLI expliqué dans le bouton d'information", ok_ko("modal" in page and "le plus souvent négligé" in page))
        constat("ancienne carte « Journal du plugin » rattachée : une seule occurrence du bouton de test", ok_ko(page.count("name='test_log'") == 1 and etats.get("log") == "ok"))
        # Le formulaire porte data-submit-once (GLPI) : sans formnovalidate sur « Enregistrer », un seul champ
        # invalide annulerait l'envoi sans message, sans requête et sans journal. Et son id doit être à lui.
        bouton_enregistrer = balise_bouton(page, "update")
        constat("bouton « Enregistrer » : formnovalidate posé (la validation du navigateur ne saurait qu'échouer en silence ici)",
                ok_ko(bouton_enregistrer != "" and "formnovalidate" in bouton_enregistrer), bouton_enregistrer[:120])
        constat("le formulaire du plugin a son propre identifiant, pas « main-form » comme celui de GLPI",
                ok_ko("id=\"pg-config-form\"" in page and 'id="main-form"' not in page))
        # Sans jeton CSRF DANS le formulaire, GLPI refuse tout envoi (AccessDeniedHttpException) : le bouton de test
        # rend une erreur et « Enregistrer » ne fait rien. GLPI pose le sien tout en bas, après une longue suite de
        # gabarits et de tables, et il n'arrivait pas jusqu'ici. Le plugin pose le sien en tête, on le vérifie.
        formulaire = lib.Formulaire("printgestion/front/config.form.php")
        formulaire.feed(page)
        noms = [n for n, _ in formulaire.champs]
        constat("le formulaire du plugin porte son jeton CSRF : sans lui, GLPI refuse tout envoi",
                ok_ko("_glpi_csrf_token" in noms), f"{len(noms)} champ(s) lus")
        constat("le bouton « Enregistrer » est rattaché au formulaire par form=, même si l'analyseur HTML le place ailleurs",
                ok_ko('form="pg-config-form"' in bouton_enregistrer), bouton_enregistrer[:140])
        constat("« Tester la connexion » : les deux boutons appellent l'AJAX, la fenêtre de résultat est dans la page, et ils restent des boutons d'envoi (repli sans JS)",
                ok_ko("data-pg-test='test_gls'" in page and "data-pg-test='test_mbe'" in page
                      and "ajax/test_connection.php" in page and "id='pg-test-modal'" in page
                      and "name='test_gls' value='1'" in page))
        reponse = WEB.post(config.AJAX + "/test_connection.php", [("action", "test_mbe")], ajax=True)[1]
        constat("le point d'entrée AJAX répond en JSON (jamais une page d'erreur HTML), avec un message lisible",
                ok_ko(reponse.strip().startswith("{") and '"ok"' in reponse and '"message"' in reponse),
                reponse.strip()[:140])
        reponse = WEB.post(config.AJAX + "/test_connection.php", [("action", "test_inconnu")], ajax=True)[1]
        constat("un test inconnu est refusé, sans appeler personne",
                ok_ko('"ok":false' in reponse.replace(" ", "")), reponse.strip()[:120])

        section("2. Actions automatiques : témoin du cron système, périmètre du plugin et de GLPI Inventory, un bouton par correction")
        maintenant = lib.php_glpi("echo date('Y-m-d H:i:s');").strip()
        # Périmètre de la carte : les tâches du plugin et celles de GLPI Inventory (la collecte en dépend).
        perimetre = "(itemtype LIKE 'PluginPrintgestion%' OR itemtype LIKE 'PluginGlpiinventory%')"
        autres = lambda: lib.lignes(f"SELECT name, mode, state FROM glpi_crontasks WHERE NOT {perimetre} ORDER BY name")  # noqa: E731
        etats_plugin = lambda: lib.lignes(f"SELECT name, state FROM glpi_crontasks WHERE {perimetre} ORDER BY name")  # noqa: E731
        modes_plugin = lambda: lib.lignes(f"SELECT name, mode FROM glpi_crontasks WHERE {perimetre} ORDER BY name")  # noqa: E731
        sql("UPDATE glpi_crontasks SET lastrun = NULL WHERE name = 'PrintgestionTemoinCron';")
        sql("UPDATE glpi_crontasks SET mode = 1, state = 1 WHERE itemtype LIKE 'PluginPrintgestion%' AND name NOT IN ('PrintgestionTemoinCron', 'PrintgestionProposeDemandes');")
        sql("UPDATE glpi_crontasks SET mode = 1, state = 0 WHERE name = 'PrintgestionProposeDemandes';")
        sql("UPDATE glpi_crontasks SET mode = 1, state = 1 WHERE itemtype LIKE 'PluginGlpiinventory%';")
        page, etats = carte()
        constat("témoin jamais passé : rouge « Aucun cron système détecté », PAS de bouton « Passer en mode CLI » (en CLI sans cron, tout s'arrêterait), l'ordre des gestes dit en clair",
                ok_ko(etats.get("cron") == "error" and "Aucun cron système détecté" in page and "cron_switch_cli" not in page
                      and "le passage en CLI n" in page and bandeau(page)))
        avant_modes_sans_cron = modes_plugin()
        WEB.post(config.FRONT + "/config.form.php", [("cron_switch_cli", "1")])
        constat("POST « Passer en mode CLI » sans le bouton et sans cron prouvé : refusé côté serveur, aucun mode changé",
                ok_ko(modes_plugin() == avant_modes_sans_cron), WEB.messages()[:160])
        local_define_sans_cron = lire_local_define()
        WEB.post(config.FRONT + "/config.form.php", [("cron_declare_system", "1")])
        constat("POST « Déclarer le cron système » sans cron prouvé : refusé côté serveur, config/local_define.php inchangé",
                ok_ko(lire_local_define() == local_define_sans_cron), WEB.messages()[:160])
        constat("détail : GLPI_SYSTEM_CRON, les tâches du plugin, celles de GLPI Inventory et le témoin avec leur fiche, queuednotification en lecture seule, la ligne de cron à installer sur le serveur",
                ok_ko("GLPI_SYSTEM_CRON" in page and page.count("Configurer dans GLPI") >= 14 and "queuednotification" in page
                      and "GLPI Inventory — " in page and "Témoin du cron" in page and "front/cron.php</code>" in page))
        constat("témoin jamais passé : pas de bouton « Déclarer le cron système » — on ne déclare pas un cron qui ne tourne pas",
                ok_ko("cron_declare_system" not in page))
        sql(f"UPDATE glpi_crontasks SET lastrun = '{maintenant}' WHERE name = 'PrintgestionTemoinCron';")
        page, etats = carte()
        constat("tâches du plugin en Interne : « Mode Interne — rien ne partira de façon fiable », un bouton par correction, jamais un bouton qui groupe tout",
                ok_ko(etats.get("cron") == "error" and "Mode Interne — rien ne partira de façon fiable" in page
                      and "cron_switch_cli" in page and "confirm(" in page and "switch_plugin_tasks_cli" not in page))
        avant_autres, avant_etats = autres(), etats_plugin()
        WEB.post(config.FRONT + "/config.form.php", [("cron_switch_cli", "1")])
        constat("« Passer en mode CLI » : le mode seulement — tâches du plugin ET de GLPI Inventory en CLI, leur état inchangé, tâches de GLPI et des autres plugins intactes",
                ok_ko(valeur(f"SELECT COUNT(*) FROM glpi_crontasks WHERE {perimetre} AND mode = 1") == "0"
                      and etats_plugin() == avant_etats and autres() == avant_autres),
                WEB.messages()[:120])

        sql("UPDATE glpi_crontasks SET state = 0 WHERE name IN ('PrintgestionSnapshotReadings', 'PrintgestionCheckAlerts', 'taskscheduler');")
        page, etats = carte()
        constat("tâches désactivées, dont le planificateur de GLPI Inventory : rouge, bouton « Activer les 3 tâches désactivées », la proposition automatique jamais comptée dedans",
                ok_ko(etats.get("cron") == "error" and "cron_enable_tasks" in page and "3 tâches désactivées" in page
                      and "doivent être réactivées" in page))
        avant_modes = modes_plugin()
        WEB.post(config.FRONT + "/config.form.php", [("cron_enable_tasks", "1")])
        constat("« Activer » : l'état seulement — les trois tâches réactivées (planificateur de GLPI Inventory compris), les modes inchangés, la proposition automatique toujours désactivée",
                ok_ko(valeur(f"SELECT COUNT(*) FROM glpi_crontasks WHERE {perimetre} AND state = 0") == "1"
                      and valeur("SELECT state FROM glpi_crontasks WHERE name = 'taskscheduler'") == "1"
                      and valeur("SELECT state FROM glpi_crontasks WHERE name = 'PrintgestionProposeDemandes'") == "0"
                      and modes_plugin() == avant_modes),
                WEB.messages()[:120])

        sql("UPDATE glpi_crontasks SET state = 2, lastrun = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE name = 'PrintgestionCheckAlerts';")
        page, etats = carte()
        constat("tâche coincée « en cours d'exécution » : rouge, bouton « Débloquer la tâche bloquée »",
                ok_ko(etats.get("cron") == "error" and "cron_unblock_tasks" in page and "doit être débloquée" in page))
        WEB.post(config.FRONT + "/config.form.php", [("cron_unblock_tasks", "1")])
        constat("« Débloquer » : la tâche repasse en attente et repartira au prochain passage du cron",
                ok_ko(valeur("SELECT state FROM glpi_crontasks WHERE name = 'PrintgestionCheckAlerts'") == "1"),
                WEB.messages()[:120])

        page, etats = carte()
        constat("cron système prouvé, tâches du plugin en CLI et actives : vert, et plus aucun bouton de correction",
                ok_ko(etats.get("cron") == "ok" and "Cron système actif" in page
                      and "cron_switch_cli" not in page and "cron_enable_tasks" not in page
                      and "cron_unblock_tasks" not in page))
        constat("la proposition automatique n'a pas de bouton dans la carte de santé : ce n'est pas une correction, c'est un réglage",
                ok_ko("cron_enable_propose" not in page and "cron_disable_propose" not in page
                      and "Proposition des demandes" in page))

        section("2 bis. Proposition automatique des demandes d'envoi : un interrupteur dans les réglages")
        etat_propose = lambda: valeur("SELECT state FROM glpi_crontasks WHERE name = 'PrintgestionProposeDemandes'")  # noqa: E731
        sql("UPDATE glpi_crontasks SET state = 0 WHERE name = 'PrintgestionProposeDemandes';")
        page, _ = carte()
        constat("carte « Proposition automatique des demandes d'envoi » avec son interrupteur, décoché, et ce qu'elle bloque dit avant le clic",
                ok_ko("Proposition automatique des demandes" in page and "name='enable_propose' value='1'" in page
                      and "name='enable_propose' value='1' checked" not in page
                      and "bloque la commande de sa cartouche" in page))

        def poster_propose(active):
            statut, page_form, _ = WEB.get(ONGLET, ajax=True)
            formulaire = lib.Formulaire("printgestion/front/config.form.php")
            formulaire.feed(page_form)
            exclus = ("_glpi_csrf_token", "update", "enable_propose", "gls_client_secret", "mbe_passphrase")
            champs = [(n, v) for n, v in formulaire.champs if n not in exclus]
            if len(champs) < 5:
                return None, f"HTTP {statut}, {len(champs)} champ(s)"
            champs.append(("update", "1"))
            if active:
                champs.append(("enable_propose", "1"))
            WEB.post(config.FRONT + "/config.form.php", champs)
            return etat_propose(), WEB.messages()[:200]

        apres, detail = poster_propose(True)
        if apres is None:
            constat("formulaire de configuration lu pour activer la proposition automatique", "NON CONCLUANT", detail)
        else:
            constat("interrupteur coché : la tâche automatique de GLPI passe en attente, et le message rappelle le verrou",
                    ok_ko(apres == "1" and "bloque la commande de sa cartouche" in detail), detail[:140])
            page, _ = carte()
            constat("la carte montre l'état réel : interrupteur coché, et ce que couper ne défait pas",
                    ok_ko("name='enable_propose' value='1' checked" in page
                          and "efface pas celles" in page))
            apres, detail = poster_propose(False)
            constat("interrupteur décoché : la tâche est coupée, et le message prévient que les demandes déjà proposées gardent leur verrou",
                    ok_ko(apres == "0" and "gardent leur verrou" in detail), detail[:140])

        sql("UPDATE glpi_crontasks SET lastrun = NULL WHERE name = 'PrintgestionTemoinCron';")
        page, etats = carte()
        constat("tout réglé ici mais cron du serveur absent : la ligne dit qu'aucun bouton de la carte ne la fera passer au vert, et n'en propose plus aucun sur les tâches",
                ok_ko(etats.get("cron") == "error" and "en mode Interne, plus aucune désactivée ni bloquée" in page
                      and "ne manque que le cron du serveur" in page and "cron_switch_cli" not in page
                      and "cron_enable_tasks" not in page and "cron_unblock_tasks" not in page
                      and "cron_declare_system" not in page))

        # Seule action de la carte qui écrit hors de la base : GLPI_SYSTEM_CRON dans config/local_define.php. Le
        # bouton n'est proposé qu'une fois le cron PROUVÉ par le témoin, et si le serveur web peut écrire ce fichier —
        # sinon le contrôle est non concluant, pas en échec, et le détail donne la ligne à ajouter à la main. Le
        # fichier est remis dans son état d'avant juste après (et de nouveau dans le finally).
        sql(f"UPDATE glpi_crontasks SET lastrun = '{maintenant}' WHERE name = 'PrintgestionTemoinCron';")
        page, etats = carte()
        if "cron_declare_system" in page:
            constat("cron du serveur prouvé par le témoin et fichier inscriptible : bouton « Déclarer le cron système (GLPI_SYSTEM_CRON) »",
                    ok_ko("Déclarer le cron système" in page and "confirm(" in page and "prouvé par la tâche témoin" in page))
            WEB.post(config.FRONT + "/config.form.php", [("cron_declare_system", "1")])
            constat("« Déclarer le cron système » : la ligne écrite dans config/local_define.php, sans réécrire le fichier",
                    ok_ko(os.path.exists(LOCAL_DEFINE) and "GLPI_SYSTEM_CRON" in lire_local_define()),
                    WEB.messages()[:160])
            page, etats = carte()
            constat("déclaré et témoin passé : vert « Cron système actif (témoin passé le … »",
                    ok_ko(etats.get("cron") == "ok" and "Cron système actif (témoin passé le" in page))
            sql("UPDATE glpi_crontasks SET lastrun = NULL WHERE name = 'PrintgestionTemoinCron';")
            page, etats = carte()
            constat("déclaré mais le témoin n'a jamais tourné : ROUGE « déclaré, mais aucun cron système ne tourne », les tâches CLI à l'arrêt comptées, la ligne de crontab affichée",
                    ok_ko(etats.get("cron") == "error" and "GLPI_SYSTEM_CRON est déclaré, mais aucun cron système ne tourne" in page
                          and "actives de GLPI et de ses plugins" in page and "front/cron.php</code>" in page and "cron_declare_system" not in page))
            restaurer_local_define()
            page, etats = carte()
            constat("déclaration retirée : toujours rouge « Aucun cron système détecté », rien n'est resté en base",
                    ok_ko(etats.get("cron") == "error" and "Aucun cron système détecté" in page))
        else:
            constat("« Déclarer le cron système » : bouton non proposé, config/local_define.php non inscriptible par le serveur web",
                    "NON CONCLUANT", "le détail doit donner la ligne à ajouter à la main : " + ok_ko("se fait à la main" in page))
        sql(f"UPDATE glpi_crontasks SET lastrun = '{maintenant}' WHERE name = 'PrintgestionTemoinCron';")
        constat("le témoin n'accepte que le mode CLI (allowmode) : jamais de bascule à faire sur lui",
                ok_ko(valeur("SELECT CONCAT(mode, '/', allowmode) FROM glpi_crontasks WHERE name = 'PrintgestionTemoinCron'") == "2/2"))

        section("2 ter. Tâche du plugin absente de GLPI : un bouton pour l'enregistrer, et rien d'autre")
        # Vécu en production : la tâche des raccordements, ajoutée par une version du plugin, n'existe qu'après
        # « Mettre à jour ». Sa ligne est retirée de la base le temps du contrôle, puis remise telle qu'elle était
        # (même identifiant, mêmes réglages), ici et de nouveau dans le finally.
        page, etats = carte()
        constat("toutes les tâches du plugin enregistrées : ni « absente de GLPI », ni bouton « Enregistrer »",
                ok_ko("du plugin absente" not in page and "cron_register_tasks" not in page))
        colonnes = [c[0] for c in lib.lignes("SHOW COLUMNS FROM glpi_crontasks")]
        TACHE_RETIREE = (colonnes, lib.lignes(f"SELECT * FROM glpi_crontasks WHERE {TACHE_ABSENTE}")[0])
        sql(f"DELETE FROM glpi_crontasks WHERE {TACHE_ABSENTE};")
        page, etats = carte()
        bouton = page[page.find("cron_register_tasks"):page.find("</button>", page.find("cron_register_tasks"))]
        constat("tâche des raccordements absente de glpi_crontasks : rouge « 1 tâche du plugin absente de GLPI », bouton « Enregistrer la tâche manquante » confirmé, qui la nomme",
                ok_ko(etats.get("cron") == "error" and "1 tâche du plugin absente de GLPI." in page and bandeau(page)
                      and "Enregistrer la tâche manquante" in bouton and "confirm(" in bouton and "fait avancer les raccordements" in bouton))
        avant_autres, avant_plugin = autres(), etats_plugin()
        WEB.post(config.FRONT + "/config.form.php", [("cron_register_tasks", "1")])
        constat("« Enregistrer » : la tâche revient avec les réglages de l'installation (10 minutes, active), les autres tâches du plugin, de GLPI Inventory et de GLPI ne bougent pas",
                ok_ko(valeur(f"SELECT CONCAT(frequency, '/', state) FROM glpi_crontasks WHERE {TACHE_ABSENTE}") == "600/1"
                      and [t for t in etats_plugin() if t[0] != "PrintgestionRaccordements"] == avant_plugin
                      and autres() == avant_autres),
                WEB.messages()[:120])
        page, etats = carte()
        constat("tâche revenue : plus de phrase « absente de GLPI », plus de bouton « Enregistrer »",
                ok_ko("du plugin absente" not in page and "cron_register_tasks" not in page))
        WEB.post(config.FRONT + "/config.form.php", [("cron_register_tasks", "1")])
        message = WEB.messages()
        constat("POST « Enregistrer » sans tâche manquante : rien n'est créé en double, le message le dit",
                ok_ko(valeur(f"SELECT COUNT(*) FROM glpi_crontasks WHERE {TACHE_ABSENTE}") == "1"
                      and "toutes présentes" in message), message[:120])
        remettre_tache()

        section("3. Autres contrôles obligatoires, un par un")
        sql("UPDATE glpi_configs SET value = '0' WHERE context = 'inventory' AND name = 'enabled_inventory';")
        page, etats = carte()
        constat("inventaire désactivé : rouge, chemin Administration → Inventaire, bannière, page non bloquée",
                ok_ko(etats.get("inventory") == "error" and "Administration → Inventaire" in page and bandeau(page) and "name='threshold_days'" in page))
        sql(f"UPDATE glpi_configs SET value = {lib.q(inventaire)} WHERE context = 'inventory' AND name = 'enabled_inventory';")

        for url, cas in (("http://localhost", "locale"), ("glpi.exemple.test", "sans schéma"), ("", "vide")):
            sql(f"UPDATE glpi_configs SET value = {lib.q(url)} WHERE context = 'core' AND name = 'url_base';")
            page, etats = carte()
            constat(f"URL de l'application {cas} : rouge, lien vers Configuration → Générale, bannière",
                    ok_ko(etats.get("app_url") == "error" and "config.form.php" in page and bandeau(page)), str(etats.get("app_url")))
        sql(f"UPDATE glpi_configs SET value = {lib.q(d.CORE['url_base'])} WHERE context = 'core' AND name = 'url_base';")

        sql("UPDATE glpi_configs SET value = '0' WHERE context = 'core' AND name = 'use_notifications';")
        page, etats = carte()
        constat("notifications GLPI désactivées : rouge, « aucune notification native ne part », lien vers l'écran natif, bannière",
                ok_ko(etats.get("notifications") == "error" and "setup.notification.php" in page and bandeau(page)))
        sql("UPDATE glpi_configs SET value = '1' WHERE context = 'core' AND name = 'use_notifications';")

        sql("UPDATE glpi_documenttypes SET is_uploadable = 0 WHERE ext = 'xlsx';")
        page, etats = carte()
        constat("xlsx non autorisé : rouge, « aucune commande ne part », chemin Types de document",
                ok_ko(etats.get("xlsx") == "error" and "aucune commande ne part" in page and "Types de document" in page and bandeau(page)))
        for ident, autorise in xlsx:
            sql(f"UPDATE glpi_documenttypes SET is_uploadable = {autorise} WHERE id = {ident};")

        # Posé mais désactivé : un pilotage est promis et ne marche pas — c'est une panne, elle reste rouge.
        sql("UPDATE glpi_plugins SET state = 4 WHERE directory = 'glpiinventory';")
        page, etats = carte()
        constat("GLPI Inventory désactivé : rouge, chemin Marketplace", ok_ko(etats.get("glpiinventory") == "error" and "Marketplace" in page and bandeau(page)))
        # Absent (jamais installé) : un choix, pas une panne. Les sondes scannent en local et les imprimantes
        # remontent ; la ligne le dit pour information, et ne déclenche aucun bandeau rouge.
        sql("UPDATE glpi_plugins SET state = 2 WHERE directory = 'glpiinventory';")
        page, etats = carte()
        constat("GLPI Inventory absent : ligne pour information, « scannent en local », aucun bandeau rouge",
                ok_ko(etats.get("glpiinventory") == "info" and "scannent en local" in lib.texte(page) and not bandeau(page)),
                str(etats.get("glpiinventory")))
        constat("GLPI Inventory absent : la ligne ne promet plus qu'aucune imprimante ne remonte",
                ok_ko("Aucune imprimante ne remonte" not in lib.texte(page)))
        sql(f"UPDATE glpi_plugins SET state = {etat_glpiinventory} WHERE directory = 'glpiinventory';")

        # Les emprunts du plugin à GLPI : une table ou une colonne qui disparaît éteint une partie du plugin en
        # silence. Le contrôle doit le voir, le dire, et nommer ce qui cesse de marcher.
        page, etats = carte()
        constat("dépendances : vert quand tout est en place, avec le compte des éléments vérifiés",
                ok_ko(etats.get("dependances") == "ok" and "éléments de GLPI utilisés" in lib.texte(page).lower()
                      or etats.get("dependances") == "ok"), str(etats.get("dependances")))

        section("4. Règle d'affectation par TAG : bouton de la carte")
        lib.supprimer_regles_tag()
        page, etats = carte()
        constat("règle absente : rouge, bouton « Créer la règle d'affectation par TAG » avec confirmation",
                ok_ko(etats.get("tag_rule") == "error" and "name='create_tag_rule'" in page and "confirm(" in page and bandeau(page)))
        WEB.post(config.FRONT + "/config.form.php", [("create_tag_rule", "1")])
        message = WEB.messages()
        page, etats = carte()
        constat("clic : règle créée, ligne verte, bouton disparu", ok_ko(etats.get("tag_rule") == "ok" and "create_tag_rule" not in page and "Règle créée" in message), message[:120])
        sql("UPDATE glpi_rules r JOIN glpi_ruleactions a ON a.rules_id = r.id SET r.is_active = 0 "
            "WHERE r.sub_type = 'RuleImportEntity' AND a.field = '_affect_entity_by_tag';")
        page, etats = carte()
        constat("règle désactivée : rouge « Présente mais désactivée », bouton « Activer la règle »",
                ok_ko(etats.get("tag_rule") == "error" and "Présente mais désactivée" in page and "name='activate_tag_rule'" in page))
        WEB.post(config.FRONT + "/config.form.php", [("activate_tag_rule", "1")])
        page, etats = carte()
        constat("clic : règle activée, ligne verte", ok_ko(etats.get("tag_rule") == "ok"), WEB.messages()[:120])

        section("5. Tout vert : une ligne")
        constat("sauvegarde de la clé jamais acquittée : pas « complète », « 1 point à voir : 1 à acquitter », détail replié",
                ok_ko("Configuration : complète" not in page and etats.get("glpicrypt") == "manual" and "1 point à voir : 1 à acquitter" in page))
        WEB.post(config.FRONT + "/config.form.php", [("ack_glpicrypt", "1")])
        page, etats = carte()
        constat("« J'ai vérifié » : « Vérifié le … par glpi », ligne verte, plus de bouton",
                ok_ko(etats.get("glpicrypt") == "ok" and "Vérifié le" in page and "par glpi" in page and "ack_glpicrypt" not in page))
        # La préparation des inventaires réseau reste « pour information » sur l'instance de test (aucune tâche de
        # collecte) : ni bonne ni mauvaise, elle ne compte pas contre « complète ».
        constat("état complet", ok_ko(all(e == "ok" for k, e in etats.items() if k != "collect_prep")
                                      and etats.get("collect_prep") in ("ok", "info")), str(etats))
        constat("« Configuration : complète », détail replié derrière un chevron, aucune bannière",
                ok_ko("Configuration : complète" in page and not bandeau(page)
                      and page.find("Configuration : complète") < page.find("class='collapse'") < page.find("data-pg-health=")))
        sql("UPDATE glpi_configs SET value = DATE_SUB(value, INTERVAL 7 MONTH) WHERE context = 'plugin:printgestion' AND name = 'glpicrypt_checked_at';")
        page, etats = carte()
        constat("vérification vieille de sept mois : la ligne redevient à acquitter d'elle-même, plus « complète », bouton de retour derrière le chevron",
                ok_ko(etats.get("glpicrypt") == "manual" and "il y a plus de six mois" in page and "ack_glpicrypt" in page and "Configuration : complète" not in page))
        WEB.post(config.FRONT + "/config.form.php", [("ack_glpicrypt", "1")])
        page, etats = carte()

        section("6. URL de l'application : confirmée par un agent, ou jamais")
        constat("sonde vue à l'instant avec le TAG d'une entité : « confirmée par un agent le … », état vert",
                ok_ko(etats.get("app_url") == "ok" and "confirmée par un agent le" in page and "SONDE-TEST-SANTE" in page))
        sql(f"UPDATE glpi_agents SET last_contact = last_contact - INTERVAL 30 DAY WHERE id = {agent};")
        page, etats = carte()
        constat("aucun agent depuis que l'URL est en place : « jamais confirmée », état d'attente (horloge), plus « complète », aucune bannière",
                ok_ko(etats.get("app_url") == "pending" and "jamais confirmée" in page and "Configuration : complète" not in page and not bandeau(page)))
        sql("UPDATE glpi_configs SET value = 'https://glpi2.exemple.test' WHERE context = 'core' AND name = 'url_base';")
        page, etats = carte()  # premier affichage : le mémo repart de l'heure GLPI (pas NOW() de la base : fuseau différent)
        depuis = lambda: valeur("SELECT value FROM glpi_configs WHERE context = 'plugin:printgestion' AND name = 'url_base_seen_since'")  # noqa: E731
        sql(f"UPDATE glpi_agents SET last_contact = {lib.q(depuis())} - INTERVAL 1 MINUTE WHERE id = {agent};")
        page, etats = carte()
        constat("URL changée : le mémo repart de maintenant, un contact antérieur ne confirme plus rien",
                ok_ko(etats.get("app_url") == "pending" and "https://glpi2.exemple.test" in page), f"{etats.get('app_url')} ; depuis {depuis()}")
        sql(f"UPDATE glpi_agents SET last_contact = {lib.q(depuis())} + INTERVAL 1 MINUTE WHERE id = {agent};")
        page, etats = carte()
        constat("contact postérieur au changement : confirmée de nouveau", ok_ko(etats.get("app_url") == "ok" and "confirmée par un agent le" in page))
        sql(f"UPDATE glpi_configs SET value = {lib.q(d.CORE['url_base'])} WHERE context = 'core' AND name = 'url_base';")
        page, etats = carte()
        sql(f"UPDATE glpi_agents SET last_contact = {lib.q(depuis())} + INTERVAL 1 MINUTE WHERE id = {agent};")
        page, etats = carte()
        constat("mémo : URL et date écrits dans la configuration du plugin, jamais dans un réglage",
                ok_ko(valeur("SELECT value FROM glpi_configs WHERE context = 'plugin:printgestion' AND name = 'url_base_seen'") == d.CORE["url_base"]))

        section("8. Tâche de suivi : affichée une seule fois, avec l'avertissement de fréquence")
        lib.connecter_admin()
        page, _ = carte()
        tache = lib.lignes("SELECT id, mode, frequency FROM glpi_crontasks WHERE name = 'PrintgestionTrackingUpdate'")[0]
        constat("plus de champ « Fréquence tracking », plus de carte « Suivi des expéditions : tâche automatique » : la tâche n'est affichée qu'une fois, avec le lien vers sa fiche",
                ok_ko("tracking_frequency" not in page and "Suivi des expéditions : tâche automatique" not in page
                      and page.count(f"crontask.form.php?id={tache[0]}'") == 1
                      and "Configurer dans GLPI" in page and "Dernière exécution" in page))
        sql("UPDATE glpi_crontasks SET mode = 1, frequency = 14400 WHERE name = 'PrintgestionTrackingUpdate';")
        page, _ = carte()
        constat("toutes les 4 heures : plus souvent que les deux passages demandés, donc rien à signaler sur la fréquence",
                ok_ko("Interne (GLPI)" in page and "ne partiront pas de façon fiable" in page and "est plus longue" not in page))
        sql("UPDATE glpi_crontasks SET frequency = 172800 WHERE name = 'PrintgestionTrackingUpdate';")
        page, _ = carte()
        constat("fréquence « tous les 2 jours » signalée comme trop longue pour le suivi, sans la corriger",
                ok_ko("est plus longue" in page and "tous les 2 jours" in page and "deux passages par jour" in page
                      and valeur("SELECT frequency FROM glpi_crontasks WHERE name = 'PrintgestionTrackingUpdate'") == "172800"))
        sql(f"UPDATE glpi_crontasks SET mode = {tache[1]}, frequency = {tache[2]} WHERE name = 'PrintgestionTrackingUpdate';")
        constat("la tâche dit d'abord son travail qui exige l'heure (le suivi des colis), le rattrapage des BL ensuite",
                ok_ko("Suivi des colis GLS" in page and "rattrapage des BL signés" in page))

        section("8 bis. Liaison avec le plugin Gestion : une carte, activée par défaut, absente sans le plugin")
        sql("DELETE FROM glpi_configs WHERE context = 'plugin:printgestion' AND name = 'gestion_link_enabled';")
        reglage = lambda: valeur("SELECT value FROM glpi_configs WHERE context = 'plugin:printgestion' AND name = 'gestion_link_enabled'")  # noqa: E731
        page, _ = carte()
        constat("aucune valeur en base : la carte est là et la liaison est activée par défaut",
                ok_ko("Liaison avec le plugin Gestion" in page and "name='enable_gestion_link' value='1' checked" in page
                      and "Active : le plugin Gestion est actif" in page and reglage() is None))
        # Le formulaire entier est reposté (patron de mbe.py) : la branche « update » relit chaque valeur dans $_POST,
        # un POST réduit remettrait toute la configuration à ses valeurs par défaut.
        def poster_liaison(active):
            statut, page_form, _ = WEB.get(ONGLET, ajax=True)
            formulaire = lib.Formulaire("printgestion/front/config.form.php")
            formulaire.feed(page_form)
            exclus = ("_glpi_csrf_token", "update", "enable_gestion_link", "gls_client_secret", "mbe_passphrase")
            champs = [(n, v) for n, v in formulaire.champs if n not in exclus]
            if len(champs) < 5:
                return None, f"HTTP {statut}, {len(champs)} champ(s)"
            champs.append(("update", "1"))
            if active:
                champs.append(("enable_gestion_link", "1"))
            WEB.post(config.FRONT + "/config.form.php", champs)
            return reglage(), WEB.messages()[:120]

        apres, detail = poster_liaison(False)
        if apres is None:
            constat("formulaire de configuration lu pour couper la liaison", "NON CONCLUANT", detail)
        else:
            constat("interrupteur décoché : la liaison est coupée, la valeur est enregistrée hors du schéma (glpi_configs)",
                    ok_ko(apres == "0"), detail)
            page, _ = carte()
            refus = WEB.post(config.AJAX + "/link_bls.php", [("expedition_id", "1"), ("bls", "[\"1\"]")])[1]
            constat("coupée : la carte le dit, et l'association de BL à une expédition est refusée",
                    ok_ko("Coupée : aucun BL signé" in page and "name='enable_gestion_link' value='1' checked" not in page
                          and "Gestion integration not enabled" in refus), refus[:120])
            apres, detail = poster_liaison(True)
            constat("réactivée par son interrupteur", ok_ko(apres == "1"), detail)
        etat_gestion = valeur("SELECT state FROM glpi_plugins WHERE directory = 'gestion'")
        sql("UPDATE glpi_plugins SET state = 4 WHERE directory = 'gestion';")
        page, _ = carte()
        constat("plugin Gestion présent mais inactif : la carte reste, l'interrupteur aussi, et elle dit que rien ne remontera",
                ok_ko("Liaison avec le plugin Gestion" in page and "inutilisable en l'état" in page))
        sql(f"UPDATE glpi_plugins SET state = {etat_gestion} WHERE directory = 'gestion';")
        # Plugin Gestion jamais installé : sa table des BL n'existe pas. Renommée le temps d'un affichage, remise juste
        # après (et dans le finally si la passe s'interrompt) : l'instance ne reste jamais sans sa table.
        sql("RENAME TABLE glpi_plugin_gestion_surveys TO glpi_plugin_gestion_surveys_absente;")
        page, _ = carte()
        constat("plugin Gestion jamais installé : la carte ne s'affiche pas du tout",
                ok_ko("Liaison avec le plugin Gestion" not in page))
        sql("RENAME TABLE glpi_plugin_gestion_surveys_absente TO glpi_plugin_gestion_surveys;")

        section("9. Filtres de facturation : critères de l'écran Facturation, plus un réglage")
        page, _ = carte()
        constat("configuration : plus de carte « Dashboard Coût à la page — Filtres »", ok_ko("billing_require_contract" not in page and "Coût à la page — Filtres" not in page))
        statut, page, _ = WEB.get(config.FRONT + "/dashboard_billing.php?pg_period=current&pg_view=printer&pg_contract=0&pg_counter=0&pg_activity=0")
        case = lambda nom: re.search(r"<input[^>]*id='pg_" + nom + r"'[^>]*>", page)  # noqa: E731
        constat("écran Facturation : trois critères décochables, page servie", ok_ko(statut == 200 and all(case(n) is not None and "checked" not in case(n).group(0) for n in ("contract", "counter", "activity"))))
        statut, page, _ = WEB.get(config.FRONT + "/dashboard_billing.php?pg_period=current&pg_view=printer&pg_contract=1&pg_counter=1&pg_activity=1")
        constat("critères cochés par défaut ; l'export Excel les porte", ok_ko(all("checked" in case(n).group(0) for n in ("contract", "counter", "activity")) and "contract=1" in page))

        section("10. Sondes muettes : délai natif de nettoyage affiché à côté, avertissement si le seuil le dépasse")
        delai = valeur("SELECT IFNULL((SELECT value FROM glpi_configs WHERE context = 'inventory' AND name = 'stale_agents_delay'), '')")
        seuil = valeur("SELECT silent_days FROM glpi_plugin_printgestion_configs WHERE id = 1")
        sql("INSERT INTO glpi_configs (context, name, value) VALUES ('inventory', 'stale_agents_delay', '2') ON DUPLICATE KEY UPDATE value = '2';")
        sql("UPDATE glpi_plugin_printgestion_configs SET silent_days = 3 WHERE id = 1;")
        page, _ = carte()
        constat("délai natif affiché avec son lien, seuil 3 ≥ délai 2 : avertissement « arriverait après que GLPI a supprimé l'agent »",
                ok_ko("nettoie un agent sans contact après 2 jours" in page and "inventory.conf.php" in page and "arriverait après que GLPI a supprimé" in page))
        sql("UPDATE glpi_configs SET value = '10' WHERE context = 'inventory' AND name = 'stale_agents_delay';")
        page, _ = carte()
        constat("seuil 3 < délai 10 : pas d'avertissement", ok_ko("après 10 jours" in page and "arriverait après" not in page))
        sql("UPDATE glpi_configs SET value = '0' WHERE context = 'inventory' AND name = 'stale_agents_delay';")
        page, _ = carte()
        constat("délai natif à 0 : « GLPI ne nettoie pas les agents », pas d'avertissement", ok_ko("ne nettoie pas les agents" in page and "arriverait après" not in page))
        if delai == "":
            sql("DELETE FROM glpi_configs WHERE context = 'inventory' AND name = 'stale_agents_delay';")
        else:
            sql(f"UPDATE glpi_configs SET value = {lib.q(delai)} WHERE context = 'inventory' AND name = 'stale_agents_delay';")
        sql(f"UPDATE glpi_plugin_printgestion_configs SET silent_days = {int(seuil)} WHERE id = 1;")

        section("11. Suivi GLS : quatre lignes, rien qui manque sans clés")

        cles_origine = lib.lignes("SELECT gls_client_id, gls_client_secret, gls_secret_date FROM glpi_plugin_printgestion_configs WHERE id = 1")[0]
        sql("UPDATE glpi_plugin_printgestion_configs SET gls_client_id = NULL, gls_client_secret = NULL, gls_secret_date = NULL WHERE id = 1;")

        lib.php_glpi("PluginPrintgestionGlsclient::resetMemo();")

        page, etats = carte()

        constat("sans clés : ligne verte « Clés non saisies : pas de suivi GLS », rien ne manque",

                ok_ko(etats.get("gls") == "ok" and "Clés non saisies" in page and "Clés : non saisies." in page))

        secret_chiffre = lib.php_glpi("echo (new GLPIKey())->encrypt('secret-test-sante');").strip()

        sql(f"UPDATE glpi_plugin_printgestion_configs SET gls_client_id = 'client-test', gls_client_secret = {lib.q(secret_chiffre)}, gls_secret_date = NOW() WHERE id = 1;")

        page, etats = carte()

        constat("clés saisies, aucun appel encore : en attente, « Tester la connexion » proposé, secret jamais dans la page",

                ok_ko(etats.get("gls") == "pending" and "aucun appel réussi encore" in page and "secret-test-sante" not in page))

        lib.php_glpi("PluginPrintgestionGlsclient::noteSuccess(); PluginPrintgestionGlsclient::countRequest();")

        page, etats = carte()

        constat("dernier appel réussi : ligne verte, les quatre lignes de détail (clés, dernier appel, échecs, quota 1 sur 500, arrêt à 400)",

                ok_ko(etats.get("gls") == "ok" and "Dernier appel réussi le" in page and "Clés : saisies, secret défini le" in page

                      and "Échecs consécutifs : 0." in page and "Quota consommé aujourd&#039;hui : 1 requête(s) sur 500, arrêt à 400." in page))

        for _ in range(5):

            lib.php_glpi("PluginPrintgestionGlsclient::noteFailure('GLS indisponible (HTTP 503).');")

        page, etats = carte()

        constat("cinq échecs consécutifs : ligne rouge avec la dernière erreur, plus « complète »",

                ok_ko(etats.get("gls") == "error" and "5 échecs techniques consécutifs" in page and "HTTP 503" in page and "Configuration : complète" not in page))

        # Valeurs exactes remises (NULL sur une base neuve, '' après « Retirer les clés ») : les données de référence ne bougent pas.
        v = lambda x: "NULL" if x == "NULL" else lib.q(x)  # noqa: E731
        sql(f"UPDATE glpi_plugin_printgestion_configs SET gls_client_id = {v(cles_origine[0])}, gls_client_secret = {v(cles_origine[1])}, gls_secret_date = {v(cles_origine[2])} WHERE id = 1;")

        lib.php_glpi("PluginPrintgestionGlsclient::resetMemo();")


        section("7. Profils")
        lecture = CTX.profil(4, "Profil test configuration en lecture", {"plugin_printgestion_config": 1})
        CTX.utilisateur("test-config-lecture", lecture, 0)
        lib.supprimer_regles_tag()
        CTX.connecter("test-config-lecture")
        page, etats = carte()
        constat("droit de configuration en lecture : carte visible, sans bouton de règle, de test du journal ni d'acquittement",
                ok_ko(etats.get("tag_rule") == "error" and "create_tag_rule" not in page and "test_log" not in page and "name='ack_glpicrypt'" not in page))
        actifs = [a for a in re.findall(r"<(?:input|select|textarea)\b(?![^>]*\bdisabled\b)[^>]*>", page) if "hidden" not in a]  # champs cachés (jeton) : pas une saisie
        envois = re.findall(r"<button\b(?![^>]*type=['\"]button['\"])(?![^>]*\bdisabled\b)[^>]*>", page)
        constat("lecture seule réelle : aucun champ, liste ou zone de texte modifiable, aucun bouton d'envoi, pas de Sauvegarder",
                ok_ko(not actifs and not envois and "name='update'" not in page and 'name="update"' not in page),
                f"{len(actifs)} champ(s) actif(s) : {' '.join(a[:60] for a in actifs[:3])} ; {len(envois)} bouton(s)")
        constat("lecture seule : les boutons d'affichage (chevrons, fenêtres d'information) restent utilisables (type=button non désactivé)",
                ok_ko(re.search(r"<button type=['\"]button['\"](?![^>]*disabled)", page) is not None))
        intermediaire = CTX.profil(4, "Profil test configuration du plugin sans configuration GLPI", {"plugin_printgestion_config": 3, "config": 1})
        CTX.utilisateur("test-config-sans-glpi", intermediaire, 0)
        CTX.connecter("test-config-sans-glpi")
        page, etats = carte()
        bouton = re.search(r"<button[^>]*name='activate_contract_alerts'[^>]*>", page)
        constat("droit du plugin sans droit de configuration GLPI : bouton « Activer les alertes de contrat natives » désactivé, raison affichée",
                ok_ko(bouton is not None and "disabled" in bouton.group(0) and "demande le droit de configuration de GLPI" in page))
        statut, _, _ = WEB.post(config.FRONT + "/config.form.php", [("activate_contract_alerts", "1")])
        constat("envoi direct refusé (403)", ok_ko(statut == 403), f"HTTP {statut}")
        sans = CTX.profil(4, "Profil test sans configuration du plugin", {"plugin_printgestion_config": 0})
        CTX.utilisateur("test-config-sans", sans, 0)
        CTX.connecter("test-config-sans")
        page, etats = carte()
        constat("sans droit de configuration du plugin : aucune carte", ok_ko("Santé de la configuration" not in page and not etats))
    finally:
        # Table des BL du plugin Gestion renommée le temps d'un contrôle : remise en place si la passe s'est
        # interrompue entre les deux (l'instance de test ne doit jamais rester sans elle).
        if valeur("SHOW TABLES LIKE 'glpi_plugin_gestion_surveys_absente'") is not None:
            sql("RENAME TABLE glpi_plugin_gestion_surveys_absente TO glpi_plugin_gestion_surveys;")
        if LIAISON_GESTION_AVANT is None:
            sql("DELETE FROM glpi_configs WHERE context = 'plugin:printgestion' AND name = 'gestion_link_enabled';")
        else:
            sql(f"UPDATE glpi_configs SET value = {lib.q(LIAISON_GESTION_AVANT)} WHERE context = 'plugin:printgestion' AND name = 'gestion_link_enabled';")
        restaurer_local_define()
        # Avant les tâches : la ligne retirée doit avoir retrouvé son identifiant pour que son état d'origine lui revienne.
        remettre_tache()
        for ident, mode, state, lastrun in cron:
            sql(f"UPDATE glpi_crontasks SET mode = {mode}, state = {state}, lastrun = {'NULL' if lastrun == 'NULL' else lib.q(lastrun)} WHERE id = {ident};")
        sql(f"UPDATE glpi_configs SET value = {lib.q(inventaire)} WHERE context = 'inventory' AND name = 'enabled_inventory';")
        for ident, autorise in xlsx:
            sql(f"UPDATE glpi_documenttypes SET is_uploadable = {autorise} WHERE id = {ident};")
        sql(f"UPDATE glpi_plugins SET state = {etat_glpiinventory} WHERE directory = 'glpiinventory';")
        sql(f"UPDATE glpi_configs SET value = {lib.q(d.CORE['url_base'])} WHERE context = 'core' AND name = 'url_base';")
        sql("UPDATE glpi_configs SET value = '1' WHERE context = 'core' AND name = 'use_notifications';")
        sql(f"DELETE FROM glpi_agents WHERE id = {agent};")
        lib.supprimer_regles_tag()
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
