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
| `Profile` | Droits du plugin (5 droits, voir §8) |
| `Dashboard` | Dashboard contrats (tuiles, camemberts ECharts, liste Search native) |
| `Contract` | Itemtype « virtuel » sur `glpi_contracts` pour borner la recherche aux contrats liés à ≥1 imprimante |
| `Contractrate` | Tarifs N&B / Couleur par contrat (onglet sur fiche Contract) |
| `Print` | Création/association imprimante ↔ contrat (4 scénarios, transactionnel) |
| `Tonerreading` | Snapshot horodaté des niveaux toner (lit `glpi_printers_cartridgeinfos` SNMP GLPI 11) |
| `Cartridgehistory` | Détection automatique des changements de cartouche (hausse de niveau ≥ `detection_delta` %) |
| `Alert` | Calcul intelligent des alertes toner (vitesse de conso sur fenêtre 30 j) + **digest mail commercial** |
| `Alertview` | Table **matérialisée** des alertes pour le moteur Search natif (rebuild par cron + bouton) |
| `Expedition` | Cycle d'expédition des cartouches, **tous les circuits mail** (planif/achats/courtoisie/rappels) |
| `Snmpmapping` | Mapping constructeur + propriété SNMP → modèle de cartouche + couleur |
| `Cartridgesnmp` | Onglet sur fiche CartridgeItem : binding direct cartouche ↔ propriétés SNMP |
| `Billing` / `Billingview` | Coût à la page + table matérialisée **par utilisateur** (le calcul dépend de la période choisie) |
| `PrinterCostsTab` | Onglet « Coût à la page » sur la fiche imprimante |
| `Tracking` | Intégrations externes : BL signés du plugin Gestion + APIs transporteurs (UPS/GLS/Chronopost) |
| `Reminder` | Les 3 tâches cron GLPI (voir §7) |
| `Dashboardactions` | Assets partagés du menu contextuel des dashboards (modals + JS) |

---

## 3. Modèle de données

Toutes les tables sont créées **à l'installation** (`config.class.php::install()` + `install()` de chaque classe,
`CREATE TABLE IF NOT EXISTS`, idempotent). **Aucune migration à chaud** (voir doc maintenance).

| Table | Contenu |
|---|---|
| `glpi_plugin_printgestion_configs` | Configuration singleton (id=1) : features, seuils, rôles mail, IDs gabarits |
| `glpi_plugin_printgestion_toner_readings` | Snapshots horodatés des niveaux toner (purge > 160 j) |
| `glpi_plugin_printgestion_cartridge_history` | Changements de cartouche détectés |
| `glpi_plugin_printgestion_alerts` | Alertes émises (traçabilité + anti-doublon mail 24 h) |
| `glpi_plugin_printgestion_alert_snoozes` | Mises en sommeil d'alertes (par toner ou par imprimante) |
| `glpi_plugin_printgestion_alertview` | **Matérialisée** : 1 ligne par couple imprimante/toner pour le Search natif |
| `glpi_plugin_printgestion_expeditions` | Expéditions de cartouches (statuts, transporteur, group_id, users) |
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
        │                                        → Expedition::markDeliveredOnInstall()
        │  (cron 2, horaire)
        ▼
Alert : vitesse = (level_t-30 − level_t) / 30 ; jours_restants = level_t / vitesse
        │
        ├─> statuts : ok / watch / critical (seuils config + seuils par imprimante)
        ├─> sendPendingAlerts() : mail commercial DIGEST (1 mail/run, voir §6)
        └─> Alertview::rebuild() : matérialisation pour le dashboard Search natif
```

Résolution de la cartouche à commander pour une propriété SNMP (`Snmpmapping::resolveCartridgeItemForSnmp`) :
binding direct (`cartridge_snmp`) **ou** mapping constructeur (`snmp_mapping`) → `CartridgeItem` GLPI.
Le stock = cartouches du modèle ni installées (`date_use IS NULL`) ni sorties (`date_out IS NULL`).

---

## 5. Cycle d'expédition

```
pending ──(planif saisit transporteur+tracking)──> shipped ──> transit ──> delivered
   └── stock_empty si aucun stock au déclenchement
```

- **Anti-doublon** : tant qu'une expédition est active (pending/shipped/transit/stock_empty),
  aucune nouvelle expédition n'est créée pour le même couple imprimante/toner.
- `group_id` (UUID) relie les expéditions d'un envoi groupé ou d'une commande.
- Passage `delivered` : automatique via détection de changement de cartouche (§4),
  via BL signé du plugin Gestion, ou via API transporteur (cron 3).

### Points d'entrée (ajax/)

| Endpoint | Action |
|---|---|
| `send_cartridge.php` | « Envoyer cartouche » unitaire → `createFromAlert()` |
| `send_group.php` | Envoi groupé multi-toners d'UNE imprimante → `createGroup()` |
| `send_purchase.php` | Commande mono/multi-imprimantes (et multi-clients) → `createPurchaseOrder()` |
| `update_expedition.php` | Marquer expédié (transporteur + tracking) → `markShipped()` |
| `edit_expedition.php`, `reassign_expedition.php` | Édition / réassignation vers une autre imprimante |
| `snooze_alert.php`, `snooze_group.php`, `unsnooze_group.php`, `resolve_alert.php` | Gestion des alertes |

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
| « Envoyer cartouche » (1 cartouche, stock OK) | gabarit unitaire | — | gabarit unitaire | — |
| « Envoyer cartouche » (1 cartouche, stock VIDE) | — | **Excel joint** | gabarit unitaire | — |
| Envoi groupé (N toners, 1 imprimante) | 1 → unitaire ; N → groupé + **Excel joint** | si stock vide : **Excel joint** | même contenu que planif | — |
| Commande (N cartouches, N imprimantes/clients) | case « Planif » : 1 → unitaire ; N → groupé **client par ligne** + **Excel joint** | **Excel joint** (toujours) | — | case « Courtoisie » : regroupé par contact |
| Cron toner bas (horaire) | — | — | **DIGEST** : 1 mail/run | — |
| Cron rappel installation | mode `planif`/`both` : **DIGEST** 1 mail/run | — | mode `commercial`/`both` : même digest | — |
| Marquer expédié | — | — | gabarit unitaire (transporteur + tracking) | — |

Détails d'implémentation (tous dans `expedition.class.php` sauf mention) :

- **`sendPurchaseOrderMail($rows, $requester_uid)`** : point UNIQUE du mail achats.
  Génère l'Excel (`buildPurchaseExcel`, format Gesconso + colonne Stock GLPI, 1 cartouche/ligne),
  l'envoie via `gabarit_achat` (corps synthétique : « N référence(s), détail dans l'Excel joint »),
  fallback mail brut si gabarit non configuré. Fichier temporaire supprimé après envoi.
- **Planif simple/multi** : 1 cartouche → `gabarit_planif` (détail unitaire) ;
  N cartouches → `gabarit_planif_group` avec liste `##printgestion.cartridges_list##`
  (client précisé par ligne pour les commandes multi-clients), plafonnée à 20, + Excel joint.
- **Courtoisie** (`sendOrderCourtesyMails`) : regroupée par **destinataire** (clé = ensemble
  d'emails résolus, trié) — un contact couvrant 3 imprimantes reçoit 1 seul mail listant
  ses 3 imprimantes (`##printgestion.printers_list##`). Destinataires résolus par
  `resolveClientEmailsForPrinter()` : usager de l'imprimante, sinon utilisateurs de l'entité.
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

Les 3 tâches sortent immédiatement (`return 0`) si la feature `toner` est désactivée.

---

## 8. Droits et profils

5 droits (`Profile::initProfile()`, ALLSTANDARDRIGHT au profil ayant `config` UPDATE à l'install) :

| Droit | Protège |
|---|---|
| `plugin_printgestion_contrats` | Dashboard contrats / Liste / Créer Print (CREATE pour créer) |
| `plugin_printgestion_dashboard` | Alertes toner (dashboard + actions) |
| `plugin_printgestion_expedition` | Expéditions (UPDATE pour agir) |
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
