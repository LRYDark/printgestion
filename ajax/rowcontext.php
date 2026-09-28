<?php
/**
 * Contexte des lignes d'un tableau natif, pour le menu clic droit (lecture seule, JSON).
 *
 * GET attendu : itemtype (type pris en charge par PluginPrintgestionContextmenu), ids (entiers séparés par des
 * virgules, 200 au plus).
 *
 * Retourne : { ok: true, rows: { id: { … } } }. Seules les lignes du périmètre de l'utilisateur sont renvoyées :
 * une ligne d'un autre client est simplement absente, comme si elle n'existait pas.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$itemtype = (string) ($_GET['itemtype'] ?? '');
if (!PluginPrintgestionContextmenu::isSupported($itemtype)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}
// Même droit que l'écran qui porte le tableau (module actif compris).
if (!PluginPrintgestionContextmenu::canRead($itemtype)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))), static fn(int $id) => $id > 0);
$ids = array_slice(array_values(array_unique($ids)), 0, PluginPrintgestionContextmenu::MAX_ROWS);

// Noms d'imprimantes issus du SNMP : données, jamais interprétées (le script du menu ne les écrit pas en HTML).
echo json_encode(
    ['ok' => true, 'rows' => PluginPrintgestionContextmenu::getContext($itemtype, $ids)],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
