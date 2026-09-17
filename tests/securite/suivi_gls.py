"""Suivi GLS, normalisation du numéro : nettoyage de la saisie et repli unique, clé par clé.

Aucun appel réseau : les réponses GLS sont SIMULÉES, inventées, à la forme de la spécification Track And Trace V1
(parcels[] : requested, unitno, status, errorCode). Les numéros ont la forme des cas réels (10 caractères
d'étiquette, Track ID à 8 caractères, colis de test « QAS_ ») mais sont inventés.

1. Nettoyage : les cinq cas du tableau (formes), URL collée, casse.
2. Repli : seulement 10 caractères alphanumériques ; jamais pour 8 caractères ni pour « QAS_… ».
3. Interrogation simulée : E_404_01 sur 10 caractères → exactement une seconde tentative, jamais deux ; 8 caractères
   → jamais de repli ; panne de colis (E_500_01) → pas de repli ; lot de trois dont un inconnu → trois résultats,
   repli sur celui-là seul ; multi-colis → liste ; lots de 10 au plus.
"""
import json
import sys

import donnees as d
import lib
from lib import section, verifier

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
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
