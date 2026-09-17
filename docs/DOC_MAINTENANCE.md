# Print Gestion — Documentation de maintenance

> Comment maintenir, mettre à jour et dépanner le plugin sans casser l'existant.
> Pour comprendre le fonctionnement interne, lire d'abord [DOC_TECHNIQUE.md](DOC_TECHNIQUE.md).

---

## 1. Mettre à jour le plugin (procédure standard)

1. Déployer les fichiers dans `plugins/printgestion/` (remplacer le dossier).
2. Si `PLUGIN_PRINTGESTION_VERSION` (`setup.php`) a changé, GLPI désactive le plugin et propose
   **« Mettre à jour »** (Configuration → Plugins → Print Gestion). Cela rejoue
   `plugin_printgestion_install()` :
   - joue les **étapes de migration de schéma** non encore appliquées (voir §2) ;
   - réenregistre les 3 crons ;
   - **réécrit les gabarits mail** (voir §3 — écrase les éditions manuelles fr_FR).
   Une erreur SQL pendant la migration **fait échouer la mise à jour** (message affiché) : corriger
   la cause puis relancer, l'étape en échec est rejouée.
3. Incrémenter le **jeton anti-cache** des assets si `public/css/*` ou `public/js/*` a changé :
   dans `setup.php`, variable `$cb = '?b=N'` → passer à `N+1`. Sinon les navigateurs gardent
   l'ancien JS/CSS en cache.
4. Vérifier le bouton **« Qui est notifié ? »** dans la configuration (récapitulatif des
   notifications selon la config réellement enregistrée — signale les gabarits non configurés).

---

## 2. Schéma BDD : migrations versionnées

Le schéma est versionné par `inc/schema.class.php` :

- la version installée est stockée dans `glpi_configs` (contexte `plugin:printgestion`, clé
  `schema_version`) ;
- `PluginPrintgestionSchema::STEPS` liste les étapes dans l'ordre (`version => méthode`) ;
- à chaque installation / « Mettre à jour », seules les étapes dont la version est supérieure à la
  version installée sont jouées ; la version n'est enregistrée qu'après la réussite de l'étape ;
- l'étape **1.0.0** est le schéma de référence historique (`Config::installSchemaBaseline()`) : elle
  couvre une installation neuve comme une installation antérieure au versionnement ;
- si la base est plus récente que le code déployé, l'installation s'arrête avec un message explicite.

**Faire évoluer le schéma (table, colonne, index, donnée) :**

1. écrire une méthode `migrateToXYZ(Migration $migration)` **idempotente** dans `schema.class.php`,
   en passant par l'API `Migration` de GLPI (`addField`, `changeField`, `addKey`…) ;
2. l'ajouter **à la fin** de `STEPS` ;
3. **incrémenter `PLUGIN_PRINTGESTION_VERSION`** dans `setup.php` (sinon GLPI ne propose pas la mise
   à jour et l'étape n'est jamais jouée) ;
4. ne jamais modifier une étape déjà livrée ni `installSchemaBaseline()`.

**Interdit** : `ALTER TABLE` à la main sur l'instance, désinstaller / réinstaller pour faire évoluer le
schéma (**destructif** : toutes les données métier du plugin sont perdues), et tout `try/catch` qui
masque une erreur de migration.

- **⚠️ Gotcha vécu** : un bloc « cleanup d'anciennes tables » ne doit JAMAIS contenir une table
  vivante. `..._billing_view` (table ACTIVE) avait été listée dans un drop → créée puis droppée à
  chaque install. Tables vivantes à ne jamais dropper : `_alertview` et `_billing_view`.

---

## 3. Gabarits mail : source de vérité et cycle de vie

- **La source de vérité est le code** : `hook.php → plugin_printgestion_template_definitions()`.
  Les gabarits en base (`glpi_notificationtemplates`, marqueur
  `comment = 'Created by plugin printgestion'`) sont **réécrits** (sujet + contenu fr_FR)
  à chaque install/« Mettre à jour ».
- Donc : **ne pas modifier les gabarits dans l'interface GLPI** (perdu à la prochaine update) —
  modifier `hook.php`, puis « Mettre à jour » le plugin.
- Après toute modification de gabarit, **régénérer l'aperçu** :

  ```bash
  cd plugins/printgestion
  php tools/generate_apercu.php     # → apercu_gabarits.html à la racine du plugin
  ```

  Le générateur lit les définitions réelles (aucune BDD requise) ; si un nouveau gabarit ou une
  nouvelle variante d'envoi apparaît, ajouter un jeu d'exemple dans `$variants` du script.

### Ajouter une balise mail

1. L'ajouter au tableau `$defaults` de `Config::sendMail()` (valeur vide par défaut —
   une balise non fournie ne doit jamais rester visible dans un mail).
2. La renseigner dans le(s) appelant(s) (`expedition.class.php`, `alert.class.php`).
3. L'utiliser dans le gabarit (`hook.php`) ; mettre à jour l'aperçu.

### Ajouter un nouveau gabarit

1. Ajouter l'entrée dans `plugin_printgestion_template_definitions()` (clé = nom du champ config).
2. Ajouter la colonne `gabarit_xxx` dans la table configs (voir §2 — mécanisme d'ajout de colonnes
   de config) ; `create_templates()` stocke automatiquement l'ID créé dans ce champ.
3. L'exposer dans l'UI de config si nécessaire (`config.class.php`, section Rôles & notifications).

---

## 4. Règles à respecter pour tout NOUVEL envoi de mail

Décisions projet (à ne pas régresser) :

1. **Jamais N mails identiques** : si un événement concerne plusieurs items, envoyer UN mail
   groupé/digest par destinataire (cf. courtoisie regroupée par contact, digests cron).
2. **Liste plafonnée** : toute liste dans un corps de mail passe par
   `Expedition::MAIL_LIST_MAX` (20) avec ligne « … et N autres ».
3. **Le détail complet va dans l'Excel joint**, pas dans le corps :
   réutiliser `Expedition::buildPurchaseRowData()` + `buildPurchaseExcel()` ; pour les achats,
   passer EXCLUSIVEMENT par `sendPurchaseOrderMail($rows, $requester_uid)`.
4. **Toujours supprimer le fichier Excel temporaire** après envoi (`@unlink`).
5. Envoi simple (1 item) = balises unitaires détaillées ; multi = agrégats
   (« 4 clients », « 6 imprimantes ») + liste.
6. Anti-doublon : les crons tracent dans `glpi_plugin_printgestion_alerts` (24 h par item) —
   conserver ce mécanisme PAR ITEM même dans un digest.

---

## 5. Gotchas GLPI 11 connus (vécus sur ce plugin)

| Symptôme | Cause | Fix |
|---|---|---|
| `TypeError: getClassForItemtype(null)` dans le Search | Nom de table à underscore (`..._billing_view`) → `getItemTypeForTable()` déduit une mauvaise classe | Enregistrer le mapping dans `setup.php` : `$CFG_GLPI['glpiitemtypetables'][table] = classe` + réciproque. À faire pour TOUTE nouvelle table dont le nom splitté sur `_` ne reforme pas la classe |
| Table « doesn't exist » juste après réinstallation | Bloc cleanup d'install qui droppe une table vivante | Ne jamais lister `_alertview` / `_billing_view` dans un drop (§2) |
| « Accès refusé » (et « CSRF check failed » dans `access-errors.log`) après un clic sur un bouton lent, alors que l'action a abouti | Double clic : le second envoi réutilise le jeton CSRF déjà consommé par le premier | Mettre `data-pg-submit-once='1'` sur le bouton : `printgestion.js` n'envoie le formulaire qu'une fois (boutons de la page Installeur GLPI Agent, import Sage, renvoi aux Achats). Incrémenter le jeton anti-cache `?b=` de `setup.php` |
| `AccessDeniedHttpException` sur un POST ajax | Double validation CSRF : le `CheckCsrfListener` de GLPI 11 valide et CONSOMME le token avant le fichier ajax | Ne PAS appeler `Session::checkCSRF()` dans les fichiers `ajax/` (commentaire en tête des endpoints `ajax/` qui écrivent) |
| Formulaire qui casse après `showFormButtons()` | `Html::closeForm()` ajouté après `showFormButtons()` (qui ferme déjà le form) | Ne pas doubler la fermeture |
| XSS : un nom d'imprimante ou un n° de suivi contenant `</script>` exécute du code | Données PHP écrites dans un `<script>` avec `json_encode()` sans `JSON_HEX_TAG` : `</script` ferme la balise (même dans un `<script type="application/json">`) | Toute donnée PHP destinée au JavaScript passe par `PluginPrintgestionUi::jsonData($id, $data, $globale)` : bloc JSON non exécuté, encodé avec les quatre drapeaux `JSON_HEX_*`, relu par `JSON.parse()`. Jamais `window.X = <?= json_encode() ?>` ni un `{$json}` dans un script |

---

## 6. Dépannage

### Carte « Santé de la configuration » (à ouvrir en premier)

En tête de Configuration → Print Gestion (droit de configuration du plugin). Contrôles automatiques, relus à chaque
affichage ; la carte ne bloque rien. Tout vert : une ligne « Configuration : complète ».

| Contrôle | Rouge quand | Corriger |
|---|---|---|
| URL de l'application GLPI (obligatoire) | vide, `localhost` ou `127.x`, sans `https://`, ou nom sans domaine qui ne se résout pas : un agent déployé avec ne joindra jamais GLPI et ne se répare pas à distance ; le téléchargement des installeurs est bloqué. Syntaxe correcte mais aucun agent n'a encore remonté depuis qu'elle est en place : « jamais confirmée » (horloge), la carte reste dépliée jusqu'au premier agent qui remonte — seule preuve qu'elle est joignable depuis un réseau client | Configuration → Générale, « URL de l'application » |
| Inventaire GLPI activé (obligatoire) | « Activer l'inventaire » décoché | Administration → Inventaire |
| Plugin GLPI Inventory (obligatoire) | absent, inactif, version trop ancienne, fichiers ou tâche `taskscheduler` manquants | Configuration → Plugins → Marketplace |
| Actions automatiques du plugin en mode CLI avec un cron système (obligatoire) | aucun cron système prouvé (tâche témoin `PrintgestionTemoinCron`, CLI seulement, chaque minute, jamais passée ou pas depuis 15 min, et `GLPI_SYSTEM_CRON` non déclaré), ou une tâche du plugin en mode Interne ; les tâches de GLPI ne sont pas jugées, `queuednotification` est affichée en lecture | sur le serveur : crontab `* * * * * php <GLPI>/front/cron.php`, puis `define('GLPI_SYSTEM_CRON', true);` dans `config/local_define.php` ; dès que le témoin est passé, la carte propose « Passer les N tâches de Print Gestion en CLI » (clic explicite, jamais automatique) ; chaque tâche a son lien « Configurer dans GLPI » |
| Type de document xlsx autorisé (obligatoire) | extension xlsx sans « Autoriser l'import » : Gesconso non archivé, aucune commande | Configuration → Intitulés → Types de document |
| Règle d'affectation par TAG présente et active (obligatoire) | règle absente ou désactivée | bouton de la carte (créer ou activer) |
| Sauvegarde de `config/glpicrypt.key` (recommandé) | jamais : non vérifiable automatiquement, rappel permanent | sauvegarde du serveur, avec la base |
| Journal du plugin inscriptible (recommandé) | dossier ou fichier non inscriptible | droits du serveur web sur `files/_log` |

Le mode CLI est le prérequis le plus souvent négligé : en mode « GLPI », les actions ne tournent que quand quelqu'un
navigue (relevés irréguliers, alertes en retard, commande non transmise jamais signalée).

### Journal applicatif (à consulter en premier)

Vérifier qu'il s'écrit vraiment : Configuration → Print Gestion → carte « Santé de la configuration », ligne « Journal du plugin inscriptible » → « Écrire une entrée
de test et la relire ». « Non inscriptible » ou échec du test : droits d'écriture du compte du serveur web sur
`files/_log` (ou disque plein) ; en attendant, chaque trace part dans le journal d'erreurs du serveur web, préfixée
`[printgestion] journal … non inscriptible`. Un fichier `printgestion.log` absent n'est normal que si la carte
indique « Inscriptible ».


Toute erreur interceptée par le plugin est écrite dans **`files/_log/printgestion.log`**, avec le
niveau `[ERREUR]` ou `[AVERTISSEMENT]` et le contexte (`Classe::méthode` ou nom d'endpoint) :
échecs d'envoi de mail (y compris depuis les tâches automatiques), API Sage des BL indisponible
(tout code HTTP autre que 404), lots de relevés toner non insérés, suivi transporteur en échec.
Les erreurs techniques d'une commande (transaction annulée) figurent en plus dans le journal
d'erreurs GLPI (`files/_log/php-errors.log`). **Aucun `catch` ne doit rester muet** : toute
nouvelle interception passe par `PluginPrintgestionLogger`.

### « Entité de lignes corrigée » dans le journal (tâche `PrintgestionEntityScope`)

Chaque donnée technique (relevé, rendement, pose, seuil, mise en veille, tarif) porte l'entité de son imprimante ou
de son contrat, et GLPI s'en sert pour cloisonner les clients. La tâche quotidienne recale les écarts et les journalise
en `[ERREUR]` avec les tables et le nombre de lignes. Hors reprise de données à la main en base, un écart signale un
bug : un chemin d'écriture du plugin qui ne pose pas l'entité. Noter la table, chercher son insertion dans le code
(elle doit passer par `PluginPrintgestionEntityscope`), corriger. Un transfert d'imprimante ou de contrat ne produit
pas d'écart : les lignes techniques suivent au moment du transfert.

Les données commerciales (envois, alertes, liaisons BL, demandes) ne sont jamais recalées : leur entité est celle de
leur création. Une expédition restée chez le client A alors que son imprimante est passée chez B est normale et ne
doit pas être « corrigée » en base : c'est l'historique commercial de A.

### Lignes sans objet de rattachement (configuration du plugin)

La carte « Lignes sans objet de rattachement » de la configuration (comptes de l'entité racine), le journal de la
tâche `PrintgestionEntityScope` (« Lignes orphelines ») et la migration 1.6.6 listent les lignes dont l'imprimante,
l'expédition, la demande ou le contrat a été purgé alors que leur entité n'était pas connue. Elles sont à l'entité
racine, non récursives : invisibles des comptes clients. Le plugin ne les rattache ni ne les supprime. Pour trancher :
retrouver le client (numéro de suivi, date, référence, BL, historique GLPI), puis soit renseigner l'entité en base
(`UPDATE <table> SET entities_id = <entité du client> WHERE id = …`), soit les laisser à la racine si elles ne
concernent aucun client. Ne jamais supprimer une expédition ni une demande.

### Les mails ne partent pas

1. Config GLPI → Notifications : mode mail activé + serveur SMTP fonctionnel (tester avec une
   notification native).
2. Plugin → Configuration → bouton **« Qui est notifié ? »** : vérifie rôles résolus
   (groupe/users avec email par défaut) et gabarits configurés (alerte rouge si manquant).
3. Gabarit à 0 dans la config (`gabarit_xxx`) → relancer « Mettre à jour » (recrée et re-stocke
   les IDs).
4. Crons : Configuration → Actions automatiques → `PrintgestionCheckAlerts` (logs de la tâche :
   volumes « Alertes: X — Rappels: Y »). Vérifier que la feature `toner` est activée
   (cron sort en silence sinon).
5. Anti-doublon : un mail déjà parti < 24 h pour le même item ne repart pas
   (table `glpi_plugin_printgestion_alerts`).

### Les alertes toner sont vides / fausses

- Vérifier que l'inventaire SNMP GLPI remonte bien `glpi_printers_cartridgeinfos`.
- Cron `PrintgestionSnapshotReadings` exécuté ? (il faut ~30 j de relevés pour une vitesse fiable ;
  en-deçà, le calcul utilise la fenêtre disponible).
- Écran des alertes : bouton « Recalculer maintenant » (`Alertview::rebuild()`) ; un bandeau signale les
  actions (commandes, annulations…) pas encore reflétées depuis le dernier calcul complet.
- Seuils : config globale + seuils par imprimante (`printer_thresholds`), réglés dans l'onglet « Seuils d'alerte »
  de la fiche imprimante (droit Alertes toner : lecture pour voir, modification pour enregistrer). L'onglet
  « Coût à la page » de la fiche imprimante exige le droit Coût à la page : le droit sur les imprimantes ne suffit pas.

### Aucune demande d'envoi proposée

- Tâche `PrintgestionProposeDemandes` : installée **désactivée** (bandeau sur l'écran Demandes d'envoi).
- Seuls les toners critiques ou à surveiller, non snoozés et sans verrou bloquant sont proposés : un envoi
  en cours, une ligne déjà ouverte, une garde après pose ou un ticket récent excluent l'emplacement.
- Une ligne annulée (à la main, avec sa demande ou avec son expédition) écarte l'emplacement pendant 30 jours,
  sauf pose détectée ou confirmée depuis : compteur « Écartés (annulés < 30 j) » du journal de la tâche. La
  commande directe depuis l'écran des alertes reste possible.
- Un site dont les toners sont seulement « à surveiller » n'est pas proposé : il faut un toner critique sur le
  site, ou une demande déjà proposée à compléter. Un toner à surveiller sans cartouche résolue n'est jamais
  ajouté. Compteur « À surveiller en attente » du journal de la tâche.
- Tâche en « Erreur d'exécution » (Configuration → Actions automatiques) : au moins un groupe client/site a échoué ;
  « Groupes en échec » dans le journal de la tâche, cause de chaque groupe dans `files/_log/printgestion.log`. Les
  autres groupes ont bien été proposés ; le groupe en échec est retenté au passage suivant.
  (contexte `demandes`) ; le groupe est annulé en entier et retenté au passage suivant.

### Importer ou mettre à jour le référentiel Sage

1. Exporter de Sage un fichier par référentiel (clients, adresses de livraison, articles) avec les colonnes
   décrites dans la doc technique (« Référentiel Sage ») ; l'écran de dépôt les rappelle.
2. Print Gestion → Référentiel Sage → Import du référentiel : déposer, **analyser**, lire le rapport d'écarts, valider.
3. Ordre conseillé : clients (et correspondances entités), puis adresses, puis articles.
4. Corriger les écarts dans GLPI : champ « Code » des lieux, référence des cartouches, correspondance
   entité ↔ client (onglet « Print Gestion — Sage » de l'entité), puis relancer l'analyse pour vérifier.

Une ligne « absente du dernier import » n'est pas perdue : elle redevient active si elle réapparaît.

### Déployer GLPI Agent chez un client (Windows, Linux, macOS)

Une fois pour tout le parc :

1. Installer et activer le plugin **GLPI Inventory avant de déployer les sondes** : c'est lui qui donne aux
   sondes les plages IP à scanner. L'adresse donnée aux agents est l'URL de l'application GLPI (Configuration →
   Générale), indépendante du plugin : un agent déjà installé n'est pas à reprendre si le plugin change. Versions
   : 1.6.0 minimum (bloquant en dessous), validée avec 1.6.10 ; plus récente, simple avertissement, avec sa tâche
   automatique `taskscheduler` programmée et le cron de GLPI qui tourne.
2. Créer la règle d'affectation d'entité par TAG (Administration → Règles → Règles d'affectation d'un élément
   à une entité) : critère « Tag d'inventaire » vérifie l'expression régulière `/^(.*)$/`, action « Entité depuis
   TAG » = `#0`. Ou, en administrateur : bouton « Créer la règle d'affectation par TAG » (onglet Déploiement Agent
   d'une entité, bloc 1, ou page Installeur GLPI Agent, carte Prérequis), ou simplement « Créer le TAG et la règle
   d'affectation » sur la première entité sans TAG : une seule règle pour tous les clients, créée en dernière
   position ; si d'autres règles actives passent avant, le chevron du bloc 1 les nomme : les vérifier ou déplacer la
   nouvelle. Une règle présente mais **désactivée** compte comme absente (elle n'affecte rien) : état rouge « Règle
   d'affectation présente mais désactivée », et bouton « Activer la règle » (ou « Créer le TAG et activer la règle
   d'affectation ») à la place du bouton de création ; les boutons ne disparaissent qu'avec une règle active. Un
   technicien qui crée un TAG sans règle active n'obtient que le TAG, avec le message « l'administrateur doit la
   créer » ou « doit l'activer ».
3. Print Gestion → Collecte SNMP / Déploiement Agent → Installeur GLPI Agent : « Récupérer depuis GitHub » pour
   chaque fichier utile (MSI Windows ; installeur Perl Linux ; les deux paquets macOS). Sans accès Internet sur le
   serveur : déposer le fichier dans le dossier indiqué, choisir lequel, puis vérifier son empreinte.
4. Même page : vérifier « Dernière version connue » (GitHub, sinon saisie), choisir si les nouveaux paquets posent la
   mise à jour automatique, et le statut GLPI des PC sondes (à créer d'abord : Configuration → Intitulés → Statuts des
   éléments, à la racine, récursif).
5. Notifications des sondes : Configuration → Inventaire → « Agent cleanup », réglages « Print Gestion » ; puis
   Configuration → Notifications → « Print Gestion - Sonde GLPI Agent sans contact » et « Print Gestion - Imprimantes
   qui ne remontent plus » : choisir les destinataires, activer. Ne pas choisir l'action native « Nettoyer les agents »
   (action par défaut) avec un délai court, ce que l'écran affiche en rouge : elle supprime l'agent et son historique ;
   le PC revient comme un nouvel agent, hors des tâches GLPI Inventory de ses raccordements et sans ses réglages Print
   Gestion. Une sonde éteinte pendant des congés serait ainsi effacée.

Pour chaque client :

1. Fiche de l'entité → Informations avancées : renseigner le TAG (lettres, chiffres, point, tiret, soulignement ;
   unique), **avant le premier inventaire** : les règles d'entité ne jouent qu'au premier import.
2. Onglet « Déploiement Agent » de l'entité : corriger ce qui n'est pas vert (le téléchargement reste bloqué tant que
   le TAG manque, que la règle d'affectation par TAG est absente ou désactivée, ou que l'URL de l'application GLPI
   est vide ou locale : une imprimante remontée avant resterait dans la mauvaise entité, un agent avec une URL
   fausse ne se répare pas à distance), régler la fréquence des relevés
   d'imprimantes (quotidienne par défaut, toutes les N heures ou tous les N jours), puis télécharger le paquet du
   système du PC sonde (Windows, Linux ou macOS).
3. Sur place : suivre `LISEZMOI.txt`, puis vérifier dans l'onglet que l'agent apparaît avec un contact récent et la
   collecte réseau installée. Page « Sondes » : « Marquer ce PC comme sonde ».
   - Windows : extraire, clic droit sur `1-installer-glpi-agent.bat` → Exécuter en tant qu'administrateur,
     assistant, attendre le message final. Étape 2 facultative, geste séparé : `2-facultatif-mise-a-jour-automatique.bat`
     en administrateur pose la mise à jour automatique. Sautée ou bloquée par l'antivirus : l'agent fonctionne mais ne
     se met plus à jour seul ; décocher « Mise à jour automatique » sur la sonde (page « Sondes »).
   - Linux : `tar -xzf GLPI-Agent-…-linux-<TAG>.tar.gz`, `cd` dans le dossier, `sudo sh installer-glpi-agent.sh`.
   - macOS : double-clic sur le ZIP, installer le paquet de ce Mac (puce Apple : `_arm64`, Intel : `_x86_64`), puis
     coller dans Terminal les trois commandes affichées dans l'onglet (dépôt de `local.cfg`, arrêt et redémarrage de
     l'agent).
4. Toujours sur place : bloc 3 de l'onglet, « Nouveau raccordement » (droit Déploiement en modification). Choisir la
   sonde, saisir les adresses des imprimantes et, carte 2 bis, leur lieu, commentaire et contrat ; créer la
   configuration de collecte, lancer la découverte. Si GLPI ne joint pas la sonde (cas normal derrière la box) : sur
   le PC sonde, ouvrir `http://127.0.0.1:62354` et cliquer « Force an Inventory », puis refaire ce geste quand
   l'assistant annonce le relevé des niveaux. Ne pas partir avant d'avoir le résultat adresse par adresse ; appliquer
   lieu, commentaire et contrat (étape 5), puis « Terminer le raccordement ».

Échec d'installation : Windows, journal `%TEMP%\GLPI-Agent-install.log` sur le PC ; Linux, relancer la commande de
`commande.txt` avec `--verbose` (distribution non prise en charge : message de l'installeur) ; macOS, journal
`/var/log/install.log`, puis `/var/log/glpi-agent.log`. Agent absent de l'onglet : il est peut-être rattaché à une
autre entité (TAG, règle) — voir Administration → Inventaire → Agents. Mac qui ne découvre aucune imprimante :
`local.cfg` absent de `/Applications/GLPI-Agent/etc/conf.d/` ou agent pas redémarré (le paquet seul ne fait que
l'inventaire du poste).

Raccordement :
- **« Assistant arrêté : prérequis de GLPI Inventory manquants »** (en tête de l'assistant ; bloc 3 de l'onglet sans
  « Nouveau raccordement ») : le message dit quoi faire. Non installé : Configuration → Plugins (Marketplace, ou
  archive dans `plugins/glpiinventory`), « Installer » puis « Activer ». Désactivé : « Activer ». Mise à jour non
  lancée (fichiers d'une autre version déposés) : « Mettre à jour » puis « Activer ». Version non prise en charge :
  revenir à une 1.6.x, ou faire vérifier la nouvelle série puis relever les bornes (section 7). `taskscheduler`
  désactivée : Configuration → Actions automatiques, statut « Programmée ». Tant qu'un prérequis manque, rien n'est
  fait (seul « Abandonner » reste possible) ; un raccordement en cours reprend où il en était une fois réglé.
- **« À vérifier : … taskscheduler … n'a pas tourné depuis »** : le cron de GLPI ne tourne pas (ou GLPI est en mode
  interne sans visite) ; la découverte lancée par l'assistant part, pas les relevés suivants.
- **Configuration refusée** : le message dit pourquoi, rien n'est créé. « Chevaucherait la plage » : adresses de
  part et d'autre d'une plage existante de la sonde, les déclarer dans des raccordements séparés (ou élargir la
  plage dans GLPI Inventory). « Tâche désactivée » : la réactiver dans GLPI Inventory plutôt que d'en créer une
  seconde.
- **Découverte « pas encore rendue »** : la sonde n'a pas reçu ou pas exécuté le job ; faire le geste sur place, ou
  attendre son prochain contact. Relancer la découverte ne crée rien de plus.
- **Mauvaise entité** : l'imprimante était déjà dans GLPI ailleurs, ou le TAG / la règle l'a envoyée ailleurs.
  Transférer l'imprimante à la main (Actions → Transférer), corriger la cause, puis relancer la découverte.
- **Pas de réponse SNMP** : imprimante éteinte, mauvaise adresse, SNMP désactivé ou autre communauté ; un « actif non
  géré » à l'adresse répond sur le réseau mais pas en SNMP avec ces identifiants.
- **Abandon** : rien n'est supprimé ; le journal le liste. Désactiver la tâche dans GLPI Inventory si la collecte
  ne doit pas avoir lieu.
- **Étape 5 : « rien n'est appliqué »** : la raison est affichée par adresse. Pas d'imprimante remontée : relancer
  la découverte ; imprimante dans une autre entité : transfert manuel d'abord ; contrat plein : augmenter son nombre
  maximal d'éléments ou choisir un autre contrat (carte 2 bis), puis appliquer à nouveau.
- **Lieu d'une imprimante remplacé par un nom venu de l'imprimante** : sans verrou, l'inventaire SNMP remplace le
  lieu par celui configuré dans l'imprimante (sysLocation). L'étape 5 pose le verrou ; pour une imprimante raccordée
  autrement, modifier son lieu dans GLPI suffit (GLPI verrouille alors le champ). Verrous : Administration →
  Inventaire → Champs verrouillés.
- Historique : page « Raccordements » du module ; chaque raccordement garde son journal horodaté.
- **Fiche imprimante : « Aucune sonde trouvée »** : aucune plage IP de GLPI Inventory de son entité (ou d'une entité
  parente) ne contient l'adresse de l'imprimante, ou aucune tâche ne vise cette plage ; la raccorder avec
  l'assistant. Le bloc de la sonde est dans la carte « Informations d'inventaire » pour les profils qui ont le droit
  Inventaire, sinon sous le formulaire ; le lien vers la fiche de l'agent demande le droit Agent.

### Sondes : mise à jour automatique et alertes

- **Où regarder** : Print Gestion → Collecte SNMP / Déploiement Agent → « Sondes » (compteurs, sondes sans contact par
  entité, conformité) ; le détail d'une sonde donne ses réglages, son paquet de consigne et ses imprimantes. Même
  contenu dans l'onglet « Sonde Print Gestion » de la fiche Agent (droit Agent).
- **Changer la mise à jour d'une sonde** (désactiver, épingler une version, revenir en arrière) : régler la sonde,
  télécharger son **paquet de consigne** et le lancer sur le PC, en administrateur. Sans ce geste rien ne change sur
  le PC : la tâche planifiée déjà posée continue.
- **La mise à jour ne se fait pas** : tâche « GLPI Agent - mise a jour (Print Gestion) » absente du Planificateur de
  tâches : l'étape 2 facultative du paquet n'a pas été lancée, ou l'antivirus l'a bloquée ; lancer le paquet de
  consigne de la sonde, en administrateur. Tâche présente : sur le PC, `C:\ProgramData\PrintGestion\glpi-agent-update.log`. « Agent occupé
  ou injoignable » : l'agent exécutait une tâche le 1er du mois à 3 h, mise à jour reportée au mois suivant ;
  « winget introuvable » : App Installer absent (Windows Server, Windows 10 ancien) ; code d'erreur de winget : winget
  sous le compte SYSTEM n'est pas pris en charge officiellement par Microsoft. Tâche : Planificateur de tâches →
  « GLPI Agent - mise a jour (Print Gestion) ». Pour ne pas attendre le mois suivant : lancer
  `C:\ProgramData\PrintGestion\glpi-agent-update.cmd` en administrateur.
- **Retour à une version plus ancienne refusé** (journal) : désinstaller GLPI Agent sur le PC, réinstaller avec le
  paquet de l'entité (même TAG), puis relancer la consigne si la version reste épinglée.
- **Linux** : tâche `/etc/cron.monthly/glpi-agent-printgestion`, journal `/var/log/glpi-agent-printgestion-update.log`
  (« Agent occupé », « GitHub injoignable », « Empreinte différente », « Déjà en version »). Le PC sonde doit joindre
  api.github.com et github.com, et avoir curl. Pour ne pas attendre le mois suivant :
  `sudo /etc/cron.monthly/glpi-agent-printgestion`.
- **macOS** : pas de mise à jour automatique ; réinstaller le paquet d'une version plus récente (paquet de l'entité),
  `local.cfg` est gardé.

### Fréquence des relevés d'imprimantes

- **Où** : onglet « Déploiement Agent » de l'entité, bloc 2, chevron « Fréquence des relevés » — administrateur du
  plugin seulement (droit de configuration en modification) : c'est une décision commerciale, pas une question de
  site, et le technicien ne la voit pas. Elle se change à tout moment, sans toucher à la sonde ; les sous-entités
  sans réglage propre en héritent. La liste sous le réglage indique, tâche par tâche, le prochain relevé.
- **« Fréquence des relevés hors service »** (onglet de l'entité, journal du plugin, tâche `PrintgestionCollectSchedule`
  en erreur) : GLPI Inventory a changé la structure de sa table des tâches (`datetime_start`/`datetime_end`) —
  mettre à jour Print Gestion avant de compter sur des relevés périodiques ; le plugin écrit directement dans cette
  table, faute d'une voie du plugin voisin.
- **Relevés moins fréquents que prévu** : la sonde ne reçoit ses jobs qu'à son contact, à la fréquence d'inventaire
  globale de GLPI (Administration → Inventaire, 24 h par défaut). Pour des relevés toutes les N heures, la régler à 1
  heure (tous les agents contacteront GLPI toutes les heures). Vérifier aussi l'action automatique
  `PrintgestionCollectSchedule` (Configuration → Actions automatiques) et celle de GLPI Inventory `taskscheduler`.
- **« Plage de dates réglée à la main »** : la tâche a une date de fin dans GLPI Inventory ; le plugin ne la touche
  pas. Effacer les dates de la tâche pour que la fréquence de l'entité s'applique.
- **Job annulé « due to the task's schedule »** dans GLPI Inventory : normal, la sonde a demandé ses jobs avant
  l'heure du prochain relevé ; il repart au contact suivant après cette heure.
- **Imprimante muette** : pas avant la fréquence de son entité plus un jour (le délai global
  « Imprimante muette après (jours) » reste le minimum).
- **Pas de notification** : réglages « Print Gestion » de Configuration → Inventaire → « Agent cleanup » ;
  notifications actives avec des destinataires (Configuration → Notifications) ; journal de la tâche
  `PrintgestionSilentProbes` (alertes ouvertes, fermées, notifiées). Une alerte n'est notifiée qu'une fois par
  épisode ; celle qui n'a pas pu l'être l'est au passage suivant.
- **Délai « sans contact »** : « Imprimante muette après (jours) » de Configuration → Print Gestion. Le délai natif
  « Agent cleanup » ne sert qu'aux actions natives.
- **Imprimante qui ne remonte plus alors que la sonde contacte GLPI** : motif dans la notification et dans « Contrôle
  de la remontée » (aucun inventaire réseau récent, jamais inventoriée, aucun niveau lisible). Causes : imprimante
  éteinte ou remplacée, adresse IP changée, SNMP coupé ou communauté modifiée. L'alerte se ferme au premier inventaire
  réseau réussi avec un niveau lisible ; tant que la sonde elle-même est sans contact, elle reste ouverte sans
  nouvelle notification.

### Cartouche « Réf. non résolue » (non commandable)

Le motif figure dans la colonne « Référence non résolue » de l'écran Alertes toner :

- **modèle non renseigné** : compléter le modèle sur la fiche imprimante ;
- **aucune cartouche** : sur la fiche de la cartouche, déclarer le modèle dans « Modèles d'imprimantes
  compatibles », puis lier la propriété SNMP dans l'onglet Print Gestion (ou renseigner le type de cartouche
  dans le mapping SNMP de la configuration). L'onglet liste toutes les propriétés remontées par les imprimantes
  de ces modèles, OK / WARNING compris, sous leur nom GLPI (`tonercyan`, `drumblack`, `fuserkit`…) ;
- **plusieurs cartouches possibles** (standard et XL, par exemple) : ne lier qu'une cartouche à la propriété
  pour ce modèle, ou n'en garder qu'une dans l'entité de l'imprimante.

### Tester l'import dans Gesconso avant de brancher l'envoi

1. Demandes d'envoi → « Exporter les demandes validées » → cocher plusieurs demandes.
2. « Télécharger le fichier (test, sans envoi) » : fichier `Gesconso_JJMMAAAA_HHMM.xlsx`, archivé sur les
   demandes avec la mention « non transmis » ; aucun statut ne change.
3. Faire importer ce fichier multi-lignes dans Gesconso par les Achats ; en cas de rejet, comparer au fichier
   réel de référence (en-têtes, 9 colonnes, date Excel, longueur de désignation — réglable en configuration).
4. Une fois l'import validé, utiliser « Envoyer aux Achats ». Attention : un fichier de test importé pour de
   bon dans Sage n'est pas tracé comme exporté — ne pas le renvoyer ensuite.

### « Commande ENREGISTRÉE mais NON TRANSMISE aux Achats »

La commande est enregistrée (expéditions, fichier archivé) et ses cartouches restent verrouillées, mais le mail aux
Achats n'est pas parti (serveur mail injoignable, aucun destinataire Achats, fichier archivé illisible : cause
exacte dans la carte et dans `printgestion.log`). **Ne pas recommander** : régler la cause, puis cliquer
« Renvoyer aux Achats » dans la carte « Commandes non transmises aux Achats » (écrans Expéditions, Demandes d'envoi
ou Export). Le renvoi reprend le fichier d'origine, jamais un fichier recalculé. Si les Achats confirment avoir
quand même reçu la commande (erreur signalée par le serveur après remise du message), ne pas renvoyer. Au-delà de
4 h, la notification « Print Gestion - Commande non transmise aux Achats » part à l'administrateur et à l'auteur ;
si le journal de la tâche `CheckAlerts` indique « non notifiées », vérifier que cette notification est active.

### Suivi GLS : clés

Configuration → Print Gestion → carte « Suivi GLS » : Client ID et Client Secret de l'API GLS (Piste et Trace). Le
secret ne se relit jamais (« •••••••• défini le … », « Remplacer » pour en saisir un autre) ; « Retirer les clés »
efface les deux et laisse les suivis collectés en place. Aucun interrupteur : des clés saisies et un dernier appel
réussi, c'est un suivi actif ; sans clés, une expédition GLS s'affiche comme les autres (transporteur et numéro
saisis à la main). Les autres transporteurs n'ont pas d'intégration.

### Commande refusée : « Fichier Gesconso non archivé »

Rien n'a été enregistré ni envoyé aux Achats. Deux causes :

- **« le type de document .xlsx n'est pas autorisé dans GLPI »** : Configuration → Intitulés → Types de document,
  ligne `xlsx`, cocher « Autoriser l'import ». GLPI refuse sinon le fichier et le supprime ; avant la correction
  P0.4, le mail partait aux Achats sans pièce jointe ;
- **« copie dans le dossier des documents en échec »** : droits d'écriture ou place disque sur `files/_documents`
  (détail dans `printgestion.log`).

Relancer la commande une fois la cause réglée : aucune expédition n'est restée en attente.

### Commande refusée : « ne peuvent pas être écrites dans le fichier Gesconso »

Chaque ligne en défaut est listée avec son motif (`Gesconso::prepare()`) :

- **code client Sage absent** : lier l'entité (ou un parent) à un client, onglet « Print Gestion — Sage »
  de l'entité, ou importer les clients ;
- **adresse de livraison absente** : renseigner le champ « Code » du lieu de l'imprimante (ou d'un parent)
  avec le code adresse Sage du client, ou importer les adresses ;
- **référence article absente** : référence de la cartouche vide, cartouche non résolue, ou référence
  absente du dernier import articles.

Aucun fichier n'est jamais produit avec une ligne incomplète.

**Prix vide** : normal hors contrat (les Achats le renseignent). Prix 0 uniquement si l'imprimante a un
contrat **en cours** dont le type est coché dans « Contrats — consommables inclus ». Une ligne attendue
sous contrat mais au prix vide : vérifier le type du contrat, sa date de début, sa durée ou sa
reconduction tacite (un contrat terminé ne couvre plus rien).

---

## 7. Compatibilité et montée de version GLPI

- Bornes dans `setup.php` : `PLUGIN_PRINTGESTION_MIN_GLPI` / `MAX_GLPI` (actuel : 11.0.0 → 11.1.0).
  Après validation sur une nouvelle version GLPI, relever la borne max.
- GLPI Inventory : bornes `GLPIINVENTORY_MIN_VERSION` / `GLPIINVENTORY_MAX_VERSION` dans
  `inc/collectsetup.class.php` (actuel : minimum 1.6.0, validé avec 1.6.10). Plus ancienne que le minimum : assistant
  de raccordement arrêté. Plus récente que la version validée (série 1.7 comprise) : avertissement seulement, pour
  ne jamais bloquer les techniciens à la sortie d'une version. À chaque nouvelle version : refaire un raccordement
  complet sur une instance de test (tâches, jobs, plages IP, préparation des jobs, états des jobs), puis mettre à
  jour `GLPIINVENTORY_TESTED_VERSION`.
- Points sensibles à re-tester lors d'une montée GLPI :
  - `GLPIMailer` / Symfony Mailer (`Config::sendMail`, `Expedition::sendRawMail`) ;
  - moteur Search (tables matérialisées, `addDefaultWhere`, mapping itemtype) ;
  - `glpi_printers_cartridgeinfos` (structure de l'inventaire SNMP) ;
  - validation CSRF des endpoints ajax (`CheckCsrfListener`).

---

## 8. Checklist avant mise en production

- [ ] `php -l` sur tous les fichiers modifiés.
- [ ] Harnais de sécurité rejoué sur une instance jetable (`tests/README.md`) : aucun KO nouveau.
- [ ] « Mettre à jour » le plugin sur un environnement de test (install idempotente, pas d'erreur).
- [ ] Jeton anti-cache incrémenté si JS/CSS modifié.
- [ ] `php tools/generate_apercu.php` exécuté si gabarits modifiés ; aperçu relu.
- [ ] Test d'un envoi de chaque circuit modifié (unitaire, groupé, commande, crons).
- [ ] « Qui est notifié ? » cohérent avec l'attendu.
- [ ] Carte « Santé de la configuration » : « Configuration : complète » sur le serveur cible.
- [ ] Pas de nouvelle table/colonne oubliée pour les instances existantes (§2).
- [ ] Aucune donnée PHP écrite dans un `<script>` exécuté : `PluginPrintgestionUi::jsonData()` (§5).
- [ ] Nouveau point d'entrée `front/` ou `ajax/` : garde « plugin actif ET module activé » en tête (même 404), et l'ajouter à `tests/securite/modules.py`.
- [ ] Écran du module Déploiement : le technicien (droit Déploiement seul) voit l'état et l'action ; commandes, propriétés, chemins de menu, noms de règles et versions seulement pour l'administrateur (`PluginPrintgestionUi::statusLine()`, `adminDetails()`, `infoButton()`, `data-pg-admin`). Nouvel écran : l'ajouter à `tests/securite/interface.py`.
- [ ] Toute nouvelle table de données client porte `entities_id` / `is_recursive`, posés à l'écriture par `PluginPrintgestionEntityscope`.
