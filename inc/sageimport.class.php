<?php
/**
 * PluginPrintgestionSageimport — import du référentiel Sage par dépôt de fichier.
 *
 * Aucune liaison directe avec Sage : un fichier exporté de Sage (xlsx, xls, ods ou csv) est
 * déposé à la main, analysé, prévisualisé avec son rapport d'écarts, puis validé. Un
 * référentiel par fichier :
 *   - adresses de livraison : plusieurs par client (code client, intitulé, code adresse) ;
 *   - articles              : référence Sage ↔ CartridgeItem.ref.
 * Ni l'un ni l'autre ne décide de rien : le code client est le nom de l'entité et l'intitulé de
 * livraison ses commentaires (PluginPrintgestionSage) ; les référentiels servent à vérifier. L'import
 * ne modifie aucun objet GLPI. Les écarts (entités sans code ou sans intitulé, intitulés absents du
 * fichier, cartouches sans référence connue…) sont signalés pour être corrigés dans GLPI.
 * Une ligne absente d'un import suivant n'est jamais supprimée : elle est marquée absente
 * (is_in_last_import = 0) et ne sert plus à la vérification.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginPrintgestionSageimport extends CommonDBTM {

    static $rightname = 'plugin_printgestion_config';

    const TYPE_DELIVERIES = 'deliveries';
    const TYPE_ARTICLES   = 'articles';

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
            self::TYPE_DELIVERIES => __('Adresses de livraison', 'printgestion'),
            self::TYPE_ARTICLES   => __('Articles', 'printgestion'),
        ];
    }

    public static function getReferentialTable(string $type): string {
        return [
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

            $code = $row['client_code'] ?? null;
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
     *                'gaps' => [['title', 'level' (warning|info), 'items' => string[]]]]
     */
    public static function computePreview(string $type, array $rows): array {
        global $DB;

        $preview = ['created' => [], 'updated' => [], 'unchanged' => 0, 'absent' => [], 'gaps' => []];
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
            case self::TYPE_DELIVERIES:
                // Ce que l'export produira pour chaque entité à imprimantes (périmètre de l'utilisateur), face au fichier.
                $in_file = [];
                foreach ($rows as $row) {
                    $in_file[mb_strtoupper($row['client_code']) . '|' . mb_strtoupper($row['label'])] = true;
                }
                $entities     = self::entitiesWithPrinters();
                $entity_codes = [];
                foreach ($entities as $entity) {
                    if ($entity['code'] !== null) {
                        $entity_codes[mb_strtoupper($entity['code'])] = true;
                    }
                }
                $unknown_client = [];
                foreach ($rows as $row) {
                    if (!isset($entity_codes[mb_strtoupper($row['client_code'])])) {
                        $unknown_client[] = sprintf('%s — %s (%s)', $row['client_code'], $row['label'], $row['address_key']);
                    }
                }
                $no_code     = [];
                $no_label    = [];
                $not_in_file = [];
                foreach ($entities as $entity) {
                    $where = sprintf(__('%1$s (%2$d imprimante(s))', 'printgestion'), $entity['completename'], $entity['nb']);
                    if ($entity['code'] === null) {
                        $no_code[] = $where;
                    }
                    if ($entity['label'] === '') {
                        $no_label[] = $where;
                    }
                    if ($entity['code'] !== null && $entity['label'] !== ''
                        && !isset($in_file[mb_strtoupper($entity['code']) . '|' . mb_strtoupper($entity['label'])])) {
                        $not_in_file[] = sprintf('%s — %s « %s »', $where, $entity['code'], $entity['label']);
                    }
                }
                $preview['gaps'][] = [
                    'title' => __('Adresses dont le code client n\'est le code d\'aucune entité à imprimantes de votre périmètre', 'printgestion'),
                    'level' => 'warning',
                    'items' => $unknown_client,
                ];
                $preview['gaps'][] = [
                    'title' => __('Entités avec imprimantes sans code client Sage (ni leur nom ni celui d\'un parent n\'a la forme d\'un code) : export bloqué', 'printgestion'),
                    'level' => 'warning',
                    'items' => $no_code,
                ];
                $preview['gaps'][] = [
                    'title' => __('Entités avec imprimantes sans intitulé de livraison (champ « Commentaires » vide) : export bloqué', 'printgestion'),
                    'level' => 'warning',
                    'items' => $no_label,
                ];
                $preview['gaps'][] = [
                    'title' => __('Entités avec imprimantes dont l\'intitulé de livraison n\'est pas une adresse de leur client dans ce fichier', 'printgestion'),
                    'level' => 'warning',
                    'items' => $not_in_file,
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

    /**
     * Entités portant des imprimantes (périmètre de l'utilisateur), avec la règle Gesconso appliquée :
     * code client (nom d'entité en forme de code, hérité) et intitulé de livraison (commentaires).
     *
     * @return array[] ['id', 'completename', 'nb', 'code' => ?string, 'label' => string]
     */
    private static function entitiesWithPrinters(): array {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'     => ['e.id', 'e.completename', new QueryExpression('COUNT(`p`.`id`) AS `nb`')],
            'FROM'       => 'glpi_printers AS p',
            'INNER JOIN' => ['glpi_entities AS e' => ['ON' => ['p' => 'entities_id', 'e' => 'id']]],
            // Rapport limité aux entités de l'utilisateur : jamais le nom d'une entité hors de son périmètre.
            'WHERE'      => ['p.is_deleted' => 0, 'p.is_template' => 0] + getEntitiesRestrictCriteria('p', '', '', true),
            'GROUPBY'    => ['e.id', 'e.completename'],
            'ORDER'      => ['e.completename'],
        ]) as $entity) {
            $rule  = PluginPrintgestionSage::describeRule((int) $entity['id']);
            $out[] = [
                'id'           => (int) $entity['id'],
                'completename' => (string) $entity['completename'],
                'nb'           => (int) $entity['nb'],
                'code'         => $rule['client']['code'] ?? null,
                'label'        => $rule['label'],
            ];
        }
        return $out;
    }

    // ── Validation de l'import ────────────────────────────────────────────────

    /**
     * Applique un import analysé et prévisualisé, tout ou rien (transaction) : création ou
     * mise à jour des lignes du fichier, lignes absentes marquées absentes (jamais
     * supprimées), trace de l'import. Aucun objet GLPI modifié.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'counts' => array]
     */
    public static function apply(string $type, array $rows, string $filename): array {
        global $DB;

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'absent' => 0];

        $table  = self::getReferentialTable($type);
        $fields = array_keys(self::getColumns($type));
        $now    = $_SESSION['glpi_currenttime'];

        try {
            PluginPrintgestionDemande::transactional(function () use ($type, $rows, $filename, $table, $fields, $now, &$counts) {
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

                $DB->insert(self::getTable(), [
                    'type'          => $type,
                    'filename'      => mb_substr($filename, 0, 255),
                    'users_id'      => (int) Session::getLoginUserID(),
                    'nb_created'    => $counts['created'],
                    'nb_updated'    => $counts['updated'],
                    'nb_unchanged'  => $counts['unchanged'],
                    'nb_absent'     => $counts['absent'],
                    'nb_linked'     => 0,
                    'date_creation' => $now,
                ]);
            });
        } catch (Throwable $e) {
            PluginPrintgestionLogger::error('sage-import', sprintf('Import %s du fichier %s annulé.', $type, $filename), $e);
            return [
                'ok'     => false,
                'errors' => [__('Import annulé (erreur technique, détail dans le journal printgestion) : rien n\'a été modifié.', 'printgestion')],
                'counts' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'absent' => 0],
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
        echo "<div class='col-md-3'><button type='submit' data-pg-submit-once='1' name='analyze' value='1' class='btn btn-primary'>"
            . "<i class='ti ti-file-search me-1'></i>" . $esc(__('Analyser', 'printgestion')) . "</button></div>";
        Html::closeForm();

        echo "<div class='table-responsive'><table class='table table-sm'><thead><tr>"
            . "<th>" . $esc(__('Référentiel', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Colonnes (obligatoires en gras) et en-têtes acceptés', 'printgestion')) . "</th>"
            . "<th>" . $esc(__('Rapprochement GLPI', 'printgestion')) . "</th></tr></thead><tbody>";
        $matching = [
            self::TYPE_DELIVERIES => __('Vérification seulement : l\'export prend le code client dans le nom de l\'entité (ou d\'un parent) et l\'intitulé de livraison dans ses commentaires ; un intitulé absent des adresses de son client donne un avertissement.', 'printgestion'),
            self::TYPE_ARTICLES   => __('Cartouche GLPI dont la référence vaut la référence article ; une référence absente du référentiel bloque la ligne.', 'printgestion'),
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


        echo "<div class='d-flex gap-2 mt-3'>";
        if ($preview !== null) {
            echo "<button type='submit' data-pg-submit-once='1' name='apply' value='1' class='btn btn-success'>"
                . "<i class='ti ti-check me-1'></i>" . $esc(__('Valider l\'import', 'printgestion')) . "</button>";
        }
        echo "<button type='submit' name='abandon' value='1' class='btn btn-outline-secondary'>"
            . $esc(__('Abandonner', 'printgestion')) . "</button>";
        echo "</div>";
        Html::closeForm();

        echo "</div></div>";
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
        $entries = [];
        foreach ($imports as $import) {
            $entries[] = [
                'date'      => Html::convDateTime((string) $import['date_creation']),
                'type'      => self::getTypeLabels()[$import['type']] ?? $import['type'],
                'file'      => $import['filename'],
                'user'      => getUserName((int) $import['users_id']),
                'created'   => (int) $import['nb_created'],
                'updated'   => (int) $import['nb_updated'],
                'unchanged' => (int) $import['nb_unchanged'],
                'absent'    => (int) $import['nb_absent'],
            ];
        }
        echo PluginPrintgestionUi::datatable([
            'date'      => __('Date', 'printgestion'),
            'type'      => __('Référentiel', 'printgestion'),
            'file'      => __('Fichier', 'printgestion'),
            'user'      => __('Par', 'printgestion'),
            'created'   => __('Nouvelles', 'printgestion'),
            'updated'   => __('Modifiées', 'printgestion'),
            'unchanged' => __('Inchangées', 'printgestion'),
            'absent'    => __('Absentes', 'printgestion'),
        ], $entries, ['created' => 'integer', 'updated' => 'integer', 'unchanged' => 'integer', 'absent' => 'integer']);
        echo "</div>";
    }

    public static function getPageURL(): string {
        return PLUGIN_PRINTGESTION_WEBDIR . '/front/sageimport.php';
    }

    // Tables créées par PluginPrintgestionSchema.

    static function uninstall(Migration $migration) {
        global $DB;
        // Les deux premières (correspondance entité ↔ client, versions de développement) ne sont plus créées : IF EXISTS.
        foreach (['glpi_plugin_printgestion_entitysageclients', 'glpi_plugin_printgestion_sageclients',
            self::TABLE_DELIVERIES, self::TABLE_ARTICLES, self::getTable()] as $table) {
            $DB->dropTable($table, true);
        }
        return true;
    }
}
