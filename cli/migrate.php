<?php
declare(strict_types=1);

/* CLI-миграция схемы БД при деплое, без веб-запроса:
   php cli/migrate.php
   Прогоняет migrate() при смене SCHEMA_VERSION (идемпотентно). */

require_once __DIR__ . '/../functions.php';

$pdo = db();

$ver = (int)$pdo->query('SELECT version FROM schema_version WHERE id = 1 LIMIT 1')->fetchColumn();
echo 'Схема БД: версия ' . $ver . ' (SCHEMA_VERSION=' . SCHEMA_VERSION . ")\n";
echo $ver === SCHEMA_VERSION ? 'Схема актуальна, миграции не требуются.' : 'Применены изменения схемы.';
echo "\n";
