<?php
/**
 * Relevé manuel d'une imprimante (POST, JSON) : niveaux et compteurs saisis d'après le client, écrits là où
 * l'inventaire les écrit (Printer_CartridgeInfo, PrinterLog, Printer), trace du plugin, alertes recalculées.
 *
 * POST attendu : printers_id, levels[<propriété>] (0-100 ou vide), counters[<compteur du journal natif>] (entier ou
 * vide), comment. Le relevé est daté de maintenant. Droit : Alertes toner en modification, imprimante du périmètre.
 * Retourne : { ok: true, warnings: [] } ou { ok: false, errors: [] }.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Écriture : POST uniquement — le contrôle CSRF du cœur ne porte que sur les requêtes à corps.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
// Validation CSRF faite par CheckCsrfListener (kernel Symfony) avant ce fichier.

$printers_id = (int) ($_POST['printers_id'] ?? 0);
$printer     = new Printer();
if ($printers_id <= 0 || !$printer->getFromDB($printers_id) || !PluginPrintgestionManualreading::canRecord($printer)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$result = PluginPrintgestionManualreading::record($printer, $_POST);
if ($result['ok']) {
    // Message affiché par GLPI au rechargement de la page.
    Session::addMessageAfterRedirect(
        htmlspecialchars(implode(' ', array_merge([__('Relevé manuel enregistré.', 'printgestion')], $result['warnings'])), ENT_QUOTES, 'UTF-8'),
        false,
        empty($result['warnings']) ? INFO : WARNING
    );
}
echo json_encode(
    $result['ok'] ? ['ok' => true, 'warnings' => $result['warnings']] : ['ok' => false, 'errors' => $result['errors']],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
