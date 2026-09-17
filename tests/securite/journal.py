"""Journal du plugin : il écrit réellement, et un journal non inscriptible ne passe pas inaperçu.

1. Fichier absent, erreur réelle provoquée (écart d'entité corrigé par la tâche PrintgestionEntityScope) : le fichier
   est recréé avec la ligne attendue ; droits du fichier : inscriptible par son propriétaire, pas par tous.
2. Carte « Journal du plugin » de la configuration : « Écrire une entrée de test et la relire » réussit.
3. Dossier des journaux rendu non inscriptible : la carte l'affiche, le bouton de test échoue avec un message clair.
"""
import os
import re
import stat
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql

JOURNAL = os.path.join(config.LOG_DIR, "printgestion.log")
ONGLET = "/ajax/common.tabs.php?_target=%2Ffront%2Fconfig.form.php&_itemtype=Config&_glpi_tab=PluginPrintgestionConfig%241&id=1"


def formulaire_configuration():
    _, page, _ = WEB.get(ONGLET, ajax=True)
    formulaire = lib.Formulaire("printgestion/front/config.form.php")
    formulaire.feed(page)
    return page, [(n, v) for n, v in formulaire.champs if n not in ("_glpi_csrf_token", "update")]


def main():
    d.verifier_instance()
    sauvegarde = JOURNAL + ".avant-test"
    if os.path.exists(JOURNAL):
        os.replace(JOURNAL, sauvegarde)
    try:
        section("1. Erreur réelle : le fichier est recréé avec la ligne attendue")
        lib.connecter_admin()
        sql(f"UPDATE glpi_plugin_printgestion_toner_readings SET entities_id = 999 WHERE printers_id = {d.IMP_BAS} ORDER BY id LIMIT 1;")
        lib.tache("PrintgestionEntityScope")
        contenu = open(JOURNAL, encoding="utf-8").read() if os.path.exists(JOURNAL) else ""
        constat("fichier printgestion.log recréé avec « [ERREUR] entityscope : Entité de lignes corrigée »",
                ok_ko(re.search(r"^\[ERREUR\] entityscope : Entité de lignes corrigée", contenu, re.M) is not None), f"{len(contenu)} octets")
        if os.path.exists(JOURNAL):
            mode = os.stat(JOURNAL).st_mode
            constat("droits du fichier : inscriptible par son propriétaire, jamais par tous",
                    ok_ko(bool(mode & stat.S_IWUSR) and not mode & stat.S_IWOTH), oct(stat.S_IMODE(mode)))

        section("2. Écriture de test depuis la configuration")
        page, champs = formulaire_configuration()
        constat("carte « Journal du plugin » : inscriptible", ok_ko("Journal du plugin" in page and "Inscriptible" in page))
        WEB.post(config.FRONT + "/config.form.php", champs + [("test_log", "1")])
        message = WEB.messages()
        contenu = open(JOURNAL, encoding="utf-8").read() if os.path.exists(JOURNAL) else ""
        constat("« Écrire une entrée de test et la relire » : succès annoncé et entrée présente dans le fichier",
                ok_ko("écrite et relue" in message and "[INFO] journal : Entrée de test écrite par" in contenu), message[:120])

        section("3. Dossier des journaux non inscriptible")
        mode_dossier = stat.S_IMODE(os.stat(config.LOG_DIR).st_mode)
        mode_journal = stat.S_IMODE(os.stat(JOURNAL).st_mode) if os.path.exists(JOURNAL) else None
        try:
            os.chmod(config.LOG_DIR, 0o555)
            if mode_journal is not None:
                os.chmod(JOURNAL, 0o444)
            page, champs = formulaire_configuration()
            constat("carte : « Non inscriptible » affiché", ok_ko("Non inscriptible" in page))
            WEB.post(config.FRONT + "/config.form.php", champs + [("test_log", "1")])
            message = WEB.messages()
            constat("écriture de test : échec annoncé clairement, jamais un faux succès",
                    ok_ko("écriture impossible" in message and "écrite et relue" not in message), message[:120])
        finally:
            os.chmod(config.LOG_DIR, mode_dossier)
            if mode_journal is not None:
                os.chmod(JOURNAL, mode_journal)
    finally:
        lib.tache("PrintgestionEntityScope")
        if os.path.exists(sauvegarde):
            ajout = open(JOURNAL, encoding="utf-8").read() if os.path.exists(JOURNAL) else ""
            os.replace(sauvegarde, JOURNAL)
            with open(JOURNAL, "a", encoding="utf-8") as fichier:
                fichier.write(ajout)
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
