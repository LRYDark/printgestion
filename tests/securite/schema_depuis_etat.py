"""Schéma du plugin à partir d'un état d'installation relevé (etat_installation.py), jamais à partir de la chaîne des
migrations : produit le corps PHP de PluginPrintgestionSchema (CREATE TABLE par table, données de référence).
Substitutions : jeu de caractères, collation et signe des clés remis aux valeurs que GLPI décide ({$charset},
{$collation}, {$key_sign}) ; `ip_num` reste un entier non signé (c'est une adresse, pas une clé).
Usage : python3 schema_depuis_etat.py etat.json > schema-genere.php
"""
import json
import sys

ENTIERS_NON_SIGNES_LITTERAUX = {"ip_num"}
GABARITS = {"gabarit_planif", "gabarit_planif_group", "gabarit_achat", "gabarit_commercial", "gabarit_rappel", "gabarit_courtoisie"}


def type_sql(colonne):
    t = colonne["column_type"]
    if t == "int(10) unsigned" and colonne["column_name"] not in ENTIERS_NON_SIGNES_LITTERAUX:
        return "int __KEY_SIGN__"
    return {"int(10) unsigned": "int unsigned", "int(11)": "int", "tinyint(4)": "tinyint", "smallint(5) unsigned": "smallint unsigned"}.get(t, t)


def ddl_colonne(colonne):
    parts = ["`" + colonne["column_name"] + "`", type_sql(colonne)]
    if colonne["extra"] == "STORED GENERATED":
        parts.append("GENERATED ALWAYS AS (" + colonne["generation_expression"] + ") STORED")
    else:
        # NULL explicite : un timestamp sans le mot NULL serait NOT NULL avec un défaut implicite selon le serveur.
        parts.append("NOT NULL" if colonne["is_nullable"] == "NO" else "NULL")
        default = colonne["column_default"]
        if default != "NULL":
            parts.append("DEFAULT " + default)
        elif colonne["is_nullable"] == "YES":
            parts.append("DEFAULT NULL")
        if colonne["extra"] == "on update current_timestamp()":
            parts.append("ON UPDATE current_timestamp()")
        if colonne["extra"] == "auto_increment":
            parts.append("AUTO_INCREMENT")
    if colonne["column_comment"]:
        parts.append("COMMENT " + sql_str(colonne["column_comment"]))
    return " ".join(parts)


def ddl_index(nom, index):
    cols = ", ".join("`" + c.replace("(", "`(") if "(" in c else "`" + c + "`" for c in index["colonnes"])
    if nom == "PRIMARY":
        return f"PRIMARY KEY ({cols})"
    return f"{'UNIQUE KEY' if index['unique'] else 'KEY'} `{nom}` ({cols})"


def php_str(s, sql=False):
    """Chaîne PHP à quotes simples : seuls « \\ » et « ' » s'échappent, aucun « $ » n'est interprété."""
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def sql_str(s):
    return "'" + s.replace("'", "''") + "'"


def creation(nom, table):
    lignes = [ddl_colonne(c) for c in table["colonnes"]] + [ddl_index(n, i) for n, i in table["index"].items()]
    options = f"ENGINE={table['options']['engine']} DEFAULT CHARSET=__CHARSET__ COLLATE=__COLLATION__ ROW_FORMAT={table['options']['row_format'].upper()}"
    if table["options"]["table_comment"]:
        options += " COMMENT=" + sql_str(table["options"]["table_comment"])
    ddl = f"CREATE TABLE `{nom}` (\n            " + ",\n            ".join(lignes) + f"\n        ) {options}"
    return f"        '{nom}' => {php_str(ddl)},"


def valeur_php(v, colonne):
    if v == "NULL" or colonne in GABARITS:
        return "null"
    return php_str(v)


def main():
    etat = json.load(open(sys.argv[1], encoding="utf-8"))
    print("    /** CREATE TABLE de chaque table (jalons __CHARSET__, __COLLATION__, __KEY_SIGN__ remplis par GLPI) ; relevé, jamais réécrit à la main. */")
    print("    const TABLES = [")
    for nom, table in etat["tables"].items():
        print(creation(nom, table))
    print("    ];")
    print()
    print("    /** Lignes de référence posées à l'installation (les identifiants de gabarits sont remplis ensuite par hook.php). */")
    print("    const SEEDS = [")
    for nom, table in etat["tables"].items():
        if isinstance(table["lignes"], list) and table["lignes"]:
            print(f"        '{nom}' => [")
            for ligne in table["lignes"]:
                champs = ", ".join(f"'{k}' => {valeur_php(v, k)}" for k, v in ligne.items())
                print(f"            [{champs}],")
            print("        ],")
    print("    ];")


if __name__ == "__main__":
    main()
