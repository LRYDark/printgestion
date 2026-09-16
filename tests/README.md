# Tests de Print Gestion

Harnais de sécurité, rejoué après chaque correction. Il s'exécute **uniquement sur une instance GLPI jetable** et ne
contient ni donnée réelle, ni identifiant.

| Dossier | Contenu |
|---|---|
| `securite/` | Harnais, jeu de données inventé, reconstruction de l'instance de test |
| `simulations/` | Plugin Gestion factice et faux serveur SMTP ; **jamais sur un serveur de production** (voir son README) |

## Règles du dossier

- **Aucune donnée réelle** : entités « Client test A », « Site test A1 », « Site test A2 », « Client test B » ;
  imprimantes `TST-…`, références `TST-REF-…`, codes Sage `TSTCLI01` / `TSTLIV01`, adresses `@exemple.test`.
- **Aucun identifiant** : pas de mot de passe, de clé ni d'adresse de serveur, même en exemple. Tout vient des variables
  d'environnement ci-dessous. Les comptes créés par les tests reçoivent un mot de passe tiré au hasard à chaque passage.
- **Déterministe** : `securite/donnees.py` crée tous les identifiants attendus (entités 1 à 4, imprimantes 1 à 12,
  contrats 1 et 2…) sur une base neuve et s'arrête si un identifiant ne tombe pas juste. Ce qu'un test crée en plus,
  il le supprime à la fin.
- **Garde-fou** : `instance.sh` exige `PG_TEST_INSTANCE_JETABLE=oui` et un nom de base contenant « test » ; chaque
  test refuse une base qui ne porte pas le marqueur posé par `donnees.py`.

## Non servi par le web

GLPI 11 n'expose d'un plugin que les scripts PHP de `ajax/`, `front/` et `report/`, et les fichiers de `public/`
(`src/Glpi/Http/RequestRouterTrait.php`). Le dossier `tests/` n'est donc ni servi ni exécuté quand la racine web du
serveur est `public/`, comme GLPI 11 l'exige. La section 7 du harnais le vérifie à chaque passage : chaque fichier de
`tests/` demandé par URL, avec et sans session, ne doit jamais revenir.

## Variables d'environnement

| Variable | Rôle |
|---|---|
| `PG_TEST_INSTANCE_JETABLE` | `oui` : confirme que la base peut être effacée (instance.sh) |
| `PG_TEST_URL` | URL de l'instance de test (défaut `http://127.0.0.1:8089`) |
| `PG_TEST_GLPI_DIR` | Dossier de GLPI 11 extrait de l'archive officielle |
| `PG_TEST_PHP`, `PG_TEST_MARIADB` | Binaires (défaut `php`, `mariadb`) |
| `PG_TEST_DB_HOST`, `PG_TEST_DB_PORT`, `PG_TEST_DB_NAME`, `PG_TEST_DB_USER`, `PG_TEST_DB_PASSWORD` | Base de test (le nom doit contenir « test ») |
| `PG_TEST_GLPI_LOGIN`, `PG_TEST_GLPI_PASSWORD` | Compte administrateur de l'instance ; instance.sh lui donne ce mot de passe |
| `PG_TEST_GLPIINVENTORY_DIR` | Dossier du plugin GLPI Inventory à installer |
| `PG_TEST_MAIL_DIR`, `PG_TEST_SMTP_PORT` | Dossier des mails capturés, port du faux SMTP (défaut 2525) |

Garder ces variables dans un fichier hors du dépôt.

## Lancer

```sh
python3 tests/simulations/smtp_factice.py "$PG_TEST_MAIL_DIR" "$PG_TEST_SMTP_PORT" &   # faux SMTP
tests/securite/instance.sh                   # base neuve, GLPI, plugins, jeu de données
php -S 127.0.0.1:8089 -t "$PG_TEST_GLPI_DIR/public" "$PG_TEST_GLPI_DIR/public/index.php" &
python3 tests/securite/lancer.py             # tous les tests, ou : lancer.py harnais bl
```

Le serveur SMTP de l'instance est réglé sur 127.0.0.1 par `donnees.py` : aucun mail ne sort.

## Tests

| Fichier | Vérifie |
|---|---|
| `harnais.py` | XSS, cloisonnement entre clients, points d'entrée sans droit (Self-Service, central sans droit du plugin), écritures en GET, échappement des mails, clés API, dossier `tests/` non servi |
| `bl.py` | BL : entité de l'imprimante ou parente seulement, jamais une entité sœur ni un autre client |
| `gesconso.py` | Aucune commande aux Achats sans le fichier Gesconso joint |
| `parcours.py` | Chaque page et point d'entrée : ni erreur PHP ou SQL, ni page blanche |

Chaque constat est `OK`, `KO`, `À NOTER` ou `NON CONCLUANT` ; `lancer.py` résume et relève les erreurs des journaux GLPI.
