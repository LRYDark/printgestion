# Print Gestion — Documentation technique (fonctionnement)

> Plugin GLPI 11 de gestion de flotte d'impression — JCD Groupe.
> Ce document décrit **comment le plugin fonctionne** (architecture, flux de données, mails, crons).
> Pour la mise à jour / le dépannage, voir [DOC_MAINTENANCE.md](DOC_MAINTENANCE.md).

---

## 1. Vue d'ensemble

Print Gestion couvre 5 domaines, activables indépendamment par des **interrupteurs de modules**
(Configuration → onglet Print Gestion) :

Chaque point d'entrée de `front/` et `ajax/` vérifie **plugin actif ET module activé** dans sa garde initiale, avant toute lecture de paramètre ou test de méthode, avec la même réponse 404 que « plugin inactif » (`tests/securite/modules.py` désactive chaque module et attend 404 sur chacun de ses points d'entrée). `PluginPrintgestionConfig::isFeatureEnabled()` est **fermé par défaut** : colonne absente ou nom de module inconnu = module désactivé, jamais ouvert en silence.

| Module (feature) | Contenu |
|---|---|
| `contrats` | Dashboard contrats d'impression, liste avec moteur de recherche natif, création/association imprimante ↔ contrat (« Créer Print »), tarifs €/page par contrat |
| `toner` | Relevés SNMP des niveaux toner, calcul d'alertes intelligent, cycle d'expédition des cartouches, commandes achats (Excel), notifications mail, suivi transporteurs |
| `cout` | Coût à la page par imprimante / par client sur une période (compteurs `glpi_printerlogs` × tarifs contrat) |
| `deploiement` | Collecte SNMP / Déploiement Agent (techniciens) : contrôle de la remontée, déploiement de GLPI Agent par entité client |
| `sage` | Référentiel Sage : import par fichier des adresses de livraison et des articles, pour vérification |

Un sous-onglet du menu n'est visible que si **sa feature est activée ET le droit READ correspondant est présent**
(`visibilité = feature ∧ droit`). Un module désactivé ne consomme aucune ressource (les crons sortent immédiatement).

**Origine** : fusion, en juin 2026, des anciens plugins `printcost` (toner, expéditions, facturation ; avril 2026) et
`gestionprint` (contrats, tableau de bord ; juin 2026), tous deux écrits par Joris Reinert (JCD Groupe) et déclarés
sous « GPL v3+ » dans leur `setup.php`. La mention de licence de Print Gestion vient de là ; droits détenus par JCD Groupe,
auteur Joris Reinert, texte intégral de la GPL v3 dans `LICENSE`. Aucun code tiers n'y est repris : les « patterns » cités en commentaire (plugin
Gestion) désignent une structure suivie, pas du code copié (vérifié ligne à ligne, septembre 2026).

---

## 2. Arborescence

```
printgestion/
├── setup.php            # init plugin : hooks, mapping itemtype/table, menu, versions min/max GLPI
├── hook.php             # install/uninstall + DÉFINITIONS DES GABARITS MAIL (source de vérité)
├── inc/                 # classes PluginPrintgestionXxx (1 fichier = 1 classe)
├── front/               # pages (dashboards, formulaires, listes)
├── ajax/                # endpoints AJAX (actions dashboard, envois cartouches, exports…)
├── public/css|js/       # assets (chargés avec jeton anti-cache ?b=N)
├── tools/
│   └── generate_apercu.php  # régénère docs/apercu_gabarits.html depuis hook.php (CLI, sans BDD)
├── docs/apercu_gabarits.html # aperçu HTML de tous les gabarits mail (généré)
└── docs/                # cette documentation
```

### Classes principales (inc/)

| Classe | Rôle |
|---|---|
| `Config` | Singleton de configuration (ligne id=1), **crée toutes les tables à l'install**, envoi mail générique `sendMail()` ; droit de configuration en lecture seule : formulaire mis en tampon, chaque champ, liste, zone de texte et bouton d'envoi ressort `disabled`, pas de bouton Sauvegarder (les boutons `type=button` restent) |
| `Menu` | Entrée de menu + hub à catégories + barre d'onglets unifiée |
| `Profile` | Droits du plugin (8 droits, voir §8) |
| `Dashboard` | Dashboard contrats (tuiles, camemberts ECharts, liste Search native) |
| `Contract` | Itemtype « virtuel » sur `glpi_contracts` pour borner la recherche aux contrats liés à ≥1 imprimante |
| `Contractrate` | Tarifs N&B / Couleur par contrat (onglet sur fiche Contract) |
| `Print` | Création/association imprimante ↔ contrat (4 scénarios, transactionnel) |
| `Tonerreading` | Snapshot horodaté des niveaux toner (lit `glpi_printers_cartridgeinfos` SNMP GLPI 11) |
| `Logger` | Journal applicatif `files/_log/printgestion.log` (écriture forcée) ; échec d'écriture vérifié et renvoyé au journal d'erreurs natif de PHP ; état et écriture de test relue pour la ligne « Journal du plugin inscriptible » de la carte « Santé de la configuration » |
| `public/js/printgestion.js` | Deux comportements partagés. **POST autonome** : un bouton `type='button'` avec `data-pg-post-action` / `data-pg-post-url` déclenche un petit formulaire construit en JS avec le jeton CSRF standalone de l'en-tête — le grand formulaire de configuration a un HTML historique dont certains navigateurs dissocient le jeton. **Test de connexion** : un bouton `data-pg-test` (GLS, MBE) reste un bouton d'envoi — sans JS ou sans Bootstrap, le formulaire part et `config.form.php` rend le résultat en message — mais le JS intercepte le clic, appelle `ajax/test_connection.php` et affiche la réponse dans la fenêtre `#pg-test-modal`, sans recharger la page ni perdre la saisie en cours ; l'appel est en AJAX, où GLPI vérifie le jeton CSRF de l'en-tête `X-Glpi-Csrf-Token` et le **conserve** (`preserve_token`), donc on peut réessayer sans recharger ; l'adresse et les phrases affichées sont posées sur le bouton par PHP (le JS n'a pas accès aux traductions) et le message du serveur est inséré en `textContent`, jamais en HTML. **Envoi unique** : `data-pg-submit-once` protège du double clic une action lente, et le garde-fou suit le **bouton cliqué** (`event.submitter`, repli sur le dernier clic), jamais le formulaire qui le contient. La distinction est vitale : le formulaire de configuration porte à la fois des actions lentes (« J'ai vérifié », règle TAG) et le bouton « Enregistrer » ordinaire ; protéger le formulaire entier posait la classe `disabled` sur « Enregistrer », sur laquelle Bootstrap coupe les clics — bouton mort, sans message et sans trace côté serveur, puisque aucune requête ne partait. Toute modification de ce fichier ou du CSS demande d'incrémenter le jeton anti-cache `$cb` de `setup.php`, sinon les navigateurs gardent l'ancienne version |
| Carte « Proposition automatique des demandes d'envoi » (`Config`) | Interrupteur de la tâche `PrintgestionProposeDemandes`, dans les réglages et non dans la carte de santé : c'est un choix de fonctionnement, pas une correction. L'état vit dans `glpi_crontasks` (pas dans la configuration du plugin) et se lit/écrit par `Confighealth::getProposeTask()` / `setProposeTask()` ; le témoin caché `propose_posted` distingue « décochée » de « carte absente » (module Toner coupé, ou tâche non enregistrée — la carte ne s'affiche pas). La carte dit avant le clic ce que l'activation entraîne : chaque ligne proposée **bloque** la commande de sa cartouche depuis l'écran Alertes jusqu'à son export ou son annulation (verrou `Guard::REASON_DEMANDE`, jamais contournable), donc le travail se déplace des Alertes vers les Demandes. Couper n'efface rien : les lignes déjà proposées gardent leur verrou. Coupée, la commande se fait à la main depuis Alertes (« Actions → Commander ») |
| `Delivery` | **Le seul endroit où une expédition passe « livrée ».** Hiérarchie des preuves, du plus fort au plus faible : pose détectée (hors de cette classe, elle seule clôt l'envoi) > BL signé > événement de livraison GLS > statut MBE. Deux règles : « livrée » n'est pas terminal (elle reste dans `ACTIVE_STATUSES`, le verrou anti-doublon n'est pas levé et les rappels continuent — c'est ce qui rend acceptable qu'un suivi écrive ce statut) ; **jamais de rétrogradation** (déjà livrée, posée ou annulée → sans effet ; `pending` → refusé et journalisé, la saisie et le terrain se contredisent et ce n'est pas à une tâche de trancher). `markDelivered()` écrit `statut` + `date_delivered` et journalise la source ; `markDeliveredBatch()` groupe et propage une seule fois aux demandes d'envoi (`Demande::syncFromExpeditions()`). Porte aussi les seuils de retard de livraison (`DELAY_DAYS` : GLS 3, UPS 6, Chronopost 4, défaut 4, en **jours ouvrés** via `businessDaysSince()`) — aucun délai ne fait jamais passer une expédition « livrée », il ne sert qu'à alerter |
| `Mbetracking` | Appariement d'une expédition avec son expédition MBE, puis lecture de son statut. **Appariement une fois par passage, pas une fois par expédition** : la fenêtre (`LOOKUP_DAYS` 30 j, `MAX_PAGES` 30) est balayée une seule fois, indexée par n° de BL et par n° transporteur, et confrontée à toutes les expéditions parties non livrées sans `mbe_master_tracking` — fait par expédition, cela coûterait 30 appels chacune. Priorité au **n° de BL** (le lien métier, `normalizeBl()` face à `Mbeclient::parseBlNumber()`), puis au n° transporteur en filet. La référence trouvée est écrite dans `mbe_master_tracking` et plus jamais cherchée. Rafraîchissement : `trackByMbeRef()` par expédition (`MAX_REFRESH` 150/passage), puis `reconcile()` — **le double contrôle** : `TrackingStatus` de MBE reste `WAITING_DELIVERY` des jours après une remise, donc son « en transit » ne vaut pas preuve du contraire ; le statut GLS **déjà rangé sur l'expédition** par le passage GLS de la même tâche (qui tourne avant) l'emporte, sans un appel de plus. `MIN_INTERVAL` 11 h : deux passages par jour même si la tâche est remise à l'heure dans GLPI (le quota MBE ne le supporterait pas). N'écrit que `mbe_master_tracking` ; le statut appartient à `Delivery` |
| `Glsnumber` | Suivi GLS, numéro de suivi (sans appel réseau, expéditions GLS seulement) : `clean()` (URL collée → code ; espaces, tirets, points retirés ; majuscules ; la saisie brute n'est jamais réécrite) ; `fallback()` (exactement 10 caractères alphanumériques → Track ID à 8 caractères + suffixe de 2 conservé sans interprétation : formats publiés par GLS Track And Trace V1 = colis 11/12 chiffres, Track ID 8, carte 6, carte numérique 14 ; le code d'étiquette à 10 n'en est aucun) ; `lookup()` (lots de 10, clé saisie interrogée d'abord, repli une seule fois et seulement sur `E_404_01`, rapprochement par `requested`, plusieurs colis possibles par clé). Harnais `tests/securite/suivi_gls.py` (réponses simulées) |
| `Carrierclient` | Interface transporteur (`isConfigured()`, `testConnection()`, `track()`), une implémentation : GLS. Pas de registre ni de fabrique |
| `Carrierexception` | Échec d'un appel transporteur, typé : `network`, `auth`, `server`, `request` (techniques, disjoncteur), `quota` (429 : journée), `budget`, `breaker` (arrêt du cycle) ; jamais un secret dans le message |
| `Glsclient` | Client GLS Track And Trace V1 : jeton `oauth2/v1/token` (Basic, client_credentials) gardé dans le cache GLPI chiffré pour `expires_in` moins 60 s ; `GET tracking/simple/trackids/{clés}` dix au plus, `showEvents=true&showLinks=false`, `Accept-Language: FR` ; 200 → `parcels[]` tels quels (erreurs par colis comprises), 401/403 → `auth` (jeton oublié), 429 → `quota` (journée bloquée), 400/404 ErrorResponseDTO → `request`, autres → `server` ; transport injectable (Guzzle avec le proxy GLPI en production, réponses inventées dans le harnais) ; mémo de santé et de quota dans `glpi_configs` (contexte du plugin) : dernier appel réussi, échecs consécutifs, dernière erreur, requêtes du jour, journée bloquée ; `testConnection()` demande un jeton et le jette |
| `Mbeclient` | Client SOAP MBE France (e-link), lecture seule : enveloppe SOAP 1.1 construite avec DOM (préfixe sur la seule opération), HTTP Basic + `Credentials`, `InternalReferenceID` unique, `testConnection()` (liste des sept derniers jours, page 1), masquage des secrets et des liens signés, mémo de santé. Deux lectures : `listShipments()` (`ShipmentsListV3Request`, une page d'une fenêtre de dates → références MBE, numéros transporteur, `Notes` d'où `parseBlNumber()` tire le n° de BL, format `BL` + chiffres, toute casse, séparateur optionnel) et `trackByMbeRef()` (`TrackingRequest` → `TrackingStatus`, `DeliveryDate`, `DeliverySign`). **L'API n'offre aucun filtre** sur le n° de BL ni sur le n° transporteur, seul `MBEMasterTrackings` filtre : chercher se fait en balayant la fenêtre page par page. Un champ dont la valeur est un tableau se répète dans l'enveloppe. Lecture de la réponse tolérante : `TrackingRequest` ne renvoie ni `RequestContainer` ni `Status`. Quota 500 appels/jour, arrêt à 80 % (`quotaRemaining()`, `countRequest()`, compté dans le mémo comme pour GLS) |
| `Glstracking` | Suivi des colis rangé sur l'expédition (colonnes `tracking_*`) et rafraîchi par `poll()` depuis la tâche `PrintgestionTrackingUpdate` : une ligne par heure au plus, les plus anciennes d'abord, paquets de dix via `Glsnumber::lookup()`, arrêt avant 80 % du quota (400 requêtes), disjoncteur à cinq échecs techniques consécutifs (E_500_01 compris), numéro inconnu réessayé après 24 h puis « non reconnu » au troisième cycle, 30 jours sans mouvement = « sans nouvelles », statut final (`DELIVERED`, `CANCELED`, `FINAL`) plus jamais interrogé, expédition posée ou annulée jamais interrogée, code hors énumération = non final et journalisé ; multi-colis : statut de tête = le moins avancé (`STATUS_RANK`, anomalies d'abord), les colis mémorisés dans `tracking_parcels` (JSON) ; `renderLine()` : pastille, libellé, événement (omis quand il répète le statut), date, chevron (lieu, numéro interrogé, dernière interrogation) ; plusieurs colis : « 3 colis — 2 livrés, 1 en cours de livraison » et un colis par ligne derrière le chevron ; rien sans clés, jamais le code brut. **N'écrit pas le statut de l'expédition lui-même** : un colis que GLS déclare `DELIVERED` (et lui seul — `DELIVEREDPS` reste en point relais) produit un constat remis à `Delivery` en fin de passage, en une fois ; tout le reste du suivi ne fait que s'afficher |
| `Schema` | Schéma de la 1.0.0 : `CREATE TABLE` et lignes de référence relevés sur l'ancien chemin puis générés (jamais écrits à la main), `refusal()` (une autre version en base : installation refusée, « désinstallez d'abord »), `install()` (tables absentes, lignes de référence, version), `uninstall()` (toutes les tables, anciennes comprises, et le contexte de configuration). Preuve rejouable : `tests/securite/comparer_etats.py` |
| `Confighealth` | Carte « Santé de la configuration », en tête de la configuration (droit de configuration du plugin ; boutons avec UPDATE) : contrôles automatiques obligatoires (URL de l'application GLPI : absolue, avec schéma, ni locale ni nom sans domaine qui ne se résout pas, `Agentdeploy::getApplicationUrlIssue()` ; puis preuve par le réel, `getUrlConfirmation()` : « confirmée par un agent le … » si un agent portant le TAG d'une entité a contacté GLPI depuis que cette URL est en place, sinon « jamais confirmée », état d'attente qui empêche la carte de se replier ; « depuis quand » = mémo dérivé dans `glpi_configs` contexte `plugin:printgestion` (`url_base_seen`, `url_base_seen_since`), réécrit seulement quand l'URL change, jamais saisi ; inventaire GLPI activé ; GLPI Inventory via `Collectsetup::getPrerequisites()` ; actions automatiques, `getCronStatus()` : jugées sur les tâches du plugin seulement + `queuednotification` en lecture ; cron système prouvé par la tâche témoin `PrintgestionTemoinCron` (CLI seulement, chaque minute, ne fait que dater son passage, passée dans les 15 min) ou déclaré par `GLPI_SYSTEM_CRON` ; rien ne se corrige tout seul et rien n'est groupé : `getCronButtons()` pose un bouton par correction, affiché seulement s'il a quelque chose à corriger, chacun confirmé, chacun disant ce qu'il ne touche pas — `cron_switch_cli` (`switchTasksToCli()` : le mode seulement, les tâches dont `allowmode` accepte le CLI, confirmation qui prévient si aucun cron système n'est prouvé), `cron_enable_tasks` (`enableTasks()` : l'état seulement, `PrintgestionProposeDemandes` exceptée), `cron_unblock_tasks` (`unblockTasks()` : tâches du plugin coincées en `STATE_RUNNING` d'après `CronTask::getZombieCronTasks()`), `cron_declare_system` (`declareSystemCron()` : écrit `define('GLPI_SYSTEM_CRON', true);` dans `config/local_define.php` — ligne remplacée si elle existe, ajoutée sinon, copie horodatée avant, jamais de réécriture du fichier ; proposé seulement si le fichier est inscriptible et à qui a le droit GLPI `config, UPDATE`, exigé aussi côté `config.form.php` ; il déclare le cron système, il ne le crée pas, et la confirmation le dit) ; **aucun bouton pour la proposition automatique des demandes d'envoi** — ce n'est pas une correction mais un choix de fonctionnement, son interrupteur est dans les réglages (carte « Proposition automatique des demandes d'envoi », `Config::showConfigForm()`), et `getProposeTask()` / `setProposeTask()` le lisent et l'écrivent dans `glpi_crontasks` ; la tâche reste affichée ici, en lecture, comme les autres ; jamais les tâches de GLPI ni d'un autre plugin ; le seul point qu'aucun bouton ne corrige est le cron du serveur : tant que le témoin n'est pas passé, le détail affiche la ligne de crontab à recopier, et quand tout le reste est réglé la ligne le dit (« les N tâches du plugin sont déjà en CLI et actives ») ; détail = GLPI_SYSTEM_CRON, chaque tâche avec état, mode, fréquence, dernière exécution et lien vers sa fiche (`renderTaskTable()`) ; c'est le **seul** endroit où une tâche du plugin est affichée (l'ancienne carte « Suivi des expéditions : tâche automatique » de la configuration est supprimée, elle montrait `TrackingUpdate` une deuxième fois ; son avertissement « fréquence plus longue qu'une heure » est repris ici, sous le tableau) ; xlsx autorisé via `Document::isValidDoc()` ; règle d'affectation par TAG active, bouton `Agentdeploy::getTagRuleButton()` traité par `config.form.php`) et recommandés (sauvegarde de `glpicrypt.key` : non vérifiable, rappel ; journal inscriptible et écriture de test). Ligne : état, ce qui casse, où corriger ; bannière rouge si un obligatoire manque, sans bloquer ; détail toujours replié derrière un chevron : « Configuration : complète » tout vert, sinon « N points à voir : … à corriger, … jamais confirmés par le réel, … à acquitter » (`Ui::statusLine()`). Harnais `tests/securite/sante.py` |
| `Ui` | Fragments d'interface partagés : barre de statistiques ; `jsonData()`, seul passage des données PHP vers le JavaScript (bloc JSON non exécuté, drapeaux `JSON_HEX_*`) ; deux publics : `isAdmin()` (droit de configuration du plugin), `statusLine()` (ligne d'état, détail replié pour l'administrateur), `adminDetails()` (chevron fermé), `infoButton()` (fenêtre « i ») ; tout élément réservé porte `data-pg-admin` et n'est jamais envoyé à un autre profil |
| `Entityscope` | Entité des données client : entité à écrire à la création ; données techniques qui suivent l'imprimante ou le contrat ; données commerciales figées ; lignes orphelines (`findOrphans()`) ; tâche de contrôle `PrintgestionEntityScope` |
| `Cartridgehistory` | Détection automatique des changements de cartouche (hausse de niveau ≥ `detection_delta` %) |
| `Alert` | Calcul intelligent des alertes toner (vitesse de conso sur fenêtre 30 j) + **digest mail commercial** ; `listPriorityAlerts()` rend trois sortes de lignes : `wrong_printer`, `late_shipment` (partie, pose non constatée après `reminder_days`) et `late_delivery` (partie et **toujours pas livrée** au-delà du délai du transporteur, en jours ouvrés, `Delivery::delayThreshold()` ; pré-filtre SQL sur le plus court des seuils en jours calendaires, décompte exact ensuite en PHP) |
| `Alertview` | Table **matérialisée** des alertes et écran natif (recherche, colonnes verrou / référence, actions de masse Commander, Ne plus alerter, Réactiver) |
| `Expedition` | Cycle d'expédition des cartouches, **tous les circuits mail** (planif/achats/courtoisie/rappels) |
| `Demande` / `Demandeline` | Demande d'envoi (en-tête client + site, lignes) : statuts, contrôles avant validation, historique natif |
| `Guard` | Verrous anti-double-envoi (envoi en cours, demande ouverte, garde après pose, ticket récent) |
| `Sageimport` | Import du référentiel Sage par fichier : analyse, prévisualisation, rapport d'écarts, validation |
| `Sage` | Règle Gesconso : code client = nom de l'entité en forme de code (hérité du parent le plus proche), intitulé de livraison = première ligne des commentaires de cette même entité porteuse ; vérifications contre les référentiels importés |
| `Gesconso` | Fichier de commande Gesconso (9 colonnes), contrôles bloquants avant écriture, archivage en Document |
| `Snmpadapter` | Service (classe simple, sans table) : lecture fiable des niveaux SNMP — sentinelles, états bruts max/used/remaining, application des règles par constructeur |
| `Snmprule` | Règle de lecture SNMP par constructeur (ignorer / inverser une propriété) : table, carte de configuration, droit de configuration du plugin |
| `Collect` | Contrôle de la remontée (lecture seule) : prérequis, états de collecte datés par le journal d'import GLPI, agents et versions, valeurs de consommables et compteurs par modèle, doublons de numéro de série |
| `Agentdeploy` | Déploiement Agent : onglet de l'entité (TAG, règle d'affectation, agents), installeur GLPI Agent servi et vérifié, paquet Windows pré-paramétré |
| `Raccordement` | Assistant de raccordement des imprimantes (5 étapes, **une seule ouverte à la fois**, journal horodaté replié), page « Raccordements » (actions massives natives, `cleanDBonPurge()`), bloc 3 de l'onglet Déploiement Agent de l'entité |
| `Collectsetup` | Service (sans table) : configuration de collecte créée dans GLPI Inventory (plage, identifiants SNMP, modules de la sonde, tâches), déclenchement, vérification adresse par adresse |
| `Raccordementdetail` | Lieu, commentaire et contrat des imprimantes d'un raccordement : saisie en attente avec les sélecteurs natifs de GLPI (étape 3), application aux imprimantes remontées avec verrou natif (étape 5) |
| `Printeragent` | Fiche imprimante : sonde responsable (plage et tâche GLPI Inventory), version, dernier contact, dernier inventaire réseau réussi ; dans la carte native « Informations d'inventaire », sinon sous le formulaire |
| `Agentsetting` | Sondes : dernière version connue de GLPI Agent (GitHub, saisie), conformité, réglages de mise à jour par sonde, paquet de consigne, imprimantes collectées, statut du PC sonde ; onglet de la fiche Agent et page « Sondes » |
| `Agentalert` | Alertes « sonde sans contact » et « imprimante qui ne remonte plus » (tâche quotidienne), réglages et action dans « Agent cleanup », cartes du tableau de bord |
| `NotificationTargetAgentalert` | Notifications natives des alertes de sondes (sonde sans contact, imprimantes qui ne remontent plus) |
| `Collectfrequency` | Fréquence des relevés d'imprimantes par entité (héritée, quotidienne par défaut) : décision commerciale, réglée par l'administrateur du plugin seulement (chevron « Fréquence des relevés » sous les installeurs, droit de configuration en modification ; le technicien n'en voit ni texte, ni champ, ni bouton), planification des tâches GLPI Inventory des raccordements, seuil « muette » des imprimantes ; **écrit directement `datetime_start` dans la table des tâches de GLPI Inventory** (le plugin voisin refuse de modifier une tâche active) — garde-fou `assertInventoryTaskColumns()` : colonnes `datetime_start`/`datetime_end` vérifiées, sinon exception (tâche automatique en erreur, journal du plugin, ligne rouge dans l'onglet de l'entité, message dans l'assistant) ; à reprendre par une voie du plugin voisin quand elle existera (`tests/securite/prerequis.py`, section 2) |
| `NotificationTargetDemande` | Notifications natives GLPI des demandes d'envoi (proposée, relance, exportée) |
| `Contractalert` | État et activation des alertes de contrat natives GLPI ; le bouton « Activer les alertes de contrat natives » écrit dans la configuration de GLPI : droit natif `config` en écriture exigé (affichage et enregistrement), sinon bouton désactivé avec la raison |
| `Snmpmapping` | Mapping constructeur + propriété SNMP → modèle de cartouche + couleur |
| `Cartridgesnmp` | Onglet sur fiche CartridgeItem : binding direct cartouche ↔ propriétés SNMP |
| `Billing` / `Billingview` | Coût à la page + table matérialisée **par utilisateur** (le calcul dépend de la période choisie) ; les trois critères (contrat, compteur, activité) sont ceux de l'écran Facturation — cochés par défaut, gardés en session, portés par l'export Excel et la clé de cache —, plus un réglage |
| `PrinterCostsTab` | Onglet « Coût à la page » sur la fiche imprimante : prix et coûts (droit `billing` READ) |
| `PrinterThresholdsTab` | Onglet « Seuils d'alerte » sur la fiche imprimante : seuils et rendement propres (droit `dashboard` READ, UPDATE pour enregistrer) |
| `Tracking` | Intégrations externes : BL signés du plugin Gestion (le suivi des colis est dans `Glstracking`) ; lien avec le plugin Gestion : `isGestionPresent()` (la table des BL existe, donc le plugin a été installé un jour) décide si la carte « Liaison avec le plugin Gestion » s'affiche ; `isGestionUsable()` (plugin actif ET table présente) dit si elle peut fonctionner, déduit du réel ; `isGestionLinkEnabled()` est l'interrupteur de cette carte, **activé par défaut** (valeur absente = activée), rangé dans `glpi_configs` contexte `plugin:printgestion` sous `gestion_link_enabled` et non dans une colonne — un réglage qui doit exister sur une base déjà installée, alors que `Schema::install()` ne crée que les tables absentes et n'ajoute pas de colonne ; `isGestionLinkActive()` = interrupteur ET utilisable, et c'est lui que lisent `link_bls`, `search_bls`, `expedition.form.php` et le dashboard ; le passage en « livrée » sur BL signé tient sur **trois couches**, du plus immédiat au dernier filet : **(1)** le plugin Gestion appelle `onGestionBlSigned()` juste après chaque signature (`front/traitement.php` ×2, `front/traitement_combined_multi.php` ×1, sous `class_exists` + `try/catch` — une erreur de Print Gestion ne fait jamais échouer une signature), ce qui donne l'immédiateté ; ces trois appels vivent chez Gestion, donc **une mise à jour de Gestion peut les emporter** et `tests/securite/livraison.py` vérifie qu'ils sont toujours là ; **(2)** `syncOnDisplay()` à l'ouverture des écrans qui affichent ou consomment le statut (`dashboard_expeditions.php`, `dashboard_alerts.php`) et `ajax/link_bls.php` au rattachement d'un BL déjà signé — du SQL local, deux jointures indexées, sans réseau ni quota (ce que la règle de GLS interdit, c'est d'appeler un transporteur au chargement d'une page, pas de lire sa propre base ; jamais d'exception vers la page) ; **(3)** la tâche **horaire** `PrintgestionCheckAlerts` rattrape ce qu'aucun clic n'a déclenché (BL importé déjà signé, `signed` basculé directement en base, appel direct en échec), et `PrintgestionTrackingUpdate` ferme la marche. Perdre la couche 1 ne casse rien : on retombe sur une heure au pire ; `findDeliveredFromGestion()` rend des constats (datés de `doc_date`, la signature, pas du passage) que `onGestionBlSigned()` remet à `Delivery` : plus aucune écriture de statut ici. Le passage automatique en « livrée » sur BL signé ne touche pas au verrou anti-doublon (statut actif, seule la pose détectée clôt l'envoi ; prouvé par `tests/securite/bl.py`) ; configuration « Suivi GLS » : `gls_client_id` en clair, `gls_client_secret` chiffré (GLPIKey), jamais réaffiché (« •••••••• défini le », « Remplacer »), « Retirer les clés » ; aucun interrupteur, aucune URL, aucune fréquence — des clés et un dernier appel réussi = suivi actif ; les URL de l'API sont des constantes du code (client GLS : bloc 4) ; les anciennes clés UPS / GLS / Chronopost et leurs fonctions vides sont supprimées ; aucun transporteur présélectionné à l'expédition, choix explicite exigé |
| `Reminder` | Les 3 tâches cron GLPI (voir §7) |
| `Dashboardactions` | Menu contextuel et modales des écrans Expéditions et Coût à la page (modifier l'expédition, BL) |

---

## 3. Modèle de données

Le schéma est **versionné** (`inc/schema.class.php`) : la version installée est enregistrée dans la
configuration GLPI (`glpi_configs`, contexte `plugin:printgestion`, clé `schema_version`) et chaque
évolution est une étape de migration jouée une seule fois lors du « Mettre à jour » (voir doc maintenance §2).

| Table | Contenu |
|---|---|
| `glpi_plugin_printgestion_configs` | Configuration singleton (id=1) : features, seuils, rôles mail, IDs gabarits, installeur GLPI Agent ; dernière version connue de GLPI Agent, mise à jour automatique des nouveaux paquets, statut des PC sondes |
| `glpi_plugin_printgestion_toner_readings` | Snapshots horodatés des niveaux toner (purge > 160 j) |
| `glpi_plugin_printgestion_cartridge_history` | Changements de cartouche détectés |
| `glpi_plugin_printgestion_alerts` | Alertes émises (traçabilité + anti-doublon mail 24 h) |
| `glpi_plugin_printgestion_alert_snoozes` | Mises en sommeil d'alertes (par toner ou par imprimante) |
| `glpi_plugin_printgestion_alertview` | **Matérialisée** : 1 ligne par couple imprimante/toner pour le Search natif |
| `glpi_plugin_printgestion_expeditions` | Expéditions de cartouches (statuts, transporteur, group_id, users) ; suivi GLS : `tracking_key` (clé interrogée), `tracking_suffix`, `tracking_status` (code GLS, fait foi), `tracking_label` (dernier événement, français, affichage seulement), `tracking_event_datetime` (telle que GLS l'envoie, décalage compris ; heure locale et règle des 30 jours dérivées à la lecture), `tracking_event_place`, `tracking_checked_at`, `tracking_failures`, `tracking_state` (`tracked`, `unknown`, `unrecognized`, `silent`, `final`), `tracking_parcels` (JSON, un colis par entrée) |
| `glpi_plugin_printgestion_demandes` | Demandes d'envoi : client (entité), site de livraison, statut, mode et contact de livraison, validation, annulation |
| `glpi_plugin_printgestion_demandelines` | Lignes de demande : imprimante, toner, cartouche, quantité, prix unitaire, contrat, statut |
| `glpi_plugin_printgestion_sagedeliveries` | Adresses de livraison Sage (plusieurs par client) : vérification des intitulés de livraison, avertissement seulement |
| `glpi_plugin_printgestion_sagearticles` | Articles Sage (référence rapprochée de `CartridgeItem.ref`) |
| `glpi_plugin_printgestion_sageimports` | Trace des imports (référentiel, fichier, auteur, volumes) |
| `glpi_plugin_printgestion_snmprules` | Règles de lecture SNMP par constructeur (ignorer / inverser une propriété) |
| `glpi_plugin_printgestion_expedition_bls` | Liaison expéditions ↔ BL du plugin Gestion |
| `glpi_plugin_printgestion_purchaseorders` | Transmission aux Achats : une ligne par commande enregistrée (groupe d'expéditions), origine, fichier archivé, lignes du mail, statut d'envoi (`pending`, `sending`, `sent`, `failed`), tentatives, dernière erreur, dates d'envoi et de notification |
| `glpi_plugin_printgestion_snmp_mapping` | Mapping constructeur/propriété SNMP → cartouche |
| `glpi_plugin_printgestion_cartridge_snmp` | Bindings directs cartouche ↔ propriété SNMP |
| `glpi_plugin_printgestion_contractrates` | Tarifs €/page N&B / Couleur par contrat |
| `glpi_plugin_printgestion_billing` | Lignes de facturation calculées |
| `glpi_plugin_printgestion_billing_view` | **Matérialisée par utilisateur** : coût à la page pour le Search natif |
| `glpi_plugin_printgestion_historical_yields` | Rendements historiques (pages/cartouche) |
| `glpi_plugin_printgestion_printer_thresholds` | Seuils d'alerte personnalisés par imprimante |
| `glpi_plugin_printgestion_raccordements` | Raccordements d'imprimantes : entité, sonde, statut, identifiants SNMP, plages et tâches GLPI Inventory utilisées, objets créés, dates des étapes |
| `glpi_plugin_printgestion_raccordementips` | Adresses déclarées d'un raccordement et leur résultat (équipement trouvé, son entité) ; lieu, commentaire et contrat en attente, imprimante et date de leur application |
| `glpi_plugin_printgestion_raccordementlogs` | Journal horodaté d'un raccordement : étape, niveau, auteur, message |
| `glpi_plugin_printgestion_agentsettings` | Réglages de mise à jour par sonde : agent (unique), mise à jour automatique, version cible, auteur, dates |
| `glpi_plugin_printgestion_agentalerts` | Alertes de sondes : type (sonde sans contact, imprimante qui ne remonte plus), entité, sonde, imprimante, motif, début, notification, fin ; une seule alerte ouverte par sonde ou par imprimante (colonne générée `open_lock`) |
| `glpi_plugin_printgestion_collectfrequencies` | Fréquence des relevés d'imprimantes par entité : entité (unique), unité (`hourly`, `daily`), nombre, auteur, dates ; sans ligne, l'entité hérite de sa parente, sinon quotidienne |
| `glpi_plugin_printgestion_table_prefs` | Préférences d'affichage des tableaux par utilisateur |

**Entité des données client.** `entities_id` et `is_recursive` sont portés par les tables
rattachées à une imprimante, à une expédition, à une demande ou à un contrat ; `demandes` a son entité depuis 1.3.1 et
reçoit `is_recursive` (0). GLPI traite alors ces objets comme rattachés à une entité (`isEntityAssign()`) : droits sur un
objet (`canViewItem`, `canUpdateItem`), moteur de recherche et actions de masse natives restreignent d'eux-mêmes. Les
contrôles du plugin (`PluginPrintgestionSecurity`) restent une seconde barrière et lisent la même entité. Deux règles,
selon la nature de la donnée :

| Nature | Tables | Au transfert de l'imprimante (ou du contrat) |
|---|---|---|
| Technique | `toner_readings`, `historical_yields`, `cartridge_history`, `printer_thresholds`, `alert_snoozes` ; `contractrates` (suit le contrat) | Suit l'imprimante (le contrat) aussitôt : hook `item_update` sur `Printer` et `Contract` |
| Commerciale | `expeditions`, `alerts`, `expedition_bls`, `demandes`, `demandelines` | Ne suit jamais : entité figée à la création, jamais recalculée |

- **écriture** : chaque insertion pose l'entité de l'objet de rattachement (`Entityscope::forPrinter()`,
  `forExpedition()`, `forContract()` ; relevés toner : entités chargées en une requête) ; une liaison BL prend celle de
  son expédition, un rappel « non posée » celle de son envoi. Une réattribution d'envoi vers une autre imprimante ne
  change pas son entité ;
- **lecture** : écrans, listes, exports et contrôles d'accès des envois, alertes, liaisons BL et demandes filtrent sur
  l'entité de la ligne (`Security::canAccessRow()`, `getAccessibleExpedition()`, `getAccessibleAlert()`), jamais sur
  l'entité actuelle de l'imprimante : après un transfert de A vers B, un compte de B ne voit rien de l'historique
  commercial de A, un compte de A le garde ;
- **contrôle** : tâche quotidienne `PrintgestionEntityScope` (`Entityscope::reconcile()`), limitée aux données
  techniques : elle recale toute ligne en écart et journalise chaque correction en `[ERREUR]` (un écart révèle un
  chemin d'écriture qui a oublié l'entité). Elle ne lit pas les tables commerciales : une expédition dont l'entité
  diffère de celle de son imprimante n'est pas une erreur ;
- **à l'installation initiale (historique)** : entité des données commerciales recalculée à leur date de création, d'après l'historique GLPI de
  l'imprimante (changements d'entité, option 80 ; de récursivité, option 86) ; une expédition réattribuée reprend son
  imprimante d'origine (note « Réassignée depuis l'imprimante #N ») ; liaisons BL et lignes de demande prennent l'entité
  de leur expédition ou de leur demande ;
- **lignes orphelines** : imprimante (expédition, demande, contrat) introuvable à l'écriture ou purgée avant que
  l'entité ne soit connue : entité racine, non récursive, donc invisible des comptes clients. Jamais rattachées d'office
  à une autre entité ni supprimées : `Entityscope::findOrphans()` les liste (objet de rattachement absent et entité
  racine non récursive) dans la configuration du plugin (carte « Lignes sans objet de rattachement », comptes de la
  racine), dans le journal de la tâche quotidienne, pour qu'un administrateur tranche.
Hors périmètre : tables globales (référentiel Sage, mappings et règles SNMP, liaisons cartouche) et tables déjà
rattachées à une entité (raccordements et leurs adresses et journaux via le raccordement, fréquences, alertes de
sondes, réglages de sonde via l'agent natif).

---

### Installation, version, désinstallation

- **Une seule version de schéma, 1.0.0**, sans chemin de mise à jour : `hook.php` refuse l'installation par-dessus une
  autre version (`Schema::refusal()`), rien n'est touché. Le schéma est relevé et généré, pas écrit à la main (voir
  DOC_MAINTENANCE §2) ; `installation.py` prouve à chaque passe l'égalité avec le relevé de référence.
- **Désinstallation** (`hook.php`) : tables, tâches, notifications et gabarits, droits, préférences d'affichage,
  recherches, configuration du plugin, cache, journal, installeurs, fichiers temporaires, liens documents. Restent :
  règle d'affectation par TAG, TAG des entités, objets GLPI Inventory des raccordements, historique natif, documents.

## 4. Flux toner : du SNMP à l'alerte

```
GLPI inventaire SNMP ──> glpi_printers_cartridgeinfos
        │  (cron 1, quotidien)
        ▼
Tonerreading::snapshotAllPrinters() ──> toner_readings (horodaté)
        │
        ├─> Cartridgehistory::detectChanges()   hausse ≥ detection_delta % = cartouche changée
        │                                        → Expedition::markInstalledOnDetection()
        │  (cron 2, horaire)
        ▼
Alert : pages restantes = niveau fiable × rendement (mesuré sur le cycle, sinon historique, sinon défaut)
        − pages imprimées depuis ; jours restants = pages restantes / cadence (pages/jour lissées sur 28 j)
        (relevé « niveau figé » : niveau inchangé malgré l'impression → conservé, marqué is_suspect)
        │
        ├─> statuts : ok / watch / critical (seuils config + seuils par imprimante)
        ├─> sendPendingAlerts() : mail commercial DIGEST (1 mail/run, voir §6)
        └─> Alertview::rebuild() : matérialisation pour le dashboard Search natif
```

Résolution de la cartouche à commander pour une propriété SNMP (`Snmpmapping::resolveCartridge`), **stricte** :

1. modèle d'imprimante obligatoire (sinon : référence non résolue) ;
2. liaison directe (`cartridge_snmp`), restreinte aux cartouches déclarées compatibles avec le modèle ;
3. à défaut, type du mapping SNMP (`snmp_mapping`), restreint aux cartouches de ce type compatibles avec le modèle ;
4. plusieurs candidates à la même étape : celle de l'entité de l'imprimante si elle est seule, sinon ambiguïté
   (référence non résolue — jamais de choix arbitraire).

- **Liaison directe** (onglet Print Gestion de la cartouche, `Cartridgesnmp`) : toutes les propriétés remontées
  par les imprimantes des modèles compatibles, quelle que soit la valeur (pourcentage, OK / WARNING, pages…),
  états bruts `…max` / `…used` / `…remaining` ramenés à leur emplacement ; une liaison existante reste affichée
  même si plus aucune imprimante ne remonte la propriété. L'enregistrement ne modifie que les propriétés
  affichées (plus de purge puis réinsertion).
- **Noms des propriétés** : ceux de l'inventaire GLPI (`Glpi\Inventory\Asset\Cartridge::knownTags()`), type +
  couleur pour les emplacements colorés (`tonerblack`, `drumcyan`, `cartridgeyellow`), sans couleur pour
  `developer`, `wastetoner`, `maintenancekit`, `fuserkit`, `transferkit`, `cleaningkit`. Mapping SNMP pré-rempli
  à l'installation avec les quatre toners et les kits, sous les seuls libellés que l'inventaire produit ; les anciennes
  lignes sous d'autres libellés (« Toner Noir », `developercyan`…) ne sont plus posées, si elles n'ont pas été
  modifiées et qu'aucune imprimante ne les remonte.

Aucun repli sans modèle (liaison toutes imprimantes confondues, type seul toutes marques). Référence non
résolue = cartouche non commandable : badge « Réf. non résolue » avec le motif, exclue de la fenêtre de
commande, refus côté serveur. Les synchronisations de l'onglet Cartouches natif ignorent aussi ces emplacements.
**Lecture des niveaux** (`inc/snmpadapter.class.php`, utilisée par les relevés, les alertes, les verrous et
l'amorçage des cartouches) :

- sentinelles de la Printer MIB (RFC 3805) : `-1` non mesurable, `-2` inconnu, `-3` « il en reste » —
  jamais lues comme un pourcentage (ni 0 %, ni 100 %) ;
- propriétés d'état brut `…max`, `…used`, `…remaining` : pas des emplacements ; si le pourcentage manque ou
  est une sentinelle, il est reconstitué (restant / max, ou (max − utilisé) / max) ;
- règles par constructeur (Configuration → « Lecture des niveaux SNMP ») : ignorer ou inverser une propriété,
  motif avec `*`, constructeur vide = tous ; aucune règle par défaut (un bac de récupération remonte déjà la
  place restante) ;
- données chargées une fois par requête (toutes les imprimantes), aucune requête par imprimante.

Aucun stock n'est lu ni affiché : le stock est dans Sage, que le plugin ne lit pas (échanges par fichier Excel
uniquement).

---

## 5. Cycle d'expédition

```
pending ──(planif saisit transporteur+tracking)──> shipped ──> transit ──> delivered ──> installed
                                                                (+ cancelled : annulée, jamais supprimée)
```

- **Envoi en cours** (`Expedition::ACTIVE_STATUSES`, sans borne de temps) : pending, shipped, transit
  **et delivered**. « Livrée » ne clôt pas l'envoi : seule la **pose** le fait.
- **Aucun statut lié au stock** : aucun statut `stock_empty` calculé sur le stock GLPI (le stock est dans Sage, que le
  plugin ne lit pas).
- **Clôture** : `installed` quand la pose est détectée (hausse de niveau, §4), confirmée manuellement
  (fenêtre « Modifier expédition ») ou constatée sur une autre imprimante (réattribution) ;
  `cancelled` pour une annulation (aucune suppression de ligne).
- `delivered` : BL signé du plugin Gestion, API transporteur (cron 3) ou saisie manuelle — n'a plus
  d'effet sur le blocage. Rappels de pose et « expéditions en retard » couvrent aussi les livrées non posées.
- **Unicité garantie par la base** : au plus un envoi en cours par (imprimante, toner). Colonne générée
  `active_lock` (1 si en cours, NULL si posé/annulé) et clé unique `uniq_active_slot`
  (printers_id, toner_property, active_lock). Un doublon (double clic, commandes simultanées) lève
  l'erreur 1062, restituée à l'utilisateur comme un refus explicite (`Expedition::isDuplicateActiveError()`).
- `group_id` (UUID) relie les expéditions d'une même commande.

### Anti-double-envoi : les trois verrous (`inc/guard.class.php`)

Évalués pour une **machine** (l'imprimante et toute imprimante de la même entité portant le même n° de série ; dans
une autre entité, jamais la même machine : un envoi d'un client ne bloque pas un autre client) et un
**emplacement toner** (propriété SNMP), en requêtes groupées (`Guard::evaluate()`), par ordre de priorité :

| Verrou | Condition | Durée | Contournable |
|---|---|---|---|
| Envoi en cours | Un envoi ni posé ni annulé existe | Sans limite, jusqu'à la pose ou l'annulation | **Jamais** |
| Demande en cours | Une ligne de demande proposée ou validée, pas encore exportée | Jusqu'à l'export ou l'annulation | **Jamais** |
| Garde après pose | Pose détectée (`cartridge_history.is_detected = 1`) ou confirmée (`installed`) | `guard_days` (5 par défaut) après la pose | Oui |
| Ticket récent | Ticket non résolu lié à la machine, ouvert récemment | `guard_ticket_days` (10 par défaut, 0 = désactivé) | Oui |

- **Contournement** (consommation anormale) : niveau mesuré ≤ `guard_bypass_level` % (10 par défaut).
  Côté commande, le niveau est relu côté serveur (`Guard::evaluateLive()`), jamais pris du navigateur.
- **Cartouche posée sur la mauvaise imprimante** : la pose est enregistrée dans l'historique de
  l'imprimante qui l'a reçue (garde immédiate) ; l'imprimante prévue reste « envoi en cours » avec un
  message qui renvoie vers la réattribution, laquelle clôt l'envoi et la libère. La détection
  « mauvaise imprimante » (`Cartridgehistory::detectAndLogWrongPrinter()`) ne s'applique que si
  l'imprimante détectée n'attendait elle-même aucun envoi pour ce toner, et ne retient qu'un envoi
  **déjà parti** (`Expedition::DEPARTED_STATUSES` : expédié, en transit, livré) d'une autre imprimante
  de la même entité, du **même site** (racine du lieu) et de la **même référence** de cartouche, dans
  la fenêtre `wrong_printer_lookback_days`. Site ou référence inconnus : aucun rapprochement. L'alerte
  est une proposition (envoi, deux machines, dates) : aucune réattribution automatique. La réattribution
  manuelle (écran Expéditions) n'est acceptée que pour un envoi parti et vers l'imprimante où l'alerte
  en cours a détecté la pose (`Expedition::getReassignRefusal()`) ; annuler l'envoi ou confirmer sa pose
  résout l'alerte.
- Chaque ligne d'alerte porte son verrou (`lock`) ; le mail « toner bas » ignore les emplacements
  verrouillés (sauf contournement).
- **Écran des alertes** (moteur de recherche natif sur `alertview`) : colonnes Verrou (envoi en cours,
  demande en cours, garde, ticket, contournement), Motif du verrou et Référence non résolue ; l'action de
  masse « Commander » (droit validation UPDATE) refuse la commande entière si une ligne est non commandable.
- **Côté serveur** (`Expedition::createPurchaseOrder()`) : verrous réévalués avant toute écriture ; une
  seule ligne verrouillée fait refuser la commande entière avec la liste des lignes et leur motif ;
  lignes en double écartées ; commande sous contournement acceptée avec avertissement.

### Sous contrat / hors contrat (`Contractrate::getConsumablesCoverage()`)

- **Contrat en cours** (`Contractrate::notInForceReason()`, aligné sur `Contract::getNotExpiredCriteria()`
  du cœur) : ni supprimé ni modèle, date de début renseignée et atteinte, puis reconduction tacite, ou fin
  (début + durée en mois, calcul `DATE_ADD` MySQL) strictement postérieure à aujourd'hui. Sans durée et sans
  reconduction tacite : pas en cours (GLPI le considère expiré).
- **Choix du contrat** (`getContractIdForPrinter()`, utilisé aussi par le coût à la page) : le contrat en
  cours le plus récemment commencé ; un contrat terminé n'est plus retenu.
- **Sous contrat** : un contrat en cours dont le type natif figure dans la configuration
  (`consumables_contracttypes`, « Contrats — consommables inclus ») ; si plusieurs, le plus récemment
  commencé. **Hors contrat** sinon, y compris quand aucun type n'est paramétré. Le motif est restitué
  (contrat terminé, type non couvert, aucun contrat lié…).
- **Prix** : 0 uniquement sous contrat ; hors contrat la cellule Prix reste **vide**, jamais 0.

### Demandes d'envoi (`inc/demande.class.php`, `inc/demandeline.class.php`)

- **En-tête** (`glpi_plugin_printgestion_demandes`) : client = entité de l'imprimante, site de livraison =
  lieu racine (`Demande::getSiteLocationId()`), mode de livraison (envoi direct / technicien, information
  seule), contact et commentaire de livraison, validation et annulation (qui, quand, motif).
- **Lignes** (`glpi_plugin_printgestion_demandelines`) : imprimante, toner, cartouche résolue, quantité,
  prix unitaire (0 sous contrat ; vide ou saisi hors contrat), contrat retenu, niveau et jours estimés à la
  proposition, statut propre, expédition liée (renseignée à l'export).
- **Statuts** : `proposed` → `validated` → `exported` → `shipped` → `delivered` → `installed`, plus
  `cancelled`. Aucune suppression : `pre_deleteItem()` refuse, suppression et modification en masse
  interdites ; l'annulation est un statut.
- **Historique natif** : `dohistory` sur la demande ; ajouts et modifications de lignes journalisés sur la
  demande (ligne, champ, ancienne → nouvelle valeur). Toutes les écritures passent par `add()` / `update()`.
- **Anti-double-envoi** : une ligne ouverte (`proposed` / `validated`) est un verrou « demande » jamais
  contournable (`Guard`), au même titre qu'un envoi en cours. Unicité en base : colonne générée
  `active_lock` + clé `uniq_active_slot` (une ligne ouverte par imprimante et toner) ; `proposal_lock` +
  `uniq_open_proposal` (une demande proposée par entité et site). À l'export, l'expédition créée prend le
  relais du verrou. **Limite** : l'exclusion entre une ligne ouverte et un envoi en cours n'est pas garantie
  par la base (deux tables) ; elle repose sur `Guard`, réévalué côté serveur avant chaque écriture.
- **Proposition automatique** (`Demande::proposeFromAlerts()`, tâche `PrintgestionProposeDemandes`) :
  toners critiques ou à surveiller, non snoozés, sans verrou bloquant, sans ligne annulée depuis moins de
  30 jours sur la machine (`Demande::RECENT_CANCEL_DAYS`, sauf pose détectée ou confirmée depuis : une
  annulation n'est pas défaite au passage suivant, la commande directe reste possible) ; regroupement par client (entité) et
  site (lieu racine). **Un toner à surveiller ne déclenche pas d'envoi seul** : un site n'est proposé que s'il
  compte un toner critique, ou pour compléter sa demande déjà proposée ; un toner à surveiller n'y est ajouté
  qu'avec sa cartouche résolue (compteur « À surveiller en attente » du journal de la tâche). La demande
  proposée existante du groupe est complétée, sinon créée (contact prérempli
  depuis la fiche de la première imprimante). Chaque ligne : cartouche résolue (0 si non résolue, ligne
  bloquée à la validation), couverture contrat, prix 0 sous contrat ou vide. Verrous réévalués juste avant
  l'écriture ; une transaction par groupe, groupe en échec annulé en entier et journalisé.
- **Contrôles** (`Demande::checkLines()`, recalculés à l'instant pour les lignes ouvertes) : imprimante
  présente et dans l'entité de la demande, référence résolue, sous contrat / hors contrat, prix 0 interdit
  hors contrat, quantité, verrous (hors lignes de la demande elle-même).
- **Écran de validation** (onglet « Demandes d'envoi » : liste native, proposées par défaut ; fiche
  `front/demande.form.php`) : sur une demande proposée, mode / contact / commentaire de livraison, quantité
  (1 à 99), prix unitaire hors contrat (vide = Achats, **0 refusé**, champ inactif sous contrat) et annulation
  de ligne ; « Enregistrer » (`Demande::saveProposal()`, tout ou rien) et « Valider » (enregistre puis
  `Demande::validateDemande()`). Action de masse « Valider » sur la liste. Annulation d'une demande proposée
  ou validée avec motif obligatoire (`Demande::cancelDemande()`) ; une demande dont toutes les lignes sont
  annulées passe annulée.
- **Validation, tout ou rien** : contrôles recalculés ; une seule ligne bloquante refuse la validation avec
  les motifs. Sinon, en transaction : cartouche résolue, contrat et prix mis à jour (0 sous contrat, prix 0
  hérité retiré d'une ligne passée hors contrat), lignes et demande « validée », valideur et date.
- **Contrôles avant export** (`Demande::prepareExport()`, carte « Contrôles avant export Gesconso » de la
  fiche) : lignes validées passées dans `Gesconso::prepare()` — code client Sage, intitulé de livraison,
  référence article, prix. Une seule ligne en défaut empêche d'exporter la demande, avec la liste des lignes.
  Affichés dès la proposition, à titre indicatif.
- **Export** (`front/demande.export.php`, `Demande::exportDemandes()`, droit validation UPDATE) : un fichier
  Gesconso pour la sélection de demandes validées. **Envoyer aux Achats** : verrous revérifiés, puis en
  transaction une expédition par ligne (la ligne `exported` cède son verrou à l'expédition), demandes
  `exported`, fichier archivé sur les demandes et les expéditions, transmission « en attente » ; mail aux
  Achats APRÈS la transaction (voir « Transmission aux Achats ») ; échec d'archivage = rien n'est enregistré. **Télécharger (test, sans envoi)** : même fichier, archivé sur les demandes avec la
  mention « non transmis », noté dans leur historique, sans mail, sans changement de statut ni expédition.
  Une seule ligne en défaut refuse l'export entier.
- **Suivi après export** (`Demande::syncFromExpeditions()`, tâches `CheckAlerts` et `TrackingUpdate`, aussitôt
  après une action sur une expédition — expédier, éditer, réattribuer : `syncForExpedition()` — et bouton
  « Actualiser les statuts » de la fiche, en POST ; l'ouverture de la fiche n'écrit rien et signale seulement les
  lignes en retard) : chaque ligne exportée suit son expédition (en attente → exportée,
  expédiée ou en transit → expédiée, livrée → livrée, posée → posée, annulée → annulée) ; l'en-tête prend le
  statut le moins avancé des lignes non annulées, annulée si toutes le sont.
- **Droits** : `plugin_printgestion_validation` (READ voir, UPDATE modifier / valider / annuler). La file
  est aussi visible avec la lecture des alertes toner, sans pouvoir agir. Pas de création manuelle.

### Référentiel Sage (`inc/sageimport.class.php`, `inc/sage.class.php`)

- **Aucune liaison directe avec Sage** : un fichier exporté de Sage est déposé à la main (module « Référentiel
  Sage », droit `sage` UPDATE). Formats : xlsx, xls, ods, csv (encodage et séparateur détectés). Première
  feuille, ligne 1 = en-têtes, reconnus sans casse ni accent, libellés ou noms de champs Sage :

  | Référentiel | Colonnes (obligatoires en gras) | Rôle |
  |---|---|---|
  | Adresses de livraison | **Code client**, **Intitulé livraison** (`LI_Intitule`), Code adresse (`LI_No`), Adresse, Code postal, Ville | Vérification : un intitulé absent des adresses de son client donne un avertissement |
  | Articles | **Référence** (`AR_Ref`), Désignation (`AR_Design`) | Vérification bloquante : cartouche dont la référence (`CartridgeItem.ref`) est absente du dernier import |

- **Règle Gesconso** (`Sage::describeRule()`), sans table de correspondance ni onglet : le **code client** est le
  nom de l'entité de l'imprimante s'il a la forme d'un code client Sage (`Sage::CODE_PATTERN` : majuscules,
  chiffres, « . _ - », 17 caractères au plus, sans espace), sinon celui de l'entité parente la plus proche dont
  le nom a cette forme ; l'**intitulé de livraison** est la première ligne non vide du champ « Commentaires » de
  **cette même entité**, celle qui porte le code : code et intitulé sont le code et le nom d'un seul client, une
  imprimante en sous-entité prend les deux sur l'entité parente porteuse. Commentaires vides sur cette entité : la
  ligne est bloquée. La règle appliquée est affichée sur la
  fiche de la demande (« Export Gesconso (Sage) » : d'où viennent le code et l'intitulé, ce qui bloque, lien vers
  l'entité). `registration_number` (SIRET) n'est pas utilisé.
- **Déroulé** : analyse (contrôles : colonnes obligatoires, valeurs manquantes, doublons — bloquants ; codes
  clients en minuscules ou avec espaces — avertissement), prévisualisation (nouvelles, modifiées, inchangées,
  absentes) et **rapport d'écarts** (adresses : codes clients d'aucune entité à imprimantes ; entités à
  imprimantes sans code, sans intitulé, ou dont l'intitulé n'est pas une adresse de leur client dans le
  fichier ; articles : cartouches sans référence ou de référence absente du fichier), puis validation en
  transaction. L'analyse attend en session : rien n'est écrit avant validation. L'import ne modifie aucun
  objet GLPI.
- **Aucune suppression** : une ligne absente d'un nouvel import passe `is_in_last_import = 0` et ne sert plus
  à la vérification.
- **Périmètre** : rapport d'écarts limité aux entités de l'utilisateur ; le nom d'une entité hors de son
  périmètre n'y apparaît jamais.

### Fichier Gesconso (`inc/gesconso.class.php`)

Référence : le fichier réel `Gesconso_02122024_1034.xlsx`, importé avec succès dans Gesconso. Nom
`Gesconso_JJMMAAAA_HHMM.xlsx`, une feuille `Export`, ligne 1 = en-têtes, **exactement 9 colonnes** :

| Col | En-tête | Source |
|---|---|---|
| A | `Devis` | Date de la demande (commande directe : date du jour), **vraie date Excel** `jj/mm/aaaa` |
| B | `Intitule Client` | Code client Sage = nom de l'entité de l'imprimante s'il a la forme d'un code, sinon celui du parent le plus proche (`Sage::getClientForEntity()`) |
| C | `Intitule Livraison` | Première ligne des commentaires de l'entité qui porte le code, la même que la colonne B (`Sage::describeRule()`) |
| D | `Consommable` | `CartridgeItem.ref` (vérifiée dans le référentiel articles s'il a été importé) |
| E | `Designation` | n° série, nom du lieu, nom de la cartouche, joints par le séparateur (`' # '`) en omettant les parties vides (jamais de séparateur orphelin) ; longueur max (69) configurable, troncature avec avertissement |
| F | `Quantite` | Entier |
| G | `Prix` | 0 sous contrat ; vide ou prix saisi hors contrat — **jamais 0 hors contrat** |
| H | `Fournisseur` | Vide |
| I | `Complement livraison` | Commande directe : commentaire du lieu ; demande : contact et commentaire de livraison |

- **Contrôles bloquants** (`Gesconso::prepare()`) : code client absent (aucun nom d'entité en forme de code),
  intitulé de livraison absent (commentaires vides sur l'entité qui porte le code), référence article absente (cartouche non
  résolue, référence vide, ou inconnue du référentiel articles importé), prix 0 hors contrat. Une ligne en
  défaut n'est jamais écrite : l'appelant refuse l'export entier avec la liste des lignes en défaut.
  Avertissements, non bloquants : référentiel (articles, adresses) jamais importé ; intitulé de livraison absent
  des adresses importées du client ; imprimante sans lieu. Ces deux derniers sont aussi rendus en **notices**
  typées par ligne (`prepare()['notices']`) : l'écran « Envoyer aux Achats » et le sous-formulaire « Commander »
  en affichent le décompte (« 3 lignes avec une adresse de livraison non reconnue », « 2 lignes sans lieu sur
  l'imprimante ») avec la liste derrière « voir », avant le clic ; la confirmation d'envoi le rappelle.
- Codes et références écrits en texte explicite (zéros de tête conservés) ; cellules vides non écrites.
- **Transmission aux Achats** (`inc/purchaseorder.class.php`, table `purchaseorders`) : commande
  directe et export de demandes ENREGISTRENT d'abord (expéditions, fichier archivé, ligne de transmission
  `pending` avec les lignes du mail, dans la transaction), puis `Purchaseorder::send()` envoie le mail. Un SMTP
  peut signaler une erreur après avoir remis le message : un échec ne défait donc rien et ne renvoie rien tout
  seul. La commande reste `failed` (verrou anti-double-envoi posé), message « ENREGISTRÉE mais NON TRANSMISE »,
  carte « Commandes non transmises aux Achats » en tête des écrans Expéditions, Demandes d'envoi et Export
  (commandes dont toutes les expéditions sont dans le périmètre), bouton « Renvoyer aux Achats »
  (`front/purchaseorder.form.php`, droit validation UPDATE) qui renvoie le MÊME fichier archivé (nom d'origine
  en pièce jointe) et les mêmes lignes, jamais régénérés. Réservation atomique de l'envoi (`sending`) : double
  clic ou écrans simultanés n'envoient qu'une fois ; une commande `sent` n'est jamais renvoyée ; un envoi
  interrompu se renvoie après 15 min. Mails planification et courtoisie seulement si la commande est
  transmise ; `demande_exported` émis une fois l'export transmis. Surveillance : tâche `CheckAlerts`
  (horaire), commande non transmise depuis plus de 4 h (`STALE_HOURS`) → notification native
  `purchaseorder_not_sent`, une fois par commande, créée **active** (administrateur de GLPI et auteur).
- **Archivage** (`Gesconso::archive()`) : chaque fichier transmis devient un Document GLPI natif (nom
  `Gesconso_…xlsx`, commentaire date / auteur / volume), rattaché aux expéditions créées (commande directe)
  ou aux demandes exportées. Entité racine, **non récursif** : invisible des comptes clients. L'archivage est
  dans la transaction de la commande : échec d'archivage = commande non passée ; transaction annulée = copie
  du fichier retirée du dossier des documents. Contrôles (P0.4) : type de document `.xlsx` autorisé à l'import
  (`Document::isValidDoc()`, sinon GLPI supprime le fichier et le plugin refusait trop tard), document créé avec
  `_only_if_upload_succeed` (jamais de document sans fichier), copie présente dans le dossier des documents et
  fichier temporaire toujours lisible. Tout échec lève une exception de code `Gesconso::ARCHIVE_FAILURE` dont le
  message, affichable, dit la cause : commande directe et export de demandes l'affichent tel quel.
- **Pièce jointe obligatoire** : quand un fichier est attendu, `Config::sendMail()` et `Expedition::sendRawMail()`
  refusent d'envoyer si le fichier est absent, vide ou n'est pas attaché au message (`Email::getAttachments()`) :
  mail non envoyé, cause journalisée, et pour les Achats la commande est annulée. Le mail de planification d'une
  seule cartouche n'a jamais de pièce jointe (inchangé) ; le mail groupé garde la sienne.
- La commande directe (action de masse « Commander » de l'écran des alertes) utilise ce générateur : plus de colonne « Stock
  GLPI », plus de nom d'entité en code client.

### Notifications natives (`inc/notificationtargetdemande.class.php`, `inc/contractalert.class.php`)

- **Demandes d'envoi** : `NotificationTarget` natif (`notificationtemplates_types`), événements
  `demande_proposed` (proposition automatique), `demande_stale` (relance), `demande_exported` (envoi aux
  Achats), émis par `NotificationEvent::raiseEvent()` → file d'attente GLPI `QueuedNotification`. Balises
  `##demande.*##` et boucle `##FOREACHlines##` (`##line.printer##`, `toner`, `cartridge`, `quantity`,
  `contract`, `status`). Gabarits et notifications créés à l'installation **inactifs** (destinataire par
  défaut : administrateur de l'entité) : choisir les destinataires (profil, groupe) puis activer.
- **Relance** (`Demande::sendReminders()`, tâche `CheckAlerts`) : proposée non validée, ou validée non
  exportée, depuis `demande_reminder_days` jours (2 par défaut, 0 = désactivé) ; au plus une relance par
  période (`date_last_reminder`).
- Un échec d'émission est journalisé (contexte `notifications`) et n'interrompt jamais le traitement.
- **Alertes de contrat natives** (Configuration → « Alertes de contrat natives ») : état de chaque maillon
  et bouton d'activation (droit GLPI `config` UPDATE) — action automatique `contract`, alertes de l'entité
  racine (délai 30 jours s'il n'y en a pas), notifications de contrat. La configuration globale des
  notifications GLPI n'est jamais modifiée : si elle est désactivée, c'est signalé.
- Les mails historiques du plugin (Achats, planification, courtoisie, digests) restent sur leurs gabarits
  (§6) : pas de refonte globale.

### Déploiement Agent — phase 1 (`inc/agentdeploy.class.php`, module « Collecte SNMP / Déploiement Agent »)

Installer GLPI Agent sur un PC du client (la sonde) sans compétence GLPI ni ligne de commande, et vérifier le
rattachement à l'entité. Rien de ce que GLPI fait nativement n'est redéveloppé : champ TAG de l'entité, règle
d'affectation « Entity from TAG », fiche Agent (lien seulement).

- **Onglet « Déploiement Agent » de l'entité** (droit `deploiement` READ ; module, droit et accès à l'entité
  revérifiés à l'affichage) :
  1. état du rattachement : TAG (vide : avertissement bloquant et lien vers « Informations avancées » ; caractères
     hors `[A-Za-z0-9._-]` ou TAG porté par plusieurs entités : paquet refusé) ; règle `RuleImportEntity` portant
     l'action `_affect_entity_by_tag` (active, position, règles actives jouées avant elle — le moteur s'arrête à
     la première qui correspond ; créée par `Agentdeploy::createTagRule()` seulement sur clic explicite d'un administrateur — droit de configuration du plugin et droit natif `rule_import` en création —, une seule règle générique, jamais recréée ni déplacée ; règle désactivée = règle absente : `getTagRuleForm()` rend « Créer la règle d'affectation par TAG » si aucune n'existe, « Activer la règle » (`activateTagRule()`, droit de configuration du plugin et `rule_import` en modification, première règle désactivée dans l'ordre, rien de créé ni déplacé) si elle est désactivée, rien si une est active ; bouton visible hors chevron, aussi dans la carte Prérequis de la page Installeur ; `createTag()` crée ou active la règle dans le même clic quand l'auteur du TAG en a les droits, sinon enregistre le TAG seul et signale la règle manquante ou désactivée) ; plugin GLPI Inventory ; agents de l'entité
     (`glpi_agents.entities_id`) : version et conformité, dernier contact et « muet », TAG différent de celui de
     l'entité, modules découverte et inventaire réseau ;
  2. installeur : **déploiement bloqué** tant que `getDeployBlockers()` renvoie un motif (TAG absent, inutilisable
     ou porté par plusieurs entités ; règle d'affectation par TAG absente ou désactivée ; URL de l'application GLPI
     vide, locale ou sans schéma, `getApplicationUrlIssue()`) — règle : on bloque ce qui ne se répare pas sans
     retourner sur le site (les règles d'entité ne jouent qu'au premier import ; un agent avec une URL fausse ne
     contacte jamais GLPI), on avertit pour le reste. Message
     « Configuration incomplète — le déploiement est bloqué » ; « Contactez l'administrateur » seulement si
     l'utilisateur ne peut pas lever lui-même tout le blocage ; les actions qui le lèvent (formulaire du TAG, bouton
     créer ou activer la règle) sont affichées juste en dessous. Même contrôle côté serveur : `getPackageBlockers()`
     inclut ces motifs, `agentdeploy.download.php` refuse le paquet (URL directe comprise). Ce qui empêche toute
     remontée mais se répare après coup (inventaire GLPI désactivé, GLPI Inventory posé mais inutilisable, actions
     automatiques en mode GLPI ou cron arrêté : contrôles obligatoires de `Confighealth::getChecks()` hors
     TAG/règle/URL) : ligne rouge « Rien ne remontera pour l'instant — contactez l'administrateur », sans blocage,
     causes et liens repliés pour l'administrateur ; l'onglet n'est jamais vert quand rien ne remontera. **GLPI
     Inventory simplement absent n'en fait pas partie** : le fichier bascule alors sur le scan local et les
     imprimantes remontent. L'écran l'annonce pour ce que c'est — ligne bleue « Mode local : chaque PC sonde
     scannera lui-même », avec ce qui change (ni plage, ni tâche, ni raccordement dans GLPI ; cadence réglée sur le
     PC). Dire « rien ne remontera » était vrai avant le mode local, et faux depuis. Boutons Windows, Linux et
     macOS (actifs dès que leurs fichiers officiels sont vérifiés et le rattachement complet), commande
     Windows et propriétés MSI expliquées, commande Linux, procédure macOS et son `local.cfg` (phase 6).
- **Fichiers uniques : ce que servent les trois boutons** (`front/agentdeploy.download.php`, `os=windows|linux|macos`,
  droit `deploiement` READ et accès à l'entité). Un seul fichier par système, quelques kilo-octets, rendu sans fichier
  temporaire : il va chercher l'installeur officiel sur ce serveur avec une clé à usage unique, **recalcule son
  SHA-256 et refuse tout écart**, puis installe. Une fenêtre s'il y en a une sur le poste (WinForms, zenity,
  osascript), la question en console sinon — une fenêtre est un confort, jamais un passage obligé, et une fenêtre
  **indisponible** ne vaut jamais une annulation (osascript rend le même code pour « Annuler » et pour « pas de
  session graphique » : on distingue sur le message, et dans le doute on redemande en console).
  `Agentdeploy::buildWindowsDialogLines()` et les briques shell (`buildLinuxUiLines()`, `buildMacosUiLines()`) écrivent la fenêtre une seule fois pour tous les
  paquets : une seule case de mise à jour, au même état par défaut (décochée), quel que soit le système.
  - **Windows** : un `.bat` dont la seconde moitié est du PowerShell. La moitié cmd (ASCII pur) ne
  fait que trois choses : contrôle administrateur, écriture de la partie qui suit le marqueur `#PG-POWERSHELL` dans
  `%TEMP%`, et lancement ; elle s'arrête sur `exit /b`, si bien que cmd ne lit jamais le PowerShell. Deux parties
  dans un fichier parce que Windows n'exécute pas un `.ps1` au double-clic (il l'ouvre dans le Bloc-notes). Rien
  n'est encodé ni caché : le technicien et l'antivirus lisent tout.
    **Une seule fenêtre du début à la fin, et sans console** — voir « Fenêtre unique et journal » plus bas. Le
    téléchargement se fait **morceau par morceau** (`HttpWebRequest` + lecture par blocs de 256 Ko, TLS 1.2 forcé) pour
    pouvoir annoncer « 12 Mo sur 22 Mo » — `WebClient.DownloadFile` ne rend la main qu'à la fin et ne sait rien dire
    pendant. L'installation, elle, n'a pas d'avancement à donner : barre défilante, et `Start-Process` **sans
    `-Wait`** (on interroge `HasExited` avec `DoEvents`, sinon la fenêtre blanchit). Un `trap` rattrape l'imprévu :
    « Stop » arrêterait tout sans un mot. **Aucune panne n'est muette**, à aucun moment : une fois la fenêtre
    construite (`$script:fenetre_prete`, posé à la fin de `buildWindowsWizardLines()`), le piège passe par `Echec()`
    et la page des étapes ; avant, par `Secours()` (`psFormsHeader()`), une boîte de message Windows avec l'erreur et
    le chemin du journal — `Echec()` touchait alors une fenêtre pas encore construite, l'erreur de l'erreur était
    avalée et PowerShell sortait sans rien montrer. Un script que PowerShell ne sait même pas lire ne démarre pas
    du tout, et aucun piège ne joue : le lanceur l'analyse donc juste après l'avoir écrit
    (`buildWindowsParseCheck()`, `Parser::ParseFile`), tant que la console est là — erreurs dans
    `%TEMP%\PrintGestion\lanceur-*.log`, boîte de message « fichier abîmé, retéléchargez-le », code 2. Toute chaîne
    passe par `psQuote()`, qui double l'apostrophe droite **et** les typographiques ’ ‘ ‚ ‛ : PowerShell les prend
    toutes pour des apostrophes, et un client « L’Atelier » rendait sinon le fichier entier illisible.
    Essais : `essai_windows_complet.py` (installation et retrait lancés sans console, avec et sans panne simulée),
    `essai_lanceur_analyse.py` (fichier sain, fichier abîmé).
  - **Linux** : un `.sh` (`sudo sh <fichier>`) — contrôle root, fenêtre zenity ou question en console (mise à jour
    automatique **non** par défaut), téléchargement (curl, sinon wget), empreinte (`sha256sum`, sinon `shasum`, sinon
    `openssl` ; **aucun des trois : on refuse d'installer**, faute de pouvoir vérifier), pose de
    `/etc/glpi-agent/conf.d/90-printgestion.cfg` (réessais SNMP, absents des options de l'installeur), installeur Perl
    officiel avec la découverte et l'inventaire réseau, puis la tâche cron mensuelle si elle a été acceptée.
  - **macOS** : un `.sh` (`sudo sh <fichier>`) qui fait les quatre gestes que le technicien faisait à la main — il lit
    la puce (`uname -m`), ne télécharge **que** le paquet de cette puce, vérifie l'empreinte, `installer -pkg`, pose
    `local.cfg` puis relance le service (`launchctl bootout`/`bootstrap`, avec repli `unload`/`load` pour macOS 12 et
    avant). Aucune question de mise à jour : sur macOS elle est manuelle, et une case qui ne ferait rien serait un
    mensonge. La clé ouvre les deux paquets (GLPI ne sait pas sur quel Mac le fichier tournera) mais ne sert qu'une
    fois, puisque le Mac n'en télécharge qu'un.

  Pourquoi la clé plutôt que l'installeur dans le fichier : plusieurs mégaoctets encodés dans un script sont le motif
  que les antivirus refusent le plus volontiers, et un exécutable fabriqué ici ne serait pas signé (SmartScreen,
  Gatekeeper). Ces fichiers portent donc l'URL du serveur, le TAG, l'empreinte attendue et **une clé de récupération à
  usage unique valable 24 h** : ce sont les seuls livrables du plugin qui portent un secret. Ils se donnent au
  technicien pour l'intervention, ils ne s'archivent pas ; l'historique de l'entité note l'émission de la clé avec sa
  date de péremption, et le panneau « Comment lancer le fichier téléchargé » compte celles qui valent encore.
- **Fenêtre unique et journal (Windows, Linux, macOS).** Installation comme retrait : **une** fenêtre qui change de
  page — réglages (ou confirmation, pour le retrait), puis les étapes cochées une à une, puis le résultat au même
  endroit avec « Ouvrir le journal » et « Fermer ». Plus de boîte de message ni de seconde fenêtre.
  - **Windows** (`buildWindowsFrameLines()`, `buildWindowsWizardLines()`, `buildWindowsChoiceLines()`,
    `buildWindowsConfirmLines()`, `buildWindowsLauncherLines()`) : PowerShell est lancé **d'emblée sans console**
    (`Start-Process -WindowStyle Hidden`). La cacher après coup ne marche pas sous Windows 11 quand le Terminal Windows
    est l'hôte par défaut : la fenêtre à cacher n'est alors plus celle qu'on croit. Reste l'éclair d'une seconde de
    cmd — le prix d'un `.bat`, dont les deux autres formes sont pires (`.ps1` ouvert dans le Bloc-notes, `.exe` non
    signé arrêté par SmartScreen). Conséquence : **plus aucun `pause`** après le lancement (il bloquerait tout, sans
    console pour le voir) — contrôlé par `verifier_scripts.py`. Autre conséquence : Windows applique ce « masqué »
    au **premier affichage d'une fenêtre** du processus — la nôtre. Elle existait sans être à l'écran et attendait
    un clic impossible : la console clignotait, le journal s'arrêtait à la ligne « PC : », sans erreur. La fenêtre
    est donc affichée deux fois (`$f.Show(); $f.Hide(); $f.Show()`), le deuxième affichage étant respecté ; même
    geste dans le garde-fou de `Fin`. Mesuré par `IsWindowVisible` : l'état `Visible` de .NET répond « oui » dans
    les deux cas, il ne prouve rien. La boîte de message de `Secours()` n'est pas touchée par ce masque. Les boutons n'ont plus de `DialogResult` : la page 1
    attend son clic sans fermer la fenêtre, qui continue page suivante ; pendant le travail la croix ne ferme rien.
    Le lanceur nettoie ses propres lignes `rem` et `title` : cmd exécute `< > | &` même là, et le nom d'entité
    « Root entity > EASI SUPPORT » créait un fichier parasite à chaque lancement du fichier de retrait.
  - **Linux** (`buildLinuxUiLines()`) : zenity, sous le compte de la personne connectée (`SUDO_USER`) et non en root
    — depuis Wayland, root n'ouvre plus de fenêtre sur la session d'un autre ; l'affichage est retrouvé même quand sudo
    a effacé `DISPLAY` (socket X11 ou Wayland de la session). Un formulaire (`zenity --forms`, les trois questions
    ensemble), puis une fenêtre d'avancement qui se termine sur le résultat. zenity ne change pas de page : deux
    fenêtres qui se suivent, jamais deux à la fois. Chaque option du formulaire finit par une continuation, la dernière
    comprise : sans elle, `2>/dev/null)` deviendrait une commande à part et `$?` vaudrait toujours 0 — « Annuler »
    aurait lancé l'installation.
  - **macOS** (`buildMacosUiLines()`, `resources/macos-fenetre.js`) : une vraie fenêtre Cocoa en JavaScript for
    Automation, avec les mêmes pages que sous Windows. Le script tourne en root, la fenêtre sous le compte de la
    personne connectée (`launchctl asuser` puis `sudo -u`). Ils se parlent par deux fichiers dans un dossier privé
    (700, à la personne connectée) : `reponses` écrit par la fenêtre, `etat` écrit par le script (`dire|…`, `pct|…`,
    `etape|clé|état|note`, `fin|OK|message`). En partant, le script attend la fermeture de la fenêtre : elle doit
    avoir lu la fin avant que son dossier disparaisse. La fenêtre est un fichier à part entière pour être relue par
    `node --check` ; le fichier de l'entité la recopie, précédée de « var T = {...}; » qui porte les textes.
  - **Une fenêtre qui ne s'ouvre pas ne vaut jamais une annulation** : session SSH, personne à l'écran, zenity absent
    — on reprend en console, avec les mêmes étapes (`[ OK ]`, `[ECHEC]`, `[ -- ]`) et le même journal.
  - **L'interface d'étapes est commune à Linux et macOS** (`pg_etape`, `pg_dire`, `pg_pct`, `pg_fin`, `pg_echec`),
    et deux étapes sont partagées mot pour mot : le premier contact (`buildShellContactLines()`) et le compte rendu
    avec ce qu'il déclenche (`buildShellReportLines()`).
  - **Le journal** (`buildWindowsJournalLines()`, `buildShellJournalLines()`) : un fichier par exécution —
    `%TEMP%\PrintGestion\installation-AAAAMMJJ-HHMMSS.log` sous Windows, `/var/tmp/printgestion-…log` ailleurs (et non
    `/tmp`, vidé au redémarrage). Chaque étape horodatée (DEBUT, OK, ECHEC, SAUTE), avec ce qui sert à comprendre :
    empreinte attendue et reçue, code de retour de l'installeur, réponse de GLPI. La sortie des installeurs et des
    gestionnaires de paquets y part au lieu de défiler dans le terminal. **Jamais la communauté SNMP ni la clé de
    téléchargement** : ce sont des secrets, et ce fichier traîne dans un dossier temporaire.
  - **Nouvelle étape « Premier contact avec GLPI »**, avant le compte rendu. Il partait dès la fin de l'installation,
    souvent avant le premier inventaire de l'agent : GLPI ne connaissait pas encore la sonde, et ne pouvait ni régler
    ses modules ni lui confier les imprimantes. Le script attend désormais que l'agent local ait fini un passage (son
    état `/status` redevient « waiting », 2 + 3 minutes au plus). Si GLPI ne la connaît toujours pas, il le dit :
    réponse **`NOAGENT`** au compte rendu, et la fenêtre l'annonce au lieu d'un succès. Un ancien fichier, qui ne
    connaît pas cette réponse, l'ignore.
- **Les adresses des imprimantes se saisissent sur le PC** (fenêtre d'installation du fichier unique, Windows,
  Linux et macOS) : deux champs, « Adresses IP des imprimantes » (même syntaxe que l'assistant : `192.168.1.0/24`,
  `192.168.1.30-35`, une liste) et « Communauté SNMP » (préremplie `public`). Elles partent avec le compte rendu, et
  **GLPI crée le raccordement tout seul, ET lance la découverte** (`Raccordement::createFromInstaller()`) : plage IP, identifiants SNMP,
  tâches de découverte et d'inventaire — exactement ce que l'assistant crée, dans un raccordement visible dans son
  écran, avec son journal étape par étape. Le but est qu'un technicien reparte sans rien avoir à faire dans GLPI.
  La sonde est retrouvée par le **nom de son PC** : au moment du compte rendu, le fichier ne connaît rien d'autre de
  GLPI. Sans communauté SNMP, la création s'arrête après les adresses — inventer des identifiants serait pire que de
  laisser un clic à faire. La communauté n'est **jamais gardée** dans les mémos du plugin : elle sert à créer (ou
  retrouver) les identifiants SNMP de GLPI, c'est là qu'elle vit.
- **La fréquence des relevés se choisit aussi dans la fenêtre** (`Collectfrequency::INSTALLER_CHOICES`) : six choix
  et pas un de plus — toutes les heures, 3 h, 6 h, **une fois par jour (défaut)**, 2 semaines, un mois. Un technicien
  devant une liste de vingt ne choisit pas, il prend le premier. Le code part avec le compte rendu, dans une liste
  fermée : ce qui vient d'un poste client est refusé s'il n'en fait pas partie.
  Le serveur l'enregistre comme **fréquence de relevés de l'entité** — la même valeur que le chevron « Fréquence des
  relevés » de l'onglet Déploiement Agent — **avant** de créer le raccordement, pour que les tâches naissent
  directement à la bonne cadence. Et parce que `Collectfrequency::getSilentDaysForEntity()` vaut
  `max(silent_days, fréquence + 1 jour)`, le seuil « cette imprimante ne remonte plus » suit tout seul : un parc
  relevé une fois par mois n'est pas signalé en retard au bout de trois jours.
  **Ce que cela ne change pas, volontairement** : l'alerte « sonde sans contact ». L'agent contacte GLPI à la
  fréquence d'inventaire de GLPI (24 h par défaut), qu'il scanne ou non ; faire suivre cette alerte à un scan
  mensuel reviendrait à ne plus voir pendant un mois une sonde réellement morte.
- **Sans GLPI Inventory, le scan est confié à la ToolBox de l'agent** (`Agentsetting::buildToolboxYaml()`,
  `buildToolboxPluginConfig()`) : le plugin voisin apporte la *planification* des tâches réseau, mais la *réception*
  des inventaires est native dans GLPI. Quand il manque, le compte rendu répond `SCAN <première> <dernière> <délai>`
  — la plage est calculée par l'analyseur d'adresses du plugin, côté serveur, et le délai vient du choix de
  fréquence (`Collectfrequency::INSTALLER_CHOICES`, troisième valeur : `1h`, `3h`, `6h`, `1d`, `2w`, `30d`). Le
  fichier d'installation écrit alors, dans le dossier `etc` de l'agent (`C:\Program Files\GLPI-Agent\etc`,
  `/etc/glpi-agent`, `/Applications/GLPI-Agent/etc`) :
  - `toolbox.yaml` : une communauté (`credentials`, SNMP v2c), une plage (`ip_range`), une planification
    (`scheduling`, `type: delay`) et une tâche `netscan` (`jobs`, 10 fils, délai d'attente 1 s, `target: server0`,
    c'est-à-dire le serveur GLPI de l'agent). Sans `next_run_date`, l'agent lance la tâche dès son redémarrage ;
  - `toolbox-plugin.local` : `disabled = no`, `forbid_not_trusted = yes` (l'interface n'est ouverte qu'aux adresses
    de `httpd-trust`, 127.0.0.1 ici).
  Puis l'agent est redémarré. C'est **l'agent lui-même** qui planifie, scanne et envoie : plus de tâche planifiée
  (`schtasks`, `/etc/cron.d`) ni de script `glpi-netdiscovery` + `glpi-injector` maison, et la tâche se voit et se
  modifie à la main sur `http://127.0.0.1:62354/toolbox` du PC sonde. Le format a été relu dans les sources de
  GLPI Agent (≥ 1.6) : `lib/GLPI/Agent/HTTP/Server/ToolBox*.pm`. Les restes de l'ancienne tâche maison
  (`PrintGestion-Scan-Imprimantes`, `glpi-scan-imprimantes.cmd`, `/etc/cron.d/printgestion-scan`) sont retirés à
  l'installation. Windows, Linux et macOS. **Ce que ça coûte** : la communauté SNMP se retrouve dans `toolbox.yaml`
  — seul moyen de scanner quand aucun serveur n'est là pour le dire. Le fichier est réservé à SYSTEM et aux
  administrateurs sous Windows (`icacls /inheritance:r`), `chmod 600` ailleurs ; la communauté est écrite entre
  apostrophes YAML (apostrophe doublée) et n'apparaît jamais dans le journal.
- **Journal de l'agent** : sous Windows, celui que le MSI pose par défaut (`C:\Program Files\GLPI-Agent\logs\glpi-agent.log`) ;
  sous Linux et macOS, `logger = file`, `logfile = /var/log/glpi-agent.log`, `logfile-maxsize = 4` dans la
  configuration posée (`Agentdeploy::buildAgentConfig()`). Le chemin est écrit dans le journal de l'installation et
  dans la fenêtre de fin : c'est le premier fichier à demander quand une sonde ne remonte rien. Le retrait l'efface sous Linux et macOS ; sous Windows il
  part avec le dossier de l'agent si la désinstallation du MSI le retire.
- **Ce qui tient un fichier est fermé avant de l'effacer** (retrait Windows, étape « Fichiers ») : le MSI vient
  d'arrêter le service, qui lâche son journal une à deux secondes plus tard — la suppression tombait pile dans cet
  intervalle et laissait `C:\Program Files\GLPI-Agent`. Trois essais : au premier échec, le service est arrêté et
  les programmes lancés **depuis le dossier visé** sont fermés (comparaison sur le chemin, jamais sur le nom : un
  programme du client au nom voisin n'est pas touché), puis deux secondes d'attente. Si le dossier résiste encore,
  la fenêtre le dit sans alarmer — c'est sans effet sur une réinstallation.
- **Une tâche automatique fait avancer les raccordements lancés** (`Raccordement::cronPrintgestionRaccordements()`,
  toutes les 10 minutes, enregistrée avec les autres dans `Reminder::install()`). C'était le chaînon manquant :
  préparer le relevé des niveaux après la découverte n'arrivait que si **quelqu'un regardait** — l'écran du
  raccordement, ou la fenêtre d'installation pendant ses quatre minutes. Un technicien reparti, une découverte un
  peu longue sur un /24, et le raccordement restait figé alors que tout était prêt. La tâche reprend les
  raccordements **lancés depuis moins de vingt-quatre heures** (pas les trente minutes de l'écran : une sonde
  appelle GLPI au moins une fois par jour) et appelle `Collectsetup::verify()`, qui repose au passage les modules
  réseau de la sonde et remonte ses erreurs au journal du raccordement.
- **Un dossier encore tenu au retrait ne reste pas pour toujours** : `Fermer()` arrête les services de l'agent,
  ferme les programmes lancés depuis le dossier **et** ceux qui y ont chargé une bibliothèque ; si la suppression
  échoue quand même (un éditeur ouvert sur `logs\glpi-agent.log` suffit), le dossier est confié à Windows par
  `MoveFileEx(..., MOVEFILE_DELAY_UNTIL_REBOOT)` — les fichiers d'abord, puis les dossiers du plus profond au
  moins profond, une entrée programmée n'emportant un dossier que s'il est vide à ce moment-là. Piège vérifié à
  l'essai : la destination doit être `[NullString]::Value` et non `$null`, que PowerShell convertit en chaîne
  vide — Windows répond alors « chemin introuvable » (code 3) et ne programme rien. L'appel exige les droits
  administrateur (code 5 sinon) ; le fichier de retrait tourne toujours élevé.
- **Le scan local ne dépend pas de la minuterie de la ToolBox** : le fichier appuie lui-même sur « Run task »
  (`POST 127.0.0.1:62354/toolbox/inventory`, champs `submit/run-now` et `checkbox/<tâche>`, encodés comme le ferait
  un navigateur), juste après avoir relancé le service — six essais espacés de cinq secondes, le temps que le port
  se rouvre. `Inventory::_submit_runnow()` appelle `netscan()` immédiatement, puis reprogramme la cadence normale :
  c'est exactement le bouton de l'interface, en un appel HTTP local qui n'affiche rien. Pourquoi : le code de
  l'agent annonce un premier passage « dans la minute » pour une tâche jamais lancée (`_get_next_run_date()`
  ramène la date à maintenant, avec `rand(60)`), mais mesuré chez un client, journal de l'agent à l'appui, il est
  parti au bout de 5 min 30, puis de 14 min 04 — la seconde fois à la seconde où quelqu'un a ouvert la page de la
  ToolBox. Un échec de cet appel ne casse rien : le scan partira à sa cadence, comme avant.
- **Le suivi répond aussi en mode local** (`front/agentprogress.php`) : sans GLPI Inventory il n'y a pas de
  raccordement, et le suivi ne savait lire que ça — la fenêtre restait muette jusqu'au bout de son délai, même
  quand la ToolBox avait parfaitement scanné. **Le mode vient de la clé de suivi**, jamais de ce qui traîne en
  base : un poste déjà utilisé en mode piloté garde son raccordement dans GLPI, et le suivi lisait alors SA table
  d'adresses — que personne ne remplit quand c'est la ToolBox qui scanne. En local, il reconnaît les imprimantes
  aux **adresses saisies par le technicien** : la clé de suivi les emporte (`Agenttoken::createProgress($entities_id, $computer, $ips)`), et
  l'écran cherche les imprimantes de l'entité qui portent une de ces adresses (`glpi_ipaddresses`, colonnes
  `mainitemtype`/`mainitems_id`, comme partout ailleurs dans le plugin), avec le même compte de niveaux. Une
  imprimante déjà connue ne compte que si elle vient d'être relevée : sur un parc déjà inventorié, la fenêtre
  annoncerait sinon « trouvée » avant même le scan de la sonde. Deux témoins, le plus récent l'emporte — la date
  d'inventaire que GLPI pose sur la fiche (`glpi_printers.last_inventory_update`, le seul qui bouge quand une
  imprimante déjà connue est simplement relevée à nouveau) et le journal d'import (`Collect::getImportDates()`,
  qui couvre aussi les passages de GLPI Inventory). **La borne est calculée par la base**, pas par PHP : les deux
  horloges du serveur ne sont pas toujours réglées sur le même fuseau (MySQL en UTC, PHP à l'heure de Paris :
  constaté chez un client, deux heures d'écart), et une imprimante relevée à l'instant passait alors pour plus
  vieille que la clé. On mesure l'âge de la clé en secondes — une différence entre deux instants PHP, insensible
  au fuseau — et `DATE_SUB(NOW(), INTERVAL <âge> SECOND)` en fait une date dans l'horloge qui a écrit les lignes.
  Pourquoi pas par l'agent du journal d'import (`glpi_rulematchedlogs.agents_id`, le premier chemin retenu) : en
  inventaire natif, cette ligne porte l'agent rattaché à l'imprimante elle-même, jamais la sonde — la fenêtre
  attendait six minutes sans rien voir pendant que l'imprimante entrait dans GLPI avec ses cartouches (constaté
  chez un client le 25/09/2026). Ce chemin reste en repli pour les clés d'anciens fichiers, sans adresses.
- **Un refus du raccordement remonte jusqu'au PC** : `agentreport.php` renvoie `ERREUR <cause>` (plage en
  chevauchement, identifiants déjà pris, tâche désactivée…) et la fenêtre l'affiche à l'étape « Compte rendu ».
  Avant, tout cela finissait en réponse vide et « rien à lancer » : la raison n'existait que côté serveur, et le
  technicien était déjà parti. Les erreurs de pose des modules sont désormais journalisées en **erreur**, pas en
  information.
- **La fenêtre attend aussi les niveaux, et réveille l'agent pour ne pas les attendre un jour.** GLPI ne pousse
  rien vers une sonde : le relevé SNMP préparé après la découverte reste en attente jusqu'au prochain appel de
  l'agent — vingt-quatre heures au pire, alors que le technicien est encore devant le PC. Le suivi
  (`front/agentprogress.php`) renvoie donc `TROUVE <imprimantes> <avec niveaux>`, et le script, qui tourne **sur le
  poste**, redemande un passage à l'agent local (`127.0.0.1:62354/now`) dès qu'il voit des imprimantes sans
  niveaux, puis attend. La fenêtre annonce « 1 imprimante(s) trouvée(s) et ajoutée(s) dans GLPI, niveaux relevés »
  — ou « niveaux au prochain passage de la sonde » si le délai passe, jamais un faux échec. Sur un parc, elle
  attend que **toutes** les imprimantes trouvées aient leurs niveaux (annoncer « relevés » dès la première serait
  faux neuf fois sur dix), et dit le compte exact quand le délai passe : « 10 imprimantes trouvées, 7 niveaux
  relevés — les autres au prochain passage de la sonde ». Surveillance : dix minutes (`WATCH_TRIES` × `WATCH_WAIT`),
  dans les deux modes, et elle s'arrête dès que tout est remonté — attendre ne coûte donc rien. Mesuré chez un
  client : la ToolBox de l'agent met jusqu'à 5 min 30 avant son premier scan, et un /24 avec plusieurs imprimantes
  prend plus longtemps qu'une seule adresse. La clé de suivi vit un quart d'heure (`PROGRESS_TTL`), plus que la
  surveillance : une clé qui expire avant la fin ferait échouer les derniers appels, ceux qui rapportent enfin
  quelque chose.
- **La fenêtre d'installation tient en trois pages** (`buildWindowsDialogLines()`, `resources/macos-fenetre.js`) :
  *l'agent*, *les imprimantes*, *le scan* — avec « Précédent » et « Suivant », « Installer » n'apparaissant qu'à la
  dernière (la touche Entrée suit le bouton visible, on n'installe donc pas depuis la première page). Les cinq
  questions d'un seul tenant faisaient une fenêtre de près de mille pixels, coupée sur un petit écran. C'est
  toujours **une seule fenêtre** : les étapes et le résultat prennent ensuite la même place. Les trois pages ont la
  hauteur de la plus chargée, mesurée à l'exécution — une traduction plus longue ou un écran à 125 % ne coupe rien.
  Sous Linux, zenity ne sait pas paginer : le formulaire reste d'un seul tenant, avec des libellés courts.
  Piège rencontré : la variable de page s'appelle `page_reglages`, car `page` désigne déjà la liste des étapes —
  l'écraser cassait la suite de l'installation, ce que la simulation a montré immédiatement.
- **Une imprimante qui répond mais dont la fiche est ailleurs est nommée comme telle** (`Collectsetup::classify()`,
  `Raccordement::showProgress()`) : le rapprochement adresse ↔ équipement **n'écarte plus les fiches en corbeille**.
  GLPI reconnaît un appareil à son adresse MAC, même supprimé : une imprimante parfaitement joignable dont la fiche
  dormait dans une autre entité, à la corbeille, était annoncée « Pas de réponse SNMP » — et l'on cherchait un
  problème de réseau qui n'existait pas. L'écran dit maintenant l'entité et l'état de la fiche, et un bouton
  **« Ramener ici »** la restaure puis la rattache à l'entité du raccordement (`Printer::restore()` puis
  `update()`, donc avec l'historique et les hooks), avant de refaire le point.
- **La fenêtre attend la découverte et nomme ce qu'elle a trouvé** (`front/agentprogress.php`,
  `Agentdeploy::watchTexts()`) : « 2 imprimante(s) trouvée(s) et ajoutée(s) dans GLPI », puis leurs noms dans le
  message de fin. Le compte rendu renvoie au PC une **clé de suivi** — dix minutes, plusieurs lectures, une entité
  et un PC — et la fenêtre interroge cette adresse toutes les dix secondes, quatre minutes au plus. Chaque appel
  **fait avancer le raccordement** côté serveur (`Collectsetup::verify()` : relevé SNMP préparé dès la découverte
  finie, sonde rappelée) : sans lui, rien ne bouge tant que personne n'ouvre l'écran du raccordement dans GLPI, et
  le technicien repart sans savoir. La réponse ne porte que des noms — ni adresse, ni série, ni identifiant SNMP —
  et vaut `ATTENTE`, `AUCUNE`, ou `TROUVE <n>` suivi d'un nom par ligne. Délai dépassé : « lancée ; le résultat
  s'affichera dans GLPI », jamais un faux échec. Windows, Linux et macOS, y compris en mode local (ToolBox).
- **Deux versions SNMP posées, v2c puis v1** (`Collectsetup::planCompanionCredential()`) : beaucoup d'imprimantes
  n'exposent que SNMPv1 — les Canon iR-ADV entre autres — et un identifiant v2c seul reste sans réponse, sans que
  rien ne le dise. Les deux identifiants portent la même communauté et sont liés à la plage dans cet ordre ; GLPI
  Inventory les essaie par rang et garde celui qui répond. Même chose dans la ToolBox de l'agent (`toolbox.yaml` :
  `…-snmp` en v2c et `…-snmp-v1`). L'assistant de raccordement propose « v2c et v1 » par défaut, chaque version
  seule restant possible ; l'installation depuis le PC pose toujours les deux — personne n'est devant GLPI pour
  s'apercevoir du contraire, et le technicien est déjà reparti.
- **Qui pilote le scan, au choix du technicien** (`Agentdeploy::pilotChoices()`) : la fenêtre d'installation
  propose *piloté par GLPI* (par défaut) ou *en local, par l'agent de ce PC*, et le compte rendu part avec
  `mode=glpi|local`. Le serveur suit : raccordement et tâches GLPI Inventory d'un côté, réponse `SCAN` qui arme la
  ToolBox de l'agent de l'autre. Le choix **n'apparaît pas** quand GLPI Inventory est absent du serveur au moment
  où le fichier est fabriqué : une phrase dit alors que le scan sera local, puisque c'est le seul chemin possible.
  Les deux font le même travail sur le réseau — même agent, même SNMP ; ce qui change est qui planifie, où vit la
  communauté SNMP, et si l'on peut y revenir à distance. Windows (liste déroulante), Linux (liste du formulaire
  zenity, menu numéroté en console), macOS (menu de la fenêtre Cocoa, même menu en console).
- **Retirer une sonde** (`Agentdeploy::buildRemovalFile()`, `os=windows|linux|macos-retrait`) : un fichier par
  système, sous les boutons d'installation. Il retire la tâche de mise à jour, celle du scan, les fichiers du
  plugin, puis l'agent — Windows par la clé de désinstallation du registre (winget peut être absent, ou ne rien
  connaître d'un agent installé par le MSI), Linux par le gestionnaire de paquets de la distribution, macOS par
  l'arrêt du service et l'oubli du paquet. Il rend compte à GLPI (`off=1`) : sans cela, la fiche d'une sonde
  continuerait d'afficher une tâche posée sur une machine qui n'a plus d'agent.
  - **Ce qu'on supprime dans GLPI** : trois choix exclusifs dans la fenêtre, le premier pris — *ne rien supprimer*,
    *retirer la sonde* (`gl=1`), *tout supprimer* (`gl=2` : la sonde, les imprimantes qu'elle a fait entrer, la
    fiche de l'ordinateur). Boutons radio sous Windows et macOS, liste à choix unique sous Linux (zenity n'a pas de
    case dans une question), et le même menu en console quand il n'y a pas d'écran. Tout passe par les classes
    natives — `Agent`, `Printer`, `Computer` — donc par leurs hooks : le nôtre (`item_purge` →
    `Cleanup::forAgent()` et `Cleanup::forPrinter()`) emporte réglages, alertes, raccordements (adresses et journal
    par `Raccordement::cleanDBonPurge()`), relevés, seuils et lignes de coût. « Tout supprimer » défait aussi ce que
    le raccordement avait **créé** dans GLPI Inventory — tâches, jobs, plage IP, identifiants SNMP et leur liaison —
    d'après la liste qu'il en garde (`created_items`, `Collectsetup::purgeCreatedItems()`). Ce qui avait seulement
    été *réutilisé* reste : une plage ou des identifiants partagés servent peut-être à un autre client. Sans GLPI
    Inventory, il n'y a rien de tel côté serveur : tout était sur le poste, et le retrait l'efface déjà
    (`toolbox.yaml`, `toolbox-plugin.local`, la configuration et le journal de l'agent). Les **expéditions et les demandes
    d'envoi** restent : elles racontent ce qui a été livré, et une comptabilité ne s'efface pas avec le matériel.
    Restent aussi les objets GLPI Inventory créés par un raccordement, qu'un administrateur peut partager.
  - **Annoncé avant, pas après** : « tout supprimer » coché, le fichier demande d'abord à GLPI ce que ce choix
    emporterait (`agentreport.php?q=1&pc=…`) et le montre — « Ce choix va supprimer définitivement de GLPI : 1
    sonde(s), 10 imprimante(s), 1 ordinateur(s), 6 objet(s) de collecte » — puis attend un oui. « Non » arrête
    tout, avant que rien n'ait été touché sur le poste. Sur un parc de dix, lire les nombres après coup ne servait
    à rien : les compteurs de pages étaient déjà perdus. La question est posée par la fenêtre du système (boîte
    Windows avec « Non » par défaut, question zenity, boîte Cocoa) et, quand aucune ne s'ouvre, en console ; une
    fenêtre qui manque ne vaut jamais un « non » — le choix du technicien est alors conservé, comme partout
    ailleurs. Côté serveur, `q=1` **ne supprime rien** : la clé est **relue** et non consommée (`Agenttoken::peek()`,
    car elle doit encore servir au compte rendu), la réponse est `COMPTE OK <sondes> <imprimantes> <ordinateurs>
    <collecte>`, `COMPTE ABSENT` ou `COMPTE REFUSE` (clé sans ce droit), et les nombres viennent de la **même**
    sélection que la suppression (`Agentreport::probeScope()`, partagée par `countProbe()` et `purgeProbe()`) : ce
    qui est promis est ce qui part. Les mêmes mots habillent les nombres avant et après (`Liste` en PowerShell,
    `pg_liste` en sh).
  - **Les imprimantes d'une sonde** : celles que GLPI dit être entrées par elle (`glpi_rulematchedlogs`, dernière
    sonde connue de chaque imprimante — la « sonde responsable » du contrôle de la remontée), dans l'entité du
    fichier. Une imprimante relevée depuis par une autre sonde n'est pas emportée.
  - **Qui décide** : « Retirer une sonde » est un **niveau du droit Déploiement** (`PURGE` sur
    `plugin_printgestion_deploiement`), distinct de celui d'installer. Il commande l'affichage des boutons de
    retrait et le téléchargement des fichiers `*-retrait` ; le pouvoir de supprimer voyage ensuite dans la clé du
    fichier (24 h, un seul usage, une seule entité). Qui peut télécharger ce fichier a le droit de s'en servir :
    la décision se prend là, une fois. Une clé d'installation, elle, ne supprime rien — `gl=1` ou `gl=2` avec elle
    est refusé. L'issue revient au PC et s'affiche telle quelle : `PURGE OK`, `PURGE TOTAL <sondes> <imprimantes>
    <ordinateurs>`, `PURGE ABSENT` ou `PURGE REFUSE`. L'historique de l'entité garde le nom de l'auteur du fichier
    et le compte exact.
  - **À la mise à jour**, ce niveau n'existait chez personne : il est accordé **une seule fois** aux profils qui
    avaient déjà le droit natif de supprimer une sonde (`Profile::grantRemovalRightOnce()`, par
    `ProfileRight::updateProfileRights()`), sinon plus aucun fichier de retrait ne se téléchargerait. Ensuite il
    s'accorde profil par profil, et un administrateur qui le retire ne le voit pas revenir.
  - **Une seule exécution à la fois** sur le poste, installation et retrait confondus : un double clic lançait deux
    fenêtres qui travaillaient en même temps. Windows : un verrou système nommé (`Global\PrintGestion-GLPI-Agent`),
    que Windows libère à la fin du processus, même tué — jamais de verrou fantôme. Linux et macOS : un dossier
    verrou `/var/run/printgestion-glpi-agent.lock` qui porte le numéro du processus (`mkdir` est atomique ; `flock`
    n'existe pas sur macOS), repris quand ce processus n'existe plus, et libéré par le `trap EXIT` de l'interface.
    Le second lancement le dit — boîte de message, zenity ou console — et ne touche à rien.
- **Archives complètes : le recours** (`os=windows-zip|linux-targz|macos-zip`, `Agentdeploy::ARCHIVE_OS`), atteignables
  depuis le panneau replié « Comment lancer le fichier téléchargé », pas depuis l'écran : elles emportent l'installeur
  officiel, donc **aucune clé et aucun téléchargement depuis le poste**, au prix d'un dossier à extraire. À prendre
  quand l'antivirus d'un client refuse les scripts. Le ZIP Windows est généré
  à la demande dans `GLPI_TMP_DIR` et supprimé en fin de requête. À la racine, deux éléments seulement :
  `INSTALLER-GLPI-AGENT.bat`, **le seul fichier à lancer**, et `LISEZMOI.txt`. Tout le reste est une donnée, rangée
  dans `fichiers/` : le MSI officiel (stocké, empreinte recalculée avant envoi), `fenetre-installation.ps1`,
  `glpi-agent-update.cmd` (script de la tâche, voir phase 5) et `commande-cmd.txt` (la commande d'installation
  seule, à coller dans cmd si Windows refuse le `.bat`). Un dossier qui montre six éléments dont un seul se lance ne
  dit pas lequel.
  Le `.bat` (ASCII, CRLF) : contrôle administrateur (`fsutil dirty query`), MSI présent, puis **la fenêtre**
  (`powershell -STA -File`), puis l'installation (`start /wait`, codes 0, 3010 et 1641 acceptés), puis la tâche
  planifiée si la case était cochée. La fenêtre passe avant l'installation parce qu'elle montre ce qui va être
  installé et pour qui ; ce que le `.bat` vérifie avant, lui, ce sont les deux choses qui la rendraient inutile.
  Elle rend un mot sur sa sortie standard (`AVEC`, `SANS`, `ANNULE`) et ne décide de rien : sans PowerShell, ou
  fenêtre fermée sans réponse, la question revient en console (`choice`, 20 s, non par défaut). La case de mise à
  jour n'est proposée que si « Nouveaux paquets Windows : poser la mise à jour automatique » est coché ; sinon la
  fenêtre l'annonce sans le redemander. `Agentdeploy::buildWindowsDialogLines()` construit la fenêtre une
  seule fois pour l'archive et pour le fichier unique : une seule écriture, donc la même case au même état par défaut.
  Chaque téléchargement est tracé dans l'historique de l'entité. Aucun identifiant, jeton ni secret dans les archives :
  URL du serveur et TAG seulement.
- **Clé de récupération** (`inc/agenttoken.class.php`, `front/agentpull.php`) : 24 octets tirés au hasard, **jamais
  stockés en clair** — seule l'empreinte SHA-256 est gardée, comme un mot de passe. Rangement : `glpi_configs`,
  contexte `plugin:printgestion`, clé `agent_pull_tokens` (JSON encodé en base64 — la valeur passe tantôt par
  `CommonDBTM`, tantôt par une écriture directe, et un guillemet échappé par l'un et pas par l'autre rendrait la
  liste illisible en silence ; 30 entrées au plus, expirées purgées à chaque lecture) ; pas de table dédiée, une poignée de lignes qui vivent un jour ne valent pas une étape de schéma.
  L'écriture se fait **par comparaison de l'ancienne valeur** (`UPDATE … WHERE value = <valeur lue>`, trois essais) :
  deux requêtes simultanées ne peuvent pas consommer la même clé. Elle est consommée **avant** d'envoyer le MSI, et
  non après : une clé refermée seulement en fin d'envoi se rejouerait en coupant la connexion. Le prix est assumé —
  un téléchargement interrompu se refait en régénérant le fichier dans GLPI, un clic.
  `front/agentpull.php` est **la seule page du plugin joignable sans session**, déclarée par
  `plugin_printgestion_boot()` via `SessionManager::registerPluginStatelessPath()` — appelée à l'amorçage, avant
  `SessionStart`, le seul moment où c'est possible. Elle ne lit que `t`, revérifie le module `deploiement`, recalcule
  l'empreinte du MSI et refuse s'il n'est plus celui que la clé nommait. Toute demande refusée (clé inconnue,
  expirée, déjà servie) répond le même 404 sans explication ; le journal `printgestion.log` garde l'adresse et le
  motif, l'historique de l'entité la récupération (sans auteur : personne n'était connecté, c'est la clé qui a
  ouvert). Ce que la clé ouvre : ce MSI, un binaire public de Teclib', et rien d'autre — ni session, ni donnée de
  GLPI, ni écriture.
- **Commande** : `msiexec /i "<MSI>" SERVER="…" TAG="…" ADDLOCAL="feat_AGENT,feat_NETINV" HTTPD_TRUST="127.0.0.1/32[,…]"
  SNMP_RETRIES="2" RUNNOW="1" EXECMODE="1" QUICKINSTALL="1" /qn /norestart /l*v "%TEMP%\GLPI-Agent-install.log"`,
  jamais lancée par PowerShell. **`/qn`** : muette, puisque tout est déjà renseigné — l'assistant n'avait plus rien à
  demander, il ne donnait qu'une occasion de se tromper. **`/norestart`** est son compagnon obligé : sans lui,
  l'installeur peut redémarrer le PC de lui-même pendant que quelqu'un travaille ; le code 3010 dit qu'un redémarrage
  est attendu, c'est à nous de le dire. Linux : même raison, `--silent` sur l'installeur Perl. macOS : `installer -pkg`
  l'était déjà. `SERVER` : réglage, sinon `<url_base>/`, l'URL
  de l'application GLPI (Configuration → Générale), racine comprise. Jamais `…/plugins/glpiinventory/` : GLPI 11
  reçoit les agents à sa racine (`CatchInventoryAgentRequestListener`) et GLPI Inventory y greffe ses tâches
  réseau par hooks ; le chemin du plugin ne répondait que par une route de compatibilité du plugin, qui tombe en
  404 dès qu'il est désactivé ou nettoyé, sans réparation possible à distance. Vérifié avec GLPI Agent 1.19
  contre GLPI 11.0.8 (`tests/securite/interface.py` relit `commande-cmd.txt` du paquet). `HTTPD_TRUST` garde
  toujours `127.0.0.1/32` (interface locale de l'agent).
- **Installeurs servis** (page « Installeur GLPI Agent », `front/agentdeploy.php` ; lecture `deploiement`, actions
  `config` UPDATE) : version servie (`DEFAULT_VERSION` = 1.19, épinglable) ; quatre fichiers officiels
  (`Agentdeploy::getAssets()`) : MSI Windows, installeur Perl Linux, paquets macOS Apple Silicon et Intel. Chacun
  est récupéré par le serveur sur GitHub (API des releases, proxy GLPI) et gardé seulement si son début de fichier
  est le bon (en-tête MSI, `#!`, `xar!`) et si son SHA-256 est l'empreinte `digest` publiée ; sans accès Internet,
  fichier déposé dans `GLPI_PLUGIN_DOC_DIR/printgestion/agent/` puis vérifié contre l'empreinte saisie (fichier
  `glpi-agent-<version>.sha256` de la release). Une description par fichier (`installer.json` pour le MSI,
  `installer-linux.json`, `installer-macos-arm64.json`, `installer-macos-x86_64.json`), une seule version en cache,
  dossier supprimé à la désinstallation.
- **Réglages** (`glpi_plugin_printgestion_configs` ; aucune « URL du serveur » : l'adresse est déduite de l'URL de l'application) : `agent_version`,
  `agent_httpd_trust` ; vides : automatiques.
- **Limites vérifiées** : « Demander le statut » et « Demander un inventaire » (natifs) sont des requêtes du
  serveur vers la sonde sur le port 62354, aux adresses du réseau local du poste (`Agent::guessAddresses()`) :
  impossibles depuis un GLPI sur Internet vers une sonde derrière le NAT d'un client, sauf VPN. Sans GLPI
  Inventory, l'agent ne reçoit aucune tâche réseau, et l'URL du serveur à lui donner change à l'installation
  du plugin.

### Listes du module : gabarit natif (`inc/ui.class.php`)

Les lignes des listes que le plugin affiche sont de vrais objets GLPI — `Agent` pour les sondes, `Printer` pour le
contrôle de la remontée, `PluginPrintgestionRaccordement` pour les raccordements. Elles sont rendues par le gabarit
du cœur `components/datatable.html.twig`, par le même appel que `src/Certificate_Item.php` :
`Ui::datatable($columns, $entries, $formatters, $itemtype, $can_edit)`. Tableau, cases à cocher et barre d'actions
massives sont donc ceux de GLPI : il déduit l'itemtype du nom des cases et n'offre que les actions permises par les
droits de l'utilisateur **sur cet objet** (`Agent::$rightname`, `Printer::$rightname`) — aucune règle de sécurité
n'est recopiée. Sans ligne ou sans droit en modification, pas de cases. Les colonnes réservées à l'administrateur
ne sont pas ajoutées pour un technicien : absentes de sa page, pas cachées. Le HTML passé en `raw_html` est échappé
par l'appelant ; le reste est échappé par Twig.

Le plugin n'ajoute au gabarit que deux gestes, par la classe `pg-datatable` du conteneur :
- **la barre naît cachée** (`printgestion.css` : `.pg-datatable:not(.pg-coche) > .mb-2:first-child`) et
  `printgestion.js` pose `pg-coche` au premier élément coché. Ce n'est pas qu'une question d'allure : **GLPI déduit
  l'itemtype des cases cochées**, donc ouvrir le menu sans rien avoir sélectionné ne donnerait qu'une liste vide. Le
  compte se fait après un `setTimeout(0)` — `change` part **avant** que le `onclick` de « tout cocher » ait fini ;
- **toute la ligne ouvre sa page** : un clic hors lien, bouton ou case suit le premier lien de la ligne (Ctrl ou
  Maj : nouvel onglet). La cellule de la case à cocher est exclue.

`Ui::datatable()` **oublie la sélection mémorisée** pour l'itemtype affiché (`$_SESSION['glpimassiveactionselected']`),
qui survivait d'une page à l'autre. `Raccordement::cleanDBonPurge()` efface adresses et journal d'un raccordement
supprimé en lot. `Raccordement::rawSearchOptions()` donne au raccordement ses options de recherche natives
(numéro, entité, sonde, statut, auteur, dates) ; rien n'y est modifiable en masse, le statut et les dates suivant le
déroulé de l'assistant.

Restent dessinés à la main, volontairement : les tableaux d'analyse de l'administrateur (valeurs de consommables et
compteurs par modèle, repliés) — des lignes groupées par modèle sur plusieurs rangs et une couleur par cellule,
que le gabarit ne sait pas faire, et aucun objet GLPI à cocher dessus.

### Ce qui passe par GLPI, et ce qui reste au plugin

Règle : ce que GLPI sait faire passe par sa classe ou son API, pour qu'une évolution de son schéma ne casse pas le
plugin et que l'historique, les hooks des autres plugins et les contrôles jouent.
- **Cartouches natives** : `Cartridge::add()` puis `update()` pour l'ouverture, `update()` pour la clôture
  (`Cartridgehistory::openNativeCartridge()`, `closeNativeCartridge()`), avec la trace d'`install()`/`uninstall()`
  sur l'imprimante. Pas `install()` : elle prend une cartouche du stock réel, et une détection SNMP n'a pas à le
  consommer. Pas `uninstall()` : elle prend le dernier compteur enregistré, le plugin a plus récent. La cartouche
  prend l'entité de sa référence, la règle de GLPI.
- **Tâches automatiques** : `CronTask::update()` pour le mode et l'état, `CronTask::resetState()` pour une tâche
  bloquée ou désactivée — ce que fait le bouton natif « Réinitialiser l'état ».
- **Désinstallation** : `Config::deleteConfigurationValues()` pour les réglages, `$DB->dropTable($table, true)` pour
  les tables ; `$DB->truncate()` pour vider la table matérialisée des alertes ; `$DB->update()` avec jointure pour
  recaler les entités (`Entityscope::reconcile()`).
- Déjà natifs : `Lockedfield`, `Toolbox::getGuzzleClient()`, `Toolbox::logInFile()`, les listes déroulantes Lieu et
  Contrat, `Contract_Item`, `NotificationTarget`, `CronTask`, les droits, `Log::history()`, l'API des actions massives.

Ce qui reste au plugin, et pourquoi :
- `Schema::install()` : `CREATE TABLE` par `doQuery()` — c'est le geste de tous les plugins, GLPI n'a pas d'API de
  création de table ;
- `Tonerreading` : un `INSERT … ON DUPLICATE KEY UPDATE` groupé par lots. `$DB->updateOrInsert()` ferait une requête
  par relevé, soit des milliers par passage de la tâche ;
- `Collectfrequency` écrit la date de début (`datetime_start`) des tâches de GLPI Inventory directement : sa classe
  refuse de modifier une tâche active et annule ses jobs à la désactivation. La présence des colonnes est vérifiée
  avant chaque écriture (`assertInventoryTaskColumns()`) ;
- `Agenttoken` : la consommation atomique du jeton (compare-and-set sur une ligne) — aucune classe de GLPI ne
  fournit ce verrou.

### Déploiement Agent — phase 2 : assistant de raccordement (`inc/raccordement.class.php`, `inc/collectsetup.class.php`, `front/raccordement.php`)

Raccorder les imprimantes d'un client à sa sonde, sur place, et vérifier le résultat avant de partir. Accès : bloc 3
de l'onglet « Déploiement Agent » de l'entité (« Nouveau raccordement ») et page « Raccordements » du module.
Lecture : droit `deploiement` READ ; toute action (POST) : `deploiement` UPDATE ; l'entité du raccordement est
revérifiée à chaque requête (hors périmètre : 404). Lieu, commentaire et contrat des imprimantes : phase 3.

- **Une étape ouverte à la fois** (`showWizard()`). La barre 1 → 5 est la navigation : les étapes que l'état des
  données permet d'ouvrir (`getReachableStep()` : 2 sans adresse, 4 dès qu'il y a des adresses, 5 dès que la
  découverte est lancée) sont des liens, les autres des libellés éteints. L'étape voyage dans l'URL (`&step=N`,
  bornée à la relecture) et dans chaque formulaire (champ caché `step`, `stepField()`) : après un enregistrement on
  revient là où l'on travaillait, et non sur l'étape que l'état des données suggère — sans quoi enregistrer à
  l'étape 5 renverrait à l'étape 4. Sans `step` demandé, `getCurrentStep()` ouvre celle où il reste à faire.
  Les cinq étapes (`getStepLabels()`, seul endroit où elles sont nommées) : **1** sonde, **2** adresses des
  imprimantes, **3** lieu / commentaire / contrat, **4** configuration de la collecte **et** découverte (aucune
  décision entre les deux), **5** application aux imprimantes. Les titres de cartes ne répètent plus le numéro.
  Le journal est replié (`Ui::foldedNote()`, ouvrable par tous les profils, pas seulement l'administrateur).
- **Statuts** : `open` → `configured` → `triggered` → `closed`, ou `abandoned` depuis tout statut non clos. Chaque
  action revérifie le statut : on ne passe jamais à l'étape suivante si la précédente a échoué. Une sonde n'a
  qu'un raccordement en cours : « Raccorder avec cette sonde » reprend celui qui existe.
- **Prérequis de GLPI Inventory, en tête de l'assistant** (`Collectsetup::getPrerequisites()`, calculés une fois par
  requête) : tous vérifiés avant la première étape, pour un nouveau raccordement comme pour un raccordement en
  cours. Bloquants : plugin installé (`Plugin::getFromDBbyDir`), activé (un message par état : désactivé, à
  configurer, mise à jour non lancée, fichiers absents, remplacé) et chargé ; version dans
  au moins `GLPIINVENTORY_MIN_VERSION` = 1.6.0 (`checkVersion()`) ;
  classes présentes ; tâche automatique `taskscheduler` présente et non désactivée (sans elle, seule la découverte
  forcée par l'assistant partirait). Avertissements seulement : version plus récente que
  `GLPIINVENTORY_TESTED_VERSION` = 1.6.10 (nouvelle série comprise) ; `taskscheduler` sans exécution depuis plus de
  max(2 h, 10 × sa fréquence). Chaque message dit où agir dans GLPI (Configuration → Plugins, Configuration →
  Actions automatiques). Un prérequis manque : l'assistant n'affiche que les prérequis, puis, pour un raccordement
  en cours, « Abandonner » et le journal ; toute action POST autre que l'abandon est refusée (message, et ligne au
  journal du raccordement) ; bloc 3 de l'onglet de l'entité : « Nouveau raccordement » désactivé, raisons
  affichées. Les contrôles « Plugin GLPI Inventory » du bloc 1 et de la page « Installeur GLPI Agent » reprennent
  les mêmes messages.
- **Étape 1, sonde présente** : prérequis bloquants (ceux de GLPI Inventory ci-dessus, inventaire GLPI activé, TAG
  de l'entité valide et unique, règle d'affectation par TAG active) ; sonde de l'entité (`glpi_agents.entities_id`) : dernier
  contact de moins de deux fois la fréquence d'inventaire (sinon bloquant ; « récent » sous une heure), modules
  `use_module_network_discovery` et `use_module_network_inventory` déclarés par l'agent (sinon : réinstaller avec
  `feat_NETINV`), TAG de l'agent égal à celui de l'entité. « Demander le statut » : `Agent::requestStatus()` natif — **son échec n'est pas un défaut**. Ce sens du réseau
  (GLPI vers le port 62354 du PC) ne marche pas derrière la box d'un client, et n'a pas à marcher : l'agent
  travaille en **tirage**, c'est lui qui appelle GLPI, et GLPI lui rend alors la liste des tâches à exécuter
  (`Glpi\Inventory\Request::handleNetDiscoveryTask()`, où GLPI Inventory déclare `netdiscovery` via le hook
  `handle_netdiscovery_task`). Une découverte préparée part donc seule, sans VPN et sans que personne touche au PC.
  D'où la règle de rédaction, tenue par `Collectsetup` : pas de réponse à une demande du serveur → **information**,
  avec la preuve de vie (`getProofOfLife()` : dernier contact) ou l'échéance (`getPickupSentence()` : au plus tard
  dernier contact + `inventory_frequency`, réglage natif Administration → Inventaire, 24 h par défaut, 1 à 240).
  Seul cas resté en avertissement : une sonde **qui n'a jamais contacté GLPI** — là, l'absence de réponse dit
  vraiment quelque chose. Le geste sur place (`http://127.0.0.1:62354`, « Force an Inventory ») n'est plus la
  consigne principale : il est replié, présenté comme le moyen de gagner l'attente quand on est devant la machine.
  Ces conditions sont revérifiées avant les étapes 3 et 4.
- **Étape 2, imprimantes** : adresses IPv4, plages `a-b` ou `a-N`, réseaux de /22 à /32 (sans réseau ni diffusion),
  1 024 au plus ; enregistrées en attente (`raccordementips.result = pending`), remplaçables tant que la
  configuration n'est pas créée. Rien n'est appliqué aux imprimantes.
- **Étape 3, configuration de la collecte** : plan affiché sans rien écrire, recalculé au moment de créer, puis
  écriture dans **une transaction** (au moindre échec, rien n'est gardé ; vérifié avec un déclencheur SQL qui
  refuse la création du job). Valeurs relevées en base après une configuration faite à la main dans GLPI
  Inventory, et reproduites :
  - plage : `name`, `entities_id`, `ip_start`, `ip_end` ; liaison aux identifiants SNMP : `rank` = rang maximal + 1 ;
  - modules de la sonde : identifiant de l'agent, en texte, dans `exceptions` de `NETWORKDISCOVERY` et
    `NETWORKINVENTORY` (`["3"]` ; une exception inverse l'activation globale). `PluginGlpiinventoryAgentmodule::updateForAgent()`
    n'est pas utilisée : pour un module que l'agent ne change pas, elle retire la première exception d'un autre
    agent (`unset` sur un `array_search` à `false`) ;
  - une tâche par méthode, GLPI Inventory ne gérant plus plusieurs jobs dans une tâche : tâche créée inactive
    (`reprepare_if_successful` = 1, dates vides, plages horaires 0 = toujours), job `targets`
    `[{"PluginGlpiinventoryIPRange":"<id>"}]`, `actors` `[{"Agent":"<id>"}]`, `restrict_to_task_entity` = 1, puis
    tâche activée (une tâche active ne se modifie plus).

  Existant vérifié **dans l'entité et pour la sonde** : deux clients, ou deux sites d'un client, ont souvent le
  même réseau privé. Plage de l'entité collectée par cette sonde, ou par aucune tâche, qui contient des adresses :
  réutilisée ; plage collectée par une autre sonde : laissée de côté (note), la sonde aura la sienne ; adresses
  restantes : une plage du minimum au maximum, refusée si elle chevauche une plage réutilisable ; tâche de cette
  sonde sur une plage réutilisée : réutilisée si active, refus si désactivée ; nouveaux identifiants identiques à
  des existants (version et communauté, sensible à la casse) : réutilisés. SNMP v3 : identifiants créés dans GLPI.
- **Étape 4, déclenchement et vérification** : « Lancer la découverte » = `PluginGlpiinventoryTask::forceRunning()`
  sur les tâches de découverte (job préparé pour la sonde), puis réveil natif `Agent::requestInventory()` (GET /now
  sur le port de l'agent). Sonde injoignable (NAT) : geste sur place, `http://127.0.0.1:62354` puis « Force an
  Inventory » (autorisé par `HTTPD_TRUST=127.0.0.1/32` du paquet), deux fois : découverte, puis relevé des niveaux.
  Vérification (bouton, et automatique toutes les 60 s tant qu'un résultat est en attente, pendant
  `VERIFY_LIMIT` = 30 min après le déclenchement au plus ; au-delà, plus de relance : ligne rouge « Vérification
  arrêtée après 30 min : N adresses toujours sans réponse » et quoi faire — sonde allumée et sur le bon réseau,
  adresses, communauté, « Relancer la découverte » qui rouvre 30 min) à partir des journaux
  GLPI Inventory de la sonde datés d'après le déclenchement : découverte terminée pour toutes les plages → relevé
  des niveaux préparé une seule fois (`forceRunning()` des tâches d'inventaire, qui ne retiennent que les
  équipements importés avec des identifiants) et nouveau réveil. Résultat par adresse, **parmi les seuls
  équipements cités par ces journaux** (`[[Printer::8]]`), jamais l'équipement d'un autre client à la même adresse :

  | Résultat | Condition |
  |---|---|
  | Trouvée | Imprimante dans l'entité, inventaire réseau terminé depuis le déclenchement, niveaux dans `glpi_printers_cartridgeinfos` |
  | Sans niveaux | Idem, sans aucun niveau |
  | Mauvaise entité | Imprimante d'une autre entité : irréversible par l'assistant (les règles ne jouent qu'au premier import), transfert manuel, alerte appuyée |
  | Pas de réponse SNMP | Découverte terminée, rien à l'adresse, ou un actif non géré (répond sans SNMP) |
  | Pas une imprimante | Équipement SNMP d'un autre type |
  | En attente | Découverte, ou relevé des niveaux, pas encore rendu par la sonde |

  La date d'inventaire de l'imprimante n'est pas utilisée : la découverte la fait avancer.
- **Journal** (`raccordementlogs`) : chaque action, avec l'étape, le niveau, l'auteur et l'heure : sonde choisie,
  adresses, objets créés ou réutilisés, refus, réveils, préparation du relevé, vérifications dont le résultat
  change, fin ou abandon. Un abandon ne supprime rien : les objets créés dans GLPI Inventory restent (listés).
- **Constats sur échanges simulés** (formats XML de l'agent, à confirmer au pilote) : la découverte applique la
  règle TAG de la sonde aux imprimantes ; le paramètre `ENTITY` de la plage est ignoré par le cœur ; la découverte
  crée le lieu SNMP (sysLocation) dans l'entité et le rattache à l'imprimante, même avec « Lieu » désactivé dans
  la configuration de l'inventaire (verrouillé en phase 3).

#### Les modules de la sonde, réglés dès l'installation

Constat sur un agent réel (onglet « Modules des agents » de GLPI Inventory) : « Découverte réseau » et « Inventaire
réseau (SNMP) » décochés. Tant qu'ils le sont, GLPI Inventory n'envoie **aucune** tâche réseau à la sonde — réveil
local ou non. Ils n'étaient activés qu'à la configuration d'un raccordement (`Collectsetup::apply()`).

Le compte rendu d'installation pose désormais le profil d'une sonde d'imprimantes
(`Collectsetup::PRINTER_PROBE_MODULES`, `applyPrinterProbeProfile()`), **indépendamment des adresses** : cochés
`INVENTORY`, `NETWORKDISCOVERY`, `NETWORKINVENTORY` ; décochés `InventoryComputerESX`, `DEPLOY`, `Collect`,
`WAKEONLAN`. L'inventaire de l'ordinateur reste coché : c'est lui qui fait exister la sonde dans GLPI (fiche
ordinateur, rattachement à l'entité par le TAG), et c'est par ce nom que le compte rendu la retrouve
(`Raccordement::findAgentByComputer()`). Le réglage passe par la mécanique des écrans de GLPI Inventory
(`setModuleForAgent()` : l'agent figure dans les exceptions d'un module exactement quand l'état voulu diffère de
l'activation globale) ; `apply()` s'en sert aussi, mais ne règle que les deux modules réseau — l'assistant raccorde
aussi des postes existants, qui peuvent faire autre chose que sonder des imprimantes.

#### La chaîne complète, depuis le PC, sans personne devant GLPI

`createFromInstaller()` s'arrêtait après `Collectsetup::apply()` : le raccordement restait `configured`, la
découverte n'était **jamais** lancée, et il fallait rouvrir l'assistant pour un clic. Deux gestes la complètent :

1. `createFromInstaller()` enchaîne sur `Collectsetup::trigger()` (relecture du raccordement d'abord : `apply()`
   vient d'y écrire les tâches créées). La tâche passe en « préparée » pour cette sonde, et le raccordement en
   `triggered` ; le retour porte `triggered => bool`.
2. `front/agentreport.php` répond **`RUN`** au PC, qui appelle alors `http://127.0.0.1:62354/now`
   (`Agentdeploy::getAgentWakeUrl()`) — **l'interface locale de l'agent, sur le poste où le script tourne**. C'est
   le même appel que le bouton « Force an Inventory », fait par le script. L'agent rappelle GLPI dans la seconde,
   reçoit la consigne et scanne. `WAKE_TRIES` × `WAKE_WAIT` secondes de patience : le service vient d'être
   installé, son interface met quelques secondes à répondre.

Le réveil local n'est jamais bloquant : s'il échoue, la consigne reste armée côté serveur et partira au prochain
appel de l'agent — c'est ce que le script affiche, au lieu de laisser croire que rien ne se passera. Le message
final de la fenêtre Windows suit (`$decouverte`) : « rien d'autre à faire sur ce PC » seulement si le réveil a
abouti. `RUN` et `SCAN …` s'excluent : le premier vaut avec GLPI Inventory, le second sans.

### Déploiement Agent — phase 3 : lieu, commentaire et contrat (`inc/raccordementdetail.class.php`)

- **Étape 3** (lecture : droit `deploiement` READ ; saisie : UPDATE, tout statut sauf abandonné) : pour chaque
  adresse, lieu, commentaire et contrat **en attente** (`raccordementips.locations_id`, `comment`, `contracts_id`,
  phase 3). Valeurs par défaut qui complètent les adresses sans valeur ; jusqu'à 64 adresses ligne à ligne,
  au-delà seulement celles qui ont une imprimante ou des valeurs. Remplacer la liste d'adresses garde les valeurs
  des adresses restantes.
  - **Lieu et contrat : les sélecteurs natifs de GLPI** (`Location::dropdown()`, `Contract::dropdown()`), et non
    plus un champ texte et un `<select>` maison. Le sélecteur des lieux porte son bouton « + » (formulaire de
    création habituel, droits de l'utilisateur) — sur la ligne des valeurs par défaut seulement, sinon il y aurait
    une fenêtre de création par adresse. Le champ texte acceptait un chemin « Siège > Bâtiment B » et créait les
    niveaux manquants : pratique, mais une faute de frappe créait un lieu jumeau, et ce n'était pas l'outil de GLPI.
  - **Même règle d'entité des deux côtés de l'écran** : le formulaire propose et `validate()` accepte ce que
    `getEntitiesRestrictCriteria($table, '', $entities_id, true)` rend — l'entité, plus les entités parentes pour
    les objets cochés « visible dans les sous-entités ». C'est exactement la règle qu'applique `Dropdown` côté
    AJAX. Les lieux étaient auparavant filtrés sur le seul `entities_id` : le sélecteur en proposait que la
    validation aurait refusés.
  - **Liste de contrats vide : on dit pourquoi** (`getContractHint()`). Aucun contrat visible depuis l'entité du
    client est presque toujours un rattachement à corriger, pas une panne : selon qu'il en existe ou non dans les
    entités de l'utilisateur, le message renvoie vers la création d'un contrat ou vers le déplacement /
    la case « visible dans les sous-entités » de ceux qui existent. Le compte se fait dans le périmètre de
    l'utilisateur, jamais au-delà.
  - Enregistrement tout ou rien (transaction). Une adresse déjà appliquée qu'on modifie repasse en attente.
- **Étape 5** (`apply_details`, UPDATE, statuts `triggered` et `closed`) : seulement aux adresses où la vérification
  a trouvé une imprimante dans l'entité (trouvée, sans niveaux, niveaux en attente) ; jamais à une adresse sans
  imprimante ni à une imprimante d'une autre entité (raison affichée). Une transaction par imprimante :
  1. `Printer::update()` du lieu et du commentaire : historique GLPI avec l'auteur, et le cœur verrouille lui-même
     les champs modifiés d'un actif dynamique (`CommonDBTM::manageLocks()`) ;
  2. verrou natif (`glpi_lockedfields` : `Printer`, imprimante, `locations_id` / `comment`) ajouté s'il manque, par
     exemple quand la valeur était déjà la bonne ;
  3. rattachement au contrat (`Contract_Item`, sans doublon). `Contract_Item::add()` ne contrôle ni l'entité ni le
     nombre maximal d'éléments (seul `can()` le fait) : l'entité est garantie par la liste des contrats,
     `max_links_allowed` est vérifié ;
  4. adresse marquée appliquée (`applied_items_id`, `date_applied`).

  Un échec annule tout pour cette imprimante (message sans détail technique, détail dans le journal PHP).
- **Vérifié sur le GLPI de test** (échanges simulés) : sans verrou, l'inventaire réseau d'une imprimante remplace son
  lieu par le lieu SNMP (sysLocation), et aussi son contact ; il ne touche pas au commentaire (aucune correspondance
  dans le cœur, hors règle métier) ; la découverte d'une imprimante déjà connue ne change ni l'un ni l'autre. Avec
  le verrou, l'inventaire garde le lieu déclaré. Au premier import, rien n'empêche la découverte de créer et
  d'attacher le lieu SNMP : il est remplacé à l'étape 5.
- **Réglage « Lieu » de la configuration d'inventaire** : lu nulle part par le cœur de GLPI 11.0.8, il n'empêche rien.

### Déploiement Agent — phase 4 : sonde responsable sur la fiche imprimante (`inc/printeragent.class.php`)

La carte native « Informations d'inventaire » d'une imprimante inventoriée en SNMP n'indique aucun agent : le cœur ne
garde le lien agent ↔ actif que pour les ordinateurs (`MainAsset::rulepassed`). Le bloc du plugin (module actif, droit
`deploiement` READ, imprimante visible) n'affiche que ce qui manque :
- la ou les sondes : acteurs des jobs `networkinventory` puis `networkdiscovery` (agents, ou agent du poste désigné)
  dont une cible est une plage IP contenant une adresse IPv4 de l'imprimante, dans son entité ou une entité parente,
  ou l'imprimante elle-même ; tâche et plage indiquées. À défaut, la sonde de son dernier inventaire réseau ;
- pour chaque sonde : nom (lien vers la fiche native si l'utilisateur a le droit Agent), version, dernier contact
  (« Muette » au-delà de `silent_days`) ;
- la date du dernier inventaire réseau réussi de l'imprimante, lue comme le contrôle de la remontée (journal d'import,
  journaux des tâches GLPI Inventory), avec la mention d'une découverte plus récente, qui fait avancer la date de la
  carte native sans relire les niveaux.

Emplacement : hook `AUTOINVENTORY_INFORMATION` (Printer), dans la carte native, quand l'utilisateur la voit (droit
Inventaire en lecture, imprimante dynamique) ; sinon hook `POST_ITEM_FORM`, sous le formulaire : le profil Technicien
n'a pas le droit Inventaire par défaut. Aucun champ de saisie, le bloc étant rendu dans le formulaire de l'imprimante.

### Déploiement Agent — phase 5 : conformité, mise à jour automatique, sondes muettes (`inc/agentsetting.class.php`, `inc/agentalert.class.php`, `inc/notificationtargetagentalert.class.php`)

**Dernière version connue** : vérifiée chaque semaine sur GitHub (`releases/latest`, ni brouillon ni préversion ;
tâche `PrintgestionCheckAgentVersion`, bouton « Vérifier sur GitHub maintenant » de la page « Installeur GLPI Agent »)
ou saisie à la main, qui prime sur GitHub tant qu'elle n'est pas effacée ; à défaut, version de l'installeur servi.
Champs `agent_latest_version`, `agent_latest_source` (`github` | `manual`), `agent_latest_checked`.

**Conformité** (`Agentsetting::getCompliance()`) : version installée (texte simple ou versions par module de
`glpi_agents.version`) comparée à la version cible de la sonde si elle est épinglée, sinon à la dernière version
connue : « À jour », « À mettre à jour (X) », « Épinglée sur X », « Version cible X non atteinte ». Même règle dans
« Contrôle de la remontée » et l'onglet de l'entité (`Collect::getAgentVersionStatus()`), qui gardent « Trop
ancienne » avant 1.15.

**Une page, trois cartes** (« Installeur GLPI Agent ») : l'installeur (état et boutons de récupération), les
paramètres transmis à l'installation, les prérequis. La carte « Prochains paquets, version de référence et repérage
des sondes » a été dissoute le 21/09/2026 : c'était un tiroir de quatre contenus sans rien en commun. La dernière
version publiée et le bouton « Vérifier sur GitHub » ont rejoint la version des agents (même question), « Imposer la
mise à jour automatique » a rejoint les paramètres des paquets (c'en est un), la saisie de secours de la dernière
version **n'apparaît que si GitHub n'a pas répondu** (affichée en permanence, elle invitait à remplir pour rien — et
une version saisie à la main prend le pas sur GitHub, en silence), et le statut GLPI des PC sondes est parti sur la
page « Sondes », à côté du bouton qui le pose. Un seul formulaire, un seul « Enregistrer »
(`Agentdeploy::saveSettings()` enchaîne sur `Agentsetting::saveDefaults()`).

**Une seule version, choisie dans une liste** (`Agentsetting::getTargetVersion()`, `getPublishedVersions()`,
`versionField()`). Il y en avait trois : une par sonde, une « cible du parc » (`agent_update_target`) et une
« servie » (`agent_version`). Elles disaient la même chose à des moments différents, et rien ne garantissait qu'elles
la disent pareil — un parc qui vise une version que le serveur ne distribue pas ne se met jamais à jour. Il ne reste
que **« Version des agents »** (`agent_version`) : le fichier distribué aux nouvelles sondes **et** ce vers quoi les
sondes déjà installées convergent. Jamais vide : faute de réglage, la version de référence du plugin (l'ancien
libellé « Version épinglée (vide : dernière vérifiée) » était faux sur ce point). `agent_update_target` reste dans le
schéma, plus personne ne l'écrit ni ne la lit.
Conséquence sur le badge : « À jour » veut désormais dire « dans la version du parc », et une sonde plus récente que
ce que le serveur distribue est signalée à part (`ahead`) plutôt que comptée à jour — après un retour arrière, le
parc n'est pas où on le croit.
Elle se choisit dans la liste des releases publiées sur GitHub, plus dans un champ libre : une faute de frappe y
passait inaperçue, et le serveur cherchait alors un fichier qui n'existe pas.
La liste **ne vient pas des fichiers téléchargés** : le cache n'en garde qu'un seul (récupérer une version efface la
précédente), elle n'aurait eu qu'une ligne. Elle vient de l'API GitHub (30 dernières releases, ni brouillons ni
préversions, 10 gardées), mémorisée un jour dans `glpi_configs` (contexte `plugin:printgestion`) — **une tentative par
jour, succès ou échec** : sans cette borne, un serveur sans accès à GitHub attendrait dix secondes de connexion à
chaque affichage de la page. Le bouton « Vérifier sur GitHub maintenant » force la lecture.
Deux garde-fous : une valeur déjà réglée mais absente de la liste reste proposée et sélectionnée (un menu qui perd en
silence un épinglage ferait basculer tout un parc sans le dire) ; et si aucune liste n'a jamais pu être récupérée, le
**champ libre revient**, avec la raison — un menu vide empêcherait de changer de version. Les deux enregistrements
revérifient le format, un menu se forge aussi facilement qu'un champ.

**Plus aucun réglage par sonde.** Il en restait deux, tous deux supprimés le 20/09/2026 :

- « Mise à jour automatique » : une case qui ne s'appliquait qu'en lançant un fichier ailleurs, donc une case qui
  finit par mentir — elle affichait « oui » pour des PC où personne n'avait rien posé. Devenue **trois actions**
  (`Agentsetting::ACTION_POSER|ACTION_RETIRER|ACTION_MAINTENANT`), portées par l'URL du bouton (`&maj=1|0|now`).
- « Version cible » par sonde : elle faisait doublon avec la version cible du parc (page « Installeur GLPI Agent »),
  et se répétait sur chaque fiche. Une version qui casse quelque chose casse partout : on épingle pour tout le parc.
  `Agentsetting::getTargetVersion()` est désormais la seule source, pour le badge de conformité **comme** pour ce que
  la tâche installera.

La table `glpi_plugin_printgestion_agentsettings` n'est donc plus ni lue ni écrite (colonnes `auto_update` et
`target_version` laissées dans le schéma) ; `getSettingsFor()` garde sa forme pour ses lecteurs (conformité, alertes,
écrans) et rend la version cible du parc. La page « Sondes » n'a plus qu'une action en POST : marquer le PC sonde. Onglet « Sonde Print Gestion » de la fiche Agent native (droit Déploiement en lecture, agent
visible) : conformité, réglages, imprimantes collectées, rien de ce que la fiche native affiche déjà. Même contenu
dans la page « Sondes » du module (`front/sondes.php`), pour les profils sans droit Agent. Enregistrement : droit
Déploiement en modification, sonde dans les entités de l'utilisateur.

**Qui décide de la mise à jour automatique** (règle du OU, décidée le 20/09/2026) : le réglage
« Mise à jour automatique des sondes » (page « Installeur GLPI Agent ») se règle par **deux boutons radio**, et
non par une case : une case seule laisse deviner l'autre branche, et son aide renvoyait à « la case » d'une fenêtre
que le lecteur n'a pas sous les yeux — elle s'ouvrira plus tard, sur le PC du client. « **Toujours posée** » : le
fichier d'installation pose la tâche sans rien demander, et la fenêtre l'annonce. « **Laissée au technicien** » : la
case apparaît dans la fenêtre d'installation, décochée, et il peut l'ajouter sur place. La tâche est donc
posée dès que l'un des deux la veut, jamais contre l'avis des deux. Deux avis pour une même décision finissent
toujours par se contredire, et personne ne saurait lequel a gagné ; ici il n'y a qu'un seul point de décision à la
fois. Le bloc qui pose la tâche est écrit dans le fichier **dans les deux cas** : c'est la réponse qui décide, pas la
présence du bloc.

**Ce que le PC déclare avoir fait** (`inc/agentreport.class.php`, `front/agentreport.php`) : en dernier geste,
l'installation dit à GLPI si la tâche a été posée — seconde clé à usage unique, nom du PC, un oui/non. Sans cela,
l'écran d'une sonde affichait un **souhait** comme s'il était l'état du PC (case cochée par défaut, PC sans tâche).
Le compte rendu est rapproché par le **nom du PC** : au moment de l'installation l'agent n'existe pas encore dans
GLPI, il n'y a aucun identifiant à transmettre. La fiche de la sonde affiche la phrase, et **signale l'écart** avec le
réglage souhaité (orange, avec ce qu'il faut faire). Ce compte rendu dit ce qui a été fait ce jour-là, jamais ce que
quelqu'un a changé depuis : le texte le formule ainsi. La ligne de rangement est créée à la fabrication du fichier,
pendant qu'une session existe — le PC, sans session, n'emprunte que l'écriture directe. Un échec du compte rendu
n'interrompt rien et ne s'affiche pas : la fiche dira « GLPI ne sait pas », ce qui sera vrai.

**Mise à jour réelle, posée sur le PC** : le plugin ne pousse rien (jamais la tâche Deploy de GLPI Inventory, ni
jeton, ni API). Le fichier d'installation copie `glpi-agent-update.cmd` dans
`%ProgramData%\PrintGestion` et pose la tâche planifiée « GLPI Agent - mise a jour (Print Gestion) » : le 1er du mois
à 3 h, compte SYSTEM. GLPI ne sait pas si cette étape a été faite : une sonde qui ne se met pas à jour apparaît
« À mettre à jour » dans la page « Sondes » dès qu'une version plus récente est visée. Le script
ne fait rien si l'agent n'est pas en attente (`http://127.0.0.1:62354/status`), cherche `winget.exe` dans
`%ProgramFiles%\WindowsApps\Microsoft.DesktopAppInstaller_*` (absent du PATH de SYSTEM) et lance
`winget upgrade --id GLPI-Project.GLPI-Agent` ou, version épinglée, `winget install --version X --force`, toujours avec
`--custom "ADDLOCAL=feat_AGENT,feat_NETINV"` pour garder l'inventaire réseau. **La version installée est comparée
à la version visée avant d'agir** (`winget list` + `findstr`, espaces autour du numéro pour que « 1.19 » ne
reconnaisse pas « 1.19.1 ») : sans ce contrôle, `--force` réinstallait l'agent tous les mois — service arrêté et
relancé au passage — alors qu'il était déjà à jour. Le script Linux comparait déjà. Journal
`%ProgramData%\PrintGestion\glpi-agent-update.log`. Après l'installation, la tâche ne se pose et ne se retire qu'en
lançant sur le PC un **fichier de consigne** (`front/sonde.consigne.php?...&maj=1|0`, droit Déploiement en lecture),
fabriqué par les deux boutons de la fiche de la sonde. `maj` est obligatoire : sans intention explicite, la page
refuse — poser une tâche planifiée par défaut serait agir sans qu'on l'ait demandé.
Trois boutons, trois fichiers : **Poser** (`maj=1`) et **Retirer** (`maj=0`) n'agissent que sur la tâche, qui fera
la mise à jour le 1er du mois ; **Mettre à jour maintenant** (`maj=now`) fait l'inverse — il lance le script de mise
à jour une seule fois, tout de suite, et **ne touche pas** à la tâche. Mettre à jour maintenant et automatiser sont
deux décisions distinctes. Ce troisième fichier affiche le journal à la fin : le script ne fait rien quand l'agent
travaille, et il faut pouvoir lire pourquoi.
Tout cela reste un fichier à lancer **sur le PC** : sans la tâche Deploy de GLPI Inventory ni jeton, tous deux exclus,
GLPI n'a aucun moyen d'agir sur un poste au contact de l'agent. Limites : winget sous SYSTEM n'est pas
pris en charge officiellement par Microsoft (à vérifier au pilote) ; l'installeur Windows peut refuser une
rétrogradation (journal) : désinstaller, puis réinstaller avec le paquet de l'entité.

**PC sonde** : statut GLPI choisi sur la page « Installeur GLPI Agent » (`agent_probe_states_id`), donné à
l'ordinateur de la sonde par « Marquer ce PC comme sonde » (page « Sondes », droit Déploiement en modification) :
modification ordinaire, que GLPI verrouille contre les inventaires suivants comme une saisie à la main. La page
« Sondes » signale les PC non marqués.

**Sondes et alertes** (`Agentalert`) :
- sonde : agent qui gère l'inventaire réseau (`use_module_network_inventory`) ou qui collecte au moins une
  imprimante ; les agents des postes de travail ne sont jamais signalés ;
- imprimantes collectées (`Agentsetting::getCoverage()`) : cibles des jobs GLPI Inventory de la sonde (plage IP
  contenant une adresse de l'imprimante, qui est dans l'entité de la plage ou une sous-entité ; ou imprimante
  ciblée), plus celles dont elle a fait le dernier inventaire réseau ;
- `agent_silent` : sonde sans contact depuis `silent_days` jours. `printer_silent` : imprimante collectée par une
  sonde qui contacte GLPI, et « Muette », « Sans niveau lisible » ou « Jamais inventoriée en SNMP » (connue depuis plus
  de `silent_days` jours) au sens du contrôle de la remontée (`Collect::getState()`). Date de référence : dernier
  inventaire réseau des journaux, jamais `last_inventory_update`, qu'une découverte fait avancer. Les lignes de
  niveaux ne changent de date que si leur valeur change et ne prouvent donc pas une lecture récente ; un inventaire
  réseau reçu sans bloc consommables laisse les anciennes valeurs (limite, à mesurer au pilote) ;
- **dépendance notée (correction 3), à traiter avec le moteur d'alertes** : l'alerte « imprimante qui ne remonte plus
  de niveaux » devra reposer sur les relevés toner (`glpi_plugin_printgestion_toner_readings`) plutôt que sur les
  journaux d'inventaire réseau. Pas possible en l'état : `Tonerreading::snapshotAllPrinters()` écarte un niveau
  inchangé (gardé, `is_suspect = 1`, seulement si l'imprimante a imprimé au moins `FROZEN_PERCENT_STEPS` × rendement /
  100 pages), et `reading_date` est le jour du passage de la tâche, copie des valeurs courantes de GLPI, pas la date
  d'une lecture SNMP : garder tous les points donnerait des « relevés » quotidiens à une imprimante muette depuis des
  semaines. À faire ensemble, dans le chantier du moteur d'alertes (après les données du site pilote) : un point par
  lecture réelle (nouvel inventaire réseau depuis le point précédent : journal des tâches ou `glpi_printerlogs`),
  niveau inchangé gardé et marqué, suspect seulement quand il est figé alors que les pages avancent ; puis l'alerte
  bascule sur ces points. Rien n'est changé à ce stade ;
- tâche quotidienne `PrintgestionSilentProbes` : une alerte ouverte par épisode (index unique sur `type`,
  `agents_id`, `printers_id`, `open_lock`, colonne générée à 1 tant que `date_end` est vide), motif ou sonde mis à
  jour sans nouvelle notification, fermée au retour, jamais supprimée. Tant que toutes les sondes d'une imprimante
  sont muettes, son alerte n'est ni ouverte ni fermée : l'alerte de sonde suffit, et le retour de la sonde ne
  renotifie pas une imprimante toujours muette ;
- délai : `silent_days` (Configuration > Print Gestion), pas le délai natif « Agent cleanup », partagé avec des
  actions destructrices : l'action native par défaut supprime l'agent (donc l'acteur de ses tâches GLPI Inventory),
  et le cœur la réenregistre quand aucune action n'est choisie.

**Notifications** : hook `STALE_AGENT_CONFIG` → Configuration > Inventaire > « Agent cleanup », « Print Gestion :
notifications des sondes » (sondes sans contact ; imprimantes qui ne remontent plus), enregistrés par le cœur dans la
configuration `inventory` (`_printgestion_notify_silent_agents`, `_printgestion_notify_silent_printers`). La tâche
native `Cleanoldagents` appelle aussi l'action du plugin pour chaque agent au-delà du délai natif : elle ouvre et
notifie son alerte s'il est une sonde muette. Événements de `NotificationTargetAgentalert` : `agent_silent` (une
notification par sonde) et `printer_silent` (une par entité, avec la liste) ; gabarits éditables, notifications
créées **inactives**, destinataire par défaut l'administrateur GLPI, jamais l'administrateur de l'entité, qui peut
être le client. Une alerte non notifiée (réglage ou notifications désactivés) l'est au premier passage qui le permet.

**Avertissement « Nettoyer les agents »** (correction 5) : le même bloc affiche, en pleine largeur sous la ligne native du
délai et de l'action (déplacé par script ; sans JavaScript, dans la cellule du plugin), ce que détruit l'action native
`STALE_AGENT_ACTION_CLEAN` (« Nettoyer les agents », action par défaut) : l'agent est supprimé de GLPI avec son historique
(`Agent::$dohistory`, historique purgé avec l'objet) ; au contact suivant, le PC revient comme un nouvel agent, hors des
tâches GLPI Inventory de ses raccordements (acteur désigné par identifiant) et sans ses réglages Print Gestion ; avec un
délai court, des sondes saines éteintes pendant des congés sont effacées. Alerte rouge quand le réglage enregistré
combine cette action et un délai non nul, mise à jour à l'écran quand l'administrateur change le délai ou l'action avant
d'enregistrer. Aucun champ ajouté au formulaire natif.

**Tableau de bord** : hook `DASHBOARD_CARDS`, groupe « Print Gestion — Sondes » : sondes sans contact, sondes sans
contact par entité, sondes à mettre à jour, imprimantes qui ne remontent plus (alertes ouvertes dont la sonde
contacte GLPI). Droits vérifiés par chaque fournisseur, GLPI gardant la liste des cartes en cache ; couverture des
sondes gardée 10 minutes en cache. Page « Sondes » du module : mêmes compteurs, sondes sans contact groupées par
entité, tableau des sondes (PC hôte et marquage, version, conformité, dernier contact, réglage, imprimantes).

### Déploiement Agent — phase 6 : Linux et macOS (`inc/agentdeploy.class.php`, `inc/agentsetting.class.php`)

Même principe que Windows : fichiers officiels servis par le serveur GLPI et vérifiés, réglages déjà remplis, aucun
identifiant ni secret, aucune interface maison. Téléchargement : `front/agentdeploy.download.php?os=linux|macos`
(droit `deploiement` READ, accès à l'entité, tracé dans l'historique de l'entité).

**Linux** (`buildLinuxPackage()`, archive `GLPI-Agent-<version>-linux-<TAG>.tar.gz` produite par `PharData`, un
seul dossier) : installeur Perl officiel, `installer-glpi-agent.sh` (LF, exécutable, à lancer avec `sudo sh`),
`commande.txt`, `LISEZMOI.txt`. Le script refuse de tourner hors root, pose
`/etc/glpi-agent/conf.d/90-printgestion.cfg` (`snmp-retries = 2`, option absente de l'installeur), puis lance
`perl glpi-agent-<version>-linux-installer.pl --install --type=network --server="…" --tag="…" --httpd-trust="…"
--runnow`. Vérifié dans le code de l'installeur 1.19 : `--type=network` ajoute les tâches de découverte et
d'inventaire réseau ; avec `--server` il ne pose aucune question (son mode interactif ne sert que sans serveur, il ne
peut pas être prérempli) ; il écrit `00-install.cfg` dans `conf.d`, que nos réglages complètent. Distributions de
l'installeur : Debian, Ubuntu, Red Hat, CentOS, Fedora, openSUSE, AlmaLinux, Rocky, Oracle.

**Mise à jour Linux** : si « Nouveaux paquets Windows et Linux : poser la mise à jour automatique » est coché, le
script pose `/etc/cron.monthly/glpi-agent-printgestion` (curl nécessaire, sinon message et rien de posé). La tâche
ne fait rien si l'agent n'est pas en attente (`/status`), lit la release sur l'API GitHub (dernière ou version
cible), en extrait avec `JSON::PP` (cœur de Perl) l'adresse et l'empreinte SHA-256 de l'installeur Linux, s'arrête
si la version installée (`glpi-agent --version`) est déjà celle-là, n'accepte qu'une adresse
`https://github.com/glpi-project/glpi-agent/releases/download/`, vérifie l'empreinte (`sha256sum -c`) puis relance
l'installeur **sans option de configuration** : vérifié dans son code, il garde alors `00-install.cfg` et le reste de
`conf.d` (`--force` en plus pour une version cible, qui autorise la rétrogradation). Journal
`/var/log/glpi-agent-printgestion-update.log`. Écart à la spécification, qui prévoyait l'`--upgrade` de l'AppImage :
l'AppImage exige FUSE (absent par défaut des distributions récentes) et coexisterait avec l'installation faite par
l'installeur Perl ; la même voie officielle sert donc à installer et à mettre à jour. Consigne Linux d'une sonde
(`front/sonde.consigne.php?os=linux`) : script sh seul qui pose, change ou retire la tâche cron.

**macOS** (`buildMacosPackage()`, ZIP `GLPI-Agent-<version>-macos-<TAG>.zip`) : les deux paquets officiels (Apple
Silicon et Intel, stockés sans recompression), `local.cfg`, `LISEZMOI.txt` ; pas de fichier `.command`. Vérifié sur
le paquet 1.19 : signé « Developer ID Installer: Teclib » et notarisé (accepté par Gatekeeper, macOS 26), réservé à
son architecture (`hostArchitectures`), macOS 11 minimum ; son `agent.cfg` règle `tasks = inventory` et
`httpd-trust = 127.0.0.1` puis inclut `conf.d` ; son script d'installation démarre le service. `local.cfg` donne
donc `server`, `tag`, `tasks = inventory,netdiscovery,netinventory`, `httpd-trust`, `snmp-retries = 2` et le
journal de l'agent (`logger = file`, `logfile = /var/log/glpi-agent.log`, `logfile-maxsize = 4`). Procédure
affichée dans l'onglet et la note : installer le bon paquet, puis dans Terminal
`sudo cp ~/Downloads/<dossier>/local.cfg /Applications/GLPI-Agent/etc/conf.d/local.cfg`,
`sudo launchctl bootout system /Library/LaunchDaemons/com.teclib.glpi-agent.plist` et
`sudo launchctl bootstrap system …` (macOS 13 et plus ; `unload`/`load` avant). Mise à jour : manuelle
(réinstaller le paquet, `local.cfg` gardé), aucun mécanisme officiel.

**Onglet de la fiche Agent / page « Sondes »** : consigne proposée selon le système du PC sonde lu dans son
inventaire (`Agentsetting::getHostPlatform()` : Windows, macOS, sinon Linux) ; les deux consignes Windows et Linux
si le système est inconnu ; note de mise à jour manuelle pour macOS.

### Fréquence des relevés d'imprimantes par entité (`inc/collectfrequency.class.php`)

**Pourquoi côté serveur** (vérifié dans le code de GLPI 11.0.8, GLPI Inventory 1.6.10 et GLPI Agent 1.19) : un agent
installé en service (Windows `EXECMODE=1`, démon Linux et macOS) prend son intervalle dans la réponse CONTACT du
serveur (`expiration`, la « fréquence d'inventaire » globale de GLPI, en heures) ; le cœur n'offre aucun point
d'extension pour la changer agent par agent (le hook `PROLOG_RESPONSE` ne touche que la réponse PROLOG, que l'agent
n'utilise pour son planning qu'avec un serveur non GLPI). `TASK_FREQUENCY`, `TASK_HOURLY_MODIFIER` et
`TASK_DAILY_MODIFIER` du MSI ne règlent que la tâche planifiée du mode tâche Windows (`EXECMODE=2`), qui ôterait
l'interface locale de l'agent (geste « Force an Inventory » de l'assistant, contrôle « agent en attente » avant mise à
jour) ; l'installeur Linux ne connaît que `--cron` (horaire) et aucune clé de `conf.d` ou de `local.cfg` ne règle
l'intervalle (`delaytime` ne vaut que pour le premier contact).

**Réglage** : onglet « Déploiement Agent » de l'entité, bloc 2, avant le téléchargement (droit Déploiement en
modification, `front/collectfrequency.php`) : « Toutes les N heures » (1 à 23), « Tous les N jours » (1 à 365, défaut
1) ou « Comme l'entité parente ». Sans réglage propre, l'entité hérite de l'entité parente la plus proche qui en a un,
sinon quotidienne. Chaque changement est tracé dans l'historique de l'entité et dans le journal des raccordements dont
la fréquence effective change ; l'étape 3 de l'assistant journalise la fréquence retenue ; la note d'une page des
trois paquets la rappelle.

**Application** : aux tâches GLPI Inventory de découverte et d'inventaire réseau enregistrées par les raccordements
(tous statuts). Vérifié dans GLPI Inventory : un job n'est préparé que si la date de début de sa tâche est vide ou
passée, et un job demandé avant cette date, ou pour une tâche désactivée, est annulé à la remise à l'agent. La tâche
automatique `PrintgestionCollectSchedule` (toutes les 15 minutes, et à chaque enregistrement) pose donc
`datetime_start` = fin du dernier relevé terminé (journal des jobs `FINISHED` ou `IN_ERROR`) + fréquence, ou l'efface
quand le relevé est dû ; elle ne touche ni une tâche dont un relevé est en cours (jobs transmis à la sonde), ni une
tâche avec une date de fin (plage réglée à la main). Écriture directe dans la table de GLPI Inventory : il refuse toute
modification d'une tâche active et annule ses jobs préparés quand on la désactive. L'assistant efface la date avant de
lancer la découverte puis le relevé des niveaux (lancement immédiat). Limites : jamais plus souvent que la fréquence
d'inventaire globale de GLPI, puisque la sonde ne reçoit ses jobs qu'à son contact (avertissement sur l'onglet quand la
fréquence de l'entité est plus courte) ; la fréquence ne porte que sur les relevés d'imprimantes, pas sur l'inventaire
du PC sonde. Sans la tâche automatique, les tâches restent sans date : relevé à chaque contact, jamais moins souvent.

**Seuil « muette »** d'une imprimante : `max(silent_days, jours de la fréquence de son entité + 1)` (contrôle de la
remontée, alertes d'imprimantes, bloc de la fiche imprimante, onglet de la sonde). Une sonde reste muette au-delà de
`silent_days` : elle contacte GLPI à la fréquence globale quelle que soit l'entité.

### Contrôle de la remontée (`inc/collect.class.php`, onglet « Contrôle de la remontée »)

Ce que l'inventaire GLPI reçoit **réellement** des imprimantes, avant tout calcul d'alerte. Page en lecture
seule sur les tables natives ; périmètre : entités de l'utilisateur (module Collecte SNMP / Déploiement Agent, droit `deploiement` READ ; la carte des imprimantes muettes de l'écran des alertes n'y renvoie qu'avec ce droit). L'absence de
remontée est un **état à signaler**, jamais une absence d'alerte. Aucune couverture complète du parc n'est
supposée.

1. **Prérequis** : inventaire GLPI activé (`inventory.enabled_inventory`), plugin GLPI Inventory installé et
   actif, imprimantes inventoriées sur 24 h et 7 jours, agents muets, versions d'agent (avant 1.15 : une
   valeur de compteur invalide fait rejeter tout l'inventaire ; au-delà, version visée : dernière version connue ou
   version cible de la sonde, voir phase 5).
2. **États** datés par le journal d'import GLPI (`glpi_rulematchedlogs`) et par le journal des tâches de GLPI
   Inventory : seul un inventaire réseau compte. Journal d'import : méthodes `snmp`, `snmpquery`, `netinventory`.
   Avec GLPI Inventory, le cœur y note l'inventaire réseau `inventory` (le chemin du plugin ne lui transmet pas
   la requête), comme le premier import par une découverte ou une imprimante déclarée dans l'inventaire d'un
   PC : cette méthode ne prouve rien. L'inventaire réseau est alors lu dans les journaux des tâches
   `networkinventory` (`==updatetheitem== … [[Printer::id]]`, avec la sonde), conservés le temps que GLPI
   Inventory garde ses tâches (réglage « nettoyage des tâches »). Une découverte réseau (`netdiscovery`) fait
   avancer `glpi_printers.last_inventory_update` sans relire niveaux ni compteurs : elle est affichée à part.
   Le journal d'import ne garde que les 30 derniers passages par équipement.

   | État | Condition |
   |---|---|
   | Jamais inventoriée en SNMP | Aucun inventaire réseau dans le journal d'import |
   | Muette | Dernier inventaire réseau plus ancien que `silent_days` jours (3 par défaut), ou que la fréquence de relevé de son entité plus un jour si elle est plus longue |
   | Sans niveau lisible | Inventaire à jour, mais aucun niveau exploitable (sentinelles, OK, valeurs inconnues) |
   | Collecte normale | — |

   Agents : celui du dernier inventaire réseau (à défaut, de la dernière découverte), avec sa version et
   « ne remonte plus » si son dernier contact dépasse `silent_days` jours.
3. **Valeurs de consommables reçues, par fabricant et modèle** : pour chaque propriété, répartition des
   valeurs brutes (pourcentage, 0, OK, WARNING, pages restantes, autre unité, négatif, supérieur à 100,
   vide) et exemples non chiffrés. Sert à régler la lecture par constructeur et par modèle.
4. **Compteurs disponibles, par modèle** (dernier relevé `glpi_printerlogs`) : relevé ancien, N&B et
   couleur, total seul, compteurs à 0, imprimantes couleur sans compteur couleur, compteur couleur
   supérieur au total, baisses du compteur total sur 90 jours ; listes détaillées (200 lignes au plus).
5. **Numéros de série en double** parmi les imprimantes actives ; doublon entre entités différentes signalé (« verrous
   anti-double-envoi non partagés, à vérifier »).

### Points d'entrée (ajax/)

| Endpoint | Action |
|---|---|
| *(action de masse « Commander »)* | **Seul parcours de commande directe** : `Alertview::processOrder()` → `createPurchaseOrder()` (expéditions + fichier + mail Achats en transaction), droit validation UPDATE |
| `update_expedition.php` | Marquer expédié (transporteur + tracking) → `markShipped()` |
| `edit_expedition.php`, `reassign_expedition.php` | Édition / réassignation vers une autre imprimante |
| `resolve_alert.php` | Ignorer une alerte « mauvaise imprimante » (suspendre / réactiver : actions de masse de l'écran des alertes) |
| `link_bls.php`, `expedition_bls.php` | Lier des BL du plugin Gestion à une expédition (identifiant local ou « sage:<n°> » préparé dans l'entité de l'expédition) ; lister les BL liés. `link_bls.php` : sélection entière ou rien (transaction) ; `ok` vrai seulement si tout est enregistré, sinon `errors` et aucune liaison modifiée |

**BL du plugin Gestion** (`Security::getBlForExpedition()`) : un BL n'est utilisable pour une expédition que s'il existe et
si son entité est celle de l'expédition (figée à sa création) ou une entité parente (BL d'un groupe couvrant ses sites),
dans le périmètre de l'utilisateur (`Security::getBlEntities()`) ; jamais une entité sœur, même visible du compte.
Appliqué à `update_expedition.php` (BL posté avec « expédiée » : refus, rien d'écrit), à `search_bls.php` (recherche
dans la modale, filtrée par l'expédition), à `link_bls.php` (identifiant posté ou BL Sage déjà présent localement dans une autre entité : refusé et
signalé dans `errors`) et à `expedition_bls.php` (un lien antérieur vers le BL d'un autre client n'est jamais relu). BL
inexistant et BL d'un autre client reçoivent le même refus : l'existence d'un document d'un autre client n'est pas
révélée.

CSRF : la validation est faite par le `CheckCsrfListener` de GLPI 11 **avant** le chargement
des fichiers ajax (token `X-Glpi-Csrf-Token`). Ne PAS rajouter de `Session::checkCSRF()` dedans
(le token serait déjà consommé → exception).

---

### Suivi des colis GLS (`inc/glsclient.class.php`, `inc/glstracking.class.php`)

- **GLS seulement**, par une interface (`Carrierclient`) et une classe. UPS n'existe pas dans le code.
- **Le suivi ne décide de rien** : il n'écrit que ses colonnes `tracking_*`. Le statut de l'expédition ne bouge que
  par les gestes du plugin (BL signé, pose détectée, actions) ; un colis « livré » n'autorise aucune nouvelle commande.
- **Numéro** : la saisie brute est conservée et affichée ; la clé interrogée est `Glsnumber::clean()` puis, sur
  `E_404_01` seulement et pour dix caractères alphanumériques, le Track ID à huit (repli unique, journalisé,
  mémorisé avec le suffixe à part).
- **Cadence et bornes** : tâche horaire, jamais au chargement d'une page ; une ligne par heure au plus ; paquets de
  dix ; 80 % du quota (500/jour) ; disjoncteur à cinq échecs techniques ; 429 = plus d'appel de la journée ; inconnu :
  24 h puis trois cycles ; 30 jours sans mouvement : « sans nouvelles » ; final : plus jamais.
- **Transporteurs** : GLS, UPS et Autre proposés à l'expédition (`Expedition::CARRIERS_OFFERED`) ; Chronopost reste lisible sur les expéditions qui le portent, jamais offert ni accepté à neuf. Un colis UPS ou Autre n'affiche que le numéro saisi.
- **Codes** : énumération fermée dans `Glstracking::STATUS_LABELS` ; finaux `DELIVERED`, `CANCELED`, `FINAL` ;
  `DELIVEREDPS` (point relais) distinct et toujours interrogé ; `NOTPICKEDUP` et `NOTDELIVERED` = anomalies, en rouge
  « à signaler aux Achats » ; code inconnu = non final, journalisé. Les codes d'événement ne pilotent rien.
- **Affichage** (liste des expéditions, cartes « expédition en retard ») : identique pour tous les profils, rien de

### MBE, intermédiaire de transport (`inc/mbeclient.class.php`) — étape 1 : identifiants et test

- **MBE n'est pas un transporteur** : c'est l'intermédiaire par lequel les cartouches du stock partent chez le client,
  confiées à GLS ou à UPS. Il n'entre pas dans `Expedition::CARRIERS_OFFERED` ; le transporteur réel et son numéro se
  saisissent comme aujourd'hui, MBE se retrouvera par ce numéro (étape suivante). Aucune création d'expédition, ni
  maintenant ni par déduction : l'API documentée est en lecture seule.
- **Cette étape** : la carte « MBE (intermédiaire de transport) » de la configuration (identifiant API et passphrase,
  chiffrés GLPIKey, `Config::SECRET_FIELDS` ; l'identifiant est réaffiché, la passphrase jamais, « définie le »,
  « Remplacer », « Retirer les identifiants »), « Tester la connexion » et la ligne « Identifiants MBE » de la carte
  Santé. Rien d'autre n'appelle MBE. Longueur maximale d'un identifiant ou d'une passphrase : 150 caractères
  (`Mbeclient::MAX_CREDENTIAL_LENGTH`), pour que la valeur chiffrée tienne dans `varchar(255)`.
- **Appel** : `POST https://api.mbeonline.fr/ws` (jamais `/ws/ws`), SOAP 1.1, `Content-Type: text/xml`, `SOAPAction`
  vide, HTTP Basic identifiant:passphrase et le bloc `<Credentials>` dans le conteneur ; adresse et système (`FR`) sont
  des constantes, pas des réglages. Enveloppe construite avec DOM, valeurs en nœuds texte : seul le nom de l'opération
  porte le préfixe du namespace MBE, les éléments internes restent sans namespace (préfixés, MBE répond 500,
  NullPointerException). Pas d'extension `soap` : Guzzle (proxy de GLPI, TLS vérifié, redirections non suivies) et DOM.
  Lecture de la réponse par XPath sans préfixe (`local-name()`), sans accès réseau (`LIBXML_NONET`).
- **Trois formes d'erreur** : 401/403 = identifiants ou droits (`auth`, « réessayer ne change rien », la carte Santé
  passe au rouge tout de suite) ; 500 = requête refusée (`request`, le format en cause) ; réseau et autres 5xx =
  technique (`network`, `server`). `Status ≠ OK` dans un 200 = `request` avec le texte des `Errors`, masqué.
- **Jamais un secret** : `mask()` retire identifiant, passphrase, en-tête `Basic` et liens signés (`X-Amz-…`) de tout
  texte rendu ; les messages HTTP ne reprennent pas le corps de la réponse.
- **Colonnes** : `glpi_plugin_printgestion_configs.mbe_username`, `.mbe_passphrase`, `.mbe_secret_date` ;
  `glpi_plugin_printgestion_expeditions.mbe_master_tracking` (référence `MasterTrackingMBE` mémorisée sur la ligne,
  posée dès la 1.0.0 parce que le suivi l'utilisera ; vide tant qu'aucune interrogation n'existe).
  diagnostic ; sans clés, l'écran est exactement celui d'avant. **Santé** : quatre lignes pour l'administrateur
  (clés, dernier appel réussi, échecs consécutifs, quota du jour).
- **Secrets** : `gls_client_secret` chiffré (GLPIKey), jeton chiffré dans le cache ; ni l'un ni l'autre dans une page,
  un message ou un journal. Harnais `tests/securite/suivi_gls.py` (65 contrôles, transport et transporteur simulés,
  copies d'écran des deux profils hors dépôt).

## 6. Circuits mail — règle d'or : REGROUPER

> Principe directeur (décision projet) : **jamais N mails identiques quand 1 mail groupé suffit.**
> Les listes dans les corps de mail sont plafonnées à `Expedition::MAIL_LIST_MAX` (20) lignes
> (« … et N autres ») ; le détail complet part en **pièce jointe Excel** quand pertinent.

### 6.1 Destinataires par rôle

3 rôles configurables (Configuration → Rôles & notifications) : `planif`, `achat`, `commercial`.
Chaque rôle = un groupe GLPI **ou** une liste d'utilisateurs GLPI (`Alert::resolveRecipientsForRole()`).
Le **demandeur** (user qui déclenche) est toujours en copie des mails planif/achats.
Le bouton « Qui est notifié ? » de la config affiche le récapitulatif selon la config enregistrée.

### 6.2 Les circuits

| Déclencheur | Planif | Achats | Commercial | Client (courtoisie) |
|---|---|---|---|---|
| Commande (N cartouches, N imprimantes/clients) | case « Planif » : 1 → unitaire ; N → groupé **client par ligne** + **Excel joint** | **Excel joint** (toujours) | — | case « Courtoisie » : regroupé par contact |
| Cron toner bas (horaire) | — | — | **DIGEST** : 1 mail/run | — |
| Cron rappel installation | mode `planif`/`both` : **DIGEST** 1 mail/run | — | mode `commercial`/`both` : même digest | — |
| Marquer expédié | — | — | gabarit unitaire (transporteur + tracking) | — |

Détails d'implémentation (tous dans `expedition.class.php` sauf mention) :

- **`sendPurchaseOrderMail($rows, $requester_uid)`** : point UNIQUE du mail achats.
  Appelé par `Purchaseorder::send()` après l'enregistrement de la commande, avec le fichier archivé.
  Joint le fichier Gesconso (`Gesconso::write`, voir « Fichier Gesconso », 1 cartouche/ligne),
  l'envoie via `gabarit_achat` (corps synthétique : « N référence(s), détail dans l'Excel joint »),
  fallback mail brut si gabarit non configuré. Fichier temporaire supprimé après envoi.
- **Planif simple/multi** : 1 cartouche → `gabarit_planif` (détail unitaire) ;
  N cartouches → `gabarit_planif_group` avec liste `##printgestion.cartridges_list##`
  (client précisé par ligne pour les commandes multi-clients), plafonnée à 20, + Excel joint.
- **Courtoisie** (`sendOrderCourtesyMails`) : regroupée par **destinataire** (clé = ensemble
  d'emails résolus, trié) — un contact couvrant 3 imprimantes reçoit 1 seul mail listant
  ses 3 imprimantes (`##printgestion.printers_list##`). Destinataires résolus par
  `resolveClientEmailsForPrinter()` : **uniquement l'usager renseigné sur la fiche imprimante** ;
  sans usager (ou sans email), aucun mail n'est envoyé pour cette imprimante. Case décochée par défaut.
- **Digests cron** : `Alert::sendPendingAlerts()` et `Expedition::sendInstallReminders()`
  collectent d'abord tous les items du run (anti-doublon 24 h conservé PAR item via
  `glpi_plugin_printgestion_alerts`), puis envoient **un seul mail** avec liste plafonnée.
  1 seul item → balises unitaires détaillées (pas de digest inutile).

### 6.3 Gabarits de notification

- **Source de vérité : `hook.php` → `plugin_printgestion_template_definitions()`** (fonction pure).
- À chaque install/« Mettre à jour » du plugin, `plugin_printgestion_create_templates()` crée les gabarits
  **manquants** (jamais réécrits : les modifications de l'administrateur survivent) dans `glpi_notificationtemplates` /
  `glpi_notificationtemplatetranslations` (marqués `comment = 'Created by plugin printgestion'`).
  Les IDs sont stockés dans la config (`gabarit_planif`, `gabarit_planif_group`, `gabarit_achat`,
  `gabarit_commercial`, `gabarit_rappel`, `gabarit_courtoisie`).
- L'envoi (`Config::sendMail($emails, $gabarit_id, $balises, $attachment)`) charge la traduction
  (langue session → 2 lettres → fr_FR → première dispo), substitue les balises, envoie via
  `GLPIMailer` (Symfony Mailer GLPI 11), pièce jointe optionnelle.
- **Aperçu** : `docs/apercu_gabarits.html`, régénérable par `php tools/generate_apercu.php`
  (lit les vraies définitions, aucune BDD).

### 6.4 Balises disponibles

`##printgestion.printer##`, `client`, `toner`, `level`, `days`, `cartridge`, `contract`,
`carrier`, `tracking`, `cartridges_list` (liste HTML détaillée), `printers_list` (liste imprimantes,
courtoisie), `count`, `glpi_url`. Toute balise non fournie est remplacée par une chaîne vide.
`stock` reste reconnue pour les gabarits existants, toujours vide : aucun stock GLPI n'est lu.

---

## 7. Tâches cron (classe `Reminder`)

| Tâche | Fréquence par défaut | Contenu |
|---|---|---|
| `PrintgestionSnapshotReadings` | quotidienne | Snapshot toner + bootstrap cartouches natives + détection changements + purge relevés > 160 j |
| `PrintgestionCheckAlerts` | horaire | Calcul alertes + **digest mail commercial** + **digest rappels installation** + rebuild `alertview` |
| `PrintgestionTrackingUpdate` | **toutes les 12 heures** (valeur initiale, réglée ensuite dans GLPI ; deux passages par jour suffisent aux deux suivis) | Dans l'ordre : rattrapage des BL signés du plugin Gestion (le cas normal part de la signature elle-même), puis `Glstracking::poll()` qui range ce que GLS publie et constate les remises, puis `Mbetracking::poll()` qui apparie ce qui ne l'est pas et **recoupe son statut avec celui que GLS vient d'écrire**. Rien sans clés, de part et d'autre |
| `PrintgestionProposeDemandes` | horaire, **enregistrée désactivée** | Demandes d'envoi proposées à partir des alertes, regroupées par client et site (`Demande::proposeFromAlerts()`) |
| `PrintgestionCheckAgentVersion` (classe `Agentsetting`) | hebdomadaire | Dernière version publiée de GLPI Agent sur GitHub (une saisie à la main est gardée) |
| `PrintgestionSilentProbes` (classe `Agentalert`) | quotidienne | Alertes « sonde sans contact » et « imprimante qui ne remonte plus » : ouverture, fermeture, notifications |
| `PrintgestionCollectSchedule` (classe `Collectfrequency`) | 15 minutes | Fréquence des relevés de chaque entité appliquée aux tâches GLPI Inventory des raccordements (date de début) |
| `PrintgestionEntityScope` (classe `Entityscope`) | quotidienne | Entité des données techniques recalée sur celle de l'imprimante ou du contrat, chaque écart corrigé journalisé en erreur ; lignes orphelines signalées ; données commerciales jamais lues |

Les 4 premières tâches sortent immédiatement (`return 0`) si la feature `toner` est désactivée, les 3 du module
Déploiement Agent si la feature `deploiement` l'est. Toutes sont enregistrées par `Reminder::install()`, sauf `PrintgestionEntityScope` (`Entityscope::install()`), qui
tourne quels que soient les modules actifs.
Un groupe client/site en échec termine `PrintgestionProposeDemandes` en erreur d'exécution (exception après le
bilan), jamais en succès. `PrintgestionProposeDemandes` est enregistrée désactivée : une ligne proposée bloque la commande de sa
cartouche depuis l'écran des alertes jusqu'à son export ou son annulation. L'activer quand l'export des
demandes validées est en service. Une mise à jour du plugin ne change pas l'état choisi.

---

## 8. Droits et profils

8 droits (`Profile::initProfile()`, ALLSTANDARDRIGHT au profil ayant `config` UPDATE à l'install) :

| Droit | Protège |
|---|---|
| `plugin_printgestion_contrats` | Dashboard contrats / Liste / Créer Print (UPDATE pour créer ; entité cible, contrat, imprimante et lieu choisis toujours dans le périmètre du compte, contrôlés avant toute écriture) ; onglet « Tarifs Print Gestion » des contrats : READ voir, UPDATE ajouter ou supprimer un tarif, toujours avec le droit natif sur le contrat |
| `plugin_printgestion_dashboard` | Alertes toner (dashboard + actions) ; onglet « Seuils d'alerte » des imprimantes (UPDATE pour enregistrer) |
| `plugin_printgestion_expedition` | Expéditions (UPDATE pour agir) |
| `plugin_printgestion_validation` | Demandes d'envoi : READ voir, UPDATE modifier / valider / annuler (file aussi visible avec `dashboard` READ, sans agir) |
| `plugin_printgestion_deploiement` | Collecte SNMP / Déploiement Agent : READ voir et télécharger l'installeur et les paquets de consigne, page « Sondes », onglet de la fiche Agent, cartes du tableau de bord ; UPDATE raccorder des imprimantes, régler la fréquence des relevés de l'entité, régler la mise à jour des sondes, marquer le PC sonde ; « Contrôle de la remontée » |
| `plugin_printgestion_sage` | Référentiel Sage : UPDATE import des référentiels (adresses, articles) ; READ sans écran propre |
| `plugin_printgestion_billing` | Coût à la page : écrans et onglet de la fiche imprimante (prix et coûts ; jamais le seul droit sur l'imprimante) |
| `plugin_printgestion_config` | Configuration du plugin + mappings SNMP ; onglet « Print Gestion » des cartouches (liaisons SNMP) : READ voir, UPDATE enregistrer, toujours avec le droit natif sur la cartouche |

À l'installation, seul le profil qui installe reçoit les droits du plugin (`Profile::createFirstAccess`) ; les autres
profils partent à zéro et se règlent dans Administration → Profils → Print Gestion.

**Une seule exception, assumée** : `front/agentpull.php` ne demande aucun droit et ne demande même pas d'être
connecté — c'est la page où le fichier unique d'installation vient chercher le MSI de GLPI Agent, depuis le PC d'un
client qui n'a aucun compte GLPI. Son contrôle d'accès est la clé de récupération : usage unique, 24 h, empreinte
seule stockée, consommée avant l'envoi (voir phase 4). Ce qu'elle donne : le MSI officiel de Teclib' déjà servi par
ce serveur, et rien d'autre — ni session, ni donnée de GLPI, ni écriture. Toute autre demande est un 404 muet, tracé
dans `printgestion.log`. Le harnais vérifie les trois comportements : sert sans compte, ne sert qu'une fois, refuse
une clé inventée.

`front/agentreport.php` est la seconde, et elle **écrit** : le compte rendu d'installation, et avec lui la création
du raccordement à partir des adresses saisies sur le PC. Ce que ça ouvre, et il faut le dire : qui détient le fichier
d'installation d'une intervention peut, une fois, faire créer dans cette entité-là un raccordement visant les
adresses de son choix — donc faire balayer en SNMP une plage de ce réseau. C'est borné par la clé (usage unique,
24 h, une seule entité), c'est visible dans l'écran Raccordements avec son journal, et ça ne donne accès à aucune
donnée de GLPI. Le fichier se donne au technicien pour l'intervention ; il ne s'archive pas.

---

## 9. Intégration moteur de recherche natif (points non triviaux)

1. **Tables matérialisées** : les alertes (calcul coûteux) et le billing (dépendant d'une période
   par utilisateur) ne peuvent pas être requêtés en direct par le Search. On matérialise :
   `alertview` (globale, rebuild cron/bouton) et `billing_view` (par utilisateur, recalcul au
   submit du formulaire de filtres, cache calcul 10 min).
2. **Mapping table ↔ itemtype** : `glpi_plugin_printgestion_billing_view` contient un underscore
   que `getItemTypeForTable()` interprète mal (→ `Billing_View`). Le mapping est enregistré
   explicitement dans `setup.php` (`$CFG_GLPI['glpiitemtypetables']` + réciproque). À refaire
   pour toute nouvelle table dont le nom ne se re-déduit pas par split sur `_`.
3. **`plugin_printgestion_addDefaultWhere()`** (setup.php) : périmètres natifs pour
   `PluginPrintgestionContract` (contrats liés à ≥1 imprimante), `Billingview` (lignes du user
   courant + vue courante) et `Expedition` (seconde barrière : restriction explicite sur l'`entities_id` de
   l'expédition, figée à sa création, la même que la restriction native ; jamais l'entité de l'imprimante).
4. **Itemtype dédié `PluginPrintgestionContract`** : sous-classe de Contract sans table propre,
   pour isoler session de recherche/colonnes/exports et porter l'autorisation par le droit plugin.

---

## 10. Dépendances

- **GLPI 11.0.x** (bornes dans `setup.php`) — utilise `GLPIMailer` (Symfony Mailer),
  `glpi_printers_cartridgeinfos` (inventaire SNMP natif), ECharts bundlé.
- **PhpSpreadsheet** : fourni par le vendor de GLPI (génération des Excel de commande).
- **Plugin Gestion** (optionnel) : liaison BL signés → expéditions delivered. Les appels sont
  défensifs : absence du plugin = ignoré silencieusement.
- **APIs transporteurs** (optionnel) : UPS / GLS / Chronopost, clés dans la config ; tout échec
  d'API est ignoré silencieusement (le cron continue).
