<?php
/**
 * PluginPrintgestionContract — itemtype « virtuel » dédié au Dashboard.
 *
 * Sous-classe de Contract réutilisant la table native `glpi_contracts` et la
 * fiche native du contrat. Son seul rôle : offrir au moteur de recherche un
 * itemtype distinct de `Contract`, ce qui permet :
 *   - de borner NATIVEMENT le périmètre aux « contrats liés à ≥1 imprimante »
 *     via plugin_printgestion_addDefaultWhere() (hook AUTO_ADD_DEFAULT_WHERE,
 *     appliqué automatiquement aux itemtypes de plugin) → affichage ET export
 *     (report.dynamic.php) filtrés de la même manière ;
 *   - d'ISOLER complètement la session de recherche, les colonnes et les
 *     recherches sauvegardées de la recherche Contrat globale de l'utilisateur
 *     (namespace $_SESSION['glpisearch']['PluginPrintgestionContract']) ;
 *   - de porter l'autorisation par le droit du plugin (et non le droit natif
 *     `contract`), conformément au §1.1.
 *
 * Aucune donnée propre, aucune table : tout pointe vers `glpi_contracts`.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionContract extends Contract {

    static $rightname = 'plugin_printgestion_contrats';

    /**
     * Réutilise la table native des contrats (sinon GLPI déduirait
     * « glpi_plugin_printgestion_contracts » du nom de classe).
     */
    public static function getTable($classname = null) {
        return 'glpi_contracts';
    }

    public static function getTypeName($nb = 0) {
        return _n('Contrat d\'impression', 'Contrats d\'impression', $nb, 'printgestion');
    }

    /**
     * L'accès au Dashboard est porté par le bit READ du plugin (pas le droit
     * natif `contract`).
     */
    public static function canView(): bool {
        return Session::haveRight('plugin_printgestion_contrats', READ);
    }

    /**
     * La « recherche » de cet itemtype EST la page Dashboard du plugin.
     * (Sert de cible par défaut aux recherches sauvegardées / formulaire.)
     */
    public static function getSearchURL($full = true) {
        $dir = $full ? PLUGIN_PRINTGESTION_WEBDIR : PLUGIN_PRINTGESTION_NOTFULL_WEBDIR;
        return $dir . '/front/list.php';
    }

    /**
     * Les liens « Contrat » de la liste doivent ouvrir la fiche NATIVE du
     * contrat, indépendamment de l'état du cache table→itemtype de GLPI
     * (glpi_contracts peut être résolu vers cette sous-classe pendant la requête).
     */
    public static function getFormURL($full = true) {
        return Contract::getFormURL($full);
    }

    public static function getFormURLWithID($id = 0, $full = true) {
        return Contract::getFormURLWithID($id, $full);
    }

    /**
     * Feature « State » : déléguée à Contract.
     *
     * Contract::rawSearchOptions() (héritée) construit l'option 31 « Statut » via
     * $this->getStateVisibilityCriteria(), et la feature State::checkSetup() exige
     * que la classe (liaison statique tardive → cette sous-classe) figure dans
     * $CFG_GLPI['state_types']. Plutôt que d'y enregistrer la sous-classe (ce qui
     * polluerait l'admin et casserait la visibilité des statuts, configurée pour
     * « Contract »), on délègue à une instance Contract : checkSetup() passe et la
     * visibilité des statuts reste celle des contrats.
     */
    public function getStateVisibilityCriteria(): array {
        return (new Contract())->getStateVisibilityCriteria();
    }

    public function isStateVisible(int $id): bool {
        return (new Contract())->isStateVisible($id);
    }

    // Identifiants des search options calculées propres au plugin (au-dessus de
    // la plage des options natives de Contract pour éviter toute collision).
    const SO_DAYS_LEFT = 9001; // Temps restant (jours)
    const SO_IN_NOTICE = 9002; // En préavis (booléen calculé)
    const SO_END_YEAR  = 9003; // Année de fin = YEAR(date_fin) (filtre du graphe)

    // ── SQL partagé (SOURCE DE VÉRITÉ unique) ─────────────────────────────────
    // Ces expressions sont utilisées À LA FOIS par les search options (filtres
    // de la liste) ET par les compteurs/graphiques du Dashboard. Tout part du
    // même SQL → cohérence garantie (et pas d'écart d'arithmétique des mois
    // entre PHP et MySQL). Le paramètre $t est la référence de table :
    //   - 'TABLE' pour les search options (GLPI remplace par `glpi_contracts`) ;
    //   - 'glpi_contracts' pour les requêtes directes du Dashboard.

    /** date_fin = begin_date + duration mois. */
    public static function sqlEndDate(string $t): string {
        return "DATE_ADD($t.begin_date, INTERVAL $t.duration MONTH)";
    }

    /** Temps restant en jours (signé). NULL si begin_date NULL. Négatif = expiré. */
    public static function sqlDaysLeft(string $t): string {
        return 'DATEDIFF(' . self::sqlEndDate($t) . ', CURDATE())';
    }

    /** 1 si aujourd'hui est dans la fenêtre de préavis [fin - notice ; fin[, sinon 0. */
    public static function sqlInNotice(string $t): string {
        $end = self::sqlEndDate($t);
        return "(CASE WHEN $t.begin_date IS NOT NULL"
            . " AND $t.duration > 0 AND $t.notice > 0"
            . " AND CURDATE() < $end"
            . " AND CURDATE() >= DATE_ADD($t.begin_date,"
            . " INTERVAL ($t.duration - LEAST($t.notice, $t.duration)) MONTH)"
            . " THEN 1 ELSE 0 END)";
    }

    /**
     * Options de recherche : celles de Contract (héritées) + colonnes CALCULÉES
     * propres au plugin, triables/filtrables (via le mécanisme natif `computation`).
     *
     *  - Temps restant (jours) = DATEDIFF(date_fin, aujourd'hui). Négatif = expiré.
     *    Filtrable avec opérateurs relatifs (« <30 », « >=0 »), et triable.
     *  - En préavis (0/1) = aujourd'hui dans la fenêtre [date_fin - notice ; date_fin[
     *    (notice borné à duration). Sert au filtre de la carte « En préavis ».
     */
    public function rawSearchOptions() {
        $tab = parent::rawSearchOptions();

        // En-tête de groupe pour nos colonnes (sinon elles héritent du dernier
        // groupe de Contract, « Coût »).
        $tab[] = [
            'id'   => 'printgestion',
            'name' => __('Gestion Print', 'printgestion'),
        ];

        $tab[] = [
            'id'            => (string) self::SO_DAYS_LEFT,
            'table'         => self::getTable(),
            'field'         => '_gp_days_left',
            'name'          => __('Temps restant', 'printgestion'),
            'datatype'      => 'number',
            'computation'   => self::sqlDaysLeft('TABLE'),
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => (string) self::SO_IN_NOTICE,
            'table'         => self::getTable(),
            'field'         => '_gp_in_notice',
            'name'          => __('En préavis', 'printgestion'),
            'datatype'      => 'bool',
            'computation'   => self::sqlInNotice('TABLE'),
            'massiveaction' => false,
        ];

        // Année de fin (filtrable equals) — utilisée par le clic « date de fin »
        // du graphe. YEAR(date_fin) = NULL si begin_date NULL → non comptée.
        $tab[] = [
            'id'            => (string) self::SO_END_YEAR,
            'table'         => self::getTable(),
            'field'         => '_gp_end_year',
            'name'          => __('Année de fin', 'printgestion'),
            'datatype'      => 'number',
            'computation'   => 'YEAR(' . self::sqlEndDate('TABLE') . ')',
            'massiveaction' => false,
        ];

        return $tab;
    }

    static function install(Migration $migration) {
        return true;
    }

    static function uninstall(Migration $migration) {
        return true;
    }
}
