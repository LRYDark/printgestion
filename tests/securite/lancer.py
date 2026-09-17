"""Lance tous les tests du harnais l'un après l'autre et résume leurs résultats.

Usage : python3 lancer.py [nom du test ...]   (par défaut : tous, dans l'ordre ci-dessous)
Prérequis : instance préparée par instance.sh, serveur web de l'instance et smtp_factice.py démarrés.
"""
import os
import re
import subprocess
import sys

import donnees
import lib

ICI = os.path.dirname(os.path.abspath(__file__))
TESTS = ["harnais", "entites", "bl", "gesconso", "commandes", "journal", "prerequis", "parcours"]


def main():
    donnees.verifier_instance()
    choisis = sys.argv[1:] or TESTS
    tailles = lib.tailles_journaux()
    resume = []
    for nom in choisis:
        avant = donnees.empreinte()
        res = subprocess.run([sys.executable, os.path.join(ICI, f"{nom}.py")], capture_output=True, text=True)
        sortie = res.stdout + res.stderr
        print(sortie)
        ligne = re.findall(r"^Résultat : .*$", sortie, re.M)
        apres = donnees.empreinte()
        modifiees = [cle for cle in avant if avant[cle] != apres[cle]]
        resume.append(f"{nom:10} code {res.returncode if not modifiees else 1} — {ligne[-1] if ligne else 'pas de bilan (voir la sortie)'}"
                      + (f" — DONNÉES DE RÉFÉRENCE MODIFIÉES : {', '.join(modifiees)}" if modifiees else ""))
    erreurs = lib.erreurs_php_depuis(tailles, attendues=("Fichier Gesconso non archivé", "panne simulee", "Proposition des demandes d'envoi"))
    print("======== Synthèse")
    print("\n".join(resume))
    print(f"journaux GLPI : {len(erreurs)} erreur(s) PHP ou SQL inattendue(s)" + ("".join(f"\n    {e}" for e in erreurs[:10])))
    return 1 if any(" code 0 " not in r for r in resume) or erreurs else 0


if __name__ == "__main__":
    sys.exit(main())
