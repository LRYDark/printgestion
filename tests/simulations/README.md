# Simulations de test — jamais sur un serveur de production

Ce dossier contient des **simulations** utilisées seulement par le harnais `tests/securite/`, sur une instance GLPI
jetable. Aucune n'a sa place sur un serveur réel.

| Élément | Ce qu'il simule | Ce qu'il ne fait pas |
|---|---|---|
| `gestion/` | Le plugin Gestion : la table des BL `glpi_plugin_gestion_surveys` (vide) et une fausse API Sage (`front/SageApi.php` : un n° commençant par `BLOK` « existe », tout autre est inconnu) | Aucun appel réseau, aucun lien avec Sage |
| `smtp_factice.py` | Un serveur SMTP sur 127.0.0.1 qui écrit chaque mail dans un fichier `.eml` | Aucun envoi réel |

`tests/securite/instance.sh` copie `gestion/` dans le dossier `plugins/` de l'instance de test. **Ne jamais le copier
à la main dans une instance réelle** : il porte le même nom que le vrai plugin Gestion et le remplacerait.
