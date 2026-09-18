# Print Gestion — Gestion de flotte d'impression pour GLPI 11

Plugin GLPI de gestion complète d'un parc d'impression sous contrat : suivi des contrats,
alertes toner intelligentes basées sur l'inventaire SNMP, cycle d'expédition des cartouches
avec notifications regroupées, commandes fournisseur avec export Excel, et coût à la page.

> Développé par **JCD Groupe — Joris Reinert** · Licence **GPL v3+** · GLPI **11.0.x**

---

## Fonctionnalités

Trois modules indépendants, activables par interrupteur dans la configuration :

### Gestion contractuelle
- Dashboard des contrats d'impression : tuiles de synthèse, graphiques (statuts, échéances),
  liste avec le moteur de recherche natif GLPI (tri, filtres, colonnes, export).
- Périmètre automatique : seuls les contrats liés à au moins une imprimante sont affichés.
- « Créer Print » : création/association imprimante ↔ contrat en un écran
  (contrat neuf ou existant × imprimante neuve ou existante).
- Tarifs €/page N&B et Couleur par contrat (onglet sur la fiche contrat).

### Gestion toner & expéditions
- Relevés horodatés des niveaux toner depuis l'inventaire SNMP natif de GLPI 11.
- Alertes intelligentes : vitesse de consommation calculée sur 30 jours → estimation des jours
  restants, seuils globaux et par imprimante, snooze par toner ou par imprimante.
- Détection automatique des changements de cartouche (hausse de niveau) → clôture des expéditions.
- Cycle d'expédition complet : `pending → shipped → transit → delivered`, anti-doublon,
  réassignation, suivi transporteurs (UPS / GLS / Chronopost) et liaison BL signés
  (plugin Gestion).
- Commandes fournisseur : fichier **Gesconso** (9 colonnes, code client et adresse de livraison Sage,
  référence article), envoyé aux achats — mono ou multi-imprimantes/clients ; aucune ligne incomplète.
  Export des demandes validées, téléchargement de test sans envoi, archivage de chaque fichier en Document.
- Demandes d'envoi : regroupement par client et site de livraison, lignes sous contrat / hors contrat,
  contrôles avant validation (référence, contrat, prix, verrous anti-double-envoi), aucune suppression,
  historique GLPI natif. Droit dédié `plugin_printgestion_validation`.
- Référentiel Sage importé par dépôt de fichier (clients, adresses de livraison, articles), avec
  prévisualisation et rapport d'écarts. Aucune connexion directe à Sage.

### Coût à la page
- Calcul par imprimante ou par client sur une période choisie (compteurs `printerlogs`
  × tarifs contrat), restitué dans le moteur de recherche natif (tri/export).
- Onglet « Coût à la page » sur la fiche imprimante.

### Notifications mail (regroupées par conception)
- 3 rôles configurables (planification, achats, commercial) + courtoisie client + demandeur en copie.
- **Aucune rafale de mails** : envois multi regroupés (1 mail avec liste plafonnée à 20 lignes,
  détail complet dans l'Excel joint), courtoisie regroupée par contact, crons en digest
  (1 mail par exécution).
- 6 gabarits HTML responsive créés automatiquement, aperçu dans `docs/apercu_gabarits.html`.

---

## Prérequis

| Composant | Version |
|---|---|
| GLPI | 11.0.x (testé 11.0.8 ; la série 11.1 n'est pas testée, `setup.php` la refuse) |
| PHP | 8.2 ou plus récent (testé 8.3), extensions zip, mbstring, intl |
| GLPI Inventory | 1.6.0 ou plus récent (testé 1.6.10) : indispensable au module de collecte, sans effet sur les autres |
| Optionnel | Plugin Gestion (BL signés → expédition livrée), identifiant et secret GLS (suivi des colis) |

---

## Installation

1. Copier le dossier dans `plugins/printgestion/`.
2. GLPI → Configuration → Plugins → **Installer** puis **Activer** « Print Gestion ».
3. Configuration → Plugins → Print Gestion (ou Configuration → onglet Print Gestion) :
   - activer les modules souhaités (contrats / toner / coût) ;
   - configurer les rôles de notification (groupe GLPI ou utilisateurs) ;
   - vérifier seuils d'alerte et délai de rappel ;
   - cocher les types de contrat « consommables inclus » (sans eux, toute ligne est hors contrat).
4. Attribuer les droits par profil : Administration → Profils → onglet Print Gestion.
5. Vérifier les actions automatiques (`PrintgestionSnapshotReadings`, `PrintgestionCheckAlerts`,
   `PrintgestionTrackingUpdate`). `PrintgestionProposeDemandes` (demandes d'envoi proposées) est installée
   désactivée : ne l'activer qu'avec l'export des demandes validées vers les Achats.
6. Configuration → Notifications : choisir les destinataires des notifications « Demande d'envoi »
   (créées inactives), puis les activer. Alertes de fin de contrat : bouton « Activer les alertes de
   contrat natives » dans la configuration du plugin.
7. Importer le référentiel Sage (Print Gestion → Référentiel Sage) avant la première commande : sans code
   client, adresse de livraison et référence article, aucun fichier Gesconso n'est produit.

La mise à jour se fait en remplaçant les fichiers puis « Mettre à jour » dans la liste des
plugins (installation idempotente — les données sont conservées).

---

## Documentation

| Document | Contenu |
|---|---|
| [docs/DOC_TECHNIQUE.md](docs/DOC_TECHNIQUE.md) | Fonctionnement interne : architecture, modèle de données, flux toner, cycle d'expédition, circuits mail, crons, intégration moteur de recherche |
| [docs/DOC_MAINTENANCE.md](docs/DOC_MAINTENANCE.md) | Maintenance : procédure de mise à jour, évolution du schéma, gabarits mail, règles d'envoi, gotchas GLPI 11, dépannage, checklist |
| [docs/apercu_gabarits.html](docs/apercu_gabarits.html) | Aperçu visuel des gabarits mail (régénérable : `php tools/generate_apercu.php`) |

---

## Licence

Ce plugin est distribué sous licence [GPL v3+](https://www.gnu.org/licenses/gpl-3.0.html).

© JCD Groupe — Joris Reinert · https://www.jcd-groupe.fr
