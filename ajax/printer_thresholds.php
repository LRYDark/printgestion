<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
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
$threshold_level     = $src['threshold_level']     ?? '';
$threshold_days      = $src['threshold_days']      ?? '';
$pages_per_cartridge = $src['pages_per_cartridge'] ?? '';

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

global $DB;
$table = 'glpi_plugin_printgestion_printer_thresholds';

// Valeurs : null si vide (→ fallback sur config globale)
$values = [
    'threshold_level'     => ($threshold_level     !== '' ? max(0, min(100, (int)$threshold_level)) : null),
    'threshold_days'      => ($threshold_days      !== '' ? max(0, (int)$threshold_days) : null),
    'pages_per_cartridge' => ($pages_per_cartridge !== '' ? max(100, (int)$pages_per_cartridge) : null),
];

$existing = $DB->request([
    'SELECT' => ['id'],
    'FROM'   => $table,
    'WHERE'  => ['printers_id' => $printers_id],
    'LIMIT'  => 1,
])->current();

if (is_array($existing)) {
    // Si tout est null → suppression pour revenir au défaut global
    if ($values['threshold_level'] === null
        && $values['threshold_days'] === null
        && $values['pages_per_cartridge'] === null) {
        $DB->delete($table, ['id' => (int)$existing['id']]);
    } else {
        $DB->update($table, $values, ['id' => (int)$existing['id']]);
    }
} else {
    if ($values['threshold_level'] !== null
        || $values['threshold_days'] !== null
        || $values['pages_per_cartridge'] !== null) {
        $DB->insert($table, PluginPrintgestionEntityscope::forPrinter($printers_id) + array_merge(['printers_id' => $printers_id], $values));
    }
}

// Invalide le cache alerts pour forcer un recalcul
PluginPrintgestionAlert::invalidateCache();

// Message flash GLPI natif : affiché au prochain chargement complet de page
Session::addMessageAfterRedirect(
    __('Seuils personnalisés enregistrés', 'printgestion'),
    true,
    INFO
);

echo json_encode(['ok' => true]);
