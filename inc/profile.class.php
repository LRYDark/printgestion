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
    /** Réglage qui retient que le niveau « Retirer une sonde » a été accordé une fois, à la mise à jour. */
    const CONFIG_REMOVAL_GRANTED = 'retrait_right_granted';

    /** Droit du module « Mise en service » (mettre une imprimante sous contrat). */
    const RIGHT_MISEENSERVICE = 'plugin_printgestion_miseenservice';
    /** Réglage qui retient que ce droit a été accordé une fois aux profils qui avaient « Créer print ». */
    const CONFIG_MISEENSERVICE_GRANTED = 'miseenservice_right_granted';

    static function getAllRights($all = false) {
        return [
            [
                'itemtype' => 'PluginPrintgestionMenu',
                'label'    => __('Contrats — dashboard & liste', 'printgestion'),
                'field'    => 'plugin_printgestion_contrats',
                'rights'   => [READ => __('Voir', 'printgestion'), UPDATE => __('Éditer les tarifs', 'printgestion')],
            ],
            [
                // Module à part : mettre une imprimante sous contrat crée et relie des objets de GLPI (contrat,
                // imprimante) ; les droits natifs sur eux sont contrôlés en plus (PluginPrintgestionPrint::processForm).
                'itemtype' => 'PluginPrintgestionPrint',
                'label'    => __('Mise en service', 'printgestion'),
                'field'    => self::RIGHT_MISEENSERVICE,
                'rights'   => [CREATE => __('Mettre une imprimante sous contrat', 'printgestion')],
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
                // Retirer est un droit distinct d'installer : le fichier de retrait sert à supprimer une sonde du
                // poste ET, depuis ce poste, dans GLPI. Qui peut le télécharger peut le faire — c'est le seul
                // contrôle, la clé du fichier ne vaut que 24 heures et une seule fois.
                'rights'   => [
                    READ   => __('Voir, télécharger l\'installeur', 'printgestion'),
                    UPDATE => __('Raccorder des imprimantes', 'printgestion'),
                    PURGE  => __('Retirer une sonde : fichier de retrait, et suppression dans GLPI depuis le poste', 'printgestion'),
                ],
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

    /**
     * Icône de l'onglet. Onglet « Print Gestion » de la fiche Profil : l'icône du plugin, la même que son menu.
     *
     * Sans elle, GLPI retombe sur l'icône par défaut de CommonDBTM, qui vaut « fa-empty-icon » et que
     * createTabEntry() remplace alors par rien.
     */
    static function getIcon() {
        return 'ti ti-printer';
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'Profile') {
            return self::createTabEntry(__('Print Gestion', 'printgestion'));
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() == 'Profile') {
            $ID   = $item->getID();
            $prof = new self();
            self::addDefaultProfileInfos($ID, [
                'plugin_printgestion_contrats'   => 0,
                self::RIGHT_MISEENSERVICE        => 0,
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

    /**
     * Mise à jour : le niveau « Retirer une sonde » n'existait pas, personne ne l'a — et plus aucun fichier de
     * retrait ne se téléchargerait. On le donne **une seule fois** aux profils qui ont déjà le droit natif de
     * supprimer une sonde dans GLPI (`Agent`, purge) : ceux-là le faisaient déjà, à la main, dans la liste des
     * sondes. Ensuite il s'accorde profil par profil, et un administrateur qui le retire ne le voit pas revenir.
     */
    public static function grantRemovalRightOnce(): void {
        global $DB;

        $fait = Config::getConfigurationValues(PluginPrintgestionSchema::CONFIG_CONTEXT, [self::CONFIG_REMOVAL_GRANTED]);
        if (!empty($fait[self::CONFIG_REMOVAL_GRANTED])) {
            return;
        }
        // Lecture et écriture par les classes de GLPI (ProfileRight), pas en SQL : c'est elle qui connaît le
        // stockage des droits, et qui recharge la session de ceux qui sont connectés.
        $accordes = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable()]) as $row) {
            $profiles_id = (int) $row['id'];
            $droits      = ProfileRight::getProfileRights($profiles_id, [Agent::$rightname, 'plugin_printgestion_deploiement']);
            $deploiement = (int) ($droits['plugin_printgestion_deploiement'] ?? 0);
            if (((int) ($droits[Agent::$rightname] ?? 0) & PURGE) !== PURGE || ($deploiement & PURGE) === PURGE) {
                continue;
            }
            ProfileRight::updateProfileRights($profiles_id, ['plugin_printgestion_deploiement' => $deploiement | PURGE]);
            $accordes++;
        }
        Config::setConfigurationValues(PluginPrintgestionSchema::CONFIG_CONTEXT, [self::CONFIG_REMOVAL_GRANTED => 1]);
        PluginPrintgestionLogger::info('profile', sprintf(
            'Niveau « Retirer une sonde » accordé à %d profil(s) : ceux qui pouvaient déjà supprimer une sonde dans GLPI.',
            $accordes
        ));
    }

    /**
     * Droit « Mise en service », sans « Mettre à jour » du plugin (version inchangée) : la ligne de droit existe pour
     * chaque profil (ProfileRight::addProfileRights), les profils qui avaient « Créer print » (contrats, UPDATE) la
     * reçoivent UNE fois, et la session courante la connaît. Ceux qui n'ont pas les droits natifs de GLPI de créer un
     * contrat ou une imprimante ne le peuvent plus par cette page : c'est voulu (PluginPrintgestionPrint::processForm). Appelée par l'installation,
     * et par plugin_init une fois par session tant que la session ignore ce droit.
     */
    public static function ensureMiseEnServiceRight(): void {
        global $DB;

        $field = self::RIGHT_MISEENSERVICE;
        if (countElementsInTable('glpi_profilerights', ['name' => $field]) === 0) {
            try {
                ProfileRight::addProfileRights([$field]);
            } catch (Throwable $e) {
                // Une autre session (ou initProfile) vient de créer les lignes : clé unique profils × droit.
                if (countElementsInTable('glpi_profilerights', ['name' => $field]) === 0) {
                    throw $e;
                }
            }
        }
        $fait = Config::getConfigurationValues(PluginPrintgestionSchema::CONFIG_CONTEXT, [self::CONFIG_MISEENSERVICE_GRANTED]);
        if (empty($fait[self::CONFIG_MISEENSERVICE_GRANTED])) {
            $accordes = 0;
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable()]) as $row) {
                $profiles_id = (int) $row['id'];
                $droits      = ProfileRight::getProfileRights($profiles_id, ['plugin_printgestion_contrats', $field]);
                $actuel      = (int) ($droits[$field] ?? 0);
                if (((int) ($droits['plugin_printgestion_contrats'] ?? 0) & UPDATE) !== UPDATE || ($actuel & CREATE) === CREATE) {
                    continue;
                }
                ProfileRight::updateProfileRights($profiles_id, [$field => $actuel | CREATE]);
                $accordes++;
            }
            Config::setConfigurationValues(PluginPrintgestionSchema::CONFIG_CONTEXT, [self::CONFIG_MISEENSERVICE_GRANTED => 1]);
            PluginPrintgestionLogger::info('profile', sprintf(
                'Droit « Mise en service » accordé à %d profil(s) : ceux qui pouvaient déjà « Créer print ».',
                $accordes
            ));
        }
        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            $droits = ProfileRight::getProfileRights((int) $_SESSION['glpiactiveprofile']['id'], [$field]);
            $_SESSION['glpiactiveprofile'][$field] = (int) ($droits[$field] ?? 0);
            // Menu gardé en session : GLPI ne le reconstruit que s'il est absent (Html::generateMenuSession) — retiré
            // en entier, comme le fait le cœur au changement de profil, il revient au prochain affichage avec le module.
            unset($_SESSION['glpimenu']);
        }
    }

    static function createFirstAccess($profiles_id) {
        self::addDefaultProfileInfos($profiles_id, [
            'plugin_printgestion_contrats'   => ALLSTANDARDRIGHT,
            self::RIGHT_MISEENSERVICE        => CREATE,
            'plugin_printgestion_config'     => ALLSTANDARDRIGHT,
            'plugin_printgestion_dashboard'  => ALLSTANDARDRIGHT,
            'plugin_printgestion_billing'    => ALLSTANDARDRIGHT,
            'plugin_printgestion_expedition' => ALLSTANDARDRIGHT,
            'plugin_printgestion_validation' => ALLSTANDARDRIGHT,
            // ALLSTANDARDRIGHT ne porte pas PURGE : le niveau « Retirer une sonde » est ajouté explicitement.
            'plugin_printgestion_deploiement' => ALLSTANDARDRIGHT | PURGE,
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
