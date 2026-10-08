<?php
/**
 * Relais du recalcul des alertes quand la tâche PrintgestionRebuildAlerts ne passe pas (cron système absent alors
 * que GLPI_SYSTEM_CRON est déclaré : la tâche est en mode CLI et rien ne la lance).
 *
 * Appelé par l'écran des alertes sans attendre la réponse : le calcul tourne dans CETTE requête, à part. La session
 * est libérée tout de suite (sinon chaque page de l'utilisateur attendrait la fin du calcul), le calcul continue si
 * l'onglet se ferme. Verrou de Alertview::rebuild() : jamais deux calculs en même temps, tâche ou relais.
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

// POST (jeton CSRF en en-tête, vérifié par GLPI) et droit de lecture des alertes : la demande a déjà été posée par
// quelqu'un qui pouvait recalculer ; ce relais ne fait que la traiter.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
if (!Session::haveRight('plugin_printgestion_dashboard', READ)) {
    PluginPrintgestionSecurity::denyJson();
}
if (PluginPrintgestionAlertview::getRequestedAt() === null) {
    echo json_encode(['ok' => true, 'done' => null]);
    exit;
}

session_write_close();
ignore_user_abort(true);
@set_time_limit(0);

PluginPrintgestionLogger::info('alertes', 'Recalcul demandé lancé par l\'écran (la tâche PrintgestionRebuildAlerts ne l\'avait pas pris).');
$done = PluginPrintgestionAlertview::processRequest();

echo json_encode(['ok' => true, 'done' => $done]);
