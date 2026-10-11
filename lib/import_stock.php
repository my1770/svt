<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

require_once dirname(__DIR__) . '/functions.php';
require_once __DIR__ . '/xlsx_helpers.php';

/**
 * Парсер файла остатков «таблица учета цифровой техники N здание.xlsx» (agents.md §8).
 *
 * Возвращает:
 * [
 *   'types'           => [ 'панель irbis', ... ],
 *   'rows'            => [ {room, plan_number, macs:[{mac, type|null}], cells:[ {id, type, number, kind, ambiguous, suggested?} ]}, ... ],
 *   'declared_totals' => { type: count, ... },
 *   'actual_totals'   => { type: count, ... },
 *   'warnings'        => [ '...', ... ],
 * ]
 * kind: 'serial' | 'inventory' | 'none' (б/н). id — сквозной индекс ячейки в payload (для resolutions).
 */
function parseStockFile(string $path): array
{
    $reader = IOFactory::createReader(IOFactory::identify($path));
    $reader->setReadDataOnly(true); // строка итогов содержит формулы — берём кэшированные значения
    $spreadsheet = $reader->load($path);
    $declaredByCol = []; // colIdx => int — кэшированные значения формул строки итогов
    try {
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, false, false, false);
        $macMap = stockExtractMacs($spreadsheet);
        /* Строка 2 (итоги) — формулы SUMPRODUCT; toArray отдаёт текст формулы,
           поэтому читаем кэшированные значения напрямую из ячеек */
        $headerCols = count($rows[0] ?? []);
        for ($ci = 2; $ci < $headerCols; $ci++) {
            $v = $sheet->getCell([$ci + 1, 2])->getOldCalculatedValue();
            if (is_int($v) || is_float($v)) {
                $declaredByCol[$ci] = (int)$v;
                continue;
            }
            $s = xlsxCellStr($v);
            if ($s !== '' && ctype_digit($s)) {
                $declaredByCol[$ci] = (int)$s;
            }
        }
    } finally {
        $spreadsheet->disconnectWorksheets();
    }

    if (count($rows) < 1) {
        throw new RuntimeException('Пустой лист «Основные»');
    }

    /* Строка 1 — шапка: A=кабинет, B=номер по плану, C.. — короткие типы */
    $types = []; // colIdx => short_name
    foreach ($rows[0] as $ci => $v) {
        if ($ci < 2) {
            continue;
        }
        $name = xlsxCellStr($v);
        if ($name !== '') {
            $types[$ci] = $name;
        }
    }

    /* Строка 2 — контрольные итоги по типам */
    $declared = [];
    foreach ($types as $ci => $name) {
        if (isset($declaredByCol[$ci])) {
            $declared[$name] = $declaredByCol[$ci];
        }
    }

    /* Строки 3+ — кабинеты */
    $resultRows = [];
    $warnings = [];
    $actual = [];
    $cellId = 0;
    $rowCount = count($rows);
    for ($i = 2; $i < $rowCount; $i++) {
        $row = $rows[$i];
        $room = xlsxCellStr($row[0] ?? null);
        if ($room === '') {
            continue;
        }
        $roomUp = mb_strtoupper($room, 'UTF-8');
        $plan = xlsxCellStr($row[1] ?? null);
        $cells = [];
        foreach ($types as $ci => $typeName) {
            $rawVal = xlsxCellStr($row[$ci] ?? null);
            if ($rawVal === '') {
                continue;
            }
            $tokens = preg_split('/[\r\n;]+/u', $rawVal) ?: [];
            $tokens = array_values(array_filter(array_map('trim', $tokens), static fn(string $t): bool => $t !== ''));
            if ($tokens === []) {
                continue;
            }
            /* Заглушки-порядковые номера: значения ячейки — последовательность 1..N */
            $isSeq = true;
            $n = 0;
            foreach ($tokens as $t) {
                $n++;
                if (!ctype_digit($t) || (int)$t !== $n) {
                    $isSeq = false;
                    break;
                }
            }
            if ($isSeq) {
                foreach ($tokens as $t) {
                    $cells[] = ['id' => $cellId++, 'type' => $typeName, 'number' => 'б/н', 'kind' => 'none', 'ambiguous' => false];
                    $actual[$typeName] = ($actual[$typeName] ?? 0) + 1;
                }
                continue;
            }
            foreach ($tokens as $tok) {
                $entry = stockClassifyToken($tok, $typeName, $roomUp, $warnings);
                if ($entry === null) {
                    continue;
                }
                $entry['id'] = $cellId++;
                $cells[] = $entry;
                $actual[$typeName] = ($actual[$typeName] ?? 0) + 1;
            }
        }
        $resultRows[] = [
            'room'        => $roomUp,
            'plan_number' => $plan !== '' ? $plan : null,
            'cells'       => $cells,
            'macs'        => $macMap[$roomUp] ?? [],
        ];
    }

    return [
        'types'           => array_values($types),
        'rows'            => $resultRows,
        'declared_totals' => $declared,
        'actual_totals'   => $actual,
        'warnings'        => $warnings,
    ];
}

/**
 * Классификация одного номера из ячейки (§8.2).
 * Возвращает массив ячейки без поля id либо null (значение пропущено, причина в $warnings).
 */
function stockClassifyToken(string $tok, string $type, string $room, array &$warnings): ?array
{
    /* MAC-адрес в ячейке номеров — не номер */
    if (preg_match('/^[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}$/', $tok)) {
        $warnings[] = "Кабинет {$room}, тип «{$type}»: MAC-адрес «{$tok}» в ячейке номеров — пропущен";
        return null;
    }
    /* Локальные метки («ос-375167», «бп-102862», «p1086nc3c3005180474») — инвентарные номера */
    if (preg_match('/^(ос-|бп-)/iu', $tok) || preg_match('/^p[0-9a-z]/i', $tok)) {
        return ['type' => $type, 'number' => $tok, 'kind' => 'inventory', 'ambiguous' => false];
    }
    /* Коды с кириллицей без пробелов («101хххх01») — инвентарный номер как есть */
    if (preg_match('/^[0-9]+[а-яё]+[0-9а-яё]+$/iu', $tok)) {
        return ['type' => $type, 'number' => $tok, 'kind' => 'inventory', 'ambiguous' => false];
    }
    /* Значения с кириллицей */
    if (preg_match('/[а-яё]/iu', $tok)) {
        /* Без пробелов и начинается с цифры — номер с буквами («21сч.5»), а не комментарий:
           сохраняем целиком. Комментарии («21 сч.», «сч 03.31») содержат пробел
           или начинаются с буквы. */
        if (!preg_match('/\s/u', $tok) && preg_match('/^\d/u', $tok)) {
            return ['type' => $type, 'number' => $tok, 'kind' => 'serial', 'ambiguous' => false];
        }
        /* Значения с комментариями: «21 сч.», «сч 03.31» — это ссылки на счёт, а не номера */
        if (preg_match('/(^|\s)сч\.?\s*[\d.]*$/iu', $tok)) {
            $warnings[] = "Кабинет {$room}, тип «{$type}»: значение «{$tok}» пропущено (комментарий со ссылкой на счёт)";
            return null;
        }
        if (preg_match('/^(\d[\dA-Za-z.\-]*)/', $tok, $m) && !preg_match('/[а-яё]/iu', $m[1])) {
            $warnings[] = "Кабинет {$room}, тип «{$type}»: из значения «{$tok}» извлечён номер «{$m[1]}»";
            $tok = $m[1];
        } else {
            $warnings[] = "Кабинет {$room}, тип «{$type}»: значение «{$tok}» пропущено (комментарий без номера)";
            return null;
        }
    }
    /* Чисто числовые — неоднозначны. Десятизначные, начинающиеся на 101, — инвентарные (школьная нумерация) */
    if (ctype_digit($tok)) {
        $suggested = (strlen($tok) === 10 && str_starts_with($tok, '101')) ? 'inventory' : 'serial';
        return ['type' => $type, 'number' => $tok, 'kind' => $suggested, 'ambiguous' => true, 'suggested' => $suggested];
    }
    /* Буквенно-цифровые — серийные номера */
    return ['type' => $type, 'number' => $tok, 'kind' => 'serial', 'ambiguous' => false];
}

/**
 * Лист «Лист2»: A=кабинет, колонки типов (шапка = короткий тип), колонка «mac».
 * Возвращает карту КАБИНЕТ => [ {mac, type|null}, ... ]:
 * type — тип из шапки колонки, если в строке заполнена ровно одна колонка типа.
 */
function stockExtractMacs(PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): array
{
    $sheet = null;
    if ($spreadsheet->sheetNameExists('Лист2')) {
        $sheet = $spreadsheet->getSheetByName('Лист2');
    } elseif ($spreadsheet->getSheetCount() > 1) {
        $sheet = $spreadsheet->getSheet(1);
    }
    if ($sheet === null) {
        return [];
    }
    /* Шапка: колонки типов (colIdx => тип) и колонки MAC */
    $hdrRow = $sheet->rangeToArray('A1:Z1', null, false, false, false);
    $hdr = $hdrRow[0] ?? [];
    $typeCols = []; // colIdx => short type name
    foreach ($hdr as $ci => $v) {
        $h = xlsxCellStr($v);
        if ($ci >= 2 && $h !== '' && mb_strtolower($h, 'UTF-8') !== 'mac') {
            $typeCols[$ci] = $h;
        }
    }
    $map = [];
    $emptyStreak = 0;
    $scanned = 0;
    foreach ($sheet->getRowIterator(2) as $rowObj) {
        if (++$scanned > 2000) {
            break;
        }
        $cellIt = $rowObj->getCellIterator();
        $cellIt->setIterateOnlyExistingCells(false);
        $vals = [];
        foreach ($cellIt as $cell) {
            $vals[] = $cell->getValue();
        }
        $room = xlsxCellStr($vals[0] ?? null);
        if ($room === '') {
            if (++$emptyStreak > 50) {
                break;
            }
            continue;
        }
        $emptyStreak = 0;
        $key = mb_strtoupper($room, 'UTF-8');
        /* Подсказка типа: заполнена ровно одна колонка типа */
        $typesInRow = [];
        foreach ($typeCols as $ci => $typeName) {
            if (xlsxCellStr($vals[$ci] ?? null) !== '') {
                $typesInRow[] = $typeName;
            }
        }
        $hint = count($typesInRow) === 1 ? $typesInRow[0] : null;
        foreach (array_slice($vals, 1) as $v) {
            $s = xlsxCellStr($v);
            if (preg_match('/^[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}$/', $s)) {
                $map[$key][] = ['mac' => strtoupper($s), 'type' => $hint];
            }
        }
    }
    return $map;
}
