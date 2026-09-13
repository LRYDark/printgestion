<?php
/**
 * Commande de cartouches — mono OU multi-imprimantes.
 *
 * POST attendu :
 *   - items_json : JSON array de [{printers_id, property, level, days}, ...]
 *                  (les cartouches peuvent appartenir à plusieurs imprimantes)
 *
 * Crée 1 expédition par cartouche (group_id commun), génère 1 fichier Excel
 * (1 cartouche/ligne, format Gesconso + colonne Stock GLPI) et envoie 1 mail
 * aux achats (demandeur en copie) avec l'Excel joint. Corps de mail générique.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

// Déclencher une commande relève du droit de validation (Q6), distinct de la mise en
// pause d'une alerte (dashboard) et du suivi des expéditions (expedition).
if (!Session::haveRight('plugin_printgestion_validation', UPDATE)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// CSRF déjà validé par CheckCsrfListener (requête AJAX avec X-Glpi-Csrf-Token).
header('Content-Type: application/json; charset=utf-8');

$items_json = (string)($_POST['items_json'] ?? '');
if ($items_json === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

$items = json_decode($items_json, true);
if (!is_array($items) || empty($items)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid items']);
    exit;
}

// Normalisation : chaque item doit avoir printers_id > 0 et une property non vide.
$clean = [];
foreach ($items as $it) {
    if (!is_array($it)) {
        continue;
    }
    $printers_id = (int)($it['printers_id'] ?? 0);
    $property    = trim((string)($it['property'] ?? ''));
    if ($printers_id <= 0 || $property === '') {
        continue;
    }
    $clean[] = [
        'printers_id' => $printers_id,
        'property'    => $property,
        'level'       => (int)($it['level'] ?? 0),
        'days'        => isset($it['days']) && $it['days'] !== null && $it['days'] !== '' ? (int)$it['days'] : null,
    ];
}

if (empty($clean)) {
    echo json_encode(['ok' => false, 'error' => 'No valid items']);
    exit;
}

// Cloisonnement client : une seule imprimante hors du périmètre de l'utilisateur
// fait rejeter la commande entière (jamais de retrait silencieux d'une ligne).
foreach (array_unique(array_column($clean, 'printers_id')) as $pid) {
    if (!PluginPrintgestionSecurity::canAccessPrinter((int)$pid)) {
        PluginPrintgestionSecurity::denyJson();
    }
}

// Cases cochées côté UI : Planif (logistique) et Courtoisie client.
$send_planif   = (string)($_POST['send_planif'] ?? '0') === '1';
$send_courtesy = (string)($_POST['send_courtesy'] ?? '0') === '1';

$result = PluginPrintgestionExpedition::createPurchaseOrder($clean, $send_planif, $send_courtesy);

echo json_encode([
    'ok'       => (bool)$result['ok'],
    'created'  => (int)$result['created'],
    'skipped'  => (int)$result['skipped'],
    'mail'     => (bool)$result['mail'],
    'rows'     => (int)$result['rows'],
    // Cause de l'échec (commande NON passée) et avertissements non bloquants.
    'error'    => (string)($result['error'] ?? ''),
    'warnings' => array_values((array)($result['warnings'] ?? [])),
]);
