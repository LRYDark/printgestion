# Audit du plugin GLPI « Print Gestion » (printgestion) — peut-il remplacer FM Audit ?

> Audit en lecture seule du code source. Aucun fichier modifié, aucun code exécuté, aucune requête sur la base de production.
> Date : 11/09/2026 — GLPI installé : 11.0.8 — Plugin : `plugins/printgestion`, version déclarée 1.0.0.
> Les références `fichier:ligne` sont relatives à `plugins/printgestion/` sauf mention contraire. Elles permettent à un développeur de vérifier ; le texte est rédigé pour être compris sans accès au code.

## Sommaire

- [Contexte et méthode](#contexte-et-méthode)
- [Étape 0 — Résumé simple](#étape-0--résumé-simple)
- [Étape A — Cartographie technique](#étape-a--cartographie-technique)
- [Étape B — Matrice de couverture des 10 objectifs](#étape-b--matrice-de-couverture-des-10-objectifs)
- [Étape C — Comparaison avec FM Audit](#étape-c--comparaison-avec-fm-audit)
- [Étape D — Export Excel et mapping Sage](#étape-d--export-excel-et-mapping-sage)
- [Étape E — Audit technique et sécurité](#étape-e--audit-technique-et-sécurité)
- [Étape F — Verdict « GLPI-native first »](#étape-f--verdict--glpi-native-first-)
- [Étape G — Plan d'action priorisé, risques, questions](#étape-g--plan-daction-priorisé-risques-questions)

---

## Contexte et méthode

**Métier.** Prestataire IT, ~2 000 imprimantes chez des clients externes (mairies, écoles, collectivités, entreprises), contrats d'impression facturés au clic, consommables fournis. Outil actuel : FM Audit (ECi), facturé par imprimante, en fin de vie (remplacé par Printanista Hub).

**Processus actuel.** Alerte consommable FM Audit → une collaboratrice ressaisit à la main dans un Excel (code client Sage, référence consommable, lieu de livraison, commentaire) → envoi aux Achats → import dans Sage 100 → expédition depuis le stock, ou commande fournisseur, ou livraison par un technicien. Douleur n° 1 : la ressaisie et les **doubles envois**.

**Contraintes figées.**
- Les Achats n'ont pas et n'auront pas accès à GLPI : leur seule entrée est le fichier Excel importé dans Sage.
- Le format de ce fichier est imposé.
- Sage 100 Gestion commerciale Premium 12.20 sur SQL Server : aucune écriture dans Sage en dehors de l'import de fichier.

**Méthode.**
- Lecture intégrale des ~16 600 lignes du plugin : 21 classes, 11 pages, 24 fichiers `ajax/`, JS, documentation.
- Vérification de points du cœur GLPI 11.0.8 dans `src/` : protection CSRF, mailer, schémas des tables natives, API de cartouches et de notifications.
- Chaque constat est étiqueté **confirmé par lecture** (code lu), **estimation** (hypothèse chiffrée) ou **non vérifié**.

**Non vérifié** :
- le contenu réel de la base : nombre d'imprimantes inventoriées, propriétés SNMP réellement remontées, volumes ;
- la version de PHP du serveur ;
- le déploiement de l'agent GLPI chez les clients ;
- l'outil d'import Sage utilisé par les Achats ;
- le schéma SQL réel de l'instance Sage.

---

## Étape 0 — Résumé simple

*Rédigé pour la personne qui gère les commandes.*

### Ce qu'il sait faire

- **Suivre les toners.** Chaque jour, il note le niveau de chaque cartouche remonté par l'inventaire GLPI et estime en combien de jours elle sera vide, en se basant sur les pages imprimées. Il classe chaque cartouche en « bon », « à surveiller » ou « critique ».
- **Un écran d'alertes.** Une ligne par imprimante avec ses toners, niveaux, jours restants et expédition en cours. On peut mettre une alerte en pause quelques jours.
- **Commander en quelques clics.** On coche une ou plusieurs imprimantes, même de clients différents, on décoche ce qu'on ne veut pas, on valide. Le plugin crée le fichier Excel et l'envoie **immédiatement par mail aux Achats**. Il peut aussi prévenir la logistique et le client.
- **Suivre l'expédition** (en attente, expédiée, en transit, livrée). Le transporteur et le numéro de suivi se saisissent à la main. La livraison est validée par un bon de livraison signé (plugin Gestion) ou quand le niveau du toner remonte.
- **Signaler une cartouche posée sur la mauvaise imprimante** d'un même client.
- **Relancer par mail** quand une cartouche expédiée n'est pas installée après X jours.
- **À côté du toner** : tableau de bord des contrats (échus, en préavis) et calcul du coût à la page.

### Ce qu'il ne sait pas faire alors qu'on en a besoin

- **Il ne bloque pas vraiment les doubles envois.**
  - Une cartouche déjà en route est **remise dans l'Excel** si on coche l'imprimante.
  - Dès qu'une expédition passe « livrée » (bon de livraison signé), le toner encore bas peut être recommandé tant que le client n'a pas posé la cartouche. C'est exactement le défaut de FM Audit.
- **Pas de file de validation.** Aucune demande ne se prépare toute seule : cliquer « Envoyer », c'est commander tout de suite. On ne peut modifier ni la quantité, ni le lieu, ni le commentaire.
- **L'Excel n'est pas au format Sage** :
  - le code client est le **nom** du client dans GLPI, pas le code Sage ;
  - le prix est **toujours 0** ;
  - il y a une colonne en trop ;
  - le nom du fichier est différent.
- **Aucun lien avec Sage** pour les codes clients, les références articles ou le stock. Le stock affiché est celui de GLPI, et le plugin ne le décompte pas quand une cartouche part.
- **Imprimantes « OK / bas » sans pourcentage** : ignorées.
- **Alertes peu réglables** :
  - deux seuils communs à tout le parc (modifiables par imprimante) ;
  - « critique à 7 jours » figé dans le code ;
  - pas de règles par client, modèle, couleur ou contrat ;
  - aucune notion sous contrat / hors contrat.
- **Pas de collecte propre.** Un client sans agent GLPI est invisible, et rien ne signale une imprimante qui ne répond plus.
- **Manquent aussi** : suivi transporteur automatique (le code est vide), relance d'une demande qui traîne, alerte de fin de contrat, distinction envoi direct / livraison par technicien.

### En une phrase

**Non, pas en l'état.** La base de suivi est réelle, mais il manque la validation, l'Excel n'est pas conforme à Sage, et la protection anti-double-envoi est contournable dans le parcours de commande principal.

---

## Étape A — Cartographie technique

### A.1 Arborescence

| Élément | Fichiers | Lignes | Rôle réel |
|---|---|---|---|
| `setup.php` | 1 | 143 | Démarrage du plugin : hooks, menu, onglets. Bornes GLPI 11.0.0–11.1.0, version figée 1.0.0 (`setup.php:7-11`). Restrictions de liste `addDefaultWhere` |
| `hook.php` | 1 | 272 | Installation et désinstallation. **Contenu des 6 modèles de mail écrit dans le code** |
| `inc/` | 21 classes | 11 477 | Logique métier. Pas de namespace, conventions historiques `PluginPrintgestionXxx` |
| `front/` | 11 | 1 696 | Pages et traitement des formulaires |
| `ajax/` | 24 | 1 844 | Appels en arrière-plan. `update_expedition.php` est en réalité un traitement de formulaire classique |
| `public/js`, `public/css` | 2 | 488 | JS contrats et graphiques, CSS. **~1 600 lignes de JS sont écrites directement dans le PHP** (`inc/dashboardactions.class.php:830-2027`, `front/dashboard_alerts.php:159-526`) |
| `locales/fr_FR.po` | 1 | 10 | Vide : les textes sont en français dans le code |
| `docs/` | 6 | — | Documentation technique et maintenance (.md/.docx), scénarios, aperçu des mails |
| `tools/generate_apercu.php` | 1 | 199 | Génère l'aperçu des mails en ligne de commande |

**Absents** : gabarits Twig (un seul appel `TemplateRenderer`, `inc/dashboard.class.php:317`), `composer.json`, tests automatisés, **dépôt git**.

### A.2 Itemtypes (classes)

| Classe | Hérite de | Droit | Table | Rôle |
|---|---|---|---|---|
| Config | CommonDBTM | plugin_printgestion_config | `_configs` (ligne unique) | Configuration, **création de toutes les tables**, envoi de mail |
| Profile | Profile | profile | droits natifs | Matrice des droits |
| Menu | CommonGLPI | plugin_printgestion_contrats | — | Menu, accueil, onglets |
| Alert | CommonDBTM | plugin_printgestion_dashboard | `_alerts` | Calcul des alertes à la volée, mail récapitulatif, pause (snooze) |
| Alertview | CommonDBTM | plugin_printgestion_dashboard | `_alertview` | Copie calculée des alertes. Options de recherche et actions de masse déclarées mais **jamais affichées** |
| Tonerreading | CommonDBTM | plugin_printgestion_dashboard | `_toner_readings` | Relevés quotidiens |
| Cartridgehistory | CommonDBTM | plugin_printgestion_dashboard | `_cartridge_history` | Détection des changements de cartouche, **écriture SQL directe dans `glpi_cartridges`** |
| Expedition | CommonDBTM | plugin_printgestion_expedition | `_expeditions` | Cycle d'expédition, tous les mails, Excel |
| Snmpmapping | CommonDBTM | plugin_printgestion_config | `_snmp_mapping` | Propriété SNMP → type de cartouche → cartouche |
| Cartridgesnmp | CommonDBTM | cartridge (natif) | `_cartridge_snmp` | Onglet fiche cartouche : liaison directe à une propriété SNMP |
| Contract | Contract | plugin_printgestion_contrats | `glpi_contracts` | Type virtuel limité aux contrats liés à une imprimante |
| Contractrate | CommonDBTM | contract (natif) | `_contractrates` | Tarifs €/page |
| Billing | CommonDBTM | plugin_printgestion_billing | `_billing` | Calcul du coût à la page ; **table jamais écrite** |
| Billingview | CommonDBTM | plugin_printgestion_billing | `_billing_view` | Copie calculée du coût, par utilisateur |
| PrinterCostsTab | CommonGLPI | printer (natif) | — | Onglet fiche imprimante : coût **et seuils d'alerte de l'imprimante** |
| Print | CommonGLPI | plugin_printgestion_contrats | — | « Créer Print » : contrat + imprimante via l'API native, en transaction |
| Dashboard | CommonGLPI | plugin_printgestion_contrats | — | Tuiles, graphiques et liste native des contrats |
| Dashboardactions | CommonGLPI | plugin_printgestion_dashboard | — | Menu clic droit, fenêtres modales, moteur de tableau JS |
| Tracking | CommonDBTM | plugin_printgestion_expedition | aucune | Bon signé → « livrée » ; transporteurs vides |
| Reminder | CommonDBTM | plugin_printgestion_dashboard | aucune | 3 tâches automatiques |
| Ui | — | — | — | Barre de statistiques |

### A.3 Tables SQL du plugin

Préfixe `glpi_plugin_printgestion`. Aucune clé étrangère : les liens sont logiques, comme dans GLPI.

| Table | Contenu principal | Index | Écrite par | Purge |
|---|---|---|---|---|
| `_configs` (`inc/config.class.php:86-127`) | 37 colonnes, détail sous le tableau | PK | `front/config.form.php:42-80` | — |
| `_toner_readings` (`config:176-186`, `457-519`) | imprimante, propriété, niveau %, date du relevé (ramenée à minuit), compteurs total / N&B / couleur | `uniq_daily` et `idx_lookup` **sur les mêmes colonnes (doublon)** | `inc/tonerreading.class.php:68-236` | 160 jours |
| `_cartridge_history` (`config:188-205`) | pose et retrait : niveaux, dates, compteurs, pages. `cartridgeitems_id` et `cartridgetypes_id` **jamais renseignés** | printers_id | `inc/cartridgehistory.class.php:142-176`, `455` | **aucune** |
| `_expeditions` (`config:207-230`) | imprimante, propriété toner, couleur, statut (pending/shipped/transit/delivered/stock_empty), transporteur, n° de suivi, BL, demandeur, planificateur, niveau, jours, 3 dates, notes, identifiant de groupe | printers_id, bl_surveys_id, group_id. **Ni index sur (imprimante, toner, statut) ni contrainte d'unicité** | `inc/expedition.class.php:444`, `1495`, `1558`, `1728` ; `inc/tracking.class.php:95` ; ajax | **aucune** |
| `_snmp_mapping` (`config:232-243`) | propriété SNMP (unique), type de cartouche, couleur ; 2 colonnes obsolètes | unique | `front/config.form.php:90-208` ; 23 lignes par défaut | — |
| `_alerts` (`config:245-263`) | journal : type (low_toner, no_install_reminder, wrong_printer ; `stock_empty` et `contract_expiry` **jamais écrits**), mail envoyé, résolue, imprimantes prévue/détectée, expédition | printers_id, is_resolved, alert_type | `inc/alert.class.php:817-831`, `inc/cartridgehistory.class.php:282` | **aucune** |
| `_alert_snoozes` (`config:267-278`) | imprimante, propriété, date de fin de pause, utilisateur | **pas d'unicité** | `inc/alert.class.php:1190-1224` | **aucune** |
| `_cartridge_snmp` (`config:283-291`) | cartouche ↔ propriété SNMP | unique paire | `front/cartridgesnmp.form.php:45-51` | — |
| `_contractrates` (`config:164-174`) | contrat, type (N&B / couleur / les deux), tarif, actif | contracts_id | `front/contractrate.form.php` | — |
| `_billing` (`config:293-310`) | coût par période | — | **jamais** | — |
| `_historical_yields` (`config:315-327`) | rendement pages/% par cycle | lookup | `inc/cartridgehistory.class.php:157` | **aucune** |
| `_expedition_bls` (`config:332-342`) | expédition ↔ BL (plugin Gestion) | unique paire | `ajax/link_bls.php:151-176` | — |
| `_printer_thresholds` (`config:345-355`) | seuils % et jours, rendement par imprimante | unique printers_id | `ajax/printer_thresholds.php` | — |
| `_alertview` (`config:364-383` + doublon `inc/alertview.class.php:55`) | 1 ligne par imprimante × toner | entité, imprimante, statut | `inc/alertview.class.php:91-117` : vidage complet (TRUNCATE) puis insertions une par une | recréée chaque heure |
| `_billing_view` (`config:385-410`) | coût par utilisateur et par vue | utilisateur, vue, entité, imprimante | `inc/billingview.class.php:108-177` | remplacée à chaque affichage |

Détail des 37 colonnes de `_configs` :
- 3 rôles de destinataires (groupe, mode, utilisateurs) ;
- les seuils ;
- 6 identifiants de modèles de mail ;
- **3 clés API transporteurs stockées en clair** ;
- `tracking_frequency` et `group_tech`, **toutes deux inutilisées** ;
- 3 filtres de facturation et 3 interrupteurs de module.

**Ce que la table des expéditions n'a pas** : entité, référence cartouche, quantité, prix, lieu de livraison, contact, lien vers l'alerte ou le contrat, statut « à valider » ou « commandé ».

### A.4 Tables natives utilisées

| Table | Lue | Écrite | Comment |
|---|---|---|---|
| `glpi_printers_cartridgeinfos` | oui | non | Source de tous les niveaux (propriété et valeur en texte libre) |
| `glpi_printerlogs` | oui | non | Compteurs journaliers |
| `glpi_printers`, `glpi_entities`, `glpi_locations` | oui | Printer via `add()` | Nom, n° de série, modèle, entité, lieu, usager, compteur |
| `glpi_cartridgeitems` (+ modèles compatibles, types) | oui | non | Référence `ref` |
| `glpi_cartridges` | oui | **SQL direct** | Création et clôture de cartouches sans l'API native, donc sans historique (`inc/cartridgehistory.class.php:426`, `531-551`) |
| `glpi_contracts`, `glpi_contracts_items` | oui | via `add()` | `inc/print.class.php:321-357` |
| `glpi_notificationtemplates` (+ traductions) | oui | **SQL direct** | Créés et **réécrits** à chaque installation ou mise à jour (`hook.php:230-264`) |
| `glpi_groups_users`, `glpi_useremails`, `glpi_profiles_users` | oui | non | Destinataires des mails |
| `glpi_crontasks` | non | oui | Fréquence forcée de la tâche de relevés |
| `glpi_plugin_gestion_surveys` (autre plugin) | oui | **SQL direct** | Insertion de BL (`ajax/link_bls.php:263-275`) |

### A.5 Installation et migrations

- **Installation** : `hook.php:7-34` parcourt `inc/*.class.php` par ordre alphabétique et appelle `install()` sur chaque classe, puis initialise les droits (tous les droits au profil qui installe) et crée les modèles de mail.
- **Tables** : `CREATE TABLE IF NOT EXISTS` dans `inc/config.class.php:78-606`.
- **Migrations** :
  - ajouts de colonnes « si absente » et `ALTER` bruts dont les erreurs sont ignorées sans trace (`config:133-158`, `434-455`, `457-519`, `534-603`) ;
  - **aucun numéro de version de schéma** (la version reste 1.0.0) ;
  - la documentation et `setup.php:27-29` indiquent « évolution du schéma = désinstaller / réinstaller », ce qui détruit les données ;
  - en cas d'erreur SQL, l'installation s'arrête net (`or die`, `config:128`, `415`).
- **Désinstallation** : suppression de toutes les tables, des droits, des modèles de mail et des tâches (`config:608-640`, `hook.php:36-74`, `inc/reminder.class.php:131-139`).

### A.6 Droits de profil (`inc/profile.class.php:23-55`)

| Droit | Niveaux | Protège réellement |
|---|---|---|
| `plugin_printgestion_contrats` | READ, UPDATE | Tableau de bord et liste des contrats (READ) ; « Créer Print » (UPDATE). Les tarifs dépendent en fait du droit natif `contract` |
| `plugin_printgestion_dashboard` | READ, UPDATE | Écran des alertes. **UPDATE suffit pour commander** (`ajax/send_purchase.php:25-26`) |
| `plugin_printgestion_expedition` | READ, UPDATE, CREATE | Expéditions. **CREATE n'est vérifié nulle part** |
| `plugin_printgestion_billing` | READ, CREATE | Coût à la page ; CREATE = export |
| `plugin_printgestion_config` | READ, UPDATE | Configuration et correspondances SNMP. La liaison cartouche ↔ SNMP dépend du droit natif `cartridge` |

Ces droits ne permettent de séparer ni « valider » de « commander », ni « mettre en pause » de « commander ».

### A.7 Points d'entrée côté interface

**Menu** : Gestion → Print Gestion. Un onglet n'apparaît que si son module est activé **et** que l'utilisateur a le droit.

| Page | Droit | Module | Contenu |
|---|---|---|---|
| `front/index.php` | au moins un onglet | — | Accueil : compteurs |
| `front/dashboard.php` | contrats READ | contrats | Tuiles et graphiques |
| `front/list.php` | contrats READ | contrats | Liste native des contrats |
| `front/print.form.php` | contrats UPDATE | contrats | Création ou liaison contrat ↔ imprimante |
| `front/dashboard_alerts.php` | dashboard READ | toner | **Tableau maison en JS** + fenêtre de commande |
| `front/dashboard_expeditions.php` | expedition READ | toner | Alertes prioritaires + liste native des expéditions |
| `front/dashboard_billing.php` | billing READ | coût | Liste native du coût + export |
| `front/expedition.form.php` | expedition UPDATE | — | « Marquer expédiée » |
| `front/config.form.php`, `cartridgesnmp.form.php`, `contractrate.form.php` | config UPDATE / cartridge UPDATE / contract UPDATE | — | Enregistrements |

**Onglets ajoutés sur des fiches natives** (`setup.php:42-54`) :
- Profil ; Configuration ;
- Contrat : tarifs (module contrats) ;
- Imprimante : coût et **seuils d'alerte de l'imprimante** (module **coût** : désactiver ce module fait disparaître le réglage des seuils) ;
- Cartouche : liaison SNMP (module toner).

**Cartes de tableau de bord natives** : aucune. **Actions de masse** : 2 déclarées, jamais affichées.

**Appels en arrière-plan (`ajax/`)** :

| Fichier | Méthode | Droit vérifié | Effet | Utilisé |
|---|---|---|---|---|
| send_purchase.php | POST AJAX | dashboard **ou** expedition UPDATE | Commande : expéditions + Excel + mails | **parcours principal** |
| send_group.php / send_cartridge.php | POST | idem | Envoi groupé / unitaire | code de secours jamais atteint |
| update_expedition.php | POST formulaire | expedition UPDATE | « expédiée » + mail, ou **suppression définitive** | oui |
| edit_expedition.php | POST AJAX | expedition UPDATE | Change statut / transporteur / suivi sans contrôle ni mail | oui |
| reassign_expedition.php | POST formulaire | expedition UPDATE | Réaffectation | oui |
| link_bls.php | POST AJAX | expedition UPDATE | Liaison BL + création de BL chez Gestion + API Sage | oui |
| snooze_alert / snooze_group / unsnooze_group | POST AJAX | dashboard ou expedition UPDATE | Pauses | oui |
| resolve_alert.php | POST formulaire | idem | Résolution « mauvaise imprimante » | oui |
| printer_thresholds.php | **GET** | modification de l'imprimante | **Écrit** les seuils | oui |
| save_table_prefs.php | **GET** | **connexion seule** | **Écrit** des préférences | oui |
| refresh_cache.php | GET | un READ du plugin | **Recalcul complet** des alertes | oui |
| list_alerts.php | GET | dashboard READ | Données du tableau d'alertes | oui |
| cartridge_stock.php | GET | **connexion seule** | Stock et nom de cartouche | oui |
| search_bls.php | GET | **connexion seule** | Recherche de BL + API Sage | oui |
| expedition_bls.php | GET | expedition ou dashboard READ | BL d'une expédition | oui |
| printer_costs.php | GET | lecture de l'imprimante | Coût | oui |
| export_excel.php | GET | billing CREATE | Excel du coût à la page | oui |
| list_expeditions / list_billing | GET | READ | — | **orphelins** |
| list_bls / bl_expeditions | GET | **connexion seule** | — | **orphelins** |

### A.8 Tâches automatiques (`inc/reminder.class.php`)

| Tâche | Fréquence | Contenu |
|---|---|---|
| PrintgestionSnapshotReadings | 1 jour | Relevés ; création de cartouches natives « en place » ; détection des changements → expédition « livrée » ; purge 160 j |
| PrintgestionCheckAlerts | 1 heure | Calcul complet des alertes + mail commercial récapitulatif ; rappels de pose ; réaffectations automatiques ; **second calcul complet** pour recréer `_alertview` |
| PrintgestionTrackingUpdate | 4 heures, en dur (le réglage de fréquence est ignoré) | BL signé → « livrée » ; transporteurs : **fonctions vides** (`inc/tracking.class.php:185-206`) |

### A.9 Notifications

**Mécanisme** :
- 6 modèles de mail rattachés artificiellement à l'itemtype Ticket (`hook.php:250`) ;
- **aucun événement de notification GLPI, aucune file d'attente** ;
- envoi immédiat, pendant le clic, via GLPIMailer (`inc/config.class.php:1321-1440`).

| Déclencheur | Destinataires | Pièce jointe | Code |
|---|---|---|---|
| Tâche horaire, toner bas | Commercial (récapitulatif) | — | `inc/alert.class.php:840-957` |
| Tâche horaire, cartouche expédiée non posée | Planif et/ou commercial | — | `inc/expedition.class.php:1567-1690` |
| Commande | **Achats** + demandeur en copie | Excel | `inc/expedition.class.php:1003-1052` |
| Commande, case Planif cochée | Planif + demandeur | Excel si plusieurs lignes | `inc/expedition.class.php:1129-1239` |
| Commande, case Courtoisie (**cochée par défaut**) | Usager de l'imprimante, **sinon tous les utilisateurs ayant un profil sur l'entité** | — | `inc/expedition.class.php:1092-1120`, `1248-1311` |
| « Marquer expédiée » | Commercial | — | `inc/expedition.class.php:1478-1544` |

**Pas d'alerte de fin de contrat.**

### A.10 SNMP, inventaire, fichiers

- **Collecte** : le plugin n'interroge aucune imprimante. Il lit ce que l'inventaire GLPI (agent GLPI) a déposé.
- **Valeurs non numériques** : « OK » et « WARNING » sont écartés (`inc/tonerreading.class.php:34-58`).
- **Propriété SNMP → cartouche** : liaison directe filtrée par modèle, puis liaison **sans filtre de modèle**, puis type de cartouche + modèle, puis **type seul** (`inc/snmpmapping.class.php:125-255`).
- **Couleur** : déduite du nom de la propriété (`inc/snmpmapping.class.php:37-52`).
- **Estimation des jours restants** : rendement mesuré sur le cycle, puis rendement du cycle précédent, puis rendement par défaut ; pages par jour sur 7 jours (`inc/alert.class.php:142-279`).
- **Excel de commande** : bibliothèque PhpSpreadsheet fournie par GLPI. Le fichier temporaire est **envoyé par mail puis supprimé** : ni téléchargement, ni archive.
- **Import de fichier** : aucun.
- **Sage** : aucun accès SQL. Seul lien : l'API HTTP de documents du **plugin Gestion**, pour les BL.

### A.11 Code mort ou orphelin (~700 à 900 lignes)

- `computeRemaining`, `computeRemainingFromCache`, `computeFallbackFromSnmp` (`inc/alert.class.php`) ;
- `isColorPrinter`, `getHistoryForPrinter`, `httpGet`, `rowDataAttributesFor*` ;
- recherche et actions de masse d'Alertview ;
- 4 fichiers `ajax/` orphelins ; `send_cartridge` et `send_group` jamais atteints ;
- table `_billing` et colonnes inutilisées.

---

## Étape B — Matrice de couverture des 10 objectifs

Règle appliquée : une fonction qui n'existe qu'en squelette, en commentaire « à faire » ou en table vide est notée ABSENT.

| # | Objectif | État | Où dans le code | Ce qui manque |
|---|---|---|---|---|
| 1 | Collecte compteurs et niveaux sans FM Audit | **PARTIEL** | `inc/tonerreading.class.php:68-236` ; `inc/reminder.class.php:38-55` ; source native `glpi_printers_cartridgeinfos` / `glpi_printerlogs` | Aucune collecte propre : tout repose sur l'agent GLPI, dont le déploiement client par client n'est pas vérifié. Valeurs OK/WARNING ignorées (`tonerreading:50-55`). Pas d'état d'erreur (toner bas, plus de toner). **Aucune détection d'imprimante muette** (la colonne native `last_inventory_update` n'est pas exploitée). Relevé écarté si le niveau n'a pas bougé (`tonerreading:180-182`), ce qui fige les compteurs utilisés par l'estimation. Pas de cycle de facturation ni de taux de couverture |
| 2 | Alertes paramétrables (critères combinables, filtres, date d'épuisement) | **PARTIEL** | Seuils globaux `inc/config.class.php:697-751` ; seuils par imprimante `inc/printercoststab.class.php:139-194` ; calcul `inc/alert.class.php:142-356` | Date d'épuisement estimée et % présents, mais **2 seuils seulement**. « Critique ≤ 7 jours » en dur (`alert:346`). Pas d'objet « définition d'alerte ». Pas de sélecteur de type de consommable, pas de combinaison TOUS / AU MOINS UN, pas de critère volume, état d'erreur ou niveau normalisé. Pas de filtre client / modèle / contrat. Réglage par imprimante masqué si le module coût est désactivé (`setup.php:49-51`) |
| 3 | File de demandes à valider, pré-remplies, validation en un clic | **ABSENT** | — (le plus proche : fenêtre de commande `front/dashboard_alerts.php:442-521`) | Aucun objet « demande », aucune table, aucun statut « à valider ». Rien n'est créé automatiquement par une alerte. La fenêtre sert à commander directement : pas de quantité, lieu, contact ou commentaire modifiables, pas de validation distincte |
| 4 | Regroupement automatique des alertes d'un même client | **ABSENT** | — (sélection manuelle multi-imprimantes : `ajax/send_purchase.php`, `inc/expedition.class.php:901-962`) | Rien d'automatique. Une sélection manuelle produit bien un seul Excel et un seul mail Achats, mais sans regroupement par client ni par site |
| 5 | Anti-double-envoi, fermeture sur expédition, période de garde | **PARTIEL, contournable** | `inc/expedition.class.php:397-412`, `432-435`, `501-504`, `925-927` | **(1)** Le parcours de commande liste quand même dans l'Excel une cartouche déjà en cours (`expedition:923-939`) ; la fenêtre ne filtre pas ces cartouches (`dashboard_alerts.php:417-438`). **(2)** Le passage à « livrée » (BL signé `inc/tracking.class.php:94-99`, édition manuelle `ajax/edit_expedition.php`) libère le blocage **avant la pose** → recommande possible. **(3)** Fermeture sur hausse du niveau (`inc/cartridgehistory.class.php:192`), comme FM Audit. **(4)** Aucune période de garde. **(5)** Ni unicité en base ni verrou : deux commandes simultanées passent |
| 6 | Export Excel au format Achats en un clic depuis les demandes validées | **PARTIEL** | `inc/expedition.class.php:777-890`, `1003-1052` | Généré seulement comme pièce jointe au moment de la commande. Non conforme : nom de fichier, colonne en trop, date en texte, code client, prix, contrôles (détail en D). Ni téléchargement, ni archive. **Si le mail Achats échoue, la commande est silencieusement perdue** (voir E) |
| 7 | Notifications et relances paramétrables | **PARTIEL** | Rôles et modèles `inc/config.class.php:801-948` ; circuits A.9 | Existent : Achats, planif, commercial, courtoisie, rappel de pose (délai réglable). **Absents** : mail « demande en attente de validation », relance d'une demande qui traîne, escalade, **alerte fin de contrat** (valeur déclarée, jamais utilisée). Contenu des modèles écrasé à chaque mise à jour (`hook.php:236-243`). Envoi synchrone hors moteur GLPI (pas de file, pas de nouvelle tentative) |
| 8 | Cycle de vie en attente → validé → commandé → expédié → transit → livré, envoi direct / technicien | **PARTIEL** | Statuts `inc/config.class.php:213` ; transitions `inc/expedition.class.php:1478-1562`, `ajax/edit_expedition.php` | Statuts « validé » et « commandé » absents. Pas de distinction envoi direct / technicien. « En transit » jamais automatique (transporteurs vides). Aucune règle de transition (on passe de n'importe quel statut à n'importe quel autre). Aucun historique. Annulation = suppression |
| 9 | Import massif du référentiel Sage 100 | **ABSENT** | — | Aucun import de fichier, aucune connexion SQL Server, aucune table de correspondance client / article / adresse |
| 10 | Tout paramétrable depuis l'interface | **PARTIEL** | Page de configuration `inc/config.class.php:646-1095` | Réglables : seuils, délais, rôles, modèles, correspondances SNMP, modules, seuils par imprimante. **En dur** : liste sous le tableau |

Valeurs figées dans le code (objectif 10) :
- critique ≤ 7 j (`alert:346`) ;
- point d'installation +20 %, alors que le delta de détection est réglable (`alert:165` contre `cartridgehistory:42`) ;
- fenêtre de 7 j pour les pages par jour (`alert:229`) ;
- rendement CMY ×3 (`alert:218-221`) ;
- anti-doublon de mail 24 h (`alert:866`, `expedition:1596`) ;
- purge 160 j (`reminder:52`) ;
- 20 lignes maximum par mail (`expedition:28`) ;
- fréquence transporteurs 4 h (`reminder:125`) ;
- quantité 1, prix 0, fournisseur vide (`expedition:831-833`) ;
- statuts et transporteurs figés dans la structure de table (`config:213-215`) ;
- durées de pause 1 à 30 j (`dashboardactions:403-409`) ;
- contenu des mails.

**Bilan : 0 IMPLÉMENTÉ, 7 PARTIEL, 3 ABSENT.**

---

## Étape C — Comparaison avec FM Audit

| Capacité FM Audit | État dans le plugin | Écart |
|---|---|---|
| Sélecteur de type de consommable (toner, encre, fuser, transfer, autre ; noir / couleurs / C, M, Y) | Aucun. Couleur devinée par mots-clés dans le nom SNMP ; les kits (« other ») sont traités comme des toners | **Absent** |
| Niveau % ≤ ou ≥ | ≤ uniquement, seuil global ou par imprimante | **Plus faible** |
| Date d'épuisement estimée ≤ X jours / semaines | Estimation par rendement mesuré sur le cycle de la cartouche × pages/jour. Seuil « à surveiller » réglable, « critique » figé à 7 j. Jours seulement | **Équivalent en calcul** (potentiellement meilleur grâce au rendement mesuré, précision non mesurée), **plus faible en paramétrage** |
| Volume ≥ X % du rendement nominal ou X unités | Rendement stocké, pas de critère | **Absent** |
| État d'erreur (toner bas / plus de toner) | Non collecté, non exploité | **Absent** |
| Niveau normalisé (imprimantes sans %) | OK/WARNING ignorés | **Absent** |
| Combinaison TOUS / AU MOINS UN | Logique figée « niveau OU jours » | **Absent** |
| Filtres sous contrat / hors contrat, attributs ET / OU / ET NON | Aucun ; filtre d'entité à l'affichage seulement | **Absent** |
| Contenu d'alerte (description, résolution, base de connaissances) | Aucun | **Absent** |
| Action « Nécessite approbation » | Aucune : le clic est la commande | **Absent** |
| Email interne | Récapitulatif commercial horaire ; mail planif à la commande ; destinataires par rôle global | **Équivalent** (pas par règle) |
| Email de courtoisie client | Oui, regroupé par contact, **coché par défaut**, repli sur tous les utilisateurs de l'entité | **Équivalent, avec risque d'envoi à de mauvais destinataires** |
| Devis consommable (hors contrat, facturé) | Prix toujours 0 | **Absent** |
| Commande directe (sous contrat) | Toute commande part en « directe » vers les Achats | **Plus faible** (aucune distinction) |
| Synchronisation XML / commande ERP | Excel par mail, non conforme | **Plus faible** |
| Regroupement de plusieurs alertes par client / fabricant | Sélection manuelle de plusieurs imprimantes → 1 Excel | **Plus faible** |
| Journalisation seule | Tableau d'alertes + journal des mails ; pas d'action « journal seul » par règle | **Plus faible** |
| Action en cascade si retard | Rappel unique « expédiée non posée après X j » ; pas d'escalade ni de retard de validation | **Plus faible** |
| Appareil sous / hors contrat | Contrat utilisé seulement pour son nom et le coût à la page ; le contrat retenu est le plus récent, sans vérifier qu'il est en cours (`inc/contractrate.class.php:168-194`) | **Absent** |
| Devis contre commande | — | **Absent** |
| Collecte : série, modèle, IP, statut, niveaux, couverture, compteurs, erreurs, compte / site | Déléguée à l'inventaire GLPI : série, modèle, IP, niveaux et compteurs natifs. Couverture et erreurs absentes. Compte = entité GLPI | **Plus faible** (dépend du déploiement de l'agent, non vérifié) |
| Santé de la collecte (appareil ou agent qui ne remonte plus) | Aucune détection | **Absent** |
| Cycles de facturation déclenchant la collecte des compteurs | Coût à la page calculé à la demande ; pas de cycle ni d'export de relevés | **Plus faible** |
| Fermeture d'alerte sur remontée du niveau (défaut à ne pas reproduire) | L'expédition se ferme sur remontée du niveau (même défaut) **ou** plus tôt sur « livrée » (BL signé, édition manuelle), ce qui **rouvre la possibilité de recommander avant la pose**. Pas de période de garde | **Équivalent au défaut, pire dans le cas « livrée avant pose »** |
| — | Détection « cartouche posée sur la mauvaise imprimante » + réaffectation | **Meilleur** (FM Audit : pas d'équivalent connu) |
| — | Rendement mesuré par cycle, historisé | **Meilleur** (à valider sur le terrain) |
| — | Liaison au BL signé (plugin Gestion) | **Meilleur** |
| — | Rappel « expédiée non posée » | **Meilleur** |
| — | Coût à la page et tableau de bord contrats dans le même outil | **Meilleur** |
| Coût de licence | Gratuit (GLPI) | **Meilleur** |

**Synthèse.** Le plugin est un **tableau de suivi avec commande manuelle**, pas un moteur d'alertes à définitions et actions comme FM Audit. Il est meilleur sur l'estimation, la traçabilité de la pose et le coût. Il est absent ou plus faible sur toute la partie règles, actions, contrat et intégration ERP.

---

## Étape D — Export Excel et mapping Sage

### D.1 Le fichier produit face au format imposé

Générateur : `Expedition::buildPurchaseExcel`, `inc/expedition.class.php:848-890`. Données : `buildPurchaseRowData`, `inc/expedition.class.php:777-842`.

| Élément | Attendu | Produit par le plugin | Conforme | Donnée réellement disponible dans GLPI ? |
|---|---|---|---|---|
| Nom du fichier | `Gesconso_JJMMAAAA_HHMM.xlsx` | `Commande_cartouches_JJMMAAAA_HHMM_<8 hexa>.xlsx` (`:884`) | **Non** | Oui (horloge) |
| Feuille | `Export`, une seule | `Export` (`:851`) | Oui | — |
| En-têtes A–I | Devis … Complement livraison | Orthographe identique (`:853-857`) | Oui | — |
| Colonne J | **Aucune** | « Stock GLPI » (`:856`, `:875`) | **Non** | — |
| A Devis | Date Excel | `date('d/m/Y')` écrit en texte (`:826`, `:864`). PhpSpreadsheet 5.7.0 stocke une chaîne comme texte : ce n'est **pas** une date Excel | **Non** | Oui |
| B Intitule Client | **Code client Sage**, majuscules, sans espace (`MAIRIEMARLY`) | `name` de l'entité GLPI de l'imprimante (`:787-790`) | **Non** | **Non** : aucun code Sage stocké dans GLPI. Sous-entité = nom de la sous-entité, pas du client |
| C Intitule Livraison | Libellé du site de livraison (tel que connu de Sage) | 1er segment du nom complet du lieu GLPI (`:798-800`) | **Incertain** | Partielle : dépend de la structure des lieux ; aucune garantie de correspondance avec le libellé d'adresse Sage |
| D Consommable | **Référence article Sage** | `ref` de la cartouche GLPI résolue ; **vide sans contrôle** si non résolue (`:806-819`) | **Partiel, risqué** | Partielle : champ natif saisi à la main, sans lien Sage. **Le résolveur peut choisir une mauvaise cartouche** (voir D.3) |
| E Designation | `<série> # <localisation> # <libellé consommable>` | `trim(série) # trim(nom du lieu) # trim(nom cartouche GLPI)` (`:830`) | **Partiel** | Série et lieu : oui. Libellé : nom GLPI, pas la désignation Sage. Aucune limite de longueur |
| F Quantite | Entier | `1` constant (`:831`) | Oui (1 ligne = 1 cartouche) | Pas modifiable |
| G Prix | 0 si sous contrat, sinon prix unitaire | `0` constant (`:832`) | **Non** hors contrat | **Non** : ni notion « consommables inclus », ni prix |
| H Fournisseur | Fournisseur imposé, souvent vide | `''` constant (`:833`) | Oui si vide acceptable | Non : aucun lien Fournisseur |
| I Complement livraison | Contact, téléphone, consignes | Commentaire du lieu GLPI (`:797`, `:834`) | **Partiel** | Partielle : texte libre du lieu. Les champs natifs `contact` / `contact_num` de la fiche imprimante ne sont pas utilisés |

**Colonnes sans aucune donnée derrière dans GLPI aujourd'hui** : B (code client Sage), G (prix / contrat), H (fournisseur). Partiellement : C (libellé Sage), D (référence Sage fiable), I (contact structuré).

**Le double espace de la colonne E.**
- Le plugin ne le reproduit pas : chaque morceau est nettoyé de ses espaces.
- Dans l'exemple réel, le double espace vient très probablement d'un espace final dans le libellé de localisation d'origine (« Lieu test␠ »), pas d'une règle de format.
- **Recommandation** : ne pas le fabriquer. Garder le séparateur ` # ` exact et le valider par un import test côté Achats.
- **Point à vérifier** : la longueur maximale de la désignation dans Sage 100 (usuellement 69 caractères). L'exemple en fait 67 et le plugin ne tronque pas.

### D.2 Défauts bloquants du parcours d'export

1. **Commande perdue silencieusement.**
   - `createPurchaseOrder` renvoie « ok » même si le mail Achats a échoué (`inc/expedition.class.php:946`, `961`), et l'écran ne regarde que ce « ok » (`front/dashboard_alerts.php:516`).
   - Les expéditions sont pourtant créées : l'anti-doublon **empêche ensuite de recommander**.
   - Si aucun destinataire Achats n'est configuré mais que le demandeur a un email, le mail part **au seul demandeur** et le plugin considère l'envoi réussi (`:1014-1032`).
2. **Aucune archive** : le fichier est supprimé après l'envoi (`:1047-1049`). Impossible de renvoyer ou de prouver ce qui a été transmis.
3. **Aucun contrôle bloquant** avant export : référence vide, code client absent, lieu absent. La ligne part quand même.
4. **Doublons dans l'Excel** : une cartouche déjà en cours d'expédition est listée à nouveau (`:923-939`).

### D.3 Stockage du mapping

| Correspondance | Stockage actuel | Constat |
|---|---|---|
| Code client Sage ↔ client | **Inexistant** | L'entité GLPI est utilisée par son nom |
| Référence article Sage ↔ cartouche | Champ natif `glpi_cartridgeitems.ref` | Saisie manuelle, ni unicité ni contrôle |
| Propriété SNMP ↔ cartouche | Tables plugin `_cartridge_snmp` (liaison directe) et `_snmp_mapping` (par type) | **Défaut de justesse** : le résolveur a deux replis dangereux. (1) Liaison directe **sans filtre de modèle** (`inc/snmpmapping.class.php:166-182`). (2) **Type seul, toutes marques** (`:240-252`). Les noms de propriétés SNMP sont souvent génériques (`tonerblack`, `Toner Noir`) : la référence d'une autre marque peut être exportée |
| Adresse de livraison Sage ↔ lieu | Inexistant (heuristique « racine du lieu ») | — |
| Contact de livraison | Commentaire du lieu | — |
| Sous / hors contrat, prix | Inexistant | — |
| Fournisseur | Inexistant | — |

### D.4 Bibliothèque Excel

- PhpSpreadsheet **5.7.0**, présente dans le `vendor/` de GLPI (`vendor/composer/installed.json:3970`).
- Le plugin ne déclare aucune dépendance.
- `composer.json` est absent de ce déploiement : non vérifié que c'est une dépendance directe et garantie de GLPI 11 (le plugin casserait si GLPI la retirait).

### D.5 Code d'accès à Sage existant

- **SQL Server** : aucun code (ni pilote sqlsrv, ni PDO, ni tables Sage).
- **API HTTP de documents Sage**, fournie par le **plugin Gestion** (`plugins/gestion/front/SageApi.php`) :
  - authentification par clé `x-api-key` ;
  - timeouts de 5 à 30 s ;
  - lecture seule côté Sage (téléchargement ou vérification de BL en PDF).
- **Utilisation par printgestion** :
  - `ajax/search_bls.php:95-115` : vérifie l'existence d'un BL ; erreurs ignorées sans trace ;
  - `ajax/link_bls.php:203-282` : analyse le PDF et **insère en SQL direct** dans la table d'un autre plugin.
- **Garanties** :
  - aucune écriture dans Sage ;
  - identifiants gérés par le plugin Gestion (mode de stockage non vérifié) ;
  - gestion d'erreur réseau réduite à « ignorer » ;
  - aucune journalisation.
- **Nature de cette API** : non vérifiée (intergiciel maison ? périmètre ?). À clarifier, car c'est peut-être une troisième voie pour le référentiel.

### D.6 Import du référentiel Sage : fichier exporté (a) ou lecture SQL en lecture seule (b)

| Critère | (a) Import d'un fichier exporté de Sage | (b) Lecture SQL Server directe, lecture seule |
|---|---|---|
| Fiabilité | Dépend d'un geste humain (oubli, mauvais filtre, encodage, colonnes déplacées). Maîtrisable par prévisualisation, contrôle d'en-têtes et rapport d'écarts avant validation | Élevée si l'on passe par des **vues SQL dédiées** et stables. Dépend de la disponibilité du serveur et du réseau |
| Fraîcheur | Figée à la date d'export ; au mieux quotidienne si l'export est planifié. Le stock est périmé en quelques heures | Temps réel pour le stock ; référentiel toujours à jour |
| Complexité | Faible : dépôt de fichier, lecture PhpSpreadsheet (déjà dispo), correspondance de colonnes, création ou mise à jour des objets, rapport | Moyenne à haute : extension PHP `pdo_sqlsrv` + pilote ODBC Microsoft sur le serveur GLPI, couche de connexion, timeouts, table locale, cron, schéma Sage 12.20 à valider |
| Couplage à Sage | Faible : un modèle d'export Sage, stable | Fort : une mise à jour de Sage peut modifier les tables. Les noms usuels (non vérifiés sur l'instance) sont listés sous le tableau |
| Risque d'exploitation, réseau | Nul (aucun flux) | Flux du serveur GLPI vers SQL Server (1433). **GLPI reçoit les inventaires des agents installés chez les clients, il est donc probablement exposé.** Une compromission de GLPI donnerait un accès en lecture à la base commerciale, sauf compte limité à quelques vues |
| Identifiants | Aucun | Compte SQL dédié (`SELECT` sur vues uniquement) ; secret à chiffrer (la brique native `GLPIKey::encrypt` existe dans `src/GLPIKey.php:432`). Aujourd'hui le plugin **stocke ses secrets en clair** |
| Adéquation à l'architecture actuelle | Bonne : s'insère dans une page d'administration. Rien d'autre à construire | Faible : le plugin n'a ni couche de connexion, ni journalisation, ni gestion d'erreur réseau (tout est ignoré sans trace). Tout est à construire |
| Effort estimé | 5 à 8 j/h | 8 à 12 j/h (hors mise en place réseau et vues côté Sage) |

Noms de colonnes Sage usuels, **non vérifiés sur l'instance** : `F_COMPTET.CT_Num` / `CT_Intitule` / `CT_Type`, `F_ARTICLE.AR_Ref` / `AR_Design`, `F_ARTSTOCK.AR_Ref` / `DE_No` / `AS_QteSto`, `F_LIVRAISON.LI_No` / `CT_Num` / `LI_Intitule`.

**Recommandation argumentée (la décision t'appartient).**
- **(a) pour le référentiel** (clients, adresses de livraison, articles), en priorité :
  - ces données changent peu ;
  - ce sont elles qui rendent l'Excel juste (colonnes B, C, D) ;
  - aucun risque réseau.
- **Le stock temps réel n'est pas nécessaire pour produire l'Excel** : c'est Sage, après import, qui décide « en stock → expédition » ou « commande fournisseur ».
- **(b) plus tard et uniquement pour le stock**, si le besoin est confirmé (par exemple afficher « en stock » à la collaboratrice avant validation). Conditions :
  - **vues SQL dédiées** créées par l'intégrateur Sage ;
  - compte limité à ces vues ;
  - lecture **par tâche automatique** vers une table locale, jamais pendant un clic ;
  - secret chiffré ;
  - traces des échecs.
- **Avant de trancher** : clarifier l'API HTTP Sage déjà utilisée par le plugin Gestion. Si elle peut exposer clients, articles et stock, elle évite d'ouvrir SQL Server.

---

## Étape E — Audit technique et sécurité

Gravité : **Critique** (perte de commande, fuite ou exécution de code dans le navigateur) / **Haute** / **Moyenne** / **Basse**.

### E.1 `declare(strict_types=1)`

Absent des **61 fichiers PHP** (0 occurrence). Pas de namespaces. Typage partiel dans les signatures.

### E.2 SQL

- Accès majoritairement via l'API GLPI (`$DB->request`, `insert`, `update`, `delete`).
- SQL brut limité à l'installation (DDL statique), au vidage de `_alertview` (`inc/alertview.class.php:98`) et à l'insertion en masse des relevés, **correctement échappée** (`inc/tonerreading.class.php:208-227`).
- Fragments `QueryExpression` construits uniquement à partir d'entiers ou de valeurs fixes (`inc/dashboard.class.php:157-163`, `front/dashboard_expeditions.php:152`).
- Restriction `addDefaultWhere` : valeurs forcées en entier ou en liste fermée (`setup.php:98-104`).
- **Aucune injection SQL identifiée.**
- Défaut mineur : les jokers `%` et `_` ne sont pas neutralisés dans les recherches LIKE (`ajax/search_bls.php:47`).

### E.3 CSRF

- Les requêtes **POST** sont protégées par le cœur GLPI 11 : `src/Glpi/Kernel/Listener/ControllerListener/CheckCsrfListener.php` contrôle le jeton du formulaire ou l'en-tête `X-Glpi-Csrf-Token`.
- Les requêtes **GET ne sont jamais contrôlées**. Or trois endpoints **écrivent en GET** :

| Endpoint | Effet | Gravité |
|---|---|---|
| `ajax/printer_thresholds.php` (appelé en GET, `inc/printercoststab.class.php:341`) | Modifie les seuils d'une imprimante (par exemple seuil à 0 = alertes supprimées) via un simple lien piégé visité par un technicien | **Moyenne** |
| `ajax/refresh_cache.php` | Déclenche un recalcul complet des alertes (lourd), vidage de table compris | **Moyenne** (déni de service) |
| `ajax/save_table_prefs.php` | Écrit des préférences en cache | Basse |

### E.4 Échappement en sortie (XSS)

| Constat | Fichier | Gravité |
|---|---|---|
| **XSS stockée par le nom d'imprimante** dans le bouton « Réattribuer ». Le texte traduit « Réattribuer l'expédition… » contient une apostrophe qui **ferme l'attribut `onclick` délimité par des apostrophes**. Le reste du texte, dont le nom d'imprimante, est alors lu comme attributs HTML : un nom du type `x onmouseover=alert(1)` injecte un gestionnaire d'événement (`htmlspecialchars` n'échappe ni espaces ni `=`). Le nom d'imprimante peut provenir de l'inventaire SNMP, donc d'un équipement sur le réseau d'un client. Effet de bord : la confirmation ne s'exécute jamais. Confirmé par lecture, non exécuté | `front/dashboard_expeditions.php:95-103` | **Critique** |
| **Injection HTML dans les mails** : les balises (nom d'imprimante, client, cartouche, liste d'imprimantes) sont insérées **sans échappement**, y compris dans le mail de courtoisie au client externe | `inc/config.class.php:1400-1404` avec valeurs brutes, par exemple `inc/alert.class.php:899-904`, `inc/expedition.class.php:631-639`, `1167-1175`, `1305-1306`, `1536-1540` | **Haute** |
| Sélecteur jQuery construit avec le texte saisi (effet limité à l'utilisateur lui-même) | `inc/dashboardactions.class.php:794`, `806`, `813` | Basse |
| Le reste de l'affichage est échappé (`htmlspecialchars` côté PHP, `esc()` / `escapeHtml()` côté JS) | — | — |

### E.5 Contrôle des droits et cloisonnement des entités

C'est déterminant pour un prestataire multi-clients si des utilisateurs clients ont un compte GLPI (information non fournie).

| Constat | Fichier | Gravité |
|---|---|---|
| **Aucun cloisonnement par entité dans le tableau des alertes** : la liste charge toutes les imprimantes ; le filtre d'entité est un paramètre modifiable, vide par défaut, donc tous les clients | `inc/alert.class.php:452-481`, `ajax/list_alerts.php:21-38` | **Haute** |
| Alertes prioritaires (mauvaise imprimante, retards) sans restriction d'entité | `inc/alert.class.php:1072-1162` | Haute |
| Export Excel du coût à la page : toutes les entités | `inc/billing.class.php:39-61`, `ajax/export_excel.php` | **Haute** |
| Totaux du coût à la page sans restriction d'entité | `inc/billingview.class.php:183-216` | Moyenne |
| Commande, édition, réaffectation, annulation, mise en pause, résolution : droit vérifié mais **aucun contrôle que l'imprimante ou l'expédition appartient à une entité autorisée** (identifiant manipulable) | `ajax/send_purchase.php`, `edit_expedition.php`, `update_expedition.php`, `reassign_expedition.php`, `snooze_*.php`, `resolve_alert.php` | Haute |
| **Aucune vérification de droit** (connexion seule) : stock et nom de cartouche de n'importe quelle imprimante ; recherche de BL toutes entités + appel API Sage ; 2 endpoints orphelins | `ajax/cartridge_stock.php`, `search_bls.php`, `list_bls.php`, `bl_expeditions.php` | Moyenne |
| « Mettre en pause » et « commander » relèvent du même droit | `ajax/send_purchase.php:25-26` | Moyenne |
| Clés API transporteurs stockées en clair **et réaffichées** dans la page de configuration | `inc/config.class.php:1061-1062` | Moyenne |
| Mail de courtoisie **coché par défaut**, avec repli sur **tous les utilisateurs ayant un profil sur l'entité** (techniciens internes compris) | `front/dashboard_alerts.php:124`, `455` ; `inc/expedition.class.php:1108-1119` | Moyenne |

### E.6 Erreurs, journaux et traçabilité

- **14 blocs `catch` qui ignorent l'erreur sans trace**, **aucune journalisation** (0 `Toolbox::logInFile`).
- Échec d'envoi de mail signalé seulement par un message de session, **invisible dans une tâche automatique** (`inc/config.class.php:1433-1437`). Échec du mail Achats non remonté à l'écran (D.2).
- **Aucun historique GLPI** sur les expéditions et alertes : les mises à jour passent par `$DB->update` direct, sans l'historique natif. L'annulation est une **suppression définitive** (`ajax/update_expedition.php:39`).
- Écritures SQL directes dans les tables natives `glpi_cartridges` et `glpi_notificationtemplates`, et dans la table d'un autre plugin (`glpi_plugin_gestion_surveys`).
- Installation : arrêt brutal sur erreur SQL ; migrations en erreur ignorées ; aucun suivi de version de schéma.

### E.7 Compatibilité GLPI 11

| Point | Constat | Référence |
|---|---|---|
| `Plugin::getWebDir()` | **Obsolète depuis 11.0** : alerte de dépréciation à chaque chargement de page | `setup.php:13-16` ; `src/Plugin.php:3129-3135` |
| Propriétés `Subject`, `Body`, `AltBody`, `ErrorInfo` de GLPIMailer | **Obsolètes** : alertes à chaque mail | `inc/config.class.php:1427-1435`, `inc/expedition.class.php:1080-1082` ; `src/GLPIMailer.php:279-383` |
| `CronTask $task = null` (paramètre implicitement nullable) | Déprécié en PHP 8.4 (version PHP du serveur non vérifiée) | `inc/reminder.class.php:38`, `61`, `86` |
| `include('../../../inc/includes.php')` | Couche de compatibilité conservée en 11 ; forme historique (35 fichiers) | — |
| HTML construit à la main au lieu de TemplateRenderer / Twig | Généralisé (1 seul appel Twig) ; ~1 600 lignes de JS dans du PHP | `inc/dashboard.class.php:317` |
| Structure `inc/` sans namespace, `extends CommonDBTM` sans table (Tracking, Reminder) | Fonctionne, mais forme historique | — |
| Notifications | Modèles rattachés artificiellement à Ticket, hors moteur de notification | `hook.php:250` |
| Borne maximale `11.1.0` | Sémantique exacte (inclusive ou exclusive) non vérifiée ; la mise à jour vers 11.1 est à tester | `setup.php:11` |

### E.8 Tenue de charge à 2 000 imprimantes

Hypothèse de volumétrie (**estimation**, à mesurer) : ~4 propriétés exploitables par imprimante, soit **~8 000 séries** imprimante × toner.

| Point chaud | Mécanisme | Ordre de grandeur estimé | Gravité |
|---|---|---|---|
| **Écran des alertes : requêtes en cascade (N+1) à chaque affichage** | Pour chaque cartouche filtrée, avant pagination, le regroupement relance la résolution de cartouche (2 à 7 requêtes) et le stock (1 requête) — **non mis en cache**. Déclenché à chaque changement de page, tri, filtre ou frappe dans la recherche (300 ms) | Filtre « Tous » : **~24 000 à 64 000 requêtes par affichage** ; filtre par défaut : quelques centaines à milliers | **Critique** |
| **Calcul complet des alertes en mémoire** | Charge **tout l'historique des relevés** dans des tableaux PHP. Tourne 2×/h en tâche automatique, puis de nouveau à la première consultation après chaque invalidation de cache (le recalcul de la tâche n'alimente pas le cache) | Plafond théorique 1,28 M lignes (8 000 × 160 j) ; réaliste 400 000 à 800 000 ; **plusieurs centaines de Mo de RAM** | **Haute** |
| Recréation de `_alertview` | Vidage complet puis ~8 000 insertions une par une, chaque heure, hors transaction : table **vide pendant la reconstruction** (compteur d'accueil à 0) | ~8 000 écritures/h | Moyenne |
| Création quotidienne de cartouches natives | Pour chaque propriété, résolution (2 à 7 requêtes) + test, **tous les jours même sans changement** | ~20 000 à 60 000 requêtes/jour | Moyenne |
| Détection des changements | 1 à 3 requêtes par série | ~8 000 à 24 000/jour | Basse |
| Coût à la page | **Recalcul et réécriture des lignes de l'utilisateur à chaque chargement de page**, pagination et tri compris (`front/dashboard_billing.php:90`). Calcul : ~6 requêtes × imprimante (cache 10 min) | Jusqu'à 2 000 suppressions et insertions par clic ; ~12 000 requêtes par calcul | **Haute** |
| Page des expéditions | **Toutes les expéditions, sans limite ni purge**, injectées en JSON dans chaque page (`front/dashboard_expeditions.php:198-232`) | ~12 000 lignes/an (hypothèse 6 envois/imprimante/an) | Moyenne, croissante |
| Envoi des mails pendant le clic | Commande multi-clients avec courtoisie : 1 envoi SMTP par groupe de contacts, dans la requête HTTP | Risque de dépassement de délai au-delà de quelques dizaines de contacts | Moyenne |
| Tables sans purge | alertes, pauses, historique des cartouches, rendements, expéditions | Croissance continue | Moyenne |
| Index | Index en double sur les relevés ; aucun index (imprimante, toner, statut) sur les expéditions | — | Basse |
| Concurrence | Aucune contrainte d'unicité ni verrou sur « une expédition active par imprimante × toner » | Doublons en cas de double clic ou d'utilisateurs simultanés | Haute (métier) |

**Durée réelle des tâches automatiques : non mesurée** (base non interrogée). Première action recommandée : consulter les journaux des actions automatiques et compter les lignes des tables.

### E.9 Autres défauts fonctionnels relevés

| Constat | Référence |
|---|---|
| Le mail commercial « toner bas » **repart toutes les 24 h** pour une cartouche en « stock vide » (ce statut n'est pas exclu) | `inc/alert.class.php:854-857` |
| Le stock GLPI **n'est jamais décrémenté** : le plugin insère de nouvelles cartouches au lieu de consommer le stock comme `Cartridge::install()` natif (`src/Cartridge.php:270-300`). La création quotidienne **invente des cartouches « posées »** | `inc/cartridgehistory.class.php:364-469`, `486-552` |
| « Stock » global toutes entités, sans notion de dépôt | `inc/expedition.class.php:1428-1473` |
| Le contrat retenu pour une imprimante est le plus récent, **même échu** | `inc/contractrate.class.php:168-194` |
| La documentation technique affirme un anti-doublon complet, contredit par le parcours de commande | `docs/DOC_TECHNIQUE.md:127-128` |

---

## Étape F — Verdict « GLPI-native first »

| Brique | Ce que fait le plugin | Ce que GLPI 11 fait nativement | Verdict | Code supprimable (estimation) |
|---|---|---|---|---|
| Compteurs et niveaux | Lit l'inventaire natif (bien). **Recalcule deux fois** l'extraction N&B / couleur | Agent GLPI : `glpi_printerlogs`, `glpi_printers_cartridgeinfos`, graphiques de compteurs `PrinterLog::getMetrics` (`src/PrinterLog.php:101`) | **Natif en source, doublon interne** | ~50 lignes |
| Consommables et stock | Insère ou clôt des cartouches en SQL direct ; invente des cartouches posées ; stock jamais consommé | `Cartridge::install()` (consomme le stock, historise), `CartridgeItem.alarm_threshold` et `stock_target`, **alerte native de stock bas** (`src/CartridgeItem.php:412`) | **Réinvente, en moins bien** | ~170 lignes, remplacées par ~30 d'appels natifs |
| Contrats : échéance et préavis | Tableau de bord et compteurs (utile) ; alerte de fin de contrat absente | **Alertes natives de contrat** : réglages d'entité `use_contracts_alert` et `send_contracts_alert_before_delay` ; événements de notification `end`, `notice`, `periodicity` (`src/NotificationTargetContract.php:47-49`) | Tableau de bord OK. **Ne pas coder l'alerte de fin de contrat : l'activer** | 0 à écrire (évite ~150) |
| Sous contrat / consommables inclus | Absent | Type de contrat natif (liste déroulante), par exemple « Impression — consommables inclus » | **À faire en natif, sans code** | — |
| Tarifs €/page | Table plugin | Pas d'équivalent natif | **Garder** (petite table) | 0 |
| Fournisseurs | Ignorés (colonne H vide) | `Supplier`, fournisseur de la fiche financière | Natif disponible si besoin | — |
| Lieux et entités | Utilisés ; heuristique « racine du lieu = site » | Colonnes natives `glpi_locations.code` (code d'adresse Sage) et `glpi_printers.contact` / `contact_num` (contact de livraison), vérifiées dans le schéma | **Utiliser les champs natifs existants** | — |
| Moteur de recherche | Natif pour contrats, expéditions, coût. **Tableau maison en JS pour les alertes**, alors que la table `_alertview` et ses options de recherche et actions de masse existent déjà pour le moteur natif | `Search::showList`, actions de masse, export, colonnes, recherches enregistrées | **Réinvente** (paradoxe : la brique native est construite puis inutilisée) | **~1 000 lignes** (moteur JS, liste des alertes, pagination, préférences de colonnes) |
| Notifications | Envoi maison synchrone ; modèles rattachés à Ticket et réécrits à chaque mise à jour ; destinataires résolus à la main | `NotificationTarget` et événements (hooks `item_get_events` / `item_get_datas`, `src/Glpi/Plugin/Hooks.php:555-564`), **file d'attente `QueuedNotification`** avec nouvelles tentatives et journal, modèles éditables par l'administrateur, destinataires par groupe, profil ou acteur | **Réinvente, en moins bien** (pas de file, pas de nouvelle tentative, contenu écrasé) | **~900 à 1 100 lignes** supprimées, ~250 à écrire |
| Cartes de tableau de bord | Aucune ; barres de statistiques maison | Hook `dashboard_cards` (`src/Glpi/Plugin/Hooks.php:1020`) : cartes dans le tableau de bord central GLPI | Optionnel | ~100 à 150 lignes |
| Champs additionnels pour les références Sage | Rien | **Les champs personnalisés natifs de GLPI 11 ne concernent que les actifs personnalisés** (`src/Glpi/Asset/CustomFieldDefinition.php:54`), **pas** Entity, Printer ou CartridgeItem natifs. Natifs réutilisables : `CartridgeItem.ref` (article Sage), `Location.code` (adresse de livraison). **Aucun champ natif libre pour le code client Sage d'une entité** (`registration_number` = SIRET, à ne pas détourner) | **Table de correspondance plugin** (entité ↔ code client, lieu ↔ adresse), alimentée par l'import Sage, plutôt que le plugin « Fields » (dépendance supplémentaire) | — |
| Historique et traçabilité | `$DB->update` direct, suppression définitive | `CommonDBTM::update` avec historique : onglet Historique natif | **Réinvente par omission** | ~0 (remplacement à l'identique) |
| Journal anti-répétition des mails | Table `_alerts` | Classe native `Alert` (`glpi_alerts`), utilisée pour contrats et cartouches | Remplaçable, gain faible | ~50 lignes |
| Définitions d'alerte (façon FM Audit) | Seuils codés | **Moteur de règles natif GLPI** (Rule / RuleCollection : critères + actions), extensible par plugin. Correspond au modèle FM Audit « critères + actions » | **À utiliser pour l'objectif 2** plutôt qu'un moteur maison. Faisabilité détaillée non prototypée | évite ~1 500 à 2 500 lignes |
| Validation des demandes | Absente | `TicketValidation` (approbation) avec relance native (`approval_reminder_repeat_interval` sur l'entité) | Option « 1 demande = 1 ticket » détaillée sous le tableau | — |
| Tâches automatiques, droits, menu | CronTask, Profile, menu natifs | — | **Natif, bien** | — |

**Option « 1 demande d'envoi = 1 ticket GLPI » avec approbation native** :
- **Pour** : validation, relances, niveaux de service, notifications et historique natifs.
- **Contre** : pollue les indicateurs du helpdesk ; pas de lignes quantité / prix / référence structurées ; un volume de tickets élevé.
- **Recommandation** : un objet « Demande » dédié, mais **appuyé sur les notifications, l'historique et les documents natifs**.

**Total estimé supprimable ou remplaçable : ~2 500 à 3 000 lignes sur ~15 000 (≈ 20 %)**, code mort compris (~800 lignes), avant ajout des fonctions manquantes.

---

## Étape G — Plan d'action priorisé, risques, questions

Efforts en jours / homme pour un développeur GLPI expérimenté. **Estimations**, à affiner après les réponses aux questions.

### P1 — Bloquant pour débrancher FM Audit

| # | Action | Effort | Fichiers à toucher |
|---|---|---|---|
| P1.0 | **Prérequis** : dépôt git ; numéros de version de schéma avec migrations réelles (plus de « désinstaller / réinstaller ») ; journal applicatif des erreurs | 1–2 j | `setup.php`, `hook.php`, `inc/config.class.php:78-606` |
| P1.1 | **Colmater le double envoi tout de suite** sur l'existant : refuser côté serveur toute ligne dont l'expédition est active ; retirer ces lignes de la fenêtre ; « livrée » ne libère plus avant la pose (période de garde paramétrable) ; contrainte d'unicité ou verrou | 1–2 j | `inc/expedition.class.php:397-412`, `901-962` ; `inc/tracking.class.php:94-99` ; `ajax/edit_expedition.php` ; `front/dashboard_alerts.php:417-438` ; config |
| P1.2 | **Échec du mail Achats bloquant et visible** ; archivage de chaque Excel en Document GLPI natif rattaché à la commande | 1 j | `inc/expedition.class.php:946-961`, `1003-1052` ; `front/dashboard_alerts.php:516` |
| P1.3 | **Objet « Demande d'envoi »** : en-tête client / site + lignes, avec les statuts proposée → validée → exportée (commandée) → expédiée → livrée → posée / annulée. Création **automatique** par la tâche horaire ; **regroupement automatique par client et site** ; validation en un clic ; quantité, contact et commentaire modifiables ; **fermeture sur expédition + période de garde** ; historique natif | 8–12 j | nouveaux `inc/request.class.php`, `inc/requestline.class.php`, `front/request*.php` ; `inc/reminder.class.php` ; refonte `inc/alert.class.php:840-957` et `inc/expedition.class.php` |
| P1.4 | **Export Excel conforme depuis les demandes validées** : `Gesconso_JJMMAAAA_HHMM.xlsx`, 9 colonnes, date Excel réelle, prix selon le contrat, **contrôles bloquants** (référence, code client, adresse), téléchargement + envoi Achats | 3–4 j | nouveau `inc/export.class.php` (remplace `inc/expedition.class.php:777-890`) |
| P1.5 | **Référentiel Sage par import de fichier (a)** : clients (code ↔ entité), adresses (↔ lieux, `Location.code`), articles (↔ `CartridgeItem.ref`, création ou mise à jour native) ; prévisualisation + rapport d'écarts | 5–8 j | nouveaux `inc/sageimport.class.php`, `front/sageimport.php`, table de correspondance |
| P1.6 | **Résolution de cartouche stricte** : modèle d'imprimante obligatoire, suppression des replis « sans modèle » et « type seul » ; pas de référence = pas d'export | 1 j | `inc/snmpmapping.class.php:125-255` |
| P1.7 | **Notion sous contrat / consommables inclus** (type de contrat natif) → prix 0 ou prix hors contrat (source à décider) ; contrat en cours seulement | 1–2 j | `inc/contractrate.class.php:168-194` ; export |
| P1.8 | **Niveaux non numériques** (OK/WARNING → niveau normalisé) et **détection des imprimantes muettes** (`last_inventory_update`, date de mise à jour des niveaux) | 2–3 j | `inc/tonerreading.class.php:34-58`, `166-194` ; `inc/alert.class.php:483-553` |
| P1.9 | **Sécurité** : XSS « Réattribuer » ; cloisonnement par entité (alertes, alertes prioritaires, exports, commandes, éditions) ; droits sur les endpoints non protégés ; écritures GET → POST ; échappement des balises de mail ; suppression des endpoints orphelins ; courtoisie décochée par défaut et sans repli « toute l'entité » | 2–3 j | `front/dashboard_expeditions.php:95-103` ; `inc/alert.class.php` ; `inc/billing.class.php` ; `ajax/*` ; `inc/config.class.php:1321-1440` ; `inc/expedition.class.php:1092-1120` |
| P1.10 | **Charge : mesurer puis corriger** : volumétrie et durée réelles ; suppression des requêtes en cascade (préchargement résolution et stock) ; chargement des seuls relevés utiles ; reconstruction de `_alertview` en masse et en transaction ; recalcul du coût seulement à la soumission du filtre | 3–5 j | `inc/alert.class.php:362-567`, `746-812` ; `inc/alertview.class.php:91-117` ; `front/dashboard_billing.php:90` |
| P1.11 | **Fonctionnement en parallèle de FM Audit** sur un échantillon de clients représentatifs (marques, mono / couleur, sites) : comparaison des alertes et des dates d'épuisement, calibration, puis débranchement | 4–6 semaines calendaires, ~4 j d'analyse | — |

**Total P1 : ~32 à 46 j/h + période de fonctionnement en parallèle.**

### P2 — Gain de temps direct (collaboratrice, Achats)

| # | Action | Effort | Fichiers |
|---|---|---|---|
| P2.1 | **Notifications natives** (NotificationTarget + événements) : demande à valider, **relance si une demande traîne** (délai paramétrable), export envoyé aux Achats, courtoisie (contact explicite), expédition, rappel de pose. Suppression de l'envoi maison | 5–7 j | nouveau `inc/notificationtargetrequest.class.php` ; suppression `inc/config.class.php:1321-1440`, `hook.php:79-272`, circuits `inc/expedition.class.php` |
| P2.2 | **Définitions d'alerte paramétrables** sur le moteur de règles natif GLPI : critères (type ou couleur de consommable, niveau ≤/≥, jours, volume, état, entité, modèle, type de contrat) avec TOUS / AU MOINS UN ; actions (proposer une demande, validation requise, notifier, journal seul, escalade si retard). Suppression des seuils codés | 8–12 j | nouveaux `inc/ruletoner*.class.php` ; `inc/alert.class.php` |
| P2.3 | **Tableau des alertes sur le moteur de recherche natif** (Alertview) + action de masse « ajouter à une demande » ; suppression du moteur JS maison | 3–4 j | `front/dashboard_alerts.php` ; `inc/dashboardactions.class.php:830-2027` ; `inc/alertview.class.php` |
| P2.4 | **Mode de livraison** : expédition directe ou technicien (option : création d'une intervention ou d'un ticket natif pour le technicien) | 1–2 j | objet Demande |
| P2.5 | **Fin de contrat** : activer les alertes natives de contrat (paramétrage d'entité et de notifications) | 0,5 j, sans code | configuration GLPI |
| P2.6 | **Stock** : `Cartridge::install()` natif au lieu des insertions SQL ; lecture du stock Sage (b) seulement si le besoin est confirmé | 2 j / 8–12 j | `inc/cartridgehistory.class.php:364-552` |
| P2.7 | **Transporteurs** : implémenter réellement les API **ou** supprimer les fonctions vides ; clés chiffrées (GLPIKey), masquées à l'écran | 3–5 j / 0,5 j | `inc/tracking.class.php` ; `inc/config.class.php:1051-1070` |
| P2.8 | Historique natif sur toutes les écritures ; annulation par statut au lieu d'une suppression | 1 j | `inc/expedition.class.php`, `ajax/*` |

**Total P2 : ~24 à 45 j/h selon les options.**

### P3 — Confort / plus tard

| # | Action | Effort |
|---|---|---|
| P3.1 | Suppression du code mort (~800 lignes) | 1 j |
| P3.2 | Politique de purge (alertes, pauses, historiques, rendements, expéditions archivées) | 1 j |
| P3.3 | Cartes de tableau de bord natives (toners critiques, demandes en attente, expéditions en retard) | 1–2 j |
| P3.4 | Modernisation GLPI 11 : `getWebDir`, API Symfony du mailer (si P2.1 non fait), types nullables, `strict_types`, namespaces, Twig, JS extrait en fichiers | 5–10 j |
| P3.5 | Traduction réelle (fichiers .po / .mo) | 1–2 j |
| P3.6 | Taux de couverture, cycles de facturation, export des relevés de compteurs pour la facturation au clic | 3–5 j |
| P3.7 | Tests automatisés (estimation des jours restants, export Excel, anti-doublon) | 3–5 j |
| P3.8 | Mise à jour de la documentation (affirmations actuelles inexactes) | 1 j |

### Risques et angles morts

**Ce qui peut casser à l'échelle du parc**
- **La collecte repose entièrement sur l'agent GLPI chez chaque client** : déploiement, identifiants SNMP, flux sortants vers GLPI, exposition de GLPI sur Internet. C'est le remplaçant du DCA de FM Audit, **hors du périmètre du plugin et non vérifié**. Sans supervision des imprimantes muettes, une rupture de collecte donne une rupture de toner silencieuse.
- **Qualité des remontées SNMP par marque** : noms de propriétés génériques, valeurs OK/WARNING, kits. Part du parc concernée inconnue.
- **Précision de l'estimation** non comparée à FM Audit ; relevés figés quand le niveau ne bouge pas.
- **Mémoire et requêtes** (E.8) : l'écran des alertes et le calcul horaire peuvent devenir inutilisables à 2 000 imprimantes.
- **Doubles envois et commandes perdues** tant que P1.1 et P1.2 ne sont pas faits.
- **Envoi des mails pendant le clic** : lenteurs et dépassements de délai sur les grosses commandes.
- **Évolutions du plugin** : sans git, sans tests et sans versions de schéma, une mise à jour peut détruire les données (réinstallation).
- **Dépendances fragiles** : plugin Gestion (API Sage documents, écriture SQL dans sa table) ; PhpSpreadsheet non déclarée ; mise à jour vers GLPI 11.1 à tester.
- **Sécurité multi-clients** (E.4, E.5) si des utilisateurs clients ont un compte GLPI.

**Dépend d'informations non fournies**
- Format exact attendu par l'outil d'import Sage (« Gesconso » : import paramétrable Sage ou outil maison ?), longueur maximale de la désignation, acceptation du prix à 0, de la date, de l'encodage.
- Nature et périmètre de l'API HTTP Sage du plugin Gestion.
- Volumétrie réelle (imprimantes inventoriées, propriétés par imprimante, part OK/WARNING, expéditions par an).
- Hébergement et exposition réseau de GLPI ; version de PHP ; configuration SMTP.

### Questions à poser avant de coder

1. **Collecte** : l'agent GLPI (inventaire réseau SNMP) est-il déjà déployé chez tous les clients ? Sinon, combien de sites et qui s'en charge ? Combien d'imprimantes remontent aujourd'hui des niveaux dans GLPI ?
2. **Import Sage** : quel outil importe l'Excel (import paramétrable Sage 100, outil maison « Gesconso ») ? Peux-tu fournir le modèle d'import et un fichier réellement importé avec succès, pour vérifier types, longueurs et encodage ?
3. **Code client et adresse** : « Intitule Livraison » doit-il correspondre exactement à un libellé d'adresse de livraison Sage, ou est-ce du texte libre ? Un client Sage peut-il avoir plusieurs sites de livraison, et comment sont-ils structurés dans GLPI (entités, sous-entités, lieux) ?
4. **Prix hors contrat** : d'où vient-il (tarif Sage de l'article, grille client, saisie des Achats) ? Le plugin doit-il le renseigner ou laisser vide ?
5. **Notion de contrat** : comment sait-on aujourd'hui qu'une imprimante est sous contrat avec consommables inclus ? Un type de contrat GLPI est-il acceptable comme support ?
6. **Validation** : qui valide (la collaboratrice seule ? une personne par client ?) et faut-il une validation à deux niveaux pour les montants hors contrat ?
7. **Période de garde** : quelle durée par défaut entre « cartouche expédiée » et « nouvelle alerte autorisée » (10 j ? par type d'envoi ?) ; que faire si le toner tombe à 0 pendant la garde ?
8. **Livraison par technicien** : faut-il générer quelque chose dans GLPI (ticket, intervention planifiée) ?
9. **Stock** : la collaboratrice doit-elle voir le stock Sage avant de valider, ou la décision stock / commande reste-t-elle entièrement chez les Achats après import ? (Cela décide de l'option (b).)
10. **API Sage existante** (plugin Gestion) : qui la maintient, que peut-elle exposer (clients, articles, stock) ?
11. **Utilisateurs clients** : des clients ont-ils un compte GLPI (portail helpdesk) ? (Cela fixe l'urgence du cloisonnement par entité.)
12. **Mail de courtoisie** : à qui exactement l'envoyer (contact déclaré par site, usager de l'imprimante) ? Faut-il un accord du client ?
13. **Hébergement** : GLPI est-il exposé sur Internet ? Un flux vers le SQL Server de Sage est-il envisageable par votre sécurité ?
14. **Débranchement FM Audit** : date butoir contractuelle (renouvellement ECi / migration Printanista) ? Cela fixe la faisabilité du fonctionnement en parallèle.
15. **Version** : puis-je initialiser un dépôt git du plugin avant toute modification ?
