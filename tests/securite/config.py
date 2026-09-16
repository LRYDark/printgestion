"""Configuration du harnais, lue uniquement dans l'environnement : aucun identifiant ni adresse de serveur dans le dépôt.

Instance de test jetable seulement, jamais un serveur de production (voir tests/README.md).
"""
import os
import sys


def _required(name):
    value = os.environ.get(name, "")
    if value == "":
        sys.exit(f"Variable d'environnement {name} absente : voir tests/README.md.")
    return value


URL = os.environ.get("PG_TEST_URL", "http://127.0.0.1:8089").rstrip("/")
GLPI_DIR = _required("PG_TEST_GLPI_DIR")
PHP = os.environ.get("PG_TEST_PHP", "php")
MARIADB = os.environ.get("PG_TEST_MARIADB", "mariadb")
DB_HOST = os.environ.get("PG_TEST_DB_HOST", "127.0.0.1")
DB_PORT = os.environ.get("PG_TEST_DB_PORT", "3306")
DB_NAME = _required("PG_TEST_DB_NAME")
DB_USER = _required("PG_TEST_DB_USER")
DB_PASSWORD = _required("PG_TEST_DB_PASSWORD")
GLPI_LOGIN = _required("PG_TEST_GLPI_LOGIN")
GLPI_PASSWORD = _required("PG_TEST_GLPI_PASSWORD")
SMTP_PORT = int(os.environ.get("PG_TEST_SMTP_PORT", "2525"))
MAIL_DIR = _required("PG_TEST_MAIL_DIR")

PLUGIN = "/plugins/printgestion"
FRONT = PLUGIN + "/front"
AJAX = PLUGIN + "/ajax"
PLUGIN_DIR = os.path.join(GLPI_DIR, "plugins", "printgestion")
LOG_DIR = os.path.join(GLPI_DIR, "files", "_log")
