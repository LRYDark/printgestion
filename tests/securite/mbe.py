"""MBE (intermédiaire de transport), étape 1 : identifiants enregistrés chiffrés, jamais réaffichés, « Tester la
connexion » avec un transport simulé, carte Santé, aucun secret nulle part. Rien d'autre n'appelle MBE.
1. Réglages par le formulaire : identifiant et passphrase chiffrés en base (GLPIKey), passphrase jamais dans la page,
   identifiant réaffiché et rechiffré seulement s'il change, autres réglages intacts, « Retirer les identifiants »,
   API REST sans ces champs.
2. Client, transport simulé : sans identifiants aucun appel ; enveloppe SOAP 1.1 construite avec DOM (préfixe sur la
   seule opération, éléments internes sans namespace, System FR, Credentials, InternalReferenceID unique, fenêtre de
   sept jours, page 1), HTTP Basic ; réponse OK comptée ; 403, 500 (NullPointerException), réseau, 503, Status ≠ OK,
   XML illisible, structure inattendue : messages distincts, jamais un identifiant, une passphrase, un Basic ni un
   lien signé.
3. Carte Santé : sans identifiants rien ne manque ; saisis sans appel : en attente ; refus 403 : rouge tout de suite ;
   appel réussi : vert ; cinq échecs : rouge.
4. Écran de configuration : boutons selon l'état, adresse affichée, passphrase absente.
La ligne de configuration est remise telle quelle à la fin (colonnes NULL sur une base neuve), le mémo effacé.
"""
import base64
import json
import sys
import xml.etree.ElementTree as ET

import config
import donnees as d
import lib
import sante
from lib import WEB, constat, lignes, ok_ko, section, sql, valeur, verifier

USER, PASS = "utilisateur-test-mbe", "passphrase-test-Q7v2Xk"
BASIC = "Basic " + base64.b64encode(f"{USER}:{PASS}".encode()).decode()
NS_SOAP, NS_MBE = "http://schemas.xmlsoap.org/soap/envelope/", "http://www.onlinembe.eu/ws/"
ENDPOINT = "https://api.mbeonline.fr/ws"
ONGLET = "/ajax/common.tabs.php?_target=%2Ffront%2Fconfig.form.php&_itemtype=Config&_glpi_tab=PluginPrintgestionConfig%241&id=1"
TABLE = "glpi_plugin_printgestion_configs"


def balise_bouton(page, nom):
    """Balise <button …> complète qui porte ce nom : `disabled` est posé juste après `<button`, pas après le nom."""
    pos = page.find(f"name='{nom}'")
    if pos < 0:
        return ""
    debut = page.rfind("<button", 0, pos)
    fin = page.find(">", pos)
    return page[debut:fin + 1] if debut >= 0 and fin > 0 else ""


def reponse(status, page=1, total=1, shipments=2, errors=""):
    """Réponse ShipmentsListV3Request inventée (HTTP 200)."""
    colis = "".join(f"<ShipmentFullInfo><TrackingInfo><MasterTrackingMBE>FR0000-00-000000TST{i}</MasterTrackingMBE></TrackingInfo></ShipmentFullInfo>" for i in range(shipments))
    body = ('<?xml version="1.0" encoding="UTF-8"?>'
            f'<soapenv:Envelope xmlns:soapenv="{NS_SOAP}"><soapenv:Body><ns2:ShipmentsListV3RequestResponse xmlns:ns2="{NS_MBE}"><RequestContainer>'
            f'<Status>{status}</Status><Errors>{errors}</Errors><ShipmentsFullInfo>{colis}</ShipmentsFullInfo><Page>{page}</Page><TotalPages>{total}</TotalPages>'
            '</RequestContainer></ns2:ShipmentsListV3RequestResponse></soapenv:Body></soapenv:Envelope>')
    return {"status": 200, "body": body}


def client(plan, appels):
    """Exécute des appels au client avec un transport simulé (une réponse par appel, 'COUPURE' = panne réseau)."""
    code = (
        "$plan = json_decode(" + json.dumps(json.dumps(plan)) + ", true); $journal = [];"
        "$transport = function (string $method, string $url, array $headers, ?string $body) use (&$plan, &$journal): array {"
        "  $journal[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string) $body];"
        "  $r = array_shift($plan); if ($r === null) { throw new RuntimeException('plan vide'); }"
        "  if ($r === 'COUPURE') { throw new RuntimeException('coupure reseau'); } return $r;"
        "};"
        "$c = new PluginPrintgestionMbeclient($transport); $out = [];"
        + appels +
        "$out['journal'] = $journal; $out['memo'] = PluginPrintgestionMbeclient::getMemo();"
        "echo json_encode($out, JSON_UNESCAPED_UNICODE);"
    )
    return json.loads(lib.php_glpi(code))


def test(plan):
    return client(plan, "$out['test'] = $c->testConnection();")


def poser_cles():
    chiffre_u = lib.php_glpi(f"echo (new GLPIKey())->encrypt({lib.q(USER)});").strip()
    chiffre_p = lib.php_glpi(f"echo (new GLPIKey())->encrypt({lib.q(PASS)});").strip()
    sql(f"UPDATE {TABLE} SET mbe_username = {lib.q(chiffre_u)}, mbe_passphrase = {lib.q(chiffre_p)}, mbe_secret_date = NOW() WHERE id = 1;")


def retirer_cles(nulles=False):
    v = "NULL" if nulles else "''"
    sql(f"UPDATE {TABLE} SET mbe_username = {v}, mbe_passphrase = {v}, mbe_secret_date = NULL WHERE id = 1;")


def main():
    d.verifier_instance()
    lib.connecter_admin()
    colonnes = [r[0] for r in lignes(f"SHOW COLUMNS FROM {TABLE}")]
    choix = ", ".join(f"IFNULL(`{c}`, 'NULL')" for c in colonnes)
    lire = lambda: dict(zip(colonnes, (lib.decoder(v) for v in lignes(f"SELECT {choix} FROM {TABLE} WHERE id = 1")[0])))  # noqa: E731
    q = lambda x: "NULL" if x == "NULL" else lib.q(x)  # noqa: E731
    avant = lire()
    memo_avant = int(valeur("SELECT COUNT(*) FROM glpi_configs WHERE context = 'plugin:printgestion' AND name LIKE 'mbe\\_%'"))
    tailles = lib.tailles_journaux()
    try:
        section("1. Réglages : identifiant et passphrase chiffrés, passphrase jamais réaffichée")
        sortie = lib.php_glpi("$c = new PluginPrintgestionConfig(); $c->getFromDB(1); $f = $c->fields; PluginPrintgestionConfig::unsetUndisclosedFields($f); "
                              "echo json_encode([array_values(array_intersect(array_keys($f), ['mbe_username', 'mbe_passphrase'])), array_key_exists('mbe_passphrase', $c->fields)]);")
        constat("API REST : identifiant et passphrase MBE retirés de la réponse (undisclosedFields), la lecture interne garde les siens",
                ok_ko(json.loads(sortie) == [[], True]), sortie.strip())
        statut, page, _ = WEB.get(ONGLET, ajax=True)
        formulaire = lib.Formulaire("printgestion/front/config.form.php")
        formulaire.feed(page)
        exclus = ("_glpi_csrf_token", "mbe_username", "mbe_passphrase", "update", "clear_mbe", "test_mbe", "clear_gls", "test_gls")
        champs = [(n, v) for n, v in formulaire.champs if n not in exclus]
        if len(champs) < 5:
            constat("formulaire de configuration lu", "NON CONCLUANT", f"HTTP {statut}, {len(champs)} champ(s)")
            return lib.bilan()
        WEB.post(config.FRONT + "/config.form.php", champs + [("mbe_username", USER), ("mbe_passphrase", PASS), ("update", "1")])
        ligne = lire()
        constat("identifiant et passphrase enregistrés chiffrés, jamais en clair en base, date posée",
                ok_ko(ligne["mbe_username"] not in ("NULL", "", USER) and USER not in ligne["mbe_username"]
                      and ligne["mbe_passphrase"] not in ("NULL", "", PASS) and PASS not in ligne["mbe_passphrase"] and ligne["mbe_secret_date"] != "NULL"))
        dechiffres = json.loads(lib.php_glpi("echo json_encode([PluginPrintgestionMbeclient::getUsername(), PluginPrintgestionConfig::getSecret('mbe_passphrase')]);"))
        verifier("déchiffrés par le plugin : identifiant et passphrase saisis", dechiffres, [USER, PASS])
        _, page, _ = WEB.get(ONGLET, ajax=True)
        constat("écran : identifiant réaffiché, passphrase jamais (« •••••••• définie le »), « Remplacer », « Retirer les identifiants », « Tester la connexion », adresse fixée affichée",
                ok_ko(PASS not in page and f"value='{USER}'" in page and "définie le" in page and "Remplacer" in page and "Retirer les identifiants" in page
                      and "name='test_mbe'" in page and ENDPOINT in page))
        WEB.post(config.FRONT + "/config.form.php", champs + [("mbe_username", USER), ("mbe_passphrase", ""), ("update", "1")])
        apres = lire()
        verifier("nouvel enregistrement sans changement : identifiant non rechiffré, passphrase et date inchangées",
                 (apres["mbe_username"], apres["mbe_passphrase"], apres["mbe_secret_date"]), (ligne["mbe_username"], ligne["mbe_passphrase"], ligne["mbe_secret_date"]))
        autres = {c: (avant[c], apres[c]) for c in colonnes
                  if not c.startswith(("mbe_", "gls_")) and c != "date_mod" and avant[c] != apres[c]}
        constat("enregistrement du formulaire : autres réglages inchangés (contrôle du test)", "OK" if not autres else "À NOTER", str(autres)[:300])
        WEB.post(config.FRONT + "/config.form.php", [("clear_mbe", "1")])
        apres = lire()
        constat("« Retirer les identifiants » : identifiant, passphrase et date effacés, message rendu",
                ok_ko(apres["mbe_username"] == "" and apres["mbe_passphrase"] == "" and apres["mbe_secret_date"] == "NULL" and "retirés" in WEB.messages()))

        section("2. Client MBE, transport simulé : enveloppe, Basic, réponses et erreurs, aucun secret")
        lib.php_glpi("PluginPrintgestionMbeclient::resetMemo();")
        retirer_cles()
        r = test([reponse("OK")])
        verifier("sans identifiants : test refusé, aucun appel", (r["test"]["ok"], "non saisis" in r["test"]["message"], r["journal"]), (False, True, []))
        poser_cles()
        r = test([reponse("OK", total=3, shipments=2)])
        appel = r["journal"][0] if r["journal"] else {}
        entetes = {k.lower(): v for k, v in appel.get("headers", {}).items()}
        verifier("réponse OK : connexion établie, 2 expéditions comptées, page 1 sur 3, un seul appel POST vers l'adresse fixée, jamais /ws/ws",
                 (r["test"]["ok"], "2 expédition(s)" in r["test"]["message"], "page 1 sur 3" in r["test"]["message"], len(r["journal"]), appel.get("method"), appel.get("url")),
                 (True, True, True, 1, "POST", ENDPOINT))
        verifier("en-têtes : Basic identifiant:passphrase, text/xml, SOAPAction vide",
                 (entetes.get("authorization"), (entetes.get("content-type") or "").startswith("text/xml"), entetes.get("soapaction")), (BASIC, True, '""'))
        racine = ET.fromstring(appel.get("body", "<vide/>"))
        corps = racine[0] if len(racine) else None
        operation = corps[0] if corps is not None and len(corps) else None
        conteneur = operation[0] if operation is not None and len(operation) else None
        enfants = [e.tag for e in conteneur] if conteneur is not None else []
        verifier("enveloppe SOAP 1.1 : Envelope et Body dans le namespace SOAP, l'opération seule dans le namespace MBE, RequestContainer et ses éléments sans namespace",
                 (racine.tag, corps.tag if corps is not None else None, operation.tag if operation is not None else None, conteneur.tag if conteneur is not None else None,
                  all(":" not in t and "{" not in t for t in enfants)),
                 (f"{{{NS_SOAP}}}Envelope", f"{{{NS_SOAP}}}Body", f"{{{NS_MBE}}}ShipmentsListV3Request", "RequestContainer", True))
        dates = json.loads(lib.php_glpi("echo json_encode([date('Y-m-d', time() - 7 * 86400), date('Y-m-d')]);"))
        valeurs = {e.tag: (e.text or "") for e in conteneur} if conteneur is not None else {}
        creds = {e.tag: (e.text or "") for e in conteneur.find("Credentials")} if conteneur is not None and conteneur.find("Credentials") is not None else {}
        verifier("conteneur : System FR, Credentials (identifiant, passphrase), InternalReferenceID GLPI-PG-…, fenêtre des sept derniers jours, page 1, dans cet ordre",
                 (enfants, valeurs.get("System"), creds.get("Username"), creds.get("Passphrase"), valeurs.get("InternalReferenceID", "").startswith("GLPI-PG-"),
                  valeurs.get("DateFrom"), valeurs.get("DateTo"), valeurs.get("Page")),
                 (["System", "Credentials", "InternalReferenceID", "DateFrom", "DateTo", "Page"], "FR", USER, PASS, True, dates[0], dates[1], "1"))
        verifier("mémo de santé : dernier appel réussi noté, zéro échec", (r["memo"]["last_success"] != "", r["memo"]["failures"]), (True, 0))
        r2 = test([reponse("OK")])
        ref = lambda rr: ET.fromstring(rr["journal"][0]["body"]).find(".//RequestContainer/InternalReferenceID").text  # noqa: E731
        verifier("InternalReferenceID unique : deux appels, deux références", ref(r) != ref(r2), True)
        r = test([{"status": 403, "body": "Forbidden"}])
        verifier("HTTP 403 : identifiants ou droits refusés, « réessayer ne change rien », mémo en échec de forme auth, aucun secret dans le message",
                 (r["test"]["ok"], "HTTP 403" in r["test"]["message"], "réessayer ne change rien" in r["test"]["message"], r["memo"]["failures"], r["memo"]["last_kind"],
                  USER in r["test"]["message"] or PASS in r["test"]["message"] or "Basic" in r["test"]["message"]), (False, True, True, 1, "auth", False))
        r = test([{"status": 500, "body": "<html>java.lang.NullPointerException</html>"}])
        verifier("HTTP 500 NullPointerException : requête refusée, le format en cause, pas les identifiants (forme request)",
                 (r["test"]["ok"], "HTTP 500, NullPointerException" in r["test"]["message"], "format" in r["test"]["message"], r["memo"]["last_kind"]), (False, True, True, "request"))
        r = test(["COUPURE"])
        verifier("panne réseau : « MBE injoignable », forme network, message sans détail brut",
                 (r["test"]["ok"], "MBE injoignable" in r["test"]["message"], r["memo"]["last_kind"], "coupure reseau" in r["test"]["message"]), (False, True, "network", False))
        r = test([{"status": 503, "body": "down"}])
        verifier("HTTP 503 : « MBE indisponible », forme server", (r["test"]["ok"], "MBE indisponible (HTTP 503)" in r["test"]["message"], r["memo"]["last_kind"]), (False, True, "server"))
        lien = "https://documents.exemple.test/doc.pdf?X-Amz-Credential=AKIATEST&amp;X-Amz-Signature=abcTST"
        r = test([reponse("KO", errors=f"Invalid credentials for {USER}, see {lien}")])
        verifier("Status KO : « MBE répond « KO » » avec le texte des Errors, identifiant et lien signé masqués",
                 (r["test"]["ok"], "MBE répond « KO »" in r["test"]["message"], "Invalid credentials for ***" in r["test"]["message"], "lien signé masqué" in r["test"]["message"],
                  USER in r["test"]["message"], "X-Amz-Signature=abc" in r["test"]["message"], r["memo"]["last_kind"]), (False, True, True, True, False, False, "request"))
        r = test([{"status": 200, "body": "pas du xml <"}])
        verifier("HTTP 200 sans XML : « illisible », forme server", ("illisible" in r["test"]["message"], r["memo"]["last_kind"]), (True, "server"))
        r = test([{"status": 200, "body": "<a><b/></a>"}])
        verifier("HTTP 200 sans RequestContainer : « structure inattendue »", "structure inattendue" in r["test"]["message"], True)
        r = test([reponse("OK", total=0, shipments=0)])
        verifier("réponse OK sans expédition : connexion établie quand même, 0 expédition, page 1 sur 1, mémo remis à zéro échec",
                 (r["test"]["ok"], "0 expédition(s)" in r["test"]["message"], "page 1 sur 1" in r["test"]["message"], r["memo"]["failures"], r["memo"]["last_kind"]), (True, True, True, 0, ""))
        journal = lib.journal_depuis(tailles, "php-errors.log") + lib.journal_depuis(tailles, "sql-errors.log") + lib.journal_depuis(tailles, "printgestion.log")
        constat("journaux GLPI et du plugin : ni passphrase, ni en-tête Basic, ni identifiant", ok_ko(PASS not in journal and BASIC not in journal and USER not in journal))

        section("3. Carte Santé : ligne MBE selon l'état, rien qui manque sans identifiants")
        retirer_cles(nulles=True)
        lib.php_glpi("PluginPrintgestionMbeclient::resetMemo();")
        page, etats = sante.carte()
        constat("sans identifiants : ligne verte « Identifiants non saisis : aucun appel MBE », rien ne manque",
                ok_ko(etats.get("mbe") == "ok" and "Identifiants non saisis" in page and "Identifiants : non saisis." in page))
        poser_cles()
        page, etats = sante.carte()
        constat("identifiants saisis, aucun appel : en attente, « Tester la connexion » proposé, passphrase jamais dans la page",
                ok_ko(etats.get("mbe") == "pending" and "aucun appel réussi encore" in page and PASS not in page and "name='test_mbe'" in page))
        lib.php_glpi("PluginPrintgestionMbeclient::noteFailure('Identifiant ou passphrase MBE refusés, ou compte sans droit d\\'accès (HTTP 403).', 'auth');")
        page, etats = sante.carte()
        constat("un refus 403 : ligne rouge tout de suite, « réessayer ne change rien », plus « complète »",
                ok_ko(etats.get("mbe") == "error" and "réessayer ne change rien" in page and "HTTP 403" in page and "Configuration : complète" not in page))
        lib.php_glpi("PluginPrintgestionMbeclient::noteSuccess();")
        page, etats = sante.carte()
        constat("appel réussi : ligne verte, trois lignes de détail (identifiants et date, dernier appel, échecs 0)",
                ok_ko(etats.get("mbe") == "ok" and "Dernier appel réussi le" in page and "Identifiants : saisis, passphrase définie le" in page and "Échecs consécutifs : 0." in page))
        for _ in range(5):
            lib.php_glpi("PluginPrintgestionMbeclient::noteFailure('MBE indisponible (HTTP 503).', 'server');")
        page, etats = sante.carte()
        constat("cinq échecs techniques consécutifs : ligne rouge avec la dernière erreur",
                ok_ko(etats.get("mbe") == "error" and "5 échecs consécutifs" in page and "HTTP 503" in page))

        section("4. Écran de configuration : boutons selon l'état")
        retirer_cles(nulles=True)
        _, page, _ = WEB.get(ONGLET, ajax=True)
        bouton = balise_bouton(page, "test_mbe")
        constat("sans identifiants : « Tester la connexion » affiché et cliquable, avec ce qui reste à enregistrer ; pas de « Retirer » ; « Aucune passphrase enregistrée » ; adresse fixée affichée",
                ok_ko(bouton != "" and "disabled" not in bouton and "Encore à enregistrer" in page
                      and "identifiant API" in page and "passphrase API" in page
                      and "name='clear_mbe'" not in page and "Aucune passphrase enregistrée" in page and ENDPOINT in page),
                bouton[:120])
        poser_cles()
        _, page, _ = WEB.get(ONGLET, ajax=True)
        constat("identifiants saisis : les deux boutons, identifiant réaffiché, passphrase absente",
                ok_ko("name='test_mbe'" in page and "name='clear_mbe'" in page and f"value='{USER}'" in page and PASS not in page))
    finally:
        apres = lire()
        remise = ", ".join(f"`{c}` = {q(avant[c])}" for c in colonnes if c != "id" and apres[c] != avant[c])
        if remise:
            sql(f"UPDATE {TABLE} SET {remise} WHERE id = 1;")
        lib.php_glpi("PluginPrintgestionMbeclient::resetMemo();")
        if memo_avant == 0:
            sql("DELETE FROM glpi_configs WHERE context = 'plugin:printgestion' AND name LIKE 'mbe\\_%';")
    return lib.bilan()


if __name__ == "__main__":
    sys.exit(main())
