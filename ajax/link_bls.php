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
// Cloisonnement client : expédition du périmètre (son entité, figée à sa création). Inexistante ou d'un autre
// client : même refus.
$exp = PluginPrintgestionSecurity::getAccessibleExpedition($expedition_id);
if ($exp === null) {
    PluginPrintgestionSecurity::denyJson();
}
// Un nouveau BL Sage est préparé dans l'entité de l'expédition (le client de l'envoi).
$expedition_entities_id = (int)$exp['entities_id'];

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
        $bl_surveys_id = prepareSageBl($bl_num, $expedition_entities_id, $error);
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

    // BL existant et du même client (entité de l'imprimante ou parente, dans le périmètre de l'utilisateur) :
    // un identifiant posté, ou un BL Sage déjà présent localement dans une autre entité, est refusé.
    if (PluginPrintgestionSecurity::getBlForExpedition($bl_surveys_id, $exp) === null) {
        $errors[] = ['bl' => $raw, 'error' => 'BL introuvable ou rattaché à un autre client'];
        continue;
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
        $DB->insert('glpi_plugin_printgestion_expedition_bls', PluginPrintgestionEntityscope::forExpedition($expedition_id) + [
            'expeditions_id' => $expedition_id,
            'bl_surveys_id'  => $aid,
            'date_creation'  => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Doublon possible en cas d'appels concurrents (clé unique) : liaison déjà
        // présente, on poursuit — mais toute autre cause d'échec reste tracée.
        PluginPrintgestionLogger::warning(
            'link_bls',
            sprintf('Liaison expédition %d ↔ BL %d non insérée (doublon concurrent ou erreur SQL).', $expedition_id, $aid),
            $e
        );
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
        PluginPrintgestionLogger::error('link_bls', "SageApi.php (plugin Gestion) introuvable : BL {$bl_num} non préparé.");
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
        PluginPrintgestionLogger::error('link_bls', "Fonction documentExiste() absente de SageApi.php : BL {$bl_num} non préparé.");
        return 0;
    }
    $httpStatus = null;
    try {
        if (documentExiste($bl_num, $httpStatus) !== true) {
            if ($httpStatus === 404) {
                $error = 'BL inexistant dans SAGE';
            } else {
                // Tout code autre que 404 est une panne de l'API, pas une absence du BL.
                $status_txt = $httpStatus === null ? 'aucune réponse' : (string)$httpStatus;
                $error = "API SAGE en échec (HTTP {$status_txt}) : existence du BL non vérifiable";
                PluginPrintgestionLogger::error('link_bls', "API Sage : vérification du BL {$bl_num} en échec (HTTP {$status_txt}).");
            }
            return 0;
        }
    } catch (Throwable $e) {
        $error = 'Erreur API SAGE : ' . $e->getMessage();
        PluginPrintgestionLogger::error('link_bls', "API Sage indisponible pendant la vérification du BL {$bl_num}.", $e);
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
        // Analyse du PDF non bloquante : BL préparé avec son seul numéro, cause tracée.
        PluginPrintgestionLogger::warning(
            'link_bls',
            "Analyse du document Sage {$bl_num} impossible : BL préparé sans client ni suivi transporteur.",
            $e
        );
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
