<?php
declare(strict_types=1);
/* Проверка кода и создание сессии (как в edo) */
require_once __DIR__ . '/functions.php';

startSession();
requireCsrf();

$in = requestJson();
$email = mb_strtolower(trim((string)($in['email'] ?? '')));
$code  = trim((string)($in['code'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
    jsonError('Некорректные данные');
}

$result = verifyCode($email, $code);
if (!$result['success']) {
    jsonError((string)$result['error'], 403);
}

$pdo = db();
$st = $pdo->prepare('SELECT id, is_active FROM users WHERE email = ?');
$st->execute([$email]);
$user = $st->fetch();
if (!$user || !(int)$user['is_active']) {
    jsonError('Учётная запись не найдена или отключена.', 403);
}

$pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
/* CSRF-токен обновляем после регенерации сессии */
$_SESSION['csrf'] = bin2hex(random_bytes(32));

jsonResponse(['ok' => true, 'redirect' => 'index.php']);
