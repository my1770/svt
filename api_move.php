<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$user = requireRole('admin', 'economist');
requireCsrf();

$data = requestJson();

/* Одна или несколько единиц техники (единый пункт назначения) */
$equipmentIds = [];
$ids = $data['equipment_ids'] ?? null;
if (is_array($ids)) {
    foreach ($ids as $v) {
        $v = (int)$v;
        if ($v > 0) {
            $equipmentIds[$v] = true;
        }
    }
} else {
    $single = (int)($data['equipment_id'] ?? 0);
    if ($single > 0) {
        $equipmentIds[$single] = true;
    }
}
$equipmentIds = array_keys($equipmentIds);

$toBuildingId = (int)($data['to_building_id'] ?? 0);
$toRoomId     = isset($data['to_room_id']) && (int)$data['to_room_id'] > 0 ? (int)$data['to_room_id'] : null;
$newRoom      = trim((string)($data['new_room'] ?? ''));
$comment      = trim((string)($data['comment'] ?? ''));

if (!$equipmentIds) {
    jsonError('Не указана техника', 400);
}
if ($toBuildingId <= 0) {
    jsonError('Не указано здание назначения', 400);
}
if ($toRoomId === null && $newRoom === '') {
    jsonError('Не указан кабинет назначения', 400);
}

$pdo = db();

$st = $pdo->prepare(
    'SELECT id, building_id, room_id, long_name_1c, inventory_number, serial_number, short_type_id, status
     FROM equipment_local WHERE id = ?'
);
$equipment = [];
foreach ($equipmentIds as $equipmentId) {
    $st->execute([$equipmentId]);
    $row = $st->fetch();
    if (!$row) {
        jsonError('Техника не найдена', 404);
    }
    if ((string)$row['status'] !== 'active') {
        jsonError('Техника со статусом «списано» не может быть перемещена', 409);
    }
    $equipment[(int)$row['id']] = $row;
}

$st = $pdo->prepare('SELECT id, address FROM buildings WHERE id = ?');
$st->execute([$toBuildingId]);
$toBuilding = $st->fetch();
if (!$toBuilding) {
    jsonError('Здание назначения не найдено', 404);
}

$pdo->beginTransaction();
try {
    /* Новый кабинет, если передано название */
    if ($newRoom !== '') {
        $roomNumber = mb_strtoupper($newRoom);
        $sel = $pdo->prepare('SELECT id, room_number FROM rooms WHERE building_id = ? AND room_number = ?');
        $sel->execute([$toBuildingId, $roomNumber]);
        $room = $sel->fetch();
        if ($room) {
            $toRoomId = (int)$room['id'];
        } else {
            /* Новый кабинет наследует МОЛ здания */
            $bm = $pdo->prepare('SELECT mol_user_id FROM buildings WHERE id = ?');
            $bm->execute([$toBuildingId]);
            $ins = $pdo->prepare('INSERT INTO rooms (building_id, room_number, mol_user_id) VALUES (?, ?, ?)');
            $ins->execute([$toBuildingId, $roomNumber, $bm->fetchColumn() ?: null]);
            $toRoomId = (int)$pdo->lastInsertId();
        }
    }

    if ($toRoomId === null) {
        throw new RuntimeException('Не указан кабинет назначения');
    }
    $chk = $pdo->prepare('SELECT id, building_id FROM rooms WHERE id = ?');
    $chk->execute([$toRoomId]);
    $room = $chk->fetch();
    if (!$room || (int)$room['building_id'] !== $toBuildingId) {
        throw new RuntimeException('Кабинет не найден в указанном здании');
    }

    $ins = $pdo->prepare(
        'INSERT INTO movements (equipment_id, from_building_id, from_room_id, to_building_id, to_room_id, user_id, comment)
         VALUES (?,?,?,?,?,?,?)'
    );
    $upd = $pdo->prepare('UPDATE equipment_local SET building_id = ?, room_id = ? WHERE id = ?');
    foreach ($equipment as $equipmentId => $eq) {
        $ins->execute([
            $equipmentId,
            $eq['building_id'],
            $eq['room_id'],
            $toBuildingId,
            $toRoomId,
            (int)$user['id'],
            $comment !== '' ? $comment : null,
        ]);
        $upd->execute([$toBuildingId, $toRoomId, $equipmentId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError($e instanceof RuntimeException ? $e->getMessage() : 'Ошибка перемещения', 400);
}

$st = $pdo->prepare('SELECT id, room_number, building_id FROM rooms WHERE id = ?');
$st->execute([$toRoomId]);
$room = $st->fetch();

/* Журнал действий: по одной записи на каждую единицу */
$coordName = static function (?int $roomId) use ($pdo): string {
    if ($roomId === null) {
        return '—';
    }
    $stc = $pdo->prepare(
        'SELECT CONCAT(b.address, \', каб. \', r.room_number)
         FROM rooms r JOIN buildings b ON b.id = r.building_id
         WHERE r.id = ?'
    );
    $stc->execute([$roomId]);
    $c = $stc->fetchColumn();
    return $c !== false ? (string)$c : '—';
};
$stName = $pdo->prepare(
    'SELECT st.short_name, e.long_name_1c
     FROM equipment_local e
     LEFT JOIN equipment_short_types st ON st.id = e.short_type_id
     WHERE e.id = ?'
);
foreach ($equipment as $equipmentId => $eq) {
    $stName->execute([$equipmentId]);
    $eqRow = $stName->fetch();
    $eqName = trim((string)($eqRow['short_name'] ?? ''));
    if ($eqName === '') {
        $eqName = trim((string)($eqRow['long_name_1c'] ?? ''));
    }
    $eqName = equipmentTag($eqName, $eq['inventory_number'], $eq['serial_number']);
    $details = 'из ' . $coordName($eq['room_id'] !== null ? (int)$eq['room_id'] : null)
             . ' → в ' . $coordName($toRoomId);
    if ($comment !== '') {
        $details .= '; прим.: ' . $comment;
    }
    logActivity((int)$user['id'], 'equipment', 'move', $eqName, $details);
}

jsonResponse([
    'ok'   => true,
    'moved' => count($equipment),
    'room' => [
        'id'          => (int)$room['id'],
        'room_number' => $room['room_number'],
        'building_id' => (int)$room['building_id'],
    ],
]);
