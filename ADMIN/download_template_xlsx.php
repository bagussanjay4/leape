<?php
// download_template_xlsx.php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$ss = new Spreadsheet();
$sheet = $ss->getActiveSheet();

// Header WAJIB (case bebas): Name, Email, Kelas, Level
$sheet->setCellValue('A1', 'Name');
$sheet->setCellValue('B1', 'Email');
$sheet->setCellValue('C1', 'Kelas'); // harus sama dengan categories.name
$sheet->setCellValue('D1', 'Level'); // angka (opsional)

// Contoh data (boleh dihapus)
$sheet->fromArray([
    ['Budi', 'budi@example.com', 'Kids', 1],
    ['Sari', 'sari@example.com', 'Teens', 2],
    ['Andi', 'andi@example.com', 'Adults', ''],
], null, 'A2', true);

// Styling ringan
$sheet->getStyle('A1:D1')->getFont()->setBold(true);
foreach (['A'=>22,'B'=>30,'C'=>18,'D'=>10] as $col=>$w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// Output file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Template_Import_Users.xlsx"');
$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
