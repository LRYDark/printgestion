"""Carte « Santé de la configuration » : contrôles automatiques de l'environnement, en tête de la configuration.

Chaque contrôle obligatoire est mis en défaut puis rétabli : ligne rouge, bannière impossible à manquer, page non
bloquée (le formulaire reste là). Actions automatiques : vert seulement sans action active en mode GLPI ET avec une
action en mode CLI lancée dans l'heure. Sauvegarde de glpicrypt.key : ligne « non vérifiable automatiquement »,
jamais une case à cocher. Tout vert : une ligne « Configuration : complète », détail replié. Profils : lecture seule
du droit de configuration → carte sans bouton ; sans ce droit → pas de carte.
"""
import re
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql, valeur

ONGLET = "/ajax/common.tabs.php?_target=%2Ffront%2Fconfig.form.php&_itemtype=Config&_glpi_tab=PluginPrintgestionConfig%241&id=1"
CTX = lib.Contexte()


def carte():
    _, page, _ = WEB.get(ONGLET, ajax=True)
    etats = dict(re.findall(r"data-pg-health='(\w+)' data-pg-state='(\w+)'", page))
    return page, etats


def bandeau(page):
    return "alert alert-danger" in page and "prérequis obligatoire" in page


def main():
    d.verifier_instance()
    cron = lib.lignes("SELECT id, mode, IFNULL(lastrun, 'NULL') FROM glpi_crontasks")
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
        constat("9 contrôles : 7 obligatoires, 2 recommandés, l'URL de l'application en tête",
                ok_ko(list(etats) == ["app_url", "inventory", "glpiinventory", "cron", "xlsx", "notifications", "tag_rule", "glpicrypt", "log"]), str(etats))
        constat("glpicrypt.key : « non vérifiable automatiquement », état neutre, rien à cocher dans la carte",
                ok_ko(etats.get("glpicrypt") == "manual" and "Non vérifiable automatiquement" in page
                      and "type='checkbox'" not in page[page.find("pg-config-health"):page.find("Activation des modules")]))
        constat("mode CLI expliqué dans le bouton d'information", ok_ko("modal" in page and "le plus souvent négligé" in page))
        constat("ancienne carte « Journal du plugin » rattachée : une seule occurrence du bouton de test", ok_ko(page.count("name='test_log'") == 1 and etats.get("log") == "ok"))

        section("2. Actions automatiques : témoin du cron système, périmètre du plugin, bascule explicite")
        maintenant = lib.php_glpi("echo date('Y-m-d H:i:s');").strip()
        autres = lambda: lib.lignes("SELECT name, mode FROM glpi_crontasks WHERE itemtype NOT LIKE 'PluginPrintgestion%' ORDER BY name")  # noqa: E731
        sql("UPDATE glpi_crontasks SET lastrun = NULL WHERE name = 'PrintgestionTemoinCron';")
        sql("UPDATE glpi_crontasks SET mode = 1 WHERE itemtype LIKE 'PluginPrintgestion%' AND name <> 'PrintgestionTemoinCron';")
        page, etats = carte()
        constat("témoin jamais passé : rouge « Aucun cron système détecté », aucune bascule proposée même avec des tâches en Interne",
                ok_ko(etats.get("cron") == "error" and "Aucun cron système détecté" in page and "switch_plugin_tasks_cli" not in page and bandeau(page)))
        constat("détail : GLPI_SYSTEM_CRON, les 8 tâches du plugin et le témoin avec leur fiche, queuednotification en lecture seule",
                ok_ko("GLPI_SYSTEM_CRON" in page and page.count("Configurer dans GLPI") >= 10 and "queuednotification" in page and "Témoin du cron" in page))
        sql(f"UPDATE glpi_crontasks SET lastrun = '{maintenant}' WHERE name = 'PrintgestionTemoinCron';")
        page, etats = carte()
        constat("témoin passé, tâches du plugin en Interne : « Mode Interne — rien ne partira de façon fiable », bouton de bascule (clic explicite)",
                ok_ko(etats.get("cron") == "error" and "Mode Interne — rien ne partira de façon fiable" in page and "switch_plugin_tasks_cli" in page and "confirm(" in page))
        avant_autres = autres()
        WEB.post(config.FRONT + "/config.form.php", [("switch_plugin_tasks_cli", "1")])
        constat("bascule : les tâches du plugin en CLI, les tâches de GLPI et des autres plugins intactes",
                ok_ko(valeur("SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype LIKE 'PluginPrintgestion%' AND mode = 1") == "0" and autres() == avant_autres),
                WEB.messages()[:120])
        page, etats = carte()
        constat("cron système prouvé et tâches du plugin en CLI : vert", ok_ko(etats.get("cron") == "ok" and "Cron système actif" in page))
        constat("le témoin n'accepte que le mode CLI (allowmode) : jamais de bascule à faire sur lui",
                ok_ko(valeur("SELECT CONCAT(mode, '/', allowmode) FROM glpi_crontasks WHERE name = 'PrintgestionTemoinCron'") == "2/2"))

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

        sql("UPDATE glpi_plugins SET state = 4 WHERE directory = 'glpiinventory';")
        page, etats = carte()
        constat("GLPI Inventory désactivé : rouge, chemin Marketplace", ok_ko(etats.get("glpiinventory") == "error" and "Marketplace" in page and bandeau(page)))
        sql(f"UPDATE glpi_plugins SET state = {etat_glpiinventory} WHERE directory = 'glpiinventory';")

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
        constat("état complet (hors rappel non vérifiable)", ok_ko(all(e in ("ok", "manual") for e in etats.values())), str(etats))
        constat("« Configuration : complète », détail replié derrière un chevron, aucune bannière",
                ok_ko("Configuration : complète" in page and not bandeau(page)
                      and page.find("Configuration : complète") < page.find("class='collapse'") < page.find("data-pg-health=")))

        section("6. URL de l'application : confirmée par un agent, ou jamais")
        constat("sonde vue à l'instant avec le TAG d'une entité : « confirmée par un agent le … », état vert",
                ok_ko(etats.get("app_url") == "ok" and "confirmée par un agent le" in page and "SONDE-TEST-SANTE" in page))
        sql(f"UPDATE glpi_agents SET last_contact = last_contact - INTERVAL 30 DAY WHERE id = {agent};")
        page, etats = carte()
        constat("aucun agent depuis que l'URL est en place : « jamais confirmée », état d'attente (horloge), carte non repliée",
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

        section("8. Tâche de suivi : encart en lecture seule, plus de champ de fréquence")
        lib.connecter_admin()
        page, _ = carte()
        tache = lib.lignes("SELECT id, mode, frequency FROM glpi_crontasks WHERE name = 'PrintgestionTrackingUpdate'")[0]
        constat("plus de champ « Fréquence tracking » ; encart avec l'état, le mode, la fréquence, la dernière exécution et le lien vers la fiche de la tâche",
                ok_ko("tracking_frequency" not in page and "Suivi des expéditions : tâche automatique" in page and f"crontask.form.php?id={tache[0]}" in page
                      and "Configurer dans GLPI" in page and "Dernière exécution" in page))
        sql("UPDATE glpi_crontasks SET mode = 1, frequency = 14400 WHERE name = 'PrintgestionTrackingUpdate';")
        page, _ = carte()
        constat("mode Interne dit en toutes lettres ; fréquence de 4 h signalée comme trop longue pour le suivi, sans la corriger",
                ok_ko("Interne (GLPI)" in page and "ne partiront pas de façon fiable" in page and "est plus longue" in page
                      and valeur("SELECT frequency FROM glpi_crontasks WHERE name = 'PrintgestionTrackingUpdate'") == "14400"))
        sql(f"UPDATE glpi_crontasks SET mode = {tache[1]}, frequency = {tache[2]} WHERE name = 'PrintgestionTrackingUpdate';")

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

        section("7. Profils")
        lecture = CTX.profil(4, "Profil test configuration en lecture", {"plugin_printgestion_config": 1})
        CTX.utilisateur("test-config-lecture", lecture, 0)
        lib.supprimer_regles_tag()
        CTX.connecter("test-config-lecture")
        page, etats = carte()
        constat("droit de configuration en lecture : carte visible, sans bouton de règle ni de test du journal",
                ok_ko(etats.get("tag_rule") == "error" and "create_tag_rule" not in page and "test_log" not in page))
        actifs = [a for a in re.findall(r"<(?:input|select|textarea)\b(?![^>]*\bdisabled\b)[^>]*>", page) if "hidden" not in a]  # champs cachés (jeton) : pas une saisie
        envois = re.findall(r"<button\b(?![^>]*type=['\"]button['\"])(?![^>]*\bdisabled\b)[^>]*>", page)
        constat("lecture seule réelle : aucun champ, liste ou zone de texte modifiable, aucun bouton d'envoi, pas de Sauvegarder",
                ok_ko(not actifs and not envois and "name='update'" not in page and 'name="update"' not in page),
                f"{len(actifs)} champ(s) actif(s) : {' '.join(a[:60] for a in actifs[:3])} ; {len(envois)} bouton(s)")
        constat("lecture seule : « Qui est notifié ? » et les chevrons restent utilisables (type=button non désactivé)",
                ok_ko(re.search(r"<button type='button'(?![^>]*disabled)[^>]*data-bs-target='#pg-notif-modal'", page) is not None))
        sans = CTX.profil(4, "Profil test sans configuration du plugin", {"plugin_printgestion_config": 0})
        CTX.utilisateur("test-config-sans", sans, 0)
        CTX.connecter("test-config-sans")
        page, etats = carte()
        constat("sans droit de configuration du plugin : aucune carte", ok_ko("Santé de la configuration" not in page and not etats))
    finally:
        for ident, mode, lastrun in cron:
            sql(f"UPDATE glpi_crontasks SET mode = {mode}, lastrun = {'NULL' if lastrun == 'NULL' else lib.q(lastrun)} WHERE id = {ident};")
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
