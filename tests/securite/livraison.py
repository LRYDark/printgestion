"""Livraison constatée : hiérarchie des preuves, double contrôle MBE + GLS, non-rétrogradation, retard signalé.

Une expédition ne passe « livrée » que si une source l'a constaté, jamais parce que le temps passe. Trois sources,
inégales : BL signé > événement de livraison GLS > statut MBE. Un seul endroit décide (Delivery), et une livraison
constatée ne se reprend jamais.

1. Fonctions pures, sans aucun appel réseau : numéro de BL tiré des notes MBE et son équivalent côté Sage, seuils de
   retard par transporteur, jours ouvrés.
2. Double contrôle : MBE reste « en attente de livraison » des jours après une remise. GLS l'emporte, DELIVEREDPS ne
   compte pas (le colis attend en point relais), et MBE seul décide pour un envoi UPS.
3. Delivery : ce qui peut passer « livrée » et ce qui ne peut pas (déjà livrée, posée, annulée, pas encore partie),
   la date retenue est celle de la source, chaque passage est journalisé avec sa source.
4. Retard de livraison : partie et non livrée au-delà du délai du transporteur (jours ouvrés) → « Livraison en
   retard » sur l'écran Expéditions ; constatée livrée → la ligne disparaît.
"""
import json
import sys

import config
import donnees as d
import lib
from lib import WEB, constat, ok_ko, section, sql, valeur, verifier

CTX = lib.Contexte()
EXP = "glpi_plugin_printgestion_expeditions"


def etat(expedition):
    return lib.lignes(f"SELECT statut, IFNULL(date_delivered, 'NULL') FROM {EXP} WHERE id = {expedition}")[0]


def jours_ouvres(depuis, jusqu):
    """Même règle que Delivery::businessDaysSince : les jours après celui du départ, week-ends exclus."""
    import datetime

    debut = datetime.datetime.strptime(depuis, "%Y-%m-%d %H:%M:%S")
    fin = datetime.datetime.strptime(jusqu, "%Y-%m-%d %H:%M:%S")
    jour = datetime.datetime.combine(debut.date(), datetime.time()) + datetime.timedelta(days=1)
    compte = 0
    while jour <= fin:
        if jour.isoweekday() <= 5:
            compte += 1
        jour += datetime.timedelta(days=1)
    return compte


def main():
    d.verifier_instance()
    try:
        section("1. Numéro de BL et délais : des fonctions pures, aucun appel réseau")
        lus = json.loads(lib.php_glpi(
            "echo json_encode(array_map([PluginPrintgestionMbeclient::class, 'parseBlNumber'], "
            "['Colis bl123456 urgent', 'BL 123456', 'Bl-123456', 'BL_123456', 'Facture 42, BL987', 'aucun numero', 'BLANC 12']));"
        ))
        verifier("numéro de BL tiré des notes MBE : toute casse, séparateur optionnel, et rien quand il n'y en a pas",
                 lus, ["BL123456", "BL123456", "BL123456", "BL123456", "BL987", "", ""])
        lus = json.loads(lib.php_glpi(
            "echo json_encode(array_map([PluginPrintgestionMbetracking::class, 'normalizeBl'], "
            "['123456', 'BL123456', 'bl 123456', 'BL-000123', '']));"
        ))
        verifier("numéro de BL de Sage ramené au même format que celui des notes MBE",
                 lus, ["BL123456", "BL123456", "BL123456", "BL000123", ""])
        constat("les deux formats se rencontrent : un BL Sage « 000123 » et une note MBE « BL-000123 » s'apparient",
                ok_ko(json.loads(lib.php_glpi(
                    "echo json_encode(PluginPrintgestionMbetracking::normalizeBl('000123') === PluginPrintgestionMbeclient::parseBlNumber('note BL-000123'));"
                )) is True))

        lus = json.loads(lib.php_glpi(
            "echo json_encode(array_map([PluginPrintgestionDelivery::class, 'delayThreshold'], ['gls', 'GLS', 'ups', 'chronopost', 'other', '']));"
        ))
        verifier("délai de retard par transporteur, en jours ouvrés (GLS 3, UPS 6, Chronopost 4, 4 par défaut)",
                 lus, [3, 3, 6, 4, 4, 4])

        paires = [("2026-09-04 09:00:00", "2026-09-11 09:00:00"), ("2026-09-18 09:00:00", "2026-09-21 09:00:00"),
                  ("2026-09-01 09:00:00", "2026-09-01 18:00:00"), ("2026-08-03 09:00:00", "2026-09-01 09:00:00")]
        appel = ", ".join(f"PluginPrintgestionDelivery::businessDaysSince('{a}', '{b}')" for a, b in paires)
        lus = json.loads(lib.php_glpi(f"echo json_encode([{appel}]);"))
        verifier("jours ouvrés comptés comme le fait le harnais (week-ends exclus), y compris sur un même jour et sur un mois",
                 lus, [jours_ouvres(a, b) for a, b in paires])

        section("2. Double contrôle : MBE seul ne suffit jamais, GLS l'emporte")
        cas = [
            ("['status' => 'in_transit', 'raw' => 'WAITING_DELIVERY'], 'gls', 'DELIVERED'",
             "MBE dit « en attente » mais GLS a livré : livrée, constatée par GLS", True, "gls"),
            ("['status' => 'in_transit', 'raw' => 'WAITING_DELIVERY'], 'gls', 'DELIVEREDPS'",
             "GLS a déposé en point relais : personne n'a le colis, rien n'est constaté", False, ""),
            ("['status' => 'in_transit', 'raw' => 'WAITING_DELIVERY'], 'gls', 'INTRANSIT'",
             "les deux disent « en transit » : rien n'est constaté", False, ""),
            ("['status' => 'delivered', 'raw' => 'DELIVERED', 'delivered_at' => '2026-09-18 12:00:00', 'delivered_to' => 'DUPONT'], 'ups', ''",
             "envoi UPS (aucune intégration transporteur) : MBE est la seule source, et elle suffit", True, "mbe"),
            ("['status' => 'delivered', 'raw' => 'DELIVERED'], 'gls', 'INTRANSIT'",
             "MBE a livré, GLS pas encore à jour : livrée quand même (jamais de rétrogradation)", True, "mbe"),
            ("['status' => 'unknown', 'raw' => 'NOT_AVAILABLE'], 'ups', ''",
             "statut MBE inconnu : jamais « livrée » par défaut", False, ""),
            ("['status' => 'exception', 'raw' => 'EXCEPTION'], 'gls', 'NOTDELIVERED'",
             "anomalie des deux côtés : rien n'est constaté, le retard sera signalé à part", False, ""),
            ("['status' => 'in_transit', 'raw' => 'WAITING_DELIVERY'], 'other', 'DELIVERED'",
             "un statut GLS mémorisé sur un envoi qui n'est pas GLS n'est pas lu", False, ""),
        ]
        appel = ", ".join(f"PluginPrintgestionMbetracking::reconcile({args})" for args, _, _, _ in cas)
        verdicts = json.loads(lib.php_glpi(f"echo json_encode([{appel}]);"))
        for verdict, (_, libelle, livree, source) in zip(verdicts, cas):
            constat(libelle, ok_ko(verdict["delivered"] is livree and verdict["source"] == source), json.dumps(verdict, ensure_ascii=False)[:110])

        section("3. Delivery : le seul endroit qui écrit « livrée », et jamais deux fois")
        lib.connecter_admin()
        entite = CTX.entite("Livraison test")
        imprimante = CTX.imprimante("IMP-LIVRAISON", entite)
        tailles = lib.tailles_journaux()

        envoi = CTX.expedition(imprimante, "tonerblack", statut="shipped")
        sql(f"UPDATE {EXP} SET date_shipped = DATE_SUB(NOW(), INTERVAL 10 DAY), transport_carrier = 'gls', transport_number = 'TESTGLS01' WHERE id = {envoi};")
        fait = lib.php_glpi(f"echo PluginPrintgestionDelivery::markDelivered({envoi}, 'gls', '2026-09-18 15:30:00', 'GLS : DELIVERED') ? '1' : '0';").strip()
        statut, date = etat(envoi)
        constat("expédition partie : constatée livrée par GLS, à la date donnée par la source (pas celle du passage)",
                ok_ko(fait == "1" and statut == "delivered" and date.startswith("2026-09-18 15:30")), f"{statut} / {date}")
        fait = lib.php_glpi(f"echo PluginPrintgestionDelivery::markDelivered({envoi}, 'bl', '2026-09-19 08:00:00', 'BL signé') ? '1' : '0';").strip()
        constat("une seconde source sur la même expédition ne rejoue rien : la date du premier constat est gardée",
                ok_ko(fait == "0" and etat(envoi) == [statut, date]))

        pose = CTX.expedition(imprimante, "tonercyan", statut="shipped")
        sql(f"UPDATE {EXP} SET statut = 'installed', date_shipped = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE id = {pose};")
        annule = CTX.expedition(imprimante, "tonermagenta", statut="shipped")
        sql(f"UPDATE {EXP} SET statut = 'cancelled' WHERE id = {annule};")
        attente = CTX.expedition(imprimante, "toneryellow", statut="pending")
        refus = json.loads(lib.php_glpi(
            "echo json_encode(["
            f"PluginPrintgestionDelivery::markDelivered({pose}, 'gls'),"
            f"PluginPrintgestionDelivery::markDelivered({annule}, 'mbe'),"
            f"PluginPrintgestionDelivery::markDelivered({attente}, 'mbe', null, 'MBE : DELIVERED'),"
            "]);"
        ))
        constat("posée, annulée, pas encore partie : aucune des trois ne passe « livrée »",
                ok_ko(refus == [False, False, False]
                      and [etat(pose)[0], etat(annule)[0], etat(attente)[0]] == ["installed", "cancelled", "pending"]),
                str(refus))
        journal = lib.journal_depuis(tailles, "printgestion.log")
        constat("chaque constat est journalisé avec sa source, et l'incohérence « livrée mais pas partie » est signalée",
                ok_ko("constaté par" in journal and "pas marquée partie" in journal),
                (journal or "journal vide").replace(chr(10), " | ")[:200])
        constat("aucune erreur PHP pendant la passe", ok_ko(lib.erreurs_php_depuis(tailles) == []),
                str(lib.erreurs_php_depuis(tailles))[:200])

        section("4. Livraison en retard : signalée, jamais conclue")
        retard = CTX.expedition(imprimante, "fuserkit", statut="shipped")
        sql(f"UPDATE {EXP} SET date_shipped = DATE_SUB(NOW(), INTERVAL 1 DAY), transport_carrier = 'gls' WHERE id = {retard};")
        _, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
        constat("partie d'hier, transporteur GLS (3 jours ouvrés) : aucun retard signalé",
                ok_ko("Livraison en retard" not in page))
        sql(f"UPDATE {EXP} SET date_shipped = DATE_SUB(NOW(), INTERVAL 15 DAY) WHERE id = {retard};")
        _, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
        constat("partie depuis quinze jours et toujours pas livrée : « Livraison en retard », avec le délai du transporteur",
                ok_ko("Livraison en retard" in page and "aucune livraison constatée" in page and "IMP-LIVRAISON" in page))
        constat("le retard n'a rien conclu : l'expédition est toujours « partie », jamais « livrée »",
                ok_ko(etat(retard)[0] == "shipped"))
        # Quinze jours dépassent aussi le délai de rappel d'installation : sans déduplication, la même expédition
        # apparaîtrait deux fois (« pose non constatée » et « livraison en retard »). Une seule ligne, la bonne.
        constat("une seule ligne pour cette expédition : la livraison en retard chasse le rappel de pose",
                ok_ko(page.count("IMP-LIVRAISON") == 1), f"{page.count('IMP-LIVRAISON')} occurrence(s)")
        lib.php_glpi(f"PluginPrintgestionDelivery::markDelivered({retard}, 'mbe', null, 'MBE : DELIVERED');")
        _, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
        constat("livraison constatée : la ligne de retard disparaît",
                ok_ko("Livraison en retard" not in page and etat(retard)[0] == "delivered"))
        section("5. Les requêtes, pour de vrai : BL signé et expéditions à apparier")
        # Ces requêtes ne se vérifient pas à la lecture : il faut les faire tourner. Les trois erreurs qui ont été
        # corrigées ici (alias de colonne, IN sur NULL, clause ON mal formée) échouaient toutes en silence.
        bl_direct = CTX.bl("BL900001", entite)
        bl_lie = CTX.bl("BL900002", entite)
        sql(f"UPDATE glpi_plugin_gestion_surveys SET signed = 1, doc_date = '2026-09-17 09:00:00' WHERE id = {bl_direct};")
        sql(f"UPDATE glpi_plugin_gestion_surveys SET signed = 1, doc_date = '2026-09-16 09:00:00' WHERE id = {bl_lie};")

        par_colonne = CTX.expedition(imprimante, "tonerblack2", statut="shipped")
        sql(f"UPDATE {EXP} SET bl_surveys_id = {bl_direct}, date_shipped = DATE_SUB(NOW(), INTERVAL 5 DAY) WHERE id = {par_colonne};")
        par_liaison = CTX.expedition(imprimante, "tonercyan2", statut="shipped")
        sql(f"UPDATE {EXP} SET date_shipped = DATE_SUB(NOW(), INTERVAL 5 DAY), transport_carrier = 'gls', transport_number = 'TESTGLS02' WHERE id = {par_liaison};")
        sql("INSERT INTO glpi_plugin_printgestion_expedition_bls (expeditions_id, bl_surveys_id, entities_id, is_recursive, date_creation) "
            f"SELECT {par_liaison}, {bl_lie}, entities_id, is_recursive, NOW() FROM {EXP} WHERE id = {par_liaison};")

        constate = lib.php_glpi("echo PluginPrintgestionTracking::onGestionBlSigned();").strip()
        constat("BL signé rattaché par la colonne héritée ET par la table de liaison : les deux expéditions passent « livrée », datées de la signature",
                ok_ko(constate == "2" and etat(par_colonne) == ["delivered", "2026-09-17 09:00:00"]
                      and etat(par_liaison) == ["delivered", "2026-09-16 09:00:00"]),
                f"{constate} — {etat(par_colonne)} / {etat(par_liaison)}")
        constat("rien à reprendre au passage suivant : les deux sont déjà constatées",
                ok_ko(lib.php_glpi("echo PluginPrintgestionTracking::onGestionBlSigned();").strip() == "0"))
        # Le passage en « livrée » part de la signature elle-même : le plugin Gestion appelle Print Gestion à ses
        # trois points de signature. Ces appels vivent chez lui, donc une mise à jour de Gestion peut les emporter —
        # ce contrôle est le rappel de les remettre, au lieu de laisser la perte se découvrir des semaines plus tard.
        # Perdus, rien ne casse : les écrans et la tâche horaire rattrapent, mais l'immédiateté disparaît.
        appels = lib.php_glpi(
            "$n = 0; foreach (glob(GLPI_ROOT . '/plugins/gestion/front/*.php') ?: [] as $f) "
            "{ $n += substr_count((string) file_get_contents($f), 'PluginPrintgestionTracking::onGestionBlSigned'); } "
            "echo $n;"
        ).strip()
        constat("les trois appels de Print Gestion sont toujours en place dans le plugin Gestion (à remettre après chaque mise à jour de Gestion)",
                ok_ko(appels == "3"), f"{appels} appel(s) trouvé(s), 3 attendus")

        # Appariement MBE : les expéditions parties sans référence MBE, avec leurs numéros de BL. La colonne
        # mbe_master_tracking est NULL sur toute expédition jamais rapprochée — c'est le cas que le IN ratait.
        a_apparier = CTX.expedition(imprimante, "toneryellow2", statut="shipped")
        sql(f"UPDATE {EXP} SET date_shipped = DATE_SUB(NOW(), INTERVAL 3 DAY), transport_number = 'ZZ999TEST', mbe_master_tracking = NULL WHERE id = {a_apparier};")
        bl_attente = CTX.bl("BL900003", entite)
        sql("INSERT INTO glpi_plugin_printgestion_expedition_bls (expeditions_id, bl_surveys_id, entities_id, is_recursive, date_creation) "
            f"SELECT {a_apparier}, {bl_attente}, entities_id, is_recursive, NOW() FROM {EXP} WHERE id = {a_apparier};")
        attente = json.loads(lib.php_glpi(
            "echo json_encode(array_values(array_filter(PluginPrintgestionMbetracking::pendingExpeditions(date('Y-m-d H:i:s')), "
            f"static fn(array $e): bool => $e['id'] === {a_apparier})));"
        ))
        constat("expédition sans référence MBE (colonne NULL) retrouvée, avec son numéro de BL et son numéro transporteur",
                ok_ko(len(attente) == 1 and attente[0]["bl_numbers"] == ["BL900003"] and attente[0]["courier"] == "ZZ999TEST"),
                json.dumps(attente, ensure_ascii=False)[:160])
        sql(f"UPDATE {EXP} SET mbe_master_tracking = 'FR0000-00-0000000001' WHERE id = {a_apparier};")
        attente = json.loads(lib.php_glpi(
            "echo json_encode(array_values(array_filter(PluginPrintgestionMbetracking::pendingExpeditions(date('Y-m-d H:i:s')), "
            f"static fn(array $e): bool => $e['id'] === {a_apparier})));"
        ))
        constat("référence MBE écrite : l'expédition n'est plus à apparier, la fenêtre ne sera plus balayée pour elle",
                ok_ko(attente == []))
        constat("aucune erreur PHP sur ces requêtes", ok_ko(lib.erreurs_php_depuis(tailles) == []),
                str(lib.erreurs_php_depuis(tailles))[:250])
    finally:
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
