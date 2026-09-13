<?php
/**
 * Export Gesconso des demandes d'envoi VALIDÉES (droit de validation, en modification).
 *
 *   - POST send     : un fichier pour la sélection, envoyé aux Achats ; demandes « exportées »,
 *                     expéditions créées, fichier archivé (Demande::exportDemandes()) ;
 *   - POST download : même fichier, téléchargé pour un test d'import dans Gesconso, SANS mail
 *                     et sans changement de statut (archivé, noté dans l'historique).
 * Une demande avec une ligne en défaut (code client, adresse, référence…) n'est pas
 * sélectionnable ; le serveur refuse de toute façon l'export entier.
 */
include('../../../inc/includes.php');

global $DB; // fichier front chargé hors portée globale (LegacyFileLoadController)

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    Html::displayNotFoundError();
}
if (!Session::haveRight('plugin_printgestion_validation', UPDATE)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$page = PLUGIN_PRINTGESTION_WEBDIR . '/front/demande.export.php';

// Messages en texte brut (noms d'imprimantes, codes Sage) : échappés.
$flash = static function (array $messages, int $type): void {
    if (!empty($messages)) {
        Session::addMessageAfterRedirect(implode('<br>', array_map(
            static fn($message) => htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8'),
            array_slice($messages, 0, 60)
        )), false, $type);
    }
};

if (isset($_POST['send']) || isset($_POST['download'])) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    $ids  = array_values(array_filter(array_map('intval', (array) ($_POST['demandes'] ?? [])), static fn(int $id) => $id > 0));
    $send = isset($_POST['send']);
    $back = $page . '?' . http_build_query(['id' => $ids]);

    if (empty($ids)) {
        $flash([__('Aucune demande sélectionnée.', 'printgestion')], ERROR);
        Html::redirect($page);
    }

    $result = PluginPrintgestionDemande::exportDemandes($ids, $send);
    if (!$result['ok']) {
        $flash(array_merge(
            [$send ? __('Export non réalisé, rien n\'a été enregistré :', 'printgestion') : __('Fichier de test non généré :', 'printgestion')],
            $result['errors']
        ), ERROR);
        $flash($result['warnings'], WARNING);
        Html::redirect($back);
    }
    $flash($result['warnings'], WARNING);

    if (!$send) {
        $document = new Document();
        if ($document->getFromDB((int) $result['documents_id'])) {
            $flash([sprintf(
                __('Fichier de test téléchargé (%d ligne(s)) et archivé : non transmis aux Achats, demandes toujours validées.', 'printgestion'),
                $result['lines']
            )], INFO);
            return $document->getAsResponse();
        }
        $flash([__('Fichier archivé mais introuvable pour le téléchargement : ouvrez-le depuis l\'onglet Documents de la demande.', 'printgestion')], ERROR);
        Html::redirect($back);
    }

    $flash([sprintf(
        __('Export envoyé aux Achats : %1$d demande(s), %2$d ligne(s), fichier archivé sur les demandes.', 'printgestion'),
        count($ids),
        $result['lines']
    )], INFO);
    Html::redirect($page);
}

Html::header(
    __('Export Gesconso', 'printgestion'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'tn_dem'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('tn_dem');

$esc       = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$preselect = array_map('intval', (array) ($_GET['id'] ?? []));
$table     = PluginPrintgestionDemande::getTable();

$rows = [];
foreach ($DB->request([
    'SELECT' => ['id'],
    'FROM'   => $table,
    'WHERE'  => array_merge(
        ['statut' => PluginPrintgestionDemande::STATUS_VALIDATED],
        getEntitiesRestrictCriteria($table, '', '', false)
    ),
    'ORDER'  => ['date_validate ASC', 'id ASC'],
]) as $record) {
    $demande = new PluginPrintgestionDemande();
    if ($demande->getFromDB((int) $record['id']) && $demande->can((int) $record['id'], UPDATE)) {
        $rows[] = ['demande' => $demande, 'export' => $demande->prepareExport()];
    }
}

echo "<div class='card'><div class='card-header'><h3 class='card-title mb-0'>"
    . $esc(__('Demandes validées à exporter', 'printgestion')) . "</h3></div><div class='card-body'>";
echo "<p class='text-muted small'>"
    . $esc(__('Un seul fichier Gesconso pour la sélection, une ligne par cartouche. « Envoyer aux Achats » transmet le fichier par mail, passe les demandes à « exportée » et crée les expéditions. « Télécharger (test) » produit le même fichier sans mail ni changement de statut, pour un test d\'import dans Gesconso. Chaque fichier est archivé en document sur les demandes.', 'printgestion'))
    . "</p>";

if (empty($rows)) {
    echo "<div class='text-muted'>" . $esc(__('Aucune demande validée en attente d\'export.', 'printgestion')) . "</div>";
} else {
    $confirm = json_encode(
        __('Envoyer le fichier aux Achats ? Les demandes cochées passeront à « exportée ».', 'printgestion'),
        JSON_UNESCAPED_UNICODE
    );
    echo "<form method='post' action='" . $esc($page) . "'>";
    echo "<div class='table-responsive'><table class='table table-sm table-vcenter'><thead><tr>"
        . "<th></th><th>" . $esc(__('Demande', 'printgestion')) . "</th>"
        . "<th>" . $esc(__('Validée', 'printgestion')) . "</th>"
        . "<th class='text-end'>" . $esc(__('Lignes', 'printgestion')) . "</th>"
        . "<th>" . $esc(__('Contrôles avant export', 'printgestion')) . "</th></tr></thead><tbody>";

    foreach ($rows as $row) {
        $demande  = $row['demande'];
        $gesconso = $row['export']['gesconso'];
        $id       = (int) $demande->getID();
        $blocked  = !empty($gesconso['errors']) || empty($row['export']['lines']);
        $checked  = !$blocked && (empty($preselect) || in_array($id, $preselect, true));

        if ($blocked) {
            $controls = "<div class='text-danger small'>";
            foreach (array_merge(...array_values($gesconso['errors'] ?: [[__('Aucune ligne validée.', 'printgestion')]])) as $message) {
                $controls .= "<div><i class='ti ti-ban me-1'></i>" . $esc($message) . "</div>";
            }
            $controls .= "</div>";
        } else {
            $controls = "<span class='text-success small'><i class='ti ti-check me-1'></i>" . $esc(__('Exportable', 'printgestion')) . "</span>";
            foreach ($gesconso['warnings'] as $message) {
                $controls .= "<div class='text-warning small'><i class='ti ti-alert-triangle me-1'></i>" . $esc($message) . "</div>";
            }
        }

        echo "<tr>"
            . "<td><input type='checkbox' class='form-check-input' name='demandes[]' value='{$id}'"
            . ($checked ? ' checked' : '') . ($blocked ? ' disabled' : '') . "></td>"
            . "<td><a href='" . $esc(PluginPrintgestionDemande::getFormURLWithID($id)) . "'>#{$id} "
            . $esc($demande->fields['name']) . "</a></td>"
            . "<td>" . $esc(Html::convDateTime((string) $demande->fields['date_validate'])) . "</td>"
            . "<td class='text-end'>" . count($row['export']['lines']) . "</td>"
            . "<td>{$controls}</td>"
            . "</tr>";
    }
    echo "</tbody></table></div>";

    echo "<div class='d-flex flex-wrap gap-2 mt-3'>";
    echo "<button type='submit' name='send' value='1' class='btn btn-success'"
        . " onclick=\"return window.confirm(" . $esc($confirm) . ");\">"
        . "<i class='ti ti-send me-1'></i>" . $esc(__('Envoyer aux Achats', 'printgestion')) . "</button>";
    echo "<button type='submit' name='download' value='1' class='btn btn-outline-primary'>"
        . "<i class='ti ti-download me-1'></i>" . $esc(__('Télécharger le fichier (test, sans envoi)', 'printgestion')) . "</button>";
    echo "</div>";
    Html::closeForm();
}

echo "</div></div>";
echo "</div>"; // container-fluid

Html::footer();
