# Print Gestion — Documentation de maintenance

> Comment maintenir, mettre à jour et dépanner le plugin sans casser l'existant.
> Pour comprendre le fonctionnement interne, lire d'abord [DOC_TECHNIQUE.md](DOC_TECHNIQUE.md).

---

## 1. Installer la 1.0.0 (et ce qu'il n'y a pas : de mise à jour)

La 1.0.0 s'installe sur une **base vierge du plugin**. Il n'y a pas de chemin de mise à jour depuis les versions de
développement (1.4 à 1.6.x) : une base qui porte une autre version est **refusée** à l'installation, avec le message
« Désinstallez d'abord le plugin ». La désinstallation supprime toutes les données du plugin (§2) : l'exporter avant si
elle contient quelque chose à garder.

1. Configuration → Plugins → Print Gestion → **Désinstaller** (si une version de développement est en place).
2. Déployer les fichiers dans `plugins/printgestion/` (remplacer le dossier).
3. Configuration → Plugins → Print Gestion → **Installer**, puis **Activer**.
4. Vérifier la carte « Santé de la configuration » (§6) et le bouton **« Qui est notifié ? »**.

Réinstaller par-dessus une base déjà en 1.0.0 (« Mettre à jour » après un redéploiement de la même version) est sans
effet sur les données : tables existantes gardées, gabarits existants jamais réécrits (§3), tâches réenregistrées.

Jeton anti-cache des ressources : si `public/css/*` ou `public/js/*` change, incrémenter `$cb = '?b=N'` dans
`setup.php`, sinon les navigateurs gardent l'ancien JS/CSS.

---

## 2. Schéma : une seule version, prouvée par comparaison

`inc/schema.class.php` porte les `CREATE TABLE` et les lignes de référence de la 1.0.0. Ils **n'ont pas été écrits à la
main** : relevés (`tests/securite/etat_installation.py`) sur une base installée par l'ancienne chaîne de migrations,
puis générés (`tests/securite/schema_depuis_etat.py`). Le relevé de référence est dans le dépôt
(`tests/securite/reference/etat-ancien-chemin.json`) et la preuve se rejoue : `tests/securite/comparer_etats.py`
entre ce relevé et celui d'une installation neuve rend **zéro écart** (tables, colonnes, types, index, défauts, jeux de
caractères, lignes de référence, tâches, notifications, gabarits, droits), hors les écarts volontaires déclarés dans
`installation.py` avec leur raison (en 1.0.0 : la date d'événement GLS gardée telle que GLS l'envoie ; les colonnes MBE, `mbe_username`, `mbe_passphrase`, `mbe_secret_date` de la configuration et `mbe_master_tracking` des expéditions, absentes de l'ancien chemin). `installation.py`
le vérifie à chaque passe.

- La version installée est dans `glpi_configs` (contexte `plugin:printgestion`, clé `schema_version`).
- **Désinstallation** : toutes les tables du plugin (vivantes et anciennes), les tâches automatiques, les notifications
  et gabarits, les droits, les préférences d'affichage et recherches enregistrées, les valeurs de configuration du
  plugin, le journal, les installeurs mis en réserve et les fichiers temporaires. Restent, parce qu'ils sont natifs ou
  appartiennent à l'exploitation : la règle d'affectation par TAG et les TAG posés sur les entités (des imprimantes en
  dépendent), les plages, identifiants et tâches créés dans GLPI Inventory par les raccordements (la collecte
  continue), l'historique natif des entités, les documents (archives Gesconso envoyées aux Achats : seuls leurs liens
  vers les objets du plugin sont retirés).
- **Faire évoluer le schéma après la 1.0.0** : la 1.0.0 est le point de départ ; la première évolution réintroduira une
  étape de migration versionnée depuis 1.0.0, avec sa preuve par comparaison. Jamais d'`ALTER TABLE` à la main sur une
  instance, jamais de désinstallation pour faire évoluer un schéma (destructif).

---

## 3. Gabarits mail : source de vérité et cycle de vie

- **Le texte par défaut est dans le code** : `hook.php → plugin_printgestion_template_definitions()`.
  À l'installation, les gabarits manquants sont créés en base (`glpi_notificationtemplates`, marqueur
  `comment = 'Created by plugin printgestion'`). **Un gabarit existant n'est jamais réécrit** : les
  modifications faites dans l'interface GLPI (Configuration → Notifications → Modèles) survivent aux mises à jour.
- Pour revenir au texte par défaut d'un gabarit : le supprimer dans GLPI, puis « Mettre à jour » le plugin,
  qui le recrée et reprend son identifiant dans la configuration.
- Après toute modification de gabarit, **régénérer l'aperçu** :

  ```bash
  cd plugins/printgestion
  php tools/generate_apercu.php     # → docs/apercu_gabarits.html
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
affichage ; la carte ne bloque rien. Le détail est toujours replié derrière un chevron : tout vert, une ligne
« Configuration : complète » ; sinon la bannière rouge des obligatoires manquants, puis une ligne « N points à voir :
… à corriger, … jamais confirmés par le réel, … à acquitter », et le chevron donne chaque contrôle avec ses boutons.

> **Piège des pages `front/` (GLPI 11).** Elles sont chargées **dans une fonction**
> (`LegacyFileLoadController::__invoke()` → `require()`) : une variable du fichier n'est donc pas une globale.
> Toute page qui se sert de `$DB` doit écrire `global $DB;` après l'`include`, sinon le premier appel meurt en
> « Call to a member function request() on null » — une erreur 500 que les scripts d'installation attrapent sans
> un mot. Le harnais de sécurité le vérifie pour toutes les pages du dossier `front/`.

| Contrôle | Rouge quand | Corriger |
|---|---|---|
| URL de l'application GLPI (obligatoire) | vide, `localhost` ou `127.x`, sans `https://`, ou nom sans domaine qui ne se résout pas : un agent déployé avec ne joindra jamais GLPI et ne se répare pas à distance ; le téléchargement des installeurs est bloqué. Syntaxe correcte mais aucun agent n'a encore remonté depuis qu'elle est en place : « jamais confirmée » (horloge), la carte reste dépliée jusqu'au premier agent qui remonte — seule preuve qu'elle est joignable depuis un réseau client | Configuration → Générale, « URL de l'application » |
| Inventaire GLPI activé (obligatoire) | « Activer l'inventaire » décoché | Administration → Inventaire |
| Pilotage des scans depuis GLPI — plugin GLPI Inventory | **rouge** quand il est posé mais inutilisable (inactif, version trop ancienne, fichiers ou tâche `taskscheduler` manquants) : un pilotage est promis et ne marche pas. **Absent** (jamais installé), ce n'est pas une panne mais un choix : ligne bleue « pour information », groupe Recommandé, aucun bandeau — les sondes scannent en local par la ToolBox de leur agent, les imprimantes remontent, mais plage IP, communauté SNMP et cadence sont écrites sur chaque PC à l'installation et ne se modifient pas depuis GLPI | Configuration → Plugins → Marketplace |
| Actions automatiques du plugin en mode CLI avec un cron système (obligatoire) | aucun cron système prouvé (tâche témoin `PrintgestionTemoinCron`, CLI seulement, chaque minute, jamais passée ou pas depuis 15 min, et `GLPI_SYSTEM_CRON` non déclaré), ou une tâche du plugin en mode Interne ; les tâches de GLPI ne sont pas jugées, `queuednotification` est affichée en lecture | sur le serveur : crontab `* * * * * php <GLPI>/front/cron.php`, puis `define('GLPI_SYSTEM_CRON', true);` dans `config/local_define.php` ; un bouton par correction au bout du tableau des tâches, chacun affiché seulement s'il sert, chacun confirmé, jamais automatique : « Passer les N tâches en mode CLI » (le mode seulement, l'état n'est pas touché ; sans cron système prouvé, la confirmation prévient que plus rien ne tournera tant que le cron n'est pas en place), « Activer les N tâches désactivées » (l'état seulement, le mode n'est pas touché), « Débloquer les N tâches bloquées » (coincées « en cours d'exécution »), « Déclarer le cron système (GLPI_SYSTEM_CRON) » (écrit la ligne dans `config/local_define.php` après copie horodatée ; demande le droit GLPI de configuration ; à ne cliquer QUE si la crontab est bien en place, sinon le contrôle passe au vert alors que rien ne tourne) ; la proposition automatique des demandes d'envoi n'a pas de bouton ici (ce n'est pas une correction) : son interrupteur est dans les réglages, carte « Proposition automatique des demandes d'envoi » ; tant que le témoin n'est pas passé, la ligne de crontab à recopier est affichée dans le détail ; chaque tâche a son lien « Configurer dans GLPI » |
| Type de document xlsx autorisé (obligatoire) | extension xlsx sans « Autoriser l'import » : Gesconso non archivé, aucune commande | Configuration → Intitulés → Types de document |
| Notifications GLPI activées (obligatoire) | « Activer le suivi » à Non : aucune notification native ne part (commande non transmise, demandes, sondes, contrats) | Configuration → Notifications → Configuration des notifications |
| Règle d'affectation par TAG présente et active (obligatoire) | règle absente ou désactivée | bouton de la carte (créer ou activer) |
| Sauvegarde de `config/glpicrypt.key` (recommandé) | non vérifiable automatiquement : « jamais vérifié », ou vérification de plus de six mois ; bouton « J'ai vérifié » (date et auteur mémorisés), la ligne revient d'elle-même au bout de six mois | sauvegarde du serveur, avec la base |
| Identifiants MBE (recommandé) | sans identifiants : vert, rien ne manque ; saisis sans appel : en attente ; refus 401/403 : rouge tout de suite ; cinq échecs consécutifs : rouge | carte « MBE » : identifiant et passphrase, « Tester la connexion » |
| Suivi des colis GLS (recommandé) | clés saisies et cinq échecs techniques consécutifs ; « en attente » tant qu'aucun appel n'a réussi ; sans clés : vert, rien ne manque | carte « Suivi GLS » : Client ID, Client Secret, « Tester la connexion » ; détail : clés, dernier appel réussi, échecs (dernière erreur), quota du jour |
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
tâche `PrintgestionEntityScope` (« Lignes orphelines ») liste les lignes dont l'imprimante,
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

1. Exporter de Sage un fichier par référentiel (adresses de livraison, articles) avec les colonnes décrites
   dans la doc technique (« Référentiel Sage ») ; l'écran de dépôt les rappelle.
2. Print Gestion → Référentiel Sage → Import du référentiel : déposer, **analyser**, lire le rapport d'écarts, valider.
3. Le code client et l'intitulé de livraison ne s'importent pas : ils se lisent sur l'entité (nom en forme de
   code client Sage, hérité du parent le plus proche ; première ligne des commentaires de cette même entité porteuse =
   intitulé de livraison). Le rapport d'écarts liste les entités à imprimantes sans code, sans intitulé, ou dont
   l'intitulé n'est pas une adresse de leur client dans le fichier.
4. Corriger dans GLPI : nom ou commentaires de l'entité, référence des cartouches, puis relancer l'analyse.

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
   - Les trois boutons (Windows, Linux, macOS) servent **un seul fichier**, rien à extraire ; une fenêtre s'ouvre,
     « Installer » fait le reste. Windows : clic droit → Exécuter en tant qu'administrateur. Linux et macOS : dans un
     terminal, taper `sudo sh` puis glisser le fichier téléchargé dans la fenêtre. Chaque fichier contient une clé
     valable **une seule fois et 24 h** : le régénérer dans GLPI s'il a déjà servi, s'il a plus d'un jour, ou si le
     téléchargement a été interrompu.
   - Recours si l'antivirus du client refuse les scripts : archives complètes (sans clé), dans le panneau replié
     « Comment lancer le fichier téléchargé ». Windows : extraire, clic droit sur `INSTALLER-GLPI-AGENT.bat` (seul fichier à lancer) → Exécuter en tant qu'administrateur,
     assistant, attendre le message final. Il demande ensuite s'il faut poser la mise à jour automatique (20 s, non par défaut)
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
- **macOS** : service launchd `com.printgestion.glpi-agent-update` (le 1er du mois à 3 h), script
  `/usr/local/sbin/glpi-agent-printgestion-update`, journal `/var/log/glpi-agent-printgestion-update.log`. Pour ne
  pas attendre le mois suivant : `sudo sh /usr/local/sbin/glpi-agent-printgestion-update`. Poser ou retirer le
  service : relancer le fichier d'installation de l'entité (case « Mettre à jour l'agent automatiquement »).

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

### Proposition automatique des demandes d'envoi : l'automatiser ou non

Configuration → Print Gestion → carte « Proposition automatique des demandes d'envoi », interrupteur « Activée ».
**Coupée par défaut**, et c'est un choix : rien n'en dépend.

- **Coupée** : les commandes se font depuis l'écran Alertes, en cochant des toners puis « Actions → Commander ».
  C'est le fonctionnement normal, rien ne manque.
- **Activée** : chaque heure, le plugin propose tout seul une demande par client et par site de livraison, avec une
  ligne par cartouche en alerte, au statut « Proposée ». Il ne commande rien et n'envoie rien : il remplit l'écran
  des demandes, que quelqu'un valide puis exporte aux Achats.

**Ce qui change vraiment** : une ligne proposée **bloque** la commande de sa cartouche depuis l'écran Alertes
jusqu'à son export ou son annulation. Le travail se déplace donc des Alertes vers les Demandes. À n'activer qu'une
fois l'export des demandes validées en service et suivi — sinon les commandes se bloquent sans que personne les
débloque.

**Couper n'efface rien** : les demandes déjà proposées restent et gardent leur verrou. Pour les lever, il faut les
valider et les exporter, ou les annuler.

La tâche écarte d'elle-même ce qui est déjà couvert (envoi en cours, demande ouverte, garde après une pose, ticket
récent) et les emplacements dont une ligne a été annulée récemment, pour ne pas reproposer ce qu'on vient de
refuser. Un groupe en échec n'est jamais enregistré à moitié : la tâche se termine alors en erreur, jamais en
succès silencieux.

### Suivi GLS : clés, test, ce qui s'affiche

Configuration → Print Gestion → carte « Suivi GLS » : Client ID et Client Secret de l'API GLS (Piste et Trace). Le
secret ne se relit jamais (« •••••••• défini le … », « Remplacer » pour en saisir un autre) ; « Retirer les clés »
efface les deux, le mémo de santé et le jeton, et laisse les suivis collectés en place. « Tester la connexion »
demande un jeton et le jette : « connexion établie » ou l'erreur. Aucun interrupteur : des clés saisies et un dernier
appel réussi, c'est un suivi actif ; sans clés, une expédition GLS s'affiche comme les autres (transporteur et numéro
saisis à la main), et le technicien ne voit rien d'une intégration. Les autres transporteurs n'ont pas d'intégration.

- **Ce qui s'affiche** sous le numéro saisi (liste des expéditions, cartes « en retard ») : une pastille, le libellé
  (« En transit », « Livré », « Livré en point relais », « Non livré »…), l'événement en français et sa date ; le
  chevron donne le lieu, le numéro réellement interrogé s'il diffère, la dernière interrogation. « Non livré » et
  « Non enlevé » sont en rouge, « à signaler aux Achats ». « Numéro non reconnu par GLS » après trois cycles ; « Sans
  nouvelles depuis 30 jours » quand plus rien ne bouge. Plusieurs colis pour un numéro : « 3 colis — 2 livrés, 1 en
  cours de livraison », le statut de tête est celui du colis le moins avancé, chaque colis derrière le chevron.
- **Transporteurs proposés** : GLS, UPS, Autre. Chronopost se lit sur les anciennes expéditions, ne se choisit plus.
- **Liaison avec le plugin Gestion** : carte « Liaison avec le plugin Gestion » de la configuration, interrupteur
  « Activée » (**oui par défaut**). La carte ne s'affiche pas du tout sur un GLPI qui n'a jamais eu le plugin
  Gestion. Coupée : aucun BL signé ne fait passer d'expédition en « livrée » et l'association de BL est refusée ;
  les expéditions déjà livrées le restent. Le passage en « livrée » est immédiat : le plugin Gestion
  appelle Print Gestion à la signature. En secours, les écrans Expéditions et Alertes reprennent les BL signés à
  leur ouverture, et la tâche horaire rattrape le reste — au pire une heure.
- **Après chaque mise à jour du plugin Gestion** : vérifier que les trois appels à Print Gestion sont toujours en
  place dans `gestion/front/traitement.php` (×2) et `gestion/front/traitement_combined_multi.php` (×1). Un paquet
  peut les emporter. Rien ne casse s'ils disparaissent — les écrans et la tâche horaire rattrapent — mais
  l'immédiateté est perdue sans prévenir. `tests/securite/livraison.py` le contrôle.
- **Quand** : tâche `PrintgestionTrackingUpdate` (horaire), une ligne par heure au plus, dix numéros par requête,
  arrêt à 400 requêtes par jour (quota GLS 500). Un 429 arrête la journée ; cinq échecs techniques consécutifs
  arrêtent le cycle (reprise au passage suivant). Le journal `printgestion.log` (contexte `gls`) garde les échecs,
  les numéros raccourcis et les codes non répertoriés.
- **Ce qui fait passer une expédition « livrée »**, dans l'ordre de force : un **BL signé** dans le plugin Gestion (immédiat, dès la signature) ; un **événement de livraison GLS** (`DELIVERED` ; un colis remis en point relais ne compte pas, personne ne l'a encore) ; le **statut MBE** `DELIVERED`. Le premier qui constate gagne, et une livraison constatée ne se reprend jamais. **Aucun délai ne fait passer une expédition « livrée »** : le temps qui passe ne produit qu'une alerte.
- **MBE seul ne suffit pas** : son statut reste « en attente de livraison » plusieurs jours après une remise déjà faite. Le plugin recoupe donc toujours avec ce que GLS a publié, et c'est GLS qui l'emporte. Pour un envoi UPS, MBE est la seule source.
- **Livraison en retard** : une expédition partie et toujours pas livrée au-delà du délai du transporteur (GLS 3 jours ouvrés, UPS 6, Chronopost 4, 4 par défaut) apparaît dans « Alertes prioritaires » de l'écran Expéditions. C'est un signalement, pas une conclusion.
- **Le suivi ne clôt rien** : un colis « livré » ne clôt pas l'envoi, seule la pose détectée le fait.

### MBE (intermédiaire de transport) : identifiants et test

Configuration → Print Gestion → carte « MBE (intermédiaire de transport) » : identifiant et passphrase de l'API MBE
France (e-link). MBE n'est pas un transporteur : c'est par lui que les cartouches du stock partent chez le client,
confiées à GLS ou à UPS ; le transporteur et son numéro se saisissent comme aujourd'hui. La passphrase API n'est pas
forcément le mot de passe de la console MBE. Elle ne se relit jamais (« •••••••• définie le … », « Remplacer ») ;
« Retirer les identifiants » efface les deux et le mémo de santé. Le bouton « Tester la connexion » affiche son résultat
dans une **fenêtre**, sans recharger la page : la saisie en cours n'est pas perdue et le test se relance autant de
fois qu'on veut. Il est **toujours affiché et toujours cliquable** : tant que l'identifiant ou la passphrase ne sont pas enregistrés, une ligne sous le
bouton liste ce qui manque, et le clic répond « non saisis » sans appeler MBE (le test appelle l'API avec ce qui est
en base, jamais avec ce qui est saisi à l'écran). Il lit la liste des expéditions
des sept derniers jours, page 1, et la jette : « Connexion MBE établie et réponse comprise : N expédition(s) …, dont
B avec un numéro de BL lisible dans les notes » ou l'erreur. Il passe par la même lecture de XML que le suivi : il
prouve donc que la réponse de MBE est comprise, pas seulement que les identifiants sont acceptés — et le décompte
des BL lisibles dit tout de suite si l'appariement par numéro de BL pourra fonctionner. Adresse et
système (FR) sont fixés dans le code, affichés en lecture seule.

- **Ce que fait cette version** : enregistrer et tester les identifiants, et la ligne « Identifiants MBE » de la
  carte Santé. Rien d'autre n'appelle MBE. Le suivi par MBE vient ensuite, sans changement de schéma.
- **Erreurs** : « HTTP 403 » = identifiant, passphrase ou droits du compte, réessayer ne change rien (la ligne Santé est
  rouge tout de suite) ; « HTTP 500, NullPointerException » = format de la requête, à signaler ; « MBE injoignable » =
  réseau ou proxy ; « MBE répond « KO » : … » = réponse de MBE avec son texte, identifiants et liens masqués.
- **Aucun secret** nulle part : ni dans les messages, ni dans les journaux, ni dans les pages. Les identifiants sont
  chiffrés avec la clé de GLPI (`glpicrypt.key`) ; `glpi:security:change_key` les rechiffre (déclarés dans
  `setup.php`, avec le secret GLS).

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

- **code client Sage absent** : ni le nom de l'entité ni celui d'un parent n'a la forme d'un code client
  (majuscules et chiffres, sans espace) : nommer l'entité cliente par son code Sage ;
- **intitulé de livraison absent** : champ « Commentaires » vide sur l'entité qui porte le code client (le message la
  nomme) : y mettre le nom du client tel que Sage le connaît (première ligne) ;
- **référence article absente** : référence de la cartouche vide, cartouche non résolue, ou référence
  absente du dernier import articles.

Aucun fichier n'est jamais produit avec une ligne incomplète. Deux avertissements non bloquants, comptés sur l'écran
d'envoi (« Envoyer aux Achats », sous-formulaire « Commander ») avec la liste des lignes derrière « voir », pour
décider avant le clic :

- **intitulé de livraison absent des adresses importées du client** : vérifier l'orthographe des commentaires de
  l'entité, ou réimporter les adresses ; Sage peut refuser la ligne ;
- **lieu absent de la fiche imprimante** : la désignation ne dira pas où est la machine ; renseigner le lieu de
  l'imprimante dans GLPI.

**Prix vide** : normal hors contrat (les Achats le renseignent). Prix 0 uniquement si l'imprimante a un
contrat **en cours** dont le type est coché dans « Contrats — consommables inclus ». Une ligne attendue
sous contrat mais au prix vide : vérifier le type du contrat, sa date de début, sa durée ou sa
reconduction tacite (un contrat terminé ne couvre plus rien).

---

## 7. Compatibilité et montée de version GLPI

- Bornes dans `setup.php` : `PLUGIN_PRINTGESTION_MIN_GLPI` / `MAX_GLPI` (actuel : 11.0.0 → 11.0.99, la série 11.1 n'étant pas testée) ; version de PHP et extensions déclarées dans `plugin_version_printgestion()`, vérifiées par GLPI avant l'installation.
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
