<?php
/**
 * Print Gestion — Coût à la page.
 *
 * Tableau NATIF (moteur de recherche GLPI) sur la table matérialisée
 * glpi_plugin_printgestion_billing_view. Le formulaire de filtres (période /
 * client / vue) recalcule et matérialise les lignes de l'utilisateur courant,
 * puis Search::showList() prend le relais (tri / filtres / colonnes / export).
 *
 * Les paramètres du formulaire sont préfixés pg_* pour ne PAS entrer en conflit
 * avec les paramètres réservés du moteur Search (start = offset, sort, order…).
 * La sélection est mémorisée en session : elle survit donc à la pagination /
 * au tri natifs (qui ne renvoient pas les pg_*).
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_billing', READ);
if (!PluginPrintgestionConfig::isFeatureEnabled('cout')) { Html::displayNotFoundError(); }

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    Html::displayNotFoundError();
}

Html::header(
    __('Print Gestion — Coût à la page', 'printgestion'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

// ── Résolution des filtres : soumission du formulaire (pg_*) sinon session ────
$sess_key = 'plugin_printgestion_billing_filter';
$today    = new DateTime();

$has_filter = isset($_GET['pg_period']) || isset($_GET['pg_view'])
           || isset($_GET['pg_start'])  || isset($_GET['pg_entities']);

if ($has_filter) {
    $period = $_GET['pg_period'] ?? 'current';
    if (!in_array($period, ['current', 'previous', 'custom'], true)) {
        $period = 'current';
    }
    switch ($period) {
        case 'previous':
            $start = (clone $today)->modify('first day of previous month')->format('Y-m-d');
            $end   = (clone $today)->modify('last day of previous month')->format('Y-m-d');
            break;
        case 'custom':
            $start = (isset($_GET['pg_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['pg_start']))
                ? $_GET['pg_start'] : date('Y-m-01');
            $end   = (isset($_GET['pg_end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['pg_end']))
                ? $_GET['pg_end'] : date('Y-m-d');
            break;
        case 'current':
        default:
            $start = (clone $today)->modify('first day of this month')->format('Y-m-d');
            $end   = $today->format('Y-m-d');
            break;
    }
    $entities_id = (isset($_GET['pg_entities']) && $_GET['pg_entities'] !== '' && (int) $_GET['pg_entities'] >= 0)
        ? (int) $_GET['pg_entities'] : null;
    $view = $_GET['pg_view'] ?? 'printer';
    if (!in_array($view, ['printer', 'client'], true)) {
        $view = 'printer';
    }
    $_SESSION[$sess_key] = compact('period', 'start', 'end', 'entities_id', 'view');
} elseif (!empty($_SESSION[$sess_key])) {
    $f           = $_SESSION[$sess_key];
    $period      = $f['period'];
    $start       = $f['start'];
    $end         = $f['end'];
    $entities_id = $f['entities_id'];
    $view        = $f['view'];
} else {
    $period      = 'current';
    $start       = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $end         = $today->format('Y-m-d');
    $entities_id = null;
    $view        = 'printer';
}

// Vue courante exposée à addDefaultWhere (isolation imprimante|client).
$_SESSION['plugin_printgestion_billing_view'] = $view;

// ── (Re)matérialise les lignes de l'utilisateur courant ──────────────────────
$uid = (int) Session::getLoginUserID();
PluginPrintgestionBillingview::rebuildForUser($uid, $start, $end, $entities_id, $view);

echo "<div class='container-fluid mt-3'>";

PluginPrintgestionMenu::showTabBar('co_bill', true);

// ── Formulaire de filtres (GET, params pg_*) ─────────────────────────────────
echo "<form method='get' class='card mb-3' id='pc-filters-billing'>";
echo "<div class='card-body'>";
echo "<div class='row g-3 align-items-end'>";

echo "<div class='col-md-2'><label class='form-label mb-1'>" . __('Période', 'printgestion') . "</label>";
Dropdown::showFromArray('pg_period', [
    'current'  => __('Mois en cours', 'printgestion'),
    'previous' => __('Mois précédent', 'printgestion'),
    'custom'   => __('Période libre', 'printgestion'),
], ['value' => $period]);
echo "</div>";

$dates_display = ($period === 'custom') ? '' : 'display:none;';
echo "<div class='col-md-2' id='pc-billing-col-start' style='{$dates_display}'>"
    . "<label class='form-label mb-1'>" . __('Début', 'printgestion') . "</label>";
echo "<input type='date' class='form-control' name='pg_start' value='"
    . htmlspecialchars($start, ENT_QUOTES, 'UTF-8') . "'></div>";
echo "<div class='col-md-2' id='pc-billing-col-end' style='{$dates_display}'>"
    . "<label class='form-label mb-1'>" . __('Fin', 'printgestion') . "</label>";
echo "<input type='date' class='form-control' name='pg_end' value='"
    . htmlspecialchars($end, ENT_QUOTES, 'UTF-8') . "'></div>";

echo "<div class='col-md-3'><label class='form-label mb-1'>" . __('Client', 'printgestion') . "</label>";
Entity::dropdown([
    'name'                => 'pg_entities',
    'value'               => $entities_id ?? -1,
    'display_emptychoice' => true,
    'emptylabel'          => __('Tous', 'printgestion'),
]);
echo "</div>";

echo "<div class='col-md-2'><label class='form-label mb-1'>" . __('Vue', 'printgestion') . "</label>";
Dropdown::showFromArray('pg_view', [
    'printer' => __('Par imprimante', 'printgestion'),
    'client'  => __('Par client', 'printgestion'),
], ['value' => $view]);
echo "</div>";

echo "<div class='col-md-1'>"
    . "<button type='submit' class='btn btn-primary w-100'><i class='fa-solid fa-magnifying-glass'></i></button></div>";

echo "</div></div></form>";

// ── Barre de stats (sur les lignes matérialisées) ────────────────────────────
$m = PluginPrintgestionBillingview::metricsForUser($uid, $view);
PluginPrintgestionUi::statsBar([
    ['count' => number_format($m['nb_printers'], 0, ',', ' '),
     'label' => __('Imprimantes', 'printgestion'), 'icon' => 'ti ti-printer', 'color' => 'primary'],
    ['count' => number_format($m['nb_clients'], 0, ',', ' '),
     'label' => __('Clients', 'printgestion'), 'icon' => 'ti ti-users', 'color' => 'azure'],
    ['count' => number_format($m['total_nb'] + $m['total_color'], 0, ',', ' '),
     'label'   => __('Pages totales', 'printgestion'),
     'tooltip' => __('Pages noir et blanc et couleur cumulées sur la période', 'printgestion'),
     'icon' => 'ti ti-file-text', 'color' => 'secondary'],
    ['count' => number_format($m['total_cost'], 2, ',', ' ') . ' €',
     'label' => __('Coût total', 'printgestion'), 'icon' => 'ti ti-cash', 'color' => 'green'],
], 'printgestionBillingStatsBar');

// ── Export Excel (conservé) — le moteur Search ajoute en plus CSV / PDF ──────
if (Session::haveRight('plugin_printgestion_billing', CREATE)) {
    $export_params = ['start' => $start, 'end' => $end, 'view' => $view];
    if ($entities_id !== null) {
        $export_params['entities_id'] = $entities_id;
    }
    $export_url = PLUGIN_PRINTGESTION_WEBDIR . '/ajax/export_excel.php?' . http_build_query($export_params);
    echo "<div class='mb-3'><a href='" . htmlspecialchars($export_url, ENT_QUOTES, 'UTF-8')
        . "' class='btn btn-success'><i class='fa-solid fa-file-excel me-2'></i>"
        . __('Exporter Excel', 'printgestion') . "</a></div>";
}

// ── Tableau NATIF (moteur de recherche GLPI) ─────────────────────────────────
$itemtype = 'PluginPrintgestionBillingview';
$params   = Search::manageParams($itemtype, $_GET);
$params['target'] = PLUGIN_PRINTGESTION_WEBDIR . '/front/dashboard_billing.php';
if (!isset($_GET['sort'])) {
    $params['sort']  = 8;       // Coût total
    $params['order'] = 'DESC';
}
$forced = ($view === 'client')
    ? [80, 3, 4, 5, 8]              // Client, Imprimantes, Pages N&B, Pages Couleur, Coût
    : [1, 80, 2, 4, 5, 6, 7, 8];    // Imprimante, Client, Contrat, N&B, Couleur, Tarif N&B, Tarif Couleur, Coût

echo "<div class='search_page row'>";
echo "<div class='col search-container' data-glpi-search-container>";
Search::showList($itemtype, $params, $forced);
echo "</div></div>";

echo "</div>"; // container-fluid

// ── Toggle dates selon la période (le submit reste un GET natif) ─────────────
echo <<<HTML
<script>
(function() {
  const periodEl = document.querySelector('#pc-filters-billing [name=pg_period]');
  const colStart = document.getElementById('pc-billing-col-start');
  const colEnd   = document.getElementById('pc-billing-col-end');
  const startEl  = document.querySelector('#pc-filters-billing [name=pg_start]');
  const endEl    = document.querySelector('#pc-filters-billing [name=pg_end]');
  function fmtDate(d) {
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  }
  function syncPeriodBounds() {
    if (!periodEl) return;
    const p = periodEl.value;
    const show = (p === 'custom');
    if (colStart) colStart.style.display = show ? '' : 'none';
    if (colEnd)   colEnd.style.display   = show ? '' : 'none';
    if (p === 'current') {
      const now = new Date();
      if (startEl) startEl.value = fmtDate(new Date(now.getFullYear(), now.getMonth(), 1));
      if (endEl)   endEl.value   = fmtDate(now);
    } else if (p === 'previous') {
      const now = new Date();
      if (startEl) startEl.value = fmtDate(new Date(now.getFullYear(), now.getMonth() - 1, 1));
      if (endEl)   endEl.value   = fmtDate(new Date(now.getFullYear(), now.getMonth(), 0));
    }
  }
  if (periodEl) {
    periodEl.addEventListener('change', syncPeriodBounds);
    if (typeof jQuery !== 'undefined') { jQuery(periodEl).on('change', syncPeriodBounds); }
    syncPeriodBounds();
  }
})();
</script>
HTML;

// Bouton « Rafraîchir » de la barre d'onglets et menu contextuel « Ouvrir la fiche
// imprimante » : leur script partagé n'était pas chargé sur cet écran (bouton inerte).
PluginPrintgestionDashboardactions::renderSharedAssets('billing');

Html::footer();
