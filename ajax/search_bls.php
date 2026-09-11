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
global $DB;

$config = PluginPrintgestionConfig::getInstance();
if ((int)($config->fields['plugin_gestion_enabled'] ?? 0) !== 1
    || !$plugin->isInstalled('gestion')
    || !$plugin->isActivated('gestion')
    || !$DB->tableExists('glpi_plugin_gestion_surveys')) {
    echo json_encode(['ok' => false, 'bls' => [], 'error' => 'Gestion integration not enabled']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'bls' => []]);
    exit;
}

// Filtre optionnel par entité de l'imprimante
$printers_id = (int)($_GET['printers_id'] ?? 0);
$entities_filter = null;
if ($printers_id > 0) {
    $printerRow = $DB->request([
        'SELECT' => ['entities_id'],
        'FROM'   => 'glpi_printers',
        'WHERE'  => ['id' => $printers_id],
        'LIMIT'  => 1,
    ])->current();
    if (is_array($printerRow)) {
        $entities_filter = (int)$printerRow['entities_id'];
    }
}

$where = [
    's.bl' => ['LIKE', '%' . $q . '%'],
];
if ($entities_filter !== null) {
    $where['s.entities_id'] = $entities_filter;
}

// 1. Recherche BL locaux (déjà dans glpi_plugin_gestion_surveys)
$bls = [];
$seen_bls = [];
foreach ($DB->request([
    'SELECT' => ['s.id', 's.bl', 's.signed', 's.date_creation', 'e.completename AS entity_name'],
    'FROM'   => 'glpi_plugin_gestion_surveys AS s',
    'LEFT JOIN' => [
        'glpi_entities AS e' => [
            'ON' => ['s' => 'entities_id', 'e' => 'id'],
        ],
    ],
    'WHERE'  => $where,
    'ORDER'  => ['s.id DESC'],
    'LIMIT'  => 50,
]) as $row) {
    $bl_num = trim((string)($row['bl'] ?? ''));
    if ($bl_num === '') {
        continue;
    }
    $signed_tag = ((int)$row['signed'] === 1) ? ' [signé]' : '';
    $bls[] = [
        'id'          => (int)$row['id'],
        'label'       => $bl_num . $signed_tag,
        'bl'          => $bl_num,
        'signed'      => (int)$row['signed'],
        'date'        => !empty($row['date_creation']) ? substr((string)$row['date_creation'], 0, 10) : '',
        'entity_name' => (string)($row['entity_name'] ?? ''),
    ];
    $seen_bls[strtoupper($bl_num)] = true;
    // Le BL local peut être suffixé avec le client (ex: "BL203846_REINERT_JORIS").
    // On marque aussi le préfixe brut (BL203846) comme "vu" pour éviter que
    // la recherche SAGE ajoute un doublon quand l'utilisateur cherche juste
    // "BL203846".
    if (preg_match('/^([A-Za-z]{2,}[0-9]{4,})/', $bl_num, $m)) {
        $seen_bls[strtoupper($m[1])] = true;
    }
}

// 2. Recherche BL SAGE (via SageApi) — uniquement si terme ressemble à un BL complet
//    (ex: "BL203846" = 2+ lettres + 4+ chiffres). On ne retourne le BL SAGE que
//    s'il n'est PAS déjà trouvé localement.
if (preg_match('/^[A-Za-z]{2,}[0-9]{4,}$/', $q) && !isset($seen_bls[strtoupper($q)])) {
    $sage_api_file = realpath(__DIR__ . '/../../gestion/front/SageApi.php');
    if ($sage_api_file && file_exists($sage_api_file)) {
        try {
            require_once $sage_api_file;
            if (function_exists('documentExiste')) {
                $httpStatus = null;
                if (documentExiste($q, $httpStatus) === true) {
                    // BL existe en SAGE mais pas encore dans la DB locale gestion.
                    // link_bls.php crée automatiquement l'entrée locale au moment
                    // de l'association (entité = celle de l'imprimante).
                    $bls[] = [
                        'id'         => 'sage:' . $q,
                        'label'      => $q . ' [SAGE]',
                        'sage_only'  => true,
                    ];
                }
            }
        } catch (Throwable $e) {
            // API Sage indisponible : silent, on retourne juste les locaux
        }
    }
}

echo json_encode(['ok' => true, 'bls' => $bls]);
