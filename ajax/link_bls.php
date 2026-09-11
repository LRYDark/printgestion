<?php
/**
 * Associe UN ou PLUSIEURS BL (plugin Gestion) à une expédition printgestion.
 *
 * POST attendu :
 *   - expedition_id : int
 *   - bls           : JSON array de strings. Chaque élément est soit :
 *                     - "123"            → ID numérique dans glpi_plugin_gestion_surveys
 *                     - "sage:BL203846"  → BL présent uniquement dans SAGE, à préparer
 *                                          d'abord via l'endpoint gestion
 *
 * Pour les "sage:..." :
 *   - On appelle la fonction `documentExiste()` de SageApi pour vérifier
 *     que le BL existe toujours côté SAGE
 *   - On appelle en interne la logique de création de survey en récupérant
 *     l'entité depuis l'imprimante de l'expédition
 *
 * La liaison est stockée dans `glpi_plugin_printgestion_expedition_bls` (N:N).
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_expedition', UPDATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

// CSRF validé par CheckCsrfListener (header X-Glpi-Csrf-Token pour AJAX).
header('Content-Type: application/json; charset=utf-8');
global $DB, $CFG_GLPI;

$config = PluginPrintgestionConfig::getInstance();
if ((int)($config->fields['plugin_gestion_enabled'] ?? 0) !== 1
    || !$plugin->isInstalled('gestion')
    || !$plugin->isActivated('gestion')) {
    echo json_encode(['ok' => false, 'error' => 'Gestion integration not enabled']);
    exit;
}

$expedition_id = (int)($_POST['expedition_id'] ?? 0);
$bls_raw       = (string)($_POST['bls'] ?? '');

if ($expedition_id <= 0 || $bls_raw === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

$bls = json_decode($bls_raw, true);
if (!is_array($bls) || empty($bls)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid bls']);
    exit;
}

// Récupère l'expédition + imprimante pour connaître l'entité
$exp = $DB->request([
    'FROM'  => 'glpi_plugin_printgestion_expeditions',
    'WHERE' => ['id' => $expedition_id],
    'LIMIT' => 1,
])->current();
if (!is_array($exp)) {
    echo json_encode(['ok' => false, 'error' => 'Expedition not found']);
    exit;
}

$printer = new Printer();
if (!$printer->getFromDB((int)$exp['printers_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Printer not found']);
    exit;
}
$printer_entities_id = (int)$printer->fields['entities_id'];

// Résolution des IDs cibles (local + création SAGE si besoin)
$target_ids = [];
$errors     = [];
$prepared   = 0;

foreach ($bls as $raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        continue;
    }

    $bl_surveys_id = 0;

    if (str_starts_with($raw, 'sage:')) {
        $bl_num = substr($raw, 5);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $bl_num)) {
            $errors[] = ['bl' => $raw, 'error' => 'Format BL invalide'];
            continue;
        }
        $bl_surveys_id = prepareSageBl($bl_num, $printer_entities_id, $error);
        if ($bl_surveys_id <= 0) {
            $errors[] = ['bl' => $bl_num, 'error' => $error ?: 'Impossible de préparer le BL'];
            continue;
        }
        $prepared++;
    } elseif (ctype_digit($raw)) {
        $bl_surveys_id = (int)$raw;
    } else {
        $errors[] = ['bl' => $raw, 'error' => 'Identifiant BL inconnu'];
        continue;
    }

    if ($bl_surveys_id <= 0) {
        continue;
    }

    // Vérifier que le BL existe dans gestion_surveys
    if ($DB->tableExists('glpi_plugin_gestion_surveys')) {
        $bl_row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_gestion_surveys',
            'WHERE'  => ['id' => $bl_surveys_id],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($bl_row)) {
            $errors[] = ['bl' => $raw, 'error' => 'BL introuvable en base'];
            continue;
        }
    }

    $target_ids[$bl_surveys_id] = true;
}

// Mode SYNC : on remplace la sélection actuelle par la cible.
//   1. Charge les liens actuels de l'expé
//   2. Supprime ceux qui ne sont plus dans la cible
//   3. Insère ceux de la cible qui n'existent pas encore
$current_ids = [];
foreach ($DB->request([
    'SELECT' => ['bl_surveys_id'],
    'FROM'   => 'glpi_plugin_printgestion_expedition_bls',
    'WHERE'  => ['expeditions_id' => $expedition_id],
]) as $r) {
    $current_ids[(int)$r['bl_surveys_id']] = true;
}
// On inclut aussi le "principal" legacy pour rester cohérent
$legacy_principal = (int)($exp['bl_surveys_id'] ?? 0);
if ($legacy_principal > 0) {
    $current_ids[$legacy_principal] = true;
}

// À supprimer
$to_remove = array_diff_key($current_ids, $target_ids);
foreach (array_keys($to_remove) as $rid) {
    $DB->delete('glpi_plugin_printgestion_expedition_bls', [
        'expeditions_id' => $expedition_id,
        'bl_surveys_id'  => $rid,
    ]);
    // Si on retire le "principal" legacy, on reset la colonne
    if ($rid === $legacy_principal) {
        $DB->update('glpi_plugin_printgestion_expeditions', [
            'bl_surveys_id' => null,
        ], ['id' => $expedition_id]);
        $legacy_principal = 0;
    }
}

// À ajouter
$to_add = array_diff_key($target_ids, $current_ids);
foreach (array_keys($to_add) as $aid) {
    try {
        $DB->insert('glpi_plugin_printgestion_expedition_bls', [
            'expeditions_id' => $expedition_id,
            'bl_surveys_id'  => $aid,
            'date_creation'  => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // silent : doublon (unique key) — peut arriver en course
    }
}

// Rétro-compat : si plus de principal mais des liens existent, set le premier
if ($legacy_principal <= 0 && !empty($target_ids)) {
    $first = array_key_first($target_ids);
    $DB->update('glpi_plugin_printgestion_expeditions', [
        'bl_surveys_id' => (int)$first,
    ], ['id' => $expedition_id]);
}

echo json_encode([
    'ok'       => true,
    'linked'   => array_keys($target_ids),
    'added'    => array_keys($to_add),
    'removed'  => array_keys($to_remove),
    'prepared' => $prepared,
    'errors'   => $errors,
]);


/**
 * Prépare un BL SAGE en créant une entrée dans glpi_plugin_gestion_surveys.
 * Utilise la même logique que ajax/quick_add_survey_form.php du plugin gestion,
 * mais appelé directement (pas via HTTP) pour éviter un round-trip CSRF.
 *
 * @return int survey_id créé (ou existant), ou 0 si échec.
 */
function prepareSageBl(string $bl_num, int $entities_id, ?string &$error = null): int {
    global $DB, $CFG_GLPI;

    $sage_api_file = realpath(__DIR__ . '/../../gestion/front/SageApi.php');
    if (!$sage_api_file || !file_exists($sage_api_file)) {
        $error = 'SageApi.php introuvable';
        return 0;
    }
    require_once $sage_api_file;

    // Si déjà présent localement, on retourne l'ID existant.
    $bl_esc = $DB->escape($bl_num);
    $existing = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_plugin_gestion_surveys',
        'WHERE'  => ['bl' => $bl_num],
        'LIMIT'  => 1,
    ])->current();
    if (is_array($existing)) {
        return (int)$existing['id'];
    }

    // Vérifie que le BL existe côté SAGE
    if (!function_exists('documentExiste')) {
        $error = 'Fonction documentExiste indisponible';
        return 0;
    }
    $httpStatus = null;
    try {
        if (documentExiste($bl_num, $httpStatus) !== true) {
            $error = 'BL inexistant dans SAGE';
            return 0;
        }
    } catch (Throwable $e) {
        $error = 'Erreur API SAGE : ' . $e->getMessage();
        return 0;
    }

    // Parse le document SAGE pour extraire tracker/client (best-effort)
    $tracker = null;
    $relatedInvoiceToBL = null;
    $pdf_filename = $bl_num;
    try {
        $fields = parseDocument($bl_num);
        $client = !empty($fields['client']) ? str_replace(' ', '_', (string)$fields['client']) : '';
        $pdf_filename = function_exists('sanitizeBLFilename')
            ? sanitizeBLFilename($client !== '' ? ($bl_num . '_' . $client) : $bl_num)
            : $bl_num;
        $tracker = $fields['tracker'] ?? null;
        $relatedInvoiceToBL = $fields['relatedInvoiceToBL'] ?? null;
    } catch (Throwable $e) {
        // silent : on garde juste le bl_num
    }

    $doc_url = function_exists('plugin_gestion_build_view_pdf_url')
        ? plugin_gestion_build_view_pdf_url($bl_num, true)
        : (rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/')
            . '/' . trim((string)PLUGIN_GESTION_NOTFULL_WEBDIR, '/')
            . '/view_pdf.php?id=' . rawurlencode($bl_num));

    $ok = $DB->insert('glpi_plugin_gestion_surveys', [
        'tickets_id'         => 0,
        'entities_id'        => $entities_id,
        'tracker'            => $tracker,
        'relatedInvoiceToBL' => $relatedInvoiceToBL,
        'url_bl'             => '',
        'bl'                 => $pdf_filename,
        'signed'             => 0,
        'doc_id'             => 0,
        'doc_url'            => $doc_url,
        'save'               => 'Sage',
        'date_creation'      => date('Y-m-d H:i:s'),
    ]);
    if (!$ok) {
        $error = 'Échec insertion gestion_surveys';
        return 0;
    }

    return (int)$DB->insertId();
}
