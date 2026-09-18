"""État d'une installation du plugin, pour la preuve d'installation : ce que le plugin a posé en base, sous une forme
comparable d'une base à l'autre.
- Tables du plugin : colonnes (type, nullité, défaut, extra, jeu de caractères, collation, commentaire), index (unicité,
  colonnes dans l'ordre), moteur, collation et commentaire de table — lus dans information_schema, jamais dans un
  SHOW CREATE TABLE à parser.
- Lignes de référence : contenu des tables de 50 lignes au plus (sur une base sans jeu de données, ce sont les seules).
- Objets natifs : tâches automatiques, notifications (cibles, gabarits liés), gabarits et traductions (empreinte du
  contenu), droits par profil, valeurs de configuration du contexte du plugin, préférences d'affichage, recherches
  enregistrées, règles portant un nom du plugin.
Usage : python3 etat_installation.py sortie.json
"""
import hashlib
import json
import sys

import lib

PREFIXE = "glpi\\_plugin\\_printgestion\\_"


def requete(sql):
    """Lignes d'une requête, en dictionnaires (en-tête de la première ligne)."""
    brut = lib.sql(sql).rstrip("\n")
    if not brut:
        return []
    lignes = brut.split("\n")
    colonnes = lignes[0].split("\t")
    return [dict(zip(colonnes, ligne.split("\t"))) for ligne in lignes[1:]]


def tables():
    return [r["table_name"] for r in requete("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() "
                                             f"AND table_name LIKE '{PREFIXE}%' ORDER BY table_name")]


def etat_table(table):
    colonnes = requete("SELECT column_name, column_type, is_nullable, column_default, extra, generation_expression, character_set_name, collation_name, column_comment "
                       f"FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{table}' ORDER BY ordinal_position")
    index = {}
    for r in requete("SELECT index_name, non_unique, seq_in_index, column_name, sub_part FROM information_schema.statistics "
                     f"WHERE table_schema = DATABASE() AND table_name = '{table}' ORDER BY index_name, seq_in_index"):
        index.setdefault(r["index_name"], {"unique": r["non_unique"] == "0", "colonnes": []})["colonnes"].append(
            r["column_name"] + (f"({r['sub_part']})" if r["sub_part"] not in ("NULL", "") else ""))
    options = requete(f"SELECT engine, row_format, table_collation, table_comment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{table}'")[0]
    nb = int(lib.valeur(f"SELECT COUNT(*) FROM `{table}`"))
    lignes = requete(f"SELECT * FROM `{table}` ORDER BY 1") if nb <= 50 else f"{nb} lignes"
    if isinstance(lignes, list):
        # Identifiants de gabarits : ils dépendent de l'ordre de création des modèles dans GLPI, pas du schéma.
        for ligne in lignes:
            for cle in list(ligne):
                if cle.startswith("gabarit_") and ligne[cle] != "NULL":
                    ligne[cle] = "<id>"
    return {"colonnes": colonnes, "index": index, "options": options, "lignes": lignes}


def natifs():
    return {
        "taches": requete("SELECT itemtype, name, frequency, state, mode, allowmode, hourmin, hourmax, logs_lifetime, param FROM glpi_crontasks "
                          "WHERE itemtype LIKE 'PluginPrintgestion%' ORDER BY name"),
        "notifications": requete("SELECT n.name, n.itemtype, n.event, n.is_active, n.is_recursive, n.entities_id, "
                                 "GROUP_CONCAT(DISTINCT CONCAT(t.type, ':', t.items_id) ORDER BY t.type, t.items_id) AS cibles, "
                                 "GROUP_CONCAT(DISTINCT CONCAT(nt.mode, ':', tpl.name) ORDER BY nt.mode) AS gabarits "
                                 "FROM glpi_notifications n LEFT JOIN glpi_notificationtargets t ON t.notifications_id = n.id "
                                 "LEFT JOIN glpi_notifications_notificationtemplates nt ON nt.notifications_id = n.id "
                                 "LEFT JOIN glpi_notificationtemplates tpl ON tpl.id = nt.notificationtemplates_id "
                                 "WHERE n.itemtype LIKE 'PluginPrintgestion%' OR n.comment = 'Created by plugin printgestion' GROUP BY n.id ORDER BY n.name, n.event"),
        "gabarits": [dict(r, empreinte=hashlib.sha1((r.pop("subject") + "|" + r.pop("content_html") + "|" + r.pop("content_text")).encode()).hexdigest()[:16])
                     for r in requete("SELECT tpl.name, tpl.itemtype, tr.language, tr.subject, tr.content_html, tr.content_text FROM glpi_notificationtemplates tpl "
                                      "LEFT JOIN glpi_notificationtemplatetranslations tr ON tr.notificationtemplates_id = tpl.id "
                                      "WHERE tpl.comment = 'Created by plugin printgestion' OR tpl.itemtype LIKE 'PluginPrintgestion%' ORDER BY tpl.name, tr.language")],
        "droits": requete("SELECT p.name AS profil, r.name, r.rights FROM glpi_profilerights r JOIN glpi_profiles p ON p.id = r.profiles_id "
                          "WHERE r.name LIKE 'plugin_printgestion%' ORDER BY p.name, r.name"),
        "configuration": requete("SELECT name, value FROM glpi_configs WHERE context = 'plugin:printgestion' ORDER BY name"),
        "preferences_affichage": requete("SELECT itemtype, num, rank, users_id FROM glpi_displaypreferences WHERE itemtype LIKE 'PluginPrintgestion%' ORDER BY itemtype, users_id, rank"),
        "recherches": requete("SELECT name, itemtype FROM glpi_savedsearches WHERE itemtype LIKE 'PluginPrintgestion%' ORDER BY name"),
        "regles": requete("SELECT name, sub_type, is_active FROM glpi_rules WHERE name LIKE '%Print Gestion%' OR name LIKE '%TAG%' ORDER BY name"),
    }


def main():
    etat = {"tables": {t: etat_table(t) for t in tables()}, "natifs": natifs()}
    with open(sys.argv[1], "w", encoding="utf-8") as fichier:
        json.dump(etat, fichier, ensure_ascii=False, indent=1, sort_keys=True)
    print(f"{len(etat['tables'])} tables, {len(etat['natifs']['taches'])} tâches, {len(etat['natifs']['notifications'])} notifications, "
          f"{len(etat['natifs']['gabarits'])} traductions de gabarits, {len(etat['natifs']['droits'])} droits → {sys.argv[1]}")


if __name__ == "__main__":
    main()
