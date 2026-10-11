<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

requireAuth();

$roomId = (int)($_GET['room_id'] ?? 0);
if ($roomId <= 0) {
    jsonError('Не указан кабинет', 400);
}

$pdo = db();
$st = $pdo->prepare(
    'SELECT r.id, r.room_number, r.plan_number, r.aliases, r.building_id, b.address AS building,
            mu.full_name AS mol_name
     FROM rooms r
     LEFT JOIN buildings b ON b.id = r.building_id
     LEFT JOIN users mu ON mu.id = r.mol_user_id
     WHERE r.id = ?'
);
$st->execute([$roomId]);
$room = $st->fetch();
if (!$room) {
    jsonError('Кабинет не найден', 404);
}
$room['id']          = (int)$room['id'];
$room['building_id'] = (int)$room['building_id'];

$st = $pdo->prepare(
    'SELECT e.id, e.long_name_1c, e.short_type_id, e.inventory_number, e.serial_number, e.mac_address,
            e.aliases, e.note, e.device_type_1c, e.building_id, e.room_id, e.status,
            st.short_name,
            b.address AS building,
            r.room_number AS room, r.plan_number,
            emp.full_name AS employee
     FROM equipment_local e
     LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
     LEFT JOIN buildings b  ON b.id  = e.building_id
     LEFT JOIN rooms r      ON r.id  = e.room_id
     LEFT JOIN employees emp ON emp.id = e.employee_id
     WHERE e.room_id = ? AND e.status = \'active\'
     ORDER BY st.short_name, e.long_name_1c, e.id'
);
$st->execute([$roomId]);

$items = [];
foreach ($st->fetchAll() as $row) {
    $row['id']            = (int)$row['id'];
    $row['short_type_id'] = $row['short_type_id'] !== null ? (int)$row['short_type_id'] : null;
    $row['building_id']   = $row['building_id'] !== null ? (int)$row['building_id'] : null;
    $row['room_id']       = $row['room_id'] !== null ? (int)$row['room_id'] : null;
    $items[] = $row;
}

jsonResponse(['room' => $room, 'items' => $items]);
