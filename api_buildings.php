<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$user = requireAuth();
$pdo = db();

/* Разбор многострочного поля сопоставлений: trim, без пустых, без дублей (регистронезависимо) */
function parseAliases(string $raw, string $ownName): array
{
    $aliases = [];
    $ownUp = mb_strtoupper($ownName, 'UTF-8');
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || mb_strlen($line) > 255) {
            continue;
        }
        $up = mb_strtoupper($line, 'UTF-8');
        if ($up !== $ownUp) {
            $aliases[$up] = $line;
        }
    }
    return $aliases;
}

/* Проверка уникальности адреса + сопоставлений среди всех зданий (кроме $excludeId) */
function findBuildingConflicts(PDO $pdo, array $candidates, int $excludeId): array
{
    $st = $pdo->prepare('SELECT id, address, aliases FROM buildings WHERE id <> ?');
    $st->execute([$excludeId]);
    $conflicts = [];
    foreach ($st->fetchAll() as $other) {
        $otherAddrUp = mb_strtoupper((string)$other['address'], 'UTF-8');
        $otherAliases = [];
        foreach (preg_split('/\R/', (string)$other['aliases']) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $otherAliases[mb_strtoupper($line, 'UTF-8')] = true;
            }
        }
        foreach ($candidates as $up => $orig) {
            if ($up === $otherAddrUp) {
                $conflicts[] = '«' . $orig . '» — уже адрес здания «' . $other['address'] . '»';
            } elseif (isset($otherAliases[$up])) {
                $conflicts[] = '«' . $orig . '» — уже в сопоставлениях здания «' . $other['address'] . '»';
            }
        }
    }
    return array_unique($conflicts);
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

    if ($action === 'create' || $action === 'update') {
        $address = trim((string)($body['address'] ?? ''));
        if ($address === '' || mb_strlen($address) > 255) {
            jsonError('Адрес пустой или длиннее 255 символов', 422);
        }
        $aliases = parseAliases((string)($body['aliases'] ?? ''), $address);
        /* МОЛ — только активный пользователь системы или пусто */
        $molId = (int)($body['mol_user_id'] ?? 0);
        if ($molId > 0) {
            $st = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
            $st->execute([$molId]);
            if (!$st->fetch()) {
                jsonError('Выбранный МОЛ не найден среди активных пользователей', 422);
            }
        } else {
            $molId = null;
        }
        $excludeId = $action === 'update' ? (int)($body['id'] ?? 0) : 0;
        $candidates = [mb_strtoupper($address, 'UTF-8') => $address] + $aliases;
        $conflicts = findBuildingConflicts($pdo, $candidates, $excludeId);
        if ($conflicts) {
            jsonError("Значения должны быть уникальными среди всех зданий:\n" . implode("\n", $conflicts), 409);
        }
        $aliasStr = $aliases ? implode("\n", $aliases) : null;

        if ($action === 'create') {
            $pdo->prepare('INSERT INTO buildings (address, aliases, mol_user_id) VALUES (?,?,?)')
                ->execute([$address, $aliasStr, $molId]);
            $newId = (int)$pdo->lastInsertId();
            logActivity((int)$user['id'], 'building', 'create', $address);
            jsonResponse(['saved' => true, 'id' => $newId]);
        }
        $st = $pdo->prepare(
            'SELECT b.address, b.aliases, u.full_name AS mol_name
             FROM buildings b LEFT JOIN users u ON u.id = b.mol_user_id
             WHERE b.id = ?'
        );
        $st->execute([$excludeId]);
        $old = $st->fetch();
        if (!$old) {
            jsonError('Здание не найдено', 404);
        }
        $pdo->prepare('UPDATE buildings SET address = ?, aliases = ?, mol_user_id = ? WHERE id = ?')
            ->execute([$address, $aliasStr, $molId, $excludeId]);
        /* МОЛ здания каскадно применяется ко всем его кабинетам */
        $pdo->prepare('UPDATE rooms SET mol_user_id = ? WHERE building_id = ?')
            ->execute([$molId, $excludeId]);
        $newMolName = '';
        if ($molId !== null) {
            $st = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
            $st->execute([$molId]);
            $newMolName = (string)$st->fetchColumn();
        }
        $details = changedFields([
            ['адрес', $old['address'], $address],
            ['сопоставление', $old['aliases'], $aliasStr],
            ['МОЛ', $old['mol_name'], $newMolName],
        ]);
        if ($details !== null) {
            logActivity((int)$user['id'], 'building', 'update', $address, $details);
        }
        jsonResponse(['saved' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM equipment_local WHERE building_id = ?');
        $cnt->execute([$id]);
        $n = (int)$cnt->fetchColumn();
        if ($n > 0) {
            jsonError('В здании числится техника (' . $n . ' ед.) — сначала переместите её', 409);
        }
        $st = $pdo->prepare('SELECT address FROM buildings WHERE id = ?');
        $st->execute([$id]);
        $address = (string)$st->fetchColumn();
        $pdo->prepare('DELETE FROM rooms WHERE building_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM buildings WHERE id = ?')->execute([$id]);
        logActivity((int)$user['id'], 'building', 'delete', $address);
        jsonResponse(['deleted' => true]);
    }

    jsonError('Неизвестное действие', 400);
}

$st = $pdo->query(
    'SELECT b.id, b.address, b.aliases, b.mol_user_id,
            u.full_name AS mol_name,
            (SELECT COUNT(*) FROM equipment_local e
              WHERE e.building_id = b.id AND e.status = \'active\') AS equipment_count
     FROM buildings b
     LEFT JOIN users u ON u.id = b.mol_user_id
     ORDER BY b.address'
);

$items = [];
foreach ($st->fetchAll() as $row) {
    $items[] = [
        'id'              => (int)$row['id'],
        'address'         => $row['address'],
        'aliases'         => $row['aliases'],
        'mol_user_id'     => $row['mol_user_id'] !== null ? (int)$row['mol_user_id'] : null,
        'mol_name'        => $row['mol_name'],
        'equipment_count' => (int)$row['equipment_count'],
    ];
}

/* Варианты МОЛ — активные пользователи системы */
$molOptions = [];
foreach ($pdo->query("SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll() as $u) {
    $molOptions[] = ['id' => (int)$u['id'], 'full_name' => $u['full_name'], 'email' => $u['email']];
}

jsonResponse(['items' => $items, 'mol_options' => $molOptions]);
