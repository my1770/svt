<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/uuid.php';
require_once __DIR__ . '/lib/import_stock.php';

$user = requireRole('admin', 'economist');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Метод не поддерживается', 405);
}
requireCsrf();

$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if (stripos($contentType, 'application/json') === 0) {
    apiImportStockConfirm($user);
} else {
    apiImportStockUpload();
}

/* ---------- Загрузка файла остатков и разбор ---------- */
function apiImportStockUpload(): never
{
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        jsonError('Файл не передан (поле file)');
    }
    $f = $_FILES['file'];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonError('Ошибка загрузки файла (код ' . (int)$f['error'] . ')');
    }
    if ((int)($f['size'] ?? 0) > MAX_FILE_SIZE) {
        jsonError('Файл слишком большой (максимум ' . (int)(MAX_FILE_SIZE / 1048576) . ' МБ)');
    }
    if ($err = validateXlsxUpload($f)) {
        jsonError($err);
    }
    $origName = (string)($f['name'] ?? '');

    /* Здание: существующее (building_id) или новое (new_address) */
    $pdo = db();
    $rawBuildingId = trim((string)($_POST['building_id'] ?? ''));
    $newAddress = trim((string)($_POST['new_address'] ?? ''));
    $buildingId = null;
    if ($newAddress !== '') {
        $st = $pdo->prepare('SELECT id FROM buildings WHERE address = ?');
        $st->execute([$newAddress]);
        $ex = $st->fetch();
        if ($ex) {
            $buildingId = (int)$ex['id'];
        } else {
            $pdo->prepare('INSERT INTO buildings (address) VALUES (?)')->execute([$newAddress]);
            $buildingId = (int)$pdo->lastInsertId();
        }
    } elseif ($rawBuildingId !== '' && ctype_digit($rawBuildingId)) {
        $st = $pdo->prepare('SELECT id FROM buildings WHERE id = ?');
        $st->execute([(int)$rawBuildingId]);
        if (!$st->fetch()) {
            jsonError('Здание не найдено', 404);
        }
        $buildingId = (int)$rawBuildingId;
    } else {
        jsonError('Укажите building_id существующего здания или new_address для создания нового');
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true) && !is_dir(UPLOAD_DIR)) {
        jsonError('Не удалось создать каталог загрузок', 500);
    }
    $batchId = uuid4();
    $dest = UPLOAD_DIR . $batchId . '.xlsx';
    if (!move_uploaded_file((string)$f['tmp_name'], $dest)) {
        jsonError('Не удалось сохранить загруженный файл', 500);
    }
    try {
        $parsed = parseStockFile($dest);
    } catch (Throwable $e) {
        appLog('import_stock: ошибка разбора ' . $origName . ': ' . $e->getMessage());
        jsonError('Не удалось разобрать файл: ' . $e->getMessage(), 422);
    }

    /* Статистика и контроль итогов */
    $items = 0;
    $bnCount = 0;
    $macCount = 0;
    $ambiguous = [];
    $bn = [];
    foreach ($parsed['rows'] as $r) {
        $macCount += count($r['macs']);
        foreach ($r['cells'] as $c) {
            $items++;
            if ($c['kind'] === 'none') {
                $bnCount++;
                $k = $r['room'] . '|' . $c['type'];
                if (!isset($bn[$k])) {
                    $bn[$k] = ['room' => $r['room'], 'short_type' => $c['type'], 'count' => 0];
                }
                $bn[$k]['count']++;
            }
            if (!empty($c['ambiguous'])) {
                $ambiguous[] = [
                    'id'         => $c['id'],
                    'room'       => $r['room'],
                    'short_type' => $c['type'],
                    'value'      => $c['number'],
                    'suggested'  => $c['suggested'] ?? 'serial',
                ];
            }
        }
    }
    $stats = [
        'rooms'     => count($parsed['rows']),
        'items'     => $items,
        'bn_count'  => $bnCount,
        'mac_count' => $macCount,
    ];
    $mismatches = [];
    $allTypes = array_unique(array_merge(array_keys($parsed['declared_totals']), array_keys($parsed['actual_totals'])));
    foreach ($allTypes as $t) {
        $d = (int)($parsed['declared_totals'][$t] ?? 0);
        $a = (int)($parsed['actual_totals'][$t] ?? 0);
        if ($d !== $a) {
            $mismatches[] = ['type' => $t, 'declared' => $d, 'actual' => $a];
        }
    }

    $payload = $parsed;
    $payload['building_id'] = $buildingId;
    $pdo->prepare('INSERT INTO import_batches (id, kind, filename, building_id, status, stats, payload) VALUES (?,?,?,?,?,?,?)')
        ->execute([
            $batchId,
            'stock',
            $origName,
            $buildingId,
            'awaiting_confirm',
            json_encode($stats, JSON_UNESCAPED_UNICODE),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

    foreach ($parsed['warnings'] as $w) {
        importLog($batchId, 'warning', $w);
    }

    jsonResponse([
        'ok'           => true,
        'batch_id'     => $batchId,
        'stats'        => $stats,
        'totals_check' => ['ok' => $mismatches === [], 'mismatches' => $mismatches],
        'ambiguous'    => $ambiguous,
        'bn'           => array_values($bn),
    ]);
}

/* ---------- Подтверждение: создание кабинетов и техники ---------- */
function apiImportStockConfirm(array $user): never
{
    $in = requestJson();
    $batchId = (string)($in['batch_id'] ?? '');
    if ($batchId === '' || empty($in['confirm'])) {
        jsonError('Требуются batch_id и confirm=true');
    }
    $resolutions = [];
    foreach ((array)($in['resolutions'] ?? []) as $r) {
        if (!is_array($r) || !isset($r['id'])) {
            continue;
        }
        $kind = (string)($r['kind'] ?? '');
        if (!in_array($kind, ['serial', 'inventory'], true)) {
            jsonError('Недопустимый kind в resolutions (ожидается serial или inventory)');
        }
        $resolutions[(int)$r['id']] = $kind;
    }

    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM import_batches WHERE id = ? AND kind = 'stock'");
    $st->execute([$batchId]);
    $batch = $st->fetch();
    if (!$batch) {
        jsonError('Пакет импорта не найден', 404);
    }
    if ($batch['status'] === 'completed') {
        jsonError('Пакет уже обработан', 409);
    }
    if ($batch['status'] !== 'awaiting_confirm') {
        jsonError('Пакет в статусе «' . $batch['status'] . '», подтверждение невозможно', 409);
    }
    $payload = json_decode((string)$batch['payload'], true);
    if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
        jsonError('Повреждённые данные пакета', 500);
    }
    $buildingId = (int)$payload['building_id'];

    $roomsCreated = 0;
    $equipmentCreated = 0;
    $duplicates = 0;
    $duplicatesList = [];

    $pdo->beginTransaction();
    try {
        /* Кабинеты */
        $selRoom = $pdo->prepare('SELECT id, plan_number FROM rooms WHERE building_id = ? AND room_number = ?');
        $insRoom = $pdo->prepare('INSERT INTO rooms (building_id, room_number, plan_number, mol_user_id) VALUES (?,?,?,?)');
        $updPlan = $pdo->prepare('UPDATE rooms SET plan_number = ? WHERE id = ? AND plan_number IS NULL');
        /* Новые кабинеты наследуют МОЛ здания */
        $bMolSt = $pdo->prepare('SELECT mol_user_id FROM buildings WHERE id = ?');
        $bMolSt->execute([$buildingId]);
        $bMol = $bMolSt->fetchColumn() ?: null;
        $roomIds = [];
        foreach ($payload['rows'] as $r) {
            $room = (string)$r['room'];
            if (isset($roomIds[$room])) {
                continue;
            }
            $plan = isset($r['plan_number']) && $r['plan_number'] !== '' ? (string)$r['plan_number'] : null;
            $selRoom->execute([$buildingId, $room]);
            $ex = $selRoom->fetch();
            if ($ex) {
                $roomIds[$room] = (int)$ex['id'];
                if ($plan !== null) {
                    $updPlan->execute([$plan, (int)$ex['id']]);
                }
                continue;
            }
            $insRoom->execute([$buildingId, $room, $plan, $bMol]);
            $roomIds[$room] = (int)$pdo->lastInsertId();
            $roomsCreated++;
        }

        /* Короткие типы */
        $selType = $pdo->prepare('SELECT id FROM equipment_short_types WHERE short_name = ?');
        $insType = $pdo->prepare('INSERT INTO equipment_short_types (short_name) VALUES (?)');
        $typeIds = [];
        foreach ((array)($payload['types'] ?? []) as $name) {
            $name = (string)$name;
            if ($name === '') {
                continue;
            }
            $selType->execute([$name]);
            $ex = $selType->fetch();
            if ($ex) {
                $typeIds[$name] = (int)$ex['id'];
            } else {
                $insType->execute([$name]);
                $typeIds[$name] = (int)$pdo->lastInsertId();
            }
        }

        /* Техника */
        $selDupSer = $pdo->prepare('SELECT id FROM equipment_local WHERE building_id = ? AND serial_number = ? LIMIT 1');
        $selDupInv = $pdo->prepare('SELECT id FROM equipment_local WHERE building_id = ? AND inventory_number = ? LIMIT 1');
        $insEq = $pdo->prepare(
            "INSERT INTO equipment_local
                (long_name_1c, short_type_id, inventory_number, serial_number, mac_address, building_id, room_id, employee_id, status)
             VALUES ('', ?, ?, ?, ?, ?, ?, 1, 'active')"
        );
        foreach ($payload['rows'] as $r) {
            $room = (string)$r['room'];
            $roomId = $roomIds[$room];
            $macPool = array_values((array)($r['macs'] ?? []));
            foreach ((array)$r['cells'] as $c) {
                $kind = (string)$c['kind'];
                if (!empty($c['ambiguous'])) {
                    $cid = (int)$c['id'];
                    if (isset($resolutions[$cid])) {
                        $kind = $resolutions[$cid];
                    } else {
                        $kind = (string)($c['suggested'] ?? 'serial');
                        importLog($batchId, 'warning', "Неоднозначный номер «{$c['number']}» ({$r['room']}, {$c['type']}) без решения оператора — принят как {$kind}");
                    }
                }
                $number = (string)$c['number'];
                $inv = null;
                $ser = null;
                if ($kind === 'none' || $number === 'б/н') {
                    $ser = 'б/н';
                } elseif ($kind === 'inventory') {
                    $inv = $number;
                } else {
                    $ser = $number;
                }

                /* Идемпотентность: не дублируем существующую технику этого здания */
                if ($ser !== null && $ser !== 'б/н') {
                    $selDupSer->execute([$buildingId, $ser]);
                    if ($selDupSer->fetch()) {
                        $duplicates++;
                        $duplicatesList[] = ['room' => $room, 'short_type' => $c['type'], 'number' => $ser, 'kind' => 'serial'];
                        importLog($batchId, 'warning', "Дубликат: с/н «{$ser}» ({$room}, {$c['type']}) уже есть в этом здании — пропущено");
                        continue;
                    }
                }
                if ($inv !== null) {
                    $selDupInv->execute([$buildingId, $inv]);
                    if ($selDupInv->fetch()) {
                        $duplicates++;
                        $duplicatesList[] = ['room' => $room, 'short_type' => $c['type'], 'number' => $inv, 'kind' => 'inventory'];
                        importLog($batchId, 'warning', "Дубликат: инв. № «{$inv}» ({$room}, {$c['type']}) уже есть в этом здании — пропущено");
                        continue;
                    }
                }

                /* MAC: сначала совпадающий по типу, затем без типа; чужой тип не присваиваем */
                $mac = null;
                foreach ($macPool as $mk => $m) {
                    $mType = is_array($m) ? ($m['type'] ?? null) : null;
                    if ($mType !== null && $mType === $c['type']) {
                        $mac = is_array($m) ? (string)($m['mac'] ?? '') : (string)$m;
                        unset($macPool[$mk]);
                        break;
                    }
                }
                if ($mac === null) {
                    foreach ($macPool as $mk => $m) {
                        $mType = is_array($m) ? ($m['type'] ?? null) : null;
                        if ($mType === null) {
                            $mac = is_array($m) ? (string)($m['mac'] ?? '') : (string)$m;
                            unset($macPool[$mk]);
                            break;
                        }
                    }
                }
                if ($mac === '') {
                    $mac = null;
                }
                $insEq->execute([
                    $typeIds[$c['type']] ?? null,
                    $inv,
                    $ser,
                    $mac,
                    $buildingId,
                    $roomId,
                ]);
                $equipmentCreated++;
            }
        }

        $stats = json_decode((string)($batch['stats'] ?? ''), true);
        if (!is_array($stats)) {
            $stats = [];
        }
        $stats['import'] = ['rooms_created' => $roomsCreated, 'equipment_created' => $equipmentCreated, 'duplicates' => $duplicates];
        $pdo->prepare("UPDATE import_batches SET status = 'completed', completed_at = NOW(), stats = ? WHERE id = ?")
            ->execute([json_encode($stats, JSON_UNESCAPED_UNICODE), $batchId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        appLog('import_stock: ошибка импорта пакета ' . $batchId . ': ' . $e->getMessage());
        jsonError('Ошибка импорта: ' . $e->getMessage(), 500);
    }

    importLog($batchId, 'info', sprintf(
        'Импорт остатков завершён (пользователь %s): создано кабинетов %d, техники %d, дубликатов %d',
        (string)($user['email'] ?? $user['id']),
        $roomsCreated,
        $equipmentCreated,
        $duplicates
    ));

    jsonResponse([
        'ok'    => true,
        'stats' => [
            'rooms_created'     => $roomsCreated,
            'equipment_created' => $equipmentCreated,
            'duplicates'        => $duplicates,
        ],
        'duplicates_list' => $duplicatesList,
    ]);
}
