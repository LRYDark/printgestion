<?php
/**
 * Ordre des cartes de l'accueil, propre à l'utilisateur connecté : enregistré après un glisser-déposer (action
 * « save », clés des cartes dans leur nouvel ordre) ou remis à l'ordre par défaut (action « reset »).
 *
 * POST seulement, jeton CSRF en en-tête (vérifié par GLPI). N'écrit que la ligne de l'utilisateur connecté.
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=utf-8');

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
if (!PluginPrintgestionMenu::canView()) {
    PluginPrintgestionSecurity::denyJson();
}

$users_id = (int) Session::getLoginUserID();
$action   = (string) ($_POST['action'] ?? '');
$keys     = $_POST['keys'] ?? [];

try {
    if ($action === 'save') {
        $ok = PluginPrintgestionHubpref::saveOrder($users_id, is_array($keys) ? $keys : []);
    } elseif ($action === 'reset') {
        $ok = PluginPrintgestionHubpref::reset($users_id);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }
} catch (Throwable $e) {
    PluginPrintgestionLogger::error('accueil', sprintf('Ordre des cartes de l\'utilisateur %d non enregistré.', $users_id), $e);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Not saved']);
    exit;
}

echo json_encode(['ok' => $ok]);
