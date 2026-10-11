<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$user = requireAuth();
requireCsrf();

$data = requestJson();
$code = trim((string)($data['code'] ?? ''));
if ($code === '') {
    jsonError('Пустой код', 400);
}

$pdo = db();
$st = $pdo->prepare(
    'SELECT e.id, e.long_name_1c, e.inventory_number, e.serial_number, e.mac_address,
            e.building_id, e.room_id, e.status,
            st.short_name,
            emp.full_name AS employee
     FROM equipment_local e
     LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
     LEFT JOIN employees emp ON emp.id = e.employee_id
     WHERE e.status = \'active\'
       AND (e.serial_number = ? OR e.inventory_number = ?)
     ORDER BY (e.serial_number = ?) DESC, e.id
     LIMIT 1'
);
$st->execute([$code, $code, $code]);
$item = $st->fetch();

if (!$item) {
    $ins = $pdo->prepare(
        'INSERT INTO inventory_scans (equipment_id, user_id, building_id, room_id, scanned_code, result)
         VALUES (NULL, ?, NULL, NULL, ?, \'not_found\')'
    );
    $ins->execute([(int)$user['id'], $code]);
    jsonResponse(['ok' => true, 'found' => false, 'code' => $code]);
}

$equipmentId = (int)$item['id'];
$buildingId  = $item['building_id'] !== null ? (int)$item['building_id'] : null;
$roomId      = $item['room_id'] !== null ? (int)$item['room_id'] : null;

$ins = $pdo->prepare(
    'INSERT INTO inventory_scans (equipment_id, user_id, building_id, room_id, scanned_code, result)
     VALUES (?,?,?,?,?, \'found\')'
);
$ins->execute([$equipmentId, (int)$user['id'], $buildingId, $roomId, $code]);

/* Комната и её состав */
$room = null;
$roomItems = [];
if ($roomId !== null) {
    $st = $pdo->prepare(
        'SELECT r.id, r.room_number, r.plan_number, r.building_id, b.address AS building
         FROM rooms r
         LEFT JOIN buildings b ON b.id = r.building_id
         WHERE r.id = ?'
    );
    $st->execute([$roomId]);
    $room = $st->fetch();
    if ($room) {
        $room['id']          = (int)$room['id'];
        $room['building_id'] = (int)$room['building_id'];

        $st = $pdo->prepare(
            'SELECT e.id, st.short_name, e.inventory_number, e.serial_number
             FROM equipment_local e
             LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
             WHERE e.room_id = ? AND e.status = \'active\'
             ORDER BY st.short_name, e.id'
        );
        $st->execute([$roomId]);
        foreach ($st->fetchAll() as $row) {
            $row['id'] = (int)$row['id'];
            $roomItems[] = $row;
        }
    }
}

jsonResponse([
    'ok'    => true,
    'found' => true,
    'item'  => [
        'id'               => $equipmentId,
        'long_name_1c'     => $item['long_name_1c'],
        'short_name'       => $item['short_name'],
        'inventory_number' => $item['inventory_number'],
        'serial_number'    => $item['serial_number'],
        'mac_address'      => $item['mac_address'],
        'status'           => $item['status'],
        'employee'         => $item['employee'],
    ],
    'room'       => $room,
    'room_items' => $roomItems,
]);
