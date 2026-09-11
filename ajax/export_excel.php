<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_printgestion_billing', CREATE);

$plugin = new Plugin();
if (!$plugin->isInstalled('printgestion') || !$plugin->isActivated('printgestion')) {
    Html::displayNotFoundError();
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$start = (isset($_GET['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start']))
    ? $_GET['start'] : date('Y-m-01');
$end   = (isset($_GET['end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end']))
    ? $_GET['end'] : date('Y-m-d');
$view = $_GET['view'] ?? 'printer';
if (!in_array($view, ['printer', 'client'], true)) {
    $view = 'printer';
}
$entities_id = (isset($_GET['entities_id']) && $_GET['entities_id'] !== '' && (int)$_GET['entities_id'] >= 0)
    ? (int)$_GET['entities_id']
    : null;

$rows           = PluginPrintgestionBilling::computeForPeriod($start, $end, $entities_id);
$rows_by_client = PluginPrintgestionBilling::groupByClient($rows);

$spreadsheet = new Spreadsheet();

$headerStyle = [
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']],
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
];
$totalStyle = ['font' => ['bold' => true]];

if ($view === 'client') {
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Par client');

    $sheet->fromArray(
        ['Client', 'Imprimantes', 'Pages N&B', 'Pages Couleur', 'Coût total (€)'],
        null,
        'A1'
    );
    $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

    $r = 2;
    $tot_cost = 0.0;
    foreach ($rows_by_client as $c) {
        $sheet->fromArray([
            $c['entity_name'],
            (int)$c['printers'],
            (int)$c['pages_nb'],
            (int)$c['pages_color'],
            round((float)$c['total_cost'], 2),
        ], null, "A{$r}");
        $tot_cost += (float)$c['total_cost'];
        $r++;
    }
    $sheet->setCellValue("A{$r}", 'TOTAL');
    $sheet->setCellValue("E{$r}", round($tot_cost, 2));
    $sheet->getStyle("A{$r}:E{$r}")->applyFromArray($totalStyle);
    $sheet->getStyle("E2:E{$r}")->getNumberFormat()->setFormatCode('#,##0.00 "€"');

    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

} else {
    // Vue par imprimante : un onglet par client
    $byEntity = [];
    foreach ($rows as $row) {
        $byEntity[$row['entity_name']][] = $row;
    }

    $first = true;
    foreach ($byEntity as $entity_name => $printers) {
        if ($first) {
            $sheet = $spreadsheet->getActiveSheet();
            $first = false;
        } else {
            $sheet = $spreadsheet->createSheet();
        }
        $clean_title = mb_substr(preg_replace('/[\\\\\/\*\?\[\]\:]/', '_', $entity_name ?: 'Client'), 0, 31);
        $sheet->setTitle($clean_title);

        $sheet->fromArray([
            'Imprimante', 'Contrat', 'Pages N&B', 'Pages Couleur',
            'Tarif N&B', 'Tarif Couleur', 'Coût total (€)',
        ], null, 'A1');
        $sheet->getStyle('A1:G1')->applyFromArray($headerStyle);

        $r = 2;
        $tot_nb = $tot_col = 0;
        $tot_cost = 0.0;
        foreach ($printers as $p) {
            $sheet->fromArray([
                $p['printer_name'],
                $p['contract_name'],
                (int)$p['pages_nb'],
                (int)$p['pages_color'],
                round((float)$p['rate_nb'], 6),
                round((float)$p['rate_color'], 6),
                round((float)$p['total_cost'], 2),
            ], null, "A{$r}");
            $tot_nb   += (int)$p['pages_nb'];
            $tot_col  += (int)$p['pages_color'];
            $tot_cost += (float)$p['total_cost'];
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("C{$r}", $tot_nb);
        $sheet->setCellValue("D{$r}", $tot_col);
        $sheet->setCellValue("G{$r}", round($tot_cost, 2));
        $sheet->getStyle("A{$r}:G{$r}")->applyFromArray($totalStyle);
        $sheet->getStyle("G2:G{$r}")->getNumberFormat()->setFormatCode('#,##0.00 "€"');
        if ($r > 2) {
            $sheet->getStyle("E2:F" . ($r - 1))->getNumberFormat()->setFormatCode('0.000000');
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    if ($first) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Vide');
        $sheet->setCellValue('A1', __('Aucune donnée sur la période', 'printgestion'));
    }
}

$filename = 'Print Gestion_' . $start . '_' . $end . '.xlsx';

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
