<?php
/**
 * PluginPrintgestionProfile — droits et profils (pattern plugin Gestion).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionProfile extends Profile {

    static $rightname = 'profile';

    static function getTypeName($nb = 0) {
        return __('Print Gestion', 'printgestion');
    }

    /**
     * Droits par sous-fonction (READ = voir l'onglet / autre bit = agir).
     * On conserve les champs historiques PrintCost (_dashboard/_billing/_expedition)
     * que le code utilise partout, et on ajoute le droit Contrats.
     */
    static function getAllRights($all = false) {
        return [
            [
                'itemtype' => 'PluginPrintgestionMenu',
                'label'    => __('Contrats — dashboard & liste', 'printgestion'),
                'field'    => 'plugin_printgestion_contrats',
                'rights'   => [READ => __('Voir', 'printgestion'), UPDATE => __('Créer print / éditer tarifs', 'printgestion')],
            ],
            [
                'itemtype' => 'PluginPrintgestionMenu',
                'label'    => __('Alertes toner', 'printgestion'),
                'field'    => 'plugin_printgestion_dashboard',
                'rights'   => [READ => __('Voir', 'printgestion'), UPDATE => __('Agir (ne plus alerter, réactiver, recalculer, seuils par imprimante)', 'printgestion')],
            ],
            [
                'itemtype' => 'PluginPrintgestionExpedition',
                'label'    => __('Expéditions', 'printgestion'),
                'field'    => 'plugin_printgestion_expedition',
                'rights'   => [READ => __('Voir', 'printgestion'), UPDATE => __('Éditer', 'printgestion'), CREATE => __('Créer', 'printgestion')],
            ],
            [
                // Distinct des alertes et des expéditions : la lecture des alertes suffit à
                // voir la file des demandes, seul ce droit permet de modifier ou valider.
                'itemtype' => 'PluginPrintgestionDemande',
                'label'    => __('Demandes d\'envoi — validation', 'printgestion'),
                'field'    => 'plugin_printgestion_validation',
                'rights'   => [READ => __('Voir', 'printgestion'), UPDATE => __('Modifier, valider, annuler', 'printgestion')],
            ],
            [
                // Techniciens et administrateur : état du rattachement des sondes et installeur
                // pré-paramétré par entité (lecture), raccordement des imprimantes (modification).
                'itemtype' => 'PluginPrintgestionMenu',
                'label'    => __('Collecte SNMP / Déploiement Agent', 'printgestion'),
                'field'    => 'plugin_printgestion_deploiement',
                'rights'   => [READ => __('Voir, télécharger l\'installeur', 'printgestion'), UPDATE => __('Raccorder des imprimantes', 'printgestion')],
            ],
            [
                // Distinct de la configuration du plugin : personne qui gère le référentiel.
                'itemtype' => 'PluginPrintgestionSage',
                'label'    => __('Référentiel Sage', 'printgestion'),
                'field'    => 'plugin_printgestion_sage',
                'rights'   => [READ => __('Voir', 'printgestion'), UPDATE => __('Importer, modifier les correspondances', 'printgestion')],
            ],
            [
                'itemtype' => 'PluginPrintgestionBilling',
                'label'    => __('Coût à la page', 'printgestion'),
                'field'    => 'plugin_printgestion_billing',
                'rights'   => [READ => __('Voir (dont l\'onglet des imprimantes)', 'printgestion'), CREATE => __('Exporter', 'printgestion')],
            ],
            [
                'itemtype' => 'PluginPrintgestionConfig',
                'label'    => __('Configuration', 'printgestion'),
                'field'    => 'plugin_printgestion_config',
                'rights'   => [READ => __('Voir', 'printgestion'), UPDATE => __('Modifier', 'printgestion')],
            ],
        ];
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'Profile') {
            return __('Print Gestion', 'printgestion');
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() == 'Profile') {
            $ID   = $item->getID();
            $prof = new self();
            self::addDefaultProfileInfos($ID, [
                'plugin_printgestion_contrats'   => 0,
                'plugin_printgestion_config'     => 0,
                'plugin_printgestion_dashboard'  => 0,
                'plugin_printgestion_billing'    => 0,
                'plugin_printgestion_expedition' => 0,
                'plugin_printgestion_validation' => 0,
                'plugin_printgestion_deploiement' => 0,
                'plugin_printgestion_sage'       => 0,
            ]);
            $prof->showForm($ID);
        }
        return true;
    }

    function showForm($profiles_id = 0, $openform = true, $closeform = true) {
        echo "<div class='firstbloc'>";

        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);
        if ($canedit && $openform) {
            $profile = new Profile();
            echo "<form method='post' action='" . $profile->getFormURL() . "'>";
        }

        $profile = new Profile();
        $profile->getFromDB($profiles_id);

        $rights = $this->getAllRights();
        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('Print Gestion', 'printgestion'),
        ]);

        if ($canedit && $closeform) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }

    static function addDefaultProfileInfos($profiles_id, $rights, $drop_existing = false) {
        $profileRight = new ProfileRight();
        $dbu          = new DbUtils();

        foreach ($rights as $right => $value) {
            $exists = $dbu->countElementsInTable('glpi_profilerights', [
                'profiles_id' => $profiles_id,
                'name'        => $right,
            ]) > 0;

            if ($exists && $drop_existing) {
                $profileRight->deleteByCriteria(['profiles_id' => $profiles_id, 'name' => $right]);
            }

            if (!$exists || $drop_existing) {
                $profileRight->add([
                    'profiles_id' => $profiles_id,
                    'name'        => $right,
                    'rights'      => $value,
                ]);
                $_SESSION['glpiactiveprofile'][$right] = $value;
            }
        }
    }

    static function initProfile() {
        global $DB;

        $profile = new self();
        $dbu     = new DbUtils();

        foreach ($profile->getAllRights(true) as $data) {
            if ($dbu->countElementsInTable('glpi_profilerights', ['name' => $data['field']]) == 0) {
                ProfileRight::addProfileRights([$data['field']]);
            }
        }

        if (!isset($_SESSION['glpiactiveprofile']['id'])) {
            return;
        }

        $criteria = [
            'SELECT' => '*',
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
                'profiles_id' => $_SESSION['glpiactiveprofile']['id'],
                'name'        => ['LIKE', '%plugin_printgestion%'],
            ],
        ];
        foreach ($DB->request($criteria) as $prof) {
            $_SESSION['glpiactiveprofile'][$prof['name']] = $prof['rights'];
        }
    }

    static function createFirstAccess($profiles_id) {
        self::addDefaultProfileInfos($profiles_id, [
            'plugin_printgestion_contrats'   => ALLSTANDARDRIGHT,
            'plugin_printgestion_config'     => ALLSTANDARDRIGHT,
            'plugin_printgestion_dashboard'  => ALLSTANDARDRIGHT,
            'plugin_printgestion_billing'    => ALLSTANDARDRIGHT,
            'plugin_printgestion_expedition' => ALLSTANDARDRIGHT,
            'plugin_printgestion_validation' => ALLSTANDARDRIGHT,
            'plugin_printgestion_deploiement' => ALLSTANDARDRIGHT,
            'plugin_printgestion_sage'       => ALLSTANDARDRIGHT,
        ], true);
    }

    static function removeRightsFromSession() {
        foreach (self::getAllRights(true) as $right) {
            if (isset($_SESSION['glpiactiveprofile'][$right['field']])) {
                unset($_SESSION['glpiactiveprofile'][$right['field']]);
            }
        }
    }

    static function install(Migration $migration) {
        return true;
    }

    static function uninstall(Migration $migration) {
        return true;
    }
}
