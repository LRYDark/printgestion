"""Suivi GLS, normalisation du numéro : nettoyage de la saisie et repli unique, clé par clé.

Aucun appel réseau : les réponses GLS sont SIMULÉES, inventées, à la forme de la spécification Track And Trace V1
(parcels[] : requested, unitno, status, errorCode). Les numéros ont la forme des cas réels (10 caractères
d'étiquette, Track ID à 8 caractères, colis de test « QAS_ ») mais sont inventés.

1. Nettoyage : les cinq cas du tableau (formes), URL collée, casse.
2. Repli : seulement 10 caractères alphanumériques ; jamais pour 8 caractères ni pour « QAS_… ».
3. Interrogation simulée : E_404_01 sur 10 caractères → exactement une seconde tentative, jamais deux ; 8 caractères
   → jamais de repli ; panne de colis (E_500_01) → pas de repli ; lot de trois dont un inconnu → trois résultats,
   repli sur celui-là seul ; multi-colis → liste ; lots de 10 au plus.
4. Client GLS avec un transport simulé (aucun réseau) : sans clés rien n'est appelé ; jeton en Basic sur oauth2/v1/token
   puis Bearer et Accept-Language: FR sur trackids ; jeton gardé en cache entre deux processus ; 401 → jeton oublié,
   429 → journée bloquée, 500 / 400 / coupure → échecs typés ; onze clés refusées avant tout appel ; quota compté sur
   le suivi seulement ; le secret, son Basic et le jeton n'apparaissent dans aucun message.
Clés inventées, posées puis retirées ; mémo et cache du jeton effacés à la fin.
"""
import json
import sys

import donnees as d
import lib
from lib import section, sql, verifier

# Réponses simulées : clé connue → colis rendus (inventés). Toute autre clé : E_404_01. « PANNE… » : E_500_01.
CONNUS = {
    "00TSTA1X": [{"requested": "00TSTA1X", "unitno": "90000000001", "status": "DELIVERED"}],
    "00TSTB2Y": [{"requested": "00TSTB2Y", "unitno": "90000000002", "status": "INTRANSIT"}],
    "00TSTC3Z": [{"requested": "00TSTC3Z", "unitno": "90000000003", "status": "DELIVEREDPS"}],
    "QAS_0000001": [{"requested": "QAS_0000001", "unitno": "90000000004", "status": "DELIVERED"}],
    "00TSTM4W": [{"requested": "00TSTM4W", "unitno": "90000000005", "status": "INDELIVERY"},
                 {"requested": "00TSTM4W", "unitno": "90000000006", "status": "DELIVERED"}],
}


def php(code):
    return json.loads(lib.php_glpi(code))


def interroger(cles):
    """lookup() avec des réponses simulées ; rend (résultats, appels successifs)."""
    code = (
        "$connus = json_decode(" + json.dumps(json.dumps(CONNUS)) + ", true); $appels = [];"
        "$query = function (array $keys) use ($connus, &$appels): array {"
        "  $appels[] = $keys; $out = [];"
        "  foreach ($keys as $k) {"
        "    if (isset($connus[$k])) { foreach ($connus[$k] as $p) { $out[] = $p; } }"
        "    elseif (str_starts_with($k, 'PANNE')) { $out[] = ['requested' => $k, 'errorCode' => 'E_500_01', 'errorMessage' => 'Internal Server Error']; }"
        "    else { $out[] = ['requested' => $k, 'errorCode' => 'E_404_01', 'errorMessage' => 'Resource Not Found']; }"
        "  }"
        "  return $out;"
        "};"
        "$r = PluginPrintgestionGlsnumber::lookup(json_decode(" + json.dumps(json.dumps(cles)) + ", true), $query);"
        "echo json_encode(['resultats' => $r, 'appels' => $appels]);"
    )
    sortie = php(code)
    return sortie["resultats"], sortie["appels"]


SECRET, JETON = "secret-test-K9x2Qv", "jeton-test-Z7mP4wL"
BASIC = "Basic " + __import__("base64").b64encode(f"client-test:{SECRET}".encode()).decode()


def client(plan, appels):
    """Exécute des appels au client avec un transport simulé ; rend ce que PHP renvoie (json)."""
    code = (
        "$plan = json_decode(" + json.dumps(json.dumps(plan)) + ", true); $journal = [];"
        "$transport = function (string $method, string $url, array $headers, ?string $body) use (&$plan, &$journal): array {"
        "  $journal[] = ['method' => $method, 'url' => $url, 'auth' => substr((string) ($headers['Authorization'] ?? ''), 0, 6),"
        "                'lang' => $headers['Accept-Language'] ?? '', 'body' => (string) $body, 'basic_ok' => ($headers['Authorization'] ?? '') === $plan['basic']];"
        "  if (str_contains($url, 'oauth2/v1/token')) { return $plan['token']; }"
        "  $r = array_shift($plan['track']); if ($r === 'COUPURE') { throw new RuntimeException('coupure reseau'); } return $r;"
        "};"
        "$c = new PluginPrintgestionGlsclient($transport); $out = [];"
        + appels +
        "$out['journal'] = $journal; $out['memo'] = PluginPrintgestionGlsclient::getMemo(); $out['restant'] = PluginPrintgestionGlsclient::quotaRemaining();"
        "echo json_encode($out, JSON_UNESCAPED_UNICODE);"
    )
    return json.loads(lib.php_glpi(code))


def tenter(nom, expression):
    """Fragment PHP : résultat de l'expression, ou ['kind', 'message'] de l'exception transporteur."""
    return (f"try {{ $out['{nom}'] = {expression}; }} catch (PluginPrintgestionCarrierexception $e) "
            f"{{ $out['{nom}'] = ['kind' => $e->getKind(), 'message' => $e->getMessage()]; }}")


def main():
    d.verifier_instance()

    section("1. Nettoyage de la saisie")
    cas = [
        ("00TSTA1XAA", "00TSTA1XAA"),
        ("00TSTB2YBB", "00TSTB2YBB"),
        ("00TSTC3Z", "00TSTC3Z"),
        ("00 TSTC 3Z", "00TSTC3Z"),
        ("QAS_0000001", "QAS_0000001"),
        (" 00-tsta1x.aa ", "00TSTA1XAA"),
        ("https://suivi.exemple.invalid/colis?match=00TSTB2YBB", "00TSTB2YBB"),
        ("https://suivi.exemple.invalid/colis/00TSTC3Z", "00TSTC3Z"),
    ]
    nettoyes = php("echo json_encode(array_map([PluginPrintgestionGlsnumber::class, 'clean'], "
                   + "json_decode(" + json.dumps(json.dumps([s for s, _ in cas])) + ", true)));")
    for (saisie, attendu), obtenu in zip(cas, nettoyes):
        verifier(f"« {saisie} » → « {attendu} »", obtenu, attendu)

    section("2. Repli applicable")
    replis = php("echo json_encode(array_map([PluginPrintgestionGlsnumber::class, 'fallback'], "
                 + "['00TSTA1XAA', '00TSTB2YBB', '00TSTC3Z', 'QAS_0000001', 'QAS_00000', '90000000001']));")
    verifier("00TSTA1XAA : 00TSTA1X, suffixe AA", replis[0], {"key": "00TSTA1X", "suffix": "AA"})
    verifier("00TSTB2YBB : 00TSTB2Y, suffixe BB", replis[1], {"key": "00TSTB2Y", "suffix": "BB"})
    verifier("00TSTC3Z (8 caractères) : pas de repli", replis[2], None)
    verifier("QAS_0000001 (contient « _ ») : pas de repli", replis[3], None)
    verifier("QAS_00000 (10 caractères avec « _ ») : pas de repli", replis[4], None)
    verifier("numéro de colis à 11 chiffres : pas de repli", replis[5], None)

    section("3. Interrogation simulée")
    resultats, appels = interroger(["00TSTA1XAA"])
    verifier("10 caractères inconnu : exactement deux appels, le second avec les 8 premiers", appels, [["00TSTA1XAA"], ["00TSTA1X"]])
    verifier("repli réussi : clé 00TSTA1X, suffixe AA, colis trouvé", (resultats["00TSTA1XAA"]["key"], resultats["00TSTA1XAA"]["suffix"],
             resultats["00TSTA1XAA"]["error"], len(resultats["00TSTA1XAA"]["parcels"]), resultats["00TSTA1XAA"]["fell_back"]),
             ("00TSTA1X", "AA", None, 1, True))

    resultats, appels = interroger(["00TSTZ9QAA"])
    verifier("10 caractères inconnu, repli inconnu aussi : deux appels, jamais trois", len(appels), 2)
    verifier("échec des deux : E_404_01, clé saisie conservée, aucun suffixe", (resultats["00TSTZ9QAA"]["key"], resultats["00TSTZ9QAA"]["suffix"],
             resultats["00TSTZ9QAA"]["error"]), ("00TSTZ9QAA", None, "E_404_01"))

    resultats, appels = interroger(["00TSTZ9Q"])
    verifier("8 caractères inconnu : un seul appel, aucun repli", (appels, resultats["00TSTZ9Q"]["fell_back"], resultats["00TSTZ9Q"]["error"]),
             ([["00TSTZ9Q"]], False, "E_404_01"))

    resultats, appels = interroger(["QAS_0000009"])
    verifier("colis de test inconnu « QAS_0000009 » : un seul appel, jamais tronqué", appels, [["QAS_0000009"]])

    resultats, appels = interroger(["QAS_0000001"])
    verifier("colis de test connu : trouvé tel quel", (appels, resultats["QAS_0000001"]["error"], resultats["QAS_0000001"]["key"]),
             ([["QAS_0000001"]], None, "QAS_0000001"))

    resultats, appels = interroger(["PANNE0001A"])
    verifier("E_500_01 sur 10 caractères : un seul appel, pas de repli (une panne ne tronque jamais)",
             (appels, resultats["PANNE0001A"]["error"], resultats["PANNE0001A"]["fell_back"]), ([["PANNE0001A"]], "E_500_01", False))

    resultats, appels = interroger(["00TSTB2Y", "00TSTA1XAA", "QAS_0000001"])
    verifier("lot de trois dont un inconnu : premier appel avec les trois, second avec le seul inconnu raccourci",
             appels, [["00TSTB2Y", "00TSTA1XAA", "QAS_0000001"], ["00TSTA1X"]])
    verifier("lot de trois : trois résultats, les deux connus sans repli",
             (sorted(resultats), resultats["00TSTB2Y"]["fell_back"], resultats["QAS_0000001"]["fell_back"], resultats["00TSTA1XAA"]["fell_back"]),
             (["00TSTA1XAA", "00TSTB2Y", "QAS_0000001"], False, False, True))

    resultats, _ = interroger(["00TSTM4W"])
    verifier("multi-colis : une clé, deux colis rapprochés par « requested »",
             sorted(p["unitno"] for p in resultats["00TSTM4W"]["parcels"]), ["90000000005", "90000000006"])

    cles = [f"00TST{n:03d}" for n in range(23)]
    _, appels = interroger(cles)
    verifier("23 clés : lots de 10, 10 et 3 (aucune clé de 8 caractères ne déclenche de repli)", [len(a) for a in appels], [10, 10, 3])

    section("4. Client GLS, transport simulé")
    tailles = lib.tailles_journaux()
    token_ok = {"status": 200, "body": json.dumps({"access_token": JETON, "expires_in": 3600, "token_type": "Bearer"})}
    reponse = {"status": 200, "body": json.dumps({"parcels": CONNUS["00TSTA1X"] + [{"requested": "00TSTZ9Q", "errorCode": "E_404_01", "errorMessage": "Resource Not Found"}]})}
    plan = {"basic": BASIC, "token": token_ok, "track": [reponse]}
    try:
        lib.php_glpi("PluginPrintgestionGlsclient::resetMemo(); (new PluginPrintgestionGlsclient())->forgetToken();")
        sql("UPDATE glpi_plugin_printgestion_configs SET gls_client_id = '', gls_client_secret = '', gls_secret_date = NULL WHERE id = 1;")
        r = client(plan, tenter("test", "$c->testConnection()") + tenter("suivi", "$c->track(['00TSTA1X'])"))
        verifier("sans clés : test de connexion refusé, suivi refusé (auth), aucun appel", (r["test"]["ok"], r["suivi"]["kind"], r["journal"]), (False, "auth", []))
        chiffre = lib.php_glpi(f"echo (new GLPIKey())->encrypt({lib.q(SECRET)});").strip()
        sql(f"UPDATE glpi_plugin_printgestion_configs SET gls_client_id = 'client-test', gls_client_secret = {lib.q(chiffre)}, gls_secret_date = NOW() WHERE id = 1;")
        r = client(plan, tenter("test", "$c->testConnection()"))
        verifier("test de connexion : un seul appel, POST oauth2/v1/token en Basic identifiant:secret, client_credentials, jeton jeté",
                 (r["test"]["ok"], len(r["journal"]), r["journal"][0]["method"], "oauth2/v1/token" in r["journal"][0]["url"], r["journal"][0]["basic_ok"],
                  r["journal"][0]["body"], JETON in r["test"]["message"]), (True, 1, "POST", True, True, "grant_type=client_credentials", False))
        verifier("le test de connexion ne compte pas dans le quota", r["memo"]["quota_count"], 0)
        r = client(plan, tenter("suivi", "$c->track(['00TSTA1X', '00TSTZ9Q'])"))
        suivi = r["journal"][-1]
        verifier("suivi : jeton puis GET trackids, clés jointes par des virgules, showEvents et showLinks explicites, Bearer, Accept-Language FR",
                 (len(r["journal"]), suivi["method"], suivi["url"], suivi["auth"], suivi["lang"]),
                 (2, "GET", "https://api.gls-group.net/track-and-trace-v1/tracking/simple/trackids/00TSTA1X,00TSTZ9Q?showEvents=true&showLinks=false", "Bearer", "FR"))
        verifier("réponse rendue telle quelle : deux entrées, dont l'erreur par colis", (len(r["suivi"]), r["suivi"][1]["errorCode"]), (2, "E_404_01"))
        verifier("quota : une requête de suivi comptée, budget 400 − 1", (r["memo"]["quota_count"], r["restant"]), (1, 399))
        r = client(plan, tenter("suivi", "$c->track(['00TSTA1X'])"))
        verifier("second processus : jeton repris du cache GLPI, un seul appel (GET), quota 2", (len(r["journal"]), r["journal"][0]["method"], r["memo"]["quota_count"]), (1, "GET", 2))
        refus = {"status": 401, "body": json.dumps({"fault": {"faultstring": "Invalid access token", "detail": {"errorcode": "oauth.v2.InvalidAccessToken"}}})}
        r = client({**plan, "track": [refus, reponse]}, tenter("refus", "$c->track(['00TSTA1X'])") + tenter("suite", "$c->track(['00TSTA1X'])"))
        verifier("401 Apigee : échec « auth » avec le faultstring, jeton oublié puis redemandé à l'appel suivant",
                 (r["refus"]["kind"], "Invalid access token" in r["refus"]["message"], [a["method"] for a in r["journal"]]), ("auth", True, ["GET", "POST", "GET"]))
        quota = {"status": 429, "body": json.dumps({"fault": {"faultstring": "Rate limit quota violation", "detail": {"errorcode": "policies.ratelimit.QuotaViolation"}}})}
        r = client({**plan, "track": [quota]}, tenter("quota", "$c->track(['00TSTA1X'])"))
        verifier("429 : échec « quota », journée bloquée, plus aucun budget", (r["quota"]["kind"], r["memo"]["quota_blocked"], r["restant"]), ("quota", True, 0))
        lib.php_glpi("Config::setConfigurationValues('plugin:printgestion', ['gls_quota_blocked_day' => '']);")
        panne = {"status": 503, "body": ""}
        requete = {"status": 400, "body": json.dumps({"type": "about:blank", "title": "Bad Request", "status": 400, "detail": "Limit size exceeded"})}
        r = client({**plan, "track": [panne, requete, "COUPURE"]},
                   tenter("panne", "$c->track(['00TSTA1X'])") + tenter("requete", "$c->track(['00TSTA1X'])") + tenter("coupure", "$c->track(['00TSTA1X'])")
                   + tenter("onze", "$c->track(array_map(fn($n) => sprintf('00TST%03d', $n), range(1, 11)))"))
        verifier("503 → « server » ; 400 ErrorResponseDTO → « request » avec le titre ; coupure → « network » sans le message brut",
                 (r["panne"]["kind"], r["requete"]["kind"], "Bad Request" in r["requete"]["message"], r["coupure"]["kind"], "coupure reseau" in r["coupure"]["message"]),
                 ("server", "request", True, "network", False))
        verifier("onze clés : refusées avant tout appel (« request »), trois appels seulement au journal", (r["onze"]["kind"], len(r["journal"])), ("request", 3))
        messages = json.dumps([r["panne"], r["requete"], r["coupure"], r["onze"]])
        verifier("ni le secret, ni son Basic, ni le jeton dans les messages d'erreur", (SECRET in messages, BASIC in messages, JETON in messages), (False, False, False))
        journal = str(lib.journal_depuis(tailles, "printgestion.log"))
        verifier("ni le secret ni le jeton dans le journal du plugin", (SECRET in journal, JETON in journal), (False, False))
    finally:
        sql("UPDATE glpi_plugin_printgestion_configs SET gls_client_id = '', gls_client_secret = '', gls_secret_date = NULL WHERE id = 1;")
        lib.php_glpi("PluginPrintgestionGlsclient::resetMemo(); (new PluginPrintgestionGlsclient())->forgetToken();")
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
