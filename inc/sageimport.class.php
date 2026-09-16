<?php
/**
 * PluginPrintgestionSageimport — import du référentiel Sage par dépôt de fichier.
 *
 * Aucune liaison directe avec Sage : un fichier exporté de Sage (xlsx, xls, ods ou csv) est
 * déposé à la main, analysé, prévisualisé avec son rapport d'écarts, puis validé. Un
 * référentiel par fichier :
 *   - clients               : code client Sage ↔ entité GLPI (table de correspondance du plugin) ;
 *   - adresses de livraison : plusieurs par client, ↔ lieux GLPI par Location.code ;
 *   - articles              : référence Sage ↔ CartridgeItem.ref.
 * L'import ne modifie aucun objet GLPI : seules les correspondances entité ↔ client
 * cochées à la validation sont écrites. Les écarts (lieux sans code, cartouches sans
 * référence connue…) sont signalés pour être corrigés dans GLPI.
 * Une ligne absente d'un import suivant n'est jamais supprimée : elle est marquée absente
 * (is_in_last_import = 0) et ne sert plus à l'export.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSageimport extends CommonDBTM {

    static $rightname = 'plugin_printgestion_config';

    const TYPE_CLIENTS    = 'clients';
    const TYPE_DELIVERIES = 'deliveries';
    const TYPE_ARTICLES   = 'articles';

    const TABLE_CLIENTS    = 'glpi_plugin_printgestion_sageclients';
    const TABLE_MAPPING    = 'glpi_plugin_printgestion_entitysageclients';
    const TABLE_DELIVERIES = 'glpi_plugin_printgestion_sagedeliveries';
    const TABLE_ARTICLES   = 'glpi_plugin_printgestion_sagearticles';

    /** Lignes de données maximum par fichier. */
    const MAX_ROWS = 20000;
    /** Éléments maximum affichés par liste de prévisualisation. */
    const LIST_MAX = 200;
    /** Analyse en attente de validation, dans la session de l'utilisateur. */
    const SESSION_KEY = 'plugin_printgestion_sageimport';

    static function getTypeName($nb = 0) {
        return _n('Import du référentiel Sage', 'Imports du référentiel Sage', $nb, 'printgestion');
    }

    public static function canCreate(): bool {
        return false;
    }

    public static function canDelete(): bool {
        return false;
    }

    public static function canPurge(): bool {
        return false;
    }

    public static function getTypeLabels(): array {
        return [
            self::TYPE_CLIENTS    => __('Clients', 'printgestion'),
            self::TYPE_DELIVERIES => __('Adresses de livraison', 'printgestion'),
            self::TYPE_ARTICLES   => __('Articles', 'printgestion'),
        ];
    }

    public static function getReferentialTable(string $type): string {
        return [
            self::TYPE_CLIENTS    => self::TABLE_CLIENTS,
            self::TYPE_DELIVERIES => self::TABLE_DELIVERIES,
            self::TYPE_ARTICLES   => self::TABLE_ARTICLES,
        ][$type];
    }

    /**
     * Colonnes attendues : champ => ['label', 'required', 'aliases' (en-têtes acceptés,
     * normalisés : minuscules, sans accent, ponctuation → espace)]. Les noms de champs Sage
     * (CT_Num, LI_Intitule, AR_Ref…) sont acceptés comme les libellés.
     */
    public static function getColumns(string $type): array {
        $client_code = ['code client', 'ct num', 'numero client', 'n client', 'code tiers', 'numero tiers', 'tiers'];
        switch ($type) {
            case self::TYPE_CLIENTS:
                return [
                    'code' => ['label' => __('Code client', 'printgestion'), 'required' => true, 'aliases' => $client_code],
                    'name' => ['label' => __('Intitulé', 'printgestion'), 'required' => true,
                               'aliases' => ['intitule', 'ct intitule', 'intitule client', 'raison sociale', 'nom']],
                ];
            case self::TYPE_DELIVERIES:
                return [
                    'client_code' => ['label' => __('Code client', 'printgestion'), 'required' => true, 'aliases' => $client_code],
                    'label'       => ['label' => __('Intitulé livraison', 'printgestion'), 'required' => true,
                                      'aliases' => ['intitule livraison', 'li intitule', 'intitule adresse', 'intitule']],
                    'address_key' => ['label' => __('Code adresse', 'printgestion'), 'required' => false,
                                      'aliases' => ['code adresse', 'li no', 'numero adresse', 'n adresse', 'code livraison', 'identifiant adresse']],
                    'address'     => ['label' => __('Adresse', 'printgestion'), 'required' => false, 'aliases' => ['adresse', 'li adresse']],
                    'postcode'    => ['label' => __('Code postal', 'printgestion'), 'required' => false, 'aliases' => ['code postal', 'li codepostal', 'cp']],
                    'town'        => ['label' => __('Ville', 'printgestion'), 'required' => false, 'aliases' => ['ville', 'li ville']],
                ];
            default:
                return [
                    'ref'   => ['label' => __('Référence', 'printgestion'), 'required' => true,
                                'aliases' => ['reference', 'ar ref', 'ref', 'reference article', 'code article']],
                    'label' => ['label' => __('Désignation', 'printgestion'), 'required' => false,
                                'aliases' => ['designation', 'ar design', 'libelle', 'intitule']],
                ];
        }
    }

    /** En-tête normalisé : minuscules, sans accent, suites de caractères non alphanumériques → espace. */
    public static function normalizeLabel(string $label): string {
        static $transliterator = null;
        if ($transliterator === null) {
            $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()') ?: false;
        }
        $text = $transliterator ? (string) $transliterator->transliterate($label) : mb_strtolower($label);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Clé d'unicité d'une ligne, insensible à la casse comme la base. */
    public static function rowKey(string $type, array $row): string {
        switch ($type) {
            case self::TYPE_CLIENTS:
                return mb_strtoupper((string) $row['code']);
            case self::TYPE_DELIVERIES:
                return mb_strtoupper((string) $row['client_code']) . '|' . mb_strtoupper((string) $row['address_key']);
            default:
                return mb_strtoupper((string) $row['ref']);
        }
    }

    // ── Analyse du fichier ────────────────────────────────────────────────────

    /**
     * Lit la première feuille d'un fichier (ligne 1 = en-têtes) et contrôle chaque ligne.
     * Aucune écriture.
     *
     * @return array ['rows' => array[], 'errors' => string[] (bloquants), 'warnings' => string[]]
     */
    public static function parseFile(string $path, string $original_name, string $type): array {
        $out     = ['rows' => [], 'errors' => [], 'warnings' => []];
        $columns = self::getColumns($type);

        $readers = ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'ods' => 'Ods', 'csv' => 'Csv', 'txt' => 'Csv'];
        $ext     = mb_strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!isset($readers[$ext])) {
            $out['errors'][] = __('Format non pris en charge : xlsx, xls, ods ou csv.', 'printgestion');
            return $out;
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readers[$ext]);
            if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
                // Exports Sage souvent en Windows-1252, séparateur point-virgule : détectés.
                $reader->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::GUESS_ENCODING);
            }
            $reader->setReadDataOnly(true);
            $data = $reader->load($path)->getSheet(0)->toArray(null, false, true);
        } catch (Throwable $e) {
            PluginPrintgestionLogger::warning('sage-import', sprintf('Fichier illisible : %s', $original_name), $e);
            $out['errors'][] = sprintf(__('Fichier illisible (%s).', 'printgestion'), $e->getMessage());
            return $out;
        }

        $header = array_shift($data) ?? [];
        $map    = [];
        foreach ($header as $index => $label) {
            $normalized = self::normalizeLabel((string) $label);
            foreach ($columns as $field => $column) {
                if (!isset($map[$field]) && in_array($normalized, $column['aliases'], true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }
        foreach ($columns as $field => $column) {
            if ($column['required'] && !isset($map[$field])) {
                $out['errors'][] = sprintf(
                    __('Colonne obligatoire absente : « %1$s » (en-têtes acceptés : %2$s).', 'printgestion'),
                    $column['label'],
                    implode(', ', $column['aliases'])
                );
            }
        }
        if (!empty($out['errors'])) {
            return $out;
        }
        if (count($data) > self::MAX_ROWS) {
            $out['errors'][] = sprintf(__('Fichier trop volumineux : %1$d lignes (maximum %2$d).', 'printgestion'), count($data), self::MAX_ROWS);
            return $out;
        }

        $seen = [];
        foreach ($data as $index => $cells) {
            $line_no = $index + 2;
            $row     = [];
            foreach ($columns as $field => $column) {
                $value = isset($map[$field]) ? trim((string) ($cells[$map[$field]] ?? '')) : '';
                if (mb_strlen($value) > 255 && $field !== 'address') {
                    $out['warnings'][] = sprintf(__('Ligne %1$d : « %2$s » tronqué à 255 caractères.', 'printgestion'), $line_no, $column['label']);
                    $value = mb_substr($value, 0, 255);
                }
                $row[$field] = $value;
            }
            if (implode('', $row) === '') {
                continue; // ligne vide
            }

            $missing = [];
            foreach ($columns as $field => $column) {
                if ($column['required'] && $row[$field] === '') {
                    $missing[] = $column['label'];
                }
            }
            if (!empty($missing)) {
                $out['errors'][] = sprintf(__('Ligne %1$d : %2$s manquant.', 'printgestion'), $line_no, implode(', ', $missing));
                continue;
            }

            if ($type === self::TYPE_DELIVERIES && $row['address_key'] === '') {
                // Sans code adresse, l'intitulé sert de clé de rapprochement avec Location.code.
                $row['address_key'] = $row['label'];
            }

            $key = self::rowKey($type, $row);
            if (isset($seen[$key])) {
                $out['errors'][] = sprintf(__('Ligne %1$d : doublon de la ligne %2$d (%3$s).', 'printgestion'), $line_no, $seen[$key], $key);
                continue;
            }
            $seen[$key] = $line_no;

            $code = $type === self::TYPE_CLIENTS ? $row['code'] : ($row['client_code'] ?? null);
            if ($code !== null && preg_match('/[a-z\s]/u', $code)) {
                $out['warnings'][] = sprintf(
                    __('Ligne %1$d : code client « %2$s » avec minuscules ou espaces (Gesconso attend le code Sage tel quel, en majuscules sans espace) : importé sans modification.', 'printgestion'),
                    $line_no,
                    $code
                );
            }

            $out['rows'][] = $row;
        }

        if (empty($out['rows']) && empty($out['errors'])) {
            $out['errors'][] = __('Aucune ligne de données dans le fichier.', 'printgestion');
        }
        return $out;
    }

    // ── Prévisualisation et rapport d'écarts ──────────────────────────────────

    /**
     * Écarts entre les lignes analysées et le référentiel en base, sans rien écrire.
     *
     * @return array ['created' => array[], 'updated' => [['row', 'changes', 'reactivated']],
     *                'unchanged' => int, 'absent' => array[],
     *                'gaps' => [['title', 'level' (warning|info), 'items' => string[]]],
     *                'clients' => [['code', 'name', 'entities' => string[], 'suggested' => int]]]
     */
    public static function computePreview(string $type, array $rows): array {
        global $DB;

        $preview = ['created' => [], 'updated' => [], 'unchanged' => 0, 'absent' => [], 'gaps' => [], 'clients' => []];
        $fields  = array_keys(self::getColumns($type));

        $existing = [];
        foreach ($DB->request(['FROM' => self::getReferentialTable($type)]) as $record) {
            $existing[self::rowKey($type, $record)] = $record;
        }

        $seen = [];
        foreach ($rows as $row) {
            $key        = self::rowKey($type, $row);
            $seen[$key] = true;
            $record     = $existing[$key] ?? null;
            if ($record === null) {
                $preview['created'][] = $row;
                continue;
            }
            $changes = [];
            foreach ($fields as $field) {
                if ((string) ($record[$field] ?? '') !== $row[$field]) {
                    $changes[$field] = [(string) ($record[$field] ?? ''), $row[$field]];
                }
            }
            $reactivated = (int) $record['is_in_last_import'] !== 1;
            if (!empty($changes) || $reactivated) {
                $preview['updated'][] = ['row' => $row, 'changes' => $changes, 'reactivated' => $reactivated];
            } else {
                $preview['unchanged']++;
            }
        }
        foreach ($existing as $key => $record) {
            if (!isset($seen[$key]) && (int) $record['is_in_last_import'] === 1) {
                $preview['absent'][] = $record;
            }
        }

        switch ($type) {
            case self::TYPE_CLIENTS:
                $preview['clients'] = self::previewClientLinks($rows);
                $preview['gaps'][]  = [
                    'title' => __('Entités avec imprimantes sans code client Sage, ni propre ni hérité (correspondances actuelles)', 'printgestion'),
                    'level' => 'warning',
                    'items' => self::entitiesWithoutClient(),
                ];
                break;

            case self::TYPE_DELIVERIES:
                $known_clients = [];
                foreach ($DB->request(['SELECT' => ['code'], 'FROM' => self::TABLE_CLIENTS, 'WHERE' => ['is_in_last_import' => 1]]) as $client) {
                    $known_clients[mb_strtoupper((string) $client['code'])] = true;
                }
                $location_codes = [];
                $keys           = array_values(array_unique(array_column($rows, 'address_key')));
                foreach (array_chunk($keys, 1000) as $chunk) {
                    foreach ($DB->request(['SELECT' => ['code'], 'FROM' => 'glpi_locations', 'WHERE' => ['code' => $chunk]]) as $location) {
                        $location_codes[mb_strtoupper((string) $location['code'])] = true;
                    }
                }
                $unknown_client = [];
                $no_location    = [];
                foreach ($rows as $row) {
                    $label = sprintf('%s — %s (%s)', $row['client_code'], $row['label'], $row['address_key']);
                    if (!isset($known_clients[mb_strtoupper($row['client_code'])])) {
                        $unknown_client[] = $label;
                    }
                    if (!isset($location_codes[mb_strtoupper($row['address_key'])])) {
                        $no_location[] = $label;
                    }
                }
                $preview['gaps'][] = [
                    'title' => __('Adresses dont le client est absent du référentiel clients', 'printgestion'),
                    'level' => 'warning',
                    'items' => $unknown_client,
                ];
                $preview['gaps'][] = [
                    'title' => __('Adresses sans lieu GLPI portant leur code (renseigner le champ « Code » du lieu)', 'printgestion'),
                    'level' => 'warning',
                    'items' => $no_location,
                ];
                $preview['gaps'][] = [
                    'title' => __('Imprimantes dont le client est connu mais sans adresse de livraison résolue avec ce fichier', 'printgestion'),
                    'level' => 'warning',
                    'items' => self::printersWithoutDelivery($rows),
                ];
                break;

            default:
                $refs = [];
                foreach ($rows as $row) {
                    $refs[mb_strtoupper($row['ref'])] = true;
                }
                $empty_ref   = [];
                $unknown_ref = [];
                $used        = [];
                foreach ($DB->request([
                    'SELECT' => ['id', 'name', 'ref'],
                    'FROM'   => 'glpi_cartridgeitems',
                    'WHERE'  => ['is_deleted' => 0],
                    'ORDER'  => ['name'],
                ]) as $cartridge) {
                    $ref = trim((string) $cartridge['ref']);
                    if ($ref === '') {
                        $empty_ref[] = sprintf('%s (#%d)', $cartridge['name'], $cartridge['id']);
                    } elseif (!isset($refs[mb_strtoupper($ref)])) {
                        $unknown_ref[] = sprintf('%s (#%d) — %s', $cartridge['name'], $cartridge['id'], $ref);
                    } else {
                        $used[mb_strtoupper($ref)] = true;
                    }
                }
                $preview['gaps'][] = [
                    'title' => __('Cartouches GLPI sans référence', 'printgestion'),
                    'level' => 'warning',
                    'items' => $empty_ref,
                ];
                $preview['gaps'][] = [
                    'title' => __('Cartouches GLPI dont la référence est absente du fichier', 'printgestion'),
                    'level' => 'warning',
                    'items' => $unknown_ref,
                ];
                $preview['gaps'][] = [
                    'title' => sprintf(
                        __('%d article(s) du fichier sans cartouche GLPI de même référence (information)', 'printgestion'),
                        count($refs) - count($used)
                    ),
                    'level' => 'info',
                    'items' => [],
                ];
        }

        return $preview;
    }

    /** Correspondances actuelles de chaque code du fichier, et suggestion d'entité de même nom. */
    private static function previewClientLinks(array $rows): array {
        global $DB;

        $mapped          = [];
        $mapped_entities = [];
        foreach ($DB->request([
            'SELECT'     => ['c.code', 'm.entities_id', 'e.completename'],
            'FROM'       => self::TABLE_MAPPING . ' AS m',
            'INNER JOIN' => [
                self::TABLE_CLIENTS . ' AS c' => ['ON' => ['m' => 'plugin_printgestion_sageclients_id', 'c' => 'id']],
            ],
            'LEFT JOIN'  => [
                'glpi_entities AS e' => ['ON' => ['m' => 'entities_id', 'e' => 'id']],
            ],
        ]) as $link) {
            // Nom d'une entité hors du périmètre de l'utilisateur jamais affiché.
            $mapped[mb_strtoupper((string) $link['code'])][] = Session::haveAccessToEntity((int) $link['entities_id'])
                ? (string) $link['completename']
                : __('entité hors de votre périmètre', 'printgestion');
            $mapped_entities[(int) $link['entities_id']] = true;
        }

        // Suggestions par nom : entités du périmètre de l'utilisateur seulement.
        $by_name = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_entities']) as $entity) {
            if (!isset($mapped_entities[(int) $entity['id']]) && Session::haveAccessToEntity((int) $entity['id'])) {
                $by_name[self::normalizeLabel((string) $entity['name'])][] = (int) $entity['id'];
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $item = [
                'code'      => $row['code'],
                'name'      => $row['name'],
                'entities'  => $mapped[mb_strtoupper($row['code'])] ?? [],
                'suggested' => 0,
            ];
            if (empty($item['entities'])) {
                $candidates = $by_name[self::normalizeLabel($row['name'])] ?? [];
                if (count($candidates) === 1) {
                    $item['suggested'] = $candidates[0];
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /** Correspondances entité → code client actuelles (entités_id => code). */
    private static function currentEntityCodes(): array {
        global $DB;

        $codes = [];
        foreach ($DB->request([
            'SELECT'     => ['m.entities_id', 'c.code'],
            'FROM'       => self::TABLE_MAPPING . ' AS m',
            'INNER JOIN' => [
                self::TABLE_CLIENTS . ' AS c' => ['ON' => ['m' => 'plugin_printgestion_sageclients_id', 'c' => 'id']],
            ],
        ]) as $link) {
            $codes[(int) $link['entities_id']] = (string) $link['code'];
        }
        return $codes;
    }

    /** Code client résolu (propre ou hérité) d'une entité, d'après une table entités_id => code. */
    private static function resolveEntityCode(int $entities_id, array $codes, array &$cache): ?string {
        if (!array_key_exists($entities_id, $cache)) {
            $cache[$entities_id] = $codes[$entities_id] ?? null;
            if ($cache[$entities_id] === null) {
                // Ancêtre le plus proche = celui qui a lui-même le plus d'ancêtres.
                $best_depth = -1;
                foreach (array_keys(getAncestorsOf('glpi_entities', $entities_id)) as $ancestor) {
                    $ancestor = (int) $ancestor;
                    if (!isset($codes[$ancestor])) {
                        continue;
                    }
                    $depth = count(getAncestorsOf('glpi_entities', $ancestor));
                    if ($depth > $best_depth) {
                        $best_depth          = $depth;
                        $cache[$entities_id] = $codes[$ancestor];
                    }
                }
            }
        }
        return $cache[$entities_id];
    }

    /** Entités portant des imprimantes et sans code client, ni propre ni hérité. */
    private static function entitiesWithoutClient(): array {
        global $DB;

        $codes = self::currentEntityCodes();
        $cache = [];
        $out   = [];
        foreach ($DB->request([
            'SELECT'     => ['e.id', 'e.completename', new QueryExpression('COUNT(`p`.`id`) AS `nb`')],
            'FROM'       => 'glpi_printers AS p',
            'INNER JOIN' => ['glpi_entities AS e' => ['ON' => ['p' => 'entities_id', 'e' => 'id']]],
            // Rapport limité aux entités de l'utilisateur.
            'WHERE'      => ['p.is_deleted' => 0, 'p.is_template' => 0] + getEntitiesRestrictCriteria('p', '', '', true),
            'GROUPBY'    => ['e.id', 'e.completename'],
            'ORDER'      => ['e.completename'],
        ]) as $entity) {
            if (self::resolveEntityCode((int) $entity['id'], $codes, $cache) === null) {
                $out[] = sprintf(__('%1$s (%2$d imprimante(s))', 'printgestion'), $entity['completename'], $entity['nb']);
            }
        }
        return $out;
    }

    /**
     * Imprimantes dont l'entité a un code client mais dont aucun lieu (le leur, puis les
     * parents) ne porte le code d'une adresse de ce client dans le fichier analysé.
     */
    private static function printersWithoutDelivery(array $rows): array {
        global $DB;

        $addresses = [];
        foreach ($rows as $row) {
            $addresses[mb_strtoupper($row['client_code']) . '|' . mb_strtoupper($row['address_key'])] = true;
        }
        $locations = [];
        foreach ($DB->request(['SELECT' => ['id', 'locations_id', 'code'], 'FROM' => 'glpi_locations']) as $location) {
            $locations[(int) $location['id']] = [(int) $location['locations_id'], trim((string) $location['code'])];
        }

        $codes = self::currentEntityCodes();
        $cache = [];
        $out   = [];
        foreach ($DB->request([
            'SELECT'     => ['p.id', 'p.name', 'p.entities_id', 'p.locations_id', 'e.completename'],
            'FROM'       => 'glpi_printers AS p',
            'INNER JOIN' => ['glpi_entities AS e' => ['ON' => ['p' => 'entities_id', 'e' => 'id']]],
            // Rapport limité aux entités de l'utilisateur.
            'WHERE'      => ['p.is_deleted' => 0, 'p.is_template' => 0] + getEntitiesRestrictCriteria('p', '', '', true),
            'ORDER'      => ['e.completename', 'p.name'],
        ]) as $printer) {
            $client_code = self::resolveEntityCode((int) $printer['entities_id'], $codes, $cache);
            if ($client_code === null) {
                continue; // signalé par le rapport des clients
            }
            $found   = false;
            $current = (int) $printer['locations_id'];
            $guard   = 0;
            while ($current > 0 && isset($locations[$current]) && $guard++ < 50) {
                [$parent, $code] = $locations[$current];
                if ($code !== '' && isset($addresses[mb_strtoupper($client_code) . '|' . mb_strtoupper($code)])) {
                    $found = true;
                    break;
                }
                $current = $parent;
            }
            if (!$found) {
                $out[] = sprintf('%s — %s (%s)', $printer['completename'], $printer['name'], $client_code);
            }
        }
        return $out;
    }

    // ── Validation de l'import ────────────────────────────────────────────────

    /**
     * Applique un import analysé et prévisualisé, tout ou rien (transaction) : création ou
     * mise à jour des lignes du fichier, lignes absentes marquées absentes (jamais
     * supprimées), correspondances entité ↔ client choisies (clients), trace de l'import.
     *
     * @param array $links CODE CLIENT EN MAJUSCULES => entities_id (import clients uniquement).
     * @return array ['ok' => bool, 'errors' => string[], 'counts' => array]
     */
    public static function apply(string $type, array $rows, array $links, string $filename): array {
        global $DB;

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'absent' => 0, 'linked' => 0];
        $errors = [];

        if ($type === self::TYPE_CLIENTS && !empty($links)) {
            $codes_in_file = [];
            foreach ($rows as $row) {
                $codes_in_file[mb_strtoupper($row['code'])] = $row;
            }
            $current  = self::currentEntityCodes();
            $entities = [];
            foreach ($links as $code => $entities_id) {
                $entity = new Entity();
                if (!isset($codes_in_file[$code])) {
                    $errors[] = sprintf(__('Code client %s absent du fichier.', 'printgestion'), $code);
                } elseif (!$entity->getFromDB($entities_id) || !Session::haveAccessToEntity($entities_id)) {
                    // Hors périmètre de l'utilisateur : même refus qu'une entité inexistante.
                    $errors[] = sprintf(__('Code client %s : entité introuvable ou hors de votre périmètre.', 'printgestion'), $code);
                } elseif (isset($entities[$entities_id])) {
                    $errors[] = sprintf(
                        __('L\'entité %1$s est choisie pour deux codes clients (%2$s et %3$s) : une entité n\'a qu\'un client.', 'printgestion'),
                        $entity->fields['completename'],
                        $entities[$entities_id],
                        $code
                    );
                } elseif (isset($current[$entities_id]) && mb_strtoupper($current[$entities_id]) !== $code) {
                    $errors[] = sprintf(
                        __('L\'entité %1$s est déjà liée au client %2$s : changez-la depuis son onglet « Print Gestion — Sage ».', 'printgestion'),
                        $entity->fields['completename'],
                        $current[$entities_id]
                    );
                }
                $entities[$entities_id] = $code;
            }
        }
        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors, 'counts' => $counts];
        }

        $table  = self::getReferentialTable($type);
        $fields = array_keys(self::getColumns($type));
        $now    = $_SESSION['glpi_currenttime'];

        try {
            PluginPrintgestionDemande::transactional(function () use ($type, $rows, $links, $filename, $table, $fields, $now, &$counts) {
                global $DB;

                $existing = [];
                foreach ($DB->request(['FROM' => $table]) as $record) {
                    $existing[self::rowKey($type, $record)] = $record;
                }

                $seen_ids = [];
                $ids      = [];
                foreach ($rows as $row) {
                    $key    = self::rowKey($type, $row);
                    $values = array_intersect_key($row, array_flip($fields)) + ['is_in_last_import' => 1, 'date_import' => $now];
                    $record = $existing[$key] ?? null;
                    if ($record === null) {
                        $DB->insert($table, $values + ['date_creation' => $now, 'date_mod' => $now]);
                        $id = (int) $DB->insertId();
                        $counts['created']++;
                    } else {
                        $id      = (int) $record['id'];
                        $changed = (int) $record['is_in_last_import'] !== 1;
                        foreach ($fields as $field) {
                            $changed = $changed || (string) ($record[$field] ?? '') !== $row[$field];
                        }
                        if ($changed) {
                            $DB->update($table, $values + ['date_mod' => $now], ['id' => $id]);
                            $counts['updated']++;
                        } else {
                            $DB->update($table, ['date_import' => $now], ['id' => $id]);
                            $counts['unchanged']++;
                        }
                    }
                    $seen_ids[$id] = true;
                    $ids[$key]     = $id;
                }

                foreach ($existing as $record) {
                    if (!isset($seen_ids[(int) $record['id']]) && (int) $record['is_in_last_import'] === 1) {
                        $DB->update($table, ['is_in_last_import' => 0, 'date_mod' => $now], ['id' => (int) $record['id']]);
                        $counts['absent']++;
                    }
                }

                foreach ($links as $code => $entities_id) {
                    if (!isset($ids[$code])) {
                        continue;
                    }
                    $already = countElementsInTable(self::TABLE_MAPPING, [
                        'entities_id'                        => (int) $entities_id,
                        'plugin_printgestion_sageclients_id' => $ids[$code],
                    ]);
                    if ($already > 0) {
                        continue;
                    }
                    $DB->insert(self::TABLE_MAPPING, [
                        'entities_id'                        => (int) $entities_id,
                        'plugin_printgestion_sageclients_id' => $ids[$code],
                        'date_creation'                      => $now,
                        'date_mod'                           => $now,
                    ]);
                    PluginPrintgestionSage::logOnEntity((int) $entities_id, sprintf(
                        __('Print Gestion : liée au client Sage %1$s (import du fichier %2$s).', 'printgestion'),
                        $code,
                        $filename
                    ));
                    $counts['linked']++;
                }

                $DB->insert(self::getTable(), [
                    'type'          => $type,
                    'filename'      => mb_substr($filename, 0, 255),
                    'users_id'      => (int) Session::getLoginUserID(),
                    'nb_created'    => $counts['created'],
                    'nb_updated'    => $counts['updated'],
                    'nb_unchanged'  => $counts['unchanged'],
                    'nb_absent'     => $counts['absent'],
                    'nb_linked'     => $counts['linked'],
                    'date_creation' => $now,
                ]);
            });
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('sage-import', sprintf('Import %s du fichier %s annulé.', $type, $filename), $e);
            return [
                'ok'     => false,
                'errors' => [__('Import annulé (erreur technique, détail dans le journal printgestion) : rien n\'a été modifié.', 'printgestion')],
                'counts' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'absent' => 0, 'linked' => 0],
            ];
        }

        return ['ok' => true, 'errors' => [], 'counts' => $counts];
    }

    // ── Écrans ────────────────────────────────────────────────────────────────

    /** Formulaire de dépôt, avec les colonnes attendues pour chaque référentiel. */
    public static function showUploadForm(): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(__('Déposer un fichier exporté de Sage', 'printgestion')) . "</h3></div><div class='card-body'>";
        echo "<p class='text-muted small'>"
            . $esc(__('Un fichier par référentiel (xlsx, xls, ods ou csv), première feuille, ligne 1 = en-têtes. Le fichier est d\'abord analysé : rien n\'est enregistré avant la validation de la prévisualisation. Une ligne présente en base mais absente du fichier est marquée absente, jamais supprimée.', 'printgestion'))
            . "</p>";

        echo "<form method='post' enctype='multipart/form-data' action='" . $esc(self::getPageURL()) . "' class='row g-3 align-items-end mb-3'>";
        echo "<div class='col-md-4'><label class='form-label'>" . $esc(__('Référentiel', 'printgestion')) . "</label>";
        Dropdown::showFromArray('type', self::getTypeLabels(), ['width' => '100%']);
        echo "</div>";
        echo "<div class='col-md-5'><label class='form-label'>" . $esc(__('Fichier', 'printgestion')) . "</label>"
            . "<input type='file' class='form-control' name='file' accept='.xlsx,.xls,.ods,.csv,.txt' required></div>";
        echo "<div class='col-md-3'><button type='submit' name='analyze' value='1' class='btn btn-primary'>"
            . "<i class='ti ti-file-search me-1'></i>" . $esc(__('Analyser', 'printgestion')) . "</button></div>";
        Html::closeForm();

        echo "<div class='table-responsive'><table class='table table-sm'><thead><tr>"
            . "<th>" . $esc(__('Référentiel', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Colonnes (obligatoires en gras) et en-têtes acceptés', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Rapprochement GLPI', 'printgestion')) . "</th></tr></thead><tbody>";
        $matching = [
            self::TYPE_CLIENTS    => __('Entité, par la correspondance du plugin (choisie à la validation ou sur l\'onglet « Print Gestion — Sage » de l\'entité) ; une sous-entité hérite du client de son parent.', 'printgestion'),
            self::TYPE_DELIVERIES => __('Lieu GLPI dont le champ « Code » vaut le code adresse (à défaut de colonne code adresse : l\'intitulé livraison).', 'printgestion'),
            self::TYPE_ARTICLES   => __('Cartouche GLPI dont la référence vaut la référence article.', 'printgestion'),
        ];
        foreach (self::getTypeLabels() as $type => $label) {
            $columns = [];
            foreach (self::getColumns($type) as $column) {
                $name      = $column['required'] ? '<strong>' . $esc($column['label']) . '</strong>' : $esc($column['label']);
                $columns[] = $name . " <span class='text-muted small'>(" . $esc(implode(', ', $column['aliases'])) . ')</span>';
            }
            echo "<tr><td>" . $esc($label) . "</td><td>" . implode('<br>', $columns) . "</td><td class='small'>" . $esc($matching[$type]) . "</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "</div></div>";
    }

    /** Prévisualisation : volumes, erreurs, écarts, correspondances clients, validation. */
    public static function showPreview(array $pending): void {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $type    = (string) $pending['type'];
        $rows    = (array) $pending['rows'];
        $errors  = (array) $pending['errors'];
        $preview = empty($errors) ? self::computePreview($type, $rows) : null;

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . $esc(sprintf(
            __('Prévisualisation — %1$s — fichier %2$s', 'printgestion'),
            self::getTypeLabels()[$type] ?? $type,
            $pending['filename']
        )) . "</h3></div><div class='card-body'>";

        $list = static function (array $items, string $class = '') use ($esc): string {
            if (empty($items)) {
                return "<p class='text-muted small mb-2'>" . $esc(__('Aucun.', 'printgestion')) . "</p>";
            }
            $html = "<ul class='small mb-2 {$class}'>";
            foreach (array_slice($items, 0, self::LIST_MAX) as $item) {
                $html .= '<li>' . $esc($item) . '</li>';
            }
            if (count($items) > self::LIST_MAX) {
                $html .= '<li>' . $esc(sprintf(__('… et %d autre(s)', 'printgestion'), count($items) - self::LIST_MAX)) . '</li>';
            }
            return $html . '</ul>';
        };

        if (!empty($errors)) {
            echo "<div class='alert alert-danger'><strong>" . $esc(sprintf(
                _n('%d erreur : import impossible, corrigez le fichier puis redéposez-le.', '%d erreurs : import impossible, corrigez le fichier puis redéposez-le.', count($errors), 'printgestion'),
                count($errors)
            )) . "</strong>" . $list($errors) . "</div>";
        }
        if (!empty($pending['warnings'])) {
            echo "<div class='alert alert-warning'><strong>" . $esc(__('Avertissements (non bloquants)', 'printgestion')) . "</strong>"
                . $list((array) $pending['warnings']) . "</div>";
        }

        if ($preview !== null) {
            $describe = static function (array $row): string {
                return implode(' — ', array_filter(array_map('strval', array_values($row)), static fn($v) => $v !== ''));
            };

            PluginPrintgestionUi::statsBar([
                ['count' => count($rows), 'label' => __('Lignes du fichier', 'printgestion'), 'icon' => 'ti ti-file-spreadsheet', 'color' => 'secondary'],
                ['count' => count($preview['created']), 'label' => __('Nouvelles', 'printgestion'), 'icon' => 'ti ti-plus', 'color' => 'green'],
                ['count' => count($preview['updated']), 'label' => __('Modifiées', 'printgestion'), 'icon' => 'ti ti-pencil', 'color' => 'blue'],
                ['count' => $preview['unchanged'], 'label' => __('Inchangées', 'printgestion'), 'icon' => 'ti ti-equal', 'color' => 'secondary'],
                ['count' => count($preview['absent']), 'label' => __('Absentes du fichier', 'printgestion'),
                 'tooltip' => __('Présentes en base, absentes du fichier : marquées absentes, plus utilisables à l\'export', 'printgestion'),
                 'icon' => 'ti ti-minus', 'color' => 'orange'],
            ]);

            echo "<h4>" . $esc(__('Nouvelles lignes', 'printgestion')) . "</h4>"
                . $list(array_map($describe, $preview['created']));

            $updated = [];
            foreach ($preview['updated'] as $update) {
                $parts = [];
                foreach ($update['changes'] as $field => [$old, $new]) {
                    $parts[] = sprintf('%s : « %s » → « %s »', $field, $old, $new);
                }
                if ($update['reactivated']) {
                    $parts[] = __('de nouveau présente', 'printgestion');
                }
                $updated[] = self::rowKey($type, $update['row']) . ' : ' . implode(' ; ', $parts);
            }
            echo "<h4>" . $esc(__('Lignes modifiées', 'printgestion')) . "</h4>" . $list($updated);

            echo "<h4>" . $esc(__('Lignes absentes du fichier (marquées absentes)', 'printgestion')) . "</h4>"
                . $list(array_map(static fn(array $record) => self::rowKey($type, $record), $preview['absent']));

            echo "<h4 class='mt-3'>" . $esc(__('Rapport d\'écarts', 'printgestion')) . "</h4>";
            foreach ($preview['gaps'] as $gap) {
                $class = $gap['level'] === 'warning' && !empty($gap['items']) ? 'text-warning' : 'text-muted';
                echo "<div class='mb-2'><strong class='{$class}'>" . $esc($gap['title'])
                    . ($gap['level'] === 'warning' ? ' (' . count($gap['items']) . ')' : '') . "</strong>";
                if ($gap['level'] === 'warning') {
                    echo $list($gap['items']);
                }
                echo "</div>";
            }
        }

        echo "<form method='post' action='" . $esc(self::getPageURL()) . "'>";
        echo Html::hidden('token', ['value' => (string) $pending['token']]);

        if ($preview !== null && $type === self::TYPE_CLIENTS) {
            self::showClientLinks($preview['clients']);
        }

        echo "<div class='d-flex gap-2 mt-3'>";
        if ($preview !== null) {
            echo "<button type='submit' name='apply' value='1' class='btn btn-success'>"
                . "<i class='ti ti-check me-1'></i>" . $esc(__('Valider l\'import', 'printgestion')) . "</button>";
        }
        echo "<button type='submit' name='abandon' value='1' class='btn btn-outline-secondary'>"
            . $esc(__('Abandonner', 'printgestion')) . "</button>";
        echo "</div>";
        Html::closeForm();

        echo "</div></div>";
    }

    /**
     * Codes du fichier sans entité liée : suggestion (entité de même nom, cochée) ou choix
     * d'une entité. Les correspondances existantes sont affichées sans être modifiables ici.
     */
    private static function showClientLinks(array $clients): void {
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $unmapped = array_values(array_filter($clients, static fn(array $c) => empty($c['entities'])));
        echo "<h4 class='mt-3'>" . $esc(sprintf(
            __('Correspondance entité ↔ client (%1$d code(s) sans entité sur %2$d)', 'printgestion'),
            count($unmapped),
            count($clients)
        )) . "</h4>";
        echo "<p class='text-muted small'>" . $esc(__('Les correspondances existantes se modifient depuis l\'onglet « Print Gestion — Sage » de l\'entité. Une sous-entité hérite du client de son parent : ne liez que l\'entité de plus haut niveau du client.', 'printgestion')) . "</p>";
        if (empty($unmapped)) {
            return;
        }

        echo "<div class='table-responsive'><table class='table table-sm'><thead><tr>"
            . "<th>" . $esc(__('Code client', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Intitulé', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Lier à l\'entité', 'printgestion')) . "</th></tr></thead><tbody>";
        $dropdowns = 0;
        foreach ($unmapped as $client) {
            $field = 'link[' . sha1(mb_strtoupper($client['code'])) . ']';
            echo "<tr><td><code>" . $esc($client['code']) . "</code></td><td>" . $esc($client['name']) . "</td><td>";
            echo Html::hidden('link_code[' . sha1(mb_strtoupper($client['code'])) . ']', ['value' => $client['code']]);
            if ($client['suggested'] > 0) {
                echo "<label class='form-check mb-0'><input type='checkbox' class='form-check-input' name='{$field}' value='"
                    . (int) $client['suggested'] . "' checked> "
                    . $esc(sprintf(__('%s (même nom)', 'printgestion'), Dropdown::getDropdownName('glpi_entities', $client['suggested'])))
                    . "</label>";
            } elseif ($dropdowns < self::LIST_MAX) {
                Entity::dropdown(['name' => $field, 'value' => -1, 'display_emptychoice' => true, 'emptylabel' => '-----', 'width' => '100%']);
                $dropdowns++;
            } else {
                echo "<span class='text-muted small'>" . $esc(__('À lier depuis l\'onglet de l\'entité', 'printgestion')) . "</span>";
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
    }

    /** Derniers imports réalisés. */
    public static function showHistory(): void {
        global $DB;

        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        echo "<div class='card'><div class='card-header'><h3 class='card-title mb-0'>"
            . $esc(__('Derniers imports', 'printgestion')) . "</h3></div>";

        $imports = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'ORDER' => ['id DESC'],
            'LIMIT' => 20,
        ]), false);
        if (empty($imports)) {
            echo "<div class='card-body text-muted'>" . $esc(__('Aucun import.', 'printgestion')) . "</div></div>";
            return;
        }
        echo "<div class='table-responsive'><table class='table table-sm card-table'><thead><tr>"
            . "<th>" . $esc(__('Date', 'printgestion')) . "</th><th>" . $esc(__('Référentiel', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Fichier', 'printgestion')) . "</th><th>" . $esc(__('Par', 'printgestion')) . "</th>"
            . "<th class='text-end'>" . $esc(__('Nouvelles', 'printgestion')) . "</th><th class='text-end'>" . $esc(__('Modifiées', 'printgestion')) . "</th>"
            . "<th class='text-end'>" . $esc(__('Inchangées', 'printgestion')) . "</th><th class='text-end'>" . $esc(__('Absentes', 'printgestion')) . "</th>"
            . "<th class='text-end'>" . $esc(__('Entités liées', 'printgestion')) . "</th></tr></thead><tbody>";
        foreach ($imports as $import) {
            echo "<tr><td>" . $esc(Html::convDateTime((string) $import['date_creation'])) . "</td>"
                . "<td>" . $esc(self::getTypeLabels()[$import['type']] ?? $import['type']) . "</td>"
                . "<td>" . $esc($import['filename']) . "</td>"
                . "<td>" . $esc(getUserName((int) $import['users_id'])) . "</td>"
                . "<td class='text-end'>" . (int) $import['nb_created'] . "</td><td class='text-end'>" . (int) $import['nb_updated'] . "</td>"
                . "<td class='text-end'>" . (int) $import['nb_unchanged'] . "</td><td class='text-end'>" . (int) $import['nb_absent'] . "</td>"
                . "<td class='text-end'>" . (int) $import['nb_linked'] . "</td></tr>";
        }
        echo "</tbody></table></div></div>";
    }

    public static function getPageURL(): string {
        return PLUGIN_PRINTGESTION_WEBDIR . '/front/sageimport.php';
    }

    // Tables créées par le schéma versionné (PluginPrintgestionSchema, étape 1.4.0).

    static function uninstall(Migration $migration) {
        global $DB;
        foreach ([self::TABLE_MAPPING, self::TABLE_CLIENTS, self::TABLE_DELIVERIES, self::TABLE_ARTICLES, self::getTable()] as $table) {
            $DB->doQuery('DROP TABLE IF EXISTS `' . $table . '`');
        }
        return true;
    }
}
