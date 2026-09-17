"""BL : contrôle d'entité et d'existence (plugin Gestion simulé, tests/simulations/gestion).

Règle : un envoi n'accepte que les BL de son entité (figée à sa création) ou d'une entité parente, et seulement parmi les
entités du compte connecté. Jamais le BL d'une entité sœur, même si le compte voit les deux.
1. update_expedition (compte Client test A) : BL de Client test B et BL inexistant refusés avec le même message ;
   BL de la racine (hors du périmètre du compte) refusé ; BL de Client test A accepté.
2. link_bls (compte Client test A) : BL de Client test B refusé ; BL de Client test A lié ; « sage:<n°> » d'un BL
   présent chez Client test B refusé ; nouveau BL Sage préparé dans l'entité de l'expédition ; réponse ok fausse et rien de modifié dès qu'un BL est refusé.
3. expedition_bls : un lien vers le BL de Client test B posé en base n'est jamais relu.
4. Sous-entités : compte de Site test A1 face au BL de Site test A2 (sœur) et de Client test A (parent hors de son
   périmètre) ; compte de Client test A (voit A1 et A2) : BL de A2 refusé sur un envoi de A1, BL de A accepté.
5. Compte racine : BL de la racine accepté (BL de groupe).
6. Modale BL : textes en bloc JSON non exécuté.
"""
import json
import re
import sys

import config
import donnees as d
import lib
from lib import WEB, lignes, section, sql, valeur, verifier

CTX = lib.Contexte()
EXP = "glpi_plugin_printgestion_expeditions"
MESSAGE_REFUS = "BL introuvable ou rattaché à un autre client"


def etat(expedition):
    return lignes(f"SELECT statut, IFNULL(bl_surveys_id, 'NULL') FROM {EXP} WHERE id = {expedition}")[0]


def liens(expedition):
    return sorted(int(r[0]) for r in lignes(f"SELECT bl_surveys_id FROM glpi_plugin_printgestion_expedition_bls WHERE expeditions_id = {expedition}"))


def expedier(expedition, bl, suivi):
    WEB.post(config.AJAX + "/update_expedition.php", [("id", str(expedition)), ("action", "ship"), ("carrier", "ups"), ("tracking", suivi), ("bl_surveys_id", str(bl))])
    return etat(expedition)


def lier(expedition, bls):
    statut, page, _ = WEB.post(config.AJAX + "/link_bls.php", [("expedition_id", str(expedition)), ("bls", json.dumps(bls))], ajax=True)
    try:
        return statut, json.loads(page)
    except ValueError:
        return statut, {"brut": page[:200]}


def bls_lus(expedition):
    statut, page, _ = WEB.get(config.AJAX + "/expedition_bls.php", [("expedition_id", str(expedition))], ajax=True)
    return statut, sorted(int(item["id"]) for item in json.loads(page).get("bls", []))


def main():
    d.verifier_instance()
    try:
        lib.connecter_admin()
        bl_a, bl_b, bl_racine = CTX.bl("BLTSTA0001", d.CLIENT_A), CTX.bl("BLTSTB0001", d.CLIENT_B), CTX.bl("BLTSTR0001", d.RACINE)
        bl_a1, bl_a2 = CTX.bl("BLTSTA1001", d.SITE_A1), CTX.bl("BLTSTA2001", d.SITE_A2)
        ea, ea2, ea3 = CTX.expedition(d.IMP_A1, "test_lien"), CTX.expedition(d.IMP_A2, "test_envoi"), CTX.expedition(d.IMP_A2, "test_envoi2")
        es1, es1b, es1c = (CTX.expedition(d.IMP_SITE_A1, p) for p in ("test_site1", "test_site2", "test_site3"))
        droits = {"plugin_printgestion_expedition": 3, "plugin_printgestion_dashboard": 3}
        profil = CTX.profil(6, "Profil test BL (droits du plugin)", droits)
        CTX.utilisateur("test-client-a", profil, d.CLIENT_A)
        CTX.utilisateur("test-site-a1", profil, d.SITE_A1)
        print(f"BL : A #{bl_a}, B #{bl_b}, racine #{bl_racine}, A1 #{bl_a1}, A2 #{bl_a2} ; envois A {ea}, {ea2}, {ea3} ; site A1 {es1}, {es1b}, {es1c}")

        section("1. update_expedition (compte Client test A)")
        CTX.connecter("test-client-a")
        verifier("BL de Client test B : refusé, envoi resté en attente sans BL", expedier(ea2, bl_b, "SUIVI-B"), ["pending", "NULL"])
        verifier("BL inexistant : refusé de la même façon", expedier(ea2, 987654, "SUIVI-X"), ["pending", "NULL"])
        verifier("BL de la racine : hors du périmètre du compte client, refusé", expedier(ea2, bl_racine, "SUIVI-R"), ["pending", "NULL"])
        verifier("BL de Client test A : accepté", expedier(ea3, bl_a, "SUIVI-A"), ["shipped", str(bl_a)])

        section("2. link_bls (compte Client test A)")
        _, reponse = lier(ea, [str(bl_b)])
        verifier("identifiant d'un BL de Client test B : refusé et signalé (ok faux), aucun lien",
                 (liens(ea), reponse.get("ok"), [e.get("error") for e in reponse.get("errors", [])]), ([], False, [MESSAGE_REFUS]))
        _, reponse = lier(ea, [str(bl_a)])
        verifier("BL de Client test A : lié (ok vrai)", (liens(ea), reponse.get("ok")), ([bl_a], True))
        _, reponse = lier(ea, [str(bl_a), "sage:BLTSTB0001"])
        verifier("« sage:<n°> » d'un BL présent chez Client test B : refusé (ok faux), BL de Client test A gardé",
                 (liens(ea), reponse.get("ok"), len(reponse.get("errors", []))), ([bl_a], False, 1))
        _, reponse = lier(ea, [str(bl_b)])
        verifier("sélection réduite à un BL refusé : aucune liaison existante retirée, ok faux", (liens(ea), reponse.get("ok")), ([bl_a], False))
        _, reponse = lier(ea, ["sage:BLINCONNU01"])
        verifier("BL Sage inexistant : ok faux, erreur rendue, liaison existante gardée", (liens(ea), reponse.get("ok"), len(reponse.get("errors", []))), ([bl_a], False, 1))
        lier(ea, [str(bl_a), "sage:BLOKTST001"])
        nouveau = valeur("SELECT id FROM glpi_plugin_gestion_surveys WHERE bl = 'BLOKTST001' OR bl LIKE 'BLOKTST001%'")
        if nouveau:
            CTX.crees["bl"].append(int(nouveau))
        verifier("nouveau BL Sage : préparé dans l'entité de l'expédition et lié",
                 (valeur(f"SELECT entities_id FROM glpi_plugin_gestion_surveys WHERE id = {nouveau}") if nouveau else None, liens(ea)),
                 (str(d.CLIENT_A), sorted([bl_a, int(nouveau or 0)])))
        # Panne d'écriture simulée (déclencheur sur la base de test) : rien de modifié, ok faux, cause journalisée.
        bl_a_bis = CTX.bl("BLTSTA0002", d.CLIENT_A)
        avant = liens(ea)
        sql("DROP TRIGGER IF EXISTS pgtest_panne_liaison; CREATE TRIGGER pgtest_panne_liaison BEFORE INSERT ON glpi_plugin_printgestion_expedition_bls "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'panne simulee';")
        try:
            tailles = lib.tailles_journaux()
            _, reponse = lier(ea, [str(bl_a_bis)])
        finally:
            sql("DROP TRIGGER IF EXISTS pgtest_panne_liaison;")
        verifier("panne d'écriture pendant link_bls : ok faux, liaisons existantes intactes (transaction annulée), erreur journalisée",
                 (reponse.get("ok"), liens(ea), "Liaisons BL de l'expédition" in lib.journal_depuis(tailles, "printgestion.log")), (False, avant, True))

        section("3. expedition_bls (compte Client test A)")
        sql(f"INSERT INTO glpi_plugin_printgestion_expedition_bls (expeditions_id, bl_surveys_id, date_creation, entities_id, is_recursive) VALUES ({ea}, {bl_b}, NOW(), {d.CLIENT_A}, 0);")
        statut, lus = bls_lus(ea)
        verifier("lien en base vers le BL de Client test B : jamais relu", (statut, bl_b in lus, bl_a in lus), (200, False, True))

        section("4. Sous-entités : jamais le BL d'une entité sœur")
        CTX.connecter("test-site-a1")
        verifier("compte Site test A1 : BL de Site test A2 (sœur) refusé", expedier(es1, bl_a2, "SUIVI-SOEUR"), ["pending", "NULL"])
        verifier("compte Site test A1 : BL de Client test A (parent hors de son périmètre) refusé", expedier(es1, bl_a, "SUIVI-PARENT"), ["pending", "NULL"])
        _, reponse = lier(es1, [str(bl_a2)])
        verifier("compte Site test A1 : link_bls avec le BL de Site test A2 refusé", (liens(es1), [e.get("error") for e in reponse.get("errors", [])]), ([], [MESSAGE_REFUS]))
        sql(f"INSERT INTO glpi_plugin_printgestion_expedition_bls (expeditions_id, bl_surveys_id, date_creation, entities_id, is_recursive) VALUES ({es1}, {bl_a2}, NOW(), {d.SITE_A1}, 0);")
        statut, lus = bls_lus(es1)
        verifier("compte Site test A1 : lien en base vers le BL de Site test A2 jamais relu", (statut, bl_a2 in lus), (200, False))
        verifier("compte Site test A1 : BL de Site test A1 accepté", expedier(es1b, bl_a1, "SUIVI-SITE"), ["shipped", str(bl_a1)])
        CTX.connecter("test-client-a")
        verifier("compte Client test A (voit A1 et A2) : BL de Site test A2 refusé sur un envoi de Site test A1", expedier(es1c, bl_a2, "SUIVI-SOEUR-2"), ["pending", "NULL"])
        statut, lus = bls_lus(es1)
        verifier("compte Client test A : lien en base vers le BL sœur jamais relu non plus", (statut, bl_a2 in lus), (200, False))
        verifier("compte Client test A : BL de Client test A (parent) accepté sur un envoi de Site test A1", expedier(es1c, bl_a, "SUIVI-PARENT-2"), ["shipped", str(bl_a)])

        section("5. Compte racine")
        lib.connecter_admin()
        verifier("BL de la racine accepté (BL de groupe)", expedier(ea2, bl_racine, "SUIVI-RACINE"), ["shipped", str(bl_racine)])

        section("6. Modale BL : textes en bloc JSON")
        _, page, _ = WEB.get(config.FRONT + "/dashboard_expeditions.php")
        bloc = re.search(r'<script type="application/json" id="pc-linkbl-labels">(.*?)</script>', page, re.S)
        verifier("bloc pc-linkbl-labels présent, décodable, relu par JSON.parse",
                 (bloc is not None and "title" in json.loads(bloc.group(1)), "JSON.parse(document.getElementById('pc-linkbl-labels').textContent)" in page), (True, True))

        section("7. BL signé → « livrée » automatique : le verrou anti-doublon tient")
        lib.connecter_admin()
        ev = CTX.expedition(d.IMP_A1, "test_verrou", "SUIVI-VERROU", statut="shipped")
        sql(f"UPDATE glpi_plugin_printgestion_expeditions SET bl_surveys_id = {bl_a}, date_shipped = NOW() WHERE id = {ev};")
        sql(f"UPDATE glpi_plugin_gestion_surveys SET signed = 1 WHERE id = {bl_a};")
        lib.tache("PrintgestionTrackingUpdate")
        verifier("expédition passée en « livrée » par le BL signé (lien déduit du plugin Gestion actif, sans réglage)",
                 lib.valeur(f"SELECT statut FROM glpi_plugin_printgestion_expeditions WHERE id = {ev}"), "delivered")
        verrou = json.loads(lib.php_glpi(f"echo json_encode(PluginPrintgestionGuard::evaluate([['printers_id' => {d.IMP_A1}, 'property' => 'test_verrou']]));"))
        cle = f"{d.IMP_A1}|test_verrou"
        lib.constat("verrou anti-doublon toujours actif après « livrée » : envoi en cours, seule la pose le clôt",
                    lib.ok_ko(verrou.get(cle) is not None and "in_progress" in json.dumps(verrou.get(cle))), json.dumps(verrou.get(cle), ensure_ascii=False)[:160])
        sql(f"UPDATE glpi_plugin_gestion_surveys SET signed = 0 WHERE id = {bl_a};")
    finally:
        CTX.nettoyer()
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
