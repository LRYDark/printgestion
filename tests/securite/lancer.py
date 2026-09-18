"""Lance tous les tests du harnais l'un après l'autre et résume leurs résultats.

Usage : python3 lancer.py [nom du test ...]   (par défaut : tous, dans l'ordre ci-dessous)
Prérequis : instance préparée par instance.sh, serveur web de l'instance et smtp_factice.py démarrés.
"""
import os
import re
import subprocess
import time
import sys

import donnees
import lib

ICI = os.path.dirname(os.path.abspath(__file__))
TESTS = ["donnees_reelles", "harnais", "entites", "bl", "gesconso", "commandes", "journal", "prerequis", "interface", "sante", "suivi_gls", "mbe", "modules", "gabarits", "parcours", "taches", "installation", "recette_vide"]


def main():
    donnees.verifier_instance()
    choisis = sys.argv[1:] or TESTS
    tailles = lib.tailles_journaux()
    depart = time.time()
    resume = []
    for nom in choisis:
        avant = donnees.empreinte()
        res = subprocess.run([sys.executable, os.path.join(ICI, f"{nom}.py")], capture_output=True, text=True)
        sortie = res.stdout + res.stderr
        print(sortie)
        ligne = re.findall(r"^Résultat : .*$", sortie, re.M)
        apres = donnees.empreinte()
        # installation.py désinstalle et réinstalle le plugin (données de référence remises aux lignes par défaut) ;
        # taches.py et recette_vide.py font écrire les tâches (mémos de version d'agent, vues recalculées) : c'est leur objet.
        modifiees = [] if nom in ("installation", "recette_vide", "taches") else [cle for cle in avant if avant[cle] != apres[cle]]
        # Le détail de l'écart, pas seulement son nom : sans lui, un écart ponctuel ne s'explique pas.
        for cle in modifiees:
            if cle == "configuration":
                colonnes = [c[0] for c in lib.lignes("SHOW COLUMNS FROM glpi_plugin_printgestion_configs")]
                for ligne_avant, ligne_apres in zip(avant[cle], apres[cle]):
                    for colonne, x, y in zip(colonnes, ligne_avant, ligne_apres):
                        if x != y:
                            print(f"    écart {cle} : {colonne} : {x!r} → {y!r}")
            else:
                for r in [r for r in avant[cle] if r not in apres[cle]][:5]:
                    print(f"    écart {cle} : disparu {r}")
                for r in [r for r in apres[cle] if r not in avant[cle]][:5]:
                    print(f"    écart {cle} : apparu {r}")
        resume.append(f"{nom:10} code {res.returncode if not modifiees else 1} — {ligne[-1] if ligne else 'pas de bilan (voir la sortie)'}"
                      + (f" — DONNÉES DE RÉFÉRENCE MODIFIÉES : {', '.join(modifiees)}" if modifiees else ""))
    erreurs = lib.erreurs_php_depuis(tailles, attendues=("Fichier Gesconso non archivé", "panne simulee", "Proposition des demandes d'envoi", "Fréquence des relevés hors service"))
    # Gabarits : aucune variable non résolue dans les mails capturés pendant la passe.
    non_resolues = sorted({m for courriel in lib.mails_depuis(depart) for m in re.findall(r"##[a-z0-9_.]+##", courriel["sujet"] + courriel["html"])})
    resume.append(f"gabarits   code {1 if non_resolues else 0} — {len(non_resolues)} variable(s) non résolue(s) dans les mails capturés" + (" : " + ", ".join(non_resolues[:10]) if non_resolues else ""))
    print("======== Synthèse")
    print("\n".join(resume))
    print(f"journaux GLPI : {len(erreurs)} erreur(s) PHP ou SQL inattendue(s)" + ("".join(f"\n    {e}" for e in erreurs[:10])))
    return 1 if any(" code 0 " not in r for r in resume) or erreurs else 0


if __name__ == "__main__":
    sys.exit(main())
