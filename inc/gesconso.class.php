<?php
/**
 * PluginPrintgestionGesconso — fichier de commande pour l'outil d'import « Gesconso »
 * (Sage 100). Référence : fichier réel Gesconso_02122024_1034.xlsx, importé avec succès.
 *
 * Nom Gesconso_JJMMAAAA_HHMM.xlsx, une feuille « Export », ligne 1 = en-têtes, EXACTEMENT
 * 9 colonnes, une ligne par cartouche :
 *   A Devis                 date de la demande, vraie date Excel (jj/mm/aaaa)
 *   B Intitule Client       code client Sage = nom de l'entité de l'imprimante s'il a la forme
 *                           d'un code, sinon celui du parent le plus proche (PluginPrintgestionSage)
 *   C Intitule Livraison    première ligne des commentaires de cette même entité, celle qui porte le
 *                           code : code et intitulé sont ceux d'un seul client
 *   D Consommable           référence article Sage (CartridgeItem.ref)
 *   E Designation           n° série <séparateur> lieu <séparateur> libellé cartouche, parties vides
 *                           omises (jamais de séparateur orphelin), tronquée à la longueur maximale
 *                           avec avertissement
 *   F Quantite              entier
 *   G Prix                  0 sous contrat ; vide ou prix saisi hors contrat, jamais 0
 *   H Fournisseur           vide
 *   I Complement livraison  texte libre
 *
 * Aucune ligne n'est écrite si son code client, son intitulé de livraison ou sa référence
 * article manque : prepare() la renvoie en erreur, à l'appelant de refuser l'export.
 * Ce qui n'empêche pas d'écrire la ligne mais mérite d'être vu AVANT l'envoi (intitulé absent
 * des adresses importées, imprimante sans lieu) est rendu en « notices », par type et par
 * ligne : les écrans d'envoi en font un décompte, la personne décide.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PluginPrintgestionGesconso {

    /** En-têtes exacts, dans l'ordre (9 colonnes, sans accent : tels que dans le fichier réel). */
    const HEADERS = [
        'A' => 'Devis',
        'B' => 'Intitule Client',
        'C' => 'Intitule Livraison',
        'D' => 'Consommable',
        'E' => 'Designation',
        'F' => 'Quantite',
        'G' => 'Prix',
        'H' => 'Fournisseur',
        'I' => 'Complement livraison',
    ];

    /** Code des exceptions d'archivage : leur message est la cause à afficher. */
    const ARCHIVE_FAILURE         = 4302;
    const SHEET_TITLE             = 'Export';
    const DEFAULT_SEPARATOR       = ' # ';
    const DEFAULT_DESIGNATION_MAX = 69;

    /** Séparateur de la désignation (configuration), espaces compris. */
    public static function getSeparator(): string {
        $separator = (string) (PluginPrintgestionConfig::getInstance()->fields['gesconso_separator'] ?? '');
        return trim($separator) !== '' ? $separator : self::DEFAULT_SEPARATOR;
    }

    /** Longueur maximale de la désignation (configuration, 69 par défaut). */
    public static function getDesignationMax(): int {
        $max = (int) (PluginPrintgestionConfig::getInstance()->fields['gesconso_designation_max'] ?? 0);
        return $max > 0 ? $max : self::DEFAULT_DESIGNATION_MAX;
    }

    /** Gesconso_JJMMAAAA_HHMM.xlsx */
    public static function buildFilename(?int $timestamp = null): string {
        return 'Gesconso_' . date('dmY_Hi', $timestamp ?? time()) . '.xlsx';
    }

    /**
     * Prépare les lignes du fichier et applique les contrôles bloquants avant export.
     *
     * @param array $lines [[
     *     'key'               => string (identifiant de la ligne pour l'appelant),
     *     'label'             => string (libellé des messages, ex. « Imprimante — Toner »),
     *     'printers_id'       => int,
     *     'cartridgeitems_id' => int,
     *     'quantity'          => int,
     *     'unit_price'        => null|string|float (ignoré sous contrat),
     *     'under_contract'    => bool,
     *     'date'              => string (Y-m-d ou Y-m-d H:i:s),
     *     'complement'        => string,
     * ], ...]
     * @return array ['rows' => key => ligne du fichier, 'errors' => key => string[] (bloquants),
     *                'warnings' => string[]]
     */
    /** Types de « notices » (ce qui mérite d'être vu avant l'envoi sans bloquer) : type => [clé de ligne => libellé]. */
    public static function emptyNotices(): array {
        return ['address' => [], 'location' => []];
    }

    /** Fusion de notices (plusieurs demandes sur un même écran d'envoi). */
    public static function mergeNotices(array $into, array $from): array {
        foreach (self::emptyNotices() as $kind => $_) {
            $into[$kind] = ($into[$kind] ?? []) + ($from[$kind] ?? []);
        }
        return $into;
    }

    /**
     * Décompte par type, prêt à afficher : [['kind', 'count', 'text', 'items' => libellés]] pour les types non vides.
     */
    public static function noticesSummary(array $notices): array {
        $texts = [
            'address'  => static fn(int $n) => sprintf(_n('%d ligne avec une adresse de livraison non reconnue', '%d lignes avec une adresse de livraison non reconnue', $n, 'printgestion'), $n),
            'location' => static fn(int $n) => sprintf(_n('%d ligne sans lieu sur l\'imprimante', '%d lignes sans lieu sur l\'imprimante', $n, 'printgestion'), $n),
        ];
        $out = [];
        foreach ($texts as $kind => $text) {
            $items = array_values($notices[$kind] ?? []);
            if (!empty($items)) {
                $out[] = ['kind' => $kind, 'count' => count($items), 'text' => $text(count($items)), 'items' => $items];
            }
        }
        return $out;
    }

    /** Le décompte en HTML : une ligne par type, la liste des lignes derrière « voir ». Chaîne vide s'il n'y a rien. */
    public static function renderNoticesSummary(array $notices, string $id): string {
        $esc     = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $summary = self::noticesSummary($notices);
        if (empty($summary)) {
            return '';
        }
        $html = "<div class='alert alert-warning py-2 mb-3' data-pg-notices='1'><div class='fw-bold mb-1'>"
            . $esc(__('À voir avant l\'envoi (n\'empêche pas la commande) :', 'printgestion')) . "</div>";
        foreach ($summary as $entry) {
            $target = $esc($id . '-' . $entry['kind']);
            $html  .= "<div><i class='ti ti-alert-triangle me-1'></i>" . $esc($entry['text'])
                . " <a class='small' data-bs-toggle='collapse' href='#{$target}' role='button' aria-expanded='false' aria-controls='{$target}'>"
                . $esc(__('voir', 'printgestion')) . "</a>"
                . "<ul class='collapse small mb-1' id='{$target}'>";
            foreach ($entry['items'] as $item) {
                $html .= '<li>' . $esc($item) . '</li>';
            }
            $html .= "</ul></div>";
        }
        return $html . "</div>";
    }

    public static function prepare(array $lines): array {
        global $DB;

        $out       = ['rows' => [], 'errors' => [], 'warnings' => [], 'notices' => self::emptyNotices()];
        $separator = self::getSeparator();
        $max       = self::getDesignationMax();

        $check_articles   = PluginPrintgestionSage::hasReferential(PluginPrintgestionSageimport::TABLE_ARTICLES);
        $check_deliveries = PluginPrintgestionSage::hasReferential(PluginPrintgestionSageimport::TABLE_DELIVERIES);
        if (!$check_articles && !empty($lines)) {
            $out['warnings'][] = __('Référentiel articles Sage non importé : l\'existence des références dans Sage n\'est pas vérifiée.', 'printgestion');
        }
        if (!$check_deliveries && !empty($lines)) {
            $out['warnings'][] = __('Référentiel des adresses de livraison Sage non importé : les intitulés de livraison ne sont pas vérifiés.', 'printgestion');
        }

        foreach ($lines as $line) {
            $key    = (string) $line['key'];
            $label  = (string) $line['label'];
            $errors = [];

            $printer = new Printer();
            if (!$printer->getFromDB((int) $line['printers_id'])) {
                $out['errors'][$key] = [$label . ' : ' . __('imprimante introuvable.', 'printgestion')];
                continue;
            }

            // Code client = nom d'entité en forme de code (hérité du parent), intitulé de livraison = commentaires de l'entité.
            $rule = PluginPrintgestionSage::describeRule((int) $printer->fields['entities_id']);
            if ($rule['client'] === null) {
                $errors[] = sprintf(
                    __('code client Sage absent : ni le nom de l\'entité « %s » ni celui d\'une entité parente n\'a la forme d\'un code client (majuscules et chiffres, sans espace).', 'printgestion'),
                    $rule['entity_name']
                );
            }
            if ($rule['client'] !== null && $rule['label'] === '') {
                $errors[] = sprintf(
                    __('intitulé de livraison absent : champ « Commentaires » de l\'entité « %1$s », qui porte le code client %2$s, vide.', 'printgestion'),
                    $rule['carrier_name'],
                    $rule['client']['code']
                );
            }

            // Référence article.
            $ref            = '';
            $cartridge_name = '';
            $cartridge      = (int) $line['cartridgeitems_id'] > 0
                ? $DB->request([
                    'SELECT' => ['name', 'ref'],
                    'FROM'   => 'glpi_cartridgeitems',
                    'WHERE'  => ['id' => (int) $line['cartridgeitems_id'], 'is_deleted' => 0],
                    'LIMIT'  => 1,
                ])->current()
                : null;
            if (!is_array($cartridge)) {
                $errors[] = __('référence article absente : cartouche non résolue.', 'printgestion');
            } else {
                $ref            = trim((string) $cartridge['ref']);
                $cartridge_name = trim((string) $cartridge['name']);
                if ($ref === '') {
                    $errors[] = sprintf(__('référence article absente : la cartouche « %s » n\'a pas de référence.', 'printgestion'), $cartridge_name);
                } elseif ($check_articles && !PluginPrintgestionSage::isArticleActive($ref)) {
                    $errors[] = sprintf(__('référence « %s » absente du référentiel articles Sage.', 'printgestion'), $ref);
                }
            }

            // Quantité et prix : 0 uniquement sous contrat, jamais 0 hors contrat.
            $quantity = (int) $line['quantity'];
            if ($quantity < 1) {
                $errors[] = __('quantité invalide.', 'printgestion');
            }
            $price = null;
            if (!empty($line['under_contract'])) {
                $price = 0.0;
            } elseif ($line['unit_price'] !== null && $line['unit_price'] !== '') {
                $price = (float) $line['unit_price'];
                if ($price <= 0.0) {
                    $errors[] = __('prix 0 ou négatif sur une ligne hors contrat.', 'printgestion');
                }
            }

            if (!empty($errors)) {
                $out['errors'][$key] = array_map(static fn(string $message) => $label . ' : ' . $message, $errors);
                continue;
            }

            if ($rule['known'] === false) {
                $out['warnings'][] = $label . ' : ' . sprintf(
                    __('intitulé de livraison « %1$s » absent des adresses importées du client %2$s : Sage peut refuser la ligne.', 'printgestion'),
                    $rule['label'],
                    $rule['client']['code']
                );
                $out['notices']['address'][$key] = $label;
            }
            // Désignation : n° série, lieu, libellé cartouche. Espaces intérieurs conservés.
            $serial = trim((string) $printer->fields['serial']);
            if ($serial === '') {
                $out['warnings'][] = $label . ' : ' . __('n° de série absent de la fiche imprimante (début de désignation vide).', 'printgestion');
            }
            // Lieu : son nom propre (dernier niveau), pas le nom complet « Site > … > Pièce ».
            $location      = (int) $printer->fields['locations_id'] > 0
                ? $DB->request([
                    'SELECT' => ['name'],
                    'FROM'   => 'glpi_locations',
                    'WHERE'  => ['id' => (int) $printer->fields['locations_id']],
                    'LIMIT'  => 1,
                ])->current()
                : null;
            $location_name = is_array($location) ? trim((string) $location['name']) : '';
            if ($location_name === '') {
                // Le toner arrivera chez un client qui ne saura pas de quelle machine il s'agit : à voir avant l'envoi.
                $out['warnings'][] = $label . ' : ' . __('lieu absent de la fiche imprimante : la désignation ne dira pas où est la machine.', 'printgestion');
                $out['notices']['location'][$key] = $label;
            }
            // Parties non vides seulement : jamais de séparateur orphelin dans un fichier qui part chez les Achats.
            $designation = implode($separator, array_values(array_filter(
                [$serial, $location_name, $cartridge_name],
                static fn(string $part) => $part !== ''
            )));
            if (mb_strlen($designation) > $max) {
                $out['warnings'][] = sprintf(
                    __('%1$s : désignation tronquée à %2$d caractères (« %3$s »).', 'printgestion'),
                    $label,
                    $max,
                    $designation
                );
                $designation = mb_substr($designation, 0, $max);
            }

            $out['rows'][$key] = [
                'devis'       => substr((string) $line['date'], 0, 10),
                'client'      => $rule['client']['code'],
                'livraison'   => $rule['label'],
                'consommable' => $ref,
                'designation' => $designation,
                'quantite'    => $quantity,
                'prix'        => $price,
                'fournisseur' => '',
                'complement'  => trim((string) $line['complement']),
            ];
        }

        return $out;
    }

    /**
     * Écrit le fichier dans le dossier temporaire GLPI, sous un nom préfixé (unicité) ; le
     * nom Gesconso est rendu à part pour l'archivage et la pièce jointe.
     *
     * @param array $rows Lignes de prepare()['rows'].
     * @return array ['path' => chemin complet, 'filename' => nom Gesconso,
     *                'tmpname' => nom dans GLPI_TMP_DIR, 'prefix' => préfixe du nom temporaire]
     */
    public static function write(array $rows, ?int $timestamp = null): array {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_TITLE);

        foreach (self::HEADERS as $column => $header) {
            $sheet->setCellValueExplicit($column . '1', $header, DataType::TYPE_STRING);
        }

        $line = 2;
        foreach ($rows as $row) {
            // Date Excel (numérique formaté), pas une chaîne.
            $sheet->setCellValue('A' . $line, ExcelDate::dateTimeToExcel(new DateTimeImmutable($row['devis'])));
            $sheet->getStyle('A' . $line)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);

            // Textes explicites : un code ou une référence numérique garde ses zéros.
            foreach (['B' => 'client', 'C' => 'livraison', 'D' => 'consommable', 'E' => 'designation', 'H' => 'fournisseur', 'I' => 'complement'] as $column => $field) {
                if ((string) $row[$field] !== '') {
                    $sheet->setCellValueExplicit($column . $line, (string) $row[$field], DataType::TYPE_STRING);
                }
            }
            $sheet->setCellValue('F' . $line, (int) $row['quantite']);
            if ($row['prix'] !== null) {
                $sheet->setCellValue('G' . $line, (float) $row['prix']);
            }
            $line++;
        }

        foreach (array_keys(self::HEADERS) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = self::buildFilename($timestamp);
        $prefix   = bin2hex(random_bytes(8)) . '_';
        $tmpname  = $prefix . $filename;
        $path     = GLPI_TMP_DIR . '/' . $tmpname;
        (new Xlsx($spreadsheet))->save($path);

        return ['path' => $path, 'filename' => $filename, 'tmpname' => $tmpname, 'prefix' => $prefix];
    }

    /**
     * Archive un fichier écrit par write() en Document GLPI natif, rattaché aux objets
     * donnés, pour pouvoir le renvoyer et prouver ce qui a été transmis.
     *
     * Entité racine, non récursif : le document n'est visible que des utilisateurs de
     * l'entité racine — jamais d'un compte client, un fichier pouvant couvrir plusieurs
     * clients. Le fichier temporaire est conservé (l'appelant le supprime).
     *
     * @param array  $file    Résultat de write().
     * @param array  $items   [[itemtype, items_id], ...]
     * @return int documents_id
     * Bloquant, comme l'échec d'envoi : GLPI qui refuse le fichier le supprime et, sans
     * _only_if_upload_succeed, créerait un document vide ; le mail partait alors aux Achats sans
     * pièce jointe. Une commande n'est jamais passée sans le fichier archivé et relisible.
     *
     * @throws RuntimeException code ARCHIVE_FAILURE (message affichable) si le fichier n'est pas archivé ;
     *                          autre code si un rattachement est refusé par GLPI.
     */
    public static function archive(array $file, string $comment, array $items): int {
        if (!Document::isValidDoc($file['filename'])) {
            throw new RuntimeException(
                __('Fichier Gesconso non archivé : le type de document .xlsx n\'est pas autorisé dans GLPI (Configuration → Intitulés → Types de document, « Autoriser l\'import »). Rien n\'a été enregistré ni envoyé aux Achats.', 'printgestion'),
                self::ARCHIVE_FAILURE
            );
        }
        $document     = new Document();
        $documents_id = (int) $document->add([
            'name'                    => $file['filename'],
            'entities_id'             => 0,
            'is_recursive'            => 0,
            'comment'                 => $comment,
            '_filename'               => [$file['tmpname']],
            '_prefix_filename'        => [$file['prefix']],
            '_only_if_upload_succeed' => 1,
        ]);
        $stored = GLPI_DOC_DIR . '/' . (string) ($document->fields['filepath'] ?? '');
        if ($documents_id <= 0 || (string) ($document->fields['filepath'] ?? '') === '' || !is_file($stored) || !is_readable($file['path'])) {
            PluginPrintgestionLogger::error('gesconso', sprintf('Fichier %s non archivé (document %d, copie %s, fichier temporaire %s).',
                $file['filename'], $documents_id, is_file($stored) ? 'présente' : 'absente', is_readable($file['path']) ? 'présent' : 'absent'));
            throw new RuntimeException(
                sprintf(__('Fichier Gesconso %s non archivé par GLPI (copie dans le dossier des documents en échec, détail dans le journal printgestion). Rien n\'a été enregistré ni envoyé aux Achats.', 'printgestion'), $file['filename']),
                self::ARCHIVE_FAILURE
            );
        }

        foreach ($items as [$itemtype, $items_id]) {
            $link = new Document_Item();
            if (!$link->add([
                'documents_id' => $documents_id,
                'itemtype'     => $itemtype,
                'items_id'     => (int) $items_id,
                'entities_id'  => 0,
            ])) {
                throw new RuntimeException(sprintf('Rattachement du fichier %1$s à %2$s #%3$d refusé par GLPI.', $file['filename'], $itemtype, $items_id));
            }
        }
        return $documents_id;
    }

    /**
     * Après annulation d'une transaction qui avait archivé un fichier : supprime la copie
     * placée dans le dossier des documents si plus aucun document ne la référence.
     */
    public static function removeOrphanArchive(array $document_fields): void {
        $filepath = (string) ($document_fields['filepath'] ?? '');
        if ($filepath === '' || countElementsInTable('glpi_documents', ['filepath' => $filepath]) > 0) {
            return;
        }
        $path = GLPI_DOC_DIR . '/' . $filepath;
        if (is_file($path) && !@unlink($path)) {
            PluginPrintgestionLogger::warning('gesconso', sprintf('Copie orpheline non supprimée : %s', $path));
        }
    }
}
