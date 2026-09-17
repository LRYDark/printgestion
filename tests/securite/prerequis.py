"""Prérequis GLPI Inventory : une version plus ancienne bloque, une version plus récente avertit seulement.

La version réelle ne se simule pas en base (GLPI désactive un plugin dont la version enregistrée diffère de ses
fichiers) : la règle est vérifiée sur PluginPrintgestionCollectsetup::checkVersion(), celle qu'appelle l'assistant,
puis sur la version réellement installée sur l'instance de test.
"""
import json
import sys

import donnees as d
import lib
from lib import section, verifier


def controle(version):
    sortie = lib.php_glpi(f"echo json_encode(PluginPrintgestionCollectsetup::checkVersion({json.dumps(version)}));")
    resultat = json.loads(sortie)
    return (len(resultat["blocking"]), len(resultat["warnings"]))


def main():
    d.verifier_instance()
    section("Borne de version de GLPI Inventory")
    verifier("1.5.9 (plus ancienne que le minimum) : bloquant", controle("1.5.9"), (1, 0))
    verifier("1.6.0 (minimum) : accepté sans remarque", controle("1.6.0"), (0, 0))
    verifier("1.6.10 (version validée) : accepté sans remarque", controle("1.6.10"), (0, 0))
    verifier("1.6.12 (plus récente que la validée) : avertissement seulement", controle("1.6.12"), (0, 1))
    verifier("1.7.0 (nouvelle série) : avertissement seulement, jamais bloquant", controle("1.7.0"), (0, 1))
    installee = json.loads(lib.php_glpi("echo json_encode(PluginPrintgestionCollectsetup::getPrerequisites());"))
    verifier(f"version installée sur l'instance de test ({installee['version']}) : aucun blocage", installee["blocking"], [])
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
