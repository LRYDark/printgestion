<?php

global $DB; // fichier front chargé hors portée globale (LegacyFileLoadController)

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_expedition', READ);
if (!PluginPrintgestionConfig::isFeatureEnabled('toner')) { throw new \Glpi\Exception\Http\NotFoundHttpException(); }

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Html::header(
    __('Print Gestion — Expéditions', 'printgestion'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

// Écran des expéditions : c'est ici que le statut « livrée » se lit. Les BL signés depuis le dernier passage sont
// repris maintenant, pour que ce qui s'affiche soit à jour — du SQL local, rien de plus.
PluginPrintgestionTracking::syncOnDisplay();

$entities_id = (isset($_GET['entities_id']) && $_GET['entities_id'] !== '' && (int)$_GET['entities_id'] >= 0)
    ? (int)$_GET['entities_id'] : null;

$filter_status = $_GET['status'] ?? 'all';
if (!in_array($filter_status, ['all', 'pending', 'shipped', 'transit', 'delivered'], true)) {
    $filter_status = 'all';
}

$filter_period = $_GET['period'] ?? '30';
if (!in_array($filter_period, ['7', '30', '90', 'all'], true)) {
    $filter_period = '30';
}

// Calcul start/end en fonction de la période
$period_start = '';
$period_end   = date('Y-m-d');
if ($filter_period !== 'all') {
    $period_start = date('Y-m-d', strtotime("-{$filter_period} days"));
}

echo "<div class='container-fluid mt-3'>";

// Bouton « Rafraîchir » (recalcul complet des alertes) : droit de modification seulement.
PluginPrintgestionMenu::showTabBar('tn_exp', Session::haveRight('plugin_printgestion_dashboard', UPDATE));

// ── Compteurs par statut (calculés, restreints par l'entité de l'envoi, figée à sa création) ──
$exp_t         = 'glpi_plugin_printgestion_expeditions';
$status_counts = ['pending' => 0, 'shipped' => 0, 'transit' => 0, 'delivered' => 0, 'installed' => 0, 'cancelled' => 0];
$sel = [];
foreach (array_keys($status_counts) as $st) {
    $sel[] = new \Glpi\DBAL\QueryExpression("SUM(`{$exp_t}`.`statut` = '{$st}') AS `{$st}`");
}
$crow = $DB->request([
    'SELECT'     => $sel,
    'FROM'       => $exp_t,
    'INNER JOIN' => ['glpi_printers' => ['ON' => ['glpi_printers' => 'id', $exp_t => 'printers_id']]],
    'WHERE'      => array_merge(['glpi_printers.is_deleted' => 0], getEntitiesRestrictCriteria($exp_t, '', '', true)),
])->current();
foreach (array_keys($status_counts) as $st) {
    $status_counts[$st] = (int) ($crow[$st] ?? 0);
}

// Vignettes = filtres du tableau : statut exact (recherche native « contient » ancrée ^…$, colonne Statut).
$status_url = static fn(string $status): string => PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_expeditions.php?' . http_build_query([
    'criteria' => [['field' => 5, 'searchtype' => 'contains', 'value' => '^' . $status . '$']],
    'reset'    => 'reset',
]);
PluginPrintgestionUi::statsBar([
    ['count' => (int) $status_counts['pending'],   'label' => __('En attente', 'printgestion'),
     'icon' => 'ti ti-hourglass', 'color' => 'orange', 'url' => $status_url('pending')],
    ['count' => (int) $status_counts['shipped'],   'label' => __('Expédiées', 'printgestion'),
     'icon' => 'ti ti-send', 'color' => 'primary', 'url' => $status_url('shipped')],
    ['count' => (int) $status_counts['transit'],   'label' => __('En transit', 'printgestion'),
     'icon' => 'ti ti-truck', 'color' => 'azure', 'url' => $status_url('transit')],
    ['count' => (int) $status_counts['delivered'], 'label' => __('Livrées non posées', 'printgestion'),
     'tooltip' => __('Livrées mais pose non encore détectée ni confirmée : toujours bloquantes', 'printgestion'),
     'icon' => 'ti ti-package', 'color' => 'green', 'url' => $status_url('delivered')],
    ['count' => (int) $status_counts['installed'], 'label' => __('Posées', 'printgestion'),
     'icon' => 'ti ti-checks', 'color' => 'teal', 'url' => $status_url('installed')],
], 'printgestionExpeditionStatsBar');

// Rien à montrer : dire d'où vient une expédition, pas un tableau vide.
if (array_sum($status_counts) === 0) {
    echo PluginPrintgestionUi::emptyState(
        __('Aucune expédition pour l\'instant. Une expédition naît d\'une commande de cartouche : depuis « Alertes toner » (action « Commander » sur une alerte), ou d\'une demande d\'envoi validée puis exportée vers les Achats.', 'printgestion'),
        [
            __('Alertes toner', 'printgestion')      => PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_alerts.php',
            __('Demandes d\'envoi', 'printgestion')  => PLUGIN_PRINTGESTION_WEBDIR . '/front/demande.php',
        ]
    );
}

// ── Tableau NATIF (moteur de recherche GLPI) ──
// Restriction d'entité : entité de l'envoi (native, et seconde barrière dans plugin_printgestion_addDefaultWhere()).
$itemtype = 'PluginPrintgestionExpedition';
$params   = Search::manageParams($itemtype, $_GET);
$params['target'] = PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_expeditions.php';
if (!isset($_GET['sort'])) {
    $params['sort']  = 10;      // Date alerte
    $params['order'] = 'DESC';
}
$forced = [1, 2, 80, 3, 5, 6, 7, 10, 11, 12]; // ID, Imprimante, Client, Toner, Statut, Transporteur, N°, dates

echo "<div class='search_page row'>";
echo "<div class='col search-container' data-glpi-search-container>";
Search::showList($itemtype, $params, $forced);
echo "</div></div>";

echo "</div>"; // container-fluid

// ── Menu clic droit par-dessus le tableau natif ──────────────────────────────
// Fenêtres « Modifier expédition » et « Associer des BL » (script partagé), puis le menu : il lit lui-même le
// contexte des lignes affichées (ajax/rowcontext.php), plus de carte de toutes les expéditions dans la page.
PluginPrintgestionDashboardactions::renderSharedAssets('expeditions');
PluginPrintgestionContextmenu::render(PluginPrintgestionExpedition::class);

Html::footer();
