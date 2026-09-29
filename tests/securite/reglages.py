"""Réglages lisibles : sommaire et sections de Configuration > Print Gestion, carte des réglages des sondes.

Le sommaire mène à six sections dans l'ordre du menu ; le seuil « imprimante muette » est rangé dans la section
Collecte SNMP, avec un lien vers la page « Installeur GLPI Agent » où vivent les réglages des sondes. Les textes
qui disaient « mise à jour manuelle » pour macOS ont disparu (service launchd depuis le fichier unique).
"""
import re
import sys

import config
import lib
from lib import WEB, constat, ok_ko, section

ONGLET = "/ajax/common.tabs.php?_target=%2Ffront%2Fconfig.form.php&_itemtype=Config&_glpi_tab=PluginPrintgestionConfig%241&id=1"
SECTIONS = ["modules", "contrats", "toner", "transport", "collecte", "maintenance"]


def main():
    lib.connecter_admin()
    section("1. Configuration > Print Gestion : sommaire et sections")
    statut, page, _ = WEB.get(ONGLET, ajax=True)
    ancres = [f"id='pg-sec-{s}'" in page for s in SECTIONS]
    liens = [f"href='#pg-sec-{s}'" in page for s in SECTIONS]
    constat("un sommaire, six sections dans l'ordre du menu", ok_ko(statut == 200 and "Sommaire" in page and all(ancres) and all(liens)),
            f"HTTP {statut}, ancres {ancres}, liens {liens}")
    positions = [page.find(f"id='pg-sec-{s}'") for s in SECTIONS]
    constat("les sections se suivent dans l'ordre du sommaire", ok_ko(positions == sorted(positions) and positions[0] >= 0), str(positions))
    muet = page.find("name='silent_days'")
    constat("le seuil « imprimante muette » est dans la section Collecte SNMP, avant Maintenance",
            ok_ko(muet > page.find("id='pg-sec-collecte'") > 0 and muet < page.find("id='pg-sec-maintenance'")), str(muet))
    constat("la section Collecte renvoie vers les réglages des sondes (page Installeur)",
            ok_ko("Réglages des sondes" in page and "agentdeploy.php" in page))
    constat("chaque carte de réglages est sous une section : la carte des alertes de contrat suit « Gestion contractuelle »",
            ok_ko(0 < page.find("id='pg-sec-contrats'") < page.find("Alertes GLPI de fin de contrat") < page.find("id='pg-sec-toner'")))

    section("2. Page Installeur : la carte des réglages des sondes")
    statut, page, _ = WEB.get(config.FRONT + "/agentdeploy.php")
    constat("carte « Réglages des sondes — transmis par le fichier d'installation »",
            ok_ko(statut == 200 and "Réglages des sondes" in page and "Paramètres transmis" not in page), f"HTTP {statut}")
    constat("plus de « mise à jour manuelle » pour macOS dans la page",
            ok_ko(not re.search(r"mise à jour manuelle|pas de mise à jour automatique", page)))
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
