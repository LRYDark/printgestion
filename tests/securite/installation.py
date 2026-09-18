"""Installation 1.0.0 : refus par-dessus une autre version, désinstallation complète, réinstallation identique à la
référence relevée sur l'ancien chemin.
1. Base portant une autre version de schéma : installation refusée avec « désinstallez d'abord », rien n'est touché.
2. Désinstallation : plus aucune table du plugin (vivante ou ancienne), aucune tâche, notification, gabarit, droit,
   préférence d'affichage, recherche enregistrée, valeur de configuration du plugin, ni journal ; la règle
   d'affectation par TAG (native) reste.
3. Réinstallation : l'état relevé (tables, colonnes, index, lignes de référence, objets natifs) est identique à
   tests/securite/reference/etat-ancien-chemin.json : zéro écart.
Dernier fichier de la suite : l'instance ressort sans le jeu de données (relancer instance.sh avant une autre passe).
"""
import json
import os
import re
import subprocess
import sys
import tempfile

import comparer_etats
import config
import donnees as d
import etat_installation
import lib
from lib import section, sql, valeur, verifier

REFERENCE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "reference", "etat-ancien-chemin.json")
# Ce que la 1.0.0 change volontairement par rapport à l'ancien chemin : une ligne par élément, avec la raison.
ECARTS_VOLONTAIRES = {
    '/tables/glpi_plugin_printgestion_expeditions/colonnes[25]/column_name : "tracking_event_date" → "tracking_event_datetime"':
        "date du dernier événement GLS gardée telle que GLS l'envoie, décalage compris ; heure locale et règle des 30 jours dérivées à la lecture",
    '/tables/glpi_plugin_printgestion_expeditions/colonnes[25]/column_type : "timestamp" → "varchar(40)"':
        "même raison : une chaîne ISO 8601 avec décalage, pas un instant converti",
    '/tables/glpi_plugin_printgestion_expeditions/colonnes[25]/character_set_name : "NULL" → "utf8mb4"':
        "conséquence : colonne texte",
    '/tables/glpi_plugin_printgestion_expeditions/colonnes[25]/collation_name : "NULL" → "utf8mb4_unicode_ci"':
        "conséquence : colonne texte",
}
MOTIFS_NOTICE = re.compile(r"(Notice|Warning|Deprecated|Fatal error|Uncaught|Stack trace)")


def console(*args):
    res = subprocess.run([config.PHP, "bin/console", *args, "-n"], cwd=config.GLPI_DIR, capture_output=True, text=True)
    return res.returncode, res.stdout + res.stderr


def tables_plugin():
    return int(valeur("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'glpi\\_plugin\\_printgestion\\_%'"))


def main():
    d.verifier_instance()
    section("1. Une autre version en base : installation refusée, rien n'est touché")
    sql("UPDATE glpi_configs SET value = '0.9.0' WHERE context = 'plugin:printgestion' AND name = 'schema_version';")
    avant = tables_plugin()
    code, sortie = console("plugin:install", "-u", config.GLPI_LOGIN, "-f", "printgestion")
    verifier("refus avec le message « désinstallez d'abord », version et tables intactes",
             ("sinstallez d" in sortie, valeur("SELECT value FROM glpi_configs WHERE context = 'plugin:printgestion' AND name = 'schema_version'"), tables_plugin()),
             (True, "0.9.0", avant))
    sql("UPDATE glpi_configs SET value = '1.0.0' WHERE context = 'plugin:printgestion' AND name = 'schema_version';")
    console("plugin:activate", "printgestion")

    section("2. Désinstallation complète, la règle d'affectation par TAG reste")
    lib.connecter_admin()
    tailles = lib.tailles_journaux()
    sorties = []
    lib.creer_regle_tag()
    regles = lambda: int(valeur("SELECT COUNT(DISTINCT r.id) FROM glpi_rules r JOIN glpi_ruleactions a ON a.rules_id = r.id "  # noqa: E731
                                "WHERE r.sub_type = 'RuleImportEntity' AND a.field = '_affect_entity_by_tag'"))
    regles_avant = regles()
    sorties.append(console("plugin:deactivate", "printgestion")[1])
    code, sortie = console("plugin:uninstall", "printgestion")
    sorties.append(sortie)
    restes = {
        "tables": tables_plugin(),
        "tâches": valeur("SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype LIKE 'PluginPrintgestion%'"),
        "notifications": valeur("SELECT COUNT(*) FROM glpi_notifications WHERE itemtype LIKE 'PluginPrintgestion%' OR comment = 'Created by plugin printgestion'"),
        "gabarits": valeur("SELECT COUNT(*) FROM glpi_notificationtemplates WHERE itemtype LIKE 'PluginPrintgestion%' OR comment = 'Created by plugin printgestion'"),
        "droits": valeur("SELECT COUNT(*) FROM glpi_profilerights WHERE name LIKE 'plugin_printgestion%'"),
        "configuration": valeur("SELECT COUNT(*) FROM glpi_configs WHERE context = 'plugin:printgestion'"),
        "préférences": valeur("SELECT COUNT(*) FROM glpi_displaypreferences WHERE itemtype LIKE 'PluginPrintgestion%'"),
        "recherches": valeur("SELECT COUNT(*) FROM glpi_savedsearches WHERE itemtype LIKE 'PluginPrintgestion%'"),
        "liens documents": valeur("SELECT COUNT(*) FROM glpi_documents_items WHERE itemtype LIKE 'PluginPrintgestion%'"),
        "journal": os.path.exists(os.path.join(config.LOG_DIR, "printgestion.log")),
    }
    verifier("désinstallation : plus rien du plugin en base ni en journal", {k: str(v) for k, v in restes.items()},
             {k: ("False" if k == "journal" else "0") for k in restes})
    verifier("la règle d'affectation par TAG, native, est toujours là", regles(), regles_avant)
    lib.supprimer_regles_tag()

    section("3. Réinstallation : état identique à la référence de l'ancien chemin")
    code, sortie = console("plugin:install", "-u", config.GLPI_LOGIN, "printgestion")
    sorties.append(sortie)
    sorties.append(console("plugin:activate", "printgestion")[1])
    verifier("installation neuve réussie, version 1.0.0", (code, valeur("SELECT value FROM glpi_configs WHERE context = 'plugin:printgestion' AND name = 'schema_version'")), (0, "1.0.0"))
    # « *** Test logger » : sonde d'écriture de GLPI lui-même (Config::checkWriteAccessToDirs) à chaque commande de console, pas le plugin.
    journal = "\n".join(l for l in (lib.journal_depuis(tailles, "php-errors.log") + lib.journal_depuis(tailles, "sql-errors.log")).splitlines() if "Test logger" not in l)
    verifier("cycle désinstallation / installation / activation : aucune notice, aucun avertissement, aucune erreur (console et journaux)",
             (sorted({m.group(1) for s in sorties for m in MOTIFS_NOTICE.finditer(s)}), journal.strip()[:200]), ([], ""))
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as fichier:
        etat = {"tables": {t: etat_installation.etat_table(t) for t in etat_installation.tables()}, "natifs": etat_installation.natifs()}
        etat["natifs"]["configuration"] = [c for c in etat["natifs"]["configuration"] if c["name"] != "schema_version"]
        json.dump(etat, fichier, ensure_ascii=False, sort_keys=True)
    ecarts = list(comparer_etats.ecarts(json.load(open(REFERENCE, encoding="utf-8")), json.load(open(fichier.name, encoding="utf-8"))))
    for ligne in ecarts:
        print(f"    écart {'volontaire' if ligne in ECARTS_VOLONTAIRES else 'NON DÉCLARÉ'} : {ligne}" + (f" — {ECARTS_VOLONTAIRES[ligne]}" if ligne in ECARTS_VOLONTAIRES else ""))
    verifier(f"tables, colonnes, index, lignes de référence, tâches, notifications, gabarits, droits : aucun écart avec la référence hors les {len(ECARTS_VOLONTAIRES)} écarts volontaires déclarés",
             sorted(ecarts), sorted(ECARTS_VOLONTAIRES))
    os.unlink(fichier.name)
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
