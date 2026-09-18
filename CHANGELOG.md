# Journal des changements

## 1.0.0 — 2026-09-18

Première version publiée. Elle s'installe sur une base vierge du plugin (aucun chemin de mise à jour depuis les
versions de développement : désinstaller d'abord). Testée avec GLPI 11.0.8 et GLPI Inventory 1.6.10.

- **Collecte** : déploiement de GLPI Agent (Windows, Linux, macOS) avec TAG d'entité et règle d'affectation unique,
  assistant de raccordement des imprimantes, fréquence des relevés par entité, conformité et mise à jour des sondes,
  alertes de sondes muettes, contrôle de la remontée.
- **Toner** : relevés SNMP, détection des changements de cartouche, alertes et seuils par imprimante, verrous
  anti-double-envoi, demandes d'envoi proposées, validées, exportées.
- **Commandes** : fichier Gesconso (Sage) construit depuis l'entité (nom = code client, commentaires = intitulé de
  livraison), contrôles avant envoi avec décompte de ce qui mérite d'être vu, transmission aux Achats enregistrée
  avant l'envoi et renvoyable, référentiels Sage (adresses, articles) importés par fichier pour vérification.
- **Expéditions** : suivi des colis GLS (jeton chiffré en cache, tâche horaire, quota, disjoncteur, multi-colis),
  affichage identique pour tous les profils, rien sans clés ; lien avec le plugin Gestion déduit (BL signé).
- **Coût à la page** et facturation par contrat.
- **Configuration** : carte « Santé de la configuration » (prérequis GLPI vérifiés, jamais redéfinis), modules
  activables, gabarits de mail jamais réécrits à la mise à jour, aucun réglage que GLPI possède déjà.
- **Sécurité** : cloisonnement par entité à chaque point d'entrée, secrets chiffrés (GLPIKey) jamais réaffichés,
  harnais de tests rejouable (`tests/`), aucune donnée réelle dans le dépôt.
