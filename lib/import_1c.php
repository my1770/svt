<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

require_once dirname(__DIR__) . '/functions.php';
require_once __DIR__ . '/xlsx_helpers.php';

/**
 * Парсер выгрузки 1С «Основной.xlsx» (agents.md §5).
 *
 * Возвращает:
 * [
 *   'items'            => [ {row, employee, building, room, long_name_1c, inventory_number, serial_number, device_type_1c}, ... ],
 *   'skipped'          => [ {row, ..., reason:'duplicate', kept_row}, ... ],
 *   'conflicts'        => [ {inventory_number, rows:[...]}, ... ],
 *   'unknown_mappings' => [ {long_name_1c, count}, ... ],
 *   'stats'            => {rows, unique_items, duplicates_skipped, conflicts},
 * ]
 */
function parse1cFile(string $path): array
{
    $spreadsheet = IOFactory::load($path);
    try {
        $rows = $spreadsheet->getSheet(0)->toArray(null, false, false, false);
    } finally {
        $spreadsheet->disconnectWorksheets();
    }

    /* Строка заголовков: содержит «Организация» и «Сотрудник» */
    $headerIdx = null;
    foreach ($rows as $i => $row) {
        $hasOrg = false;
        $hasEmp = false;
        foreach ($row as $v) {
            $s = xlsxCellStr($v);
            if ($s === '') {
                continue;
            }
            if (mb_strpos($s, 'Организация') !== false) {
                $hasOrg = true;
            }
            if (mb_strpos($s, 'Сотрудник') !== false) {
                $hasEmp = true;
            }
        }
        if ($hasOrg && $hasEmp) {
            $headerIdx = $i;
            break;
        }
    }
    if ($headerIdx === null) {
        throw new RuntimeException('Не найдена строка заголовков («Организация»/«Сотрудник») — это точно выгрузка 1С?');
    }

    /* Данные: строки с непустой колонкой 10 (0-based) */
    $raw = [];
    $total = count($rows);
    for ($i = $headerIdx + 1; $i < $total; $i++) {
        $row = $rows[$i];
        $name = xlsxCellStr($row[10] ?? null);
        if ($name === '') {
            continue;
        }
        $mol  = xlsxCellStr($row[3] ?? null);
        $addr = xlsxCellStr($row[7] ?? null);
        $room = xlsxCellStr($row[8] ?? null);
        $inv  = xlsxCellStr($row[11] ?? null);
        $ser  = xlsxCellStr($row[12] ?? null);
        $dev  = xlsxCellStr($row[13] ?? null);
        /* Служебная строка-артефакт («Средство вычислительной техники, Элемент комплекса»): только название, без номеров и координат */
        if ($inv === '' && $ser === '' && $mol === '' && $addr === '' && $room === '') {
            continue;
        }
        $raw[] = [
            'row'              => $i + 1,
            'employee'         => $mol !== '' ? $mol : null,
            'building'         => $addr !== '' ? $addr : null,
            'room'             => $room !== '' ? mb_strtoupper($room, 'UTF-8') : null,
            'long_name_1c'     => $name,
            'inventory_number' => $inv,
            'serial_number'    => $ser,
            'device_type_1c'   => $dev !== '' ? $dev : null,
        ];
    }

    /* Дедупликация по инвентарному номеру (§5.2, вариант А) */
    $items = [];
    $groups = [];
    foreach ($raw as $r) {
        if ($r['inventory_number'] === '') {
            $items[] = $r;
            continue;
        }
        $groups[$r['inventory_number']][] = $r;
    }
    $skipped = [];
    $conflicts = [];
    foreach ($groups as $inv => $g) {
        $keepIdx = null;
        $serCount = 0;
        foreach ($g as $idx => $r) {
            if ($r['serial_number'] !== '') {
                $serCount++;
                $keepIdx = $idx;
            }
        }
        if ($serCount >= 2) {
            $conflicts[] = ['inventory_number' => $inv, 'rows' => $g];
            continue;
        }
        if ($keepIdx === null) {
            $keepIdx = 0;
        }
        $items[] = $g[$keepIdx];
        foreach ($g as $idx => $r) {
            if ($idx === $keepIdx) {
                continue;
            }
            $r['reason'] = 'duplicate';
            $r['kept_row'] = $g[$keepIdx]['row'];
            $skipped[] = $r;
        }
    }

    /* Проверка маппинга длинных названий */
    $nameCounts = [];
    foreach ($items as $it) {
        $n = $it['long_name_1c'];
        $nameCounts[$n] = ($nameCounts[$n] ?? 0) + 1;
    }
    $known = [];
    /* Регистронезависимое сравнение (collation БД нечувствительна к регистру) */
    $st = db()->query('SELECT long_name_1c FROM equipment_mapping');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $known[mb_strtolower((string)$n, 'UTF-8')] = true;
    }
    $unknown = [];
    foreach ($nameCounts as $n => $c) {
        if (!isset($known[mb_strtolower($n, 'UTF-8')])) {
            $unknown[] = ['long_name_1c' => $n, 'count' => $c];
        }
    }
    usort($unknown, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

    return [
        'items'            => $items,
        'skipped'          => $skipped,
        'conflicts'        => $conflicts,
        'unknown_mappings' => $unknown,
        'stats'            => [
            'rows'               => count($raw),
            'unique_items'       => count($items),
            'duplicates_skipped' => count($skipped),
            'conflicts'          => count($conflicts),
        ],
    ];
}
