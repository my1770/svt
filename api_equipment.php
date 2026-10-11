<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$user = requireAuth();
$pdo = db();

/* Разбор многострочного поля «Сопоставление»: trim, без пустых, без дублей */
function parseEquipmentAliases(string $raw): string
{
    $aliases = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim($line);
        if ($line !== '' && mb_strlen($line) <= 128) {
            $aliases[mb_strtoupper($line, 'UTF-8')] = $line;
        }
    }
    return $aliases ? implode("\n", $aliases) : '';
}

/* Поиск дублей введённых данных среди всей техники (инвентарный, серийный, MAC) */
function findEquipmentDupes(PDO $pdo, array $fields, ?int $excludeId): array
{
    $conditions = [];
    $params = [':ex' => $excludeId ?? 0];
    foreach (['inventory_number', 'serial_number', 'mac_address'] as $f) {
        $v = trim((string)($fields[$f] ?? ''));
        if ($v !== '') {
            $conditions[] = "(e.$f = :$f AND e.$f <> '')";
            $params[":$f"] = $v;
        }
    }
    if (!$conditions) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT e.id, e.long_name_1c, e.inventory_number, e.serial_number, e.mac_address,
                b.address AS building, r.room_number AS room
         FROM equipment_local e
         LEFT JOIN buildings b ON b.id = e.building_id
         LEFT JOIN rooms r ON r.id = e.room_id
         WHERE (' . implode(' OR ', $conditions) . ')
           AND e.id <> :ex AND e.status = \'active\'
         ORDER BY e.id LIMIT 50'
    );
    $st->execute($params);

    $labels = ['inventory_number' => 'инвентарный номер', 'serial_number' => 'серийный номер', 'mac_address' => 'MAC'];
    $conflicts = [];
    foreach ($st->fetchAll() as $r) {
        foreach ($labels as $f => $label) {
            $v = trim((string)($fields[$f] ?? ''));
            if ($v !== '' && $r[$f] === $v) {
                $conflicts[] = '«' . $v . '» (' . $label . ') — уже у техники «'
                    . ($r['long_name_1c'] !== '' ? $r['long_name_1c'] : 'без названия')
                    . '», ' . ($r['building'] ?? '—') . ', каб. ' . ($r['room'] ?? '—');
            }
        }
    }
    return array_unique($conflicts);
}

/* Автодополнение названий: все названия из базы (equipment_local + equipment_mapping), максимум 15 */
if (isset($_GET['names'])) {
    $q = trim((string)$_GET['names']);
    if ($q === '') {
        jsonResponse(['items' => []]);
    }
    $st = $pdo->prepare(
        'SELECT name FROM (
            SELECT DISTINCT long_name_1c AS name FROM equipment_local WHERE long_name_1c LIKE ?
            UNION
            SELECT DISTINCT long_name_1c AS name FROM equipment_mapping WHERE long_name_1c LIKE ?
            UNION
            SELECT DISTINCT short_name AS name FROM equipment_short_types WHERE short_name LIKE ?
        ) t ORDER BY name LIMIT 15'
    );
    $like = '%' . $q . '%';
    $st->execute([$like, $like, $like]);
    jsonResponse(['items' => array_column($st->fetchAll(), 'name')]);
}

/* Проверка введённых данных на дубли (для blur в форме и перед сохранением) */
if (isset($_GET['dupes'])) {
    $excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;
    jsonResponse(['conflicts' => findEquipmentDupes($pdo, $_GET, $excludeId)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        jsonError('Некорректный запрос', 400);
    }
    requireCsrf();
    if (($user['role'] ?? '') !== 'admin') {
        jsonError('Недостаточно прав', 403);
    }
    $action = (string)($body['action'] ?? '');
    if ($action !== 'update' && $action !== 'create') {
        jsonError('Неизвестное действие', 400);
    }

    $norm = static function ($v): ?string {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    };
    $name = trim((string)($body['long_name_1c'] ?? ''));
    if ($name === '' || mb_strlen($name) > 512) {
        jsonError('Название пустое или длиннее 512 символов', 422);
    }
    $inv = $norm($body['inventory_number'] ?? null);
    $ser = $norm($body['serial_number'] ?? null);
    $mac = $norm($body['mac_address'] ?? null);
    $note = $norm($body['note'] ?? null);
    $aliases = parseEquipmentAliases((string)($body['aliases'] ?? ''));

    if ($action === 'create') {
        $buildingId = (int)($body['building_id'] ?? 0);
        $roomId = (int)($body['room_id'] ?? 0);
        $st = $pdo->prepare('SELECT id FROM buildings WHERE id = ?');
        $st->execute([$buildingId]);
        if (!$st->fetch()) {
            jsonError('Здание не найдено', 404);
        }
        if ($roomId > 0) {
            $st = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND building_id = ?');
            $st->execute([$roomId, $buildingId]);
            if (!$st->fetch()) {
                jsonError('Кабинет не найден в указанном здании', 404);
            }
        } else {
            $roomId = null;
        }
        $dupes = findEquipmentDupes($pdo, ['inventory_number' => $inv, 'serial_number' => $ser, 'mac_address' => $mac], null);
        if ($dupes) {
            jsonError("Такие данные уже есть у другой техники:\n" . implode("\n", $dupes), 409);
        }
        $pdo->prepare(
            'INSERT INTO equipment_local (long_name_1c, short_type_id, inventory_number, serial_number, mac_address, aliases, note, building_id, room_id)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$name, $inv, $ser, $mac, $aliases ?: null, $note, $buildingId, $roomId]);
        $newId = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT address FROM buildings WHERE id = ?');
        $st->execute([$buildingId]);
        $details = 'Здание: ' . (string)$st->fetchColumn();
        if ($roomId !== null) {
            $st = $pdo->prepare('SELECT room_number FROM rooms WHERE id = ?');
            $st->execute([$roomId]);
            $details .= ', каб. ' . (string)$st->fetchColumn();
        }
        logActivity((int)$user['id'], 'equipment', 'create', equipmentTag($name, $inv, $ser), $details);
        jsonResponse(['saved' => true, 'id' => $newId]);
    }

    /* update */
    $id = (int)($body['id'] ?? 0);
    $st = $pdo->prepare(
        'SELECT e.id, e.long_name_1c, e.inventory_number, e.serial_number, e.mac_address, e.aliases, e.note,
                st.short_name AS cur_short
         FROM equipment_local e
         LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
         WHERE e.id = ?'
    );
    $st->execute([$id]);
    $eq = $st->fetch();
    if (!$eq) {
        jsonError('Техника не найдена', 404);
    }

    $oldName = (string)$eq['long_name_1c'];
    $renamedCount = 0;
    /* Имя считается изменённым, если отличается от прежнего; подставленное короткое
       название у единицы без длинного имени — не изменение */
    $nameChanged = $name !== $oldName && !($oldName === '' && (string)$eq['cur_short'] !== '' && $name === (string)$eq['cur_short']);
    if ($nameChanged) {
        /* Уникальное ли новое название (не встречается ни у техники, ни в маппинге) */
        $st = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM equipment_local WHERE long_name_1c = ?)
                  + (SELECT COUNT(*) FROM equipment_mapping WHERE long_name_1c = ?)'
        );
        $st->execute([$name, $name]);
        $isUniqueName = (int)$st->fetchColumn() === 0;

        if ($isUniqueName) {
            $scope = (string)($body['rename_scope'] ?? '');
            if ($scope !== 'all' && $scope !== 'single') {
                /* Сколько единиц с прежним названием затронет массовое переименование */
                $st = $pdo->prepare('SELECT COUNT(*) FROM equipment_local WHERE long_name_1c = ?');
                $st->execute([$oldName]);
                jsonResponse([
                    'ok' => true,
                    'need_scope' => true,
                    'affected' => (int)$st->fetchColumn(),
                ]);
            }
            if ($scope === 'all') {
                $st = $pdo->prepare('SELECT COUNT(*) FROM equipment_local WHERE long_name_1c = ?');
                $st->execute([$oldName]);
                $renamedCount = (int)$st->fetchColumn();
                $pdo->prepare('UPDATE equipment_local SET long_name_1c = ? WHERE long_name_1c = ?')
                    ->execute([$name, $oldName]);
                $pdo->prepare('UPDATE equipment_mapping SET long_name_1c = ? WHERE long_name_1c = ?')
                    ->execute([$name, $oldName]);
            }
        }
    }

    $pdo->prepare(
        'UPDATE equipment_local
         SET long_name_1c = ?, inventory_number = ?, serial_number = ?, mac_address = ?, aliases = ?, note = ?
         WHERE id = ?'
    )->execute([$name, $inv, $ser, $mac, $aliases ?: null, $note, $id]);

    /* «Сопоставление» — свойство наименования: изменили в одной карточке —
       применили ко всем единицам с тем же (отображаемым) названием */
    $oldAliases = trim((string)$eq['aliases']);
    $newAliases = trim((string)($aliases ?? ''));
    if ($oldAliases !== $newAliases) {
        $dispName = trim((string)($eq['cur_short'] ?? ''));
        if ($dispName === '') {
            $dispName = $name;
        }
        if ($dispName !== '') {
            $pdo->prepare(
                "UPDATE equipment_local e
                 LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
                 SET e.aliases = ?
                 WHERE COALESCE(NULLIF(st.short_name, ''), NULLIF(e.long_name_1c, '')) = ?"
            )->execute([$aliases ?: null, $dispName]);
        }
    }

    /* Журнал действий — только если реально что-то изменилось */
    $details = changedFields([
        ['название', $oldName, $name],
        ['инв. номер', $eq['inventory_number'], $inv],
        ['сер. номер', $eq['serial_number'], $ser],
        ['MAC', $eq['mac_address'], $mac],
        ['сопоставление', $eq['aliases'], $aliases],
        ['примечание', $eq['note'], $note],
    ]);
    if ($details !== null) {
        if ($nameChanged && $renamedCount > 0) {
            $details .= '; применено к ' . $renamedCount . ' ед. с прежним названием';
        }
        logActivity((int)$user['id'], 'equipment', 'update', equipmentTag($name, $inv, $ser), $details);
    }

    jsonResponse(['saved' => true]);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonError('Не указана техника', 400);
}

$st = $pdo->prepare(
    'SELECT e.id, e.long_name_1c, e.short_type_id, e.inventory_number, e.serial_number, e.mac_address,
            e.aliases, e.note, e.device_type_1c, e.building_id, e.room_id, e.status, e.last_seen_in_1c,
            st.short_name,
            b.address AS building,
            r.room_number AS room, r.plan_number,
            emp.full_name AS employee
     FROM equipment_local e
     LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
     LEFT JOIN buildings b  ON b.id  = e.building_id
     LEFT JOIN rooms r      ON r.id  = e.room_id
     LEFT JOIN employees emp ON emp.id = e.employee_id
     WHERE e.id = ?'
);
$st->execute([$id]);
$item = $st->fetch();
if (!$item) {
    jsonError('Техника не найдена', 404);
}
$item['id']            = (int)$item['id'];
$item['short_type_id'] = $item['short_type_id'] !== null ? (int)$item['short_type_id'] : null;
$item['building_id']   = $item['building_id'] !== null ? (int)$item['building_id'] : null;
$item['room_id']       = $item['room_id'] !== null ? (int)$item['room_id'] : null;

$st = $pdo->prepare(
    'SELECT m.id, m.created_at, m.comment,
            fb.address AS from_building,
            fr.room_number AS from_room,
            tb.address AS to_building,
            tr.room_number AS to_room,
            u.full_name AS user
     FROM movements m
     LEFT JOIN buildings fb ON fb.id = m.from_building_id
     LEFT JOIN rooms fr     ON fr.id = m.from_room_id
     LEFT JOIN buildings tb ON tb.id = m.to_building_id
     LEFT JOIN rooms tr     ON tr.id = m.to_room_id
     LEFT JOIN users u      ON u.id  = m.user_id
     WHERE m.equipment_id = ?
     ORDER BY m.created_at DESC, m.id DESC'
);
$st->execute([$id]);

$movements = [];
foreach ($st->fetchAll() as $row) {
    $row['id'] = (int)$row['id'];
    $movements[] = $row;
}

jsonResponse(['item' => $item, 'movements' => $movements]);
