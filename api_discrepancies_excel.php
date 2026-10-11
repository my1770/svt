<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

requireRole('admin', 'economist');

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$type     = (string)($_GET['type'] ?? 'all');
$resolved = (string)($_GET['resolved'] ?? '0');
/* Пустое значение = «Все» (старый UI передавал resolved='') */
$type     = $type === '' ? 'all' : $type;
$resolved = $resolved === '' ? 'all' : $resolved;

$allowedTypes = ['all', 'new_arrival', 'write_off', 'unrecorded_move'];
if (!in_array($type, $allowedTypes, true)) {
    jsonError('Недопустимый тип расхождения', 400);
}
if (!in_array($resolved, ['0', '1', 'all'], true)) {
    jsonError('Недопустимый фильтр resolved', 400);
}

$where  = [];
$params = [];
if ($type !== 'all') {
    $where[]  = 'd.type = ?';
    $params[] = $type;
}
if ($resolved !== 'all') {
    $where[]  = 'd.resolved = ?';
    $params[] = (int)$resolved;
}
$buildingId = (int)($_GET['building_id'] ?? 0);
if ($buildingId > 0) {
    $where[] = '(e.building_id = ? OR JSON_EXTRACT(d.details, \'$.building_id\') = ? OR JSON_EXTRACT(d.details, \'$.to.building_id\') = ?)';
    array_push($params, $buildingId, $buildingId, $buildingId);
}

$pdo = db();
$st = $pdo->prepare(
    'SELECT d.id, d.type, d.created_at, d.resolved, d.details,
            e.long_name_1c, e.inventory_number, e.serial_number,
            st.short_name, b.address AS building, r.room_number AS room
     FROM discrepancies d
     LEFT JOIN equipment_local e ON e.id = d.equipment_id
     LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
     LEFT JOIN buildings b ON b.id = e.building_id
     LEFT JOIN rooms r     ON r.id = e.room_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY d.id DESC'
);
$st->execute($params);

$typeLabels = [
    'new_arrival'     => 'Новое поступление',
    'write_off'       => 'Списание',
    'unrecorded_move' => 'Перемещение',
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Расхождения');

$headers = [
    'Тип', 'Дата',
    'Наименование (база)', 'Здание (база)', 'Кабинет (база)', 'Инв. номер (база)', 'Серийный номер (база)',
    'Наименование (1С)', 'Здание (1С)', 'Кабинет (1С)', 'Инв. номер (1С)', 'Серийный номер (1С)',
    'Статус',
];
foreach ($headers as $i => $h) {
    $sheet->setCellValue([$i + 1, 1], $h);
}

$rowIndex = 2;
foreach ($st->fetchAll() as $r) {
    $details = json_decode((string)$r['details'], true);
    if (!is_array($details)) {
        $details = [];
    }
    /* Сторона 1С */
    if ($r['type'] === 'unrecorded_move') {
        $to = $details['to'] ?? [];
        $name1c  = $r['long_name_1c'] ?? '';
        $bld1c   = $to['building'] ?? '';
        $room1c  = $to['room'] ?? '';
        $inv1c   = $r['inventory_number'] ?? '';
        $ser1c   = $r['serial_number'] ?? '';
    } elseif ($r['type'] === 'new_arrival') {
        $name1c = (string)($details['long_name_1c'] ?? '');
        $bld1c  = (string)($details['building'] ?? '');
        $room1c = (string)($details['room'] ?? '');
        $inv1c  = (string)($details['inventory_number'] ?? '');
        $ser1c  = (string)($details['serial_number'] ?? '');
    } else { /* write_off — в 1С отсутствует */
        $name1c = $bld1c = $room1c = $inv1c = $ser1c = '';
    }

    $values = [
        $typeLabels[$r['type']] ?? $r['type'],
        (string)$r['created_at'],
        (string)($r['short_name'] ?? '') !== '' ? (string)$r['short_name'] : (string)($r['long_name_1c'] ?? ''),
        (string)($r['building'] ?? ''),
        (string)($r['room'] ?? ''),
        (string)($r['inventory_number'] ?? ''),
        (string)($r['serial_number'] ?? ''),
        $name1c,
        $bld1c,
        $room1c,
        $inv1c,
        $ser1c,
        (int)$r['resolved'] === 1 ? 'Решено' : 'Нерешено',
    ];
    foreach ($values as $i => $v) {
        $sheet->setCellValue([$i + 1, $rowIndex], $v !== '' ? $v : null);
    }
    $rowIndex++;
}

$lastCol = Coordinate::stringFromColumnIndex(count($headers));
$lastRow = max(1, $rowIndex - 1);
$sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 10],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0F2FE']],
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
]);
$sheet->getStyle('A1:' . $lastCol . $lastRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
]);
foreach (range(1, count($headers)) as $i) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="discrepancies.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
