<?php
include('../../../inc/includes.php');

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

$can_update = Session::haveRight('plugin_printgestion_expedition', UPDATE);

echo "<div class='container-fluid mt-3'>";

// Bouton « Rafraîchir » (recalcul complet des alertes) : droit de modification seulement.
PluginPrintgestionMenu::showTabBar('tn_exp', Session::haveRight('plugin_printgestion_dashboard', UPDATE));

// ── Alertes prioritaires (wrong_printer + late_shipment) — côté PHP (non paginé) ──
$priority_alerts = PluginPrintgestionAlert::listPriorityAlerts($entities_id);

if (!empty($priority_alerts)) {
    echo "<div class='card mb-3 border-danger'>";
    echo "<div class='card-header bg-danger text-white'>";
    echo "<h3 class='card-title mb-0'>";
    echo "<i class='fa-solid fa-circle-exclamation me-2'></i>"
        . __('Alertes prioritaires', 'printgestion')
        . " <span class='badge bg-light text-danger ms-2'>" . count($priority_alerts) . "</span>";
    echo "</h3>";
    echo "</div>";
    echo "<div class='card-body'>";

    foreach ($priority_alerts as $pa) {
        echo "<div class='alert alert-warning mb-2 py-2'>";

        if ($pa['kind'] === 'wrong_printer') {
            $intended_url = Printer::getFormURLWithID($pa['intended_printers_id']);
            $detected_url = Printer::getFormURLWithID($pa['detected_printers_id']);

            echo "<div class='d-flex align-items-start'>";
            echo "<i class='fa-solid fa-triangle-exclamation fa-lg me-3 mt-1 text-danger'></i>";
            echo "<div class='flex-grow-1'>";
            echo "<strong>" . __('Mauvaise imprimante', 'printgestion') . "</strong>";
            if (!empty($pa['entity_name'])) {
                echo " — " . htmlspecialchars($pa['entity_name'], ENT_QUOTES, 'UTF-8');
            }
            echo "<br>";
            // Proposition à confirmer, jamais appliquée seule : l'envoi, les deux machines et
            // les dates, pour vérifier sur place avant de réattribuer.
            $sent_label = !empty($pa['date_delivered'])
                ? sprintf(__('livré le %s', 'printgestion'), Html::convDateTime((string) $pa['date_delivered']))
                : sprintf(__('expédié le %s', 'printgestion'), Html::convDateTime((string) $pa['date_shipped']));
            echo "<span class='text-muted small'>"
                . sprintf(
                    __('Envoi #%1$d « %2$s » (%3$s) prévu pour %4$s ; pose de la même référence détectée sur %5$s le %6$s. À vérifier sur place avant de réattribuer.', 'printgestion'),
                    (int) $pa['expeditions_id'],
                    htmlspecialchars($pa['toner_property'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($sent_label, ENT_QUOTES, 'UTF-8'),
                    "<a href='" . htmlspecialchars($intended_url, ENT_QUOTES, 'UTF-8') . "'><strong>"
                        . htmlspecialchars($pa['intended_name'], ENT_QUOTES, 'UTF-8') . "</strong></a>",
                    "<a href='" . htmlspecialchars($detected_url, ENT_QUOTES, 'UTF-8') . "'><strong>"
                        . htmlspecialchars($pa['detected_name'], ENT_QUOTES, 'UTF-8') . "</strong></a>",
                    htmlspecialchars(Html::convDateTime((string) $pa['date_detected']), ENT_QUOTES, 'UTF-8')
                )
                . "</span>";
            echo "</div>";

            if ($can_update) {
                echo "<div class='ms-3 d-flex gap-1'>";
                // Confirmation portée par un attribut data-* entièrement échappé et
                // lue par un gestionnaire délégué (script en fin de bloc) : aucun
                // texte dynamique (nom d'imprimante issu du SNMP) dans un attribut on*.
                $confirm_msg = sprintf(
                    __('Réattribuer l\'envoi #%1$d de %2$s à %3$s ? Il sera marqué posé sur %3$s.', 'printgestion'),
                    (int) $pa['expeditions_id'],
                    $pa['intended_name'],
                    $pa['detected_name']
                );
                echo "<form method='post' action='" . PLUGIN_PRINTGESTION_WEBDIR . "/ajax/reassign_expedition.php' class='d-inline'"
                    . " data-pg-confirm=\"" . htmlspecialchars($confirm_msg, ENT_QUOTES, 'UTF-8') . "\">";
                echo Html::hidden('expedition_id', ['value' => $pa['expeditions_id']]);
                echo Html::hidden('new_printers_id', ['value' => $pa['detected_printers_id']]);
                echo "<button type='submit' class='btn btn-sm btn-primary'>"
                    . "<i class='fa-solid fa-exchange-alt me-1'></i>"
                    . htmlspecialchars(sprintf(__('Réattribuer à %s', 'printgestion'), $pa['detected_name']), ENT_QUOTES, 'UTF-8')
                    . "</button>";
                Html::closeForm();

                echo "<form method='post' action='" . PLUGIN_PRINTGESTION_WEBDIR . "/ajax/resolve_alert.php' class='d-inline'>";
                echo Html::hidden('alert_id', ['value' => $pa['alert_id']]);
                echo "<button type='submit' class='btn btn-sm btn-outline-secondary'>"
                    . "<i class='fa-solid fa-xmark me-1'></i>" . __('Ignorer', 'printgestion') . "</button>";
                Html::closeForm();
                echo "</div>";
            }
            echo "</div>";
        } else {
            $printer_url = Printer::getFormURLWithID($pa['printers_id']);
            echo "<div class='d-flex align-items-start'>";
            echo "<i class='fa-solid fa-clock fa-lg me-3 mt-1 text-warning'></i>";
            echo "<div class='flex-grow-1'>";
            echo "<strong>" . __('Expédition en retard', 'printgestion') . "</strong>";
            if (!empty($pa['entity_name'])) {
                echo " — " . htmlspecialchars($pa['entity_name'], ENT_QUOTES, 'UTF-8');
            }
            echo "<br>";
            echo "<span class='text-muted small'>"
                . sprintf(
                    __('Cartouche "%s" expédiée vers %s depuis %d jour(s) (pas encore installée)', 'printgestion'),
                    htmlspecialchars($pa['toner_property'], ENT_QUOTES, 'UTF-8'),
                    "<a href='" . htmlspecialchars($printer_url, ENT_QUOTES, 'UTF-8') . "'><strong>"
                        . htmlspecialchars($pa['printer_name'], ENT_QUOTES, 'UTF-8') . "</strong></a>",
                    $pa['days_since']
                );
            if (!empty($pa['tracking'])) {
                echo " — " . strtoupper(htmlspecialchars($pa['carrier'], ENT_QUOTES, 'UTF-8'))
                    . " <code>" . htmlspecialchars($pa['tracking'], ENT_QUOTES, 'UTF-8') . "</code>";
            }
            echo "</span>";
            echo "</div>";
            echo "</div>";
        }

        echo "</div>";
    }

    echo "</div></div>";

    // Confirmation des formulaires portant data-pg-confirm : le message est lu via
    // getAttribute(), jamais interprété comme du code ou du HTML.
    echo "<script>
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form instanceof HTMLFormElement && form.hasAttribute('data-pg-confirm')
        && !window.confirm(form.getAttribute('data-pg-confirm'))) {
        e.preventDefault();
    }
}, true);
</script>";
}

// ── Compteurs par statut (calculés, restreints par l'entité de l'imprimante) ──
$exp_t         = 'glpi_plugin_printgestion_expeditions';
$status_counts = ['pending' => 0, 'shipped' => 0, 'transit' => 0, 'delivered' => 0, 'installed' => 0, 'cancelled' => 0];
$sel = [];
foreach (array_keys($status_counts) as $st) {
    $sel[] = new QueryExpression("SUM(`{$exp_t}`.`statut` = '{$st}') AS `{$st}`");
}
$crow = $DB->request([
    'SELECT'     => $sel,
    'FROM'       => $exp_t,
    'INNER JOIN' => ['glpi_printers' => ['ON' => ['glpi_printers' => 'id', $exp_t => 'printers_id']]],
    'WHERE'      => array_merge(['glpi_printers.is_deleted' => 0], getEntitiesRestrictCriteria('glpi_printers', '', '', true)),
])->current();
foreach (array_keys($status_counts) as $st) {
    $status_counts[$st] = (int) ($crow[$st] ?? 0);
}

PluginPrintgestionUi::statsBar([
    ['count' => (int) $status_counts['pending'],   'label' => __('En attente', 'printgestion'),
     'icon' => 'ti ti-hourglass', 'color' => 'orange'],
    ['count' => (int) $status_counts['shipped'],   'label' => __('Expédiées', 'printgestion'),
     'icon' => 'ti ti-send', 'color' => 'primary'],
    ['count' => (int) $status_counts['transit'],   'label' => __('En transit', 'printgestion'),
     'icon' => 'ti ti-truck', 'color' => 'azure'],
    ['count' => (int) $status_counts['delivered'], 'label' => __('Livrées non posées', 'printgestion'),
     'tooltip' => __('Livrées mais pose non encore détectée ni confirmée : toujours bloquantes', 'printgestion'),
     'icon' => 'ti ti-package', 'color' => 'green'],
    ['count' => (int) $status_counts['installed'], 'label' => __('Posées', 'printgestion'),
     'icon' => 'ti ti-checks', 'color' => 'teal'],
], 'printgestionExpeditionStatsBar');

// ── Tableau NATIF (moteur de recherche GLPI) ──
// Restriction d'entité (via l'imprimante) appliquée par plugin_printgestion_addDefaultWhere().
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

// ── Restauration de l'UI « comme avant » sur le tableau natif ─────────────────
// 1) Carte des données d'expédition (clés = id) pour alimenter le menu clic droit
//    sans requête supplémentaire côté client. Inclut le nom imprimante/client
//    (jointures) que getSpecificValueToDisplay ne peut pas fournir seul.
$exp_map  = [];
$exp_data = $DB->request([
    'SELECT'     => [
        $exp_t . '.id AS id',
        $exp_t . '.printers_id AS printers_id',
        $exp_t . '.toner_property AS property',
        $exp_t . '.statut AS statut',
        $exp_t . '.transport_carrier AS carrier',
        $exp_t . '.transport_number AS tracking',
        'glpi_printers.name AS printer_name',
        'glpi_entities.completename AS entity_name',
    ],
    'FROM'       => $exp_t,
    'INNER JOIN' => ['glpi_printers' => ['ON' => ['glpi_printers' => 'id', $exp_t => 'printers_id']]],
    'LEFT JOIN'  => ['glpi_entities' => ['ON' => ['glpi_entities' => 'id', 'glpi_printers' => 'entities_id']]],
    'WHERE'      => array_merge(['glpi_printers.is_deleted' => 0], getEntitiesRestrictCriteria('glpi_printers', '', '', true)),
]);
foreach ($exp_data as $r) {
    $exp_map[(int) $r['id']] = [
        'printers_id'  => (int) $r['printers_id'],
        'printer_name' => (string) ($r['printer_name'] ?? ''),
        'entity_name'  => (string) ($r['entity_name'] ?? ''),
        'property'     => (string) ($r['property'] ?? ''),
        'statut'       => (string) ($r['statut'] ?? ''),
        'carrier'      => (string) ($r['carrier'] ?? ''),
        'tracking'     => (string) ($r['tracking'] ?? ''),
    ];
}

// 2) Menu clic droit + modales (Modifier expédition, Associer BL) + JS.
PluginPrintgestionDashboardactions::renderSharedAssets('expeditions');

// 3) Pont : recopie les data-pc-* sur chaque ligne native (via le marqueur caché
//    .pg-exp-bridge rendu dans la cellule Statut), puis ré-applique après chaque
//    rechargement AJAX du tableau natif (tri / pagination / recherche).
// Noms d'imprimante (SNMP), clients, n° de suivi : données, jamais écrites dans un script exécuté.
echo PluginPrintgestionUi::jsonData('pg-exp-data', $exp_map, 'PG_EXP_DATA') . "\n";
echo <<<'JS'
<script>
(function() {
  function applyBridge() {
    if (!window.PG_EXP_DATA) return;
    document.querySelectorAll('.pg-exp-bridge').forEach(function(span) {
      var tr = span.closest('tr');
      if (!tr || tr.getAttribute('data-pc-row') === '1') return;
      var id = span.getAttribute('data-expid');
      var d  = window.PG_EXP_DATA[id];
      if (!d) return;
      tr.setAttribute('data-pc-row', '1');
      tr.setAttribute('data-pc-grouped', '0');
      tr.setAttribute('data-pc-has-expedition', '1');
      tr.setAttribute('data-pc-printers-id', d.printers_id || '');
      tr.setAttribute('data-pc-printer-name', d.printer_name || '');
      tr.setAttribute('data-pc-entity-name', d.entity_name || '');
      tr.setAttribute('data-pc-property', d.property || '');
      tr.setAttribute('data-pc-expedition-id', id);
      tr.setAttribute('data-pc-exp-statut', d.statut || '');
      tr.setAttribute('data-pc-exp-carrier', d.carrier || '');
      tr.setAttribute('data-pc-exp-tracking', d.tracking || '');
    });
  }
  function init() {
    applyBridge();
    var cont = document.querySelector('[data-glpi-search-container]');
    if (cont && window.MutationObserver) {
      new MutationObserver(function() { applyBridge(); }).observe(cont, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>
JS;

Html::footer();
