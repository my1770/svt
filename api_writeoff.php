<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/* Списание техники: только администратор */
$user = requireRole('admin');
requireCsrf();

$data = requestJson();

$ids = $data['equipment_ids'] ?? null;
$equipmentIds = [];
if (is_array($ids)) {
    foreach ($ids as $v) {
        $v = (int)$v;
        if ($v > 0) {
            $equipmentIds[$v] = true;
        }
    }
}
$equipmentIds = array_keys($equipmentIds);
if (!$equipmentIds) {
    jsonError('Не указана техника', 400);
}

$comment = trim((string)($data['comment'] ?? ''));
$pdo = db();

$st = $pdo->prepare(
    'SELECT e.id, e.long_name_1c, e.inventory_number, e.serial_number, e.short_type_id, e.status,
            b.address AS building, r.room_number AS room
     FROM equipment_local e
     LEFT JOIN buildings b ON b.id = e.building_id
     LEFT JOIN rooms r ON r.id = e.room_id
     WHERE e.id = ?'
);
$equipment = [];
foreach ($equipmentIds as $equipmentId) {
    $st->execute([$equipmentId]);
    $row = $st->fetch();
    if (!$row) {
        jsonError('Техника не найдена', 404);
    }
    if ((string)$row['status'] !== 'active') {
        $name = trim((string)$row['long_name_1c']);
        jsonError(($name !== '' ? '«' . $name . '»' : 'Техника') . ' уже списана', 409);
    }
    $equipment[(int)$row['id']] = $row;
}

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE equipment_local SET status = 'written_off' WHERE id = ?");
    $stShort = $pdo->prepare('SELECT short_name FROM equipment_short_types WHERE id = ?');
    foreach ($equipment as $equipmentId => $eq) {
        $upd->execute([$equipmentId]);
        $name = trim((string)$eq['long_name_1c']);
        $short = '';
        if ($eq['short_type_id'] !== null) {
            $stShort->execute([(int)$eq['short_type_id']]);
            $short = trim((string)$stShort->fetchColumn());
        }
        $eqName = $short !== '' ? $short : $name;
        $eqName = equipmentTag($eqName, $eq['inventory_number'], $eq['serial_number']);
        $details = 'Здание: ' . (string)($eq['building'] ?? '—')
                 . ', каб. ' . (string)($eq['room'] ?? '—');
        if ($comment !== '') {
            $details .= '; прим.: ' . $comment;
        }
        logActivity((int)$user['id'], 'equipment', 'write_off', $eqName, $details);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('Ошибка списания', 400);
}

jsonResponse(['ok' => true, 'written_off' => count($equipment)]);
