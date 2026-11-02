"""Parcours des pages de GLPI, plugin activé.

Connexion en glpi/glpi, puis :
  - accueil, nouveau ticket, ticket existant, utilisateur, configuration,
    entité racine, profil Super-Admin, avec TOUS leurs onglets (c'est là que
    les plugins s'accrochent, et là que les templates Twig cassés se voient :
    le formulaire ticket et le formulaire utilisateur, le 02/11/2026) ;
  - les pages du plugin trouvées dans le menu de GLPI.

Échoue sur une réponse HTTP 500, une page d'erreur GLPI, ou la moindre ligne
nouvelle dans php-errors.log.

Usage : python3 smoke.py <url de base> <clé du plugin> <dossier files/_log de GLPI>
"""
import html
import http.cookiejar
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE, PLUGIN, LOG_DIR = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3]
PHP_ERRORS = os.path.join(LOG_DIR, 'php-errors.log')

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
failures = []
seen = set()


def get(path, data=None):
    url = path if path.startswith('http') else BASE + path
    headers = {'User-Agent': 'plugin-ci', 'Origin': BASE, 'Referer': BASE + '/'}
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, headers=headers)
    try:
        with opener.open(req, timeout=120) as r:
            return r.status, r.read().decode('utf-8', 'replace'), r.geturl()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), url


def log_size():
    return os.path.getsize(PHP_ERRORS) if os.path.exists(PHP_ERRORS) else 0


def check(path, label=None):
    if path in seen:
        return ''
    seen.add(path)
    before = log_size()
    status, body, _ = get(path)
    # GLPI 12 : zone sensible (configuration, utilisateur, profil…) — il redemande le mot de passe avant
    # d'afficher la page (pages/reauth/prompt.html.twig, champ « user_input »).
    if 'name="user_input"' in body:
        form = re.search(r'<form[^>]*method="(\w+)"[^>]*action="([^"]+)"', body) \
            or re.search(r'<form[^>]*action="([^"]+)"[^>]*method="(\w+)"', body)
        if form:
            method, action = (form.group(1), form.group(2)) if form.re.pattern.startswith('<form[^>]*method') \
                else (form.group(2), form.group(1))
            action = html.unescape(action)
            fields = {'user_input': 'glpi'}
            if method.lower() == 'get':
                get(action + ('&' if '?' in action else '?') + urllib.parse.urlencode(fields))
            else:
                get(action, fields)
            status, body, _ = get(path)
    problems = []
    if status >= 500:
        problems.append(f'HTTP {status}')
    for marker in ('An exception has been thrown during the rendering of a template',
                   'Fatal error', 'Uncaught ', 'Une erreur inattendue est survenue',
                   'An unexpected error occurred'):
        if marker in body:
            problems.append(f'« {marker} » dans la page')
    after = log_size()
    if after > before:
        with open(PHP_ERRORS, encoding='utf-8', errors='replace') as f:
            f.seek(before)
            # L'onglet « Système » de la configuration écrit lui-même « *** Test logger » pour vérifier la
            # journalisation : ce n'est pas une erreur.
            lines = [l for l in f.read().splitlines() if l.strip() and '*** Test logger' not in l]
        if lines:
            problems.append('php-errors.log :\n' + '\n'.join(lines))
    print(('FAIL ' if problems else 'OK   ') + f'{status} {label or path}')
    if problems:
        failures.append((label or path, problems))
    return body


def tabs_of(body):
    """URL des onglets d'un formulaire (chargés en Ajax par common.tabs.php)."""
    urls = []
    for m in re.finditer(r'''["'](/[^"']*?ajax/common\.tabs\.php\?[^"']+)["']''', body):
        u = html.unescape(m.group(1))
        if u not in urls:
            urls.append(u)
    return urls


# --- Connexion ---
status, body, _ = get('/index.php')
token = re.search(r'name="_glpi_csrf_token" value="([^"]+)"', body)
form = {'login_name': 'glpi', 'login_password': 'glpi', 'noAUTO': '1', 'redirect': ''}
if token:
    form['_glpi_csrf_token'] = token.group(1)
for m in re.finditer(r'<select[^>]+name="auth"[^>]*>.*?<option value="([^"]+)"', body, re.S):
    form['auth'] = m.group(1)
status, body, final = get('/front/login.php', form)
if 'login_password' in body and 'logout' not in body.lower():
    print(f'Connexion impossible (HTTP {status}, {final})')
    print(body[:3000])
    sys.exit(1)
print('Connexion glpi/glpi OK')

# --- Pages de GLPI et leurs onglets ---
pages = [
    ('/front/central.php', 'Accueil'),
    ('/front/ticket.form.php', 'Nouveau ticket'),
    ('/front/ticket.form.php?id=1', 'Ticket 1'),
    ('/front/user.form.php?id=2', 'Utilisateur glpi'),
    ('/front/config.form.php', 'Configuration générale'),
    ('/front/entity.form.php?id=0', 'Entité racine'),
    ('/front/profile.form.php?id=4', 'Profil Super-Admin'),
]
central = ''
for path, label in pages:
    body = check(path, label)
    if path == '/front/central.php':
        central = body
    if '.form.php' in path and not tabs_of(body):
        titles = [re.sub(r'<[^>]+>', '', t).strip() for t in re.findall(r'<h[1-3][^>]*>(.*?)</h[1-3]>', body, re.S)]
        alerts = [re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', a)).strip()[:200]
                  for a in re.findall(r'<div[^>]+class="[^"]*alert[^"]*"[^>]*>(.*?)</div>', body, re.S)]
        print(f'     (aucun onglet trouvé ; {len(body)} octets ; titres : {titles[:6]} ; alertes : {alerts[:3]})')
    for tab in tabs_of(body):
        check(tab, f'{label} › onglet {urllib.parse.parse_qs(urllib.parse.urlparse(tab).query).get("_glpi_tab", ["?"])[0]}')

# --- Pages du plugin présentes dans le menu ---
plugin_links = sorted(set(
    html.unescape(m.group(1))
    for m in re.finditer(r'''href=["']([^"']*/plugins/%s/[^"'#]+)["']''' % re.escape(PLUGIN), central)
))
print(f'{len(plugin_links)} page(s) du plugin dans le menu')
for link in plugin_links:
    path = link if link.startswith('/') or link.startswith('http') else '/' + link
    body = check(path)
    for tab in tabs_of(body):
        check(tab)

# --- Bilan ---
if failures:
    print(f'\n{len(failures)} page(s) en erreur :')
    for label, problems in failures:
        print(f'\n### {label}')
        for p in problems:
            print(p)
    sys.exit(1)
print(f'\n{len(seen)} page(s) vérifiée(s), aucune erreur.')
