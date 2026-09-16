#!/bin/zsh
# Reconstruit une instance de test JETABLE : base vidée, GLPI réinstallé, Print Gestion (cette copie de travail),
# GLPI Inventory et le plugin Gestion simulé (tests/simulations/gestion), puis le jeu de données inventé.
# Jamais sur un serveur de production : la base PG_TEST_DB_NAME est supprimée puis recréée.
# Variables : voir tests/README.md.
set -e
setopt NULL_GLOB

: "${PG_TEST_INSTANCE_JETABLE:?}" "${PG_TEST_GLPI_DIR:?}" "${PG_TEST_DB_NAME:?}" "${PG_TEST_DB_USER:?}" "${PG_TEST_DB_PASSWORD:?}"
: "${PG_TEST_GLPI_LOGIN:?}" "${PG_TEST_GLPI_PASSWORD:?}" "${PG_TEST_GLPIINVENTORY_DIR:?}" "${PG_TEST_MAIL_DIR:?}"
if [ "$PG_TEST_INSTANCE_JETABLE" != "oui" ]; then echo "PG_TEST_INSTANCE_JETABLE=oui requis : cette commande efface la base."; exit 1; fi
case "$PG_TEST_DB_NAME" in *test*) ;; *) echo "Nom de base sans « test » : refusé."; exit 1 ;; esac

ICI="${0:A:h}"
DEPOT="${ICI:h:h}"
PHP="${PG_TEST_PHP:-php}"
MARIADB="${PG_TEST_MARIADB:-mariadb}"
HOTE="${PG_TEST_DB_HOST:-127.0.0.1}"
PORT="${PG_TEST_DB_PORT:-3306}"
G="$PG_TEST_GLPI_DIR"
export MYSQL_PWD="$PG_TEST_DB_PASSWORD"

"$MARIADB" -h"$HOTE" -P"$PORT" -u"$PG_TEST_DB_USER" -e "DROP DATABASE IF EXISTS \`$PG_TEST_DB_NAME\`; CREATE DATABASE \`$PG_TEST_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd "$G"
mkdir -p config plugins marketplace
for d in _cache _cron _dumps _graphs _inventories _locales _lock _log _pictures _plugins _rss _sessions _tmp _uploads; do mkdir -p "files/$d"; done
rm -f config/config_db.php config/glpicrypt.key
rm -rf files/_cache/* files/_log/* files/_sessions/*
# Le mot de passe passe par l'environnement du processus PHP, jamais par la ligne de commande (visible dans ps).
HOTE="$HOTE" PORT="$PORT" "$PHP" -r '$a = ["bin/console", "database:install", "-H", getenv("HOTE"), "-P", getenv("PORT"), "-u", getenv("PG_TEST_DB_USER"),
  "-p", getenv("PG_TEST_DB_PASSWORD"), "-d", getenv("PG_TEST_DB_NAME"), "-L", "fr_FR", "--no-telemetry", "-n"];
  $_SERVER["argv"] = $a; $_SERVER["argc"] = count($a); require "bin/console";' > files/_log/installation.log 2>&1 || { tail -5 files/_log/installation.log; exit 1; }
echo "GLPI installé"

rm -rf plugins/printgestion plugins/glpiinventory plugins/gestion
mkdir -p plugins/printgestion
/usr/bin/rsync -a --exclude '.git' --exclude '._*' --exclude '.DS_Store' "$DEPOT/" plugins/printgestion/
cp -R "$PG_TEST_GLPIINVENTORY_DIR" plugins/glpiinventory
cp -R "$DEPOT/tests/simulations/gestion" plugins/gestion
: > files/_log/php-errors.log; : > files/_log/sql-errors.log
for p in gestion printgestion glpiinventory; do
  "$PHP" bin/console plugin:install -u "$PG_TEST_GLPI_LOGIN" "$p" -n > /dev/null
  "$PHP" bin/console plugin:activate "$p" -n > /dev/null
  echo "plugin $p installé et activé"
done

# Mot de passe du compte administrateur remplacé par celui de l'environnement : aucun mot de passe par défaut utilisé.
EMPREINTE=$(printf '%s' "$PG_TEST_GLPI_PASSWORD" | "$PHP" -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);')
"$MARIADB" -h"$HOTE" -P"$PORT" -u"$PG_TEST_DB_USER" "$PG_TEST_DB_NAME" -e "UPDATE glpi_users SET password = '$EMPREINTE', password_last_update = NOW() WHERE name = '$PG_TEST_GLPI_LOGIN';"
mkdir -p "$PG_TEST_MAIL_DIR"
python3 "$ICI/donnees.py"
