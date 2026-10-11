<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

requireAuth();

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    jsonError('Пустой поисковый запрос', 400);
}
$offset = max(0, (int)($_GET['offset'] ?? 0));

$like = '%' . $q . '%';
$st = db()->prepare(
    'SELECT e.id, e.long_name_1c, e.inventory_number, e.serial_number, e.mac_address,
            e.device_type_1c, e.building_id, e.room_id, e.status,
            st.short_name,
            b.address AS building,
            r.room_number AS room, r.plan_number,
            emp.full_name AS employee
     FROM equipment_local e
     LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
     LEFT JOIN buildings b  ON b.id  = e.building_id
     LEFT JOIN rooms r      ON r.id  = e.room_id
     LEFT JOIN employees emp ON emp.id = e.employee_id
     WHERE e.status = \'active\'
       AND (e.long_name_1c LIKE :q1
            OR e.inventory_number LIKE :q2
            OR e.serial_number LIKE :q3
            OR e.mac_address LIKE :q4
            OR st.short_name LIKE :q5)
     ORDER BY e.id
     LIMIT 16 OFFSET ' . $offset
);
$st->execute([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like]);

$rows = $st->fetchAll();
$hasMore = count($rows) > 15;
$rows = array_slice($rows, 0, 15);

$items = [];
foreach ($rows as $row) {
    $row['id']          = (int)$row['id'];
    $row['building_id'] = $row['building_id'] !== null ? (int)$row['building_id'] : null;
    $row['room_id']     = $row['room_id'] !== null ? (int)$row['room_id'] : null;
    $items[] = $row;
}

jsonResponse(['items' => $items, 'has_more' => $hasMore]);
