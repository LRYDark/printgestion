<?php
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
global $DB;

// La recherche sert uniquement à associer des BL à une expédition (droit de
// modification des expéditions) ; elle déclenche en outre un appel à l'API Sage.
if (!Session::haveRight('plugin_printgestion_expedition', UPDATE)) {
    PluginPrintgestionSecurity::denyJson();
}

if (!PluginPrintgestionTracking::isGestionLinkActive()) {
    echo json_encode(['ok' => false, 'bls' => [], 'error' => 'Gestion integration not enabled']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'bls' => []]);
    exit;
}

// Filtre par expédition : BL de son entité (figée à sa création) ou d'une entité parente, comme link_bls.php.
$expedition_id   = (int)($_GET['expedition_id'] ?? 0);
$entities_filter = null;
if ($expedition_id > 0) {
    $expedition = PluginPrintgestionSecurity::getAccessibleExpedition($expedition_id);
    if ($expedition === null) {
        PluginPrintgestionSecurity::denyJson();
    }
    $entities_filter = PluginPrintgestionSecurity::getBlEntities($expedition) ?: [-1];
}

$where = [
    's.bl' => ['LIKE', '%' . $q . '%'],
    // Cloisonnement client : jamais de BL hors des entités de l'utilisateur,
    // y compris quand aucune imprimante n'est précisée.
    getEntitiesRestrictCriteria('s', '', '', false),
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
    $signed_tag = ((int)$row['signed'] === 1) ? ' [' . __('signé', 'printgestion') . ']' : '';
    $bls[] = [
        'id'          => (int)$row['id'],
        'label'       => $bl_num . $signed_tag,
        'bl'          => $bl_num,
        'signed'      => (int)$row['signed'],
        'date'        => !empty($row['date_creation']) ? substr((string)$row['date_creation'], 0, 10) : '',
        'entity_name' => (string)($row['entity_name'] ?? ''),
    ];
    $seen_bls[strtoupper($bl_num)] = true;
    // Le BL local peut être suffixé avec le client (ex: "BL000123_CLIENT_TEST").
    // On marque aussi le préfixe brut (BL000123) comme "vu" pour éviter que
    // la recherche SAGE ajoute un doublon quand l'utilisateur cherche juste
    // "BL000123".
    if (preg_match('/^([A-Za-z]{2,}[0-9]{4,})/', $bl_num, $m)) {
        $seen_bls[strtoupper($m[1])] = true;
    }
}

// 2. Recherche BL SAGE (via SageApi) — uniquement si terme ressemble à un BL complet
//    (ex: "BL000123" = 2+ lettres + 4+ chiffres). On ne retourne le BL SAGE que
//    s'il n'est PAS déjà trouvé localement.
$sage_error = false;
if (preg_match('/^[A-Za-z]{2,}[0-9]{4,}$/', $q) && !isset($seen_bls[strtoupper($q)])) {
    $sage_api_file = realpath(__DIR__ . '/../../gestion/front/SageApi.php');
    if (!$sage_api_file || !file_exists($sage_api_file)) {
        PluginPrintgestionLogger::error('search_bls', "SageApi.php (plugin Gestion) introuvable : vérification du BL {$q} dans Sage impossible.");
        $sage_error = true;
    } else {
        try {
            require_once $sage_api_file;
            if (!function_exists('documentExiste')) {
                PluginPrintgestionLogger::error('search_bls', "Fonction documentExiste() absente de SageApi.php : vérification du BL {$q} dans Sage impossible.");
                $sage_error = true;
            } else {
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
                } elseif ($httpStatus !== 404) {
                    // 404 = BL absent de Sage (cas métier normal) ; tout autre code est une
                    // panne de l'API, pas une absence du BL.
                    PluginPrintgestionLogger::error('search_bls', sprintf(
                        'API Sage : vérification du BL %s en échec (HTTP %s).',
                        $q,
                        $httpStatus === null ? 'aucune réponse' : (string)$httpStatus
                    ));
                    $sage_error = true;
                }
            }
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('search_bls', "API Sage indisponible pendant la vérification du BL {$q}.", $e);
            $sage_error = true;
        }
    }
}

// sage_error : la vérification Sage n'a pas abouti — l'absence de résultat Sage ne
// signifie alors PAS que le BL n'existe pas (l'écran l'indique à l'utilisateur).
echo json_encode(['ok' => true, 'bls' => $bls, 'sage_error' => $sage_error]);
