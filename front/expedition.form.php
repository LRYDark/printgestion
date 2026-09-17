<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_expedition', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

global $DB;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    Html::back();
}

// Expédition inexistante OU hors du périmètre d'entités : même réponse (pas de
// divulgation de l'existence d'une expédition d'un autre client).
$exp = PluginPrintgestionSecurity::getAccessibleExpedition($id);
if ($exp === null) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Html::header(
    __('Print Gestion — Expédition', 'printgestion'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu'
);

$printer = new Printer();
$printer->getFromDB((int)$exp['printers_id']);

$entity = new Entity();
$entity->getFromDB((int)$printer->fields['entities_id']);

echo "<div class='container mt-3'>";
echo "<h1><i class='fa-solid fa-truck me-2'></i>"
    . __('Marquer une expédition comme expédiée', 'printgestion') . "</h1>";

echo "<div class='card'><div class='card-body'>";

echo "<table class='tab_cadre_fixehov mb-3'>";
echo "<tr><th>" . __('Imprimante', 'printgestion') . "</th><td>"
    . htmlspecialchars((string)$printer->fields['name'], ENT_QUOTES, 'UTF-8') . "</td></tr>";
echo "<tr><th>" . __('Client', 'printgestion') . "</th><td>"
    . htmlspecialchars((string)$entity->fields['completename'], ENT_QUOTES, 'UTF-8') . "</td></tr>";
echo "<tr><th>" . __('Toner', 'printgestion') . "</th><td>"
    . htmlspecialchars((string)$exp['toner_property'], ENT_QUOTES, 'UTF-8') . "</td></tr>";
echo "<tr><th>" . __('Niveau à l\'alerte', 'printgestion') . "</th><td>"
    . (int)$exp['level_at_alert'] . " %</td></tr>";
echo "<tr><th>" . __('Date alerte', 'printgestion') . "</th><td>"
    . Html::convDateTime((string)$exp['date_alert']) . "</td></tr>";
echo "</table>";

echo "<form method='post' action='" . PLUGIN_PRINTGESTION_WEBDIR . "/ajax/update_expedition.php'>";
// CSRF géré par Html::closeForm() qui injecte _glpi_csrf_token auto.
echo Html::hidden('id',     ['value' => $id]);
echo Html::hidden('action', ['value' => 'ship']);

echo "<div class='row g-3'>";

echo "<div class='col-md-6'><label class='form-label'>" . __('Transporteur', 'printgestion') . "</label>";
Dropdown::showFromArray('carrier', [
    'ups'        => 'UPS',
    'gls'        => 'GLS',
    'chronopost' => 'Chronopost',
    'other'      => __('Autre', 'printgestion'),
], ['value' => 'ups']);
echo "</div>";

echo "<div class='col-md-6'><label class='form-label'>" . __('N° de suivi', 'printgestion') . "</label>";
echo "<input type='text' class='form-control' name='tracking' required></div>";

// Lien BL : déduit de l'état du plugin Gestion.
if (PluginPrintgestionTracking::isGestionLinkActive()) {
    echo "<div class='col-md-12'><label class='form-label'>"
        . __('BL plugin Gestion (optionnel)', 'printgestion') . "</label>";

    $bls = [];
    foreach ($DB->request([
        'SELECT' => ['id', 'bl'],
        'FROM'   => 'glpi_plugin_gestion_surveys',
        'WHERE'  => [
            'signed'      => 0,
            'entities_id' => (int)$printer->fields['entities_id'],
        ],
        'ORDER'  => ['id DESC'],
        'LIMIT'  => 100,
    ]) as $bl) {
        $bls[(int)$bl['id']] = (string)$bl['bl'];
    }

    if (!empty($bls)) {
        Dropdown::showFromArray('bl_surveys_id', $bls, [
            'display_emptychoice' => true,
            'emptylabel'          => '-----',
        ]);
    } else {
        echo "<p class='text-muted'>" . __('Aucun BL disponible pour ce client', 'printgestion') . "</p>";
        echo Html::hidden('bl_surveys_id', ['value' => 0]);
    }
    echo "</div>";
}

echo "<div class='col-12 text-center'>";
echo "<button type='submit' class='btn btn-success me-2'>"
    . "<i class='fa-solid fa-check me-1'></i>" . __('Confirmer expédition', 'printgestion') . "</button>";
echo "<a href='" . PLUGIN_PRINTGESTION_WEBDIR . "/front/dashboard_alerts.php' class='btn btn-secondary'>"
    . _sx('button', 'Cancel') . "</a>";
echo "</div>";

echo "</div>";
Html::closeForm();

echo "</div></div>";
echo "</div>";

Html::footer();
