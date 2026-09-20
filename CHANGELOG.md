# Journal des changements

## Non publié

- **« Tester la connexion » (GLS et MBE) répond dans une fenêtre**, sans recharger la page : la saisie en cours
  n'est pas perdue et le test se relance autant de fois qu'on veut. L'appel passe par `ajax/test_connection.php`, où
  GLPI conserve le jeton CSRF. Sans JavaScript, le bouton reste un bouton d'envoi et le résultat s'affiche en
  message, comme avant.

- **La proposition automatique des demandes d'envoi a son interrupteur dans les réglages** : carte « Proposition
  automatique des demandes d'envoi », activable et coupable au même endroit. Ce n'est pas une correction mais un
  choix de fonctionnement, sa place n'était pas dans la carte de santé. La carte dit avant le clic ce que
  l'activation entraîne — chaque ligne proposée bloque la commande de sa cartouche depuis l'écran Alertes jusqu'à son
  export ou son annulation — et rappelle que couper n'efface pas les propositions déjà faites.

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
