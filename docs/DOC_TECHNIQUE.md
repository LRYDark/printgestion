# Print Gestion — Documentation technique (fonctionnement)

> Plugin GLPI 11 de gestion de flotte d'impression — JCD Groupe.
> Ce document décrit **comment le plugin fonctionne** (architecture, flux de données, mails, crons).
> Pour la mise à jour / le dépannage, voir [DOC_MAINTENANCE.md](DOC_MAINTENANCE.md).

---

## 1. Vue d'ensemble

Print Gestion couvre 3 domaines, activables indépendamment par des **interrupteurs de modules**
(Configuration → onglet Print Gestion) :

| Module (feature) | Contenu |
|---|---|
| `contrats` | Dashboard contrats d'impression, liste avec moteur de recherche natif, création/association imprimante ↔ contrat (« Créer Print »), tarifs €/page par contrat |
| `toner` | Relevés SNMP des niveaux toner, calcul d'alertes intelligent, cycle d'expédition des cartouches, commandes achats (Excel), notifications mail, suivi transporteurs |
| `cout` | Coût à la page par imprimante / par client sur une période (compteurs `glpi_printerlogs` × tarifs contrat) |

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
| `Config` | Singleton de configuration (ligne id=1), **crée toutes les tables à l'install**, envoi mail générique `sendMail()` |
| `Menu` | Entrée de menu + hub à catégories + barre d'onglets unifiée |
| `Profile` | Droits du plugin (6 droits, voir §8) |
| `Dashboard` | Dashboard contrats (tuiles, camemberts ECharts, liste Search native) |
| `Contract` | Itemtype « virtuel » sur `glpi_contracts` pour borner la recherche aux contrats liés à ≥1 imprimante |
| `Contractrate` | Tarifs N&B / Couleur par contrat (onglet sur fiche Contract) |
| `Print` | Création/association imprimante ↔ contrat (4 scénarios, transactionnel) |
| `Tonerreading` | Snapshot horodaté des niveaux toner (lit `glpi_printers_cartridgeinfos` SNMP GLPI 11) |
| `Cartridgehistory` | Détection automatique des changements de cartouche (hausse de niveau ≥ `detection_delta` %) |
| `Alert` | Calcul intelligent des alertes toner (vitesse de conso sur fenêtre 30 j) + **digest mail commercial** |
| `Alertview` | Table **matérialisée** des alertes et écran natif (recherche, colonnes verrou / référence / stock, actions de masse Commander, Ne plus alerter, Réactiver) |
| `Expedition` | Cycle d'expédition des cartouches, **tous les circuits mail** (planif/achats/courtoisie/rappels) |
| `Demande` / `Demandeline` | Demande d'envoi (en-tête client + site, lignes) : statuts, contrôles avant validation, historique natif |
| `Guard` | Verrous anti-double-envoi (envoi en cours, demande ouverte, garde après pose, ticket récent) |
| `Sageimport` | Import du référentiel Sage par fichier : analyse, prévisualisation, rapport d'écarts, validation |
| `Sage` | Correspondances Sage (code client d'une entité, hérité du parent) + onglet « Print Gestion — Sage » de l'entité |
| `Gesconso` | Fichier de commande Gesconso (9 colonnes), contrôles bloquants avant écriture, archivage en Document |
| `Snmpadapter` | Lecture fiable des niveaux SNMP : sentinelles, états bruts max/used/remaining, règles par constructeur |
| `Collect` | Collecte SNMP : imprimantes jamais remontées, muettes ou sans niveau lisible, agents qui ne remontent plus |
| `NotificationTargetDemande` | Notifications natives GLPI des demandes d'envoi (proposée, relance, exportée) |
| `Contractalert` | État et activation des alertes de contrat natives GLPI |
| `Snmpmapping` | Mapping constructeur + propriété SNMP → modèle de cartouche + couleur |
| `Cartridgesnmp` | Onglet sur fiche CartridgeItem : binding direct cartouche ↔ propriétés SNMP |
| `Billing` / `Billingview` | Coût à la page + table matérialisée **par utilisateur** (le calcul dépend de la période choisie) |
| `PrinterCostsTab` | Onglet « Coût à la page » sur la fiche imprimante |
| `Tracking` | Intégrations externes : BL signés du plugin Gestion + APIs transporteurs (UPS/GLS/Chronopost) |
| `Reminder` | Les 3 tâches cron GLPI (voir §7) |
| `Dashboardactions` | Menu contextuel et modales des écrans Expéditions et Coût à la page (stock, modifier l'expédition, BL) |

---

## 3. Modèle de données

Le schéma est **versionné** (`inc/schema.class.php`) : la version installée est enregistrée dans la
configuration GLPI (`glpi_configs`, contexte `plugin:printgestion`, clé `schema_version`) et chaque
évolution est une étape de migration jouée une seule fois lors du « Mettre à jour » (voir doc maintenance §2).

| Table | Contenu |
|---|---|
| `glpi_plugin_printgestion_configs` | Configuration singleton (id=1) : features, seuils, rôles mail, IDs gabarits |
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
| `glpi_plugin_printgestion_snmpadapters` | Règles de lecture SNMP par constructeur (ignorer / inverser une propriété) |
| `glpi_plugin_printgestion_expedition_bls` | Liaison expéditions ↔ BL du plugin Gestion |
| `glpi_plugin_printgestion_snmp_mapping` | Mapping constructeur/propriété SNMP → cartouche |
| `glpi_plugin_printgestion_cartridge_snmp` | Bindings directs cartouche ↔ propriété SNMP |
| `glpi_plugin_printgestion_contractrates` | Tarifs €/page N&B / Couleur par contrat |
| `glpi_plugin_printgestion_billing` | Lignes de facturation calculées |
| `glpi_plugin_printgestion_billing_view` | **Matérialisée par utilisateur** : coût à la page pour le Search natif |
| `glpi_plugin_printgestion_historical_yields` | Rendements historiques (pages/cartouche) |
| `glpi_plugin_printgestion_printer_thresholds` | Seuils d'alerte personnalisés par imprimante |
| `glpi_plugin_printgestion_table_prefs` | Préférences d'affichage des tableaux par utilisateur |

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

Le stock = cartouches du modèle ni installées (`date_use IS NULL`) ni sorties (`date_out IS NULL`).

---

## 5. Cycle d'expédition

```
pending ──(planif saisit transporteur+tracking)──> shipped ──> transit ──> delivered ──> installed
   └── stock_empty si aucun stock au déclenchement              (+ cancelled : annulée, jamais supprimée)
```

- **Envoi en cours** (`Expedition::ACTIVE_STATUSES`, sans borne de temps) : pending, stock_empty,
  shipped, transit **et delivered**. « Livrée » ne clôt pas l'envoi : seule la **pose** le fait.
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

Évalués pour une **machine** (l'imprimante et toute imprimante portant le même n° de série) et un
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
  « mauvaise imprimante » ne retient que les envois **en cours** d'une autre imprimante, et seulement
  si l'imprimante détectée n'attendait elle-même aucun envoi pour ce toner.
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
  toners critiques ou à surveiller, non snoozés, sans verrou bloquant ; regroupement par client (entité) et
  site (lieu racine) — la demande proposée existante du groupe est complétée, sinon créée (contact prérempli
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
  `exported`, fichier archivé sur les demandes et les expéditions, mail Achats ; échec d'archivage ou de mail
  = rien n'est enregistré. **Télécharger (test, sans envoi)** : même fichier, archivé sur les demandes avec la
  mention « non transmis », noté dans leur historique, sans mail, sans changement de statut ni expédition.
  Une seule ligne en défaut refuse l'export entier.
- **Suivi après export** (`Demande::syncFromExpeditions()`, tâches `CheckAlerts` et `TrackingUpdate`, et à
  l'ouverture d'une fiche) : chaque ligne exportée suit son expédition (en attente / stock vide → exportée,
  expédiée ou en transit → expédiée, livrée → livrée, posée → posée, annulée → annulée) ; l'en-tête prend le
  statut le moins avancé des lignes non annulées, annulée si toutes le sont.
- **Droits** : `plugin_printgestion_validation` (READ voir, UPDATE modifier / valider / annuler). La file
  est aussi visible avec la lecture des alertes toner, sans pouvoir agir. Pas de création manuelle.

### Référentiel Sage (`inc/sageimport.class.php`, `inc/sage.class.php`)

- **Aucune liaison directe avec Sage** : un fichier exporté de Sage est déposé à la main (onglet « Référentiel
  Sage », droit `config` UPDATE). Formats : xlsx, xls, ods, csv (encodage et séparateur détectés). Première
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
- **Archivage** (`Gesconso::archive()`) : chaque fichier transmis devient un Document GLPI natif (nom
  `Gesconso_…xlsx`, commentaire date / auteur / volume), rattaché aux expéditions créées (commande directe)
  ou aux demandes exportées. Entité racine, **non récursif** : invisible des comptes clients. L'archivage est
  dans la transaction de la commande : échec d'archivage = commande non passée ; transaction annulée = copie
  du fichier retirée du dossier des documents.
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

### Collecte SNMP (`inc/collect.class.php`, onglet « Collecte SNMP »)

L'absence de remontée est un **état à signaler**, jamais une absence d'alerte : une imprimante dont on ne
lit plus les niveaux ne déclenche aucune alerte toner. Aucune couverture complète du parc n'est supposée.

| État | Condition |
|---|---|
| Jamais remontée | Ni date d'inventaire (`last_inventory_update`) ni consommable remonté |
| Muette | Dernier inventaire plus ancien que `silent_days` jours (3 par défaut) |
| Sans niveau lisible | Inventaire à jour, mais aucun niveau exploitable (sentinelles, OK, valeurs inconnues) |
| Collecte normale | — |

Agents : dernier agent ayant inventorié chaque imprimante (`glpi_rulematchedlogs`), signalé « ne remonte
plus » si son dernier contact dépasse `silent_days` jours, avec le nombre d'imprimantes concernées.
Périmètre : entités de l'utilisateur (droit `dashboard` READ).

### Points d'entrée (ajax/)

| Endpoint | Action |
|---|---|
| *(action de masse « Commander »)* | **Seul parcours de commande directe** : `Alertview::processOrder()` → `createPurchaseOrder()` (expéditions + fichier + mail Achats en transaction), droit validation UPDATE |
| `update_expedition.php` | Marquer expédié (transporteur + tracking) → `markShipped()` |
| `edit_expedition.php`, `reassign_expedition.php` | Édition / réassignation vers une autre imprimante |
| `resolve_alert.php` | Ignorer une alerte « mauvaise imprimante » (suspendre / réactiver : actions de masse de l'écran des alertes) |

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

`##printgestion.printer##`, `client`, `toner`, `level`, `days`, `cartridge`, `stock`, `contract`,
`carrier`, `tracking`, `cartridges_list` (liste HTML détaillée), `printers_list` (liste imprimantes,
courtoisie), `count`, `glpi_url`. Toute balise non fournie est remplacée par une chaîne vide.

---

## 7. Tâches cron (classe `Reminder`)

| Tâche | Fréquence par défaut | Contenu |
|---|---|---|
| `PrintgestionSnapshotReadings` | quotidienne | Snapshot toner + bootstrap cartouches natives + détection changements + purge relevés > 160 j |
| `PrintgestionCheckAlerts` | horaire | Calcul alertes + **digest mail commercial** + **digest rappels installation** + réassignation auto wrong_printer + rebuild `alertview` |
| `PrintgestionTrackingUpdate` | 4 h | BL signés plugin Gestion → delivered + APIs transporteurs (UPS/GLS/Chronopost) |
| `PrintgestionProposeDemandes` | horaire, **enregistrée désactivée** | Demandes d'envoi proposées à partir des alertes, regroupées par client et site (`Demande::proposeFromAlerts()`) |

Les 4 tâches sortent immédiatement (`return 0`) si la feature `toner` est désactivée.
`PrintgestionProposeDemandes` est enregistrée désactivée : une ligne proposée bloque la commande de sa
cartouche depuis l'écran des alertes jusqu'à son export ou son annulation. L'activer quand l'export des
demandes validées est en service. Une mise à jour du plugin ne change pas l'état choisi.

---

## 8. Droits et profils

6 droits (`Profile::initProfile()`, ALLSTANDARDRIGHT au profil ayant `config` UPDATE à l'install) :

| Droit | Protège |
|---|---|
| `plugin_printgestion_contrats` | Dashboard contrats / Liste / Créer Print (CREATE pour créer) |
| `plugin_printgestion_dashboard` | Alertes toner (dashboard + actions) |
| `plugin_printgestion_expedition` | Expéditions (UPDATE pour agir) |
| `plugin_printgestion_validation` | Demandes d'envoi : READ voir, UPDATE modifier / valider / annuler (file aussi visible avec `dashboard` READ, sans agir) |
| `plugin_printgestion_billing` | Coût à la page |
| `plugin_printgestion_config` | Configuration du plugin + mappings SNMP |

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
   courant + vue courante) et `Expedition` (restriction d'entité via l'imprimante liée).
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
