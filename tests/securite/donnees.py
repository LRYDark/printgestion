"""Jeu de données du harnais, entièrement inventé, chargé sur une instance neuve (instance.sh).

Tous les identifiants attendus par les tests sont posés ici, explicitement ; un test n'en suppose aucun autre. Ce qu'un
test crée en plus (envois, alertes, comptes) il le crée lui-même et le supprime à la fin.

Arbre des entités :
    Entité racine (0)          imprimantes 1 à 7, contrat 1
    ├── Client test A (1)      imprimantes 8 et 9, contrat 2
    │   ├── Site test A1 (2)   imprimante 10     (imprimantes 9 à 12 sans contrat : transférables par les tests)
    │   └── Site test A2 (3)   imprimante 11
    └── Client test B (4)      imprimante 12
"""
import sys

import config
import lib
from lib import sql, valeur

RACINE, CLIENT_A, SITE_A1, SITE_A2, CLIENT_B = 0, 1, 2, 3, 4
ENTITES = ((CLIENT_A, "Client test A", RACINE), (SITE_A1, "Site test A1", CLIENT_A), (SITE_A2, "Site test A2", CLIENT_A), (CLIENT_B, "Client test B", RACINE))

IMP_BAS = 1          # racine, noir à 12 % sous contrat 1 : alerte « Commander »
IMP_DOUBLON = 5      # même n° de série que l'imprimante 1
IMP_POSE = 7         # envoi en attente, cartouche posée
IMP_A1, IMP_A2 = 8, 9
IMP_SITE_A1, IMP_SITE_A2 = 10, 11
IMP_B = 12
IMPRIMANTES_A = (IMP_A1, IMP_A2)
CONTRAT_RACINE, CONTRAT_A = 1, 2
CARTOUCHE_NOIR = 1
LIEU = 1
AGENT_RECENT, AGENT_ANCIEN = 1, 2
ADMIN_ID = 2         # compte administrateur créé par l'installation de GLPI
MARQUEUR = ("printgestion_tests", "instance_jetable")

BASE = f"""
SET @now = NOW();
INSERT INTO glpi_manufacturers (id, name, date_creation, date_mod) VALUES (1,'Fabricant test Alpha',@now,@now),(2,'Fabricant test Beta',@now,@now),(3,'Fabricant test Gamma',@now,@now),(4,'Fabricant test Delta',@now,@now);
INSERT INTO glpi_printermodels (id, name, date_creation, date_mod) VALUES (1,'Modèle test A-100',@now,@now),(2,'Modèle test B-200',@now,@now),(3,'Modèle test C-300',@now,@now),(4,'Modèle test D-400',@now,@now);
INSERT INTO glpi_locations (id, entities_id, name, completename, code, level, locations_id, date_creation, date_mod) VALUES ({LIEU},0,'Lieu test 1','Lieu test 1','TSTLIV01',1,0,@now,@now);
INSERT INTO glpi_printers (id, entities_id, name, serial, manufacturers_id, printermodels_id, locations_id, is_dynamic, is_deleted, is_template, last_inventory_update, contact, date_creation, date_mod) VALUES
 (1,0,'TST-RACINE-01','TSTSN0001',1,1,1,1,0,0,@now,'Contact test',@now,@now),
 (2,0,'TST-RACINE-02','TSTSN0002',2,2,1,1,0,0,@now,'',@now,@now),
 (3,0,'TST-RACINE-03','TSTSN0003',3,3,1,1,0,0,@now,'',@now,@now),
 (4,0,'TST-RACINE-04','TSTSN0004',4,4,1,1,0,0,@now,'',@now,@now),
 (5,0,'TST-RACINE-05','TSTSN0001',1,1,1,1,0,0,@now,'',@now,@now),
 (6,0,'TST-RACINE-06','TSTSN0006',4,4,1,1,0,0,@now,'',@now,@now),
 (7,0,'TST-RACINE-07','TSTSN0007',4,4,1,1,0,0,@now,'',@now,@now),
 (8,{CLIENT_A},'TST-A-01','TSTSN0008',2,2,0,1,0,0,@now,'',@now,@now),
 (9,{CLIENT_A},'TST-A-02','TSTSN0009',1,1,0,1,0,0,@now,'',@now,@now),
 (10,{SITE_A1},'TST-A1-01','TSTSN0010',1,1,0,1,0,0,@now,'',@now,@now),
 (11,{SITE_A2},'TST-A2-01','TSTSN0011',1,1,0,1,0,0,@now,'',@now,@now),
 (12,{CLIENT_B},'TST-B-01','TSTSN0012',1,1,0,1,0,0,@now,'',@now,@now);
INSERT INTO glpi_printers_cartridgeinfos (printers_id, property, value, date_creation, date_mod) VALUES
 (1,'tonerblack','12',@now,@now),(1,'tonercyan','45',@now,@now),(1,'tonermagenta','WARNING',@now,@now),(1,'toneryellow','0',@now,@now),(1,'drumblack','60',@now,@now),(1,'wastetoner','100',@now,@now),(1,'fuserkit','1500impressions',@now,@now),
 (2,'tonerblack','OK',@now,@now),(2,'drumblack','OK',@now,@now),
 (3,'tonerblack','OK',@now,@now),(3,'tonercyan','OK',@now,@now),(3,'tonermagenta','30',@now,@now),(3,'toneryellow','150',@now,@now),(3,'wastetoner','-8500',@now,@now),
 (4,'tonerblack','8',@now,@now),(4,'tonercyan','55',@now,@now),(4,'tonermagenta','60',@now,@now),(4,'toneryellow','62',@now,@now),(4,'developerblack','40',@now,@now),(4,'maintenancekit','70',@now,@now),
 (6,'tonerblack','95',@now,@now),(7,'tonerblack','50',@now,@now),
 (8,'tonerblack','40',@now,@now),(9,'tonerblack','35',@now,@now),(10,'tonerblack','30',@now,@now),(11,'tonerblack','30',@now,@now),(12,'tonerblack','30',@now,@now);
INSERT INTO glpi_printerlogs (itemtype, items_id, total_pages, bw_pages, color_pages, date, date_creation, date_mod)
WITH RECURSIVE d(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM d WHERE n < 39)
SELECT 'Printer', p.id,
  CASE WHEN p.id = 4 AND n >= 35 THEN (n - 35) * 400 ELSE 100000 * p.id + n * (150 * p.id) END,
  CASE WHEN p.id = 3 THEN 0 WHEN p.id = 4 AND n >= 35 THEN (n - 35) * 300 ELSE 70000 * p.id + n * (100 * p.id) END,
  CASE WHEN p.id = 3 THEN 0 WHEN p.id = 4 AND n >= 35 THEN (n - 35) * 100 ELSE 30000 * p.id + n * (50 * p.id) END,
  DATE_SUB(CURDATE(), INTERVAL 39 - n DAY), @now, @now
FROM d CROSS JOIN (SELECT 1 AS id UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) p;
UPDATE glpi_printers p SET last_pages_counter = (SELECT l.total_pages FROM glpi_printerlogs l WHERE l.itemtype='Printer' AND l.items_id=p.id ORDER BY l.date DESC LIMIT 1) WHERE p.id IN (1,2,3,4);
INSERT INTO glpi_plugin_printgestion_toner_readings (printers_id, property_name, level_percent, reading_date, total_pages, bw_pages, color_pages, is_suspect)
WITH RECURSIVE d(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM d WHERE n < 30)
SELECT p.id, 'tonerblack', GREATEST(1, 42 - n), DATE_SUB(CURDATE(), INTERVAL 31 - n DAY), 100000 * p.id + (n + 8) * (150 * p.id), 70000 * p.id + (n + 8) * (100 * p.id), 30000 * p.id + (n + 8) * (50 * p.id), 0
FROM d CROSS JOIN (SELECT 1 AS id UNION ALL SELECT 4) p;
INSERT INTO glpi_plugin_printgestion_toner_readings (printers_id, property_name, level_percent, reading_date, total_pages, bw_pages, color_pages, is_suspect) VALUES
 (6,'tonerblack',6,DATE_SUB(CURDATE(), INTERVAL 2 DAY),50000,40000,10000,0),
 (6,'tonerblack',5,DATE_SUB(CURDATE(), INTERVAL 1 DAY),50200,40150,10050,0),
 (7,'tonerblack',52,DATE_SUB(CURDATE(), INTERVAL 1 DAY),20000,15000,5000,0);
INSERT INTO glpi_cartridgeitems (id, entities_id, is_recursive, name, ref, manufacturers_id, is_deleted, alarm_threshold, date_creation, date_mod) VALUES
 (1,0,1,'Toner test noir A','TST-REF-N01',1,0,0,@now,@now),(2,0,1,'Toner test cyan A','TST-REF-C01',1,0,0,@now,@now),(3,0,1,'Toner test noir D','TST-REF-N04',4,0,0,@now,@now);
INSERT INTO glpi_cartridgeitems_printermodels (cartridgeitems_id, printermodels_id) VALUES (1,1),(2,1),(3,4);
INSERT INTO glpi_plugin_printgestion_cartridge_snmp (cartridgeitems_id, snmp_property) VALUES (1,'tonerblack'),(2,'tonercyan'),(3,'tonerblack');
INSERT INTO glpi_contracttypes (id, name, date_creation, date_mod) VALUES (1,'Type test — consommables inclus',@now,@now);
INSERT INTO glpi_contracts (id, entities_id, is_recursive, name, num, contracttypes_id, begin_date, duration, is_deleted, is_template, date_creation, date_mod) VALUES
 ({CONTRAT_RACINE},0,0,'Contrat test racine','TST-CTR-001',1,'2026-01-01',36,0,0,@now,@now),
 ({CONTRAT_A},{CLIENT_A},0,'Contrat test A','TST-CTR-002',1,'2026-01-01',36,0,0,@now,@now);
INSERT INTO glpi_contracts_items (contracts_id, itemtype, items_id) VALUES ({CONTRAT_RACINE},'Printer',1),({CONTRAT_RACINE},'Printer',4),({CONTRAT_A},'Printer',8);
INSERT INTO glpi_plugin_printgestion_contractrates (contracts_id, type_cout, rate, actif, date_creation, entities_id, is_recursive) VALUES ({CONTRAT_RACINE},'nb',0.005,1,@now,0,0),({CONTRAT_RACINE},'color',0.05,1,@now,0,0),({CONTRAT_A},'nb',0.006,1,@now,{CLIENT_A},0);
UPDATE glpi_plugin_printgestion_configs SET consumables_contracttypes='1',
  mode_commercial='emails', emails_commercial='{ADMIN_ID}', mode_achat='emails', emails_achat='{ADMIN_ID}', mode_planif='emails', emails_planif='{ADMIN_ID}' WHERE id=1;
INSERT INTO glpi_plugin_printgestion_sageclients (id, code, name, is_in_last_import, date_import, date_creation, date_mod) VALUES (1,'TSTCLI01','CLIENT TEST RACINE',1,@now,@now,@now);
INSERT INTO glpi_plugin_printgestion_entitysageclients (entities_id, plugin_printgestion_sageclients_id, date_creation, date_mod) VALUES (0,1,@now,@now);
INSERT INTO glpi_plugin_printgestion_sagedeliveries (client_code, address_key, label, address, postcode, town, is_in_last_import, date_import, date_creation, date_mod) VALUES ('TSTCLI01','TSTLIV01','Adresse test 1','1 rue de l''Essai','00000','Ville test',1,@now,@now,@now);
INSERT INTO glpi_plugin_printgestion_sagearticles (ref, label, is_in_last_import, date_import, date_creation, date_mod) VALUES ('TST-REF-N01','TONER TEST NOIR A',1,@now,@now,@now),('TST-REF-C01','TONER TEST CYAN A',1,@now,@now,@now),('TST-REF-N04','TONER TEST NOIR D',1,@now,@now,@now);
INSERT INTO glpi_agents (id, deviceid, entities_id, name, agenttypes_id, last_contact, version, useragent, tag, locked, itemtype, items_id, use_module_network_inventory, use_module_network_discovery) VALUES
 ({AGENT_RECENT}, 'agent-test-recent', 0, 'AGENT-TEST-RECENT', 1, @now, '1.19', 'GLPI-Agent_v1.19', '', 0, 'Computer', 0, 1, 1),
 ({AGENT_ANCIEN}, 'agent-test-ancien', 0, 'AGENT-TEST-ANCIEN', 1, DATE_SUB(@now, INTERVAL 20 DAY), '1.12', 'GLPI-Agent_v1.12', '', 0, 'Computer', 0, 1, 1);
INSERT INTO glpi_cartridges (entities_id, cartridgeitems_id, printers_id, date_in, date_use, date_out, pages, date_creation, date_mod) VALUES
 (0,3,6,DATE_SUB(CURDATE(), INTERVAL 2 DAY),DATE_SUB(CURDATE(), INTERVAL 2 DAY),NULL,50000,@now,@now),
 (0,3,7,DATE_SUB(CURDATE(), INTERVAL 2 DAY),DATE_SUB(CURDATE(), INTERVAL 2 DAY),NULL,20000,@now,@now);
INSERT INTO glpi_plugin_printgestion_cartridge_history (printers_id, toner_property, toner_color, level_at_install, date_install, printer_counter_at_install, is_detected) VALUES
 (6,'tonerblack','black',40,DATE_SUB(CURDATE(), INTERVAL 2 DAY),50000,0),
 (7,'tonerblack','black',60,DATE_SUB(CURDATE(), INTERVAL 2 DAY),20000,0);
INSERT INTO glpi_plugin_printgestion_expeditions (printers_id, toner_property, toner_color, statut, level_at_alert, date_alert, date_shipped, users_id_tech, group_id) VALUES
 (4,'tonerblack','black','shipped',8,DATE_SUB(@now, INTERVAL 2 DAY),DATE_SUB(@now, INTERVAL 1 DAY),{ADMIN_ID},'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'),
 (7,'tonerblack','black','pending',50,DATE_SUB(@now, INTERVAL 1 HOUR),NULL,{ADMIN_ID},'aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff');
INSERT INTO glpi_useremails (users_id, is_default, is_dynamic, email) VALUES ({ADMIN_ID}, 1, 0, 'admin@exemple.test');
"""

CORE = {"smtp_mode": "1", "smtp_host": "127.0.0.1", "smtp_port": str(config.SMTP_PORT), "use_notifications": "1", "notifications_mailing": "1",
        "admin_email": "glpi@exemple.test", "from_email": "glpi@exemple.test",
        # URL de l'application inventée (domaine .test réservé) : les paquets d'agent la contiennent, et une URL
        # vide ou locale bloque le téléchargement.
        "url_base": "https://glpi.exemple.test"}


def empreinte():
    """Données de référence qu'aucun test ne doit laisser modifiées."""
    return {
        "entités": lib.lignes("SELECT id, name, entities_id FROM glpi_entities ORDER BY id"),
        "imprimantes": lib.lignes("SELECT id, name, serial, entities_id, is_recursive, is_deleted FROM glpi_printers ORDER BY id"),
        "contrats": lib.lignes("SELECT id, name, entities_id, is_recursive, is_deleted FROM glpi_contracts ORDER BY id"),
        "liens contrat": lib.lignes("SELECT contracts_id, itemtype, items_id FROM glpi_contracts_items ORDER BY contracts_id, items_id"),
        "tarifs": lib.lignes("SELECT contracts_id, type_cout, rate, entities_id FROM glpi_plugin_printgestion_contractrates ORDER BY id"),
        "cartouches": lib.lignes("SELECT id, ref, entities_id FROM glpi_cartridgeitems ORDER BY id"),
        "profils": lib.lignes("SELECT id, name FROM glpi_profiles ORDER BY id"),
        "comptes": lib.lignes("SELECT id, name FROM glpi_users ORDER BY id"),
        "configuration": lib.lignes("SELECT * FROM glpi_plugin_printgestion_configs"),
    }


def verifier_instance():
    """Refuse de toucher une base qui n'a pas été préparée par ce jeu de données."""
    if valeur(f"SELECT COUNT(*) FROM glpi_configs WHERE context = '{MARQUEUR[0]}' AND name = '{MARQUEUR[1]}' AND value = '1'") != "1":
        sys.exit("Base sans le marqueur d'instance de test : le harnais ne s'exécute que sur une instance préparée par instance.sh et donnees.py.")


def charger():
    if valeur("SELECT COUNT(*) FROM glpi_printers") != "0" or valeur("SELECT COUNT(*) FROM glpi_entities WHERE id > 0") != "0":
        sys.exit("Instance déjà utilisée : relancer instance.sh pour repartir d'une base neuve.")
    sql(f"INSERT INTO glpi_configs (context, name, value) VALUES ('{MARQUEUR[0]}', '{MARQUEUR[1]}', '1');")
    lib.connecter_admin()
    contexte = lib.Contexte()
    for attendu, nom, parente in ENTITES:
        obtenu = contexte.entite(nom, parente)
        if obtenu != attendu:
            sys.exit(f"Entité « {nom} » créée avec l'identifiant {obtenu}, {attendu} attendu : base non neuve.")
    sql(f"UPDATE glpi_entities SET tag = 'CLIENT-TEST-A' WHERE id = {CLIENT_A};")
    sql(BASE)
    # Inventaire natif activé : sans lui, GLPI masque une partie de son écran de configuration (nettoyage des agents).
    sql("INSERT INTO glpi_configs (context, name, value) VALUES ('inventory', 'enabled_inventory', '1') ON DUPLICATE KEY UPDATE value = '1';")
    for nom, val in CORE.items():
        sql(f"INSERT INTO glpi_configs (context, name, value) VALUES ('core', '{nom}', '{val}') ON DUPLICATE KEY UPDATE value = '{val}';")
    lib.vider_cache()
    for tache in ("PrintgestionSnapshotReadings", "PrintgestionCheckAlerts"):
        print(f"tâche {tache} : {lib.tache(tache)[:120]}")
    print(lib.sql("SELECT (SELECT COUNT(*) FROM glpi_entities) AS entites, (SELECT COUNT(*) FROM glpi_printers) AS imprimantes, "
                  "(SELECT COUNT(*) FROM glpi_plugin_printgestion_alertview) AS vue_alertes, (SELECT COUNT(*) FROM glpi_plugin_printgestion_expeditions) AS expeditions"))


if __name__ == "__main__":
    charger()
