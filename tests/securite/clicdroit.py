"""Menu clic droit par-dessus les tableaux natifs (alertes toner, demandes d'envoi, expéditions, facturation).

Le menu ne rend que ce que l'utilisateur a le droit de faire (une entrée absente, pas cachée) ; le contexte des
lignes (ajax/rowcontext.php) ne renvoie que les lignes du périmètre de l'utilisateur, et rien sans session. Les
entrées « natives » reprennent les actions de masse de GLPI : l'annulation d'une demande depuis la liste passe par
la même méthode que la fiche (motif obligatoire, statuts ouverts seulement). L'écran des expéditions n'embarque
plus la carte de toutes les expéditions dans la page.
"""
import json
import re
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql, valeur

CTX = lib.Contexte()


def configuration(page):
    """Bloc de données du menu : type de tableau et clés des entrées rendues."""
    bloc = re.search(r'<script type="application/json" id="pg-ctx-config">(.*?)</script>', page, re.S)
    if not bloc:
        return None, []
    cfg = json.loads(bloc.group(1))
    return cfg.get("itemtype"), [i["key"] for i in cfg.get("items", [])]


def contexte(itemtype, ids):
    statut, page, _ = WEB.get(config.AJAX + f"/rowcontext.php?itemtype={itemtype}&ids=" + ",".join(str(i) for i in ids), ajax=True)
    try:
        return statut, json.loads(page)
    except ValueError:
        return statut, {}


def main():
    lib.connecter_admin()
    try:
        # Alertes calculées à l'instant : une ligne d'un client, pour le contexte et le cloisonnement.
        WEB.post(config.FRONT + "/dashboard_alerts.php", [("recompute_alerts", "1")])
        ligne = lib.lignes("SELECT id, printers_id, entities_id FROM glpi_plugin_printgestion_alertview "
                           f"WHERE entities_id <> {d.CLIENT_B} ORDER BY status = 'ok', id LIMIT 1")
        alerte, imprimante = (int(ligne[0][0]), int(ligne[0][1])) if ligne else (0, 0)
        exp = CTX.expedition(d.IMP_A1, "test_clicdroit", "SUIVI-CLIC", statut="shipped")

        section("1. Chaque écran porte le menu, avec ses seules entrées")
        attendus = {
            "/dashboard_alerts.php": ("PluginPrintgestionAlertview",
                                      {"order", "snooze", "unsnooze", "open-printer", "open-cartridge", "open-expedition", "edit-expedition"}),
            "/demande.php": ("PluginPrintgestionDemande", {"open-demande", "validate", "cancel"}),
            "/dashboard_expeditions.php": ("PluginPrintgestionExpedition", {"open-expedition", "edit-expedition", "open-printer", "open-demande"}),
            "/dashboard_billing.php": ("PluginPrintgestionBillingview", {"open-printer"}),
        }
        for chemin, (type_attendu, cles_attendues) in attendus.items():
            statut, page, _ = WEB.get(config.FRONT + chemin)
            itemtype, cles = configuration(page)
            # « link-bl » dépend de l'état du plugin Gestion : ni exigée ni interdite.
            constat(f"{chemin} : menu rendu pour {type_attendu} avec {sorted(cles_attendues)}",
                    ok_ko(statut == 200 and "id='pc-ctx-menu'" in page and itemtype == type_attendu and set(cles) - {"link-bl"} == cles_attendues),
                    f"HTTP {statut}, type {itemtype}, entrées {cles}")
        _, page, _ = WEB.get(config.FRONT + "/dashboard_alerts.php")
        constat("alertes : la fenêtre « Modifier expédition » est disponible pour l'expédition en cours",
                ok_ko('id="pc-modal-editexp"' in page))
        _, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
        constat("expéditions : plus de carte de toutes les expéditions ni de pont dans la page",
                ok_ko("PG_EXP_DATA" not in page and "pg-exp-bridge" not in page and "pg-ctx-row-marker" in page))

        section("2. Contexte des lignes : périmètre et droits")
        if alerte:
            statut, reponse = contexte("PluginPrintgestionAlertview", [alerte])
            ligne_ctx = reponse.get("rows", {}).get(str(alerte), {})
            constat("administrateur : contexte d'une alerte (fiche imprimante, ce qui est possible)",
                    ok_ko(statut == 200 and reponse.get("ok") is True and ligne_ctx.get("printer_url", "").endswith(f"printer.form.php?id={imprimante}")
                          and all(k in ligne_ctx for k in ("can_order", "can_snooze", "can_unsnooze", "has_expedition", "has_cartridge"))),
                    f"HTTP {statut}, {str(ligne_ctx)[:200]}")
        else:
            constat("administrateur : contexte d'une alerte", "NON CONCLUANT", "aucune alerte calculée")
        statut, reponse = contexte("PluginPrintgestionExpedition", [exp])
        ligne_ctx = reponse.get("rows", {}).get(str(exp), {})
        constat("administrateur : contexte d'une expédition (fiche, statut, transporteur)",
                ok_ko(statut == 200 and ligne_ctx.get("expedition_url", "").endswith(f"expedition.form.php?id={exp}")
                      and ligne_ctx.get("exp_statut") == "shipped" and ligne_ctx.get("printers_id") == d.IMP_A1),
                f"HTTP {statut}, {str(ligne_ctx)[:200]}")
        statut, page, _ = WEB.get(config.AJAX + "/rowcontext.php?itemtype=Computer&ids=1", ajax=True)
        constat("type inconnu : refusé", ok_ko(lib.refus(statut, page)), f"HTTP {statut}")
        statut, page, _ = lib.Session().brut("GET", config.AJAX + f"/rowcontext.php?itemtype=PluginPrintgestionExpedition&ids={exp}")
        constat("sans session : rien", ok_ko(statut in (302, 401, 403) and "expedition.form.php" not in page), f"HTTP {statut}")

        droits = {"plugin_printgestion_dashboard": 1, "plugin_printgestion_expedition": 1}
        profil = CTX.profil(6, "Profil test clic droit (lecture, client B)", droits)
        CTX.utilisateur("test-clicdroit-b", profil, d.CLIENT_B)
        CTX.connecter("test-clicdroit-b")
        statut, reponse = contexte("PluginPrintgestionExpedition", [exp])
        constat("utilisateur du client B : l'expédition du client A n'existe pas pour lui",
                ok_ko(statut == 200 and reponse.get("ok") is True and reponse.get("rows") in ({}, [])), f"HTTP {statut}, {str(reponse)[:120]}")
        if alerte:
            statut, reponse = contexte("PluginPrintgestionAlertview", [alerte])
            constat("utilisateur du client B : l'alerte d'un autre client n'existe pas pour lui",
                    ok_ko(statut == 200 and reponse.get("rows") in ({}, [])), f"HTTP {statut}, {str(reponse)[:120]}")
        _, page, _ = WEB.get(config.FRONT + "/dashboard_alerts.php")
        itemtype, cles = configuration(page)
        constat("lecture seule : ni Commander, ni suspension, ni modification d'expédition dans son menu",
                ok_ko(itemtype == "PluginPrintgestionAlertview" and not ({"order", "snooze", "unsnooze", "edit-expedition", "link-bl"} & set(cles))),
                f"entrées {cles}")
        lib.connecter_admin()

        section("3. « Annuler… » depuis la liste : la même règle que la fiche")
        sql("INSERT INTO glpi_plugin_printgestion_demandes (name, entities_id, locations_id, statut, delivery_mode, date_creation, date_mod) "
            f"VALUES ('Demande test clic droit', {d.CLIENT_A}, 0, 'proposed', 'direct', NOW(), NOW());")
        demande = int(valeur("SELECT MAX(id) FROM glpi_plugin_printgestion_demandes WHERE name = 'Demande test clic droit'"))
        CTX.crees["demandes"].append(demande)
        sql("INSERT INTO glpi_plugin_printgestion_demandelines (plugin_printgestion_demandes_id, printers_id, toner_property, cartridgeitems_id, quantity, "
            f"is_under_contract, contracts_id, level_at_proposal, statut, date_creation, date_mod, entities_id) VALUES ({demande}, {d.IMP_A2}, 'tonerblack', "
            f"{d.CARTOUCHE_NOIR}, 1, 1, {d.CONTRAT_A}, 35, 'proposed', NOW(), NOW(), {d.CLIENT_A});")
        statut_de = lambda: valeur(f"SELECT statut FROM glpi_plugin_printgestion_demandes WHERE id = {demande}")  # noqa: E731
        WEB.action_de_masse("PluginPrintgestionDemande", [demande], "pg_cancel", "PluginPrintgestionDemande")
        constat("sans motif : la demande reste proposée", ok_ko(statut_de() == "proposed"), statut_de())
        WEB.action_de_masse("PluginPrintgestionDemande", [demande], "pg_cancel", "PluginPrintgestionDemande", [("cancel_reason", "Test clic droit")])
        motif = valeur(f"SELECT cancel_reason FROM glpi_plugin_printgestion_demandes WHERE id = {demande}")
        ligne_statut = valeur(f"SELECT statut FROM glpi_plugin_printgestion_demandelines WHERE plugin_printgestion_demandes_id = {demande}")
        constat("avec motif : demande et ligne annulées, motif gardé",
                ok_ko(statut_de() == "cancelled" and ligne_statut == "cancelled" and motif == "Test clic droit"), f"{statut_de()}, ligne {ligne_statut}, motif {motif}")
        statut, reponse = contexte("PluginPrintgestionDemande", [demande])
        ligne_ctx = reponse.get("rows", {}).get(str(demande), {})
        constat("contexte d'une demande annulée : ni Valider ni Annuler possibles, la fiche reste ouvrable",
                ok_ko(ligne_ctx.get("can_validate") is False and ligne_ctx.get("can_cancel") is False and ligne_ctx.get("demande_url", "").endswith(f"demande.form.php?id={demande}")),
                str(ligne_ctx)[:200])
    finally:
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
