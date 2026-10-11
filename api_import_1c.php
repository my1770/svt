<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/uuid.php';
require_once __DIR__ . '/lib/import_1c.php';

$user = requireRole('admin', 'economist');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Метод не поддерживается', 405);
}
requireCsrf();

$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if (stripos($contentType, 'application/json') === 0) {
    $in = requestJson();
    $action = (string)($in['action'] ?? '');
    if ($action === 'geo_status' || $action === 'resolve_geo') {
        apiImport1cGeo($user, $in);
    }
    apiImport1cConfirm($user);
} else {
    apiImport1cUpload();
}

/* ---------- Гео-сопоставления: адреса 1С -> здания, кабинеты 1С -> кабинеты ---------- */
/* Из 1С ничего не создаётся: неизвестные адреса/кабинеты предлагается привязать
   через поле «Сопоставление» существующих зданий/кабинетов. */
function apiImport1cGeo(array $user, array $in): never
{
    $batchId = (string)($in['batch_id'] ?? '');
    if ($batchId === '') {
        jsonError('Требуется batch_id');
    }
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM import_batches WHERE id = ? AND kind = '1c'");
    $st->execute([$batchId]);
    $batch = $st->fetch();
    if (!$batch) {
        jsonError('Пакет импорта не найден', 404);
    }
    if (in_array($batch['status'], ['completed', 'failed'], true)) {
        jsonError('Пакет уже обработан', 409);
    }
    $payload = json_decode((string)$batch['payload'], true);
    if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
        jsonError('Повреждённые данные пакета', 500);
    }

    $errors = [];

    /* Применение выбранных сопоставлений */
    if (($in['action'] ?? '') === 'resolve_geo') {
        /* Адреса 1С -> здания: значение дописывается в Сопоставление здания */
        $buildingMap = $in['building_map'] ?? [];
        if (is_array($buildingMap)) {
            foreach ($buildingMap as $address => $buildingId) {
                $address = trim((string)$address);
                $buildingId = (int)$buildingId;
                if ($address === '' || $buildingId <= 0) {
                    continue;
                }
                $st = $pdo->prepare('SELECT id, address, aliases FROM buildings WHERE id = ?');
                $st->execute([$buildingId]);
                $b = $st->fetch();
                if (!$b) {
                    $errors[] = 'Здание не найдено для адреса «' . $address . '»';
                    continue;
                }
                /* Конфликт: адрес уже занят другим зданием (название или сопоставление) */
                $up = mb_strtoupper($address, 'UTF-8');
                $conflict = null;
                foreach ($pdo->query('SELECT address, aliases FROM buildings WHERE id <> ' . (int)$b['id'])->fetchAll() as $o) {
                    if (mb_strtoupper((string)$o['address'], 'UTF-8') === $up) {
                        $conflict = '«' . $address . '» — уже адрес здания «' . $o['address'] . '»';
                        break;
                    }
                    foreach (preg_split('/\R/', (string)$o['aliases']) as $line) {
                        if (mb_strtoupper(trim($line), 'UTF-8') === $up && trim($line) !== '') {
                            $conflict = '«' . $address . '» — уже в сопоставлениях здания «' . $o['address'] . '»';
                            break 2;
                        }
                    }
                }
                if ($conflict !== null) {
                    $errors[] = $conflict;
                    continue;
                }
                $lines = [];
                foreach (preg_split('/\R/', (string)$b['aliases']) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $lines[mb_strtoupper($line, 'UTF-8')] = $line;
                    }
                }
                if (mb_strtoupper((string)$b['address'], 'UTF-8') !== $up) {
                    $lines[$up] = $address;
                }
                $pdo->prepare('UPDATE buildings SET aliases = ? WHERE id = ?')
                    ->execute([implode("\n", $lines) ?: null, (int)$b['id']]);
                importLog($batchId, 'info', 'Гео-сопоставление: адрес 1С «' . $address . '» -> здание «' . $b['address'] . '» (пользователь ' . (string)($user['email'] ?? $user['id']) . ')');
            }
        }

        /* Кабинеты 1С -> кабинеты: значение дописывается в Сопоставление кабинета */
        $roomMap = $in['room_map'] ?? [];
        if (is_array($roomMap)) {
            /* Текущие здания для резолва адреса */
            $addrUp = [];
            foreach ($pdo->query('SELECT id, address, aliases FROM buildings')->fetchAll() as $b) {
                $bid = (int)$b['id'];
                $addrUp[mb_strtoupper((string)$b['address'], 'UTF-8')] = $bid;
                foreach (preg_split('/\R/', (string)$b['aliases']) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $addrUp[mb_strtoupper($line, 'UTF-8')] = $bid;
                    }
                }
            }
            foreach ($roomMap as $rm) {
                if (!is_array($rm)) {
                    continue;
                }
                $address = trim((string)($rm['address'] ?? ''));
                $roomName = trim((string)($rm['room'] ?? ''));
                $roomId = (int)($rm['room_id'] ?? 0);
                if ($roomName === '' || $roomId <= 0) {
                    continue;
                }
                $buildingId = $addrUp[mb_strtoupper($address, 'UTF-8')] ?? null;
                if ($buildingId === null) {
                    $errors[] = 'Не удалось определить здание для кабинета «' . $roomName . '» (адрес «' . $address . '»)';
                    continue;
                }
                $st = $pdo->prepare('SELECT id, room_number, aliases FROM rooms WHERE id = ? AND building_id = ?');
                $st->execute([$roomId, $buildingId]);
                $room = $st->fetch();
                if (!$room) {
                    $errors[] = 'Кабинет не найден в здании для «' . $roomName . '»';
                    continue;
                }
                /* Конфликт в рамках здания */
                $up = mb_strtoupper($roomName, 'UTF-8');
                $conflict = null;
                $st = $pdo->prepare('SELECT room_number, aliases FROM rooms WHERE building_id = ? AND id <> ?');
                $st->execute([$buildingId, (int)$room['id']]);
                foreach ($st->fetchAll() as $o) {
                    if (mb_strtoupper((string)$o['room_number'], 'UTF-8') === $up) {
                        $conflict = '«' . $roomName . '» — уже кабинет «' . $o['room_number'] . '»';
                        break;
                    }
                    foreach (preg_split('/\R/', (string)$o['aliases']) as $line) {
                        if (mb_strtoupper(trim($line), 'UTF-8') === $up && trim($line) !== '') {
                            $conflict = '«' . $roomName . '» — уже в сопоставлениях кабинета «' . $o['room_number'] . '»';
                            break 2;
                        }
                    }
                }
                if ($conflict !== null) {
                    $errors[] = $conflict;
                    continue;
                }
                $lines = [];
                foreach (preg_split('/\R/', (string)$room['aliases']) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $lines[mb_strtoupper($line, 'UTF-8')] = $line;
                    }
                }
                if (mb_strtoupper((string)$room['room_number'], 'UTF-8') !== $up) {
                    $lines[$up] = $roomName;
                }
                $pdo->prepare('UPDATE rooms SET aliases = ? WHERE id = ?')
                    ->execute([implode("\n", $lines) ?: null, (int)$room['id']]);
                importLog($batchId, 'info', 'Гео-сопоставление: кабинет 1С «' . $roomName . '» -> кабинет «' . $room['room_number'] . '» (' . $address . ')');
            }
        }
    }

    /* Пересчёт несопоставленных адресов и кабинетов по актуальному состоянию БД */
    $addrExact = [];
    $addrUp = [];
    foreach ($pdo->query('SELECT id, address, aliases FROM buildings')->fetchAll() as $b) {
        $bid = (int)$b['id'];
        $addrExact[(string)$b['address']] = $bid;
        $addrUp[mb_strtoupper((string)$b['address'], 'UTF-8')] = $bid;
        foreach (preg_split('/\R/', (string)$b['aliases']) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $addrUp[mb_strtoupper($line, 'UTF-8')] = $bid;
            }
        }
    }
    $roomsUp = [];
    foreach ($pdo->query('SELECT building_id, room_number, aliases FROM rooms')->fetchAll() as $r) {
        $key = (int)$r['building_id'];
        $roomsUp[$key][mb_strtoupper((string)$r['room_number'], 'UTF-8')] = true;
        foreach (preg_split('/\R/', (string)$r['aliases']) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $roomsUp[$key][mb_strtoupper($line, 'UTF-8')] = true;
            }
        }
    }

    $unknownBuildings = [];
    $unknownRooms = [];
    foreach ($payload['items'] as $it) {
        $address = trim((string)($it['building'] ?? ''));
        if ($address === '') {
            continue;
        }
        $buildingId = $addrExact[$address] ?? ($addrUp[mb_strtoupper($address, 'UTF-8')] ?? null);
        if ($buildingId === null) {
            $unknownBuildings[$address] = ($unknownBuildings[$address] ?? 0) + 1;
            continue;
        }
        $room = trim((string)($it['room'] ?? ''));
        if ($room === '') {
            continue;
        }
        if (!isset($roomsUp[$buildingId][mb_strtoupper($room, 'UTF-8')])) {
            $k = $address . '|' . $room;
            if (!isset($unknownRooms[$k])) {
                $unknownRooms[$k] = ['address' => $address, 'room' => $room, 'building_id' => $buildingId, 'count' => 0];
            }
            $unknownRooms[$k]['count']++;
        }
    }

    jsonResponse([
        'ok'                => true,
        'errors'            => $errors,
        'unknown_buildings' => array_map(
            static fn(int $count, string $address): array => ['address' => $address, 'count' => $count],
            array_values($unknownBuildings),
            array_keys($unknownBuildings)
        ),
        'unknown_rooms'     => array_values($unknownRooms),
    ]);
}

/* ---------- Загрузка файла 1С и разбор ---------- */
function apiImport1cUpload(): never
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
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true) && !is_dir(UPLOAD_DIR)) {
        jsonError('Не удалось создать каталог загрузок', 500);
    }
    $batchId = uuid4();
    $dest = UPLOAD_DIR . $batchId . '.xlsx';
    if (!move_uploaded_file((string)$f['tmp_name'], $dest)) {
        jsonError('Не удалось сохранить загруженный файл', 500);
    }
    try {
        $parsed = parse1cFile($dest);
    } catch (Throwable $e) {
        appLog('import_1c: ошибка разбора ' . $origName . ': ' . $e->getMessage());
        jsonError('Не удалось разобрать файл: ' . $e->getMessage(), 422);
    }

    $status = $parsed['unknown_mappings'] !== [] ? 'mapping_required' : 'awaiting_confirm';
    $payload = [
        'items'            => $parsed['items'],
        'skipped'          => $parsed['skipped'],
        'conflicts'        => $parsed['conflicts'],
        'unknown_mappings' => $parsed['unknown_mappings'],
    ];
    db()->prepare('INSERT INTO import_batches (id, kind, filename, status, stats, payload) VALUES (?,?,?,?,?,?)')
        ->execute([
            $batchId,
            '1c',
            $origName,
            $status,
            json_encode($parsed['stats'], JSON_UNESCAPED_UNICODE),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

    foreach ($parsed['skipped'] as $s) {
        importLog($batchId, 'info', sprintf(
            'Дубль по инв. № %s пропущен (строка %d, оставлена строка %d): %s',
            $s['inventory_number'],
            $s['row'],
            $s['kept_row'],
            $s['long_name_1c']
        ));
    }
    foreach ($parsed['conflicts'] as $c) {
        $rowsList = implode(', ', array_map(
            static fn(array $r): string => 'строка ' . $r['row'] . ' (с/н ' . $r['serial_number'] . ', ' . ($r['employee'] ?? 'МОЛ не указан') . ', каб. ' . ($r['room'] ?? '—') . ')',
            $c['rows']
        ));
        importLog($batchId, 'conflict', 'Конфликт дублей по инв. № ' . $c['inventory_number'] . ': несколько строк с серийными номерами — ' . $rowsList);
    }

    jsonResponse([
        'ok'               => true,
        'batch_id'         => $batchId,
        'status'           => $status,
        'stats'            => $parsed['stats'],
        'unknown_mappings' => $parsed['unknown_mappings'],
    ]);
}

/* ---------- Подтверждение: сверка с equipment_local (§7) ---------- */
function apiImport1cConfirm(array $user): never
{
    $in = requestJson();
    $batchId = (string)($in['batch_id'] ?? '');
    if ($batchId === '' || empty($in['confirm'])) {
        jsonError('Требуются batch_id и confirm=true');
    }
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM import_batches WHERE id = ? AND kind = '1c'");
    $st->execute([$batchId]);
    $batch = $st->fetch();
    if (!$batch) {
        jsonError('Пакет импорта не найден', 404);
    }
    if ($batch['status'] === 'completed') {
        jsonError('Пакет уже обработан', 409);
    }
    if ($batch['status'] === 'failed') {
        jsonError('Пакет помечен как неудачный', 409);
    }
    $payload = json_decode((string)$batch['payload'], true);
    if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
        jsonError('Повреждённые данные пакета', 500);
    }
    $items = $payload['items'];

    /* Все длинные названия должны быть замаплены */
    $names = [];
    foreach ($items as $it) {
        $n = (string)($it['long_name_1c'] ?? '');
        if ($n !== '') {
            $names[$n] = true;
        }
    }
    $mapping = [];
    /* Регистронезависимое сравнение (collation БД нечувствительна к регистру) */
    foreach ($pdo->query('SELECT long_name_1c, short_type_id FROM equipment_mapping')->fetchAll() as $m) {
        $mapping[mb_strtolower((string)$m['long_name_1c'], 'UTF-8')] = (int)$m['short_type_id'];
    }
    $missing = [];
    foreach (array_keys($names) as $n) {
        if (!isset($mapping[mb_strtolower($n, 'UTF-8')])) {
            $missing[] = $n;
        }
    }
    if ($missing !== []) {
        $pdo->prepare("UPDATE import_batches SET status = 'mapping_required' WHERE id = ?")->execute([$batchId]);
        jsonResponse([
            'ok'               => false,
            'error'            => 'Есть длинные названия без соответствия типам. Сначала заполните маппинг.',
            'unknown_mappings' => $missing,
        ], 409);
    }

    /* Снимок активной техники из БД */
    $eq = [];
    $bySerial = [];
    $byInv = [];
    $byAlias = [];
    $stEq = $pdo->query(
        "SELECT e.id, e.short_type_id, e.long_name_1c, e.inventory_number, e.serial_number, e.aliases, e.building_id, e.room_id,
                b.address AS building, r.room_number AS room
         FROM equipment_local e
         LEFT JOIN buildings b ON b.id = e.building_id
         LEFT JOIN rooms r ON r.id = e.room_id
         WHERE e.status = 'active'"
    );
    foreach ($stEq->fetchAll() as $e) {
        $id = (int)$e['id'];
        $eq[$id] = $e;
        $ser = trim((string)$e['serial_number']);
        if ($ser !== '' && mb_strtoupper($ser, 'UTF-8') !== 'Б/Н') {
            $bySerial[$ser][] = $id;
        }
        $inv = trim((string)$e['inventory_number']);
        if ($inv !== '' && mb_strtoupper($inv, 'UTF-8') !== 'Б/Н') {
            $byInv[$inv][] = $id;
        }
        /* Сопоставление техники — названия из 1С, по которым эта единица находится при сверке */
        foreach (preg_split('/\R/', (string)$e['aliases']) as $alias) {
            $alias = trim($alias);
            if ($alias !== '') {
                $aliasUp = mb_strtoupper($alias, 'UTF-8');
                $byAlias[$aliasUp][$id] = $id;
            }
        }
    }
    /* Короткие названия типов: «отображаемое» наименование единицы = короткое имя типа,
       а при его отсутствии — длинное имя (по нему строится группа «с одинаковым названием») */
    $shortNames = [];
    foreach ($pdo->query('SELECT id, short_name FROM equipment_short_types')->fetchAll() as $sn) {
        $shortNames[(int)$sn['id']] = (string)$sn['short_name'];
    }

    /* Резолверы справочников: из 1С ничего не создаём — только поиск (адрес/кабинет/сопоставления) */
    $buildingCache = [];
    $buildingUpCache = [];
    $buildingAliasCache = [];
    foreach ($pdo->query('SELECT id, address, aliases FROM buildings')->fetchAll() as $b) {
        $buildingCache[(string)$b['address']] = (int)$b['id'];
        $buildingUpCache[mb_strtoupper((string)$b['address'], 'UTF-8')] = (int)$b['id'];
        foreach (preg_split('/\R/', (string)$b['aliases']) as $alias) {
            $alias = trim($alias);
            if ($alias !== '') {
                $buildingAliasCache[mb_strtoupper($alias, 'UTF-8')] = (int)$b['id'];
            }
        }
    }
    $resolveBuilding = static function (?string $address) use (&$buildingCache, &$buildingUpCache, &$buildingAliasCache): ?int {
        if ($address === null || $address === '') {
            return null;
        }
        if (isset($buildingCache[$address])) {
            return $buildingCache[$address];
        }
        $up = mb_strtoupper($address, 'UTF-8');
        if (isset($buildingUpCache[$up])) {
            return $buildingUpCache[$up];
        }
        if (isset($buildingAliasCache[$up])) {
            return $buildingAliasCache[$up];
        }
        return null;
    };

    $roomCache = [];
    $roomAliasCache = [];
    foreach ($pdo->query('SELECT id, building_id, room_number, aliases FROM rooms')->fetchAll() as $r) {
        $roomCache[(int)$r['building_id'] . '|' . mb_strtoupper((string)$r['room_number'], 'UTF-8')] = (int)$r['id'];
        foreach (preg_split('/\R/', (string)$r['aliases']) as $alias) {
            $alias = trim($alias);
            if ($alias !== '') {
                $roomAliasCache[(int)$r['building_id'] . '|' . mb_strtoupper($alias, 'UTF-8')] = (int)$r['id'];
            }
        }
    }
    $resolveRoom = static function (?int $buildingId, ?string $room) use (&$roomCache, &$roomAliasCache): ?int {
        if ($buildingId === null || $room === null || $room === '') {
            return null;
        }
        $key = $buildingId . '|' . mb_strtoupper($room, 'UTF-8');
        if (isset($roomCache[$key])) {
            return $roomCache[$key];
        }
        if (isset($roomAliasCache[$key])) {
            return $roomAliasCache[$key];
        }
        return null;
    };

    $empCache = [];
    foreach ($pdo->query('SELECT id, full_name FROM employees')->fetchAll() as $e) {
        $empCache[(string)$e['full_name']] = (int)$e['id'];
    }
    $insE = $pdo->prepare('INSERT INTO employees (full_name, last_sync) VALUES (?, NOW())');
    $resolveEmployee = static function (?string $name) use (&$empCache, $insE, $pdo): int {
        $name = ($name === null || $name === '') ? 'Не определён' : $name;
        if (isset($empCache[$name])) {
            return $empCache[$name];
        }
        $insE->execute([$name]);
        $id = (int)$pdo->lastInsertId();
        $empCache[$name] = $id;
        return $id;
    };

    $counts = ['new_arrival' => 0, 'write_off' => 0, 'unrecorded_move' => 0, 'seen_updated' => 0];
    $seen = [];
    $insD = $pdo->prepare('INSERT INTO discrepancies (import_batch_id, equipment_id, type, details) VALUES (?,?,?,?)');
    $updSeen = $pdo->prepare('UPDATE equipment_local SET last_seen_in_1c = NOW() WHERE id = ?');

    $pdo->beginTransaction();
    try {
        foreach ($items as $it) {
            $ser = trim((string)($it['serial_number'] ?? ''));
            $inv = trim((string)($it['inventory_number'] ?? ''));
            $nameUp = mb_strtoupper(trim((string)($it['long_name_1c'] ?? '')), 'UTF-8');
            $matchId = null;
            if ($ser !== '' && !empty($bySerial[$ser])) {
                $matchId = $bySerial[$ser][0];
            } elseif ($inv !== '' && !empty($byInv[$inv])) {
                $matchId = $byInv[$inv][0];
            } elseif ($ser !== '' && isset($byInv[$ser]) && count($byInv[$ser]) === 1) {
                /* Перекрёстная проверка: в 1С инвентарный номер записан в поле серийного.
                   Принимаем только однозначное совпадение (защита от случайного совпадения номеров) */
                $matchId = $byInv[$ser][0];
                importLog($batchId, 'warning', sprintf(
                    'Строка %d: серийный «%s» из 1С совпал с инвентарным номером в БД — вероятно, номера переставлены в 1С',
                    (int)($it['row'] ?? 0), $ser
                ));
            } elseif ($inv !== '' && isset($bySerial[$inv]) && count($bySerial[$inv]) === 1) {
                /* Перекрёстная проверка: в 1С серийный номер записан в поле инвентарного */
                $matchId = $bySerial[$inv][0];
                importLog($batchId, 'warning', sprintf(
                    'Строка %d: инвентарный «%s» из 1С совпал с серийным номером в БД — вероятно, номера переставлены в 1С',
                    (int)($it['row'] ?? 0), $inv
                ));
            } elseif ($nameUp !== '' && isset($byAlias[$nameUp]) && count($byAlias[$nameUp]) === 1) {
                /* Сопоставление по названию из 1С (только если однозначно) */
                $matchId = reset($byAlias[$nameUp]);
            }

            /* Имя из 1С добавляем в «Сопоставление» ВСЕХ единиц с таким же
               (отображаемым) названием — сопоставление общее для наименования */
            if ($matchId !== null && $nameUp !== '' && isset($eq[$matchId])) {
                $long1c = trim((string)($it['long_name_1c'] ?? ''));
                $curAliases = trim((string)$eq[$matchId]['aliases']);
                $present = false;
                foreach (preg_split('/\R/', $curAliases) ?: [] as $al) {
                    if (mb_strtoupper(trim((string)$al), 'UTF-8') === $nameUp) {
                        $present = true;
                        break;
                    }
                }
                if (!$present) {
                    $newAliases = ($curAliases === '' ? '' : $curAliases . "\n") . $long1c;
                    $stId = $eq[$matchId]['short_type_id'] !== null ? (int)$eq[$matchId]['short_type_id'] : null;
                    $dispName = ($stId !== null && isset($shortNames[$stId]) && $shortNames[$stId] !== '')
                        ? $shortNames[$stId]
                        : trim((string)$eq[$matchId]['long_name_1c']);
                    if ($dispName !== '') {
                        $pdo->prepare(
                            "UPDATE equipment_local e
                             LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
                             SET e.aliases = ?
                             WHERE COALESCE(NULLIF(st.short_name, ''), NULLIF(e.long_name_1c, '')) = ?"
                        )->execute([$newAliases, $dispName]);
                    } else {
                        $pdo->prepare('UPDATE equipment_local SET aliases = ? WHERE id = ?')
                            ->execute([$newAliases, $matchId]);
                    }
                    $eq[$matchId]['aliases'] = $newAliases;
                    importLog($batchId, 'info', sprintf(
                        'Название 1С «%s» добавлено в «Сопоставление» всех единиц с наименованием «%s»',
                        $long1c, $dispName !== '' ? $dispName : '—'
                    ));
                }
            }

            $buildingId = $resolveBuilding($it['building'] ?? null);
            $roomId = $resolveRoom($buildingId, $it['room'] ?? null);

            if ($matchId === null) {
                /* Новое поступление: только предложение, в equipment_local ничего не создаём */
                $employeeName = (string)(($it['employee'] ?? null) ?: 'Не определён');
                $resolveEmployee($it['employee'] ?? null);
                $details = [
                    'long_name_1c'     => (string)($it['long_name_1c'] ?? ''),
                    'inventory_number' => $inv !== '' ? $inv : null,
                    'serial_number'    => $ser !== '' ? $ser : null,
                    'mac_address'      => null,
                    'device_type_1c'   => $it['device_type_1c'] ?? null,
                    'short_type_id'    => $mapping[mb_strtolower((string)($it['long_name_1c'] ?? ''), 'UTF-8')] ?? null,
                    'employee'         => $employeeName,
                    'building_id'      => $buildingId,
                    'room_id'          => $roomId,
                    'building'         => $it['building'] ?? null,
                    'room'             => $it['room'] ?? null,
                ];
                /* Кандидаты из базы по «Сопоставлению»: название 1С совпало с
                   сопоставлениями нескольких единиц — показываем их оператору */
                $dbMatches = [];
                foreach (array_keys($byAlias[$nameUp] ?? []) as $cid) {
                    if (isset($eq[$cid])) {
                        $e2 = $eq[$cid];
                        $dbMatches[] = [
                            'name'     => (string)$e2['long_name_1c'],
                            'building' => $e2['building'],
                            'room'     => $e2['room'],
                        ];
                    }
                }
                if ($dbMatches !== []) {
                    $details['db_matches'] = $dbMatches;
                }
                $insD->execute([$batchId, null, 'new_arrival', json_encode($details, JSON_UNESCAPED_UNICODE)]);
                $counts['new_arrival']++;
                continue;
            }

            /* Совпадение: обновляем только last_seen_in_1c */
            if (isset($seen[$matchId])) {
                continue;
            }
            $seen[$matchId] = true;
            $updSeen->execute([$matchId]);
            $counts['seen_updated']++;

            if ($buildingId !== null && $roomId !== null) {
                $e = $eq[$matchId];
                $eb = $e['building_id'] !== null ? (int)$e['building_id'] : null;
                $er = $e['room_id'] !== null ? (int)$e['room_id'] : null;
                if ($eb !== $buildingId || $er !== $roomId) {
                    $details = [
                        'from' => [
                            'building_id' => $eb,
                            'room_id'     => $er,
                            'building'    => $e['building'],
                            'room'        => $e['room'],
                        ],
                        'to'   => [
                            'building_id' => $buildingId,
                            'room_id'     => $roomId,
                            'building'    => $it['building'] ?? null,
                            'room'        => $it['room'] ?? null,
                        ],
                    ];
                    $insD->execute([$batchId, $matchId, 'unrecorded_move', json_encode($details, JSON_UNESCAPED_UNICODE)]);
                    $counts['unrecorded_move']++;
                }
            }
        }

        /* Списание: активная техника, не встретившаяся в файле */
        foreach ($eq as $id => $e) {
            if (isset($seen[$id])) {
                continue;
            }
            $details = [
                'building_id'      => $e['building_id'] !== null ? (int)$e['building_id'] : null,
                'room_id'          => $e['room_id'] !== null ? (int)$e['room_id'] : null,
                'building'         => $e['building'],
                'room'             => $e['room'],
                'inventory_number' => $e['inventory_number'],
                'serial_number'    => $e['serial_number'],
            ];
            $insD->execute([$batchId, $id, 'write_off', json_encode($details, JSON_UNESCAPED_UNICODE)]);
            $counts['write_off']++;
        }

        $stats = json_decode((string)($batch['stats'] ?? ''), true);
        if (!is_array($stats)) {
            $stats = [];
        }
        $stats['recon'] = $counts;
        $pdo->prepare("UPDATE import_batches SET status = 'completed', completed_at = NOW(), stats = ? WHERE id = ?")
            ->execute([json_encode($stats, JSON_UNESCAPED_UNICODE), $batchId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        appLog('import_1c: ошибка сверки пакета ' . $batchId . ': ' . $e->getMessage());
        jsonError('Ошибка сверки: ' . $e->getMessage(), 500);
    }

    importLog($batchId, 'info', sprintf(
        'Сверка завершена (пользователь %s): новых %d, списаний %d, перемещений %d, подтверждено в 1С %d',
        (string)($user['email'] ?? $user['id']),
        $counts['new_arrival'],
        $counts['write_off'],
        $counts['unrecorded_move'],
        $counts['seen_updated']
    ));

    jsonResponse(['ok' => true, 'stats' => $counts]);
}
