<?php
require 'vendor/autoload.php'; // pastikan PhpSpreadsheet sudah diinstall via Composer

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Koneksi DB
$db = new PDO("mysql:host=localhost;dbname=book_management_system;charset=utf8mb4", 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ambil data user
$stmt = $db->query("SELECT name, email, kelas, 
    (SELECT level FROM book_levels bl 
     JOIN user_books ub ON ub.book_level_id = bl.id 
     WHERE ub.user_id = users.id LIMIT 1) AS level
    FROM users");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buat spreadsheet baru
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Header
$headers = ['Name', 'Email', 'Kelas', 'Level'];
$sheet->fromArray($headers, NULL, 'A1');

// Isi data mulai dari baris ke-2
$rowNum = 2;
foreach ($data as $row) {
    $sheet->setCellValue("A$rowNum", $row['name']);
    $sheet->setCellValue("B$rowNum", $row['email']);
    $sheet->setCellValue("C$rowNum", $row['kelas']);
    $sheet->setCellValue("D$rowNum", $row['level']);
    $rowNum++;
}

// Styling header
$sheet->getStyle('A1:D1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '0B3A6F']],
    'alignment' => ['horizontal' => 'center']
]);

// Border semua sel
$sheet->getStyle("A1:D" . ($rowNum - 1))->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
]);

// Lebar kolom otomatis
foreach (range('A', 'D') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Output sebagai XLSX
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template_pengguna.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
