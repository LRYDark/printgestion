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

// Écran des alertes : le verrou anti-doublon dépend du statut des expéditions. Un BL signé qui n'aurait pas encore
// été repris ferait proposer une commande pour une cartouche déjà livrée.
PluginPrintgestionTracking::syncOnDisplay();

$page = PluginPrintgestionAlertview::getSearchURL();

if (isset($_POST['recompute_alerts'])) {
    // Jeton CSRF validé par CheckCsrfListener. Calcul lourd : droit de modification, et jamais dans la page — la
    // tâche minute PrintgestionRebuildAlerts le fait, l'écran se met à jour tout seul.
    Session::checkRight('plugin_printgestion_dashboard', UPDATE);
    PluginPrintgestionAlertview::requestRebuild();
    Session::addMessageAfterRedirect(__('Recalcul des alertes demandé : il tourne en arrière-plan, l\'écran se met à jour tout seul quand il est fini.', 'printgestion'), false, INFO);
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

// Retour d'une commande avec « Télécharger le fichier Gesconso » cochée : le fichier part au chargement.
PluginPrintgestionPurchaseorder::emitQueuedDownload();

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

// Collecte : une imprimante muette ne déclenche aucune alerte — à signaler ici aussi. Décompte lu dans la vue
// calculée par la tâche horaire (mêmes états, même périmètre) ; l'analyse complète, longue, seulement si la vue
// n'a pas été calculée depuis deux heures (module Déploiement désactivé, tâche arrêtée).
$collect_at = PluginPrintgestionCollectview::getComputedAt();
$collect    = ($collect_at !== null && strtotime($collect_at) >= time() - 2 * HOUR_TIMESTAMP)
    ? PluginPrintgestionCollectview::getCounts()
    : PluginPrintgestionCollect::analyze(true)['counts'];
$silent  = $collect[PluginPrintgestionCollect::STATE_STALE]
    + $collect[PluginPrintgestionCollect::STATE_NO_LEVEL]
    + $collect[PluginPrintgestionCollect::STATE_NO_INVENTORY];

// Recalcul complet (droit de modification) et date du dernier calcul dessous : à droite de la barre de stats. Un
// recalcul demandé tourne dans la tâche minute : l'écran l'annonce et se recharge seul quand il est fini.
$computed         = (string) ($counts['computed'] ?? '');
$pending          = PluginPrintgestionAlertview::getPendingRequest();
$waiting          = PluginPrintgestionAlertview::getRequestedAt(); // pas encore pris (ni tâche ni relais)
$late             = $waiting !== null && strtotime($waiting) < time() - PluginPrintgestionAlertview::REQUEST_LATE_SECONDS;
$recompute_button = "<div class='d-flex flex-column align-items-end gap-1'>"
    . (Session::haveRight('plugin_printgestion_dashboard', UPDATE)
        ? "<form method='post' action='" . $esc($page) . "' class='m-0'>"
            . "<button type='submit' name='recompute_alerts' value='1' class='btn btn-sm btn-outline-secondary'"
            . ($pending !== null ? ' disabled' : '') . ">"
            . "<i class='ti ti-refresh me-1'></i>" . $esc(__('Recalculer maintenant', 'printgestion')) . "</button>"
            . Html::closeForm(false)
        : '')
    . ($pending === null
        ? "<span class='text-muted small'>" . $esc(sprintf(__('Alertes calculées le %s', 'printgestion'), Html::convDateTime($computed))) . "</span>"
        : "<span class='small " . ($late ? 'text-danger' : 'text-muted') . "' id='pg-alerts-pending' data-pg-computed='" . $esc($computed) . "'>"
            . ($late ? "<i class='ti ti-alert-triangle me-1'></i>" : "<span class='spinner-border spinner-border-sm me-1'></span>")
            . $esc($late
                ? sprintf(__('Recalcul demandé le %s, toujours en attente : la tâche « PrintgestionRebuildAlerts » ne passe pas (Configuration → Actions automatiques, carte Santé de la configuration).', 'printgestion'), Html::convDateTime($pending))
                : sprintf(__('Recalcul en cours (demandé le %s)… Alertes du %s affichées.', 'printgestion'), Html::convDateTime($pending), Html::convDateTime($computed)))
            . "</span>")
    . "</div>";

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
     'tooltip' => __('Aucune alerte possible sans remontée : voir « Imprimantes collectées » (module Collecte SNMP / Déploiement Agent)', 'printgestion'),
     'icon' => 'ti ti-wifi-off', 'color' => 'dark',
     // Page d'un autre module, à droit distinct : lien seulement pour qui y a accès.
     'url' => PluginPrintgestionMenu::tabAllowed('deploiement', ['plugin_printgestion_deploiement', READ])
        ? PLUGIN_PRINTGESTION_WEBDIR . '/front/collect.php' : ''],
], 'printgestionAlertsStatsBar', $recompute_button);

// ── Fraîcheur du calcul : la date est dans la barre, l'avertissement seulement s'il y a lieu ──
$stale = PluginPrintgestionAlertview::getStaleSince();
if ($stale !== null && ($computed === '' || $stale > $computed)) {
    echo "<div class='mb-3'><span class='badge bg-yellow-lt'>" . $esc(sprintf(
        __('Actions depuis le %s pas encore reflétées partout (commandes, annulations…) : une commande verrouillée reste refusée côté serveur.', 'printgestion'),
        Html::convDateTime($stale)
    )) . "</span></div>";
}

// Rien à montrer : dire par quoi commencer, pas un tableau vide.
if (countElementsInTable('glpi_plugin_printgestion_alertview') === 0 && $pending !== null) {
    echo "<div class='alert alert-info'><div class='w-100'><span class='spinner-border spinner-border-sm me-2'></span>"
        . $esc(__('Premier calcul des alertes en cours, en arrière-plan : l\'écran se met à jour tout seul quand il est fini.', 'printgestion'))
        . "</div></div>";
} elseif (countElementsInTable('glpi_plugin_printgestion_alertview') === 0) {
    echo PluginPrintgestionUi::emptyState(
        __('Aucune alerte pour l\'instant. Les alertes viennent des relevés SNMP des sondes : déployer une sonde chez le client, raccorder ses imprimantes depuis la fiche de l\'entité (onglet Déploiement Agent), puis suivre la remontée. Les premières alertes apparaissent au passage de la tâche horaire, ou avec « Recalculer maintenant ».', 'printgestion'),
        [
            __('Installeur GLPI Agent', 'printgestion')      => PLUGIN_PRINTGESTION_WEBDIR . '/front/agentdeploy.php',
            __('Imprimantes collectées', 'printgestion')     => PLUGIN_PRINTGESTION_WEBDIR . '/front/collect.php',
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
    . $esc(__('Clic droit sur une ligne, ou cases cochées puis « Actions » : Commander (droit de validation), Ne plus alerter pendant…, Réactiver les alertes, fiche imprimante, cartouche, expédition en cours. Les motifs de verrou et de référence non résolue figurent dans les colonnes du même nom.', 'printgestion'))
    . "</p>";
echo "</div>"; // container-fluid
// Menu clic droit par-dessus le tableau natif, et les fenêtres qu'il peut appeler (expédition en cours).
PluginPrintgestionDashboardactions::renderSharedAssets('alerts');
PluginPrintgestionContextmenu::render(PluginPrintgestionAlertview::class);

// Recalcul en arrière-plan : l'écran interroge son état toutes les 15 s et se recharge quand il est fini — sauf
// fenêtre ouverte (une commande en cours de saisie ne se perd pas : rechargement à sa fermeture).
if ($pending !== null) {
    echo "<script>(function () {
  var url = " . json_encode(PLUGIN_PRINTGESTION_WEBDIR . '/ajax/alerts_status.php', JSON_UNESCAPED_SLASHES) . ";
  // Relais : sans cron système, la tâche minute ne passe pas. Le calcul est lancé dans une requête à part, sans
  // attendre sa réponse (verrou côté serveur : jamais deux calculs, tâche ou relais).
  var csrf = document.querySelector('meta[property=\"glpi:csrf_token\"]');
  fetch(" . json_encode(PLUGIN_PRINTGESTION_WEBDIR . '/ajax/alerts_rebuild.php', JSON_UNESCAPED_SLASHES) . ", {
    method: 'POST', credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrf ? csrf.content : '' }
  }).catch(function () { /* la tâche minute reste le chemin normal */ });
  var reloadWhenFree = function () {
    if (document.querySelector('.modal.show')) {
      document.addEventListener('hidden.bs.modal', function () { window.location.reload(); }, { once: true });
      return;
    }
    window.location.reload();
  };
  var timer = setInterval(function () {
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (data && data.ok === true && data.pending === null) { clearInterval(timer); reloadWhenFree(); }
      })
      .catch(function () { /* état indisponible : nouvel essai au tour suivant */ });
  }, 15000);
})();</script>";
}
Html::footer();
