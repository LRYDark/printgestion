"""Parcours de chaque page et point d'entrée AJAX du plugin avec le compte administrateur de l'instance de test.

Pour chaque requête (GET, puis POST sans action avec un jeton CSRF neuf) : ni erreur 5xx, ni marqueur d'erreur PHP ou
SQL dans la réponse, ni page blanche, ni nouvelle ligne dans php-errors.log ou sql-errors.log.
"""
import os
import re
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, section

MARQUEURS = re.compile(r"(Fatal error|Parse error|Uncaught |Stack trace:|SQLSTATE|Call to undefined|must be of type|"
                       r"Undefined array key|Undefined variable|Undefined property|Division by zero|ArgumentCountError|TypeError:)")


def controler(anomalies, methode, chemin, ajax=False):
    tailles = lib.tailles_journaux()
    envoi = WEB.post if methode == "POST" else WEB.get
    statut, page, _ = envoi(chemin, [], ajax=ajax)
    problemes = []
    if statut >= 500 or statut == 0:
        problemes.append(f"HTTP {statut}")
    trouves = sorted(set(m.group(1) for m in MARQUEURS.finditer(page)))
    if trouves:
        problemes.append("marqueurs " + ", ".join(trouves))
    if not ajax and statut == 200 and len(page.strip()) < 200:
        problemes.append("page blanche")
    journal = lib.journal_depuis(tailles, "php-errors.log") + lib.journal_depuis(tailles, "sql-errors.log")
    if journal.strip():
        problemes.append("journal : " + journal.strip().splitlines()[0][:200])
    if problemes:
        anomalies.append(f"{methode} {chemin} : {' ; '.join(problemes)}")


def main():
    d.verifier_instance()
    section("Parcours des pages et points d'entrée")
    lib.connecter_admin()
    anomalies = []
    pages = sorted(n for n in os.listdir(os.path.join(config.PLUGIN_DIR, "front")) if n.endswith(".php"))
    ajax = sorted(n for n in os.listdir(os.path.join(config.PLUGIN_DIR, "ajax")) if n.endswith(".php"))
    for nom in pages:
        controler(anomalies, "GET", f"{config.FRONT}/{nom}")
        if nom.endswith(".form.php") or nom in ("demande.export.php", "sageimport.php"):
            controler(anomalies, "POST", f"{config.FRONT}/{nom}")
    for nom in ajax:
        controler(anomalies, "GET", f"{config.AJAX}/{nom}", ajax=True)
        controler(anomalies, "POST", f"{config.AJAX}/{nom}", ajax=True)
    for chemin in (f"/front/printer.form.php?id={d.IMP_A1}", f"/front/contract.form.php?id={d.CONTRAT_A}", f"/front/entity.form.php?id={d.CLIENT_A}"):
        controler(anomalies, "GET", chemin)
    constat(f"{2 * len(ajax) + len(pages) + 3} requêtes sur les pages et points d'entrée : aucune anomalie", lib.ok_ko(not anomalies), " | ".join(anomalies[:10]))
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
