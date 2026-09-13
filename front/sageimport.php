<?php
/**
 * Référentiel Sage — import par dépôt de fichier (droit « Référentiel Sage » en modification).
 *
 *   1. POST analyze : le fichier est lu et contrôlé, l'analyse est gardée en session ;
 *   2. GET ?preview : prévisualisation et rapport d'écarts, rien n'est encore écrit ;
 *   3. POST apply   : validation, écriture en transaction ; POST abandon : oubli de l'analyse.
 * Aucune liaison directe avec Sage.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('sage')) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
Session::checkRight('plugin_printgestion_sage', UPDATE);

$page = PluginPrintgestionSageimport::getPageURL();
$key  = PluginPrintgestionSageimport::SESSION_KEY;

// Messages en texte brut (contenu du fichier déposé) : échappés.
$flash = static function (array $messages, int $type): void {
    if (!empty($messages)) {
        Session::addMessageAfterRedirect(implode('<br>', array_map(
            static fn($message) => htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8'),
            array_slice($messages, 0, 50)
        )), false, $type);
    }
};

if (isset($_POST['analyze'])) {
    // Jeton CSRF déjà validé par CheckCsrfListener avant ce fichier.
    $type = (string) ($_POST['type'] ?? '');
    $file = $_FILES['file'] ?? null;
    if (!isset(PluginPrintgestionSageimport::getTypeLabels()[$type])) {
        $flash([__('Référentiel inconnu.', 'printgestion')], ERROR);
        Html::redirect($page);
    }
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string) $file['tmp_name'])) {
        $flash([__('Aucun fichier reçu, ou fichier plus volumineux que la limite du serveur.', 'printgestion')], ERROR);
        Html::redirect($page);
    }

    $filename = mb_substr(basename((string) $file['name']), 0, 255);
    $parsed   = PluginPrintgestionSageimport::parseFile((string) $file['tmp_name'], $filename, $type);
    $token    = bin2hex(random_bytes(16));

    $_SESSION[$key] = [
        'token'    => $token,
        'type'     => $type,
        'filename' => $filename,
        'rows'     => $parsed['rows'],
        'errors'   => $parsed['errors'],
        'warnings' => $parsed['warnings'],
    ];
    Html::redirect($page . '?preview=' . $token);
}

if (isset($_POST['apply']) || isset($_POST['abandon'])) {
    $pending = $_SESSION[$key] ?? null;
    if (!is_array($pending) || !hash_equals((string) $pending['token'], (string) ($_POST['token'] ?? ''))) {
        $flash([__('Analyse expirée ou déjà traitée : redéposez le fichier.', 'printgestion')], ERROR);
        Html::redirect($page);
    }

    if (isset($_POST['abandon'])) {
        unset($_SESSION[$key]);
        $flash([__('Import abandonné : rien n\'a été enregistré.', 'printgestion')], INFO);
        Html::redirect($page);
    }

    if (!empty($pending['errors'])) {
        $flash([__('Le fichier contient des erreurs : import impossible.', 'printgestion')], ERROR);
        Html::redirect($page . '?preview=' . $pending['token']);
    }

    // Correspondances entité ↔ client cochées ou choisies (clés : empreinte du code).
    $links = [];
    $codes = (array) ($_POST['link_code'] ?? []);
    foreach ((array) ($_POST['link'] ?? []) as $hash => $entities_id) {
        if (isset($codes[$hash]) && (int) $entities_id > 0) {
            $links[mb_strtoupper((string) $codes[$hash])] = (int) $entities_id;
        }
    }

    $result = PluginPrintgestionSageimport::apply(
        (string) $pending['type'],
        (array) $pending['rows'],
        $pending['type'] === PluginPrintgestionSageimport::TYPE_CLIENTS ? $links : [],
        (string) $pending['filename']
    );
    if (!$result['ok']) {
        $flash(array_merge([__('Import non réalisé :', 'printgestion')], $result['errors']), ERROR);
        Html::redirect($page . '?preview=' . $pending['token']);
    }

    unset($_SESSION[$key]);
    $counts = $result['counts'];
    $flash([sprintf(
        __('Import réalisé : %1$d nouvelle(s), %2$d modifiée(s), %3$d inchangée(s), %4$d marquée(s) absente(s), %5$d entité(s) liée(s).', 'printgestion'),
        $counts['created'],
        $counts['updated'],
        $counts['unchanged'],
        $counts['absent'],
        $counts['linked']
    )], INFO);
    Html::redirect($page);
}

Html::header(
    PluginPrintgestionSage::getTypeName(),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginPrintgestionMenu',
    'sg_import'
);

echo "<div class='container-fluid mt-3'>";
PluginPrintgestionMenu::showTabBar('sg_import');

$pending = $_SESSION[$key] ?? null;
if (isset($_GET['preview']) && is_array($pending) && hash_equals((string) $pending['token'], (string) $_GET['preview'])) {
    PluginPrintgestionSageimport::showPreview($pending);
} else {
    PluginPrintgestionSageimport::showUploadForm();
    PluginPrintgestionSageimport::showHistory();
}

echo "</div>";

Html::footer();
