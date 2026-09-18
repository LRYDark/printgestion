"""Gabarits de mail du plugin : jamais réécrits par une installation ou une mise à jour.
1. Sujet modifié par l'administrateur, puis réinstallation forcée du plugin : la modification reste.
2. Gabarit absent (renommé pour le test) : recréé avec le texte par défaut, identifiant repris dans la configuration.
3. Aperçu des six gabarits régénéré (tools/generate_apercu.php) : aucune variable non résolue.
Remet tout en place : gabarit d'origine, sujet, identifiant dans la configuration, gabarit recréé supprimé.
"""
import os
import re
import subprocess
import sys

import config
import donnees as d
import lib
from lib import lignes, section, sql, valeur, verifier

TPL, TRAD, CONFIGS = "glpi_notificationtemplates", "glpi_notificationtemplatetranslations", "glpi_plugin_printgestion_configs"


def reinstaller():
    """Réinstallation forcée : le chemin de mise à jour du plugin (migration à la même version, gabarits)."""
    for commande in (["plugin:install", "-u", config.GLPI_LOGIN, "printgestion", "-n", "--force"], ["plugin:activate", "printgestion", "-n"]):
        res = subprocess.run([config.PHP, "bin/console", *commande], cwd=config.GLPI_DIR, capture_output=True, text=True)
        if res.returncode != 0:
            raise RuntimeError(f"{commande[0]} : {(res.stderr or res.stdout)[-300:]}")


def main():
    d.verifier_instance()
    ident, nom = lignes(f"SELECT t.id, t.name FROM {TPL} t JOIN {TRAD} tr ON tr.notificationtemplates_id = t.id AND tr.language = 'fr_FR' "
                        "WHERE t.comment = 'Created by plugin printgestion' ORDER BY t.id LIMIT 1")[0]
    ident = int(ident)
    colonnes = [c[0] for c in lignes(f"SHOW COLUMNS FROM {CONFIGS} LIKE 'gabarit_%'")]
    champ = next(c for c in colonnes if valeur(f"SELECT {c} FROM {CONFIGS} WHERE id = 1") == str(ident))
    sujet = valeur(f"SELECT subject FROM {TRAD} WHERE notificationtemplates_id = {ident} AND language = 'fr_FR'")
    recree = None
    try:
        section("1. Sujet modifié par l'administrateur, réinstallation forcée")
        sql(f"UPDATE {TRAD} SET subject = 'Sujet modifié par le test' WHERE notificationtemplates_id = {ident} AND language = 'fr_FR';")
        reinstaller()
        verifier("sujet conservé après réinstallation", valeur(f"SELECT subject FROM {TRAD} WHERE notificationtemplates_id = {ident} AND language = 'fr_FR'"),
                 "Sujet modifié par le test")
        verifier("identifiant inchangé dans la configuration", valeur(f"SELECT {champ} FROM {CONFIGS} WHERE id = 1"), str(ident))

        section("2. Gabarit absent : recréé avec le texte par défaut, configuration mise à jour")
        sql(f"UPDATE {TPL} SET name = CONCAT(name, ' (test)') WHERE id = {ident};")
        reinstaller()
        recree = valeur(f"SELECT IFNULL(MAX(id), 0) FROM {TPL} WHERE name = '{nom}' AND comment = 'Created by plugin printgestion' AND id <> {ident}")
        verifier("gabarit recréé sous son nom, avec une traduction", (recree != "0", valeur(f"SELECT COUNT(*) FROM {TRAD} WHERE notificationtemplates_id = {recree}")), (True, "1"))
        verifier("identifiant du gabarit recréé repris dans la configuration", valeur(f"SELECT {champ} FROM {CONFIGS} WHERE id = 1"), recree)
        verifier("gabarit renommé (modifié par l'administrateur) laissé intact", valeur(f"SELECT subject FROM {TRAD} WHERE notificationtemplates_id = {ident} AND language = 'fr_FR'"),
                 "Sujet modifié par le test")
        section("3. Aperçu des gabarits : aucune variable non résolue")
        res = subprocess.run([config.PHP, "tools/generate_apercu.php"], cwd=config.PLUGIN_DIR, capture_output=True, text=True)
        apercu = open(os.path.join(config.PLUGIN_DIR, "docs", "apercu_gabarits.html"), encoding="utf-8").read() if res.returncode == 0 else ""
        verifier("aperçu généré, sans « ##variable## » restante", (res.returncode, sorted(set(re.findall(r"##[a-z0-9_.]+##", apercu)))), (0, []))
    finally:
        if recree not in (None, "0"):
            sql(f"DELETE FROM {TRAD} WHERE notificationtemplates_id = {recree}; DELETE FROM {TPL} WHERE id = {recree};")
        sql(f"UPDATE {TPL} SET name = '{nom}' WHERE id = {ident}; "
            f"UPDATE {TRAD} SET subject = '{sujet.replace(chr(39), chr(39) * 2)}' WHERE notificationtemplates_id = {ident} AND language = 'fr_FR'; "
            f"UPDATE {CONFIGS} SET {champ} = {ident} WHERE id = 1;")
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
