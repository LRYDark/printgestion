# Journal des changements

## Non publié

- **Windows : la fenêtre d'installation et de retrait s'affiche de nouveau.** Lancée sans console, elle s'ouvrait
  invisible : la console clignotait, puis plus rien, et PowerShell restait en attente d'un clic impossible (à
  terminer dans le Gestionnaire des tâches). Rien n'avait été installé ni retiré. Retéléchargez les fichiers
  depuis GLPI.

- **Windows : plus aucune panne muette.** Une erreur survenue avant l'ouverture de la fenêtre faisait disparaître le
  fichier sans rien montrer (la console clignotait, rien d'autre) : elle s'affiche maintenant dans une boîte de
  message, avec le chemin du journal. Un fichier illisible par PowerShell est détecté avant lancement et le dit.
  Et un nom de client avec une apostrophe typographique (« L’Atelier ») ne casse plus le fichier.

- **Sans GLPI Inventory, le scan des imprimantes est confié à la ToolBox de l'agent**, sa fonction native, au lieu
  d'une tâche planifiée maison (`schtasks` / cron) qui enchaînait `glpi-netdiscovery` et `glpi-injector`. L'agent
  planifie, scanne et envoie lui-même, à la fréquence choisie ; la tâche se voit et se modifie sur
  `http://127.0.0.1:62354/toolbox` du PC sonde. Windows, Linux et, nouveau, macOS. L'ancienne tâche est retirée à
  l'installation. Avec GLPI Inventory, rien ne change : les tâches sont réglées à distance par ce plugin.

- **Le journal de l'agent est connu** : `C:\Program Files\GLPI-Agent\logs\glpi-agent.log` sous Windows,
  `/var/log/glpi-agent.log` sous Linux et macOS (posé par la configuration). La fenêtre de fin donne son chemin.

- **Plus natif, plus solide aux mises à jour de GLPI.** Les cartouches passent par la classe `Cartridge`, les
  tâches automatiques par `CronTask` (`update()`, `resetState()`), la désinstallation par
  `Config::deleteConfigurationValues()` et `$DB->dropTable()`, le recalage des entités par `$DB->update()` : plus
  d'écriture directe dans les tables de GLPI. Les listes Sondes, Contrôle de la remontée et Raccordements utilisent
  le gabarit natif `components/datatable.html.twig`, et le raccordement a ses options de recherche natives.

- **Une seule fenêtre, sans console, pour installer comme pour retirer — sur Windows, Linux et macOS.** Réglages
  (ou confirmation pour le retrait), puis les étapes qui se cochent une à une, puis le résultat au même endroit, avec
  « Ouvrir le journal » et « Fermer ». Plus de boîte de message ni de seconde fenêtre. Sous Windows la console ne
  reste plus derrière (un éclair d'une seconde au lancement, le prix d'un `.bat`) ; sous macOS c'est une vraie
  fenêtre Cocoa ; sous Linux, un formulaire puis une fenêtre d'avancement (zenity ne sait pas changer de page). Sans
  écran — session SSH, serveur —, tout se fait en console, étape par étape : une fenêtre qui ne s'ouvre pas ne vaut
  jamais une annulation.

- **Un journal de chaque exécution sur le poste**, étape par étape, succès et échecs : `%TEMP%\PrintGestion` sous
  Windows, `/var/tmp` ailleurs. Empreintes, codes de retour, réponse de GLPI, et la sortie des installeurs qui
  défilait auparavant dans le terminal. Jamais la communauté SNMP ni la clé de téléchargement.

- **Les modules « Découverte réseau » et « Inventaire réseau » de la sonde sont cochés dès l'installation.** Tant
  qu'ils étaient décochés, GLPI Inventory n'envoyait aucune tâche réseau à la sonde : c'était la vraie raison pour
  laquelle rien ne partait. Ils ne s'activaient qu'à la configuration d'un raccordement. L'installation règle
  maintenant ce qui sert aux imprimantes : ordinateur, découverte et inventaire réseau cochés ; VMware, déploiement
  et collecte décochés.

- **L'installation attend que GLPI connaisse la sonde avant de lui confier les imprimantes.** Le compte rendu
  partait souvent avant le premier inventaire de l'agent : GLPI ne pouvait alors ni régler ses modules ni créer le
  raccordement. Et si GLPI ne la connaît toujours pas, la fenêtre le dit au lieu d'annoncer un succès.

- **Le fichier de retrait Windows ne crée plus de fichier parasite.** Sa première ligne portait le nom complet de
  l'entité, « Root entity > EASI SUPPORT > … », et cmd exécute les « > » même dans un commentaire.

- **La découverte part vraiment à la fin de l'installation.** C'était un défaut, pas un réglage : le fichier
  d'installation créait tout dans GLPI — raccordement, adresses, identifiants SNMP, tâches — puis s'arrêtait sans
  jamais lancer la découverte. Le raccordement restait « configuré », personne ne scannait, et il fallait rouvrir
  l'assistant pour un clic alors que le technicien était déjà reparti. Deux gestes complètent la chaîne : GLPI arme
  la découverte, puis **le script réveille l'agent depuis le PC lui-même** (`127.0.0.1`, l'adresse du bouton
  « Force an Inventory » — toujours ouverte sur le poste, et c'est là que le script tourne). L'agent rappelle GLPI
  dans la seconde et scanne. Rien à faire à distance, aucun VPN, aucune attente. Si le réveil échoue, la consigne
  reste armée et partira au prochain appel de l'agent : le script le dit, au lieu de laisser croire le contraire.

- **Le réveil de fin d'installation manquait sur macOS.** Il était rangé avec le scan local, qui est propre à
  Linux (macOS ne lit pas `/etc/cron.d`) : sur un Mac, la découverte restait armée sans que rien ne la déclenche
  avant le prochain appel de l'agent. Les deux sont désormais séparés — le réveil vaut pour les trois systèmes,
  le scan local reste Linux.

- **Le fichier de retrait Linux emporte aussi les deux journaux** que le plugin écrit dans `/var/log`
  (mise à jour, scan). Sur Windows ils étaient déjà retirés avec le dossier `PrintGestion` ; sur Linux ils
  restaient en place.

- **Réinstaller l'agent sur un PC déjà raccordé n'écrase plus rien.** Le fichier d'installation reprenait le
  raccordement en cours — c'est voulu — mais réécrivait ses adresses sans regarder son état, alors que l'assistant
  les fige dès que la collecte est configurée. Désormais : mêmes adresses, la découverte est simplement relancée ;
  adresses différentes, rien n'est touché et le raccordement le dit dans son journal.

- **Les listes deviennent de vrais tableaux GLPI**, avec cases à cocher et actions massives : **Sondes**,
  **Contrôle de la remontée** et **Raccordements**. Supprimer, transférer, modifier en lot — les actions natives de
  l'objet, offertes selon vos droits sur lui. Il fallait auparavant ouvrir chaque fiche l'une après l'autre.
  Le bouton « Actions » n'apparaît **qu'une fois un élément coché**, comme dans les listes de GLPI : ouvrir le menu
  sans rien avoir sélectionné ne pouvait donner qu'une liste d'actions vide, puisque GLPI déduit le type d'objet
  des cases cochées. Et la sélection ne survit plus d'une page à l'autre — on arrivait sinon sur une liste avec des
  cases déjà cochées sans avoir rien fait.

- **Les vignettes de la page Sondes s'alignent.** Trois cartes de trois hauteurs différentes : cette page était la
  seule à fabriquer ses vignettes à la main, un libellé long poussant sa carte plus haut que les voisines. Elle
  reprend la barre déjà utilisée par « Contrôle de la remontée ».

- **« Statut GLPI des PC sondes » rejoint les réglages**, sur « Installeur GLPI Agent ». C'était un formulaire
  d'administrateur posé au milieu d'un écran de consultation : on venait y regarder, on tombait sur un réglage.

- **« Pas de réponse » n'est plus écrit comme une panne.** Un agent installé et qui fonctionne affichait
  « Statut de « … » : pas de réponse » en avertissement. Ce n'était pas un défaut : GLPI appelait le PC sur son
  port 62354, en entrant, ce qui ne marche pas derrière la box d'un client — et n'a pas à marcher. **L'agent
  travaille en tirage : c'est lui qui appelle GLPI**, et GLPI lui rend alors la liste des tâches à exécuter. Le
  message devient une information, accompagnée de ce qui prouve vraiment qu'une sonde fonctionne : son dernier
  contact. Une seule situation reste un avertissement, et c'en est un vrai : une sonde **qui n'a jamais contacté
  GLPI**.

- **« Allez sur le PC et cliquez Force an Inventory » n'est plus la consigne.** L'assistant annonçait ce geste
  comme la solution, ce qui laissait croire qu'à distance on était bloqué. C'est faux : une découverte préparée
  part toute seule au prochain appel de la sonde. L'assistant annonce donc l'**échéance** — « au plus tard le
  22/09/2026 à 08:14 » — calculée depuis le dernier contact et le réglage natif *Administration → Inventaire,
  Fréquence d'inventaire (en heures)* (24 h par défaut, réglable de 1 à 240 ; le lien y mène pour l'administrateur).
  Le geste sur place reste, replié : ce qu'il fait gagner, c'est l'attente, rien d'autre.

- **Le lieu et le contrat se choisissent avec les outils de GLPI.** Le lieu était un champ texte où l'on tapait
  « Siège > Bâtiment B » : commode, mais une faute de frappe créait un lieu jumeau, et il n'y avait pas de bouton
  pour en créer un vrai. C'est désormais le sélecteur de lieux de GLPI, avec son bouton « + » qui ouvre le
  formulaire de création habituel. Le contrat passe lui aussi au sélecteur natif.

- **Une liste de contrats vide dit pourquoi elle est vide.** Un menu « — » et rien d'autre se lit comme une panne.
  GLPI ne montre, dans une entité, que les contrats de cette entité et ceux d'une entité parente cochés « visible
  dans les sous-entités » : le message dit lequel des deux cas s'applique et où corriger. Au passage, les lieux
  suivent maintenant la même règle : ceux d'une entité parente partagée manquaient à la liste.

- **L'assistant de raccordement n'affiche plus qu'une étape à la fois.** Il déroulait huit cartes et six tableaux
  d'un seul tenant, du choix de la sonde jusqu'au journal. La barre 1 → 5 devient la navigation : les étapes
  ouvrables sont cliquables, on revient corriger un oubli quand on veut, et l'enregistrement ramène là où l'on
  travaillait. La numérotation redevient franche — « 2 bis » n'était le numéro de rien :

      1. Sonde   2. Adresses des imprimantes   3. Lieu, commentaire, contrat
      4. Configuration et découverte           5. Application aux imprimantes

  Le journal du raccordement se replie, et les titres de cartes ne répètent plus le numéro que la barre porte déjà.

- **On ouvre une ligne de liste en cliquant n'importe où dessus** — raccordements, sondes, agents et imprimantes du
  contrôle de la remontée. Le lien tenait dans un nom ou dans le chiffre de la colonne « N° », qu'il fallait viser.

- **La carte « Dernière version, mise à jour automatique et PC sondes » s'appelle désormais « Prochains paquets,
  version de référence et repérage des sondes »** (page « Installeur GLPI Agent »). L'ancien titre se lisait comme une
  télécommande : on croyait y piloter la mise à jour des agents déjà installés. Ce n'est pas ce qu'il fait — et le
  plugin ne pousse aucune mise à jour. Ces quatre réglages disent la version publiée qui sert de référence (pour
  constater un retard sur la page « Sondes »), ce que **les prochains paquets** contiendront (tâche de mise à jour
  posée ou non, version visée) et le statut GLPI qui repère les PC sondes dans le parc. Aucun ne touche un PC déjà
  installé : pour cela, il faut lancer la consigne de la sonde sur le PC.

- **Un seul fichier à donner au technicien, et une vraie fenêtre** pour installer une sonde Windows. Deux nouveautés
  qui vont ensemble :

  - **L'installation pose ses questions dans une fenêtre**, plus dans la console. Le paquet ZIP emporte
    `fichiers/fenetre-installation.ps1` (PowerShell/WinForms, présent sur tout Windows 10 et 11) : elle rappelle le
    client, le TAG et le serveur — rien à saisir — et propose « Mettre à jour l'agent automatiquement », **décochée
    par défaut**. Puis le `.bat` installe. Sans PowerShell, la question revient en console comme avant (20 s, non par
    défaut) : une interface est un confort, jamais un passage obligé.

  - **Le technicien saisit les adresses des imprimantes sur le PC, et n'a plus rien à faire dans GLPI.** La fenêtre
    d'installation demande les adresses (`192.168.1.0/24`, une plage, une liste) et la communauté SNMP (préremplie
    `public`) ; le fichier les remonte avec son compte rendu, et **GLPI crée le raccordement tout seul** : plage IP,
    identifiants SNMP, tâches de découverte et d'inventaire. C'est un vrai raccordement, visible dans son écran avec
    son journal : vérifiable, corrigible, jamais caché. Sans communauté saisie, la création s'arrête après les
    adresses plutôt que d'inventer des identifiants.

  - **Le technicien choisit aussi la fréquence des relevés**, dans la même fenêtre : toutes les heures, 3 h, 6 h,
    une fois par jour (choisi d'avance), toutes les 2 semaines, une fois par mois. Ce choix devient la fréquence de
    relevés de l'entité — donc il replanifie les tâches **et** fait suivre le délai au-delà duquel GLPI signale
    qu'une imprimante ne remonte plus : un parc relevé une fois par mois n'est plus signalé en retard au bout de
    trois jours. L'alerte « sonde sans contact », elle, ne bouge pas : l'agent contacte GLPI à la fréquence
    d'inventaire de GLPI, qu'il scanne ou non, et la faire suivre reviendrait à ne plus voir une sonde morte.

  - **Sans le plugin GLPI Inventory, c'est le PC qui scanne.** Le plugin voisin apporte la planification des tâches
    réseau ; la réception des inventaires, elle, est native dans GLPI. Quand il manque, le fichier d'installation pose
    une tâche quotidienne sur le PC : `glpi-netdiscovery` balaie la plage, `glpi-injector` pousse les résultats vers
    `front/inventory.php`. Les imprimantes remontent pareil. La plage est calculée par le serveur, avec l'analyseur
    d'adresses déjà éprouvé du plugin. Windows et Linux.

  - **Un bouton pour retirer une sonde**, sous ceux qui l'installent, un fichier par système. Il retire les tâches
    planifiées posées par le plugin, ses fichiers, puis l'agent lui-même (registre sous Windows, gestionnaire de
    paquets sous Linux, service et paquet sous macOS), et déclare à GLPI que ce PC n'est plus une sonde — sans quoi
    la fiche continuerait d'afficher une tâche posée sur une machine qui n'a plus d'agent.

  - **La page « Installeur GLPI Agent » perd sa carte fourre-tout.** « Prochains paquets, version de référence et
    repérage des sondes » rassemblait quatre choses sans rien en commun. La dernière version publiée et son bouton de
    vérification ont rejoint la version des agents, « Mise à jour automatique des sondes » les paramètres des paquets,
    et le statut GLPI des PC sondes est parti sur la page « Sondes », à côté du bouton qui le pose. La saisie de
    secours de la dernière version ne s'affiche plus que si GitHub n'a pas répondu — affichée en permanence, elle
    invitait à remplir un champ inutile qui prenait ensuite le pas sur GitHub, en silence. Il reste trois cartes et un
    seul bouton « Enregistrer ».

  - **Corrigé : la tâche mensuelle réinstallait l'agent pour rien, tous les mois.** Sous Windows, dès qu'une version
    cible était réglée, la tâche lançait `winget install --version X --force` **sans jamais comparer à la version
    installée** — l'agent était donc réinstallé chaque mois, service arrêté et relancé au passage, alors qu'il était
    déjà à jour. Le script Linux, lui, comparait. La tâche Windows regarde désormais avant d'agir.

  - **Il n'y a plus qu'une seule version : « Version des agents ».** Elle était en trois exemplaires (par sonde,
    « cible du parc », « servie ») qui disaient la même chose à des moments différents, sans rien pour garantir
    qu'elles la disent pareil — un parc qui vise une version que le serveur ne distribue pas ne se met jamais à jour.
    Celle qui reste décide du fichier distribué aux nouvelles sondes **et** de ce vers quoi les sondes déjà
    installées convergent. « À jour » veut donc dire « dans la version du parc ».

  - **Les versions se choisissent dans une liste, elles ne se tapent plus.** « Version servie aux nouvelles sondes »
    et « Version cible du parc » sont devenues des menus déroulants alimentés par les releases publiées sur GitHub
    (mémorisées un jour, une tentative par jour même en échec). Une faute de frappe y passait inaperçue : le serveur
    cherchait un fichier qui n'existe pas. Au passage, un libellé faux corrigé — « Version épinglée (vide : dernière
    vérifiée) » ne donnait pas la dernière version publiée mais la version de référence du plugin, et c'est ce champ
    qui décide du fichier distribué. Si GitHub n'a jamais répondu, le champ libre revient avec la raison ; une version
    réglée mais absente de la liste reste proposée, pour qu'un épinglage ne disparaisse jamais en silence.

  - **Un bouton « Mettre à jour maintenant ».** Il manquait le geste le plus évident : la tâche planifiée attend le
    1er du mois, le fichier d'installation réinstalle tout, et entre les deux il n'y avait rien. Ce fichier lance le
    script de mise à jour une fois, tout de suite, sans toucher à l'automatisation — et affiche le journal à la fin,
    parce qu'il ne fait rien quand l'agent travaille et qu'il faut pouvoir lire pourquoi. Windows et Linux.

  - **La « Version cible » par sonde disparaît aussi.** Elle faisait doublon avec celle du parc (page « Installeur
    GLPI Agent ») et se répétait sur chaque fiche, alors qu'une version qui casse quelque chose casse partout. Il n'y
    a donc plus **aucun** réglage par sonde : la fiche montre ce que le PC a déclaré, et trois boutons qui disent ce
    qu'ils fabriquent. Ce qu'on perd, et qui est assumé : retenir une seule sonde sur une vieille version.

  - **La case « Mise à jour automatique » de la fiche d'une sonde disparaît, remplacée par deux boutons** : « Poser
    la mise à jour automatique » et « Retirer la mise à jour automatique ». Elle ne faisait rien au clic — son seul
    rôle était de dire ce que ferait le fichier de consigne — et elle pouvait donc afficher « oui » pour un PC où
    personne n'avait rien posé. Désormais GLPI ne garde aucun souhait : deux actions qui disent ce qu'elles
    fabriquent, et une seule source d'information sur l'état du PC, ce que l'installation a déclaré. La « version
    cible » reste, elle : c'est elle qui dit de quelle version la sonde doit être, et elle pilote le badge de
    conformité comme ce que la tâche installera.

  - **Qui décide de la mise à jour automatique : le serveur d'abord, le technicien à défaut.** Le réglage du serveur
    s'appelle désormais « Mise à jour automatique des sondes », et ce sont **deux boutons radio** qui écrivent les
    deux branches au lieu d'en sous-entendre une : « Toujours posée » (le fichier l'installe sans rien demander) ou
    « Laissée au technicien » (la case apparaît dans la fenêtre d'installation, décochée, et il décide sur place).
    L'ancienne case et son « sinon » renvoyaient à une case que le lecteur n'a pas sous les yeux — elle est dans la
    fenêtre qui s'ouvrira plus tard, sur le PC du client. La tâche est donc posée dès que l'un des deux la veut. Avant, le serveur
    disait « poser la mise à jour » et la fenêtre demandait quand même : deux avis pour une même décision, et le
    réglage du serveur ne servait qu'à autoriser la question.

  - **L'écran d'une sonde ne prétend plus savoir ce qu'il ignore.** En dernier geste, l'installation déclare à GLPI si
    la tâche a été posée sur ce PC (seconde clé à usage unique, `front/agentreport.php`). La fiche de la sonde montre
    désormais les deux : le réglage souhaité, et ce que l'installation a déclaré — **en signalant l'écart**. Jusqu'ici
    la case restait cochée par défaut pour un PC où personne n'avait rien posé, et rien ne le disait. Un compte rendu
    dit ce qui a été fait ce jour-là, pas ce que quelqu'un a changé depuis, et le texte le formule ainsi.

  - **L'installation dit où elle en est.** Sous Windows, une petite fenêtre d'avancement s'ouvre derrière : étape en
    cours, barre, et « 12 Mo sur 22 Mo » pendant le téléchargement — qui se fait désormais morceau par morceau, seule
    façon de savoir où l'on en est. L'installation elle-même n'a pas d'avancement à donner : barre défilante, et la
    fenêtre reste vivante parce qu'on interroge le processus au lieu de l'attendre. Sous Linux et macOS, l'avancement
    s'affiche dans le terminal (`curl --progress-bar`), là où le technicien se trouve déjà.
    Vérifié pour de vrai contre un petit serveur local : 23 068 672 octets envoyés, 23 068 672 reçus, 89 mises à jour
    de 1 % à 100 %.

  - **L'installation ne pose plus aucune question** (`/qn /norestart` sous Windows, `--silent` sous Linux ;
    `installer -pkg` l'était déjà sous macOS). Tout est renseigné d'avance : l'assistant de GLPI Agent n'avait plus
    rien à demander, il ne donnait qu'une occasion de se tromper. `/norestart` est volontaire : sans lui, l'installeur
    peut redémarrer le PC de lui-même pendant que quelqu'un travaille.

  - **La fenêtre a été refaite** : bandeau bleu avec ce qu'on installe, carte claire avec le client et le TAG, notes
    en gris, pied de page pour les boutons. Et surtout, **plus aucune hauteur devinée** : chaque bloc de texte se
    dimensionne tout seul et le suivant se pose dessous, si bien qu'un nom de client à rallonge, une traduction plus
    longue ou un écran réglé à 125 % ne coupent plus rien. Deux phrases en moins : « Ces valeurs sont déjà réglées :
    rien à saisir » (la fenêtre ne demande rien, le dire n'apprend rien) et « Version visée : la dernière publiée »
    (ce n'est pas une version — la ligne ne s'affiche plus que lorsqu'une version précise est épinglée).

  - **Les trois boutons (Windows, Linux, macOS) servent désormais un seul fichier chacun**, rien à extraire, rien à
    choisir dans un dossier. Chacun ouvre une fenêtre, télécharge l'installeur officiel depuis ce serveur GLPI,
    **vérifie son empreinte SHA-256** et installe. Sous Linux il pose aussi les réessais SNMP et la tâche cron de mise
    à jour si on l'a acceptée ; sous macOS il lit la puce du Mac, ne télécharge que le paquet correspondant, dépose
    local.cfg et relance le service — les trois commandes qu'il fallait coller à la main.
    L'installeur n'est pas dans le fichier : plusieurs mégaoctets encodés dans un script sont le motif que les
    antivirus refusent le plus volontiers, et un exécutable fabriqué ici ne serait pas signé. Chaque fichier emporte
    donc une **clé de récupération à usage unique, valable 24 h** (`front/agentpull.php`, seule page du plugin
    joignable sans être connecté — le PC d'un client n'a aucun compte GLPI). Ce que la clé ouvre : les fichiers
    officiels de ce système, déjà vérifiés, et rien d'autre. Ce sont les seuls livrables du plugin qui portent un
    secret : ils se donnent au technicien pour l'intervention, ils ne s'archivent pas.
    Les archives complètes (ZIP, .tar.gz), sans aucune clé, restent produites et atteignables depuis le panneau
    replié « Comment lancer le fichier téléchargé » : c'est le recours quand l'antivirus d'un client refuse les
    scripts, pas le chemin normal.

  Le harnais télécharge les trois fichiers, **fait analyser les deux scripts shell par « sh -n »**, relit la moitié cmd
  du `.bat` (ASCII pur, arrêtée avant la partie PowerShell), puis se sert de chaque clé **sans être connecté** : elle
  rend bien l'installeur officiel, le second appel est refusé, et une clé Windows ne sert pas le paquet macOS.

- **« Tester la connexion » (GLS et MBE) répond dans une fenêtre**, sans recharger la page : la saisie en cours
  n'est pas perdue et le test se relance autant de fois qu'on veut. L'appel passe par `ajax/test_connection.php`, où
  GLPI conserve le jeton CSRF. Sans JavaScript, le bouton reste un bouton d'envoi et le résultat s'affiche en
  message, comme avant.

- **La proposition automatique des demandes d'envoi a son interrupteur dans les réglages** : carte « Proposition
  automatique des demandes d'envoi », activable et coupable au même endroit. Ce n'est pas une correction mais un
  choix de fonctionnement, sa place n'était pas dans la carte de santé. La carte dit avant le clic ce que
  l'activation entraîne — chaque ligne proposée bloque la commande de sa cartouche depuis l'écran Alertes jusqu'à son
  export ou son annulation — et rappelle que couper n'efface pas les propositions déjà faites.

- **Le paquet Windows de la sonde n'a plus qu'un seul fichier à lancer** : `INSTALLER-GLPI-AGENT.bat`. Il vérifie
  qu'il est administrateur, ouvre la fenêtre d'installation, installe le MSI officiel, puis pose la mise à jour
  automatique mensuelle si la case était cochée. Les deux .bat numérotés obligeaient celui qui ouvrait le dossier à
  deviner lequel lancer et dans quel ordre ; la séparation des deux décisions est gardée, mais dans la fenêtre, pas
  dans les fichiers. Tout le reste du paquet (MSI, script de la tâche, fenêtre, commande de secours) est descendu
  dans le sous-dossier `fichiers/` : à la racine, il ne reste que ce qui se lance et ce qui se lit. Le LISEZMOI commence désormais par « UN SEUL FICHIER À LANCER », et le harnais vérifie qu'il n'y a qu'un
  seul `.bat` dans le paquet.

### Corrigé

- **Le formulaire de configuration ne portait aucun jeton CSRF.** GLPI émet le sien tout en bas, juste avant
  `</form>`, après une longue suite de gabarits et de tables — et il n'arrivait pas dans le formulaire. Tout envoi
  partait donc sans jeton et GLPI le refusait : « Tester la connexion » rendait une erreur d'accès, « Enregistrer »
  ne faisait rien du tout. Le plugin pose désormais son propre jeton en tête de formulaire, hors de toute table, et
  rattache le bouton « Enregistrer » au formulaire par l'attribut `form=` — deux garanties en HTML standard, qui ne
  dépendent plus de ce que font les gabarits.

- **Le bouton « Enregistrer » de la configuration ne partait pas.** Le garde-fou anti-double-clic s'appliquait au
  formulaire entier dès qu'il contenait une action lente — or la carte « Santé de la configuration » en pose une
  (« J'ai vérifié », création de la règle TAG). « Enregistrer » recevait alors la classe `disabled`, sur laquelle
  Bootstrap coupe les clics : bouton mort, sans message et sans rien dans les journaux, puisque aucune requête ne
  partait. Le garde-fou suit désormais le bouton cliqué, et lui seul.
- Le passage en « livrée » sur BL signé était en échec à chaque appel (alias de colonne SQL mal formé), et
  l'appariement MBE ne trouvait jamais rien (un `IN` sur `NULL` ne rapproche aucune ligne, et la clause de jointure
  des BL était mal formée). Les trois requêtes sont désormais exécutées par le harnais, pas seulement relues.

- **Une expédition peut enfin passer « livrée » toute seule.** Jusqu'ici, seul un BL signé le faisait ; le suivi GLS
  s'affichait sans rien décider et MBE ne servait qu'à tester la connexion. Trois sources constatent maintenant une
  livraison, dans cet ordre de force : BL signé, événement de livraison GLS, statut MBE. Un seul endroit décide
  (`Delivery`), une livraison constatée ne se reprend jamais, et « livrée » reste un envoi en cours — le verrou
  anti-doublon n'est pas levé, seule la pose détectée clôt.
- **MBE est branché** : appariement d'une expédition avec son expédition MBE par le **numéro de BL** des notes MBE
  (puis par le numéro transporteur en filet), puis lecture de son statut de livraison. L'API MBE n'offrant aucun
  filtre sur ces numéros, la fenêtre de dates est balayée **une fois par passage pour toutes les expéditions à
  apparier**, jamais une fois par expédition.
- **Double contrôle MBE + GLS** : le statut MBE reste « en attente de livraison » des jours après une remise déjà
  publiée par le transporteur. Le plugin recoupe donc chaque statut MBE avec celui que GLS vient d'écrire dans le même
  passage — sans un appel de plus — et c'est le transporteur qui l'emporte.
- **Nouvelle alerte « Livraison en retard »** sur l'écran Expéditions : partie et toujours pas livrée au-delà du délai
  du transporteur, en jours ouvrés (GLS 3, UPS 6, Chronopost 4, 4 par défaut). Aucun délai ne fait jamais passer une
  expédition « livrée » : il n'y a pas de livraison sans constat.
- **Cadence** : la tâche de suivi passe de toutes les heures à **deux fois par jour** (les deux suivis s'en
  contentent). Le quota MBE est de 500 appels par jour, arrêt à 80 % ; MBE garde en plus son propre délai minimal de
  onze heures entre deux passages, pour que remettre la tâche à l'heure dans GLPI n'épuise pas le quota.
- **Les boutons « Tester la connexion » de GLS et de MBE sont maintenant toujours visibles.** Ils n'apparaissaient
  qu'une fois les clés enregistrées : devant deux champs vides, rien ne disait qu'un test existait. Ils sont désormais
  affichés en permanence et restent cliquables — un bouton désactivé est délavé par le thème au point de disparaître
  sur fond blanc. Une ligne sous le bouton liste ce qui reste à enregistrer, et le clic sans clés répond « non saisis »
  sans appeler l'API : le test appelle l'API avec ce qui est **en base**, jamais avec ce qui est saisi à l'écran.
- Le test MBE passe par la même lecture de XML que le suivi : il ne prouve plus seulement que les identifiants sont
  acceptés, mais que la réponse est comprise, et il compte les expéditions dont les notes portent un numéro de BL
  lisible — de quoi savoir tout de suite si l'appariement par numéro de BL pourra fonctionner.
- La date de livraison retenue est celle de la source — signature du BL, événement GLS, date MBE — et non celle du
  passage de la tâche : un rattrapage ne réécrit plus l'histoire.

- **Liaison avec le plugin Gestion, sur trois couches.** Le passage d'une expédition en « livrée » sur BL signé est
  **immédiat** : le plugin Gestion appelle Print Gestion juste après chaque signature. En secours, les écrans
  Expéditions et Alertes reprennent les BL signés à leur ouverture (lecture directe de la table des BL, deux
  jointures locales), et la tâche **horaire** rattrape le reste — au pire une heure. Les trois appels vivent dans le
  plugin Gestion : une mise à jour de Gestion peut les emporter, et le harnais vérifie qu'ils sont toujours là.
- L'écran des alertes lit une table matérialisée qui garde le motif de verrou : une livraison constatée la marque
  désormais périmée, au lieu d'afficher un verrou d'avant la livraison.
- Nouvelle carte de configuration « Liaison avec le plugin Gestion », avec un interrupteur « Activée », **oui par
  défaut**. Elle ne s'affiche pas du tout sur un GLPI qui n'a jamais eu le plugin Gestion, et dit en toutes lettres
  quand le plugin est là mais inactif. Coupée, plus aucun BL signé ne fait passer d'expédition en « livrée » et
  l'association de BL à une expédition est refusée.
- La tâche du suivi n'est plus affichée deux fois dans la configuration : la carte « Suivi des expéditions : tâche
  automatique » est supprimée (elle répétait le tableau de la carte de santé), et son avertissement de fréquence est
  repris sous ce tableau. La tâche est renommée « Suivi des colis GLS (et rattrapage des BL signés du plugin
  Gestion) » : le suivi des colis est le seul de ses travaux qui exige un passage horaire.

- **Santé de la configuration, actions automatiques** : le bouton unique « Passer et activer les N tâches en CLI » est
  remplacé par un bouton par correction, affiché seulement s'il a quelque chose à corriger — « Passer les N tâches en
  mode CLI » (le mode seulement), « Activer les N tâches désactivées » (l'état seulement), « Débloquer les N tâches
  bloquées », « Déclarer le cron système (GLPI_SYSTEM_CRON) » et « Activer la proposition automatique des demandes
  d'envoi ». Chacun se clique seul : passer en CLI sans activer, activer sans passer en CLI, et ainsi de suite.
- La ligne dit désormais ce qui reste à corriger, correction par correction, et le dit en toutes lettres quand tout est
  réglé dans GLPI alors que le cron du serveur manque encore : aucun bouton de la carte ne peut le créer. Le détail
  affiche la ligne de crontab à recopier tant que la tâche témoin n'est pas passée.
- Une tâche du plugin coincée « en cours d'exécution » rend maintenant la ligne rouge au lieu d'être corrigée en
  silence.

## 1.0.0 — 2026-09-18

Première version publiée. Elle s'installe sur une base vierge du plugin (aucun chemin de mise à jour depuis les
versions de développement : désinstaller d'abord). Testée avec GLPI 11.0.8 et GLPI Inventory 1.6.10.

- **Collecte** : déploiement de GLPI Agent (Windows, Linux, macOS) avec TAG d'entité et règle d'affectation unique,
  assistant de raccordement des imprimantes, fréquence des relevés par entité, conformité et mise à jour des sondes,
  alertes de sondes muettes, contrôle de la remontée.
- **Toner** : relevés SNMP, détection des changements de cartouche, alertes et seuils par imprimante, verrous
  anti-double-envoi, demandes d'envoi proposées, validées, exportées.
- **Commandes** : fichier Gesconso (Sage) construit depuis l'entité qui porte le code client (son nom = code, ses commentaires =
  intitulé de livraison), contrôles avant envoi avec décompte de ce qui mérite d'être vu, transmission aux Achats enregistrée
  avant l'envoi et renvoyable, référentiels Sage (adresses, articles) importés par fichier pour vérification.
- **Expéditions** : suivi des colis GLS (jeton chiffré en cache, tâche horaire, quota, disjoncteur, multi-colis),
  affichage identique pour tous les profils, rien sans clés ; lien avec le plugin Gestion déduit (BL signé) ;
  identifiants MBE (intermédiaire de transport) enregistrés chiffrés et testables, rien d'autre encore.
- **Coût à la page** et facturation par contrat.
- **Configuration** : carte « Santé de la configuration » (prérequis GLPI vérifiés, jamais redéfinis), modules
  activables, gabarits de mail jamais réécrits à la mise à jour, aucun réglage que GLPI possède déjà.
- **Sécurité** : cloisonnement par entité à chaque point d'entrée, secrets chiffrés (GLPIKey) jamais réaffichés,
  harnais de tests rejouable (`tests/`), aucune donnée réelle dans le dépôt.
