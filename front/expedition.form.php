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

// Tableau d'où l'on vient : on y revient après la confirmation, comme par « Annuler ».
// Après une saisie refusée, le formulaire est rouvert avec ?return= (cf. update_expedition.php).
$return_url = PluginPrintgestionExpedition::getReturnURL(
    (string) ($_GET['return'] ?? ($_SERVER['HTTP_REFERER'] ?? ''))
);

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

// Récapitulatif clé/valeur, hors du formulaire : gabarit natif sans ligne d'en-tête. Le libellé garde le gras de
// l'ancienne cellule d'en-tête (th : 700 ; strong seul ne donne que 600 avec Tabler, fw-bolder rend le 700) ; les
// valeurs sont du texte brut, échappé par le gabarit. Aucun lien : la ligne ne réagit pas au clic. mb-3 : l'espace
// d'avant entre le tableau et le formulaire.
$esc     = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$label   = static fn(string $text) => "<strong class='fw-bolder'>" . $esc($text) . '</strong>';
$entries = [
    ['label' => $label(__('Imprimante', 'printgestion')),          'value' => (string)$printer->fields['name']],
    ['label' => $label(__('Client', 'printgestion')),              'value' => (string)$entity->fields['completename']],
    ['label' => $label(__('Toner', 'printgestion')),               'value' => (string)$exp['toner_property']],
    ['label' => $label(__('Niveau à l\'alerte', 'printgestion')),  'value' => (int)$exp['level_at_alert'] . " %"],
    ['label' => $label(__('Date alerte', 'printgestion')),         'value' => (string)Html::convDateTime((string)$exp['date_alert'])],
];
echo "<div class='mb-3'>" . PluginPrintgestionUi::datatable(['label' => '', 'value' => ''], $entries, ['label' => 'raw_html'], null, false, ['no_header' => true]) . "</div>";

// Envoi déjà parti (ou plus loin) : rien à saisir. Le dire, au lieu d'un formulaire vide
// dont la confirmation serait refusée (« seul un envoi en attente… »).
if ((string)$exp['statut'] !== PluginPrintgestionExpedition::STATUS_PENDING) {
    $facts = [sprintf(__('Statut : %s', 'printgestion'), PluginPrintgestionExpedition::getStatusLabel((string)$exp['statut']))];
    if (trim((string)$exp['transport_carrier']) !== '' || trim((string)$exp['transport_number']) !== '') {
        $facts[] = sprintf(
            __('Transporteur : %1$s, n° de suivi : %2$s', 'printgestion'),
            strtoupper(trim((string)$exp['transport_carrier'])) ?: '—',
            trim((string)$exp['transport_number']) ?: '—'
        );
    }
    if (!empty($exp['date_shipped'])) {
        $facts[] = sprintf(__('Expédiée le %s', 'printgestion'), Html::convDateTime((string)$exp['date_shipped']));
    }
    echo "<div class='alert alert-info'><div class='fw-bold mb-1'>"
        . $esc(__('Cette expédition n\'est plus en attente : rien à confirmer.', 'printgestion')) . "</div>"
        . implode('<br>', array_map($esc, $facts)) . "</div>";
    echo "<div class='text-center'><a href='" . $esc($return_url) . "' class='btn btn-secondary'>"
        . "<i class='ti ti-arrow-left me-1'></i>" . $esc(__('Retour au tableau', 'printgestion')) . "</a></div>";
    echo "</div></div>";
    echo "</div>";
    Html::footer();
    return;
}

echo "<form method='post' action='" . PLUGIN_PRINTGESTION_WEBDIR . "/ajax/update_expedition.php'>";
// CSRF géré par Html::closeForm() qui injecte _glpi_csrf_token auto.
echo Html::hidden('id',     ['value' => $id]);
echo Html::hidden('action', ['value' => 'ship']);
echo Html::hidden('_return', ['value' => $return_url]);

echo "<div class='row g-3'>";

echo "<div class='col-md-6'><label class='form-label'>" . __('Transporteur', 'printgestion') . "</label>";
Dropdown::showFromArray('carrier', PluginPrintgestionExpedition::getCarrierLabels(), ['value' => '', 'display_emptychoice' => true]);  // aucun transporteur présélectionné : un choix faux s'enregistrerait tout seul
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
echo "<a href='" . htmlspecialchars($return_url, ENT_QUOTES, 'UTF-8') . "' class='btn btn-secondary'>"
    . _sx('button', 'Cancel') . "</a>";
echo "</div>";

echo "</div>";
Html::closeForm();

echo "</div></div>";
echo "</div>";

Html::footer();
