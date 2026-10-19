"""Vérification par les pages de suivi publiques d'UPS et de GLS France : en plus des autres sources, sans rien remplacer.

Aucun appel réseau : les réponses d'UPS et de GLS sont SIMULÉES, inventées, à la forme relevée le 19/10/2026 sur des
envois réels (jamais recopiés ici). Numéros inventés : UPS « 1ZTEST… » à la clé de contrôle juste (ou fausse, exprès),
GLS « 00TST… ».

1. Fonctions pures : clé de contrôle UPS ; clés GLS essayées (clé validée par l'API, saisie nettoyée, Track ID à 8).
2. Lecture des réponses : UPS remis, déposé en Access Point, retiré en Access Point, retour, anomalie, en cours,
   inconnu, 402 ; GLS remis (type LIV), remis par le seul statut, point relais sur le colis ou sur l'événement, en
   cours, anomalie, date nulle de GLS (0001-01-01) ; dates rendues à l'heure du serveur.
3. Client, transport simulé : une session UPS pour plusieurs numéros, jeton tiré du cookie et renvoyé en en-tête ;
   session expirée rouverte une fois ; page « Access Denied » → arrêt (quota) sans nouvelle tentative ; amorce sans
   jeton → panne ; numéro mal formé → aucun appel ; GLS 404 → inconnu, 503 → panne ; le jeton n'apparaît dans aucun
   message.
4. Passage de la tâche, transporteurs simulés : remise UPS et GLS (repli 10 → 8) constatées « livrée », datées de
   l'événement, journalisées avec leur source ; point relais, Access Point et clé fausse laissés « partie » ; en
   attente, autre transporteur, départ de plus de 30 jours jamais interrogés ; colonnes tracking_* jamais écrites ;
   second passage dans l'heure sans appel ; interrupteur coupé → aucun appel.
Expéditions de test supprimées ; mémo, date de passage et interrupteur remis comme avant.
"""
import json
import sys

import donnees as d
import lib
from lib import constat, ok_ko, section, sql, verifier

CTX = lib.Contexte()
EXP = "glpi_plugin_printgestion_expeditions"

# Clé de contrôle juste (calculée comme UPS), sauf la dernière.
UPS_LIVRE, UPS_RELAIS, UPS_RETIRE, UPS_RETOUR, UPS_ANOMALIE, UPS_ROUTE = (
    "1ZTEST000000000005", "1ZTEST000000000014", "1ZTEST000000000023", "1ZTEST000000000032", "1ZTEST000000000041", "1ZTEST000000000050")
UPS_CLE_FAUSSE = "1ZTEST000000000000"


def j(valeur):
    """Valeur Python passée à PHP par JSON."""
    return "json_decode(" + json.dumps(json.dumps(valeur)) + ", true)"


def php(code):
    return json.loads(lib.php_glpi(code))


def ups(numero, type_="D", livre=True, uap=False, retire=False, retour=False, statut="Delivered"):
    """Réponse UPS inventée, à la forme relevée : trackDetails[0] et les champs que le plugin lit."""
    activites = [{"gmtDate": "20260915", "gmtTime": "08:00:33", "actCode": "OT",
                  "milestoneName": {"name": "Out for Delivery", "nameKey": "cms.stapp.outForDelivery"}}]
    if livre:
        activites.insert(0, {"gmtDate": "20260915", "gmtTime": "08:32:35", "actCode": "KB",
                             "milestoneName": {"name": "Delivered", "nameKey": "cms.stapp.delivered"}})
    return {"statusCode": "200", "statusText": "Successful", "trackDetails": [{
        "trackingNumber": numero, "requestedTrackingNumber": numero, "errorCode": None, "errorText": None,
        "packageStatus": statut, "packageStatusType": type_, "packageStatusCode": "011" if livre else "005",
        "isDelivered": livre, "isDeliveredToUAP": uap, "isPickedUpByCustomer": retire,
        "receivedBy": "ACCUEIL TEST" if livre else "",
        "shipToAddressLblKey": "cms.stapp.returnedTo" if retour else "cms.stapp.deliveredTo",
        "shipToAddress": {"isReturnAddress": retour},
        "currentMilestone": {"name": statut, "nameKey": "cms.stapp.delivered" if livre else "cms.stapp.inTransit",
                             "date": "15/09/2026", "time": "10:32"},
        "shipmentGMTInfo": {"shipToGMTOffset": "+02:00"},
        "shipmentProgressActivities": activites,
    }]}


REMISE = (("CON", "CON", "2026-09-14 15:49:13.0", ""), ("REC", "REC", "2026-09-15 06:09:47.0", "0"),
          ("LIV", "LTV", "2026-09-15 09:06:17.0", "0"))


def gls(code, statut="LTV", evenements=REMISE, relais="0"):
    """Réponse GLS France inventée, à la forme relevée : colis et evenements[]."""
    return {"colis": {"trackid": code, "statutColis": statut, "relaisGlsColis": relais,
                      "nomSignataireColis": "SIGNATAIRE TEST", "dateActionColis": "0001-01-01 00:00:00.0"},
            "evenements": [{"typeEvenement": t, "statutEvenement": s, "datereference": dt, "datecreation": dt,
                            "relaisglsEvenement": r, "nomSignataire": "SIGNATAIRE TEST" if t == "LIV" else ""}
                           for t, s, dt, r in evenements]}


def heure_serveur(date, zone):
    """Date donnée dans un fuseau, rendue à l'heure du serveur GLPI (le calcul même du plugin, fait à part)."""
    return php(f"echo json_encode((new DateTime('{date}', new DateTimeZone('{zone}')))"
               "->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'));")


# Transport simulé, commun aux sections 3 et 4 : amorce UPS (cookies), suivi UPS par numéro, GLS par code.
TRANSPORT = (
    "$journal = [];"
    "$transport = function (string $method, string $url, array $headers, ?string $body) use (&$plan, &$journal): array {"
    "  $lookup = str_contains($url, 'GetLookupData'); $status = str_contains($url, 'GetStatus');"
    "  $numero = $body !== null ? (string) (json_decode($body, true)['TrackingNumber'][0] ?? '') : basename((string) parse_url($url, PHP_URL_PATH));"
    "  $journal[] = ['appel' => $lookup ? 'amorce' : ($status ? 'ups' : 'gls'), 'numero' => $lookup ? '' : $numero,"
    "                'xsrf' => $headers['X-XSRF-TOKEN'] ?? '', 'cookie' => $headers['Cookie'] ?? '', 'ua' => $headers['User-Agent'] ?? ''];"
    "  if ($lookup) { $r = isset($plan['amorces']) ? array_shift($plan['amorces']) : $plan['amorce']; }"
    "  elseif ($status) { $r = isset($plan['suivis']) ? array_shift($plan['suivis']) : ($plan['ups'][$numero] ?? ['status' => 200, 'body' => json_encode(['statusCode' => '200', 'trackDetails' => [['trackingNumber' => null, 'errorCode' => '504', 'errorText' => 'Tracking number not found in database']]])]); }"
    "  else { $r = $plan['gls'][$numero] ?? ['status' => 404, 'body' => 'No command found for code: ' . $numero]; }"
    "  if ($r === 'COUPURE') { throw new RuntimeException('coupure reseau'); }"
    "  return $r;"
    "};"
)
AMORCE = {"status": 200, "body": "{}", "headers": {"Set-Cookie": ["X-XSRF-TOKEN-ST=jeton-test%2Fsession; path=/; secure",
                                                                 "bm_test=valeur-test; path=/"]}}
REFUS = {"status": 403, "body": "<HTML><HEAD><TITLE>Access Denied</TITLE></HEAD><BODY>Access Denied (test)</BODY></HTML>"}


def reponse(contenu):
    return {"status": 200, "body": json.dumps(contenu)}


def tenter(nom, expression):
    """Fragment PHP : résultat de l'expression, ou ['kind', 'message'] de l'exception transporteur."""
    return (f"try {{ $out['{nom}'] = {expression}; }} catch (PluginPrintgestionCarrierexception $e) "
            f"{{ $out['{nom}'] = ['kind' => $e->getKind(), 'message' => $e->getMessage()]; }}")


def client(plan, appels):
    """Appels au client avec le transport simulé ; rend résultats, journal des appels et requêtes comptées."""
    return php("$plan = " + j(plan) + ";" + TRANSPORT
               + "$c = new PluginPrintgestionPublicclient($transport); $out = [];" + appels
               + "$out['journal'] = $journal; $out['requetes'] = $c->getRequestCount(); echo json_encode($out, JSON_UNESCAPED_UNICODE);")


def etat(expedition):
    return lib.lignes(f"SELECT statut, IFNULL(date_delivered, 'NULL'), IFNULL(tracking_status, 'NULL'), tracking_state FROM {EXP} WHERE id = {expedition}")[0]


def main():
    d.verifier_instance()
    configs_avant = lib.lignes("SELECT name, value FROM glpi_configs WHERE context = 'plugin:printgestion' AND name LIKE 'public\\_%'")
    try:
        section("1. Fonctions pures, aucun appel réseau")
        numeros = [UPS_LIVRE, UPS_RELAIS, UPS_ROUTE, UPS_CLE_FAUSSE, "1ZTEST00000000000", " 1ztest 0000-0000-0000-5 "]
        verifier("clé de contrôle UPS : justes acceptées, fausse ou longueur fausse refusées, saisie nettoyée d'abord",
                 php("echo json_encode(array_map(fn($n) => PluginPrintgestionPublicclient::isUpsNumber(PluginPrintgestionPublicclient::normalizeUpsNumber($n)), "
                     + j(numeros) + "));"),
                 [True, True, True, False, False, True])
        cas = [("00TSTA1XAA", ""), ("00TSTA1XAA", "00TSTA1X"), (" 00-tstc.3z ", ""),
               ("https://suivi.exemple.invalid/colis?match=00TSTB2YBB", ""), ("", ""), ("??", "")]
        verifier("clés GLS : clé validée par l'API d'abord, puis la saisie nettoyée, puis son Track ID à 8 ; doublons et formes impossibles écartés",
                 php("echo json_encode(array_map(fn($c) => PluginPrintgestionPublictracking::glsKeys($c[0], $c[1]), " + j(cas) + "));"),
                 [["00TSTA1XAA", "00TSTA1X"], ["00TSTA1X", "00TSTA1XAA"], ["00TSTC3Z"], ["00TSTB2YBB", "00TSTB2Y"], [], []])

        section("2. Lecture des réponses (inventées, à la forme relevée)")
        cas_ups = [
            ("remis au destinataire", UPS_LIVRE, ups(UPS_LIVRE), "delivered"),
            ("déposé en UPS Access Point, pas encore retiré : pas livré", UPS_RELAIS, ups(UPS_RELAIS, uap=True), "relay"),
            ("retiré en UPS Access Point par le destinataire : livré", UPS_RETIRE, ups(UPS_RETIRE, uap=True, retire=True), "delivered"),
            ("retour à l'expéditeur, même « livré » : jamais une livraison", UPS_RETOUR, ups(UPS_RETOUR, retour=True), "returned"),
            ("anomalie (type X)", UPS_ANOMALIE, ups(UPS_ANOMALIE, type_="X", livre=False, statut="Exception"), "exception"),
            ("en route (type I)", UPS_ROUTE, ups(UPS_ROUTE, type_="I", livre=False, statut="On the Way"), "transit"),
            ("numéro inconnu d'UPS (errorCode 504, sans numéro)", UPS_LIVRE,
             {"statusCode": "200", "trackDetails": [{"trackingNumber": None, "errorCode": "504", "errorText": "Tracking number not found in database"}]}, "not_found"),
        ]
        lus = php("$out = []; foreach (" + j([[n, r] for _, n, r, _ in cas_ups]) + " as [$n, $r]) { $out[] = PluginPrintgestionPublicclient::parseUps($r, $n); }"
                  " echo json_encode($out, JSON_UNESCAPED_UNICODE);")
        for verdict, (libelle, _, _, attendu) in zip(lus, cas_ups):
            constat(f"UPS — {libelle}", ok_ko(verdict["status"] == attendu), json.dumps(verdict, ensure_ascii=False)[:150])
        heure_ups = heure_serveur("2026-09-15 08:32:35", "UTC")
        constat("UPS — date de remise : l'activité « Delivered » en UTC, rendue à l'heure du serveur ; signataire dans le détail",
                ok_ko(lus[0]["date"] == heure_ups and "ACCUEIL TEST" in lus[0]["detail"]), f"{lus[0]['date']} / attendu {heure_ups}")
        constat("UPS — numéro inconnu : le code d'erreur d'UPS est gardé", ok_ko(lus[-1]["raw"] == "UPS:504"), lus[-1]["raw"])
        refus = php("try { PluginPrintgestionPublicclient::parseUps(['statusCode' => '402', 'statusText' => 'Invalid Request'], '" + UPS_LIVRE + "'); echo json_encode('aucune'); }"
                    " catch (PluginPrintgestionCarrierexception $e) { echo json_encode($e->getKind()); }")
        constat("UPS — statusCode 402 sur un numéro bien formé : la page a changé, échec technique (« request »), jamais un verdict",
                ok_ko(refus == "request"), refus)

        relais_evenement = (("CON", "CON", "2026-09-14 15:49:13.0", ""), ("LIV", "LTV", "2026-09-15 09:06:17.0", "9876"))
        cas_gls = [
            ("remis au destinataire (événement LIV, statut LTV)", gls("00TSTA1X"), "delivered"),
            ("remis, lu sur le seul statut d'un événement (INF / LIV)",
             gls("00TSTA1X", statut="LIV", evenements=(("CON", "CON", "2026-09-14 15:21:57.0", ""), ("INF", "LIV", "2026-09-15 11:57:00.0", "0"))), "delivered"),
            ("point relais sur le colis : pas livré", gls("00TSTC3Z", relais="1234"), "relay"),
            ("point relais sur l'événement de remise : pas livré", gls("00TSTC3Z", evenements=relais_evenement), "relay"),
            ("en tournée (TRV)", gls("00TSTD4V", statut="TRV", evenements=REMISE[:2] + (("TRV", "TRV", "2026-09-15 07:16:33.0", "0"),)), "transit"),
            ("anomalie (PBA)", gls("00TSTD4V", statut="PBA", evenements=REMISE[:2]), "exception"),
            ("remis, date nulle de GLS : pas de date inventée",
             gls("00TSTA1X", evenements=(("LIV", "LTV", "0001-01-01 00:00:00.0", "0"),)), "delivered"),
        ]
        lus = php("$out = []; foreach (" + j([r for _, r, _ in cas_gls]) + " as $r) { $out[] = PluginPrintgestionPublicclient::parseGls($r, 'X'); }"
                  " echo json_encode($out, JSON_UNESCAPED_UNICODE);")
        for verdict, (libelle, _, attendu) in zip(lus, cas_gls):
            constat(f"GLS — {libelle}", ok_ko(verdict["status"] == attendu), json.dumps(verdict, ensure_ascii=False)[:150])
        heure_gls = heure_serveur("2026-09-15 09:06:17", "Europe/Paris")
        constat("GLS — date de remise : heure de Paris de l'événement LIV, rendue à l'heure du serveur ; signataire dans le détail",
                ok_ko(lus[0]["date"] == heure_gls and "SIGNATAIRE TEST" in lus[0]["detail"]), f"{lus[0]['date']} / attendu {heure_gls}")
        constat("GLS — date nulle (0001-01-01) : aucune date rendue, Delivery datera du passage",
                ok_ko(lus[-1]["date"] is None), str(lus[-1]["date"]))

        section("3. Client, transport simulé")
        r = client({"amorce": AMORCE, "ups": {UPS_LIVRE: reponse(ups(UPS_LIVRE)), UPS_RELAIS: reponse(ups(UPS_RELAIS, uap=True))}},
                   tenter("a", f"$c->trackUps('{UPS_LIVRE}')") + tenter("b", f"$c->trackUps('{UPS_RELAIS}')"))
        appels = [a["appel"] for a in r["journal"]]
        constat("une seule session UPS pour deux numéros : une amorce, deux suivis",
                ok_ko(appels == ["amorce", "ups", "ups"] and r["requetes"] == 3 and r["a"].get("status") == "delivered" and r["b"].get("status") == "relay"),
                f"{appels} — {r['a'].get('status')} / {r['b'].get('status')}")
        suivi = r["journal"][1]
        constat("jeton de session tiré du cookie (décodé) et renvoyé en en-tête ; cookies de l'amorce renvoyés ; le plugin se présente sous son nom",
                ok_ko(suivi["xsrf"] == "jeton-test/session" and "X-XSRF-TOKEN-ST=jeton-test%2Fsession" in suivi["cookie"]
                      and "bm_test=valeur-test" in suivi["cookie"] and suivi["ua"].startswith("JCD-PrintGestion/")),
                json.dumps(suivi, ensure_ascii=False)[:160])

        r = client({"amorces": [AMORCE, AMORCE], "suivis": [{"status": 403, "body": "{\"message\":\"session expiree\"}"}, reponse(ups(UPS_LIVRE))]},
                   tenter("a", f"$c->trackUps('{UPS_LIVRE}')"))
        constat("session refusée (403 sans page de blocage) : rouverte une fois, puis le suivi aboutit",
                ok_ko([a["appel"] for a in r["journal"]] == ["amorce", "ups", "amorce", "ups"] and r["a"].get("status") == "delivered"),
                str([a["appel"] for a in r["journal"]]))

        r = client({"amorce": AMORCE, "suivis": [REFUS, reponse(ups(UPS_LIVRE))]}, tenter("a", f"$c->trackUps('{UPS_LIVRE}')"))
        constat("page « Access Denied » du filtre anti-robots : arrêt (« quota »), aucune nouvelle tentative",
                ok_ko(r["a"].get("kind") == "quota" and [a["appel"] for a in r["journal"]] == ["amorce", "ups"]),
                f"{r['a']} — {[a['appel'] for a in r['journal']]}")
        r = client({"amorce": REFUS}, tenter("a", f"$c->trackUps('{UPS_LIVRE}')"))
        constat("amorce refusée par le filtre : arrêt, aucun suivi demandé",
                ok_ko(r["a"].get("kind") == "quota" and [a["appel"] for a in r["journal"]] == ["amorce"]), str(r["a"]))
        r = client({"amorce": {"status": 200, "body": "{}", "headers": {"Set-Cookie": ["autre=valeur-test; path=/"]}}},
                   tenter("a", f"$c->trackUps('{UPS_LIVRE}')"))
        constat("amorce sans jeton : panne (« server »), pas de suivi sans session",
                ok_ko(r["a"].get("kind") == "server" and [a["appel"] for a in r["journal"]] == ["amorce"]), str(r["a"]))
        r = client({"amorce": AMORCE}, tenter("a", f"$c->trackUps('{UPS_CLE_FAUSSE}')") + tenter("b", "$c->trackGls('??')"))
        constat("numéro UPS à la clé fausse, code GLS impossible : refusés avant tout appel",
                ok_ko(r["a"].get("kind") == "request" and r["b"].get("kind") == "request" and r["requetes"] == 0), str(r["journal"]))
        r = client({"gls": {"00TSTA1X": reponse(gls("00TSTA1X")), "00TSTE5W": {"status": 503, "body": "indisponible"}}},
                   tenter("a", "$c->trackGls('00TSTA1X')") + tenter("b", "$c->trackGls('00tsta1xaa')") + tenter("c", "$c->trackGls('00TSTE5W')")
                   + tenter("d", "$c->trackGls('00TSTF6U')"))
        constat("GLS : connu → remis ; code inconnu (404) → « inconnu », un verdict et non une erreur ; 503 → panne",
                ok_ko(r["a"].get("status") == "delivered" and r["b"].get("status") == "not_found" and r["c"].get("kind") == "server"
                      and r["d"].get("status") == "not_found"),
                f"{r['a'].get('status')} / {r['b']} / {r['c']} / {r['d'].get('status')}")
        constat("GLS : code envoyé en majuscules", ok_ko(r["journal"][1]["numero"] == "00TSTA1XAA"), r["journal"][1]["numero"])
        r = client({"amorce": AMORCE, "suivis": ["COUPURE"]}, tenter("a", f"$c->trackUps('{UPS_LIVRE}')"))
        constat("coupure réseau : échec « network » ; le jeton de session n'apparaît dans aucun message",
                ok_ko(r["a"].get("kind") == "network" and "jeton-test" not in r["a"].get("message", "x")), str(r["a"]))

        section("4. Passage de la tâche, transporteurs simulés")
        lib.connecter_admin()
        entite = CTX.entite("Suivi public test")
        imprimante = CTX.imprimante("IMP-SUIVI-PUBLIC", entite)
        tailles = lib.tailles_journaux()

        def envoi(propriete, transporteur, numero, statut="shipped", jours=2):
            ident = CTX.expedition(imprimante, propriete, suivi=numero, statut=statut)
            sql(f"UPDATE {EXP} SET transport_carrier = {lib.q(transporteur)}, date_shipped = DATE_SUB(NOW(), INTERVAL {jours} DAY) WHERE id = {ident};")
            return ident

        e_ups = envoi("tonerblack", "ups", UPS_LIVRE)
        e_uap = envoi("tonercyan", "ups", UPS_RELAIS)
        e_faux = envoi("tonermagenta", "ups", UPS_CLE_FAUSSE)
        e_gls = envoi("toneryellow", "gls", "00TSTA1XAA")
        e_relais = envoi("drumblack", "gls", "00TSTC3Z")
        e_attente = envoi("developerblack", "ups", UPS_RETIRE, statut="pending")
        e_autre = envoi("wastetoner", "other", UPS_RETOUR)
        e_vieux = envoi("fuserkit", "gls", "00TSTD4V", jours=31)
        plan = {"amorce": AMORCE,
                "ups": {UPS_LIVRE: reponse(ups(UPS_LIVRE)), UPS_RELAIS: reponse(ups(UPS_RELAIS, uap=True)),
                        UPS_RETIRE: reponse(ups(UPS_RETIRE)), UPS_RETOUR: reponse(ups(UPS_RETOUR))},
                "gls": {"00TSTA1X": reponse(gls("00TSTA1X")), "00TSTC3Z": reponse(gls("00TSTC3Z", relais="1234")),
                        "00TSTD4V": reponse(gls("00TSTD4V"))}}

        def passage(avant=""):
            return php("$plan = " + j(plan) + ";" + TRANSPORT + avant
                       + "$stats = PluginPrintgestionPublictracking::poll(null, new PluginPrintgestionPublicclient($transport), null, 0);"
                       "echo json_encode(['stats' => $stats, 'journal' => $journal], JSON_UNESCAPED_UNICODE);")

        r = passage("PluginPrintgestionPublictracking::resetMemo(); PluginPrintgestionPublictracking::setEnabled(true);")
        demandes = [a["numero"] for a in r["journal"] if a["appel"] != "amorce"]
        constat("remise UPS constatée : « livrée », datée de l'événement UPS",
                ok_ko(etat(e_ups)[:2] == ["delivered", heure_ups]), str(etat(e_ups)))
        constat("remise GLS constatée après le repli du code d'étiquette (10 → 8), datée de l'événement GLS",
                ok_ko(etat(e_gls)[:2] == ["delivered", heure_gls] and demandes.index("00TSTA1XAA") < demandes.index("00TSTA1X")),
                f"{etat(e_gls)} — {demandes}")
        constat("UPS Access Point, point relais GLS, numéro UPS à la clé fausse : laissées « partie »",
                ok_ko([etat(e_uap)[0], etat(e_relais)[0], etat(e_faux)[0]] == ["shipped"] * 3 and UPS_CLE_FAUSSE not in demandes),
                f"{etat(e_uap)[0]} / {etat(e_relais)[0]} / {etat(e_faux)[0]}")
        constat("jamais interrogées : en attente, autre transporteur, partie depuis plus de 30 jours",
                ok_ko(UPS_RETIRE not in demandes and UPS_RETOUR not in demandes and "00TSTD4V" not in demandes
                      and [etat(e_attente)[0], etat(e_autre)[0], etat(e_vieux)[0]] == ["pending", "shipped", "shipped"]),
                str(demandes))
        constat("les colonnes du suivi GLS par l'API (tracking_*) ne sont jamais écrites",
                ok_ko(all(etat(e)[2:] == ["NULL", ""] for e in (e_gls, e_relais, e_ups))), str([etat(e)[2:] for e in (e_gls, e_relais)]))
        constat("une session UPS pour tout le passage", ok_ko([a["appel"] for a in r["journal"]].count("amorce") == 1),
                str([a["appel"] for a in r["journal"]]))
        journal = lib.journal_depuis(tailles, "printgestion.log")
        constat("chaque constat journalisé avec sa source et le détail « suivi public »",
                ok_ko("événement de livraison UPS" in journal and "événement de livraison GLS" in journal and "suivi public" in journal),
                (journal or "journal vide").replace("\n", " | ")[:220])
        constat("bilan du passage : deux livraisons, deux dépôts en relais, un numéro mal formé",
                ok_ko(r["stats"]["delivered"] >= 2 and r["stats"]["relay"] >= 2 and r["stats"]["invalid"] >= 1 and r["stats"]["stopped"] == ""),
                json.dumps(r["stats"]))

        r = passage()
        constat("second passage dans l'heure : rien n'est appelé", ok_ko(r["stats"]["stopped"] == "too_soon" and r["journal"] == []),
                json.dumps(r["stats"]))
        r = passage("PluginPrintgestionPublictracking::setEnabled(false); PluginPrintgestionPublictracking::resetMemo();")
        constat("interrupteur coupé : rien n'est appelé", ok_ko(r["stats"]["stopped"] == "disabled" and r["journal"] == []),
                json.dumps(r["stats"]))
        constat("aucune erreur PHP pendant la passe", ok_ko(lib.erreurs_php_depuis(tailles) == []), str(lib.erreurs_php_depuis(tailles))[:200])
    finally:
        CTX.nettoyer()
        sql("DELETE FROM glpi_configs WHERE context = 'plugin:printgestion' AND name LIKE 'public\\_%';")
        for nom, valeur in configs_avant:
            sql(f"INSERT INTO glpi_configs (context, name, value) VALUES ('plugin:printgestion', {lib.q(nom)}, {lib.q(valeur)});")
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
