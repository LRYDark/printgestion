"""Données réelles : la chasse du bloc 3 rejouée à chaque passe sur tout le dépôt (code, tests, documentation).

Un contrôle joué une fois cesse d'être vrai le lendemain. Les motifs et la liste blanche de la chasse vivent donc ici :
adresse mail, IPv4 hors plages de test, nom d'hôte ou URL, téléphone, SIRET, SIREN, numéro de suivi, code client ou
colis, secret, clé longue. Toute valeur hors liste blanche est un KO, avec la valeur et ses fichiers.
Les noms propres ne se décident pas par motif : le harnais relève les paires de mots capitalisés (deux mots
capitalisés qui se suivent, avec ou sans particule), signale À NOTER celles apparues depuis la dernière passe, à
relire à la main, puis réécrit la liste de référence reference/mots-capitalises.txt, suivie par git : les
nouveautés se relisent aussi dans le diff.
Section 0 : chaque motif reconnaît d'abord un témoin inventé, assemblé à l'exécution pour ne pas figurer dans ce
fichier ; un motif qui ne reconnaîtrait plus rien serait un contrôle vide.
Ne touche pas la base.
"""
import os
import re
import sys
import urllib.parse
import zipfile

from lib import bilan, constat, ok_ko, section

ICI = os.path.dirname(os.path.abspath(__file__))
DEPOT = os.path.abspath(os.path.join(ICI, "..", ".."))
REFERENCE = os.path.join(ICI, "reference", "mots-capitalises.txt")
DOSSIERS_IGNORES = {".git", "vendor", "node_modules"}
BINAIRES = (".png", ".jpg", ".jpeg", ".gif", ".ico", ".woff", ".woff2", ".ttf", ".exe", ".msi", ".pkg", ".zip", ".gz", ".tar",
            ".bundle", ".phar", ".pdf", ".pyc")
OFFICE = (".docx", ".xlsx", ".odt")
# Hors relevé des noms propres : le texte de la licence (FSF, pas le nôtre), les relevés JSON, la liste elle-même.
HORS_NOMS_PROPRES = ("LICENSE", os.path.relpath(REFERENCE, DEPOT))

HOTE = r"[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+"
MOTIFS = {
    "adresse mail":         re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}"),
    "IPv4":                 re.compile(r"\b(?:\d{1,3}\.){3}\d{1,3}\b"),
    "nom d'hôte ou URL":    re.compile(r"https?://[^\s'\"<>)\]`]+|\b" + HOTE
                                       + r"\.(?:fr|com|net|org|eu|io|de|be|ch|uk|info|biz|app|dev|test|invalid|example|local|lan)\b", re.I),
    "téléphone":            re.compile(r"(?:\+33\s?|\b0)[1-9](?:[\s.-]?\d{2}){4}\b"),
    "SIRET":                re.compile(r"\b\d{14}\b"),
    "SIREN":                re.compile(r"\b\d{9}\b"),
    "numéro de suivi":      re.compile(r"\b\d{11,13}\b"),
    "code client ou colis": re.compile(r"\b[A-Z0-9][A-Z0-9-]{5,}\b"),  # retenu seulement avec lettre ET chiffre
    "secret":               re.compile(r"['\"]?(?:password|passwd|mot de passe|secret|apikey|api_key|token)['\"]?\s*(?:=>|[:=])\s*['\"]([^'\"]{4,})['\"]", re.I),
    "clé longue":           re.compile(r"(?<![A-Za-z0-9+/=-])[A-Za-z0-9+/=-]{40,}(?![A-Za-z0-9+/=-])"),  # retenu avec ≥ 3 chiffres et ≥ 3 lettres
}
# Liste blanche de la chasse du bloc 3 (valeur entière, après normalisation : hôte seul pour une URL, valeur seule pour
# un secret). Plages IP de test : boucle locale, TEST-NET (RFC 5737), 10.0.0.x, 192.168.{0,1,10,100}.x, réseaux en .0.
LISTE_BLANCHE = {
    "adresse mail":         re.compile(r"[^@]+@(?:(?:[a-z0-9-]+\.)*(?:exemple\.test|example\.(?:com|org|net))|[a-z0-9.-]+\.(?:test|invalid|example)|localhost)|noreply@.*", re.I),
    "IPv4":                 re.compile(r"127\.0\.0\.1|0\.0\.0\.0|255\.255\.255\.\d+|192\.0\.2\.\d+|198\.51\.100\.\d+|203\.0\.113\.\d+|10\.0\.0\.\d+"
                                       r"|192\.168\.(?:0|1|10|100)\.\d+|1\.\d+\.\d+\.\d+|\d+\.\d+\.\d+\.0"),
    # Domaines réservés (RFC 2606) ; services publics appelés ou cités par le plugin ; site de l'ayant droit (plugin.xml).
    "nom d'hôte ou URL":    re.compile(r"(?:[a-z0-9-]+\.)*(?:exemple\.test|example\.(?:com|org|net))|[a-z0-9.-]+\.(?:test|invalid|example|local|lan)|localhost"
                                       r"|api\.gls-group\.(?:net|eu)|(?:api\.)?github\.com|(?:[a-z]+\.)?glpi-project\.org|packagist\.org|(?:www\.)?php\.net"
                                       r"|getcomposer\.org|schemas\.(?:openxmlformats\.org|microsoft\.com)|purl\.org|www\.w3\.org|www\.gnu\.org|fsf\.org"
                                       r"|nsis\.sourceforge\.io|sourceforge\.net|brew\.sh|(?:www|developer)\.apple\.com|dev\.mysql\.com|mariadb\.org"
                                       r"|www\.openssl\.org|(?:fonts|cdn|docs)\.[a-z0-9.-]+|xml\.apache\.org|www\.ecma-international\.org|www\.jcd-groupe\.fr"
                                       r"|%s|\{[a-z_]+\}|\d+\.\d+\.\d+\.\d+", re.I),  # une adresse IP est jugée par le motif IPv4
    "téléphone":            re.compile(r"(?!)"),
    "SIRET":                re.compile(r"(?!)"),
    "SIREN":                re.compile(r"(?!)"),
    "numéro de suivi":      re.compile(r"9(?:0{8})\d{2}"),  # numéros inventés du test GLS : 9 puis des zéros
    # Valeurs de test (TST, TEST, INVENTÉ), couleurs hexadécimales, exemples de format « BL000123 » de l'interface,
    # mot-clé SMTP, pannes simulées du test GLS, normes citées (PSR-16, SHA-256).
    "code client ou colis": re.compile(r".*(?:TST|TEST|INVENT).*|[A-F0-9]{6}|BL000(?:123|456)|8BITMIME|PANNE\d+[A-Z]?|(?:PSR|SHA)-\d+"),
    # Variable de shell, NULL du relevé, balise ##…##, gabarit {…} ou %s, valeur de test.
    "secret":               re.compile(r"\$[A-Za-z_].*|NULL|##[a-z0-9_.]+##|\{[^}]*\}|%s|.*(?:tst|test|exemple|factice|invent).*", re.I),
    "clé longue":           re.compile(r".*(?:TST|test).*"),
}
# Témoins inventés, assemblés à l'exécution : aucun ne figure tel quel dans ce fichier.
TEMOINS = {
    "adresse mail":         "contact@" + "entreprise-fictive." + "fr",
    "IPv4":                 "172.20." + "5.9",
    "nom d'hôte ou URL":    "https://" + "intranet.entreprise-fictive." + "fr/glpi",
    "téléphone":            "0" + "3 87 00 00 00",
    "SIRET":                "1234567" + "8901234",
    "SIREN":                "12345" + "6789",
    "numéro de suivi":      "12345" + "678901",
    "code client ou colis": "CLIENT" + "0042",
    "secret":               "".join(["password = '", "hunter", "2024'"]),
    "clé longue":           "AbCd" + "1234" * 10,
}
PAIRE = re.compile(r"\b([A-ZÀ-Ý][A-Za-zÀ-ÿ]+(?:[ -](?:d[eu]s?|d'|l[ae]s?|sur|en|et|à|aux?|la|le|les|du|des|de)[ ']?)?[ -][A-ZÀ-Ý][A-Za-zÀ-ÿ]+)\b")


def fichiers():
    """(chemin relatif, texte) de chaque fichier texte du dépôt ; les documents Office sont lus dézippés."""
    for dossier, sous_dossiers, noms in os.walk(DEPOT):
        sous_dossiers[:] = sorted(s for s in sous_dossiers if s not in DOSSIERS_IGNORES)
        for nom in sorted(noms):
            chemin = os.path.join(dossier, nom)
            rel = os.path.relpath(chemin, DEPOT)
            if nom.startswith("._") or nom.endswith(BINAIRES):
                continue
            if nom.endswith(OFFICE):
                try:
                    with zipfile.ZipFile(chemin) as z:
                        for n in z.namelist():
                            if n.endswith(".xml"):
                                yield f"{rel}::{n}", re.sub(r"<[^>]+>", " ", z.read(n).decode("utf-8", "ignore"))
                except zipfile.BadZipFile:
                    pass
                continue
            with open(chemin, encoding="utf-8", errors="ignore") as f:
                yield rel, f.read()


def normaliser(motif, m):
    """Valeur comparée à la liste blanche : hôte d'une URL, valeur d'un secret, la trouvaille sinon ; None = ignorée."""
    valeur = m.group(0)
    if motif == "nom d'hôte ou URL":
        if valeur.lower().startswith("http"):
            try:
                valeur = urllib.parse.urlsplit(valeur).hostname or valeur
            except ValueError:
                pass
        return valeur.lower()
    if motif == "secret":
        return m.group(1)
    if motif == "code client ou colis":
        # Lettre ET chiffre ; ni une date ISO (2026-09-17T10), ni un fragment de classe de caractères (A-Z0-9).
        if not (re.search(r"[A-Z]", valeur) and re.search(r"\d", valeur)) or re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}", valeur) \
                or any(len(segment) < 2 for segment in valeur.split("-")):
            return None
    if motif == "clé longue" and not (len(re.findall(r"\d", valeur)) >= 3 and len(re.findall(r"[A-Za-z]", valeur)) >= 3):
        return None
    return valeur


def detecter(texte):
    """{motif: (refusées, acceptées)} : valeurs normalisées hors liste blanche, et celles que la liste accepte."""
    resultat = {}
    for motif, rx in MOTIFS.items():
        refusees, acceptees = set(), set()
        for m in rx.finditer(texte):
            valeur = normaliser(motif, m)
            if valeur is None:
                continue
            (acceptees if LISTE_BLANCHE[motif].fullmatch(valeur) else refusees).add(valeur)
        resultat[motif] = (refusees, acceptees)
    return resultat


def paires(texte):
    trouvees = set()
    for p in PAIRE.findall(texte):
        mots = re.findall(r"[A-Za-zÀ-ÿ]+", p)
        if any(re.search(r"[a-zà-ÿ]", mot) for mot in (mots[0], mots[-1])):  # deux sigles (SELECT COUNT) : pas un nom
            trouvees.add(p)
    return trouvees


def lire_reference():
    if not os.path.exists(REFERENCE):
        return None
    with open(REFERENCE, encoding="utf-8") as f:
        return {ligne.rstrip("\n") for ligne in f if ligne.strip() and not ligne.startswith("#")}


def ecrire_reference(relevees):
    with open(REFERENCE, "w", encoding="utf-8") as f:
        f.write("# Paires de mots capitalisés relevées dans le dépôt par tests/securite/donnees_reelles.py ; réécrit à chaque passe.\n")
        f.write("# Une paire nouvelle est signalée À NOTER par le harnais et se relit ici, dans le diff git. Ne pas éditer à la main.\n")
        for p in sorted(relevees):
            f.write(p + "\n")


def main():
    section("0. Témoins : chaque motif reconnaît une valeur inventée hors liste blanche")
    for motif, temoin in TEMOINS.items():
        refusees, _ = detecter(temoin)[motif]
        constat(f"motif « {motif} » : le témoin est refusé", ok_ko(len(refusees) == 1), "" if len(refusees) == 1 else f"refusées {refusees!r}")

    section("1. Motifs de données réelles sur tout le dépôt, liste blanche comprise")
    refusees = {motif: {} for motif in MOTIFS}
    acceptees = {motif: set() for motif in MOTIFS}
    relevees = {}
    nb_fichiers, setup_vu = 0, False
    for rel, texte in fichiers():
        nb_fichiers += 1
        setup_vu = setup_vu or rel == "setup.php"
        for motif, (refus, accept) in detecter(texte).items():
            acceptees[motif] |= accept
            for valeur in refus:
                refusees[motif].setdefault(valeur, []).append(rel)
        if rel not in HORS_NOMS_PROPRES and not rel.endswith(".json"):
            for p in paires(texte):
                relevees.setdefault(p, []).append(rel)
    constat(f"dépôt parcouru : {nb_fichiers} fichiers texte sous {DEPOT}", ok_ko(nb_fichiers > 100 and setup_vu))
    for motif in MOTIFS:
        trouvailles = refusees[motif]
        constat(f"{motif} : {len(trouvailles)} valeur(s) hors liste blanche", ok_ko(not trouvailles),
                "; ".join(f"{v!r} ({', '.join(fichiers_[:4])})" for v, fichiers_ in sorted(trouvailles.items())[:20]) if trouvailles
                else f"{len(acceptees[motif])} valeur(s) distincte(s) acceptée(s) par la liste blanche")

    section("2. Noms propres : paires de mots capitalisés apparues depuis la dernière passe, à relire à la main")
    reference = lire_reference()
    if reference is None:
        constat(f"première passe : {len(relevees)} paires relevées, liste de référence créée, à relire en entier", "À NOTER", os.path.relpath(REFERENCE, DEPOT))
    else:
        nouvelles = sorted(set(relevees) - reference)
        disparues = reference - set(relevees)
        constat(f"paires nouvelles depuis la dernière passe : {len(nouvelles)} (relevées {len(relevees)}, disparues {len(disparues)})",
                "À NOTER" if nouvelles else "OK",
                "; ".join(f"« {p} » ({', '.join(relevees[p][:2])})" for p in nouvelles[:40]) + (" …" if len(nouvelles) > 40 else ""))
    ecrire_reference(relevees)
    return bilan()


if __name__ == "__main__":
    sys.exit(main())
