<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$user = requireAuth();
$pdo = db();

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

    if ($action === 'create') {
        $buildingId = (int)($body['building_id'] ?? 0);
        $chk = $pdo->prepare('SELECT id FROM buildings WHERE id = ?');
        $chk->execute([$buildingId]);
        if (!$chk->fetch()) {
            jsonError('Здание не найдено', 404);
        }
        $name = trim((string)($body['room_number'] ?? ''));
        if ($name === '' || mb_strlen($name) > 128) {
            jsonError('Название кабинета пустое или длиннее 128 символов', 422);
        }
        $aliases = [];
        foreach (preg_split('/\R/', (string)($body['aliases'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) > 128) {
                continue;
            }
            $up = mb_strtoupper($line, 'UTF-8');
            if ($up !== mb_strtoupper($name, 'UTF-8')) {
                $aliases[$up] = $line;
            }
        }
        /* Проверка уникальности: название + сопоставления против кабинетов здания */
        $candidates = [mb_strtoupper($name, 'UTF-8') => $name] + $aliases;
        $conflicts = [];
        $st = $pdo->prepare('SELECT room_number, aliases FROM rooms WHERE building_id = ?');
        $st->execute([$buildingId]);
        foreach ($st->fetchAll() as $other) {
            $otherNameUp = mb_strtoupper((string)$other['room_number'], 'UTF-8');
            $otherAliases = [];
            foreach (preg_split('/\R/', (string)$other['aliases']) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $otherAliases[mb_strtoupper($line, 'UTF-8')] = true;
                }
            }
            foreach ($candidates as $up => $orig) {
                if ($up === $otherNameUp) {
                    $conflicts[] = '«' . $orig . '» — уже название кабинета «' . $other['room_number'] . '»';
                } elseif (isset($otherAliases[$up])) {
                    $conflicts[] = '«' . $orig . '» — уже в сопоставлениях кабинета «' . $other['room_number'] . '»';
                }
            }
        }
        if ($conflicts) {
            jsonError("Значения должны быть уникальными в рамках здания:\n" . implode("\n", array_unique($conflicts)), 409);
        }
        /* Новый кабинет наследует МОЛ здания */
        $bm = $pdo->prepare('SELECT address, mol_user_id FROM buildings WHERE id = ?');
        $bm->execute([$buildingId]);
        $building = $bm->fetch();
        $ins = $pdo->prepare('INSERT INTO rooms (building_id, room_number, aliases, mol_user_id) VALUES (?,?,?,?)');
        $ins->execute([$buildingId, $name, $aliases ? implode("\n", $aliases) : null, $building['mol_user_id'] ?: null]);
        $newId = (int)$pdo->lastInsertId();
        logActivity((int)$user['id'], 'room', 'create', $name, 'Здание: ' . (string)$building['address']);
        jsonResponse(['saved' => true, 'id' => $newId]);
    }

    if ($action === 'update') {
        $roomId = (int)($body['id'] ?? 0);
        $st = $pdo->prepare('SELECT id, building_id, room_number, aliases FROM rooms WHERE id = ?');
        $st->execute([$roomId]);
        $room = $st->fetch();
        if (!$room) {
            jsonError('Кабинет не найден', 404);
        }
        $name = trim((string)($body['room_number'] ?? ''));
        if ($name === '' || mb_strlen($name) > 128) {
            jsonError('Название кабинета пустое или длиннее 128 символов', 422);
        }
        /* Сопоставления: каждое значение с новой строки */
        $aliases = [];
        foreach (preg_split('/\R/', (string)($body['aliases'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) > 128) {
                continue;
            }
            $up = mb_strtoupper($line, 'UTF-8');
            if ($up !== mb_strtoupper($name, 'UTF-8')) {
                $aliases[$up] = $line;
            }
        }
        /* Проверка уникальности: название + сопоставления против других кабинетов здания */
        $candidates = [mb_strtoupper($name, 'UTF-8') => $name] + $aliases;
        $st = $pdo->prepare('SELECT id, room_number, aliases FROM rooms WHERE building_id = ? AND id <> ?');
        $st->execute([(int)$room['building_id'], $roomId]);
        $conflicts = [];
        foreach ($st->fetchAll() as $other) {
            $otherNameUp = mb_strtoupper((string)$other['room_number'], 'UTF-8');
            $otherAliases = [];
            foreach (preg_split('/\R/', (string)$other['aliases']) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $otherAliases[mb_strtoupper($line, 'UTF-8')] = true;
                }
            }
            foreach ($candidates as $up => $orig) {
                if ($up === $otherNameUp) {
                    $conflicts[] = '«' . $orig . '» — уже название кабинета «' . $other['room_number'] . '»';
                } elseif (isset($otherAliases[$up])) {
                    $conflicts[] = '«' . $orig . '» — уже в сопоставлениях кабинета «' . $other['room_number'] . '»';
                }
            }
        }
        if ($conflicts) {
            jsonError("Значения должны быть уникальными в рамках здания:\n" . implode("\n", array_unique($conflicts)), 409);
        }
        $upd = $pdo->prepare('UPDATE rooms SET room_number = ?, aliases = ? WHERE id = ?');
        $upd->execute([$name, $aliases ? implode("\n", $aliases) : null, $roomId]);
        $details = changedFields([
            ['номер', $room['room_number'], $name],
            ['сопоставление', $room['aliases'], $aliases ? implode("\n", $aliases) : null],
        ]);
        if ($details !== null) {
            logActivity((int)$user['id'], 'room', 'update', $name, $details);
        }
        jsonResponse(['saved' => true]);
    }

    if ($action === 'delete') {
        $roomId = (int)($body['id'] ?? 0);
        if ($roomId <= 0) {
            jsonError('Не указан кабинет', 400);
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM equipment_local WHERE room_id = ?');
        $cnt->execute([$roomId]);
        $n = (int)$cnt->fetchColumn();
        if ($n > 0) {
            jsonError('В кабинете числится техника (' . $n . ' ед.) — сначала переместите её', 409);
        }
        $st = $pdo->prepare(
            'SELECT r.room_number, b.address
             FROM rooms r JOIN buildings b ON b.id = r.building_id
             WHERE r.id = ?'
        );
        $st->execute([$roomId]);
        $room = $st->fetch();
        if (!$room) {
            jsonError('Кабинет не найден', 404);
        }
        $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$roomId]);
        logActivity((int)$user['id'], 'room', 'delete', (string)$room['room_number'], 'Здание: ' . (string)$room['address']);
        jsonResponse(['deleted' => true]);
    }

    jsonError('Неизвестное действие', 400);
}

$buildingId = (int)($_GET['building_id'] ?? 0);
if ($buildingId <= 0) {
    jsonError('Не указано здание', 400);
}

$chk = $pdo->prepare('SELECT id FROM buildings WHERE id = ?');
$chk->execute([$buildingId]);
if (!$chk->fetch()) {
    jsonError('Здание не найдено', 404);
}

$st = $pdo->prepare(
    'SELECT r.id, r.room_number, r.plan_number, r.aliases,
            (SELECT COUNT(*) FROM equipment_local e
              WHERE e.room_id = r.id AND e.status = \'active\') AS equipment_count
     FROM rooms r
     WHERE r.building_id = ?
     ORDER BY r.room_number + 0, r.room_number'
);
$st->execute([$buildingId]);

$items = [];
foreach ($st->fetchAll() as $row) {
    $items[] = [
        'id'              => (int)$row['id'],
        'room_number'     => $row['room_number'],
        'plan_number'     => $row['plan_number'],
        'aliases'         => $row['aliases'],
        'equipment_count' => (int)$row['equipment_count'],
    ];
}

jsonResponse(['items' => $items]);
