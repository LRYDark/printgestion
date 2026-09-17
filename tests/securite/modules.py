"""Interrupteurs de modules : un module désactivé ferme tous ses points d'entrée, et l'interrupteur est fermé par défaut.

Chaque module est désactivé à son tour (colonne enable_* de la configuration du plugin), puis chacun de ses points
d'entrée est demandé avec le compte administrateur : réponse 404 attendue (page ou JSON), jamais un écran ni une
action. Un nom de module inconnu vaut « désactivé » (fermé par défaut), jamais « activé ».
"""
import json
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql

MODULES = {
    "contrats": ["front/dashboard.php", "front/list.php", "front/print.form.php", "front/contractrate.form.php"],
    "toner": ["front/dashboard_alerts.php", "front/dashboard_expeditions.php", "front/demande.php", "front/demande.form.php",
              "front/demande.export.php", "front/expedition.form.php", "front/purchaseorder.form.php", "front/cartridgesnmp.form.php",
              "ajax/edit_expedition.php", "ajax/expedition_bls.php", "ajax/link_bls.php", "ajax/reassign_expedition.php",
              "ajax/resolve_alert.php", "ajax/search_bls.php", "ajax/update_expedition.php", "ajax/printer_thresholds.php"],
    "cout": ["front/dashboard_billing.php", "ajax/printer_costs.php", "ajax/export_excel.php"],
    "deploiement": ["front/agentdeploy.php", "front/agentdeploy.download.php", "front/collect.php", "front/collectfrequency.php",
                    "front/raccordement.php", "front/sonde.consigne.php", "front/sondes.php"],
    "sage": ["front/sage.form.php", "front/sageimport.php"],
}
TABLE = "glpi_plugin_printgestion_configs"


def ferme(chemin):
    ajax = chemin.startswith("ajax/")
    statut, page, _ = WEB.get(config.PLUGIN + "/" + chemin, ajax=ajax)
    if statut == 404:
        return True, f"{statut}"
    return False, f"HTTP {statut}, {len(page)} octets"


def main():
    d.verifier_instance()
    section("1. Chaque module désactivé ferme tous ses points d'entrée")
    lib.connecter_admin()
    try:
        for module, chemins in MODULES.items():
            sql(f"UPDATE {TABLE} SET enable_{module} = 0 WHERE id = 1;")
            fermes = [ferme(c) for c in chemins]
            constat(f"module « {module} » désactivé : {len(chemins)} points d'entrée en 404",
                    ok_ko(all(ok for ok, _ in fermes)), " ; ".join(f"{c} → {det}" for c, (ok, det) in zip(chemins, fermes) if not ok))
            sql(f"UPDATE {TABLE} SET enable_{module} = 1 WHERE id = 1;")
        sql(f"UPDATE {TABLE} SET enable_toner = 0, enable_cout = 0 WHERE id = 1;")
        ok, det = ferme("ajax/refresh_cache.php")
        constat("recalcul des caches : 404 seulement quand alertes toner ET coût à la page sont désactivés", ok_ko(ok), det)
        sql(f"UPDATE {TABLE} SET enable_toner = 1, enable_cout = 1 WHERE id = 1;")

        section("2. Fermé par défaut")
        sortie = lib.php_glpi("echo json_encode([PluginPrintgestionConfig::isFeatureEnabled('inexistant'), PluginPrintgestionConfig::isFeatureEnabled('toner')]);")
        inconnu, toner = json.loads(sortie)
        constat("nom de module inconnu (colonne absente) : désactivé, jamais activé en silence", ok_ko(inconnu is False and toner is True), sortie.strip())
        ok, det = ferme("front/dashboard_alerts.php")
        constat("modules rétablis : les écrans répondent de nouveau", ok_ko(not ok), det)
    finally:
        sql(f"UPDATE {TABLE} SET enable_contrats = 1, enable_toner = 1, enable_cout = 1, enable_deploiement = 1, enable_sage = 1 WHERE id = 1;")
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
