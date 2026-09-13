<?php
/**
 * PluginPrintgestionGesconso — fichier de commande pour l'outil d'import « Gesconso »
 * (Sage 100). Référence : fichier réel Gesconso_02122024_1034.xlsx, importé avec succès.
 *
 * Nom Gesconso_JJMMAAAA_HHMM.xlsx, une feuille « Export », ligne 1 = en-têtes, EXACTEMENT
 * 9 colonnes, une ligne par cartouche :
 *   A Devis                 date de la demande, vraie date Excel (jj/mm/aaaa)
 *   B Intitule Client       code client Sage (correspondance entité, héritée du parent)
 *   C Intitule Livraison    intitulé de l'adresse de livraison Sage (lieu via Location.code)
 *   D Consommable           référence article Sage (CartridgeItem.ref)
 *   E Designation           n° série <séparateur> lieu <séparateur> libellé cartouche,
 *                           tronquée à la longueur maximale avec avertissement
 *   F Quantite              entier
 *   G Prix                  0 sous contrat ; vide ou prix saisi hors contrat, jamais 0
 *   H Fournisseur           vide
 *   I Complement livraison  texte libre
 *
 * Aucune ligne n'est écrite si son code client, son adresse de livraison ou sa référence
 * article manque : prepare() la renvoie en erreur, à l'appelant de refuser l'export.
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
    public static function prepare(array $lines): array {
        global $DB;

        $out       = ['rows' => [], 'errors' => [], 'warnings' => []];
        $separator = self::getSeparator();
        $max       = self::getDesignationMax();

        $check_articles = PluginPrintgestionSage::hasReferential(PluginPrintgestionSageimport::TABLE_ARTICLES);
        if (!$check_articles && !empty($lines)) {
            $out['warnings'][] = __('Référentiel articles Sage non importé : l\'existence des références dans Sage n\'est pas vérifiée.', 'printgestion');
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

            // Code client et adresse de livraison.
            $entities_id = (int) $printer->fields['entities_id'];
            $client      = PluginPrintgestionSage::getClientForEntity($entities_id);
            $delivery    = null;
            if ($client === null) {
                $errors[] = sprintf(
                    __('code client Sage absent pour l\'entité « %s » (onglet « Print Gestion — Sage » de l\'entité, ou import des clients).', 'printgestion'),
                    Dropdown::getDropdownName('glpi_entities', $entities_id)
                );
            } elseif (!$client['is_in_last_import']) {
                $errors[] = sprintf(__('code client Sage %s absent du dernier import des clients.', 'printgestion'), $client['code']);
            } else {
                $delivery = PluginPrintgestionSage::getDeliveryForLocation((int) $printer->fields['locations_id'], $client['code']);
                if ($delivery === null) {
                    $errors[] = sprintf(
                        __('adresse de livraison absente : ni le lieu de l\'imprimante ni ses parents ne portent le code d\'une adresse du client %s (champ « Code » du lieu, import des adresses).', 'printgestion'),
                        $client['code']
                    );
                }
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

            $designation = implode($separator, [$serial, $location_name, $cartridge_name]);
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
                'client'      => $client['code'],
                'livraison'   => (string) $delivery['label'],
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
     * @throws RuntimeException si le document ou un rattachement est refusé par GLPI.
     */
    public static function archive(array $file, string $comment, array $items): int {
        $document     = new Document();
        $documents_id = (int) $document->add([
            'name'              => $file['filename'],
            'entities_id'       => 0,
            'is_recursive'      => 0,
            'comment'           => $comment,
            '_filename'         => [$file['tmpname']],
            '_prefix_filename'  => [$file['prefix']],
        ]);
        if ($documents_id <= 0) {
            throw new RuntimeException(sprintf('Archivage du fichier %s refusé par GLPI.', $file['filename']));
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
