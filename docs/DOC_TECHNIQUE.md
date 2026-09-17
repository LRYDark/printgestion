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
| `sage` | Référentiel Sage : import par fichier, correspondance entité ↔ client Sage |

Un sous-onglet du menu n'est visible que si **sa feature est activée ET le droit READ correspondant est présent**
(`visibilité = feature ∧ droit`). Un module désactivé ne consomme aucune ressource (les crons sortent immédiatement).

Origine : fusion des anciens plugins `printcost` (toner/expéditions/billing) et `gestionprint` (contrats/dashboard).

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
│   └── generate_apercu.php  # régénère apercu_gabarits.html depuis hook.php (CLI, sans BDD)
├── apercu_gabarits.html # aperçu HTML de tous les gabarits mail (généré)
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
| `Glsnumber` | Suivi GLS, numéro de suivi (sans appel réseau, expéditions GLS seulement) : `clean()` (URL collée → code ; espaces, tirets, points retirés ; majuscules ; la saisie brute n'est jamais réécrite) ; `fallback()` (exactement 10 caractères alphanumériques → Track ID à 8 caractères + suffixe de 2 conservé sans interprétation : formats publiés par GLS Track And Trace V1 = colis 11/12 chiffres, Track ID 8, carte 6, carte numérique 14 ; le code d'étiquette à 10 n'en est aucun) ; `lookup()` (lots de 10, clé saisie interrogée d'abord, repli une seule fois et seulement sur `E_404_01`, rapprochement par `requested`, plusieurs colis possibles par clé). Harnais `tests/securite/suivi_gls.py` (réponses simulées) |
| `Confighealth` | Carte « Santé de la configuration », en tête de la configuration (droit de configuration du plugin ; boutons avec UPDATE) : contrôles automatiques obligatoires (URL de l'application GLPI : absolue, avec schéma, ni locale ni nom sans domaine qui ne se résout pas, `Agentdeploy::getApplicationUrlIssue()` ; puis preuve par le réel, `getUrlConfirmation()` : « confirmée par un agent le … » si un agent portant le TAG d'une entité a contacté GLPI depuis que cette URL est en place, sinon « jamais confirmée », état d'attente qui empêche la carte de se replier ; « depuis quand » = mémo dérivé dans `glpi_configs` contexte `plugin:printgestion` (`url_base_seen`, `url_base_seen_since`), réécrit seulement quand l'URL change, jamais saisi ; inventaire GLPI activé ; GLPI Inventory via `Collectsetup::getPrerequisites()` ; actions automatiques, `getCronStatus()` : jugées sur les tâches du plugin seulement + `queuednotification` en lecture ; cron système prouvé par la tâche témoin `PrintgestionTemoinCron` (CLI seulement, chaque minute, ne fait que dater son passage, passée dans les 15 min) ou déclaré par `GLPI_SYSTEM_CRON` ; rien ne bascule tout seul : bouton « Passer les N tâches de Print Gestion en CLI » (config.form.php, `switch_plugin_tasks_cli`) seulement quand le cron système est prouvé, jamais les tâches de GLPI ni d'un autre plugin ; détail = GLPI_SYSTEM_CRON, chaque tâche avec état, mode, fréquence, dernière exécution et lien vers sa fiche (`renderTaskTable()`) ; xlsx autorisé via `Document::isValidDoc()` ; règle d'affectation par TAG active, bouton `Agentdeploy::getTagRuleButton()` traité par `config.form.php`) et recommandés (sauvegarde de `glpicrypt.key` : non vérifiable, rappel ; journal inscriptible et écriture de test). Ligne : état, ce qui casse, où corriger ; bannière rouge si un obligatoire manque, sans bloquer ; tout vert, repliée en « Configuration : complète ». Harnais `tests/securite/sante.py` |
| `Ui` | Fragments d'interface partagés : barre de statistiques ; `jsonData()`, seul passage des données PHP vers le JavaScript (bloc JSON non exécuté, drapeaux `JSON_HEX_*`) ; deux publics : `isAdmin()` (droit de configuration du plugin), `statusLine()` (ligne d'état, détail replié pour l'administrateur), `adminDetails()` (chevron fermé), `infoButton()` (fenêtre « i ») ; tout élément réservé porte `data-pg-admin` et n'est jamais envoyé à un autre profil |
| `Entityscope` | Entité des données client (1.6.5, 1.6.6) : entité à écrire à la création ; données techniques qui suivent l'imprimante ou le contrat ; données commerciales figées ; lignes orphelines (`findOrphans()`) ; tâche de contrôle `PrintgestionEntityScope` |
| `Cartridgehistory` | Détection automatique des changements de cartouche (hausse de niveau ≥ `detection_delta` %) |
| `Alert` | Calcul intelligent des alertes toner (vitesse de conso sur fenêtre 30 j) + **digest mail commercial** |
| `Alertview` | Table **matérialisée** des alertes et écran natif (recherche, colonnes verrou / référence, actions de masse Commander, Ne plus alerter, Réactiver) |
| `Expedition` | Cycle d'expédition des cartouches, **tous les circuits mail** (planif/achats/courtoisie/rappels) |
| `Demande` / `Demandeline` | Demande d'envoi (en-tête client + site, lignes) : statuts, contrôles avant validation, historique natif |
| `Guard` | Verrous anti-double-envoi (envoi en cours, demande ouverte, garde après pose, ticket récent) |
| `Sageimport` | Import du référentiel Sage par fichier : analyse, prévisualisation, rapport d'écarts, validation |
| `Sage` | Correspondances Sage (code client d'une entité, hérité du parent) + onglet « Print Gestion — Sage » de l'entité |
| `Gesconso` | Fichier de commande Gesconso (9 colonnes), contrôles bloquants avant écriture, archivage en Document |
| `Snmpadapter` | Service (classe simple, sans table) : lecture fiable des niveaux SNMP — sentinelles, états bruts max/used/remaining, application des règles par constructeur |
| `Snmprule` | Règle de lecture SNMP par constructeur (ignorer / inverser une propriété) : table, carte de configuration, droit de configuration du plugin |
| `Collect` | Contrôle de la remontée (lecture seule) : prérequis, états de collecte datés par le journal d'import GLPI, agents et versions, valeurs de consommables et compteurs par modèle, doublons de numéro de série |
| `Agentdeploy` | Déploiement Agent : onglet de l'entité (TAG, règle d'affectation, agents), installeur GLPI Agent servi et vérifié, paquet Windows pré-paramétré |
| `Raccordement` | Assistant de raccordement des imprimantes (4 étapes, journal horodaté), page « Raccordements », bloc 3 de l'onglet Déploiement Agent de l'entité |
| `Collectsetup` | Service (sans table) : configuration de collecte créée dans GLPI Inventory (plage, identifiants SNMP, modules de la sonde, tâches), déclenchement, vérification adresse par adresse |
| `Raccordementdetail` | Lieu (hiérarchie créée dans l'entité), commentaire et contrat des imprimantes d'un raccordement : saisie en attente (carte 2 bis), application aux imprimantes remontées avec verrou natif (étape 5) |
| `Printeragent` | Fiche imprimante : sonde responsable (plage et tâche GLPI Inventory), version, dernier contact, dernier inventaire réseau réussi ; dans la carte native « Informations d'inventaire », sinon sous le formulaire |
| `Agentsetting` | Sondes : dernière version connue de GLPI Agent (GitHub, saisie), conformité, réglages de mise à jour par sonde, paquet de consigne, imprimantes collectées, statut du PC sonde ; onglet de la fiche Agent et page « Sondes » |
| `Agentalert` | Alertes « sonde sans contact » et « imprimante qui ne remonte plus » (tâche quotidienne), réglages et action dans « Agent cleanup », cartes du tableau de bord |
| `NotificationTargetAgentalert` | Notifications natives des alertes de sondes (sonde sans contact, imprimantes qui ne remontent plus) |
| `Collectfrequency` | Fréquence des relevés d'imprimantes par entité (héritée, quotidienne par défaut) : décision commerciale, réglée par l'administrateur du plugin seulement (chevron « Fréquence des relevés » sous les installeurs, droit de configuration en modification ; le technicien n'en voit ni texte, ni champ, ni bouton), planification des tâches GLPI Inventory des raccordements, seuil « muette » des imprimantes ; **écrit directement `datetime_start` dans la table des tâches de GLPI Inventory** (le plugin voisin refuse de modifier une tâche active) — garde-fou `assertInventoryTaskColumns()` : colonnes `datetime_start`/`datetime_end` vérifiées, sinon exception (tâche automatique en erreur, journal du plugin, ligne rouge dans l'onglet de l'entité, message dans l'assistant) ; à reprendre par une voie du plugin voisin quand elle existera (`tests/securite/prerequis.py`, section 2) |
| `NotificationTargetDemande` | Notifications natives GLPI des demandes d'envoi (proposée, relance, exportée) |
| `Contractalert` | État et activation des alertes de contrat natives GLPI |
| `Snmpmapping` | Mapping constructeur + propriété SNMP → modèle de cartouche + couleur |
| `Cartridgesnmp` | Onglet sur fiche CartridgeItem : binding direct cartouche ↔ propriétés SNMP |
| `Billing` / `Billingview` | Coût à la page + table matérialisée **par utilisateur** (le calcul dépend de la période choisie) |
| `PrinterCostsTab` | Onglet « Coût à la page » sur la fiche imprimante : prix et coûts (droit `billing` READ) |
| `PrinterThresholdsTab` | Onglet « Seuils d'alerte » sur la fiche imprimante : seuils et rendement propres (droit `dashboard` READ, UPDATE pour enregistrer) |
| `Tracking` | Intégrations externes : BL signés du plugin Gestion + APIs transporteurs (UPS/GLS/Chronopost) ; lien avec le plugin Gestion **déduit** (`isGestionLinkActive()` : plugin actif et table des BL présente), jamais réglé — l'ancien interrupteur « Activer lien plugin Gestion » est supprimé (1.6.8) ; le passage automatique en « livrée » sur BL signé ne touche pas au verrou anti-doublon (statut actif, seule la pose détectée clôt l'envoi ; prouvé par `tests/securite/bl.py`) ; configuration « Suivi GLS » : `gls_client_id` en clair, `gls_client_secret` chiffré (GLPIKey), jamais réaffiché (« •••••••• défini le », « Remplacer »), « Retirer les clés » ; aucun interrupteur, aucune URL, aucune fréquence — des clés et un dernier appel réussi = suivi actif ; les URL de l'API sont des constantes du code (client GLS : bloc 4) ; les anciennes clés UPS / GLS / Chronopost et leurs fonctions vides sont supprimées (1.6.8) ; aucun transporteur présélectionné à l'expédition, choix explicite exigé |
| `Reminder` | Les 3 tâches cron GLPI (voir §7) |
| `Dashboardactions` | Menu contextuel et modales des écrans Expéditions et Coût à la page (modifier l'expédition, BL) |

---

## 3. Modèle de données

Le schéma est **versionné** (`inc/schema.class.php`) : la version installée est enregistrée dans la
configuration GLPI (`glpi_configs`, contexte `plugin:printgestion`, clé `schema_version`) et chaque
évolution est une étape de migration jouée une seule fois lors du « Mettre à jour » (voir doc maintenance §2).

| Table | Contenu |
|---|---|
| `glpi_plugin_printgestion_configs` | Configuration singleton (id=1) : features, seuils, rôles mail, IDs gabarits, installeur GLPI Agent (étape 1.6.0) ; dernière version connue de GLPI Agent, mise à jour automatique des nouveaux paquets, statut des PC sondes (1.6.3) |
| `glpi_plugin_printgestion_toner_readings` | Snapshots horodatés des niveaux toner (purge > 160 j) |
| `glpi_plugin_printgestion_cartridge_history` | Changements de cartouche détectés |
| `glpi_plugin_printgestion_alerts` | Alertes émises (traçabilité + anti-doublon mail 24 h) |
| `glpi_plugin_printgestion_alert_snoozes` | Mises en sommeil d'alertes (par toner ou par imprimante) |
| `glpi_plugin_printgestion_alertview` | **Matérialisée** : 1 ligne par couple imprimante/toner pour le Search natif |
| `glpi_plugin_printgestion_expeditions` | Expéditions de cartouches (statuts, transporteur, group_id, users) |
| `glpi_plugin_printgestion_demandes` | Demandes d'envoi : client (entité), site de livraison, statut, mode et contact de livraison, validation, annulation |
| `glpi_plugin_printgestion_demandelines` | Lignes de demande : imprimante, toner, cartouche, quantité, prix unitaire, contrat, statut |
| `glpi_plugin_printgestion_sageclients` | Clients Sage importés (code, intitulé, présent au dernier import) |
| `glpi_plugin_printgestion_entitysageclients` | Correspondance entité GLPI → client Sage (une entité = un client ; un client = plusieurs entités) |
| `glpi_plugin_printgestion_sagedeliveries` | Adresses de livraison Sage (plusieurs par client), clé rapprochée de `Location.code` |
| `glpi_plugin_printgestion_sagearticles` | Articles Sage (référence rapprochée de `CartridgeItem.ref`) |
| `glpi_plugin_printgestion_sageimports` | Trace des imports (référentiel, fichier, auteur, volumes) |
| `glpi_plugin_printgestion_snmprules` | Règles de lecture SNMP par constructeur (ignorer / inverser une propriété) — nommée `snmpadapters` avant la 1.5.5 |
| `glpi_plugin_printgestion_expedition_bls` | Liaison expéditions ↔ BL du plugin Gestion |
| `glpi_plugin_printgestion_purchaseorders` | Transmission aux Achats (étape 1.6.7) : une ligne par commande enregistrée (groupe d'expéditions), origine, fichier archivé, lignes du mail, statut d'envoi (`pending`, `sending`, `sent`, `failed`), tentatives, dernière erreur, dates d'envoi et de notification |
| `glpi_plugin_printgestion_snmp_mapping` | Mapping constructeur/propriété SNMP → cartouche |
| `glpi_plugin_printgestion_cartridge_snmp` | Bindings directs cartouche ↔ propriété SNMP |
| `glpi_plugin_printgestion_contractrates` | Tarifs €/page N&B / Couleur par contrat |
| `glpi_plugin_printgestion_billing` | Lignes de facturation calculées |
| `glpi_plugin_printgestion_billing_view` | **Matérialisée par utilisateur** : coût à la page pour le Search natif |
| `glpi_plugin_printgestion_historical_yields` | Rendements historiques (pages/cartouche) |
| `glpi_plugin_printgestion_printer_thresholds` | Seuils d'alerte personnalisés par imprimante |
| `glpi_plugin_printgestion_raccordements` | Raccordements d'imprimantes (étape 1.6.1) : entité, sonde, statut, identifiants SNMP, plages et tâches GLPI Inventory utilisées, objets créés, dates des étapes |
| `glpi_plugin_printgestion_raccordementips` | Adresses déclarées d'un raccordement et leur résultat (équipement trouvé, son entité) ; depuis 1.6.2, lieu, commentaire et contrat en attente, imprimante et date de leur application |
| `glpi_plugin_printgestion_raccordementlogs` | Journal horodaté d'un raccordement : étape, niveau, auteur, message |
| `glpi_plugin_printgestion_agentsettings` | Réglages de mise à jour par sonde (étape 1.6.3) : agent (unique), mise à jour automatique, version cible, auteur, dates |
| `glpi_plugin_printgestion_agentalerts` | Alertes de sondes (étape 1.6.3) : type (sonde sans contact, imprimante qui ne remonte plus), entité, sonde, imprimante, motif, début, notification, fin ; une seule alerte ouverte par sonde ou par imprimante (colonne générée `open_lock`) |
| `glpi_plugin_printgestion_collectfrequencies` | Fréquence des relevés d'imprimantes par entité (étape 1.6.4) : entité (unique), unité (`hourly`, `daily`), nombre, auteur, dates ; sans ligne, l'entité hérite de sa parente, sinon quotidienne |
| `glpi_plugin_printgestion_table_prefs` | Préférences d'affichage des tableaux par utilisateur |

**Entité des données client (étapes 1.6.5 et 1.6.6).** `entities_id` et `is_recursive` sont portés par les tables
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
- **étape 1.6.6** : entité des données commerciales recalculée à leur date de création, d'après l'historique GLPI de
  l'imprimante (changements d'entité, option 80 ; de récursivité, option 86) ; une expédition réattribuée reprend son
  imprimante d'origine (note « Réassignée depuis l'imprimante #N ») ; liaisons BL et lignes de demande prennent l'entité
  de leur expédition ou de leur demande ;
- **lignes orphelines** : imprimante (expédition, demande, contrat) introuvable à l'écriture ou purgée avant que
  l'entité ne soit connue : entité racine, non récursive, donc invisible des comptes clients. Jamais rattachées d'office
  à une autre entité ni supprimées : `Entityscope::findOrphans()` les liste (objet de rattachement absent et entité
  racine non récursive) dans la configuration du plugin (carte « Lignes sans objet de rattachement », comptes de la
  racine), dans le journal de la tâche quotidienne et à la migration 1.6.6, pour qu'un administrateur tranche.
Hors périmètre : tables globales (référentiel Sage, mappings et règles SNMP, liaisons cartouche) et tables déjà
rattachées à une entité (raccordements et leurs adresses et journaux via le raccordement, fréquences, alertes de
sondes, réglages de sonde via l'agent natif).

---

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
  à l'installation avec les quatre toners et les kits ; la 1.5.8 retire les anciennes lignes pré-remplies sous
  des libellés qu'aucun inventaire ne produit (« Toner Noir », `developercyan`…), si elles n'ont pas été
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
- **Aucun statut lié au stock** : le statut `stock_empty`, calculé sur le stock GLPI, est supprimé depuis la
  1.5.7 (envois concernés repassés `pending`, avec une note dans l'expédition).
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
  fiche) : lignes validées passées dans `Gesconso::prepare()` — code client Sage, adresse de livraison,
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

  | Référentiel | Colonnes (obligatoires en gras) | Rapprochement GLPI |
  |---|---|---|
  | Clients | **Code client** (`CT_Num`), **Intitulé** (`CT_Intitule`) | Entité, par la table de correspondance du plugin |
  | Adresses de livraison | **Code client**, **Intitulé livraison** (`LI_Intitule`), Code adresse (`LI_No`), Adresse, Code postal, Ville | Lieu dont le champ natif `Code` vaut le code adresse (à défaut : l'intitulé) |
  | Articles | **Référence** (`AR_Ref`), Désignation (`AR_Design`) | Cartouche dont la référence (`CartridgeItem.ref`) vaut la référence |

- **Déroulé** : analyse (contrôles : colonnes obligatoires, valeurs manquantes, doublons — bloquants ; codes
  clients en minuscules ou avec espaces — avertissement), prévisualisation (nouvelles, modifiées, inchangées,
  absentes) et **rapport d'écarts** (entités à imprimantes sans code client ; adresses sans lieu GLPI ou sans
  client connu ; imprimantes sans adresse résolue ; cartouches sans référence ou de référence absente du
  fichier), puis validation en transaction. L'analyse attend en session : rien n'est écrit avant validation.
- **Aucune suppression** : une ligne absente d'un nouvel import passe `is_in_last_import = 0` et ne sert plus
  à l'export. L'import ne modifie aucun objet GLPI ; seules les correspondances entité ↔ client cochées
  (suggestion : entité de même nom) ou choisies à la validation sont écrites.
- **Périmètre** : une correspondance n'est acceptée que vers une entité du périmètre de l'utilisateur (sinon
  même refus qu'une entité inexistante) ; suggestions, correspondances affichées (« entité hors de votre
  périmètre ») et rapport d'écarts limités à ses entités.
- **Code client d'une entité** (`Sage::getClientForEntity()`) : correspondance propre, sinon celle de
  l'ancêtre le plus proche. Modifiable sur l'onglet « Print Gestion — Sage » de l'entité ; chaque changement
  est tracé dans l'historique natif de l'entité. `registration_number` (SIRET) n'est pas utilisé.

### Fichier Gesconso (`inc/gesconso.class.php`)

Référence : le fichier réel `Gesconso_02122024_1034.xlsx`, importé avec succès dans Gesconso. Nom
`Gesconso_JJMMAAAA_HHMM.xlsx`, une feuille `Export`, ligne 1 = en-têtes, **exactement 9 colonnes** :

| Col | En-tête | Source |
|---|---|---|
| A | `Devis` | Date de la demande (commande directe : date du jour), **vraie date Excel** `jj/mm/aaaa` |
| B | `Intitule Client` | Code client Sage de l'entité de l'imprimante (propre ou hérité) |
| C | `Intitule Livraison` | Intitulé de l'adresse Sage du client dont le code est porté par le lieu de l'imprimante ou un parent |
| D | `Consommable` | `CartridgeItem.ref` (vérifiée dans le référentiel articles s'il a été importé) |
| E | `Designation` | n° série + séparateur + nom du lieu + séparateur + nom de la cartouche ; séparateur (`' # '`) et longueur max (69) configurables, troncature avec avertissement |
| F | `Quantite` | Entier |
| G | `Prix` | 0 sous contrat ; vide ou prix saisi hors contrat — **jamais 0 hors contrat** |
| H | `Fournisseur` | Vide |
| I | `Complement livraison` | Commande directe : commentaire du lieu ; demande : contact et commentaire de livraison |

- **Contrôles bloquants** (`Gesconso::prepare()`) : code client absent ou absent du dernier import, adresse de
  livraison absente, référence article absente (cartouche non résolue, référence vide, ou inconnue du
  référentiel articles importé), prix 0 hors contrat. Une ligne en défaut n'est jamais écrite : l'appelant
  refuse l'export entier avec la liste des lignes en défaut.
- Codes et références écrits en texte explicite (zéros de tête conservés) ; cellules vides non écrites.
- **Transmission aux Achats** (`inc/purchaseorder.class.php`, table `purchaseorders`, étape 1.6.7) : commande
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
     remontée mais se répare après coup (GLPI Inventory absent, inventaire désactivé, actions automatiques en mode
     GLPI ou cron arrêté : contrôles obligatoires de `Confighealth::getChecks()` hors TAG/règle/URL) : ligne rouge
     « Rien ne remontera pour l'instant — contactez l'administrateur », sans blocage, causes et liens repliés pour
     l'administrateur ; l'onglet n'est jamais vert quand rien ne remontera. Boutons Windows, Linux et
     macOS (actifs dès que leurs fichiers officiels sont vérifiés et le rattachement complet), commande
     Windows et propriétés MSI expliquées, commande Linux, procédure macOS et son `local.cfg` (phase 6).
- **Paquet Windows** (`front/agentdeploy.download.php`, droit `deploiement` READ et accès à l'entité) : ZIP généré
  à la demande dans `GLPI_TMP_DIR` et supprimé en fin de requête : MSI officiel (stocké, empreinte recalculée
  avant envoi) et deux gestes séparés, en ASCII et CRLF. Geste 1, `1-installer-glpi-agent.bat` : lance seulement
  la commande d'installation (`start /wait`, codes 0, 3010 et 1641 acceptés) après avoir vérifié que le MSI est à
  côté ; ni contrôle administrateur, ni copie, ni tâche. Geste 2, facultatif, présent seulement si « Nouveaux
  paquets Windows : poser la mise à jour automatique » est coché : `2-facultatif-mise-a-jour-automatique.bat`
  (contrôle administrateur, copie de `glpi-agent-update.cmd`, tâche planifiée ; n'installe rien) et
  `glpi-agent-update.cmd` (script de la tâche, voir phase 5). Pourquoi deux fichiers : un .bat ne se signe pas et
  les antivirus ou EDR se méfient d'un script qui enchaîne installation et création de tâche planifiée ; séparés,
  le premier ne lance que le MSI signé, et un blocage du second n'empêche pas l'installation. Plus
  `commande-cmd.txt` (la commande d'installation seule, à coller dans cmd) et `LISEZMOI.txt` (étape 1
  obligatoire ; étape 2 facultative et ce qu'on perd en la sautant). Chaque téléchargement est tracé dans
  l'historique de l'entité. Aucun identifiant, jeton ni secret : URL du serveur et TAG seulement.
- **Commande** : `msiexec /i "<MSI>" SERVER="…" TAG="…" ADDLOCAL="feat_AGENT,feat_NETINV" HTTPD_TRUST="127.0.0.1/32[,…]"
  SNMP_RETRIES="2" RUNNOW="1" EXECMODE="1" QUICKINSTALL="1" /l*v "%TEMP%\GLPI-Agent-install.log"`, sans `/quiet`
  (assistant standard prérempli), jamais lancée par PowerShell. `SERVER` : réglage, sinon `<url_base>/`, l'URL
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
- **Réglages** (`glpi_plugin_printgestion_configs`, étape 1.6.0 ; `agent_server_url` supprimée en 1.6.8, l'adresse est déduite de l'URL de l'application) : `agent_version`,
  `agent_httpd_trust` ; vides : automatiques.
- **Limites vérifiées** : « Demander le statut » et « Demander un inventaire » (natifs) sont des requêtes du
  serveur vers la sonde sur le port 62354, aux adresses du réseau local du poste (`Agent::guessAddresses()`) :
  impossibles depuis un GLPI sur Internet vers une sonde derrière le NAT d'un client, sauf VPN. Sans GLPI
  Inventory, l'agent ne reçoit aucune tâche réseau, et l'URL du serveur à lui donner change à l'installation
  du plugin.

### Déploiement Agent — phase 2 : assistant de raccordement (`inc/raccordement.class.php`, `inc/collectsetup.class.php`, `front/raccordement.php`)

Raccorder les imprimantes d'un client à sa sonde, sur place, et vérifier le résultat avant de partir. Accès : bloc 3
de l'onglet « Déploiement Agent » de l'entité (« Nouveau raccordement ») et page « Raccordements » du module.
Lecture : droit `deploiement` READ ; toute action (POST) : `deploiement` UPDATE ; l'entité du raccordement est
revérifiée à chaque requête (hors périmètre : 404). Lieu, commentaire et contrat des imprimantes : phase 3.

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
  `feat_NETINV`), TAG de l'agent égal à celui de l'entité. « Demander le statut » : `Agent::requestStatus()` natif.
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

### Déploiement Agent — phase 3 : lieu, commentaire et contrat (`inc/raccordementdetail.class.php`)

- **Carte 2 bis** (lecture : droit `deploiement` READ ; saisie : UPDATE, tout statut sauf abandonné) : pour chaque
  adresse, lieu, commentaire et contrat **en attente** (`raccordementips.locations_id`, `comment`, `contracts_id`,
  étape 1.6.2). Valeurs par défaut qui complètent les adresses sans valeur ; jusqu'à 64 adresses ligne à ligne,
  au-delà seulement celles qui ont une imprimante ou des valeurs. Remplacer la liste d'adresses garde les valeurs
  des adresses restantes.
  - Lieu : chemin « Siège > Bâtiment B > Étage 4 > Bureau 3 » ou nom simple, autocomplétion sur les lieux de
    l'entité. Niveaux manquants créés dans l'entité, non récursifs, cherchés par nom + parent + entité (la clé
    unique de `glpi_locations`), comme le formulaire natif ; 10 niveaux et 255 caractères par niveau au plus.
  - Contrat : ceux de l'entité et, récursifs, de ses entités parentes (ni supprimés ni modèles).
  - Enregistrement tout ou rien (transaction, lieux compris). Une adresse déjà appliquée qu'on modifie repasse en
    attente.
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

**Réglages par sonde** (`glpi_plugin_printgestion_agentsettings`, ligne créée au premier enregistrement) : « Mise à
jour automatique » (cochée par défaut) et « Version cible » (vide : dernière connue ; une valeur : épinglage, retour
arrière compris). Onglet « Sonde Print Gestion » de la fiche Agent native (droit Déploiement en lecture, agent
visible) : conformité, réglages, imprimantes collectées, rien de ce que la fiche native affiche déjà. Même contenu
dans la page « Sondes » du module (`front/sondes.php`), pour les profils sans droit Agent. Enregistrement : droit
Déploiement en modification, sonde dans les entités de l'utilisateur.

**Mise à jour réelle, posée sur le PC** : le plugin ne pousse rien (jamais la tâche Deploy de GLPI Inventory, ni
jeton, ni API). Si « Nouveaux paquets Windows : poser la mise à jour automatique » est coché (défaut), le paquet de
l'entité contient l'étape 2 facultative, `2-facultatif-mise-a-jour-automatique.bat`, que le technicien lance
séparément après l'installation, en administrateur : elle copie `glpi-agent-update.cmd` dans
`%ProgramData%\PrintGestion` et pose la tâche planifiée « GLPI Agent - mise a jour (Print Gestion) » : le 1er du mois
à 3 h, compte SYSTEM. GLPI ne sait pas si cette étape a été faite : une sonde qui ne se met pas à jour apparaît
« À mettre à jour » dans la page « Sondes » dès qu'une version plus récente est visée. Le script
ne fait rien si l'agent n'est pas en attente (`http://127.0.0.1:62354/status`), cherche `winget.exe` dans
`%ProgramFiles%\WindowsApps\Microsoft.DesktopAppInstaller_*` (absent du PATH de SYSTEM) et lance
`winget upgrade --id GLPI-Project.GLPI-Agent` ou, version épinglée, `winget install --version X --force`, toujours avec
`--custom "ADDLOCAL=feat_AGENT,feat_NETINV"` pour garder l'inventaire réseau ; journal
`%ProgramData%\PrintGestion\glpi-agent-update.log`. Un changement de réglage n'est appliqué qu'en lançant sur le PC le
**paquet de consigne** de la sonde (`front/sonde.consigne.php`, droit Déploiement en lecture), qui pose, change ou
retire la tâche. Décocher la case dans GLPI ne retire pas une tâche déjà posée : sans la tâche Deploy ni jeton, tous
deux exclus, GLPI n'a aucun moyen de changer cette tâche au contact de l'agent. Limites : winget sous SYSTEM n'est pas
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
donc `server`, `tag`, `tasks = inventory,netdiscovery,netinventory`, `httpd-trust` et `snmp-retries = 2`. Procédure
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
- À chaque install/« Mettre à jour » du plugin, `plugin_printgestion_create_templates()` crée
  ou **réécrit** (sujet + contenu fr_FR) les gabarits dans `glpi_notificationtemplates` /
  `glpi_notificationtemplatetranslations` (marqués `comment = 'Created by plugin printgestion'`).
  Les IDs sont stockés dans la config (`gabarit_planif`, `gabarit_planif_group`, `gabarit_achat`,
  `gabarit_commercial`, `gabarit_rappel`, `gabarit_courtoisie`).
- L'envoi (`Config::sendMail($emails, $gabarit_id, $balises, $attachment)`) charge la traduction
  (langue session → 2 lettres → fr_FR → première dispo), substitue les balises, envoie via
  `GLPIMailer` (Symfony Mailer GLPI 11), pièce jointe optionnelle.
- **Aperçu** : `apercu_gabarits.html` à la racine, régénérable par `php tools/generate_apercu.php`
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
| `PrintgestionTrackingUpdate` | 4 h | BL signés plugin Gestion → delivered + APIs transporteurs (UPS/GLS/Chronopost) |
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
| `plugin_printgestion_sage` | Référentiel Sage : READ onglet Sage de l'entité, UPDATE import et correspondances |
| `plugin_printgestion_billing` | Coût à la page : écrans et onglet de la fiche imprimante (prix et coûts ; jamais le seul droit sur l'imprimante) |
| `plugin_printgestion_config` | Configuration du plugin + mappings SNMP ; onglet « Print Gestion » des cartouches (liaisons SNMP) : READ voir, UPDATE enregistrer, toujours avec le droit natif sur la cartouche |

Migration 1.5.9 : `sage` repris de `config` (lecture → lecture, modification → lecture et modification),
`deploiement` donné en lecture et modification aux profils qui modifiaient la configuration ; le module
`sage` reprend l'état du module `toner`.

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
