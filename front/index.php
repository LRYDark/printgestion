<?php
/**
 * Print Gestion — page d'accueil (hub).
 * Barre de synthèse commune (contrats en préavis/dépassés, toners critiques) +
 * cartes des catégories (chacune liste ses sous-onglets accessibles).
 */

include('../../../inc/includes.php');

global $DB; // fichier front chargé hors portée globale (LegacyFileLoadController)

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
if (!PluginPrintgestionMenu::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::header(
    PluginPrintgestionMenu::getTypeName(),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

$base = PLUGIN_PRINTGESTION_WEBDIR;

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('hub');

// ── Barre de synthèse (même rendu que les barres de stats de gestion et rp) ───
$stat_cards = [];

if (PluginPrintgestionConfig::isFeatureEnabled('contrats')
    && Session::haveRight('plugin_printgestion_contrats', READ)) {
    $c       = PluginPrintgestionDashboard::counts();
    $nb_all  = (int) ($c['total'] ?? 0);

    $stat_cards[] = ['url' => $base . '/front/list.php?reset=reset', 'count' => $nb_all,
        'label' => _n('Contrat d\'impression', 'Contrats d\'impression', $nb_all, 'printgestion'),
        'icon' => 'ti ti-file-text', 'color' => 'primary'];
    $stat_cards[] = ['url' => $base . '/front/dashboard.php', 'count' => (int) ($c['expired'] ?? 0),
        'label' => __('Contrats dépassés', 'printgestion'),
        'icon' => 'ti ti-alert-triangle', 'color' => 'red'];
    $stat_cards[] = ['url' => $base . '/front/dashboard.php', 'count' => (int) ($c['notice'] ?? 0),
        'label' => __('Contrats en préavis', 'printgestion'),
        'icon' => 'ti ti-hourglass', 'color' => 'orange'];
}

if (PluginPrintgestionConfig::isFeatureEnabled('toner')
    && Session::haveRight('plugin_printgestion_dashboard', READ)) {
    // Compteur léger sur la table matérialisée (pas de calcul SNMP ici).
    $av_table   = 'glpi_plugin_printgestion_alertview';
    $toner_crit = $DB->tableExists($av_table)
        ? countElementsInTable($av_table, array_merge(
            ['status' => 'critical'],
            // Pas de clause « récursif » : la table n'a pas de colonne is_recursive
            // (la demander provoquait une erreur SQL dès qu'une entité parente existait).
            getEntitiesRestrictCriteria($av_table, '', '', false)
        ))
        : 0;
    $stat_cards[] = ['url' => $base . '/front/dashboard_alerts.php', 'count' => (int) $toner_crit,
        'label'   => __('Toners critiques', 'printgestion'),
        'tooltip' => __('Toners au niveau critique sur les imprimantes supervisées', 'printgestion'),
        'icon' => 'ti ti-droplet', 'color' => 'red'];
}

PluginPrintgestionUi::statsBar($stat_cards, 'printgestionHubStatsBar');

// ── Cartes de catégories (chacune liste ses sous-onglets accessibles) ─────────
// Dans l'ordre choisi par l'utilisateur (glisser-déposer par la poignée), sinon dans l'ordre par défaut.
$users_id = (int) Session::getLoginUserID();
try {
    $card_order = PluginPrintgestionHubpref::getOrder($users_id);
} catch (Throwable $e) {
    // Un ordre illisible n'empêche jamais l'accueil de s'afficher : ordre par défaut.
    PluginPrintgestionLogger::error('accueil', sprintf('Ordre des cartes de l\'utilisateur %d illisible : ordre par défaut.', $users_id), $e);
    $card_order = [];
}

$cards = [];
foreach (PluginPrintgestionHubpref::sort(PluginPrintgestionMenu::categories(), $card_order) as $key => $cat) {
    if (!PluginPrintgestionConfig::isFeatureEnabled($cat['feature'])) {
        continue;
    }
    $tabs = array_filter($cat['tabs'], static function ($t) use ($cat) {
        return PluginPrintgestionMenu::tabAllowed($cat['feature'], $t['right']);
    });
    if (!empty($tabs)) {
        $cards[$key] = ['cat' => $cat, 'tabs' => $tabs];
    }
}
$can_sort = count($cards) > 1;

echo "<div class='d-flex align-items-center flex-wrap gap-2 mb-2'>";
echo "<h4 class='mb-0'>" . __('Modules', 'printgestion') . "</h4>";
if ($can_sort) {
    // Visible dès que l'utilisateur a rangé ses cartes (révélé après le premier glisser-déposer).
    echo "<button type='button' id='printgestionHubReset' class='btn btn-sm btn-ghost-secondary ms-auto"
        . ($card_order === [] ? ' d-none' : '') . "'>"
        . "<i class='ti ti-arrow-back-up me-1'></i>" . __('Ordre par défaut', 'printgestion') . "</button>";
}
echo "</div>";
echo "<div class='row g-3' id='printgestionHubCards'>";

$grip_label = htmlspecialchars(__('Déplacer la carte', 'printgestion'), ENT_QUOTES, 'UTF-8');
foreach ($cards as $key => $card) {
    $cat = $card['cat'];
    echo "<div class='col-md-6 col-xl-4 printgestion-hub-card' data-key='" . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . "'>"
        . "<div class='card h-100'>";
    echo "<div class='card-header d-flex align-items-center'>"
        . "<i class='{$cat['icon']} me-2 fs-2 text-secondary'></i>"
        . "<h3 class='card-title mb-0'>" . htmlspecialchars($cat['label'], ENT_QUOTES, 'UTF-8') . "</h3>"
        . ($can_sort
            ? "<span class='printgestion-hub-grip ms-auto text-muted' title='{$grip_label}' aria-label='{$grip_label}'>"
                . "<i class='ti ti-grip-vertical fs-2'></i></span>"
            : '')
        . "</div>";
    echo "<div class='list-group list-group-flush'>";
    foreach ($card['tabs'] as $t) {
        $href = htmlspecialchars($base . $t['path'], ENT_QUOTES, 'UTF-8');
        echo "<a href='{$href}' class='list-group-item list-group-item-action d-flex align-items-center'>"
            . "<i class='{$t['icon']} me-2 text-muted'></i>"
            . htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8')
            . "<i class='ti ti-chevron-right ms-auto text-muted'></i></a>";
    }
    echo "</div></div></div>";
}

echo "</div>"; // row modules
echo "</div>"; // container

// Glisser-déposer : html5sortable, livré et chargé par GLPI sur toutes ses pages (tri des recherches enregistrées,
// des règles). L'ordre part à chaque déplacement ; un échec le dit, sans rien défaire à l'écran.
if ($can_sort) {
    $order_url  = json_encode(PLUGIN_PRINTGESTION_WEBDIR . '/ajax/hub_order.php', JSON_UNESCAPED_SLASHES);
    $msg_failed = json_encode(__('L\'ordre des cartes n\'a pas été enregistré.', 'printgestion'), JSON_UNESCAPED_UNICODE);
    echo "<style>
  .printgestion-hub-grip { cursor: grab; }
  .printgestion-hub-placeholder { border: 2px dashed var(--tblr-border-color, #dce1e7); background: transparent; box-shadow: none; }
</style>";
    echo "<script>$(function () {
  var list = document.getElementById('printgestionHubCards');
  if (!list || typeof sortable !== 'function') { return; }
  var reset = document.getElementById('printgestionHubReset');
  var failed = function () {
    if (typeof glpi_toast_error === 'function') { glpi_toast_error({$msg_failed}); } else { alert({$msg_failed}); }
  };
  var post = function (body) {
    var csrf = document.querySelector('meta[property=\"glpi:csrf_token\"]');
    return fetch({$order_url}, {
      method: 'POST', credentials: 'same-origin', body: body,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrf ? csrf.content : '' }
    }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
  };
  sortable(list, {
    items: '.printgestion-hub-card',
    handle: '.printgestion-hub-grip',
    forcePlaceholderSize: true,
    placeholder: '<div class=\"col-md-6 col-xl-4\"><div class=\"card h-100 printgestion-hub-placeholder\"></div></div>'
  });
  list.addEventListener('sortupdate', function () {
    var body = new URLSearchParams();
    body.append('action', 'save');
    list.querySelectorAll('.printgestion-hub-card').forEach(function (el) { body.append('keys[]', el.dataset.key); });
    post(body).then(function (data) {
      if (data && data.ok === true) { if (reset) { reset.classList.remove('d-none'); } } else { failed(); }
    });
  });
  if (reset) {
    reset.addEventListener('click', function () {
      var body = new URLSearchParams();
      body.append('action', 'reset');
      post(body).then(function (data) {
        if (data && data.ok === true) { window.location.reload(); } else { failed(); }
      });
    });
  }
});</script>";
}

Html::footer();
