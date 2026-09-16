"""Outils communs du harnais de sécurité : session HTTP GLPI (jeton CSRF), base de test, résultats, objets de test.

Tout objet créé par un test passe par Contexte, qui le supprime à la fin (Contexte.nettoyer()).
"""
import email
import email.policy
import glob
import html
import http.cookiejar
import io
import os
import re
import secrets
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from html.parser import HTMLParser

import config

# ── Résultats ────────────────────────────────────────────────────────────────

RESULTATS = []
_section = ["—"]


def section(titre):
    _section[0] = titre
    print(f"\n======== {titre}")


def constat(libelle, verdict, detail=""):
    """verdict : OK, KO, À NOTER ou NON CONCLUANT."""
    RESULTATS.append((_section[0], verdict, libelle, detail))
    print(f"    {verdict:13} {libelle}" + (f" — {detail}" if detail else ""))


def verifier(libelle, obtenu, attendu):
    ok = obtenu == attendu
    constat(libelle, "OK" if ok else "KO", "" if ok else f"attendu {attendu!r}, obtenu {obtenu!r}")
    return ok


def ok_ko(condition):
    return "OK" if condition else "KO"


def bilan():
    print("\n======== Bilan")
    for section_titre, verdict, libelle, detail in RESULTATS:
        if verdict != "OK":
            print(f"{verdict:13} [{section_titre}] {libelle}" + (f" — {detail}" if detail else ""))
    compte = {v: sum(1 for r in RESULTATS if r[1] == v) for v in ("OK", "KO", "À NOTER", "NON CONCLUANT")}
    print("Résultat : " + " ; ".join(f"{v} {n}" for v, n in compte.items()))
    return 1 if compte["KO"] or compte["NON CONCLUANT"] else 0


# ── Base de test ─────────────────────────────────────────────────────────────

def sql(requete):
    """Requête sur la base de test ; le mot de passe passe par MYSQL_PWD, jamais par la ligne de commande."""
    env = dict(os.environ, MYSQL_PWD=config.DB_PASSWORD)
    res = subprocess.run([config.MARIADB, "-h", config.DB_HOST, "-P", config.DB_PORT, "-u", config.DB_USER, "-B", config.DB_NAME, "-e", requete],
                         capture_output=True, text=True, env=env)
    erreurs = [l for l in res.stderr.splitlines() if "ssl-verify" not in l and "Using a password" not in l]
    if res.returncode != 0 or any(l.startswith("ERROR") for l in erreurs):
        raise RuntimeError(f"SQL en échec : {' '.join(erreurs)[:500]} — {requete[:200]}")
    return res.stdout


def lignes(requete):
    sortie = sql(requete).splitlines()
    return [ligne.split("\t") for ligne in sortie[1:]] if sortie else []


def valeur(requete):
    trouve = lignes(requete)
    return trouve[0][0] if trouve else None


def q(texte):
    return "NULL" if texte is None or texte == "NULL" else "'" + str(texte).replace("\\", "\\\\").replace("'", "\\'") + "'"


def decoder(texte):
    """Sortie « mariadb -B » : \\n, \\t et \\\\ échappés."""
    return re.sub(r"\\(.)", lambda m: {"n": "\n", "t": "\t", "0": "\0", "\\": "\\"}.get(m.group(1), m.group(0)), texte)


def tache(nom):
    """Lance une tâche automatique GLPI et renvoie son dernier message."""
    subprocess.run([config.PHP, "front/cron.php", "--force", nom], cwd=config.GLPI_DIR, capture_output=True, text=True)
    return valeur("SELECT l.content FROM glpi_crontasklogs l JOIN glpi_crontasks t ON t.id = l.crontasks_id "
                  f"WHERE t.name = '{nom}' AND l.content NOT LIKE 'Action%' AND l.content <> '' ORDER BY l.id DESC LIMIT 1") or ""


def php_glpi(code):
    """Exécute du PHP dans GLPI démarré (sans session) et renvoie ce qu'il affiche."""
    amorce = "chdir(getenv('PG_GLPI')); require 'vendor/autoload.php'; (new \\Glpi\\Kernel\\Kernel())->boot(); "
    res = subprocess.run([config.PHP, "-r", amorce + code], capture_output=True, text=True, env=dict(os.environ, PG_GLPI=config.GLPI_DIR))
    if res.returncode != 0:
        raise RuntimeError(f"PHP en échec : {(res.stderr or res.stdout)[-400:]}")
    return res.stdout


def vider_cache():
    subprocess.run([config.PHP, "bin/console", "cache:clear", "-n"], cwd=config.GLPI_DIR, capture_output=True)


# ── Session HTTP ─────────────────────────────────────────────────────────────

def transferer_imprimante(imprimante, entite):
    """Transfert natif (liste de transfert puis « Transférer »), avec la configuration « complete » de GLPI comme
    l'écran natif ; session administrateur requise."""
    WEB.action_de_masse("Printer", [imprimante], "add_transfer_list", "MassiveAction")
    options = dict(zip([r for r in sql("SELECT * FROM glpi_transfers WHERE id = 1").splitlines()[0].split("\t")],
                       lignes("SELECT * FROM glpi_transfers WHERE id = 1")[0]))
    champs = [(k, v) for k, v in options.items() if k.startswith(("keep_", "clean_", "lock_"))]
    statut = WEB.post("/front/transfer.action.php", champs + [("transfer", "1"), ("to_entity", str(entite)), ("id", "1")])[0]
    if valeur(f"SELECT entities_id FROM glpi_printers WHERE id = {imprimante}") != str(entite):
        raise RuntimeError(f"Transfert de l'imprimante #{imprimante} vers l'entité #{entite} sans effet (HTTP {statut})")


class _SansRedirection(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.suivre = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.rester = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), _SansRedirection)

    def brut(self, methode, chemin, donnees=None, entetes=None, redirections=False, octets=False):
        corps = urllib.parse.urlencode(donnees, doseq=True).encode() if donnees is not None else None
        req = urllib.request.Request(config.URL + chemin, data=corps, method=methode, headers=entetes or {})
        ouvreur = self.suivre if redirections else self.rester
        try:
            rep = ouvreur.open(req, timeout=180)
            statut, contenu, en_tetes = rep.status, rep.read(), rep.headers
        except urllib.error.HTTPError as err:
            statut, contenu, en_tetes = err.code, err.read(), err.headers
        return statut, (contenu if octets else contenu.decode("utf-8", "replace")), en_tetes

    def connecter(self, login, mot_de_passe):
        self.jar.clear()
        _, page, _ = self.brut("GET", "/index.php", redirections=True)
        jeton = re.search(r'name="_glpi_csrf_token" value="([^"]+)"', page)
        if not jeton:
            raise RuntimeError("Page de connexion GLPI illisible")
        _, page, _ = self.brut("POST", "/front/login.php", {"login_name": login, "login_password": mot_de_passe, "_glpi_csrf_token": jeton.group(1),
                                                            "noAUTO": "1", "redirect": "", "submit": ""}, redirections=True)
        if 'name="login_password"' in page:
            raise RuntimeError(f"Connexion refusée pour {login}")

    def jeton(self):
        _, page, _ = self.brut("GET", "/front/central.php", redirections=True)
        trouve = re.search(r'property="glpi:csrf_token" content="([^"]+)"', page)
        return trouve.group(1) if trouve else ""

    def get(self, chemin, champs=(), ajax=False):
        if champs:
            chemin += ("&" if "?" in chemin else "?") + urllib.parse.urlencode(list(champs))
        return self.brut("GET", chemin, None, {"X-Requested-With": "XMLHttpRequest"} if ajax else {})

    def post(self, chemin, champs=(), ajax=False):
        champs = list(champs)
        entetes = {}
        jeton = self.jeton()
        if ajax:
            entetes = {"X-Requested-With": "XMLHttpRequest", "X-Glpi-Csrf-Token": jeton}
        else:
            champs.append(("_glpi_csrf_token", jeton))
        return self.brut("POST", chemin, champs, entetes)

    def envoyer_fichier(self, chemin, champs, nom_champ, nom_fichier, contenu):
        """POST multipart (dépôt de fichier) avec un jeton CSRF neuf ; renvoie statut, page, en-têtes (redirection non suivie)."""
        limite = "----pgtest" + secrets.token_hex(12)
        parties = []
        for nom, val in list(champs) + [("_glpi_csrf_token", self.jeton())]:
            parties.append(f'--{limite}\r\nContent-Disposition: form-data; name="{nom}"\r\n\r\n{val}\r\n'.encode())
        parties.append(f'--{limite}\r\nContent-Disposition: form-data; name="{nom_champ}"; filename="{nom_fichier}"\r\n'
                       f'Content-Type: application/octet-stream\r\n\r\n'.encode() + contenu + b"\r\n")
        parties.append(f"--{limite}--\r\n".encode())
        req = urllib.request.Request(config.URL + chemin, data=b"".join(parties), method="POST",
                                     headers={"Content-Type": f"multipart/form-data; boundary={limite}"})
        try:
            rep = self.rester.open(req, timeout=180)
            return rep.status, rep.read().decode("utf-8", "replace"), rep.headers
        except urllib.error.HTTPError as err:
            return err.code, err.read().decode("utf-8", "replace"), err.headers

    def telecharger(self, chemin):
        statut, contenu, _ = self.brut("GET", chemin, octets=True)
        return statut, contenu

    def messages(self):
        _, page, _ = self.brut("GET", "/front/central.php", redirections=True)
        return " | ".join(m for m in messages(page) if "Astuce" not in m)

    def action_de_masse(self, itemtype, ids, action, processeur, extra=()):
        champs = []
        for item_id in ids:
            champs += [(f"items[{itemtype}][{item_id}]", str(item_id)), (f"initial_items[{itemtype}][{item_id}]", str(item_id))]
        champs += [("action", action), ("action_name", action), ("processor", processeur), ("is_deleted", "0")] + list(extra) + [("massiveaction", "1")]
        return self.post("/front/massiveaction.php", champs)[0]


WEB = Session()


def connecter_admin():
    WEB.connecter(config.GLPI_LOGIN, config.GLPI_PASSWORD)


# ── Lecture de pages ─────────────────────────────────────────────────────────

def messages(page):
    trouves = []
    for motif in (r'<div[^>]*class="[^"]*toast-body[^"]*"[^>]*>(.*?)</div>', r'<div[^>]*class="[^"]*\balert\b[^"]*"[^>]*>(.*?)</div>'):
        for m in re.finditer(motif, page, re.S):
            texte = re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", m.group(1)))).strip()
            if texte and texte not in trouves:
                trouves.append(texte[:400])
    return trouves


def texte(page):
    page = re.sub(r"<script.*?</script>", " ", page, flags=re.S)
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", page)))


def refus(statut, contenu):
    return statut in (401, 403, 404, 405) or re.search(r'"ok"\s*:\s*false', contenu or "") is not None


class Balises(HTMLParser):
    """Balises, attributs décodés et contenu des scripts, tels que l'analyseur HTML d'un navigateur les découpe."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.balises, self.scripts, self._script = [], [], None

    def handle_starttag(self, tag, attrs):
        self.balises.append((tag, dict(attrs)))
        if tag == "script":
            self._script = ""

    def handle_startendtag(self, tag, attrs):
        self.balises.append((tag, dict(attrs)))

    def handle_data(self, data):
        if self._script is not None:
            self._script += data

    def handle_endtag(self, tag):
        if tag == "script" and self._script is not None:
            self.scripts.append(self._script)
            self._script = None


class Formulaire(HTMLParser):
    """Champs d'un formulaire HTML (valeurs courantes), pour le renvoyer tel quel."""

    def __init__(self, action):
        super().__init__()
        self.action, self.dedans, self.champs, self.select, self.zone = action, False, [], None, None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "form" and self.action in (a.get("action") or ""):
            self.dedans = True
        if not self.dedans:
            return
        if tag == "input":
            nom, genre = a.get("name"), (a.get("type") or "text").lower()
            if nom and genre not in ("submit", "button", "file", "image", "reset") and (genre not in ("checkbox", "radio") or "checked" in a):
                self.champs.append((nom, a.get("value") or ""))
        elif tag == "select":
            self.select = {"nom": a.get("name"), "multiple": "multiple" in a, "options": [], "choisies": []}
        elif tag == "option" and self.select is not None:
            self.select["options"].append(a.get("value") or "")
            if "selected" in a:
                self.select["choisies"].append(a.get("value") or "")
        elif tag == "textarea":
            self.zone = [a.get("name"), ""]

    def handle_data(self, data):
        if self.zone is not None:
            self.zone[1] += data

    def handle_endtag(self, tag):
        if not self.dedans:
            return
        if tag == "select" and self.select is not None:
            if self.select["nom"]:
                valeurs = self.select["choisies"] if self.select["multiple"] else (self.select["choisies"][:1] or self.select["options"][:1])
                self.champs.extend((self.select["nom"], v) for v in valeurs)
            self.select = None
        elif tag == "textarea" and self.zone is not None:
            self.champs.append(tuple(self.zone))
            self.zone = None
        elif tag == "form":
            self.dedans = False


def texte_xlsx(contenu):
    try:
        with zipfile.ZipFile(io.BytesIO(contenu)) as archive:
            return " ".join(archive.read(n).decode("utf-8", "replace") for n in archive.namelist() if n.endswith(".xml"))
    except zipfile.BadZipFile:
        return contenu.decode("utf-8", "replace")


def mails_depuis(instant):
    trouves = []
    for chemin in sorted(glob.glob(os.path.join(config.MAIL_DIR, "*.eml"))):
        if os.path.getmtime(chemin) >= instant:
            with open(chemin, "rb") as fichier:
                message = email.message_from_bytes(fichier.read(), policy=email.policy.default)
            corps = message.get_body(preferencelist=("html",))
            trouves.append({"sujet": str(message["Subject"] or ""), "pour": message.get("X-Env-To", ""),
                            "fichiers": [p.get_filename() for p in message.iter_attachments()], "html": corps.get_content() if corps else ""})
    return trouves


# ── Journaux ─────────────────────────────────────────────────────────────────

def tailles_journaux():
    return {n: os.path.getsize(os.path.join(config.LOG_DIR, n)) if os.path.exists(os.path.join(config.LOG_DIR, n)) else 0
            for n in ("php-errors.log", "sql-errors.log", "printgestion.log")}


def journal_depuis(tailles, nom):
    chemin = os.path.join(config.LOG_DIR, nom)
    if not os.path.exists(chemin):
        return ""
    with open(chemin, encoding="utf-8", errors="replace") as fichier:
        fichier.seek(tailles.get(nom, 0))
        return fichier.read()


def erreurs_php_depuis(tailles, attendues=()):
    """Entrées ERROR / CRITICAL du journal PHP de GLPI, hors celles attendues par le test."""
    contenu = journal_depuis(tailles, "php-errors.log")
    entrees = re.findall(r"^\[[^\]]+\] glpi\.(?:ERROR|CRITICAL):.*$", contenu, re.M)
    return [e[:250] for e in entrees if not any(a in e for a in attendues)] + ([contenu[:250]] if journal_depuis(tailles, "sql-errors.log").strip() else [])


# ── Objets de test ───────────────────────────────────────────────────────────

class Contexte:
    """Objets créés pour un test et leur suppression. Mots de passe des comptes de test tirés au hasard à chaque passage."""

    def __init__(self):
        self.crees = {k: [] for k in ("entites", "imprimantes", "expeditions", "alertes", "contrats", "utilisateurs", "profils", "bl", "demandes")}
        self.mots_de_passe = {}

    def entite(self, nom, parente=0):
        """Entité créée par le formulaire natif (caches d'entités cohérents) ; session administrateur requise."""
        WEB.post("/front/entity.form.php", [("name", nom), ("entities_id", str(parente)), ("add", "1")])
        ident = valeur(f"SELECT id FROM glpi_entities WHERE name = {q(nom)} AND entities_id = {int(parente)}")
        if not ident:
            raise RuntimeError(f"Entité de test {nom} non créée")
        self.crees["entites"].append(int(ident))
        return int(ident)

    def imprimante(self, nom, entite, recursive=0):
        sql(f"INSERT INTO glpi_printers (name, entities_id, is_recursive, is_deleted, is_template, date_creation, date_mod) VALUES ({q(nom)}, {entite}, {recursive}, 0, 0, NOW(), NOW());")
        ident = int(valeur(f"SELECT MAX(id) FROM glpi_printers WHERE name = {q(nom)}"))
        self.crees["imprimantes"].append(ident)
        return ident

    def expedition(self, imprimante, propriete, suivi=None, statut="pending"):
        """Comme le plugin : entité et récursivité de l'imprimante à la création."""
        sql("INSERT INTO glpi_plugin_printgestion_expeditions (printers_id, toner_property, statut, transport_number, transport_carrier, date_alert, entities_id, is_recursive) "
            f"SELECT {imprimante}, {q(propriete)}, {q(statut)}, {q(suivi)}, 'other', NOW(), entities_id, is_recursive FROM glpi_printers WHERE id = {imprimante};")
        ident = int(valeur(f"SELECT MAX(id) FROM glpi_plugin_printgestion_expeditions WHERE printers_id = {imprimante} AND toner_property = {q(propriete)}"))
        self.crees["expeditions"].append(ident)
        return ident

    def alerte_mauvaise_imprimante(self, detectee, prevue, expedition):
        sql("INSERT INTO glpi_plugin_printgestion_alerts (printers_id, toner_property, level_percent, alert_type, date_alert, mail_sent, is_resolved, "
            "intended_printers_id, detected_printers_id, expeditions_id, entities_id, is_recursive) "
            f"SELECT {detectee}, 'test', 90, 'wrong_printer', NOW(), 0, 0, {prevue}, {detectee}, {expedition}, entities_id, is_recursive FROM glpi_printers WHERE id = {detectee};")
        ident = int(valeur("SELECT MAX(id) FROM glpi_plugin_printgestion_alerts"))
        self.crees["alertes"].append(ident)
        return ident

    def bl(self, numero, entite):
        sql(f"INSERT INTO glpi_plugin_gestion_surveys (entities_id, bl, bl_number, signed, save, date_creation) VALUES ({entite}, {q(numero)}, {q(numero)}, 0, 'Test', NOW());")
        ident = int(valeur(f"SELECT id FROM glpi_plugin_gestion_surveys WHERE bl_number = {q(numero)}"))
        self.crees["bl"].append(ident)
        return ident

    def profil(self, source, nom, droits):
        sql(f"DROP TEMPORARY TABLE IF EXISTS pg_profil; CREATE TEMPORARY TABLE pg_profil SELECT * FROM glpi_profiles WHERE id = {source}; "
            f"UPDATE pg_profil SET id = 0, name = {q(nom)}; INSERT INTO glpi_profiles SELECT * FROM pg_profil; DROP TEMPORARY TABLE pg_profil;")
        ident = int(valeur(f"SELECT MAX(id) FROM glpi_profiles WHERE name = {q(nom)}"))
        self.crees["profils"].append(ident)
        sql(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT {ident}, name, rights FROM glpi_profilerights WHERE profiles_id = {source};")
        for droit, valeur_droit in droits.items():
            sql(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES ({ident}, {q(droit)}, {valeur_droit}) ON DUPLICATE KEY UPDATE rights = {valeur_droit};")
        return ident

    def utilisateur(self, nom, profil, entite, recursif=1):
        mot_de_passe = secrets.token_urlsafe(18)
        empreinte = subprocess.run([config.PHP, "-r", "echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);"],
                                   input=mot_de_passe, capture_output=True, text=True).stdout.strip()
        sql(f"INSERT INTO glpi_users (name, password, authtype, is_active, profiles_id, entities_id, date_creation, date_mod) VALUES ({q(nom)}, {q(empreinte)}, 1, 1, {profil}, {entite}, NOW(), NOW());")
        ident = int(valeur(f"SELECT id FROM glpi_users WHERE name = {q(nom)}"))
        self.crees["utilisateurs"].append(ident)
        sql(f"INSERT INTO glpi_profiles_users (users_id, profiles_id, entities_id, is_recursive, is_dynamic, is_default_profile) VALUES ({ident}, {profil}, {entite}, {recursif}, 0, 1);")
        self.mots_de_passe[nom] = mot_de_passe
        return ident

    def connecter(self, nom):
        WEB.connecter(nom, self.mots_de_passe[nom])

    def nettoyer(self):
        c = {k: ", ".join(str(i) for i in sorted(set(v))) or "0" for k, v in self.crees.items()}
        connecter_admin()
        sql(f"DELETE FROM glpi_plugin_printgestion_expedition_bls WHERE expeditions_id IN ({c['expeditions']}) OR bl_surveys_id IN ({c['bl']});"
            f"DELETE FROM glpi_plugin_printgestion_alerts WHERE id IN ({c['alertes']}) OR expeditions_id IN ({c['expeditions']}) OR printers_id IN ({c['imprimantes']});"
            f"DELETE FROM glpi_plugin_printgestion_expeditions WHERE id IN ({c['expeditions']}) OR printers_id IN ({c['imprimantes']});"
            f"DELETE FROM glpi_plugin_printgestion_demandelines WHERE plugin_printgestion_demandes_id IN ({c['demandes']});"
            f"DELETE FROM glpi_plugin_printgestion_demandes WHERE id IN ({c['demandes']});"
            f"DELETE FROM glpi_plugin_printgestion_toner_readings WHERE printers_id IN ({c['imprimantes']});"
            f"DELETE FROM glpi_plugin_printgestion_cartridge_history WHERE printers_id IN ({c['imprimantes']});"
            f"DELETE FROM glpi_plugin_printgestion_printer_thresholds WHERE printers_id IN ({c['imprimantes']});"
            f"DELETE FROM glpi_plugin_printgestion_contractrates WHERE contracts_id IN ({c['contrats']});"
            f"DELETE FROM glpi_contracts_items WHERE contracts_id IN ({c['contrats']}) OR (itemtype = 'Printer' AND items_id IN ({c['imprimantes']}));"
            f"DELETE FROM glpi_contracts WHERE id IN ({c['contrats']});"
            f"DELETE FROM glpi_printers WHERE id IN ({c['imprimantes']});"
            f"DELETE FROM glpi_profiles_users WHERE users_id IN ({c['utilisateurs']});"
            f"DELETE FROM glpi_users WHERE id IN ({c['utilisateurs']});"
            f"DELETE FROM glpi_profilerights WHERE profiles_id IN ({c['profils']});"
            f"DELETE FROM glpi_profiles WHERE id IN ({c['profils']});")
        if self.crees["bl"]:
            sql(f"DELETE FROM glpi_plugin_gestion_surveys WHERE id IN ({c['bl']});")
        for entite in sorted(set(self.crees["entites"]), reverse=True):
            WEB.post("/front/entity.form.php", [("id", str(entite)), ("purge", "1")])
            if valeur(f"SELECT COUNT(*) FROM glpi_entities WHERE id = {entite}") != "0":
                raise RuntimeError(f"Entité de test #{entite} non supprimée")


def attendre_fichier(chemin, delai=5.0):
    fin = time.time() + delai
    while time.time() < fin and not os.path.exists(chemin):
        time.sleep(0.1)
    return os.path.exists(chemin)
