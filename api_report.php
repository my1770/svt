<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$user = requireAuth();

$buildingId = (int)($_GET['building_id'] ?? 0);

$pdo = db();

/* Список уникальных наименований техники (для отчёта «По технике») */
if (isset($_GET['names'])) {
    $st = $pdo->query(
        'SELECT name FROM (
            SELECT DISTINCT short_name AS name FROM equipment_short_types WHERE short_name IS NOT NULL AND short_name <> \'\'
            UNION
            SELECT DISTINCT long_name_1c AS name FROM equipment_local WHERE long_name_1c IS NOT NULL AND long_name_1c <> \'\'
        ) t ORDER BY name'
    );
    jsonResponse(['items' => array_column($st->fetchAll(), 'name')]);
}

/* Отчёт по наименованию: где и сколько единиц этого типа/названия */
if (isset($_GET['equipment'])) {
    $name = trim((string)$_GET['equipment']);
    if ($name === '') {
        jsonError('Не указано наименование', 400);
    }
    $st = $pdo->prepare(
        'SELECT e.id, e.inventory_number, e.serial_number, e.mac_address, e.long_name_1c, e.aliases,
                st.short_name,
                b.id AS building_id, b.address AS building,
                r.id AS room_id, r.room_number AS room
         FROM equipment_local e
         LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
         LEFT JOIN buildings b ON b.id = e.building_id
         LEFT JOIN rooms r ON r.id = e.room_id
         WHERE e.status = \'active\'
           AND (st.short_name = ? OR e.long_name_1c = ?)
         ORDER BY b.address, r.room_number + 0, r.room_number, e.id'
    );
    $st->execute([$name, $name]);

    $groups = [];
    $total = 0;
    foreach ($st->fetchAll() as $row) {
        $key = (int)$row['building_id'] . '|' . (int)$row['room_id'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'building_id' => (int)$row['building_id'],
                'building'    => $row['building'],
                'room_id'     => (int)$row['room_id'],
                'room'        => $row['room'],
                'units'       => [],
            ];
        }
        $groups[$key]['units'][] = [
            'id'               => (int)$row['id'],
            'inventory_number' => $row['inventory_number'],
            'serial_number'    => $row['serial_number'],
            'mac_address'      => $row['mac_address'],
            'long_name_1c'     => $row['long_name_1c'],
            'short_name'       => $row['short_name'],
            'aliases'          => $row['aliases'],
        ];
        $total++;
    }
    jsonResponse([
        'name'   => $name,
        'total'  => $total,
        'groups' => array_values($groups),
    ]);
}

/* Последние 50 перемещений из/в здание */
if (isset($_GET['movements'])) {
    $bId = (int)($_GET['building_id'] ?? 0);
    if ($bId <= 0) {
        jsonError('Не указано здание', 400);
    }
    $st = $pdo->prepare(
        'SELECT m.id, m.created_at, m.comment,
                m.to_building_id, m.to_room_id,
                u.full_name AS user,
                e.id AS eq_id, e.long_name_1c, e.inventory_number, e.serial_number,
                st.short_name,
                fb.address AS from_building, fr.room_number AS from_room,
                tb.address AS to_building, tr.room_number AS to_room
         FROM movements m
         JOIN equipment_local e ON e.id = m.equipment_id
         LEFT JOIN users u ON u.id = m.user_id
         LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
         LEFT JOIN buildings fb ON fb.id = m.from_building_id
         LEFT JOIN rooms fr     ON fr.id = m.from_room_id
         LEFT JOIN buildings tb ON tb.id = m.to_building_id
         LEFT JOIN rooms tr     ON tr.id = m.to_room_id
         WHERE m.from_building_id = ? OR m.to_building_id = ?
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 50'
    );
    $st->execute([$bId, $bId]);
    $items = [];
    foreach ($st->fetchAll() as $row) {
        $row['id'] = (int)$row['id'];
        $row['eq_id'] = (int)$row['eq_id'];
        $row['to_building_id'] = $row['to_building_id'] !== null ? (int)$row['to_building_id'] : null;
        $row['to_room_id'] = $row['to_room_id'] !== null ? (int)$row['to_room_id'] : null;
        $items[] = $row;
    }
    jsonResponse(['items' => $items]);
}

/* Журнал действий (только admin): перемещения, изменения, удаления — техника/кабинеты/здания */
if (isset($_GET['journal'])) {
    if (($user['role'] ?? '') !== 'admin') {
        jsonError('Недостаточно прав', 403);
    }
    $limit  = max(1, min(200, (int)($_GET['limit'] ?? 40)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    /* Поиск по строкам: ФИО, наименование объекта, детали и наименование операции.
       Активен при вводе более двух символов; спецсимволы LIKE экранируются */
    $q = trim((string)($_GET['q'] ?? ''));
    $where = '';
    $params = [];
    if (mb_strlen($q, 'UTF-8') > 2) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $where = " WHERE u.full_name LIKE ? OR al.entity_name LIKE ? OR al.details LIKE ?
                  OR CONCAT(
                      CASE al.action
                          WHEN 'create' THEN 'Добавление '
                          WHEN 'update' THEN 'Изменение '
                          WHEN 'move'   THEN 'Перемещение '
                          WHEN 'delete' THEN 'Удаление '
                      END,
                      CASE al.entity
                          WHEN 'equipment' THEN 'техники'
                          WHEN 'room'      THEN 'кабинета'
                          WHEN 'building'  THEN 'здания'
                      END
                  ) LIKE ?";
        $params = [$like, $like, $like, $like];
    }
    /* Запрашиваем на одну строку больше — так узнаём, есть ли продолжение */
    $st = $pdo->prepare(
        'SELECT al.id, al.created_at, al.entity, al.action, al.entity_name, al.details,
                u.full_name AS user
         FROM activity_log al
         LEFT JOIN users u ON u.id = al.user_id'
        . $where
        . " ORDER BY al.created_at DESC, al.id DESC
           LIMIT " . ($limit + 1) . " OFFSET " . $offset
    );
    $st->execute($params);
    $rows = $st->fetchAll();
    $hasMore = count($rows) > $limit;
    $items = [];
    foreach (array_slice($rows, 0, $limit) as $row) {
        $row['id'] = (int)$row['id'];
        $items[] = $row;
    }
    jsonResponse(['items' => $items, 'has_more' => $hasMore]);
}

if ($buildingId <= 0) {
    jsonError('Не указано здание', 400);
}
$st = $pdo->prepare('SELECT id, address FROM buildings WHERE id = ?');
$st->execute([$buildingId]);
$building = $st->fetch();
if (!$building) {
    jsonError('Здание не найдено', 404);
}

/* Все кабинеты здания (естественная сортировка) */
$st = $pdo->prepare(
    'SELECT id, room_number, plan_number FROM rooms
     WHERE building_id = ?
     ORDER BY room_number + 0, room_number'
);
$st->execute([$buildingId]);
$rooms = $st->fetchAll();

/* Активная техника здания: колонка = короткое название типа либо наименование техники */
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
$cmp = static function (string $a, string $b): int {
    return strcmp(mb_strtoupper($a, 'UTF-8'), mb_strtoupper($b, 'UTF-8'));
};
uksort($labelMap, $cmp);
$columns = [];
foreach ($labelMap as $label => $key) {
    $columns[] = ['key' => $key, 'name' => $label];
}

/* Группировка: cells[room_id][column_key][] и итоги */
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

$rows = [];
foreach ($rooms as $room) {
    $roomId = (int)$room['id'];
    $roomCells = [];
    foreach ($cells[$roomId] ?? [] as $key => $numbers) {
        $roomCells[$key] = $numbers;
    }
    $rows[] = [
        'room_id'     => $roomId,
        'room_number' => $room['room_number'],
        'plan_number' => $room['plan_number'],
        'cells'       => (object)$roomCells,
    ];
}

jsonResponse([
    'building' => ['id' => (int)$building['id'], 'address' => $building['address']],
    'columns'  => $columns,
    'rows'     => $rows,
    'totals'   => (object)$totals,
]);
