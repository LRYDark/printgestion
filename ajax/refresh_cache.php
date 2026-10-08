<?php
/**
 * Invalide les caches dashboard (alertes + billing) à la demande.
 * Utilisé par le bouton "Rafraîchir" en haut des dashboards — pratique pour
 * les tests manuels de données SNMP qui ne passent pas par le flux plugin.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || (!PluginPrintgestionConfig::isFeatureEnabled('toner') && !PluginPrintgestionConfig::isFeatureEnabled('cout'))) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

// Recalcul complet des alertes (lourd : vidage et reconstruction de la table) :
// réservé au droit de modification des alertes. Invalidation du cache de
// facturation : droit de lecture du coût à la page.
$can_refresh_alerts  = PluginPrintgestionConfig::isFeatureEnabled('toner') && Session::haveRight('plugin_printgestion_dashboard', UPDATE);
$can_refresh_billing = PluginPrintgestionConfig::isFeatureEnabled('cout') && Session::haveRight('plugin_printgestion_billing', READ);

if (!$can_refresh_alerts && !$can_refresh_billing) {
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

if ($can_refresh_alerts) {
    PluginPrintgestionAlert::invalidateCache();
    // Table matérialisée des alertes : recalcul demandé, fait par la tâche minute PrintgestionRebuildAlerts, jamais
    // dans cette requête (l'écran des alertes l'annonce et se recharge seul quand il est fini).
    PluginPrintgestionAlertview::requestRebuild();
}
if ($can_refresh_billing) {
    PluginPrintgestionBilling::invalidateCache();
}

echo json_encode(['ok' => true, 'alerts' => $can_refresh_alerts, 'billing' => $can_refresh_billing]);
