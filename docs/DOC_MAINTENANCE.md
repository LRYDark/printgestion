# Print Gestion — Documentation de maintenance

> Comment maintenir, mettre à jour et dépanner le plugin sans casser l'existant.
> Pour comprendre le fonctionnement interne, lire d'abord [DOC_TECHNIQUE.md](DOC_TECHNIQUE.md).

---

## 1. Mettre à jour le plugin (procédure standard)

1. Déployer les fichiers dans `plugins/printgestion/` (remplacer le dossier).
2. Si `setup.php`, `hook.php` ou un `install()` de classe a changé :
   GLPI → Configuration → Plugins → Print Gestion → **« Mettre à jour »** (ou désactiver/réactiver
   selon le cas). Cela rejoue `plugin_printgestion_install()` qui est **idempotent** :
   - `CREATE TABLE IF NOT EXISTS` sur toutes les tables (ne touche pas aux données existantes) ;
   - réenregistre les 3 crons ;
   - **réécrit les gabarits mail** (voir §3 — écrase les éditions manuelles fr_FR).
3. Incrémenter le **jeton anti-cache** des assets si `public/css/*` ou `public/js/*` a changé :
   dans `setup.php`, variable `$cb = '?b=N'` → passer à `N+1`. Sinon les navigateurs gardent
   l'ancien JS/CSS en cache.
4. Vérifier le bouton **« Qui est notifié ? »** dans la configuration (récapitulatif des
   notifications selon la config réellement enregistrée — signale les gabarits non configurés).

---

## 2. Schéma BDD : PAS de migration à chaud

**Design assumé** : tout le schéma est créé à l'installation ; il n'y a pas de système de
migration incrémentale. Conséquences :

- **Ajouter une table** : ajouter le `CREATE TABLE IF NOT EXISTS` dans l'`install()` de la classe
  concernée (ou `config.class.php::install()`), puis « Mettre à jour » le plugin. Sans risque.
- **Ajouter une colonne à une table existante** : le `CREATE TABLE IF NOT EXISTS` ne la créera PAS
  sur les installations existantes. Deux options :
  1. (préférée) ajouter la colonne **à la main** en SQL sur l'instance (`ALTER TABLE ... ADD ...`)
     ET dans le `CREATE TABLE` du code (pour les installations neuves) ;
  2. désinstaller/réinstaller le plugin — **DESTRUCTIF** : toutes les données métier du plugin
     sont perdues (expéditions, relevés, alertes, tarifs…). À éviter en production.
  - Cas particulier : `config.class.php` contient un bloc d'ajout de colonnes de config
    (tableau `champ => définition` appliqué si la colonne manque) — pour une colonne de
    **configuration**, passer par ce mécanisme.
- **⚠️ Gotcha vécu** : un bloc « cleanup d'anciennes tables » dans `install()` ne doit JAMAIS
  contenir une table vivante. `..._billing_view` (table ACTIVE) avait été listée dans un drop
  → créée puis droppée à chaque install. Tables vivantes à ne jamais dropper :
  `_alertview` et `_billing_view`.

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
| `AccessDeniedHttpException` sur un POST ajax | Double validation CSRF : le `CheckCsrfListener` de GLPI 11 valide et CONSOMME le token avant le fichier ajax | Ne PAS appeler `Session::checkCSRF()` dans les fichiers `ajax/` (commentaire en tête de `send_cartridge.php`) |
| Formulaire qui casse après `showFormButtons()` | `Html::closeForm()` ajouté après `showFormButtons()` (qui ferme déjà le form) | Ne pas doubler la fermeture |

---

## 6. Dépannage

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
- Dashboard alertes : bouton « Rafraîchir » force `Alertview::rebuild()` + invalidation cache.
- Seuils : config globale + seuils par imprimante (`printer_thresholds`).

### Le stock affiché est faux

Stock = cartouches du `CartridgeItem` résolu avec `date_use IS NULL AND date_out IS NULL`.
Vérifier le binding (onglet Print Gestion de la cartouche, ou mapping SNMP constructeur) :
`Snmpmapping::resolveCartridgeItemForSnmp()` doit retrouver le bon modèle.

### Excel de commande vide/incomplet

`buildPurchaseRowData()` lit : entité de l'imprimante (Intitulé Client), racine du lieu
(Intitulé Livraison), `ref` du CartridgeItem (Consommable), n° série + lieu + nom modèle
(Designation), commentaire du lieu (Complément). Champs vides dans l'Excel = données manquantes
sur la fiche imprimante/lieu/cartouche GLPI.

---

## 7. Compatibilité et montée de version GLPI

- Bornes dans `setup.php` : `PLUGIN_PRINTGESTION_MIN_GLPI` / `MAX_GLPI` (actuel : 11.0.0 → 11.1.0).
  Après validation sur une nouvelle version GLPI, relever la borne max.
- Points sensibles à re-tester lors d'une montée GLPI :
  - `GLPIMailer` / Symfony Mailer (`Config::sendMail`, `Expedition::sendRawMail`) ;
  - moteur Search (tables matérialisées, `addDefaultWhere`, mapping itemtype) ;
  - `glpi_printers_cartridgeinfos` (structure de l'inventaire SNMP) ;
  - validation CSRF des endpoints ajax (`CheckCsrfListener`).

---

## 8. Checklist avant mise en production

- [ ] `php -l` sur tous les fichiers modifiés.
- [ ] « Mettre à jour » le plugin sur un environnement de test (install idempotente, pas d'erreur).
- [ ] Jeton anti-cache incrémenté si JS/CSS modifié.
- [ ] `php tools/generate_apercu.php` exécuté si gabarits modifiés ; aperçu relu.
- [ ] Test d'un envoi de chaque circuit modifié (unitaire, groupé, commande, crons).
- [ ] « Qui est notifié ? » cohérent avec l'attendu.
- [ ] Pas de nouvelle table/colonne oubliée pour les instances existantes (§2).
