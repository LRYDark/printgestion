"""Chaque tâche automatique du plugin, sur la base peuplée par le jeu de données : aucune en erreur, aucune erreur PHP ou
SQL écrite pendant le passage. (Sur base vide : recette_vide.py.)
"""
import sys

import donnees as d
import lib
import taches_lib
from lib import section, verifier


def main():
    d.verifier_instance()
    section("Toutes les tâches automatiques, base peuplée")
    resultats, journal = taches_lib.passer_toutes()
    for tache, (erreur, messages) in resultats.items():
        verifier(f"{tache} : terminée sans erreur ({messages[:90] or 'sans message'})", erreur, False)
    verifier("aucune erreur PHP ou SQL pendant les passages", journal.strip()[:200], "")
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
