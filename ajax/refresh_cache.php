<?php
/**
 * Invalide les caches dashboard (alertes + billing) à la demande.
 * Utilisé par le bouton "Rafraîchir" en haut des dashboards — pratique pour
 * les tests manuels de données SNMP qui ne passent pas par le flux plugin.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

if (!Session::haveRight('plugin_printgestion_dashboard', READ)
    && !Session::haveRight('plugin_printgestion_billing', READ)
    && !Session::haveRight('plugin_printgestion_expedition', READ)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// POST uniquement (contrôle CSRF du cœur GLPI 11) : l'action vide et recalcule
// intégralement la table des alertes. En GET, un lien piégé répété suffisait à
// saturer le serveur.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (class_exists('PluginPrintgestionAlert')) {
    PluginPrintgestionAlert::invalidateCache();
}
if (class_exists('PluginPrintgestionBilling')) {
    PluginPrintgestionBilling::invalidateCache();
}
// Reconstruit la table matérialisée des alertes (tableau Search natif).
if (class_exists('PluginPrintgestionAlertview')
    && Session::haveRight('plugin_printgestion_dashboard', READ)) {
    PluginPrintgestionAlertview::rebuild();
}

echo json_encode(['ok' => true]);
