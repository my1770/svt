<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$user = requireRole('admin', 'economist');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $st = db()->query(
        'SELECT id, email, full_name, role, is_active, last_login
         FROM users
         ORDER BY full_name, email, id'
    );
    $items = [];
    foreach ($st->fetchAll() as $row) {
        $items[] = [
            'id'         => (int)$row['id'],
            'email'      => $row['email'],
            'full_name'  => $row['full_name'],
            'role'       => $row['role'],
            'is_active'  => (int)$row['is_active'],
            'last_login' => $row['last_login'],
        ];
    }
    jsonResponse(['items' => $items, 'can_manage' => isSuperAdmin($user), 'super_email' => WEB_NAME]);
}

if ($method !== 'POST') {
    jsonError('Метод не поддерживается', 405);
}

requireCsrf();

$data = requestJson();
$action = (string)($data['action'] ?? '');
$pdo = db();

/* Синхронизация с Yandex 360: зеркалирование списка пользователей организации (admin и выше) */
if ($action === 'sync') {
    if ($user['role'] !== 'admin') {
        jsonError('Синхронизировать может только администратор', 403);
    }
    try {
        $stats = syncUsersFromYandex();
    } catch (Throwable $e) {
        appLog('users_sync: фатальная ошибка: ' . $e->getMessage());
        jsonError('Ошибка синхронизации: ' . $e->getMessage(), 500);
    }
    if (empty($stats['ok'])) {
        jsonError((string)($stats['error'] ?? 'Ошибка синхронизации'), 502);
    }
    appLog('users_sync (' . ($user['email'] ?? '') . '): ' . json_encode($stats, JSON_UNESCAPED_UNICODE));
    jsonResponse($stats);
}

/* Управление правами — только суперадминистратор (WEB_NAME) */
if (!isSuperAdmin($user)) {
    jsonError('Назначать права может только администратор системы (' . WEB_NAME . ')', 403);
}

$id = (int)($data['id'] ?? 0);
if ($id <= 0) {
    jsonError('Не указан пользователь', 400);
}
$st = $pdo->prepare('SELECT id, email, role, is_active FROM users WHERE id = ?');
$st->execute([$id]);
$target = $st->fetch();
if (!$target) {
    jsonError('Пользователь не найден', 404);
}
$isSelf = ((int)$user['id'] === $id) || (strcasecmp((string)$target['email'], WEB_NAME) === 0);

/* Обновление (роль, ФИО, активность) */
$newRole     = (string)$target['role'];
$newIsActive = (int)$target['is_active'];
$newName     = null;

if (array_key_exists('role', $data)) {
    $role = (string)$data['role'];
    if (!in_array($role, ['admin', 'economist', 'viewer'], true)) {
        jsonError('Недопустимая роль', 400);
    }
    if ($isSelf && $role !== 'admin') {
        jsonError('Нельзя снять роль администратора с администратора системы', 400);
    }
    $newRole = $role;
}

if (array_key_exists('is_active', $data)) {
    $newIsActive = $data['is_active'] ? 1 : 0;
    if ($isSelf && $newIsActive === 0) {
        jsonError('Нельзя деактивировать администратора системы', 400);
    }
}

if (array_key_exists('full_name', $data)) {
    $newName = trim((string)$data['full_name']);
}

if ($newName !== null) {
    $pdo->prepare('UPDATE users SET role = ?, is_active = ?, full_name = ? WHERE id = ?')
        ->execute([$newRole, $newIsActive, $newName, $id]);
} else {
    $pdo->prepare('UPDATE users SET role = ?, is_active = ? WHERE id = ?')
        ->execute([$newRole, $newIsActive, $id]);
}

jsonResponse(['ok' => true]);
