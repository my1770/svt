<?php
declare(strict_types=1);

/* Версии программы и схемы БД (APP_VERSION, SCHEMA_VERSION) */
require_once __DIR__ . '/version.php';

/* Администратор системы: email суперпользователя — только он управляет правами */
define('WEB_NAME', 'svt@school.moscow');

/* Yandex OAuth 360 — только для фоновой синхронизации списка пользователей организации (Directory API) */
define('CLIENT_ID',     'YOUR_YANDEX_CLIENT_ID');
define('CLIENT_SECRET', 'YOUR_YANDEX_CLIENT_SECRET');
define('REFRESH_TOKEN', 'YOUR_YANDEX_REFRESH_TOKEN');
define('ORG_ID',        'YOUR_YANDEX_ORG_ID');

/* MySQL */
define('DB_HOST', 'localhost');
define('DB_NAME', 'svt_accounting');
define('DB_USER', 'svt_user');
define('DB_PASS', 'STRONG_PASSWORD');

/* Пути */
define('UPLOAD_DIR',    __DIR__ . '/uploads/');
define('LOG_DIR',       __DIR__ . '/logs/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024);

/* SMTP (Яндекс.Почта для домена) — отправка кодов входа */
define('SMTP_HOST',      'smtp.yandex.ru');
define('SMTP_PORT',      465);
define('SMTP_SECURE',    'ssl');
define('SMTP_USER',      'robot@school.moscow');
define('SMTP_PASS',      'YOUR_SMTP_PASSWORD');
define('SMTP_FROM',      'robot@school.moscow');
define('SMTP_FROM_NAME', 'Учёт СВТ');
define('SMTP_TIMEOUT',   10); /* Таймаут SMTP, сек: не ответил — резервный канал */

/* Резервный канал отправки (хостинг reg.ru): используется, когда основной SMTP
   не ответил за SMTP_TIMEOUT секунд. SMTP_BACKUP_HOST = '' — резерв отключён. */
define('SMTP_BACKUP_HOST',   'mail.hosting.reg.ru');
define('SMTP_BACKUP_PORT',   587);
define('SMTP_BACKUP_SECURE', 'tls'); /* STARTTLS */
define('SMTP_BACKUP_USER',   'robot@svt.1770.ru');
define('SMTP_BACKUP_PASS',   'YOUR_BACKUP_SMTP_PASSWORD');

/* Коды подтверждения входа */
define('CODE_MAX_ATTEMPTS', 3);
define('CODE_TTL_MINUTES',  10);

/* Бизнес-логика */
define('SESSION_NAME', 'Y360APPSESSID');
define('APP_DEBUG', false);
