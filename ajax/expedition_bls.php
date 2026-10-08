<?php
/**
 * Liste les BL (glpi_plugin_gestion_surveys) associés à une expédition.
 *
 * GET attendu : expedition_id
 *
 * Retourne : { ok: true, bls: [{ id, bl, signed, date_creation, entity_name }], html }
 * bls pré-remplit le sélecteur de la fenêtre « Associer des BL » ; html est son tableau « Détail des BL associés »,
 * rendu par le gabarit natif (vide sans BL).
 * Inclut la relation N:N via glpi_plugin_printgestion_expedition_bls ET
 * le bl_surveys_id "principal" stocké sur expeditions (rétro-compat).
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

if (!Session::haveRight('plugin_printgestion_expedition', READ)
    && !Session::haveRight('plugin_printgestion_dashboard', READ)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
global $DB;

$expedition_id = (int)($_GET['expedition_id'] ?? 0);
if ($expedition_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Cloisonnement client : l'expédition doit être dans le périmètre (son entité, figée à sa création).
$accessible = PluginPrintgestionSecurity::getAccessibleExpedition($expedition_id);
if ($accessible === null) {
    PluginPrintgestionSecurity::denyJson();
}
// BL affichables : ceux de l'entité de l'expédition ou d'une parente, dans le périmètre de l'utilisateur.
// Un lien vers le BL d'un autre client, posé avant ce contrôle, n'est jamais relu.
$bl_entities = PluginPrintgestionSecurity::getBlEntities($accessible);

if (!$DB->tableExists('glpi_plugin_gestion_surveys')) {
    echo json_encode(['ok' => true, 'bls' => []]);
    exit;
}

// Collecte des bl_surveys_id liés (N:N + rétro-compat bl_surveys_id)
$linked_ids = [];

if ($DB->tableExists('glpi_plugin_printgestion_expedition_bls')) {
    foreach ($DB->request([
        'SELECT' => ['bl_surveys_id'],
        'FROM'   => 'glpi_plugin_printgestion_expedition_bls',
        'WHERE'  => ['expeditions_id' => $expedition_id],
    ]) as $r) {
        $linked_ids[(int)$r['bl_surveys_id']] = true;
    }
}

$exp = $DB->request([
    'SELECT' => ['bl_surveys_id'],
    'FROM'   => 'glpi_plugin_printgestion_expeditions',
    'WHERE'  => ['id' => $expedition_id],
    'LIMIT'  => 1,
])->current();
if (is_array($exp) && (int)($exp['bl_surveys_id'] ?? 0) > 0) {
    $linked_ids[(int)$exp['bl_surveys_id']] = true;
}

if (empty($linked_ids) || empty($bl_entities)) {
    echo json_encode(['ok' => true, 'bls' => []]);
    exit;
}

$bls = [];
foreach ($DB->request([
    'SELECT'    => ['s.id', 's.bl', 's.signed', 's.date_creation', 's.save', 'e.completename AS entity_name'],
    'FROM'      => 'glpi_plugin_gestion_surveys AS s',
    'LEFT JOIN' => [
        'glpi_entities AS e' => [
            'ON' => ['s' => 'entities_id', 'e' => 'id'],
        ],
    ],
    'WHERE'     => ['s.id' => array_keys($linked_ids), 's.entities_id' => $bl_entities],
    'ORDER'     => ['s.id DESC'],
]) as $row) {
    $bls[] = [
        'id'            => (int)$row['id'],
        'bl'            => (string)($row['bl'] ?? ''),
        'signed'        => (int)($row['signed'] ?? 0),
        'date_creation' => (string)($row['date_creation'] ?? ''),
        'save'          => (string)($row['save'] ?? ''),
        'entity_name'   => (string)($row['entity_name'] ?? ''),
    ];
}

// Tableau « Détail des BL associés » : rendu ici par le gabarit natif (components/datatable.html.twig), la fenêtre
// l'injecte tel quel. Mêmes colonnes, mêmes valeurs et même échappement que les lignes que son script assemblait :
// « — » pour un numéro, une entité ou une source vide, date coupée à 10 caractères, badge « signé ». Sans BL, pas de
// tableau : la fenêtre n'affiche pas le bloc (le gabarit écrirait « No results found »).
$html = '';
if (!empty($bls)) {
    $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $muted   = static fn(string $text) => "<span class='text-muted small'>" . $esc($text) . "</span>";
    $entries = [];
    foreach ($bls as $b) {
        $entries[] = [
            'bl'     => $esc($b['bl'] !== '' ? $b['bl'] : '—')
                . ($b['signed'] ? " <span class='badge bg-success ms-1'>" . $esc(__('signé', 'printgestion')) . "</span>" : ''),
            'date'   => $muted(substr($b['date_creation'], 0, 10)),
            'entity' => $muted($b['entity_name'] !== '' ? $b['entity_name'] : '—'),
            'source' => $muted($b['save'] !== '' ? $b['save'] : '—'),
        ];
    }
    try {
        $html = PluginPrintgestionUi::datatable(
            [
                'bl'     => __('BL', 'printgestion'),
                'date'   => __('Date', 'printgestion'),
                'entity' => __('Entité', 'printgestion'),
                'source' => __('Source', 'printgestion'),
            ],
            $entries,
            ['bl' => 'raw_html', 'date' => 'raw_html', 'entity' => 'raw_html', 'source' => 'raw_html']
        );
    } catch (Throwable $e) {
        // Le sélecteur reste pré-rempli par bls : seul le tableau de détail manque, la cause est tracée.
        PluginPrintgestionLogger::error('expedition_bls', sprintf('Tableau « Détail des BL associés » de l\'expédition %d non rendu : seul le détail manque à la fenêtre.', $expedition_id), $e);
    }
}

echo json_encode(['ok' => true, 'bls' => $bls, 'html' => $html]);
