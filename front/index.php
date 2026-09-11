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
    Html::displayNotFoundError();
}
if (!PluginPrintgestionMenu::canView()) {
    Html::displayRightError();
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
            getEntitiesRestrictCriteria($av_table, '', '', true)
        ))
        : 0;
    $stat_cards[] = ['url' => $base . '/front/dashboard_alerts.php', 'count' => (int) $toner_crit,
        'label'   => __('Toners critiques', 'printgestion'),
        'tooltip' => __('Toners au niveau critique sur les imprimantes supervisées', 'printgestion'),
        'icon' => 'ti ti-droplet', 'color' => 'red'];
}

PluginPrintgestionUi::statsBar($stat_cards, 'printgestionHubStatsBar');

// ── Cartes de catégories (chacune liste ses sous-onglets accessibles) ─────────
echo "<h4 class='mb-2'>" . __('Modules', 'printgestion') . "</h4>";
echo "<div class='row g-3'>";

foreach (PluginPrintgestionMenu::categories() as $cat) {
    if (!PluginPrintgestionConfig::isFeatureEnabled($cat['feature'])) {
        continue;
    }
    $tabs = array_filter($cat['tabs'], static function ($t) use ($cat) {
        return PluginPrintgestionMenu::tabAllowed($cat['feature'], $t['right']);
    });
    if (empty($tabs)) {
        continue;
    }

    echo "<div class='col-md-6 col-xl-4'><div class='card h-100'>";
    echo "<div class='card-header d-flex align-items-center'>"
        . "<i class='{$cat['icon']} me-2 fs-2 text-secondary'></i>"
        . "<h3 class='card-title mb-0'>" . htmlspecialchars($cat['label'], ENT_QUOTES, 'UTF-8') . "</h3></div>";
    echo "<div class='list-group list-group-flush'>";
    foreach ($tabs as $t) {
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

Html::footer();
