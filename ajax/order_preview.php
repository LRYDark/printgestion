<?php
/**
 * Aperçu en direct du sous-formulaire « Commander » (alertes toner) : appelé pendant la saisie d'une référence de
 * cartouche, il refait les contrôles du fichier Gesconso avec cette référence et rend le bloc rouge / vert.
 *
 * Lecture seule : rien n'est créé ici, la cartouche ne l'est qu'à l'envoi de la commande. En AJAX, GLPI vérifie le
 * jeton CSRF de l'en-tête `X-Glpi-Csrf-Token` et le conserve : la saisie peut relancer l'aperçu autant de fois
 * qu'il faut.
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

// Même droit que l'action « Commander ».
if (!Session::haveRight('plugin_printgestion_validation', UPDATE)) {
    PluginPrintgestionSecurity::denyJson();
}

// Lignes relues et recloisonnées côté serveur : les ids postés ne donnent accès qu'à ce que l'utilisateur voit.
$ids     = array_map('intval', (array) ($_POST['ids'] ?? []));
$preview = PluginPrintgestionAlertview::renderOrderPreview(
    PluginPrintgestionAlertview::getOrderItems($ids),
    $_POST['pg_newcart'] ?? []
);

echo json_encode([
    'ok'      => true,
    'html'    => $preview['html'],
    'blocked' => (object) $preview['blocked'],
    'total'   => $preview['total'],
]);
