<?php
/**
 * PluginPrintgestionMenu — entrée de menu « Print Gestion » + hub à catégories
 * + barre d'onglets unifiée.
 *
 * Organisation par CATÉGORIES (cf. config : interrupteurs de modules) :
 *   - Gestion contractuelle  (feature 'contrats') : Dashboard / Liste / Créer Print
 *   - Gestion toner & expéd. (feature 'toner')    : Alertes toner / Demandes / Expéditions
 *   - Coût à la page          (feature 'cout')      : Facturation
 *   - Collecte SNMP / Déploiement Agent (feature 'deploiement') : Contrôle de la remontée
 *   - Référentiel Sage        (feature 'sage')      : Import du référentiel
 *
 * Un sous-onglet n'est visible que si SA feature est activée ET le droit READ
 * correspondant est présent (visibilité = feature ∧ droit).
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionMenu extends CommonGLPI {

    static $rightname = 'plugin_printgestion_contrats';

    static function getTypeName($nb = 0) {
        return __('Print Gestion', 'printgestion');
    }

    static function getMenuName() {
        return self::getTypeName();
    }

    static function getIcon() {
        return 'ti ti-printer';
    }

    /**
     * Définition des catégories → sous-onglets. Chaque onglet :
     *   key, label, icon, path (relatif au webdir plugin), feature, right[name,bit].
     */
    static function categories(): array {
        return [
            'contrats' => [
                'label'   => __('Gestion contractuelle', 'printgestion'),
                'icon'    => 'ti ti-file-text',
                'feature' => 'contrats',
                'tabs'    => [
                    ['key' => 'ct_dash', 'label' => __('Dashboard', 'printgestion'),     'icon' => 'ti ti-gauge', 'path' => '/front/dashboard.php',  'right' => ['plugin_printgestion_contrats', READ]],
                    ['key' => 'ct_list', 'label' => __('Liste contrats', 'printgestion'), 'icon' => 'ti ti-list-details', 'path' => '/front/list.php',       'right' => ['plugin_printgestion_contrats', READ]],
                    ['key' => 'ct_new',  'label' => __('Créer Print', 'printgestion'),    'icon' => 'ti ti-plus',       'path' => '/front/print.form.php', 'right' => ['plugin_printgestion_contrats', UPDATE]],
                ],
            ],
            'toner' => [
                'label'   => __('Gestion toner & expéditions', 'printgestion'),
                'icon'    => 'ti ti-droplet',
                'feature' => 'toner',
                'tabs'    => [
                    ['key' => 'tn_alerts', 'label' => __('Alertes toner', 'printgestion'), 'icon' => 'ti ti-alert-triangle', 'path' => '/front/dashboard_alerts.php',      'right' => ['plugin_printgestion_dashboard', READ]],
                    // File des demandes : droit de validation OU lecture des alertes (voir sans valider).
                    ['key' => 'tn_dem',    'label' => __('Demandes d\'envoi', 'printgestion'), 'icon' => 'ti ti-clipboard-check', 'path' => '/front/demande.php', 'right' => [['plugin_printgestion_validation', READ], ['plugin_printgestion_dashboard', READ]]],
                    ['key' => 'tn_exp',    'label' => __('Expéditions', 'printgestion'),   'icon' => 'ti ti-truck',                'path' => '/front/dashboard_expeditions.php', 'right' => ['plugin_printgestion_expedition', READ]],
                ],
            ],
            'deploiement' => [
                'label'   => __('Collecte SNMP / Déploiement Agent', 'printgestion'),
                'icon'    => 'ti ti-antenna',
                'feature' => 'deploiement',
                'tabs'    => [
                    // Techniciens (déploiement) ou suivi des alertes toner, qui y renvoient.
                    ['key' => 'dp_collect', 'label' => __('Contrôle de la remontée', 'printgestion'), 'icon' => 'ti ti-activity', 'path' => '/front/collect.php', 'right' => [['plugin_printgestion_deploiement', READ], ['plugin_printgestion_dashboard', READ]]],
                ],
            ],
            'sage' => [
                'label'   => __('Référentiel Sage', 'printgestion'),
                'icon'    => 'ti ti-database-import',
                'feature' => 'sage',
                'tabs'    => [
                    ['key' => 'sg_import', 'label' => __('Import du référentiel', 'printgestion'), 'icon' => 'ti ti-file-import', 'path' => '/front/sageimport.php', 'right' => ['plugin_printgestion_sage', UPDATE]],
                ],
            ],
            'cout' => [
                'label'   => __('Coût à la page', 'printgestion'),
                'icon'    => 'ti ti-currency-euro',
                'feature' => 'cout',
                'tabs'    => [
                    ['key' => 'co_bill', 'label' => __('Facturation', 'printgestion'), 'icon' => 'ti ti-file-invoice', 'path' => '/front/dashboard_billing.php', 'right' => ['plugin_printgestion_billing', READ]],
                ],
            ],
        ];
    }

    /**
     * Un onglet est accessible si sa feature est activée ET le droit présent.
     * $right : [nom, bit], ou liste de [nom, bit] dont un seul suffit.
     */
    static function tabAllowed(string $feature, array $right): bool {
        if (!PluginPrintgestionConfig::isFeatureEnabled($feature)) {
            return false;
        }
        $alternatives = is_array($right[0] ?? null) ? $right : [$right];
        foreach ($alternatives as $alternative) {
            if (Session::haveRight($alternative[0], $alternative[1])) {
                return true;
            }
        }
        return false;
    }

    /** Le menu est visible s'il existe au moins un onglet accessible. */
    public static function canView(): bool {
        foreach (self::categories() as $cat) {
            foreach ($cat['tabs'] as $t) {
                if (self::tabAllowed($cat['feature'], $t['right'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Contenu du menu GLPI : entrée « Print Gestion » + sous-entrées accessibles. */
    static function getMenuContent() {
        $menu = [];
        if (!self::canView()) {
            return $menu;
        }

        $menu['title'] = self::getTypeName();
        $menu['icon']  = self::getIcon();
        $menu['page']  = PLUGIN_PRINTGESTION_NOTFULL_WEBDIR . '/front/index.php';

        foreach (self::categories() as $cat) {
            if (!PluginPrintgestionConfig::isFeatureEnabled($cat['feature'])) {
                continue;
            }
            foreach ($cat['tabs'] as $t) {
                if (!self::tabAllowed($cat['feature'], $t['right'])) {
                    continue;
                }
                $menu['options'][$t['key']] = [
                    'title' => $cat['label'] . ' · ' . $t['label'],
                    'page'  => PLUGIN_PRINTGESTION_NOTFULL_WEBDIR . $t['path'],
                    'icon'  => $t['icon'],
                ];
            }
        }
        return $menu;
    }

    /** Retrouve la catégorie (module) contenant un onglet donné, ou null. */
    static function categoryOfTab(string $tabKey): ?array {
        foreach (self::categories() as $cat) {
            foreach ($cat['tabs'] as $t) {
                if ($t['key'] === $tabKey) {
                    return $cat;
                }
            }
        }
        return null;
    }

    /**
     * Barre de navigation rendue en haut des pages front.
     *
     * Navigation PAR MODULE (pas une barre globale) :
     *   - Accueil ('hub') : AUCUN sous-onglet (c'est l'accueil général).
     *   - Page d'un module : onglet « Accueil » (retour au hub) + UNIQUEMENT les
     *     sous-onglets de CE module (ex. Toner → Alertes toner / Expéditions).
     *
     * @param string $active clé de l'onglet courant ('hub' pour l'accueil).
     */
    static function showTabBar(string $active, bool $with_refresh = false): void {
        // Accueil général : pas de barre de sous-onglets.
        if ($active === 'hub') {
            return;
        }

        $base = PLUGIN_PRINTGESTION_WEBDIR;
        $cat  = self::categoryOfTab($active);

        /*
         * Les onglets sont posés sur une `card` — le même fond blanc que la barre
         * de stats et les tableaux dessous — au lieu de flotter sur le gris de la
         * page. `nav-bordered` (les onglets soulignés de Tabler, ceux de GLPI 11)
         * sans son filet plein largeur : le seul marqueur est le trait sous
         * l'onglet courant.
         */
        echo "<div class='card mb-3'>";
        echo "<div class='card-body py-2 px-3 d-flex justify-content-between align-items-end flex-wrap gap-2'>";
        echo "<ul class='nav nav-tabs nav-bordered printgestion-tabs border-0 flex-grow-1 flex-wrap mb-0'>";

        // Onglet « Accueil » : retour au hub général.
        echo "<li class='nav-item'><a class='nav-link' href='"
            . htmlspecialchars($base . '/front/index.php', ENT_QUOTES, 'UTF-8') . "'>"
            . "<i class='ti ti-home me-1'></i>" . __('Accueil', 'printgestion') . "</a></li>";

        // Sous-onglets du module courant uniquement.
        if ($cat !== null && PluginPrintgestionConfig::isFeatureEnabled($cat['feature'])) {
            foreach ($cat['tabs'] as $t) {
                if (!self::tabAllowed($cat['feature'], $t['right'])) {
                    continue;
                }
                $cls  = 'nav-link' . ($t['key'] === $active ? ' active' : '');
                $href = htmlspecialchars($base . $t['path'], ENT_QUOTES, 'UTF-8');
                echo "<li class='nav-item'><a class='{$cls}' href='{$href}'>"
                    . "<i class='{$t['icon']} me-1'></i>"
                    . htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8') . "</a></li>";
            }
        }
        echo "</ul>";

        // Bouton « Rafraîchir » à droite (handler JS #pc-refresh-cache rendu par
        // Dashboardactions::renderSharedAssets()).
        if ($with_refresh) {
            $tip = htmlspecialchars(
                __('Forcer la mise à jour (ignore le cache de 15 min)', 'printgestion'),
                ENT_QUOTES, 'UTF-8'
            );
            echo "<button type='button' class='btn btn-sm btn-outline-secondary flex-shrink-0' "
                . "id='pc-refresh-cache' title='{$tip}'>"
                . "<i class='ti ti-refresh me-1'></i>"
                . __('Rafraîchir', 'printgestion') . "</button>";
        }

        echo "</div></div>"; // card-body + card
    }

    static function removeRightsFromSession() {
        foreach ([
            'plugin_printgestion_contrats',
            'plugin_printgestion_config',
            'plugin_printgestion_dashboard',
            'plugin_printgestion_billing',
            'plugin_printgestion_expedition',
            'plugin_printgestion_validation',
            'plugin_printgestion_deploiement',
            'plugin_printgestion_sage',
        ] as $right) {
            if (isset($_SESSION['glpiactiveprofile'][$right])) {
                unset($_SESSION['glpiactiveprofile'][$right]);
            }
        }
        if (isset($_SESSION['glpimenu']['management']['types']['PluginPrintgestionMenu'])) {
            unset($_SESSION['glpimenu']['management']['types']['PluginPrintgestionMenu']);
        }
        if (isset($_SESSION['glpimenu']['management']['content']['pluginprintgestionmenu'])) {
            unset($_SESSION['glpimenu']['management']['content']['pluginprintgestionmenu']);
        }
    }

    static function install(Migration $migration) {
        return true;
    }

    static function uninstall(Migration $migration) {
        return true;
    }
}
