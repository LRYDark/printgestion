<?php
/**
 * Print Gestion — Plugin GLPI 11 de gestion de flotte d'impression
 * JCD Groupe — Joris Reinert
 */

// À incrémenter à chaque nouvelle étape de schéma (inc/schema.class.php) : GLPI
// ne rejoue l'installation, donc les migrations, que si cette version change.
define('PLUGIN_PRINTGESTION_VERSION', '1.2.2');
$_SESSION['PLUGIN_PRINTGESTION_VERSION'] = PLUGIN_PRINTGESTION_VERSION;

define('PLUGIN_PRINTGESTION_MIN_GLPI', '11.0.0');
define('PLUGIN_PRINTGESTION_MAX_GLPI', '11.1.0');

define('PLUGIN_PRINTGESTION_WEBDIR',         Plugin::getWebDir('printgestion'));
define('PLUGIN_PRINTGESTION_DIR',            Plugin::getPhpDir('printgestion'));
define('PLUGIN_PRINTGESTION_NOTFULL_DIR',    Plugin::getPhpDir('printgestion', false));
define('PLUGIN_PRINTGESTION_NOTFULL_WEBDIR', Plugin::getWebDir('printgestion', false));

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
            }

            // Jeton anti-cache (beta) : à incrémenter à chaque modif de public/css|js.
            $cb = '?b=1';
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

    // Expéditions : pas d'entities_id propre → on restreint par l'entité de
    // l'imprimante liée (le moteur Search ne sait pas le faire seul).
    if ($itemtype === 'PluginPrintgestionExpedition') {
        $entity_where = getEntitiesRestrictRequest('', 'glpi_printers', '', '', true);
        if (trim((string) $entity_where) === '') {
            return ''; // l'utilisateur voit toutes les entités → aucune restriction
        }
        return '`glpi_plugin_printgestion_expeditions`.`printers_id` IN ('
            . 'SELECT `glpi_printers`.`id` FROM `glpi_printers` WHERE ' . $entity_where . ')';
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
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PRINTGESTION_MIN_GLPI,
                'max' => PLUGIN_PRINTGESTION_MAX_GLPI,
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
