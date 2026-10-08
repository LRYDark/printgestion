<?php
/**
 * Demandes d'envoi — file des demandes (moteur de recherche natif).
 * Par défaut : les demandes proposées, à valider. Visible avec le droit de validation ou
 * la lecture des alertes toner ; seul le droit de validation permet d'agir.
 */
include('../../../inc/includes.php');

global $DB; // fichier front chargé hors portée globale (LegacyFileLoadController)

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
if (!PluginPrintgestionDemande::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::header(
    PluginPrintgestionDemande::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'tn_dem'
);

echo "<div class='container-fluid mt-3'>";

// Proposition automatique désactivée : la file ne se remplit pas — le dire, dans la
// bulle d'information de la barre d'onglets (à droite) plutôt qu'en bandeau.
$propose_info = '';
$propose_task = new CronTask();
if ($propose_task->getFromDBbyName(PluginPrintgestionReminder::class, 'PrintgestionProposeDemandes')
    && (int) $propose_task->fields['state'] === CronTask::STATE_DISABLE) {
    $propose_info = __('La tâche automatique « PrintgestionProposeDemandes » est désactivée : aucune demande n\'est proposée. Elle s\'active dans Configuration → Actions automatiques. Une ligne proposée ou validée bloque la commande de sa cartouche depuis l\'écran des alertes jusqu\'à son export ou son annulation.', 'printgestion');
}

PluginPrintgestionMenu::showTabBar('tn_dem', false, $propose_info);
// Commandes enregistrées mais non transmises aux Achats : en tête, jusqu'au renvoi.
PluginPrintgestionPurchaseorder::showNotSentCard();

// ── Compteurs, restreints aux entités de l'utilisateur ──
$table = PluginPrintgestionDemande::getTable();
$crow  = $DB->request([
    'SELECT' => [
        new QueryExpression("COALESCE(SUM(`statut` = 'proposed'), 0) AS `proposed`"),
        new QueryExpression("COALESCE(SUM(`statut` = 'validated'), 0) AS `validated`"),
    ],
    'FROM'   => $table,
    'WHERE'  => getEntitiesRestrictCriteria($table, '', '', false),
])->current();

$status_url = static function (string $statut): string {
    return PluginPrintgestionDemande::getSearchURL() . '?' . http_build_query([
        'criteria' => [['field' => 3, 'searchtype' => 'equals', 'value' => $statut]],
        'reset'    => 'reset',
    ]);
};

// Export Gesconso : bouton de l'écran, à droite de la barre de stats.
$export_button = Session::haveRight('plugin_printgestion_validation', UPDATE)
    ? "<a class='btn btn-sm btn-primary' href='"
        . htmlspecialchars(PLUGIN_PRINTGESTION_WEBDIR . '/front/demande.export.php', ENT_QUOTES, 'UTF-8') . "'>"
        . "<i class='ti ti-file-export me-1'></i>"
        . htmlspecialchars(__('Exporter les demandes validées (Gesconso)', 'printgestion'), ENT_QUOTES, 'UTF-8') . "</a>"
    : '';

PluginPrintgestionUi::statsBar([
    ['count' => (int) ($crow['proposed'] ?? 0), 'label' => __('À valider', 'printgestion'),
     'tooltip' => __('Demandes proposées par la tâche automatique, en attente de validation', 'printgestion'),
     'icon' => 'ti ti-clipboard-list', 'color' => 'orange', 'url' => $status_url(PluginPrintgestionDemande::STATUS_PROPOSED)],
    ['count' => (int) ($crow['validated'] ?? 0), 'label' => __('Validées, non exportées', 'printgestion'),
     'tooltip' => __('Validées : bloquent toute nouvelle commande de leurs cartouches jusqu\'à l\'export ou l\'annulation', 'printgestion'),
     'icon' => 'ti ti-clipboard-check', 'color' => 'blue', 'url' => $status_url(PluginPrintgestionDemande::STATUS_VALIDATED)],
], 'printgestionDemandeStatsBar', $export_button);

// ── Tableau NATIF (restriction d'entité native : colonne entities_id) ──
$itemtype = PluginPrintgestionDemande::class;
$params   = Search::manageParams($itemtype, $_GET);
$params['target'] = PluginPrintgestionDemande::getSearchURL();
$forced   = [1, 80, 7, 3, 4, 13, 121]; // Nom, Client, Site, Statut, Mode, Lignes, Proposée le

echo "<div class='search_page row'>";
echo "<div class='col search-container' data-glpi-search-container>";
Search::showList($itemtype, $params, $forced);
echo "</div></div>";

echo "</div>"; // container-fluid
// Menu clic droit par-dessus le tableau natif : ouvrir, valider, annuler.
PluginPrintgestionContextmenu::render(PluginPrintgestionDemande::class);
Html::footer();
