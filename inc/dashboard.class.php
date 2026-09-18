<?php
/**
 * PluginPrintgestionDashboard — surveillance des contrats d'impression.
 *
 *   - showCards()  : barre de synthèse (vignettes cliquables → onglet Liste filtré).
 *   - showCharts() : camembert (répartition par statut) + barres (contrats par
 *     année), via ECharts (lib bundlée GLPI, chargée par Html::requireJs('charts')).
 *   - showList()   : moteur de recherche NATIF sur l'itemtype dédié
 *     PluginPrintgestionContract (form intégré + résultats + colonne calculée
 *     « Temps restant »). Drill-down via critères GLPI dans l'URL (criteria[...]).
 *
 * COHÉRENCE (point critique) : compteurs, camembert, surcouche orange ET filtres
 * de la liste partagent EXACTEMENT le même SQL (PluginPrintgestionContract::sql*)
 * et le même périmètre (scopeWhere/scopeJoin = celui de addDefaultWhere). Aucun
 * écart possible entre « ce qu'affiche la carte » et « ce que filtre la liste ».
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionDashboard extends CommonGLPI {

    static $rightname = 'plugin_printgestion_contrats';

    // Search options natives de Contract utilisées comme colonnes par défaut.
    const SO_NAME     = 1;   // Contrat (itemlink → fiche native)
    const SO_ENTITY   = 80;  // Client = Entité
    const SO_END_DATE = 20;  // Date de fin (date_delay)
    const SO_NOTICE   = 7;   // Durée du préavis (mois)

    // Fenêtre « bientôt expirés » (mois). Pas de table de config (§7) → constante.
    const SOON_MONTHS = 3;

    // Nombre d'années affichées dans le graphe « contrats par année ».
    const YEARS_BACK = 7;

    static function getTypeName($nb = 0) {
        return __('Dashboard', 'printgestion');
    }

    // ── Périmètre commun (IDENTIQUE à la liste / addDefaultWhere) ─────────────

    /** WHERE : contrats liés à une imprimante EXISTANTE + entité courante. */
    private static function scopeWhere(): array {
        return array_merge([
            'glpi_contracts_items.itemtype' => 'Printer',
            'glpi_contracts.is_deleted'     => 0,
            'glpi_contracts.is_template'    => 0,
            'glpi_printers.is_deleted'      => 0,
            'glpi_printers.is_template'     => 0,
        ], getEntitiesRestrictCriteria('glpi_contracts', '', '', true));
    }

    /** JOINs : contrat → contracts_items (Printer) → printer. */
    private static function scopeJoin(): array {
        return [
            'glpi_contracts_items' => [
                'ON' => ['glpi_contracts_items' => 'contracts_id', 'glpi_contracts' => 'id'],
            ],
            'glpi_printers' => [
                'ON' => ['glpi_printers' => 'id', 'glpi_contracts_items' => 'items_id'],
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Onglet Dashboard : tuiles + graphiques
    // ══════════════════════════════════════════════════════════════════════════

    public static function showCards(): void {
        $s = self::computeStats();
        // Rien à montrer : dire par quoi commencer, pas des compteurs à zéro.
        if ((int) $s['total'] === 0) {
            echo PluginPrintgestionUi::emptyState(
                __('Aucun contrat d\'impression pour l\'instant. Commencez par « Créer Print » : un contrat et son imprimante en un seul formulaire. Un contrat GLPI existant compte dès qu\'il est d\'un type « consommables inclus » (Configuration → Print Gestion, section Commandes).', 'printgestion'),
                [__('Créer Print', 'printgestion') => PLUGIN_PRINTGESTION_WEBDIR . '/front/print.form.php']
            );
        }

        PluginPrintgestionUi::statsBar([
            ['url' => self::cardUrl('all'), 'count' => $s['total'],
             'label' => _n('Contrat d\'impression', 'Contrats d\'impression', $s['total'], 'printgestion'),
             'icon' => 'ti ti-file-text', 'color' => 'primary'],
            ['url' => self::cardUrl('notice'), 'count' => $s['notice'],
             'label'   => __('En préavis', 'printgestion'),
             'tooltip' => __('Contrats dont le préavis de résiliation a commencé', 'printgestion'),
             'icon' => 'ti ti-hourglass', 'color' => 'orange'],
            ['url' => self::cardUrl('expired'), 'count' => $s['expired'],
             'label'   => __('Dépassés', 'printgestion'),
             'tooltip' => __('Contrats dont la date de fin est passée', 'printgestion'),
             'icon' => 'ti ti-alert-triangle', 'color' => 'red'],
            ['url' => self::cardUrl('soon'), 'count' => $s['soon'],
             'label' => sprintf(__('Bientôt expirés (%d mois)', 'printgestion'), self::SOON_MONTHS),
             'icon' => 'ti ti-clock', 'color' => 'azure'],
            // Pas de lien : la liste porte sur les contrats, pas les imprimantes.
            ['count' => $s['printers'],
             'label' => _n('Imprimante sous contrat', 'Imprimantes sous contrat', $s['printers'], 'printgestion'),
             'icon' => 'ti ti-printer', 'color' => 'secondary'],
        ], 'printgestionContractStatsBar');
    }

    public static function showCharts(): void {
        $status = self::chartStatusData();
        $byyear = self::chartByYearData();

        echo "<div class='row g-3 printgestion-charts mb-3'>";

        echo "<div class='col-12 col-xl-5'><div class='card h-100'>"
            . "<div class='card-header'><h3 class='card-title mb-0'>"
            . __('Répartition par statut', 'printgestion') . "</h3></div>"
            . "<div class='card-body'>"
            . "<div id='printgestion-chart-status' class='printgestion-chart' data-chart='"
            . htmlspecialchars(json_encode($status), ENT_QUOTES, 'UTF-8') . "'></div>"
            . "</div></div></div>";

        echo "<div class='col-12 col-xl-7'><div class='card h-100'>"
            . "<div class='card-header'><h3 class='card-title mb-0'>"
            . __('Contrats par année', 'printgestion') . "</h3></div>"
            . "<div class='card-body'>"
            . "<div id='printgestion-chart-byyear' class='printgestion-chart' data-chart='"
            . htmlspecialchars(json_encode($byyear), ENT_QUOTES, 'UTF-8') . "'></div>"
            . "</div></div></div>";

        echo "</div>";
    }

    /**
     * Construit l'URL d'une tuile vers l'onglet Liste avec les VRAIS critères
     * GLPI dans l'URL (criteria[...]) → le moteur natif les lit, les applique ET
     * les affiche dans la barre de recherche. La tuile « total » réinitialise.
     */
    private static function cardUrl(string $filter): string {
        $base     = PLUGIN_PRINTGESTION_WEBDIR . '/front/list.php';
        $criteria = self::cardCriteria($filter);

        if (empty($criteria)) {
            return $base . '?reset=reset'; // total → liste complète (réinitialise)
        }
        return $base . '?' . http_build_query(['criteria' => $criteria]);
    }

    /**
     * Compteurs des tuiles, calculés en SQL avec EXACTEMENT les mêmes expressions
     * que les filtres de la liste (PluginPrintgestionContract::sql*) et le même
     * périmètre → chaque compteur = nombre d'éléments obtenus en cliquant la tuile.
     *
     * Note : « notice » et « soon » se recouvrent volontairement (une tuile = un
     * filtre indépendant). La répartition exclusive est dans le camembert.
     */
    /** Compteurs contrats (exposé pour le hub). */
    public static function counts(): array {
        return self::computeStats();
    }

    private static function computeStats(): array {
        global $DB;

        $days = PluginPrintgestionContract::sqlDaysLeft('glpi_contracts');
        $not  = PluginPrintgestionContract::sqlInNotice('glpi_contracts');
        $soon = self::SOON_MONTHS * 30;
        $id   = '`glpi_contracts`.`id`';

        $row = $DB->request([
            'SELECT'     => [
                new QueryExpression("COUNT(DISTINCT $id) AS `total`"),
                new QueryExpression("COUNT(DISTINCT CASE WHEN ($days) < 0 THEN $id END) AS `expired`"),
                new QueryExpression("COUNT(DISTINCT CASE WHEN ($not) = 1 THEN $id END) AS `notice`"),
                new QueryExpression("COUNT(DISTINCT CASE WHEN ($days) >= 0 AND ($days) <= $soon THEN $id END) AS `soon`"),
                new QueryExpression("COUNT(DISTINCT `glpi_printers`.`id`) AS `printers`"),
            ],
            'FROM'       => 'glpi_contracts',
            'INNER JOIN' => self::scopeJoin(),
            'WHERE'      => self::scopeWhere(),
        ])->current();

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'expired'  => (int) ($row['expired'] ?? 0),
            'notice'   => (int) ($row['notice'] ?? 0),
            'soon'     => (int) ($row['soon'] ?? 0),
            'printers' => (int) ($row['printers'] ?? 0),
        ];
    }

    /**
     * Données du camembert : répartition en statuts MUTUELLEMENT EXCLUSIFS
     * (priorité dépassé > préavis > bientôt > en cours), + non calculable.
     * La somme des parts = total des contrats d'impression.
     */
    private static function chartStatusData(): array {
        global $DB;

        $days = PluginPrintgestionContract::sqlDaysLeft('glpi_contracts');
        $not  = PluginPrintgestionContract::sqlInNotice('glpi_contracts');
        $soon = self::SOON_MONTHS * 30;
        $id   = '`glpi_contracts`.`id`';

        $row = $DB->request([
            'SELECT'     => [
                new QueryExpression("COUNT(DISTINCT CASE WHEN ($days) < 0 THEN $id END) AS `expired`"),
                new QueryExpression("COUNT(DISTINCT CASE WHEN ($days) >= 0 AND ($not) = 1 THEN $id END) AS `notice`"),
                new QueryExpression("COUNT(DISTINCT CASE WHEN ($days) >= 0 AND ($not) = 0 AND ($days) <= $soon THEN $id END) AS `soon`"),
                new QueryExpression("COUNT(DISTINCT CASE WHEN ($days) > $soon AND ($not) = 0 THEN $id END) AS `ok`"),
                new QueryExpression("COUNT(DISTINCT CASE WHEN `glpi_contracts`.`begin_date` IS NULL OR `glpi_contracts`.`duration` <= 0 THEN $id END) AS `na`"),
            ],
            'FROM'       => 'glpi_contracts',
            'INNER JOIN' => self::scopeJoin(),
            'WHERE'      => self::scopeWhere(),
        ])->current();

        // [label, valeur, couleur] — n'inclut que les parts non nulles.
        $slices = [
            ['name' => __('Dépassés', 'printgestion'),          'value' => (int) ($row['expired'] ?? 0), 'color' => '#d63939'],
            ['name' => __('En préavis', 'printgestion'),        'value' => (int) ($row['notice'] ?? 0),  'color' => '#f59f00'],
            ['name' => __('Bientôt expirés', 'printgestion'),   'value' => (int) ($row['soon'] ?? 0),     'color' => '#4299e1'],
            ['name' => __('En cours', 'printgestion'),          'value' => (int) ($row['ok'] ?? 0),       'color' => '#2fb344'],
            ['name' => __('Non calculable', 'printgestion'),    'value' => (int) ($row['na'] ?? 0),       'color' => '#adb5bd'],
        ];
        return array_values(array_filter($slices, static fn($s) => $s['value'] > 0));
    }

    /**
     * Données du graphe « contrats par année » sur les YEARS_BACK dernières
     * années, en DEUX séries :
     *   - Date de début (bleu)  : YEAR(begin_date) = Y ;
     *   - Date de fin   (orange): YEAR(date_fin)  = Y, où date_fin = begin+durée.
     * Chaque barre est cliquable → Liste filtrée sur l'année (filtre cohérent
     * avec le comptage : option 5 pour le début, option « Année de fin » 9003).
     */
    private static function chartByYearData(): array {
        global $DB;

        $current = (int) date('Y');
        $years   = range($current - (self::YEARS_BACK - 1), $current);
        $id      = '`glpi_contracts`.`id`';
        $begin   = 'YEAR(`glpi_contracts`.`begin_date`)';
        $end     = 'YEAR(' . PluginPrintgestionContract::sqlEndDate('glpi_contracts') . ')';
        $base    = PLUGIN_PRINTGESTION_WEBDIR . '/front/list.php';

        $select = [];
        foreach ($years as $y) {
            $y = (int) $y;
            $select[] = new QueryExpression("COUNT(DISTINCT CASE WHEN $begin = $y THEN $id END) AS `b$y`");
            $select[] = new QueryExpression("COUNT(DISTINCT CASE WHEN $end = $y THEN $id END) AS `e$y`");
        }

        $row = $DB->request([
            'SELECT'     => $select,
            'FROM'       => 'glpi_contracts',
            'INNER JOIN' => self::scopeJoin(),
            'WHERE'      => self::scopeWhere(),
        ])->current();

        $begin_counts = [];
        $end_counts   = [];
        $begin_urls   = [];
        $end_urls     = [];
        foreach ($years as $y) {
            $y = (int) $y;
            $begin_counts[] = (int) ($row['b' . $y] ?? 0);
            $end_counts[]   = (int) ($row['e' . $y] ?? 0);

            // Début : begin_date contient Y (≡ YEAR(begin_date) = Y).
            $begin_urls[(string) $y] = $base . '?' . http_build_query([
                'criteria' => [[
                    'link'       => 'AND',
                    'field'      => 5, // Contract : begin_date
                    'searchtype' => 'contains',
                    'value'      => (string) $y,
                ]],
            ]);
            // Fin : option calculée « Année de fin » = Y (≡ YEAR(date_fin) = Y).
            $end_urls[(string) $y] = $base . '?' . http_build_query([
                'criteria' => [[
                    'link'       => 'AND',
                    'field'      => PluginPrintgestionContract::SO_END_YEAR,
                    'searchtype' => 'equals',
                    'value'      => (string) $y,
                ]],
            ]);
        }

        return [
            'years'  => array_map('strval', $years),
            'series' => [
                ['name' => __('Date de début', 'printgestion'), 'color' => '#4263eb', 'counts' => $begin_counts, 'urls' => $begin_urls],
                ['name' => __('Date de fin', 'printgestion'),   'color' => '#f59f00', 'counts' => $end_counts,   'urls' => $end_urls],
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Onglet Liste : recherche native + surcouche
    // ══════════════════════════════════════════════════════════════════════════

    public static function showList(): void {
        self::renderNoticeOverlayData();
        self::renderContractSearch();
    }

    private static function renderContractSearch(): void {
        $itemtype = PluginPrintgestionContract::class;

        // Les critères de drill-down arrivent nativement dans $_GET['criteria']
        // (liens des tuiles) → manageParams les lit et les applique tout seul.
        $params = Search::manageParams($itemtype, $_GET);
        $params['target'] = PLUGIN_PRINTGESTION_WEBDIR . '/front/list.php';

        if (!isset($_GET['sort'])) {
            $params['sort']  = self::SO_END_DATE;
            $params['order'] = 'ASC';
        }

        $forced_display = [
            self::SO_NAME,
            self::SO_ENTITY,
            self::SO_END_DATE,
            self::SO_NOTICE,
            PluginPrintgestionContract::SO_DAYS_LEFT, // colonne calculée « Temps restant »
        ];

        echo "<div class='search_page row'>";
        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            'layout/parts/saved_searches.html.twig',
            ['itemtype' => $itemtype]
        );
        echo "<div class='col search-container' data-glpi-search-container>";
        if ((int) self::computeStats()['total'] === 0) {
            echo PluginPrintgestionUi::emptyState(
                __('Aucun contrat d\'impression pour l\'instant. Commencez par « Créer Print » : un contrat et son imprimante en un seul formulaire.', 'printgestion'),
                [__('Créer Print', 'printgestion') => PLUGIN_PRINTGESTION_WEBDIR . '/front/print.form.php']
            );
        }
        Search::showList($itemtype, $params, $forced_display);
        echo "</div>";
        echo "</div>";
    }

    /** Critères de recherche associés à chaque tuile (drill-down). */
    private static function cardCriteria(string $filter): array {
        $soon_days = self::SOON_MONTHS * 30;

        switch ($filter) {
            case 'notice':
                return [[
                    'link'       => 'AND',
                    'field'      => PluginPrintgestionContract::SO_IN_NOTICE,
                    'searchtype' => 'equals',
                    'value'      => 1,
                ]];

            case 'expired':
                return [[
                    'link'       => 'AND',
                    'field'      => PluginPrintgestionContract::SO_DAYS_LEFT,
                    'searchtype' => 'contains',
                    'value'      => '<0',
                ]];

            case 'soon':
                // 0 ≤ temps restant ≤ N mois (non encore dépassé, bientôt échu).
                return [
                    [
                        'link'       => 'AND',
                        'field'      => PluginPrintgestionContract::SO_DAYS_LEFT,
                        'searchtype' => 'contains',
                        'value'      => '>=0',
                    ],
                    [
                        'link'       => 'AND',
                        'field'      => PluginPrintgestionContract::SO_DAYS_LEFT,
                        'searchtype' => 'contains',
                        'value'      => '<=' . $soon_days,
                    ],
                ];

            case 'all':
            default:
                return [];
        }
    }

    /**
     * Expose en JSON l'ensemble des contrats « en préavis » (mêmes SQL/périmètre
     * que le filtre de la liste) → la surcouche JS colore en orange la valeur
     * « Temps restant » de ces lignes. {id: 1, ...} pour un lookup O(1) côté JS.
     */
    private static function renderNoticeOverlayData(): void {
        global $DB;

        $not   = PluginPrintgestionContract::sqlInNotice('glpi_contracts');
        $where = array_merge(self::scopeWhere(), [new QueryExpression("($not) = 1")]);

        $set = [];
        foreach ($DB->request([
            'SELECT'     => ['glpi_contracts.id'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_contracts',
            'INNER JOIN' => self::scopeJoin(),
            'WHERE'      => $where,
        ]) as $row) {
            $set[(int) $row['id']] = 1;
        }

        $json = json_encode($set, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        if ($json === false) {
            $json = '{}';
        }
        echo "<div id='printgestion-dashboard' data-notice='"
            . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . "'></div>";
    }

    static function install(Migration $migration) {
        return true;
    }

    static function uninstall(Migration $migration) {
        return true;
    }
}
