# Module « Déploiement Agent » — résumé de ce qui a été fait

Plugin Print Gestion **1.6.4**, livré sur le serveur (`789cef2`). Rien n'est modifié dans GLPI lui-même : tout est dans le plugin.
Phases 1 à 6, puis les corrections après relecture (points 1 à 5), terminées et testées sur l'instance de test avec une sonde
simulée ; pas encore essayé sur un vrai site client. Le point 6 (contrôle du lot 1 de sécurité) est une restitution, sans correction.

## Où trouver chaque chose

| Écran | Chemin | Droit |
|---|---|---|
| Installeur GLPI Agent (fichiers officiels, dernière version, réglages par défaut) | Gestion > Print Gestion > Collecte SNMP / Déploiement Agent > Installeur GLPI Agent | lecture : Déploiement ; actions : configuration du plugin |
| Raccordements (assistant) | … > Raccordements | Déploiement (modification pour agir) |
| Sondes (sondes muettes, conformité, réglages, PC sonde) | … > Sondes | Déploiement |
| Contrôle de la remontée | … > Contrôle de la remontée | Déploiement |
| Onglet « Déploiement Agent » (dont la fréquence des relevés, bloc 2) | Fiche Entité | Déploiement (modification pour la fréquence) |
| Bloc « Sonde responsable » | Fiche Imprimante (carte « Informations d'inventaire », sinon sous le formulaire) | Déploiement |
| Onglet « Sonde Print Gestion » | Fiche Agent (Administration > Inventaire > Agents) | Déploiement + droit Agent de GLPI |
| Réglages des notifications de sondes, avertissement « Nettoyer les agents » | Configuration > Inventaire > « Nettoyage de l'agent » | Configuration GLPI |
| 2 notifications (modèles, destinataires) | Configuration > Notifications | Configuration GLPI |
| 4 cartes « Print Gestion — Sondes » | Tableau de bord GLPI (ajouter une carte) | Déploiement |
| Tâches automatiques | Configuration > Actions automatiques : `PrintgestionCheckAgentVersion` (hebdomadaire), `PrintgestionSilentProbes` (quotidienne), `PrintgestionCollectSchedule` (toutes les 15 min) | — |

## Corrections après relecture

| Point | Fait | Comment |
|---|---|---|
| 1. Fréquence des relevés par entité | Oui (écart) | Réglage de l'entité (quotidien par défaut, toutes les N heures ou tous les N jours, ou hérité), modifiable avant le paquet, rappelé dans la note et dans le journal du raccordement. Appliqué **côté serveur** (report de la date de début des tâches GLPI Inventory) : les propriétés MSI de fréquence ne valent pas pour l'agent installé en service. |
| 2. `.bat` Windows en deux gestes | Oui | `1-installer-glpi-agent.bat` ne lance que le MSI officiel avec ses propriétés ; `2-facultatif-mise-a-jour-automatique.bat`, lancé à part, pose seulement la tâche planifiée. La note dit que l'étape 2 est facultative et ce qu'on perd en la sautant. |
| 3. Alerte « imprimante qui ne remonte plus » sur les relevés toner | Dépendance notée | À faire avec le moteur d'alertes, après les données du pilote : aujourd'hui un niveau inchangé est écarté et la date d'un relevé n'est pas celle d'une vraie lecture. Noté dans la doc technique et dans le code. |
| 4. Prérequis bloquants en tête de l'assistant | Oui | GLPI Inventory installé, activé, en version prise en charge (1.6.x, validée avec 1.6.10), tâche `taskscheduler` programmée : tout est vérifié avant la première étape, message avec le chemin à suivre, plus d'erreur PHP. |
| 5. Avertissement « Nettoyer les agents » | Oui | Dans le bloc natif : l'action supprime l'agent et son historique ; en rouge si le réglage l'applique avec un délai. |

## Vos demandes → ce qui est fait

### Les 4 manques (§3)

| Demande | Fait | Comment |
|---|---|---|
| Lien d'installation pré-paramétré depuis la fiche Entité | Oui | Onglet « Déploiement Agent » : boutons Windows, Linux, macOS avec le TAG de l'entité et l'URL du serveur |
| Assistant de raccordement | Oui | Page « Raccordements », 5 étapes |
| Agent responsable sur la fiche d'une imprimante SNMP | Oui | Bloc « Sonde responsable » |
| Conformité de version + alerte « agent muet » | Oui | Page « Sondes », onglet Agent, notifications, tableau de bord |

### Onglet Entité (§5.1)

| Demande | Fait | Comment |
|---|---|---|
| Droit dédié `plugin_printgestion_deploiement` | Oui | Distinct de tous les autres droits |
| TAG vide : avertissement bloquant + lien | Oui | Lien vers « Informations avancées » ; TAG invalide ou en double aussi signalé |
| Vérifier la règle TAG sans la créer | Oui | Active ou non, position, règles jouées avant ; jamais créée |
| Agents de l'entité (version, dernier contact, lien fiche native) | Oui | Plus : TAG différent, modules réseau installés ou non |
| 3 boutons, fichier servi par le plugin, commande exacte, note d'une page | Oui | Fichiers officiels récupérés par le serveur et vérifiés (empreinte GitHub) ; le client ne télécharge rien sur GitHub |
| Version servie affichée | Oui | Titre du bloc 2 |
| Bloc 3 : lien vers l'assistant | Oui | « Nouveau raccordement », désactivé avec la raison si un prérequis de GLPI Inventory manque |

### Assistant de raccordement (§5.2)

| Étape | Fait | Comment |
|---|---|---|
| Prérequis de GLPI Inventory | Oui | Vérifiés en tête, avant l'étape 1 (correction 4) |
| 1. Agent présent : contact récent, PC hôte, module Network inventory, bouton statut | Oui | Absence de `feat_NETINV` dite explicitement ; bouton statut natif ; geste sur place `http://127.0.0.1:62354` si GLPI ne joint pas la sonde (NAT) |
| 2. IP ou plage, lieu hiérarchique, commentaire, contrat, en attente | Oui | « FC Metz > Bâtiment B > … » crée la hiérarchie dans l'entité ; rien n'est appliqué à ce stade |
| 3. Credential SNMP, plage IP, modules, tâches et jobs, sans doublon | Oui | Existant réutilisé, chevauchement refusé, tout ou rien ; format des jobs copié d'un job créé à la main |
| 4. Déclencher et vérifier par IP (trouvée / pas de SNMP / mauvaise entité / sans niveaux) | Oui | Mauvaise entité : message clair et rappel du transfert manuel |
| 5. Lieu, commentaire, contrat seulement sur les imprimantes remontées + verrou | Oui | Vérifié : l'inventaire SNMP écrase le lieu, donc verrou natif posé |
| Journal horodaté consultable | Oui | Un journal par raccordement, avec la fréquence des relevés retenue |

### Fiche Imprimante (§5.3)

| Demande | Fait | Comment |
|---|---|---|
| Sonde responsable : nom, lien fiche native, version, dernier contact, dernier inventaire réussi | Oui | Lien seulement avec le droit Agent ; date du vrai inventaire réseau, pas de la découverte |

### Fiche Agent (§5.4)

| Demande | Fait | Comment |
|---|---|---|
| Conformité : installée / dernière connue / à jour ou non | Oui | Plus la version visée (cible si épinglée) |
| « Mise à jour automatique » (cochée par défaut) + « Version cible » | Oui | Version cible = épinglage, couvre le retour arrière |
| Liste des imprimantes scannées | Oui | Avec IP et dernier inventaire réseau |
| Rien de ce que la fiche native affiche déjà | Oui | |

### Mise à jour des agents (§5.5)

| Demande | Fait | Comment |
|---|---|---|
| Connaître la dernière version (en ligne, sinon saisie) | Oui | GitHub chaque semaine ; une saisie manuelle prime |
| Conformité par agent + compteur sur le tableau de bord | Oui | Page « Sondes » + carte « Sondes à mettre à jour » |
| Réglage par agent avec version cible | Oui | Onglet Agent ou page « Sondes » |
| Windows : tâche planifiée mensuelle winget | Oui | Posée par l'étape 2 facultative du paquet (fichier séparé) ; compte SYSTEM, seulement si l'agent est en attente |
| Linux : tâche cron mensuelle | Oui (écart) | Installeur officiel relancé plutôt que l'AppImage, voir Écarts |
| Réglage appliqué « au prochain contact de l'agent » | Écart | Impossible sans Deploy ni jeton : « consigne » à lancer sur le PC |
| Dire que décocher ne désactive pas une tâche posée | Oui | Écrit sur l'onglet, la page Installeur et dans les notes des paquets |
| Jamais la tâche Deploy | Oui | |

### Alertes « agent muet » (§5.6)

| Demande | Fait | Comment |
|---|---|---|
| Réglages dans « Agent cleanup » via le hook `STALE_AGENT_CONFIG` | Oui | 2 réglages Oui/Non ; le délai vient du plugin (écart) ; avertissement sur « Nettoyer les agents » |
| Carte : agents sans contact depuis N jours, par entité | Oui | Page « Sondes » + carte du tableau de bord |
| Notification native, modèle éditable, destinataires paramétrables | Oui | 2 événements, notifications créées inactives, destinataire par défaut l'administrateur GLPI |
| Distinguer sonde muette et imprimante qui ne remonte plus | Oui | 2 alertes distinctes ; les postes de travail ne déclenchent rien ; seuil porté à la fréquence de relevé de l'entité |
| Piège de la découverte : vérifier la fraîcheur des niveaux | Oui (limite) | Date du vrai inventaire réseau + « aucun niveau lisible » ; voir Écarts et correction 3 |

### PC sonde (§2.8)

| Demande | Fait | Comment |
|---|---|---|
| Distinguer le PC sonde du parc du client | Oui | Statut GLPI au choix ; « Marquer ce PC comme sonde » sur la page « Sondes » |

### Sécurité (§4)

| Demande | Fait | Comment |
|---|---|---|
| Aucun jeton, API, mot de passe ni secret | Oui | Les paquets ne contiennent que l'URL du serveur et le TAG |
| Pas d'interface maison, pas de PowerShell | Oui | Windows : MSI officiel lancé seul par un `.bat` d'une action ; Linux : installeur officiel ; macOS : pkg officiel + `local.cfg` |
| Jeton d'enrôlement « au cas où » | Non fait (voulu) | |

### Linux et macOS (phase 6)

| Demande | Fait | Comment |
|---|---|---|
| Linux : installeur Perl officiel lancé avec les bons paramètres | Oui | `.tar.gz` + `sudo sh installer-glpi-agent.sh` ; réglages dans `/etc/glpi-agent/conf.d` |
| macOS : pkg officiel + `local.cfg` + procédure affichée, pas de `.command` | Oui | ZIP avec les 2 paquets (puce Apple, Intel) ; 3 commandes Terminal affichées ; mise à jour manuelle |

### Modules et droits (§6 et vos autres demandes)

| Demande | Fait | Comment |
|---|---|---|
| Déploiement Agent : droit à part, interrupteur de module, onglets seulement si module actif + droit | Oui | Droits revérifiés à l'affichage |
| « Contrôle de la remontée » sorti de la gestion toner | Oui | Dans le module Déploiement, droit Déploiement |
| « Référentiel Sage » : module et droit à part | Oui | Droit `plugin_printgestion_sage` |
| Onglet « Coût à la page » de l'imprimante : droit à part (facturation, commerciaux) | Oui | Droit `plugin_printgestion_billing` ; seuils d'alerte dans leur propre onglet |

### Points à vérifier (§7)

| Point | Résultat |
|---|---|
| 1. Les imprimantes SNMP héritent du TAG de la sonde | Vérifié dans le code et en test simulé ; à confirmer au pilote |
| 2. L'inventaire SNMP écrase le lieu | Vérifié : oui, d'où le verrou de l'étape 5 |
| 3. Format `targets` / `actors` des jobs | Vérifié sur un job créé à la main |
| 4. Signature du `.pkg` macOS | Vérifié sur un Mac : signé Teclib, notarisé, accepté par macOS |

### Ce que vous ne vouliez pas voir (§9) — tout est respecté

- Onglet qui réaffiche la fiche Agent : non, seulement conformité, réglages et imprimantes.
- Secret dans un fichier téléchargé : non.
- Interface PowerShell qui appelle le MSI : non.
- Plugin ToolBox de l'agent activé : non.
- Mise à jour de l'agent par la tâche Deploy : non.
- Plage, tâche ou credential en double : non, l'existant est vérifié.
- Lieu ou commentaire sur une imprimante pas encore remontée : non.
- Règle d'affectation d'entité créée par le plugin : non, seulement vérifiée.

## Écarts à valider

1. **« Au prochain contact de l'agent »** : impossible sans la tâche Deploy ni jeton. Le changement de réglage s'applique en lançant la consigne de la sonde sur le PC.
2. **Délai des alertes** : « Imprimante muette après (jours) » du plugin, pas le délai natif « Nettoyage de l'agent », dont l'action par défaut « Nettoyer les agents » supprime les agents (avertissement affiché dans ce bloc).
3. **Linux** : mise à jour par l'installeur Perl officiel relancé (il garde la configuration), pas par l'AppImage (FUSE requis, double installation). Le mode interactif ne pose pas de questions quand le serveur et le TAG sont déjà donnés.
4. **Fraîcheur des niveaux** : GLPI ne date un niveau que quand sa valeur change ; on se fie au vrai inventaire réseau. Un inventaire reçu sans consommables garde les anciennes valeurs : à mesurer au pilote (voir correction 3).
5. **Fréquence des relevés** : appliquée par le serveur, pas par l'agent. Elle ne peut pas descendre sous le délai de contact de GLPI (« fréquence d'inventaire », globale, 24 h par défaut), et ne porte que sur la découverte et l'inventaire réseau, pas sur l'inventaire du PC sonde.
6. **Linux** : le script d'installation pose encore la tâche cron dans le même geste (la séparation demandée ne visait que le `.bat` Windows).

## À tester au pilote

- Vraie sonde Windows : les deux gestes, raccordement complet, puis mise à jour winget sous le compte SYSTEM (journal `C:\ProgramData\PrintGestion\glpi-agent-update.log`) et accueil des deux `.bat` par l'antivirus du client.
- Fréquence des relevés autre que quotidienne, sur une vraie sonde.
- Retour à une version plus ancienne (l'installeur peut le refuser).
- Vraie sonde Linux et vrai Mac.
- Imprimante qui ne remonte plus, sur de vraies données.

## Sur le serveur, dans l'ordre

1. Plugins : Print Gestion, « Mettre à jour » (1.6.4) puis « Activer ».
2. Installer et activer GLPI Inventory (1.6.x) ; vérifier que sa tâche `taskscheduler` est programmée et que le cron de GLPI tourne ; activer l'inventaire ; créer la règle d'affectation par TAG.
3. Installeur GLPI Agent : récupérer les fichiers Windows, Linux et macOS ; choisir le statut « PC sonde » (à créer dans Intitulés).
4. Configuration > Inventaire > Nettoyage de l'agent : réglages Print Gestion (ne pas choisir « Nettoyer les agents » avec un délai court) ; Configuration > Notifications : choisir les destinataires puis activer.
5. Pour chaque client : onglet « Déploiement Agent » de l'entité, régler la fréquence des relevés avant de télécharger le paquet.

Détails : `DOC_TECHNIQUE.md` (fonctionnement) et `DOC_MAINTENANCE.md` (procédures et dépannage).
