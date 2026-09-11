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
  envois groupés, réassignation, suivi transporteurs (UPS / GLS / Chronopost) et liaison
  BL signés (plugin Gestion).
- Commandes fournisseur : génération d'un fichier **Excel** (format Gesconso + stock GLPI),
  envoyé aux achats — mono ou multi-imprimantes/clients.

### Coût à la page
- Calcul par imprimante ou par client sur une période choisie (compteurs `printerlogs`
  × tarifs contrat), restitué dans le moteur de recherche natif (tri/export).
- Onglet « Coût à la page » sur la fiche imprimante.

### Notifications mail (regroupées par conception)
- 3 rôles configurables (planification, achats, commercial) + courtoisie client + demandeur en copie.
- **Aucune rafale de mails** : envois multi regroupés (1 mail avec liste plafonnée à 20 lignes,
  détail complet dans l'Excel joint), courtoisie regroupée par contact, crons en digest
  (1 mail par exécution).
- 6 gabarits HTML responsive créés automatiquement, aperçu dans `apercu_gabarits.html`.

---

## Prérequis

| Composant | Version |
|---|---|
| GLPI | 11.0.x |
| PHP | 8.2+ (testé 8.3) |
| Inventaire | Remontée SNMP des cartouches (`glpi_printers_cartridgeinfos`) pour le module toner |
| Optionnel | Plugin Gestion (liaison BL signés), clés API UPS/GLS/Chronopost |

---

## Installation

1. Copier le dossier dans `plugins/printgestion/`.
2. GLPI → Configuration → Plugins → **Installer** puis **Activer** « Print Gestion ».
3. Configuration → Plugins → Print Gestion (ou Configuration → onglet Print Gestion) :
   - activer les modules souhaités (contrats / toner / coût) ;
   - configurer les rôles de notification (groupe GLPI ou utilisateurs) ;
   - vérifier seuils d'alerte et délai de rappel.
4. Attribuer les droits par profil : Administration → Profils → onglet Print Gestion.
5. Vérifier les 3 actions automatiques (`PrintgestionSnapshotReadings`, `PrintgestionCheckAlerts`,
   `PrintgestionTrackingUpdate`).

La mise à jour se fait en remplaçant les fichiers puis « Mettre à jour » dans la liste des
plugins (installation idempotente — les données sont conservées).

---

## Documentation

| Document | Contenu |
|---|---|
| [docs/DOC_TECHNIQUE.md](docs/DOC_TECHNIQUE.md) | Fonctionnement interne : architecture, modèle de données, flux toner, cycle d'expédition, circuits mail, crons, intégration moteur de recherche |
| [docs/DOC_MAINTENANCE.md](docs/DOC_MAINTENANCE.md) | Maintenance : procédure de mise à jour, évolution du schéma, gabarits mail, règles d'envoi, gotchas GLPI 11, dépannage, checklist |
| [apercu_gabarits.html](apercu_gabarits.html) | Aperçu visuel des gabarits mail (régénérable : `php tools/generate_apercu.php`) |

---

## Licence

Ce plugin est distribué sous licence [GPL v3+](https://www.gnu.org/licenses/gpl-3.0.html).

© JCD Groupe — Joris Reinert · https://www.jcd-groupe.fr
