<?php
/**
 * Print Gestion — plugin GLPI de gestion de flotte d'impression.
 * Copyright (C) 2026 JCD Groupe — Auteur : Joris Reinert.
 * Licence GPL v3+ : texte intégral dans le fichier LICENSE à la racine du plugin.
 */
/**
 * Print Gestion — Plugin GLPI 11 de gestion de flotte d'impression
 * JCD Groupe — Joris Reinert
 */

// À incrémenter à chaque nouvelle étape de schéma (inc/schema.class.php) ou nouvelle
// tâche automatique : GLPI ne rejoue l'installation (schéma, enregistrement des
// tâches) que si cette version change. La 1.0.0 s'installe sur une base vierge du plugin.
define('PLUGIN_PRINTGESTION_VERSION', '1.0.0');
$_SESSION['PLUGIN_PRINTGESTION_VERSION'] = PLUGIN_PRINTGESTION_VERSION;

define('PLUGIN_PRINTGESTION_MIN_GLPI', '11.0.0');
define('PLUGIN_PRINTGESTION_MAX_GLPI', '11.0.99'); // testé sur GLPI 11.0.8 ; la série 11.1 ne l'est pas

// Chemins web : en GLPI 11, les ressources d'un plugin sont toutes servies sous /plugins/
// (Plugin::getWebDir() est déprécié). Fichier inclus depuis une méthode de GLPI :
// $CFG_GLPI n'est accessible qu'avec « global ».
global $CFG_GLPI;
define('PLUGIN_PRINTGESTION_WEBDIR',         ($CFG_GLPI['root_doc'] ?? '') . '/plugins/printgestion');
define('PLUGIN_PRINTGESTION_DIR',            Plugin::getPhpDir('printgestion'));
define('PLUGIN_PRINTGESTION_NOTFULL_DIR',    Plugin::getPhpDir('printgestion', false));
define('PLUGIN_PRINTGESTION_NOTFULL_WEBDIR', 'plugins/printgestion');

function plugin_init_printgestion() {
    global $PLUGIN_HOOKS, $CFG_GLPI, $DB;

    $PLUGIN_HOOKS['csrf_compliant']['printgestion'] = true;

    // Colonnes chiffrées avec GLPIKey (clés API transporteurs) : déclarées pour
    // que la commande glpi:security:change_key les rechiffre avec la nouvelle clé.
    $PLUGIN_HOOKS['secured_fields']['printgestion'] = [
        'glpi_plugin_printgestion_configs.api_ups',
        'glpi_plugin_printgestion_configs.api_gls',
        'glpi_plugin_printgestion_configs.api_chronopost',
    ];
    $PLUGIN_HOOKS['change_profile']['printgestion'] = [PluginPrintgestionProfile::class, 'initProfile'];

    $plugin = new Plugin();
    if ($plugin->isInstalled('printgestion') && $plugin->isActivated('printgestion')) {
        // Entité des données client : suit l'imprimante ou le contrat quand il change d'entité
        // (fiche ou transfert), quels que soient les modules actifs.
        $PLUGIN_HOOKS['item_update']['printgestion'] = [
            'Printer'  => [PluginPrintgestionEntityscope::class, 'onPrinterUpdate'],
            'Contract' => [PluginPrintgestionEntityscope::class, 'onContractUpdate'],
        ];

        // Schéma versionné (inc/schema.class.php) : les migrations sont jouées par
        // plugin_printgestion_install(), lors de l'installation ou du « Mettre à
        // jour » que GLPI propose dès que PLUGIN_PRINTGESTION_VERSION change.

        // La table matérialisée du « coût à la page » porte un nom NON
        // conventionnel (`..._billing_view`, avec underscore) : getItemTypeForTable()
        // en déduirait « Billing_View » et ne retrouverait pas la classe
        // PluginPrintgestionBillingview → null → TypeError dans le moteur Search
        // (SQLProvider::giveItem). On enregistre donc explicitement la correspondance
        // table ↔ itemtype (alertview, sans underscore, se mappe nativement).
        $CFG_GLPI['glpiitemtypetables']['glpi_plugin_printgestion_billing_view'] = 'PluginPrintgestionBillingview';
        $CFG_GLPI['glpitablesitemtype']['PluginPrintgestionBillingview']        = 'glpi_plugin_printgestion_billing_view';

        // Fichiers Gesconso archivés en Documents natifs (expéditions, demandes) et
        // notifications natives des demandes : déclarés hors session aussi, pour les tâches
        // automatiques qui émettent ces notifications.
        Plugin::registerClass('PluginPrintgestionExpedition', ['document_types' => true]);
        Plugin::registerClass('PluginPrintgestionDemande', [
            'document_types'              => true,
            'notificationtemplates_types' => true,
        ]);
        // Déploiement Agent : notifications des alertes de sondes, réglages et action dans « Agent cleanup »
        // (lus par la tâche native Cleanoldagents, hors session) et cartes du tableau de bord.
        Plugin::registerClass('PluginPrintgestionAgentalert', ['notificationtemplates_types' => true]);
        // Commandes enregistrées mais non transmises aux Achats : notification native.
        Plugin::registerClass('PluginPrintgestionPurchaseorder', ['notificationtemplates_types' => true]);
        if (PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
            $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::STALE_AGENT_CONFIG]['printgestion'] = PluginPrintgestionAgentalert::getStaleAgentHook();
            $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::DASHBOARD_CARDS]['printgestion']    = [PluginPrintgestionAgentalert::class, 'getDashboardCards'];
        }

        if (Session::getLoginUserID()) {
            // Onglets toujours présents (admin).
            Plugin::registerClass('PluginPrintgestionProfile', ['addtabon' => 'Profile']);
            Plugin::registerClass('PluginPrintgestionConfig',  ['addtabon' => 'Config']);

            // Onglets natifs gated par interrupteur de module (désactivé = non chargé).
            if (PluginPrintgestionConfig::isFeatureEnabled('contrats')) {
                Plugin::registerClass('PluginPrintgestionContractrate', ['addtabon' => 'Contract']); // tarifs €/page
            }
            if (PluginPrintgestionConfig::isFeatureEnabled('cout')) {
                Plugin::registerClass('PluginPrintgestionPrinterCostsTab', ['addtabon' => 'Printer']); // coûts imprimante
            }
            if (PluginPrintgestionConfig::isFeatureEnabled('toner')) {
                Plugin::registerClass('PluginPrintgestionCartridgesnmp', ['addtabon' => 'CartridgeItem']); // binding SNMP
                Plugin::registerClass('PluginPrintgestionPrinterThresholdsTab', ['addtabon' => 'Printer']); // seuils d'alerte
            }
            if (PluginPrintgestionConfig::isFeatureEnabled('deploiement')) {
                Plugin::registerClass('PluginPrintgestionAgentdeploy', ['addtabon' => 'Entity']); // Déploiement Agent
                Plugin::registerClass('PluginPrintgestionAgentsetting', ['addtabon' => 'Agent']); // conformité, mise à jour
                // Sonde responsable sur la fiche imprimante : dans la carte native « Informations d'inventaire »
                // quand l'utilisateur la voit, sinon sous le formulaire (droits revérifiés à l'affichage).
                $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::AUTOINVENTORY_INFORMATION]['printgestion'] = ['Printer' => 'plugin_printgestion_printer_probe_card'];
                $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::POST_ITEM_FORM]['printgestion']             = 'plugin_printgestion_printer_probe_form';
            }

            // Jeton anti-cache (beta) : à incrémenter à chaque modif de public/css|js.
            $cb = '?b=5';
            $PLUGIN_HOOKS['add_css']['printgestion']        = ['public/css/printgestion.css' . $cb];
            $PLUGIN_HOOKS['add_javascript']['printgestion'] = ['public/js/printgestion.js' . $cb];
        }

        // Restriction « contrats liés à ≥1 imprimante » pour l'itemtype dédié.
        // (Voir plugin_printgestion_addDefaultWhere plus bas.)

        // Menu « Print Gestion » : visible si ≥1 onglet accessible (feature ∧ droit).
        if (PluginPrintgestionMenu::canView()) {
            $PLUGIN_HOOKS['menu_toadd']['printgestion'] = ['management' => PluginPrintgestionMenu::class];
        }

        $PLUGIN_HOOKS['config_page']['printgestion'] =
            '../../front/config.form.php?forcetab=' . urlencode('PluginPrintgestionConfig$1');
    }
}

/**
 * Default WHERE de l'itemtype dédié PluginPrintgestionContract (recherche des
 * contrats d'impression) : restreint aux contrats liés à ≥1 imprimante existante.
 * Appelé automatiquement par GLPI (Hooks::AUTO_ADD_DEFAULT_WHERE) pour les
 * itemtypes de plugin. WHERE EXISTS → aucun doublon, n'impacte pas Contract natif.
 */
function plugin_printgestion_addDefaultWhere($itemtype) {
    // Contrats d'impression : restreint aux contrats liés à ≥1 imprimante.
    if ($itemtype === 'PluginPrintgestionContract') {
        return 'EXISTS ('
            . 'SELECT 1 FROM `glpi_contracts_items` AS `pg_ci`'
            . ' INNER JOIN `glpi_printers` AS `pg_p` ON `pg_p`.`id` = `pg_ci`.`items_id`'
            . ' WHERE `pg_ci`.`contracts_id` = `glpi_contracts`.`id`'
            . " AND `pg_ci`.`itemtype` = 'Printer'"
            . ' AND `pg_p`.`is_deleted` = 0'
            . ' AND `pg_p`.`is_template` = 0'
            . ')';
    }

    // Coût à la page (table matérialisée par utilisateur) : on isole les lignes
    // de l'utilisateur courant ET la vue courante (imprimante|client, stockée en
    // session). La restriction d'entité est ajoutée nativement (isEntityAssign).
    if ($itemtype === 'PluginPrintgestionBillingview') {
        $uid  = (int) Session::getLoginUserID();
        $view = $_SESSION['plugin_printgestion_billing_view'] ?? 'printer';
        if (!in_array($view, ['printer', 'client'], true)) {
            $view = 'printer';
        }
        return '`glpi_plugin_printgestion_billing_view`.`users_id` = ' . $uid
            . " AND `glpi_plugin_printgestion_billing_view`.`view_mode` = '" . $view . "'";
    }

    // Expéditions : le moteur de recherche restreint nativement sur leur entities_id, figée à la
    // création ; seconde barrière explicite sur la même entité. Jamais l'entité actuelle de
    // l'imprimante : une imprimante transférée ne fait pas passer l'historique commercial au nouveau client.
    if ($itemtype === 'PluginPrintgestionExpedition') {
        return trim((string) getEntitiesRestrictRequest('', 'glpi_plugin_printgestion_expeditions', '', '', true));
    }

    return '';
}

function plugin_version_printgestion() {
    return [
        'name'         => 'Print Gestion — Gestion flotte impression',
        'version'      => PLUGIN_PRINTGESTION_VERSION,
        'author'       => 'JCD Groupe — Joris Reinert',
        'license'      => 'GPL v3+',
        'homepage'     => 'https://www.jcd-groupe.fr',
        // Vérifié par GLPI avant l'installation (Plugin::checkVersions) : version de GLPI, version de PHP, extensions.
        // PhpSpreadsheet (fichiers Gesconso, imports Sage) est fourni par GLPI lui-même : rien à déclarer.
        // GLPI Inventory n'est pas déclaré en dépendance dure : les modules contrats, toner et coût fonctionnent sans lui ;
        // la collecte le demande, et la carte « Santé de la configuration » le dit.
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PRINTGESTION_MIN_GLPI,
                'max' => PLUGIN_PRINTGESTION_MAX_GLPI,
            ],
            'php'  => [
                'min'  => '8.2',
                'exts' => [
                    'zip'      => ['required' => true],   // archives ZIP des paquets de sonde, fichiers xlsx
                    'mbstring' => ['required' => true],   // chaînes multioctets (codes, désignations, imports)
                    'intl'     => ['required' => true],   // normalisation sans accent des en-têtes et libellés (Transliterator)
                ],
            ],
        ],
    ];
}

function plugin_printgestion_check_prerequisites() {
    return true;
}

function plugin_printgestion_check_config() {
    return true;
}
