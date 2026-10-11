<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$user = requireRole('admin', 'economist');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    apiMappingGet();
}
if ($method === 'POST') {
    requireCsrf();
    apiMappingPost();
}
jsonError('Метод не поддерживается', 405);

/* ---------- GET: незамапленные длинные названия + справочник коротких типов ---------- */
function apiMappingGet(): never
{
    $pdo = db();
    $batchId = trim((string)($_GET['batch_id'] ?? ''));

    $unknown = [];
    if ($batchId !== '') {
        $st = $pdo->prepare('SELECT id, status, payload FROM import_batches WHERE id = ?');
        $st->execute([$batchId]);
        $batch = $st->fetch();
        if (!$batch) {
            jsonError('Пакет импорта не найден', 404);
        }
        $unknown = mappingUnknownFromPayload((string)$batch['payload'], $pdo);
    } else {
        /* Все длинные названия из последних пакетов, ожидающих маппинга */
        $st = $pdo->query("SELECT payload FROM import_batches WHERE status = 'mapping_required' AND kind = '1c' ORDER BY created_at DESC LIMIT 20");
        $counts = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            foreach (mappingUnknownFromPayload((string)$payload, $pdo) as $u) {
                $n = $u['long_name_1c'];
                if (isset($counts[$n])) {
                    $counts[$n]['count'] += $u['count'];
                } else {
                    $counts[$n] = $u;
                }
            }
        }
        $unknown = array_values($counts);
        usort($unknown, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
    }

    $types = [];
    foreach ($pdo->query('SELECT id, short_name FROM equipment_short_types ORDER BY short_name')->fetchAll() as $t) {
        $types[] = ['id' => (int)$t['id'], 'short_name' => (string)$t['short_name']];
    }

    jsonResponse(['ok' => true, 'unknown' => $unknown, 'types' => $types]);
}

/* ---------- POST: сохранение соответствий ---------- */
function apiMappingPost(): never
{
    $in = requestJson();
    $batchId = (string)($in['batch_id'] ?? '');
    if ($batchId === '') {
        jsonError('Требуется batch_id');
    }
    $mappings = $in['mappings'] ?? [];
    if (!is_array($mappings)) {
        jsonError('mappings должен быть массивом');
    }

    $pdo = db();
    $st = $pdo->prepare('SELECT id, status, payload FROM import_batches WHERE id = ?');
    $st->execute([$batchId]);
    $batch = $st->fetch();
    if (!$batch) {
        jsonError('Пакет импорта не найден', 404);
    }

    $selType = $pdo->prepare('SELECT id FROM equipment_short_types WHERE id = ?');
    $selTypeByName = $pdo->prepare('SELECT id FROM equipment_short_types WHERE short_name = ?');
    $insType = $pdo->prepare('INSERT INTO equipment_short_types (short_name) VALUES (?)');
    $selMap = $pdo->prepare('SELECT id FROM equipment_mapping WHERE long_name_1c = ?');
    $insMap = $pdo->prepare('INSERT INTO equipment_mapping (long_name_1c, short_type_id) VALUES (?,?)');
    $updMap = $pdo->prepare('UPDATE equipment_mapping SET short_type_id = ? WHERE id = ?');

    $pdo->beginTransaction();
    try {
        foreach ($mappings as $m) {
            if (!is_array($m)) {
                continue;
            }
            $long = trim((string)($m['long_name_1c'] ?? ''));
            if ($long === '') {
                jsonError('Пустое длинное название в mappings');
            }
            $newName = trim((string)($m['new_short_name'] ?? ''));
            $shortTypeId = null;
            if ($newName !== '') {
                $selTypeByName->execute([$newName]);
                $ex = $selTypeByName->fetch();
                if ($ex) {
                    $shortTypeId = (int)$ex['id'];
                } else {
                    $insType->execute([$newName]);
                    $shortTypeId = (int)$pdo->lastInsertId();
                }
            } elseif (!empty($m['short_type_id'])) {
                $shortTypeId = (int)$m['short_type_id'];
                $selType->execute([$shortTypeId]);
                if (!$selType->fetch()) {
                    jsonError('Короткий тип id=' . $shortTypeId . ' не найден');
                }
            } else {
                jsonError('Для «' . mb_substr($long, 0, 80) . '» не указан тип (short_type_id или new_short_name)');
            }
            $selMap->execute([$long]);
            $exMap = $selMap->fetch();
            if ($exMap) {
                $updMap->execute([$shortTypeId, (int)$exMap['id']]);
            } else {
                $insMap->execute([$long, $shortTypeId]);
            }
        }

        /* Остались ли незамапленные названия в пакете */
        $remaining = count(mappingUnknownFromPayload((string)$batch['payload'], $pdo));
        $status = (string)$batch['status'];
        if ($remaining === 0 && $status === 'mapping_required') {
            $pdo->prepare("UPDATE import_batches SET status = 'awaiting_confirm' WHERE id = ?")->execute([$batchId]);
            $status = 'awaiting_confirm';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        /* jsonError() завершает запрос через exit: незакрытая транзакция откатится при закрытии соединения */
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        appLog('mapping: ошибка сохранения для пакета ' . $batchId . ': ' . $e->getMessage());
        jsonError('Ошибка сохранения маппинга: ' . $e->getMessage(), 500);
    }

    jsonResponse(['ok' => true, 'batch_status' => $status, 'remaining_unknown' => $remaining]);
}

/**
 * Уникальные длинные названия из payload пакета (items), которых нет в equipment_mapping.
 *
 * @return array<int, array{long_name_1c: string, count: int}>
 */
function mappingUnknownFromPayload(string $payloadJson, PDO $pdo): array
{
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
        return [];
    }
    $counts = [];
    foreach ($payload['items'] as $it) {
        $n = (string)($it['long_name_1c'] ?? '');
        if ($n !== '') {
            $counts[$n] = ($counts[$n] ?? 0) + 1;
        }
    }
    if ($counts === []) {
        return [];
    }
    $known = [];
    /* Сравнение регистронезависимое: collation БД (utf8mb4_unicode_ci) нечувствительна
       к регистру, а PHP-lookup по массиву — чувствителен; иначе названия, различающиеся
       только регистром («ноутбук Hp,»/«ноутбук HP,»), навсегда остаются «незамапленными» */
    foreach ($pdo->query('SELECT long_name_1c FROM equipment_mapping')->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $known[mb_strtolower((string)$n, 'UTF-8')] = true;
    }
    $unknown = [];
    foreach ($counts as $n => $c) {
        if (!isset($known[mb_strtolower($n, 'UTF-8')])) {
            $unknown[] = ['long_name_1c' => $n, 'count' => $c];
        }
    }
    usort($unknown, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
    return $unknown;
}
