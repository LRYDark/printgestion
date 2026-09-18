<?php
/**
 * Alertes toner — moteur de recherche natif GLPI sur la table matérialisée des alertes
 * (PluginPrintgestionAlertview) : recherche, tri, filtres, colonnes et export natifs.
 * Actions de masse : Commander (droit de validation), Ne plus alerter / Réactiver
 * (modification des alertes). Recalcul complet : tâche horaire ou « Recalculer maintenant ».
 */
include('../../../inc/includes.php');

global $DB; // fichier front chargé hors portée globale (LegacyFileLoadController)

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_dashboard', READ);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$page = PluginPrintgestionAlertview::getSearchURL();

if (isset($_POST['recompute_alerts'])) {
    // Jeton CSRF validé par CheckCsrfListener. Calcul lourd : droit de modification.
    Session::checkRight('plugin_printgestion_dashboard', UPDATE);
    $count = PluginPrintgestionAlertview::rebuild();
    Session::addMessageAfterRedirect(sprintf(__('Alertes recalculées : %d toner(s).', 'printgestion'), $count), false, INFO);
    Html::redirect($page);
}

PluginPrintgestionAlertview::rebuildIfEmpty();

Html::header(
    __('Print Gestion — Alertes toner', 'printgestion'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'tn_alerts'
);

$esc   = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$table = PluginPrintgestionAlertview::getTable();

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('tn_alerts');

// ── Compteurs (entités de l'utilisateur) ──
$counts = $DB->request([
    'SELECT' => [
        new QueryExpression("COALESCE(SUM(`status` = 'critical'), 0) AS `critical`"),
        new QueryExpression("COALESCE(SUM(`status` = 'watch'), 0) AS `watch`"),
        new QueryExpression("COALESCE(SUM(`status` <> 'ok' AND `lock_reason` IS NOT NULL AND `lock_reason` <> 'bypassed'), 0) AS `locked`"),
        new QueryExpression("COALESCE(SUM(`status` <> 'ok' AND `ref_error` IS NOT NULL), 0) AS `unresolved`"),
        new QueryExpression("COALESCE(SUM(`level_suspect` = 1), 0) AS `suspect`"),
        new QueryExpression("MAX(`date_compute`) AS `computed`"),
    ],
    'FROM'   => $table,
    'WHERE'  => getEntitiesRestrictCriteria($table, '', '', false),
])->current();

$status_url = static function (string $status) use ($page): string {
    return $page . '?' . http_build_query([
        'criteria' => [['field' => 6, 'searchtype' => 'equals', 'value' => $status]],
        'reset'    => 'reset',
    ]);
};

// Collecte : une imprimante muette ne déclenche aucune alerte — à signaler ici aussi.
$collect = PluginPrintgestionCollect::analyze(true)['counts'];
$silent  = $collect[PluginPrintgestionCollect::STATE_STALE]
    + $collect[PluginPrintgestionCollect::STATE_NO_LEVEL]
    + $collect[PluginPrintgestionCollect::STATE_NO_INVENTORY];

PluginPrintgestionUi::statsBar([
    ['count' => (int) ($counts['critical'] ?? 0), 'label' => __('Critiques', 'printgestion'),
     'icon' => 'ti ti-alert-triangle', 'color' => 'red', 'url' => $status_url(PluginPrintgestionAlert::STATUS_CRITICAL)],
    ['count' => (int) ($counts['watch'] ?? 0), 'label' => __('À surveiller', 'printgestion'),
     'icon' => 'ti ti-eye', 'color' => 'orange', 'url' => $status_url(PluginPrintgestionAlert::STATUS_WATCH)],
    ['count' => (int) ($counts['locked'] ?? 0), 'label' => __('En alerte mais verrouillés', 'printgestion'),
     'tooltip' => __('Envoi ou demande en cours, garde après pose ou ticket récent : non commandables (colonne Verrou)', 'printgestion'),
     'icon' => 'ti ti-lock', 'color' => 'secondary'],
    ['count' => (int) ($counts['unresolved'] ?? 0), 'label' => __('Sans référence', 'printgestion'),
     'tooltip' => __('Toners en alerte dont la cartouche n\'est pas résolue : non commandables (colonne Référence non résolue)', 'printgestion'),
     'icon' => 'ti ti-help-hexagon', 'color' => 'purple'],
    ['count' => (int) ($counts['suspect'] ?? 0), 'label' => __('Niveaux figés suspects', 'printgestion'),
     'tooltip' => __('Niveau inchangé alors que l\'imprimante imprime : estimation approximative', 'printgestion'),
     'icon' => 'ti ti-snowflake', 'color' => 'azure'],
    ['count' => $silent, 'label' => __('Imprimantes muettes ou illisibles', 'printgestion'),
     'tooltip' => __('Aucune alerte possible sans remontée : voir « Contrôle de la remontée » (module Collecte SNMP / Déploiement Agent)', 'printgestion'),
     'icon' => 'ti ti-wifi-off', 'color' => 'dark',
     // Page d'un autre module, à droit distinct : lien seulement pour qui y a accès.
     'url' => PluginPrintgestionMenu::tabAllowed('deploiement', ['plugin_printgestion_deploiement', READ])
        ? PLUGIN_PRINTGESTION_WEBDIR . '/front/collect.php' : ''],
], 'printgestionAlertsStatsBar');

// ── Fraîcheur du calcul ──
$computed = (string) ($counts['computed'] ?? '');
$stale    = PluginPrintgestionAlertview::getStaleSince();
echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
echo "<span class='text-muted small'>" . $esc(sprintf(__('Alertes calculées le %s', 'printgestion'), Html::convDateTime($computed))) . "</span>";
if ($stale !== null && ($computed === '' || $stale > $computed)) {
    echo "<span class='badge bg-yellow-lt'>" . $esc(sprintf(
        __('Actions depuis le %s pas encore reflétées partout (commandes, annulations…) : une commande verrouillée reste refusée côté serveur.', 'printgestion'),
        Html::convDateTime($stale)
    )) . "</span>";
}
if (Session::haveRight('plugin_printgestion_dashboard', UPDATE)) {
    echo "<form method='post' action='" . $esc($page) . "' class='ms-auto'>";
    echo "<button type='submit' name='recompute_alerts' value='1' class='btn btn-sm btn-outline-secondary'>"
        . "<i class='ti ti-refresh me-1'></i>" . $esc(__('Recalculer maintenant', 'printgestion')) . "</button>";
    Html::closeForm();
}
echo "</div>";

// Rien à montrer : dire par quoi commencer, pas un tableau vide.
if (countElementsInTable('glpi_plugin_printgestion_alertview') === 0) {
    echo PluginPrintgestionUi::emptyState(
        __('Aucune alerte pour l\'instant. Les alertes viennent des relevés SNMP des sondes : déployer une sonde chez le client, raccorder ses imprimantes depuis la fiche de l\'entité (onglet Déploiement Agent), puis suivre la remontée. Les premières alertes apparaissent au passage de la tâche horaire, ou avec « Recalculer maintenant ».', 'printgestion'),
        [
            __('Installeur GLPI Agent', 'printgestion')      => PLUGIN_PRINTGESTION_WEBDIR . '/front/agentdeploy.php',
            __('Contrôle de la remontée', 'printgestion')    => PLUGIN_PRINTGESTION_WEBDIR . '/front/collect.php',
        ]
    );
}

// ── Tableau NATIF (restriction d'entité native : colonne entities_id) ──
$itemtype = PluginPrintgestionAlertview::class;
$params   = Search::manageParams($itemtype, $_GET);
$params['target'] = $page;
// Imprimante, Client, Toner, Cartouche, Niveau, Jours, Statut, Verrou, Envoi en cours, Référence
$forced = [1, 80, 2, 3, 4, 5, 6, 11, 16, 13];

echo "<div class='search_page row'>";
echo "<div class='col search-container' data-glpi-search-container>";
Search::showList($itemtype, $params, $forced);
echo "</div></div>";

echo "<p class='text-muted small mt-2'><i class='ti ti-info-circle me-1'></i>"
    . $esc(__('Cochez des toners puis « Actions » : Commander (droit de validation), Ne plus alerter pendant…, Réactiver les alertes. Les motifs de verrou et de référence non résolue figurent dans leurs colonnes.', 'printgestion'))
    . "</p>";

echo "</div>"; // container-fluid

Html::footer();
