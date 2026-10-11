<?php
declare(strict_types=1);

/**
 * Общие хелперы для парсеров Excel (lib/import_1c.php, lib/import_stock.php).
 */

/**
 * Приведение значения ячейки к строке: Excel отдаёт номера как int/float,
 * float с нулевой дробной частью — без ".0", обрезка NBSP и пробелов.
 */
function xlsxCellStr(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? '1' : '';
    }
    if (is_int($v)) {
        return (string)$v;
    }
    if (is_float($v)) {
        if (floor($v) === $v && abs($v) < 1e15) {
            return (string)(int)$v;
        }
        return (string)$v;
    }
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d H:i:s');
    }
    $s = (string)$v;
    $trimmed = preg_replace('/^[\s\x{00A0}\x{2007}\x{202F}]+|[\s\x{00A0}\x{2007}\x{202F}]+$/u', '', $s);
    return $trimmed !== null ? $trimmed : trim($s);
}
