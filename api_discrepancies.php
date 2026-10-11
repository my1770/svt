<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$user = requireRole('admin', 'economist');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
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
        /* Здание с любой стороны расхождения: техника в базе либо координаты из 1С */
        $where[] = '(e.building_id = ? OR JSON_EXTRACT(d.details, \'$.building_id\') = ? OR JSON_EXTRACT(d.details, \'$.to.building_id\') = ?)';
        array_push($params, $buildingId, $buildingId, $buildingId);
    }
    $sql = 'SELECT d.id, d.type, d.created_at, d.resolved, d.import_batch_id AS batch_id,
                   d.equipment_id, d.details,
                   e.id AS eq_id, e.long_name_1c, e.short_type_id, e.inventory_number, e.serial_number,
                   e.aliases, e.mac_address, e.building_id AS eq_building_id, e.room_id AS eq_room_id,
                   st.short_name,
                   b.address AS building, r.room_number AS room
            FROM discrepancies d
            LEFT JOIN equipment_local e ON e.id = d.equipment_id
            LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
            LEFT JOIN buildings b ON b.id = e.building_id
            LEFT JOIN rooms r     ON r.id = e.room_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY d.id DESC';
    $st = db()->prepare($sql);
    $st->execute($params);

    $items = [];
    foreach ($st->fetchAll() as $row) {
        $details = null;
        if ($row['details'] !== null && $row['details'] !== '') {
            $decoded = json_decode((string)$row['details'], true);
            $details = is_array($decoded) ? $decoded : null;
        }
        $equipment = null;
        if ($row['eq_id'] !== null) {
            $equipment = [
                'id'               => (int)$row['eq_id'],
                'long_name_1c'     => $row['long_name_1c'],
                'short_name'       => $row['short_name'],
                'short_type_id'    => $row['short_type_id'] !== null ? (int)$row['short_type_id'] : null,
                'inventory_number' => $row['inventory_number'],
                'serial_number'    => $row['serial_number'],
                'mac_address'      => $row['mac_address'],
                'aliases'          => $row['aliases'],
                'building_id'      => $row['eq_building_id'] !== null ? (int)$row['eq_building_id'] : null,
                'room_id'          => $row['eq_room_id'] !== null ? (int)$row['eq_room_id'] : null,
                'building'         => $row['building'],
                'room'             => $row['room'],
            ];
        }
        $items[] = [
            'id'           => (int)$row['id'],
            'type'         => $row['type'],
            'created_at'   => $row['created_at'],
            'resolved'     => (int)$row['resolved'],
            'batch_id'     => $row['batch_id'],
            'equipment_id' => $row['equipment_id'] !== null ? (int)$row['equipment_id'] : null,
            'equipment'    => $equipment,
            'details'      => $details,
        ];
    }

    /* «Новое поступление»: первое найденное «Сопоставление» в базе (на лету,
       точечно по названию из 1С — без прохода по всем единицам и их алиасам) */
    $escLike = static function (string $s): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    };
    $stFirst = db()->prepare(
        "SELECT e.id, e.long_name_1c AS name, e.aliases, st.short_name,
                b.address AS building, r.room_number AS room
         FROM equipment_local e
         LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
         LEFT JOIN buildings b ON b.id = e.building_id
         LEFT JOIN rooms r ON r.id = e.room_id
         WHERE e.status = 'active'
           AND CONCAT('\n', e.aliases, '\n') LIKE ?
         ORDER BY e.id
         LIMIT 1"
    );
    $firstByAlias = [];
    foreach ($items as &$it) {
        if ($it['type'] !== 'new_arrival' || !is_array($it['details'])) {
            continue;
        }
        $name = trim((string)($it['details']['long_name_1c'] ?? ''));
        if ($name === '') {
            $it['alias_match'] = null;
            continue;
        }
        if (!array_key_exists($name, $firstByAlias)) {
            $stFirst->execute(['%' . "\n" . $escLike($name) . "\n%"]);
            $row = $stFirst->fetch();
            $firstByAlias[$name] = $row ?: null;
        }
        $it['alias_match'] = $firstByAlias[$name];
    }
    unset($it);

    jsonResponse(['items' => $items]);
}

jsonError('Метод не поддерживается', 405);
