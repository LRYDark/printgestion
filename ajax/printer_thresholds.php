<?php

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')
    || !PluginPrintgestionConfig::isFeatureEnabled('toner')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Écriture : POST uniquement. Le contrôle CSRF du cœur GLPI 11 ne porte que sur
// les requêtes à corps (POST…) ; en GET, un simple lien piégé suffisait à
// modifier les seuils d'une imprimante (ex. seuil 0 = alertes supprimées).
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
$src = $_POST;

$printers_id         = (int)($src['printers_id'] ?? 0);

if ($printers_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid printer']);
    exit;
}

// Seuils d'alerte : module toner et droit Alertes toner en modification, sur une imprimante du
// périmètre de l'utilisateur (réglage du plugin, pas une modification de la fiche imprimante).
$printer = new Printer();
if (!PluginPrintgestionConfig::isFeatureEnabled('toner')
    || !Session::haveRight('plugin_printgestion_dashboard', UPDATE)
    || !$printer->getFromDB($printers_id) || !$printer->canViewItem()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// Enregistrement partagé avec l'action « Seuils d'alerte… » du tableau des alertes (vide = valeur globale),
// cache des alertes invalidé pour forcer un recalcul.
PluginPrintgestionPrinterThresholdsTab::saveFor($printers_id, array_intersect_key($src, PluginPrintgestionPrinterThresholdsTab::FIELDS));
// Message flash GLPI natif : affiché au prochain chargement complet de page
Session::addMessageAfterRedirect(
    __('Seuils personnalisés enregistrés', 'printgestion'),
    true,
    INFO
);

echo json_encode(['ok' => true]);
