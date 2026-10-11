<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

requireAuth();

$buildingId = (int)($_GET['building_id'] ?? 0);
if ($buildingId <= 0) {
    jsonError('Не указано здание', 400);
}

$pdo = db();
$st = $pdo->prepare('SELECT id, address FROM buildings WHERE id = ?');
$st->execute([$buildingId]);
$building = $st->fetch();
if (!$building) {
    jsonError('Здание не найдено', 404);
}

$st = $pdo->prepare(
    'SELECT id, room_number, plan_number FROM rooms
     WHERE building_id = ?
     ORDER BY room_number + 0, room_number'
);
$st->execute([$buildingId]);
$rooms = $st->fetchAll();

$st = $pdo->prepare(
    'SELECT e.id, e.room_id, e.short_type_id, e.long_name_1c, e.serial_number, e.inventory_number,
            st.short_name
     FROM equipment_local e
     LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
     WHERE e.building_id = ? AND e.status = \'active\''
);
$st->execute([$buildingId]);
$equipment = $st->fetchAll();

/* Колонки: все уникальные названия в здании, в алфавитном порядке */
$labelMap = [];
foreach ($equipment as $eq) {
    $label = trim((string)($eq['short_name'] ?? ''));
    if ($label === '') {
        $label = trim((string)($eq['long_name_1c'] ?? ''));
    }
    if ($label === '') {
        continue;
    }
    $key = $eq['short_name'] !== null ? 't:' . (int)$eq['short_type_id'] : 'n:' . $label;
    $labelMap[$label] = $key;
}
uksort($labelMap, static function (string $a, string $b): int {
    return strcmp(mb_strtoupper($a, 'UTF-8'), mb_strtoupper($b, 'UTF-8'));
});
$columns = [];
foreach ($labelMap as $label => $key) {
    $columns[] = ['id' => $key, 'short_name' => $label];
}

$cells = [];
$totals = [];
foreach ($equipment as $eq) {
    $label = trim((string)($eq['short_name'] ?? ''));
    if ($label === '') {
        $label = trim((string)($eq['long_name_1c'] ?? ''));
    }
    if ($label === '') {
        continue;
    }
    $key = $eq['short_name'] !== null ? 't:' . (int)$eq['short_type_id'] : 'n:' . $label;
    $serial = trim((string)($eq['serial_number'] ?? ''));
    $inv    = trim((string)($eq['inventory_number'] ?? ''));
    $number = $serial !== '' ? $serial : ($inv !== '' ? $inv : 'б/н');
    $roomId = $eq['room_id'] !== null ? (int)$eq['room_id'] : 0;
    $cells[$roomId][$key][] = $number;
    $totals[$key] = ($totals[$key] ?? 0) + 1;
}

/* ---------- Генерация Xlsx ---------- */
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Основные');

$lastCol = Coordinate::stringFromColumnIndex(2 + count($columns)); // A, B, типы с C

/* Шапка (строка 1); B1 пустая — как в эталонном файле */
$sheet->setCellValue('A1', 'кабинет');
foreach ($columns as $i => $col) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex(3 + $i) . '1', (string)$col['short_name']);
}
$sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 10],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_TOP,
        'wrapText' => true,
    ],
]);

/* Итоги (строка 2, сразу под шапкой) */
$sheet->setCellValue('B2', 'строит');
foreach ($columns as $i => $col) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex(3 + $i) . '2', (int)($totals[$col['id']] ?? 0));
}
$sheet->getStyle('A2:' . $lastCol . '2')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 10],
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
]);

/* Кабинеты (строки 3+) */
$rowIndex = 3;
foreach ($rooms as $room) {
    $sheet->setCellValue('A' . $rowIndex, (string)$room['room_number']);
    $sheet->setCellValue('B' . $rowIndex, $room['plan_number'] !== null ? (string)$room['plan_number'] : null);
    foreach ($columns as $i => $col) {
        $numbers = $cells[(int)$room['id']][$col['id']] ?? [];
        if ($numbers) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(3 + $i) . $rowIndex, implode("\n", $numbers));
        }
    }
    $rowIndex++;
}
$lastRow = max(2, $rowIndex - 1);

/* Общий стиль: тонкие границы, выравнивание по верху, перенос строк */
$sheet->getStyle('A1:' . $lastCol . $lastRow)->applyFromArray([
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN],
    ],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_TOP,
        'wrapText' => true,
    ],
]);

/* Ширины колонок */
$sheet->getColumnDimension('A')->setWidth(15.62);
$sheet->getColumnDimension('B')->setWidth(5);
for ($i = 0; $i < count($columns); $i++) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex(3 + $i))->setWidth(14);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="report_building_' . $buildingId . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
