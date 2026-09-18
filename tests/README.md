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
| `PG_TEST_SANS_DONNEES` | `oui` : instance.sh s'arrête après l'installation des plugins, sans le jeu de données (relevé d'installation) |
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

Les tests de l'installeur (`interface.py`, `harnais.py`) ont besoin des installeurs GLPI Agent dans
`files/_plugins/printgestion/agent` de l'instance : page « Installeur GLPI Agent » → « Récupérer depuis GitHub »
pour chaque fichier, ou `PluginPrintgestionAgentdeploy::fetchFromGitHub('windows'|'linux'|'macos-arm64'|'macos-x86_64')`.

Pour copier l'arbre GLPI d'un serveur vers l'instance de test avec `rsync`, ancrer les exclusions à la racine :
`--exclude '/files/*' --exclude '/config/*' --exclude '/plugins/*' --exclude '/marketplace/*'`. Sans le `/` initial,
un motif s'applique à toute profondeur : `config/*` exclut aussi `vendor/symfony/config/*`, et GLPI ne démarre plus
(« Class Symfony\Component\Config\ConfigCache not found »). Constaté le 17/09/2026.

Le harnais ne vide jamais le cache de GLPI : en GLPI 11, la configuration et l'état des plugins sont relus en base à
chaque requête, et un `bin/console cache:clear` pendant les tests efface le conteneur Symfony compilé — une requête
servie pendant sa reconstruction tombe en erreur 500 (constaté une fois, non reproductible à la demande).

Le serveur SMTP de l'instance est réglé sur 127.0.0.1 par `donnees.py` : aucun mail ne sort.

## Tests

| Fichier | Vérifie |
|---|---|
| `harnais.py` | XSS, cloisonnement entre clients, points d'entrée sans droit (Self-Service, central sans droit du plugin), écritures en GET, échappement des mails, clés API, dossier `tests/` non servi |
| `bl.py` | BL : entité de l'imprimante ou parente seulement, jamais une entité sœur ni un autre client |
| `gesconso.py` | Aucune commande aux Achats sans le fichier Gesconso joint |
| `parcours.py` | Chaque page et point d'entrée : ni erreur PHP ou SQL, ni page blanche |
| `entites.py` | Entité des données : figée sur les données commerciales, suit l'imprimante pour les données techniques, transferts, lignes orphelines |
| `commandes.py` | Commandes aux Achats : proposition en échec jamais « réussie », commande enregistrée avant l'envoi, renvoi d'une commande non transmise |
| `journal.py` | Journal du plugin : écriture réelle vérifiée, dossier non inscriptible signalé, jamais un faux succès |
| `prerequis.py` | Borne de version de GLPI Inventory : plus ancienne bloquante, plus récente avertissement |
| `interface.py` | Écrans du module Déploiement par profil : technicien état et action seulement ; TAG, règle d'affectation, blocages du téléchargement (TAG, règle, URL), environnement, limite de temps de la vérification, paquet relu |
| `sante.py` | Carte « Santé de la configuration » : chaque contrôle mis en défaut puis rétabli, URL confirmée par un agent, profils |
| `suivi_gls.py` | Suivi GLS, sans réseau : nettoyage du numéro, repli unique sur E_404_01, lots de 10 ; client (jeton, en-têtes, trois formes d'erreur, quota, aucun secret dans les messages) ; tâche (cadence, repli mémorisé, inconnu, sans nouvelles, budget, disjoncteur) ; « Tester la connexion » ; affichage dans les deux profils avec copies d'écran hors dépôt |
| `modules.py` | Interrupteurs de modules : chaque point d'entrée en 404 quand son module est désactivé, fermé par défaut |
| `gabarits.py` | Gabarits de mail : un gabarit modifié par l'administrateur survit à une réinstallation ; un gabarit absent est recréé |
| `taches.py` | Chaque tâche automatique sur la base peuplée : aucune en erreur, aucune erreur PHP ou SQL |
| `installation.py` | Installation 1.0.0 : refus par-dessus une autre version, cycle sans notice, désinstallation complète (la règle TAG reste), réinstallation identique au relevé de référence hors écarts volontaires déclarés |
| `recette_vide.py` | Sur le plugin fraîchement réinstallé (tables vides) : chaque écran, point d'entrée et onglet dans les deux profils et en accès direct, chaque tâche. **Dernier fichier** : l'instance ressort sans le jeu de données |

Chaque constat est `OK`, `KO`, `À NOTER` ou `NON CONCLUANT` ; `lancer.py` résume et relève les erreurs des journaux GLPI.

## Preuve d'installation (schéma de la 1.0.0)

- `etat_installation.py sortie.json` : relevé de ce que le plugin a posé en base (tables, colonnes, index, lignes de
  référence, tâches, notifications, gabarits, droits, configuration, préférences), depuis `information_schema`.
- `comparer_etats.py avant.json apres.json` : chaque écart sur une ligne, code de sortie 1 s'il y en a.
- `schema_depuis_etat.py etat.json` : corps PHP de `PluginPrintgestionSchema` généré depuis un relevé.
- `reference/etat-ancien-chemin.json` : relevé de référence, pris sur une base installée par l'ancienne chaîne de
  migrations (`PG_TEST_SANS_DONNEES=oui zsh instance.sh` avec le code de la 1.6.10). `installation.py` compare une
  installation neuve de la 1.0.0 à ce relevé.
