<?php
declare(strict_types=1);
/* Отправка кода входа на email (как в edo) */
require_once __DIR__ . '/functions.php';

startSession();
requireCsrf();

$in = requestJson();
$email = mb_strtolower(trim((string)($in['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Некорректный email');
}

$pdo = db();

/* Суперадминистратор создаётся автоматически при первом входе */
if (strcasecmp($email, WEB_NAME) === 0) {
    $st = $pdo->prepare('SELECT id, role, is_active FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) {
        $pdo->prepare("INSERT INTO users (email, full_name, role, is_active) VALUES (?, 'Администратор', 'admin', 1)")
            ->execute([$email]);
    } elseif ($u['role'] !== 'admin' || !(int)$u['is_active']) {
        $pdo->prepare("UPDATE users SET role = 'admin', is_active = 1 WHERE email = ?")->execute([$email]);
    }
} else {
    /* Остальные — только предварительно заведённые суперадминистратором */
    $st = $pdo->prepare('SELECT id, is_active FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) {
        jsonError('Этот email не зарегистрирован в системе. Обратитесь к администратору.', 403);
    }
    if (!(int)$u['is_active']) {
        jsonError('Учётная запись отключена. Обратитесь к администратору.', 403);
    }
}

cleanOldCodes();
$code = generateCode();
saveCode($email, $code);

if (sendCodeEmail($email, $code)) {
    jsonResponse(['ok' => true, 'message' => 'Код отправлен']);
}
appLog('auth: не удалось отправить код на ' . $email . (APP_DEBUG ? ' (код ' . $code . ')' : ''));
jsonError('Ошибка отправки письма. Попробуйте позже или обратитесь к администратору.', 500);
