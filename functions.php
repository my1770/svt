<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* В проде ворнинги/нотисы — только в лог, не в ответ (иначе ломается JSON API) */
if (!APP_DEBUG) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
/* Буферизация вывода: jsonResponse/jsonError очищают буфер, чтобы в JSON не попал лишний вывод */
if (!ob_get_level()) {
    ob_start();
}

/* ---------- Автозагрузка библиотек из lib/ (без composer) ---------- */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'PhpOffice\\PhpSpreadsheet\\' => __DIR__ . '/lib/PhpSpreadsheet/',
        'Psr\\SimpleCache\\'          => __DIR__ . '/lib/Psr/SimpleCache/',
        'Composer\\Pcre\\'            => __DIR__ . '/lib/Composer/Pcre/',
        'ZipStream\\'                 => __DIR__ . '/lib/ZipStream/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

/* ---------- Сессия ---------- */
function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------- База данных ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
    $root->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
    /* Миграции — только при смене версии схемы (один дешёвый SELECT version,
       а не куча проверок information_schema на каждый запрос) */
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (
        id TINYINT NOT NULL PRIMARY KEY,
        version INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $ver = (int)$pdo->query('SELECT version FROM schema_version WHERE id = 1 LIMIT 1')->fetchColumn();
    if ($ver !== SCHEMA_VERSION) {
        migrate($pdo);
        $pdo->prepare('INSERT INTO schema_version (id, version) VALUES (1, ?) ON DUPLICATE KEY UPDATE version = VALUES(version)')
            ->execute([SCHEMA_VERSION]);
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) NOT NULL DEFAULT '',
            role ENUM('admin','economist','viewer') NOT NULL DEFAULT 'viewer',
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            last_login DATETIME NULL,
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS verification_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            code VARCHAR(16) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_vcodes_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(255) NOT NULL,
            position VARCHAR(255) NULL,
            department VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            last_sync DATETIME NULL,
            UNIQUE KEY uq_employees_name (full_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS buildings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            address VARCHAR(255) NOT NULL,
            aliases TEXT NULL,
            mol_user_id INT NULL,
            UNIQUE KEY uq_buildings_address (address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            room_number VARCHAR(128) NOT NULL,
            plan_number VARCHAR(64) NULL,
            aliases TEXT NULL,
            mol_user_id INT NULL,
            UNIQUE KEY uq_rooms_bld_room (building_id, room_number),
            KEY ix_rooms_building (building_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS equipment_short_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            short_name VARCHAR(128) NOT NULL,
            UNIQUE KEY uq_est_name (short_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS equipment_mapping (
            id INT AUTO_INCREMENT PRIMARY KEY,
            long_name_1c VARCHAR(512) NOT NULL,
            short_type_id INT NOT NULL,
            UNIQUE KEY uq_mapping_long (long_name_1c(255)),
            KEY ix_mapping_type (short_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS equipment_local (
            id INT AUTO_INCREMENT PRIMARY KEY,
            long_name_1c VARCHAR(512) NOT NULL DEFAULT '',
            short_type_id INT NULL,
            inventory_number VARCHAR(128) NULL,
            serial_number VARCHAR(128) NULL,
            mac_address VARCHAR(64) NULL,
            aliases TEXT NULL,
            device_type_1c VARCHAR(128) NULL,
            building_id INT NULL,
            room_id INT NULL,
            employee_id INT NULL,
            status ENUM('active','written_off') NOT NULL DEFAULT 'active',
            last_seen_in_1c DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY ix_eq_inv (inventory_number),
            KEY ix_eq_ser (serial_number),
            KEY ix_eq_room (room_id),
            KEY ix_eq_building (building_id),
            KEY ix_eq_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            equipment_id INT NOT NULL,
            from_building_id INT NULL,
            from_room_id INT NULL,
            to_building_id INT NULL,
            to_room_id INT NULL,
            user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            comment TEXT NULL,
            KEY ix_mov_eq (equipment_id),
            KEY ix_mov_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS discrepancies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            import_batch_id VARCHAR(36) NOT NULL,
            equipment_id INT NULL,
            type ENUM('new_arrival','write_off','unrecorded_move') NOT NULL,
            details JSON NULL,
            resolved TINYINT(1) NOT NULL DEFAULT 0,
            resolved_by INT NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_disc_batch (import_batch_id),
            KEY ix_disc_type_res (type, resolved)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS inventory_scans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            equipment_id INT NULL,
            user_id INT NOT NULL,
            building_id INT NULL,
            room_id INT NULL,
            scanned_code VARCHAR(255) NOT NULL,
            result ENUM('found','moved','not_found') NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_scan_created (created_at),
            KEY ix_scan_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS import_batches (
            id VARCHAR(36) PRIMARY KEY,
            kind ENUM('1c','stock') NOT NULL,
            filename VARCHAR(255) NOT NULL DEFAULT '',
            building_id INT NULL,
            status ENUM('analyzed','mapping_required','awaiting_confirm','completed','failed') NOT NULL DEFAULT 'analyzed',
            stats JSON NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            KEY ix_batch_kind (kind, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS import_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(36) NOT NULL,
            level ENUM('info','warning','conflict') NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_ilog_batch (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            entity ENUM('equipment','room','building') NOT NULL,
            action ENUM('create','update','move','delete','write_off') NOT NULL,
            entity_name VARCHAR(512) NOT NULL DEFAULT '',
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_al_created (created_at),
            KEY ix_al_entity (entity, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    /* Добавление колонок в существующие таблицы (неразрушающая миграция) */
    $colSt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $addColumn = static function (string $table, string $column, string $ddl) use ($pdo, $colSt): void {
        $colSt->execute([$table, $column]);
        if (!(int)$colSt->fetchColumn()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $ddl");
        }
    };
    $addColumn('rooms', 'aliases', 'TEXT NULL');
    $addColumn('rooms', 'mol_user_id', 'INT NULL');
    $addColumn('buildings', 'aliases', 'TEXT NULL');
    $addColumn('buildings', 'mol_user_id', 'INT NULL');
    $addColumn('equipment_local', 'aliases', 'TEXT NULL');
    $addColumn('equipment_local', 'note', 'TEXT NULL');
    /* Журнал: действие «Списание» (для старых баз) */
    $actType = (string)$pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'action'"
    )->fetchColumn();
    if ($actType !== '' && !str_contains($actType, "'write_off'")) {
        $pdo->exec("ALTER TABLE activity_log MODIFY COLUMN action ENUM('create','update','move','delete','write_off') NOT NULL");
    }
    /* Служебный МОЛ «Не определён» */
    $pdo->exec("INSERT IGNORE INTO employees (id, full_name) VALUES (1, 'Не определён')");
}

function importLog(string $batchId, string $level, string $message): void
{
    db()->prepare('INSERT INTO import_log (batch_id, level, message) VALUES (?,?,?)')
        ->execute([$batchId, $level, $message]);
}

/* Журнал действий: техника / кабинеты / здания (только для просмотра admin) */
function logActivity(int $userId, string $entity, string $action, string $entityName, ?string $details = null): void
{
    db()->prepare('INSERT INTO activity_log (user_id, entity, action, entity_name, details) VALUES (?,?,?,?,?)')
        ->execute([$userId, $entity, $action, mb_substr($entityName, 0, 512), $details]);
}

/* Человекочитаемый список изменённых полей: «поле: "прежнее" → "новое"» (только изменённые) */
function changedFields(array $pairs): ?string
{
    $fmt = static function ($v): string {
        $v = str_replace("\n", '; ', trim((string)($v ?? '')));
        return $v !== '' ? $v : '—';
    };
    $parts = [];
    foreach ($pairs as $p) {
        [$label, $old, $new] = $p;
        if (trim((string)($old ?? '')) !== trim((string)($new ?? ''))) {
            $parts[] = $label . ': «' . $fmt($old) . '» → «' . $fmt($new) . '»';
        }
    }
    return $parts ? implode('; ', $parts) : null;
}

/* Идентификатор техники для журнала: «Название (инв. …; сер. …)» — только заполненные номера */
function equipmentTag(string $name, ?string $inventoryNumber, ?string $serialNumber): string
{
    $parts = [];
    $inv = trim((string)($inventoryNumber ?? ''));
    $ser = trim((string)($serialNumber ?? ''));
    if ($inv !== '') {
        $parts[] = 'инв. ' . $inv;
    }
    if ($ser !== '') {
        $parts[] = 'сер. ' . $ser;
    }
    return $parts ? $name . ' (' . implode('; ', $parts) . ')' : $name;
}

/**
 * Валидация загруженного .xlsx (кроме ошибки загрузки и размера):
 * расширение, ZIP-сигнатура (magic bytes) и MIME через finfo.
 * finfo для xlsx не 100% надёжен, поэтому MIME — второй слой с широким
 * белым списком; окончательная проверка содержимого — открытие PhpSpreadsheet.
 * Возвращает null при успехе или текст ошибки при отказе.
 */
function validateXlsxUpload(array $file): ?string
{
    $origName = (string)($file['name'] ?? '');
    if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'xlsx') {
        return 'Ожидается файл формата .xlsx';
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) {
        return 'Файл не получен';
    }
    /* xlsx — это ZIP-архив: первые 4 байта всегда "PK\x03\x04" */
    $fh = @fopen($tmp, 'rb');
    if ($fh === false) {
        return 'Файл не читается';
    }
    $magic = (string)fread($fh, 4);
    fclose($fh);
    if ($magic !== "PK\x03\x04") {
        return 'Файл не является корректным .xlsx (нет ZIP-сигнатуры)';
    }
    /* Второй слой: MIME-тип через finfo (широкий белый список — finfo для xlsx нестабилен) */
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
            $allowed = [
                'application/zip',
                'application/x-zip-compressed',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/xml',
                'text/xml',
            ];
            if ($mime !== '' && !in_array($mime, $allowed, true)) {
                appLog('upload: неожиданный MIME ' . $origName . ' (' . $mime . ')');
                return 'Файл не похож на .xlsx (тип: ' . $mime . ')';
            }
        }
    }
    return null;
}

/* ---------- Вывод и JSON ---------- */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(mixed $data, int $code = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $code = 400): never
{
    jsonResponse(['ok' => false, 'error' => $message], $code);
}

function requestJson(): array
{
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

/* ---------- CSRF ---------- */
function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function requireCsrf(): void
{
    startSession();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        jsonError('CSRF: недействительный токен', 403);
    }
}

/* ---------- Авторизация ---------- */
function currentUser(): ?array
{
    startSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $st->execute([$_SESSION['user_id']]);
    $user = $st->fetch();
    return $user ?: null;
}

function requireAuth(): array
{
    $user = currentUser();
    if (!$user) {
        $isApi = str_starts_with(basename($_SERVER['SCRIPT_NAME'] ?? ''), 'api_');
        if ($isApi) {
            jsonError('Требуется авторизация', 401);
        }
        header('Location: auth.php');
        exit;
    }
    return $user;
}

function requireRole(string ...$roles): array
{
    $user = requireAuth();
    if (!in_array($user['role'], $roles, true)) {
        $isApi = str_starts_with(basename($_SERVER['SCRIPT_NAME'] ?? ''), 'api_');
        if ($isApi) {
            jsonError('Недостаточно прав', 403);
        }
        http_response_code(403);
        echo 'Недостаточно прав';
        exit;
    }
    return $user;
}

/* ---------- Суперадминистратор ---------- */
function isSuperAdmin(array $user): bool
{
    return strcasecmp((string)($user['email'] ?? ''), WEB_NAME) === 0;
}

/* ---------- Yandex 360: сервисный токен (как в edo, только для синхронизации справочника) ---------- */
function getAccessToken(): ?string
{
    $tokenFile = __DIR__ . '/.access_token.json';
    $tokens = [];
    if (file_exists($tokenFile)) {
        $content = file_get_contents($tokenFile);
        $tokens = $content ? json_decode($content, true) : [];
        if (!is_array($tokens)) {
            $tokens = [];
        }
    }
    if (!empty($tokens['access_token']) && !empty($tokens['expires_at'])
        && $tokens['expires_at'] > time() + 60) {
        return $tokens['access_token'];
    }
    return refreshAccessToken();
}

function refreshAccessToken(): ?string
{
    if (!function_exists('curl_init')) {
        appLog('oauth: на сервере отсутствует расширение curl');
        return null;
    }
    $ch = curl_init('https://oauth.yandex.ru/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => REFRESH_TOKEN,
            'client_id'     => CLIENT_ID,
            'client_secret' => CLIENT_SECRET,
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = $resp ? json_decode($resp, true) : null;
    if (empty($data['access_token'])) {
        appLog('oauth: не удалось обновить access_token: ' . (string)$resp);
        return null;
    }
    $tokens = [
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ];
    $fp = fopen(__DIR__ . '/.access_token.json', 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($tokens));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $data['access_token'];
}

/* ---------- Yandex 360 Directory API: список пользователей организации ---------- */
function yandex360GetOrgUsers(): array
{
    $token = getAccessToken();
    if (!$token) {
        return [];
    }
    $users = [];
    $page = 1;
    do {
        $url = 'https://api360.yandex.net/directory/v1/org/' . rawurlencode(ORG_ID)
             . '/users?page=' . $page . '&perPage=1000';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: OAuth ' . $token],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $resp ? json_decode($resp, true) : null;
        if ($code !== 200 || !isset($data['users'])) {
            return [];
        }
        foreach ($data['users'] as $u) {
            $users[] = $u;
        }
        $pages = (int)($data['pages'] ?? 1);
        $page++;
    } while ($page <= $pages);
    return $users;
}

/**
 * Синхронизация users с организацией Yandex 360 (зеркалирование по email):
 * новые из организации добавляются (роль viewer), отсутствующие в организации —
 * деактивируются (is_active=0, запись сохраняется для восстановления),
 * кроме суперадминистратора WEB_NAME; у остальных обновляется ФИО.
 * Пустой/неудачный ответ API или подозрительно неполный список (меньше 80% активных
 * локальных пользователей) — ошибка, локальная база не трогается (защита от случайной зачистки).
 */
function syncUsersFromYandex(?array $remote = null): array
{
    if ($remote === null) {
        $remote = yandex360GetOrgUsers();
    }
    if ($remote === []) {
        return ['ok' => false, 'error' => 'Не удалось получить список пользователей из Yandex 360 (проверьте REFRESH_TOKEN/ORG_ID)'];
    }

    $pdo = db();
    $local = [];
    $activeLocal = 0;
    foreach ($pdo->query('SELECT id, email, full_name, role, is_active FROM users')->fetchAll() as $r) {
        $local[mb_strtolower((string)$r['email'], 'UTF-8')] = $r;
        if ((int)$r['is_active'] === 1) {
            $activeLocal++;
        }
    }

    $added = 0; $updated = 0; $seen = [];
    $ins = $pdo->prepare("INSERT INTO users (email, full_name, role, is_active) VALUES (?,?,'viewer',0)");
    $upd = $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?');
    foreach ($remote as $u) {
        $email = mb_strtolower(trim((string)($u['email'] ?? '')), 'UTF-8');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $seen[] = $email;
        $name = trim(implode(' ', array_filter([
            $u['name']['last'] ?? '', $u['name']['first'] ?? '', $u['name']['middle'] ?? '',
        ])));
        if (isset($local[$email])) {
            if ($name !== '' && $name !== (string)$local[$email]['full_name']) {
                $upd->execute([$name, (int)$local[$email]['id']]);
                $updated++;
            }
        } else {
            $ins->execute([$email, $name]);
            $added++;
        }
    }

    /* Порог безопасности: если организация вернула заметно меньше людей, чем активных
       локальных пользователей, список неполный (сбой/пагинация) — не деактивируем. */
    if ($activeLocal >= 5 && count($seen) < 0.8 * $activeLocal) {
        return ['ok' => false, 'error' => 'Список из Yandex 360 подозрительно мал ('
            . count($seen) . ' из ' . $activeLocal . ' активных) — синхронизация отменена'];
    }

    $deactivated = 0;
    $deact = $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
    foreach ($local as $email => $r) {
        if (strcasecmp($email, WEB_NAME) === 0) {
            continue; // суперадминистратора не трогаем никогда
        }
        if (!in_array($email, $seen, true) && (int)$r['is_active'] === 1) {
            /* Деактивация вместо физического удаления: доступ закрыт,
               запись (и роль) сохранена для восстановления одним UPDATE */
            $deact->execute([(int)$r['id']]);
            $deactivated++;
        }
    }

    return ['ok' => true, 'total_remote' => count($seen), 'added' => $added, 'updated' => $updated, 'deactivated' => $deactivated];
}

/* ---------- Коды подтверждения входа (как в edo) ---------- */
function generateCode(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function saveCode(string $email, string $code): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM verification_codes WHERE email = ?')->execute([$email]);
    $pdo->prepare('INSERT INTO verification_codes (email, code, attempts, expires_at)
                   VALUES (?, ?, 0, DATE_ADD(NOW(), INTERVAL ? MINUTE))')
        ->execute([$email, $code, CODE_TTL_MINUTES]);
}

function verifyCode(string $email, string $code): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM verification_codes WHERE email = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $st->execute([$email]);
    $row = $st->fetch();

    if (!$row) {
        return ['success' => false, 'error' => 'Код не найден или срок действия истёк. Запросите новый код.'];
    }
    if ((int)$row['attempts'] >= CODE_MAX_ATTEMPTS) {
        return ['success' => false, 'error' => 'Превышено количество попыток. Запросите новый код.'];
    }
    if (!hash_equals((string)$row['code'], $code)) {
        $pdo->prepare('UPDATE verification_codes SET attempts = attempts + 1 WHERE id = ?')
            ->execute([$row['id']]);
        $left = CODE_MAX_ATTEMPTS - (int)$row['attempts'] - 1;
        return ['success' => false, 'error' => 'Неверный код', 'attempts_left' => max(0, $left)];
    }
    $pdo->prepare('DELETE FROM verification_codes WHERE id = ?')->execute([$row['id']]);
    return ['success' => true];
}

function cleanOldCodes(): int
{
    $st = db()->prepare('DELETE FROM verification_codes WHERE expires_at < NOW() - INTERVAL 1 DAY');
    $st->execute();
    return $st->rowCount();
}

/* ---------- Почта (PHPMailer из lib/, как в edo) ---------- */
function sendCodeEmail(string $email, string $code): bool
{
    $subject = 'Код подтверждения входа — Учёт СВТ';
    $body    = "Ваш код подтверждения: {$code}\n\n"
             . 'Код действителен в течение ' . CODE_TTL_MINUTES . " минут.\n"
             . 'Если вы не запрашивали код, проигнорируйте это письмо.';

    /* Основной канал: SMTP из config */
    if (smtpSend($email, $subject, $body, [
        'host' => SMTP_HOST, 'port' => SMTP_PORT, 'secure' => SMTP_SECURE,
        'user' => SMTP_USER, 'pass' => SMTP_PASS,
        'from' => SMTP_FROM,
    ], 'основной SMTP')) {
        return true;
    }

    /* Резервный канал: SMTP рег-ру (если заполнен) */
    if (trim((string)SMTP_BACKUP_HOST) !== '') {
        appLog('mail: основной SMTP не сработал — пробую резервный канал ('
            . SMTP_BACKUP_HOST . ':' . SMTP_BACKUP_PORT . ')');
        return smtpSend($email, $subject, $body, [
            'host' => SMTP_BACKUP_HOST, 'port' => SMTP_BACKUP_PORT, 'secure' => SMTP_BACKUP_SECURE,
            'user' => SMTP_BACKUP_USER, 'pass' => SMTP_BACKUP_PASS,
            /* отправитель резервного канала — аккаунт, от имени которого шлём (требование рег.ру) */
            'from' => SMTP_BACKUP_USER,
        ], 'резервный SMTP');
    }

    return false;
}

/* Отправка одного письма через указанный SMTP-канал. true — доставлено, false — сбой (в app.log) */
function smtpSend(string $to, string $subject, string $body, array $s, string $label): bool
{
    $mailerPath = __DIR__ . '/lib/PHPMailer/PHPMailer.php';
    $smtpPath   = __DIR__ . '/lib/PHPMailer/SMTP.php';
    $excPath    = __DIR__ . '/lib/PHPMailer/Exception.php';
    if (!is_file($mailerPath) || !is_file($smtpPath) || !is_file($excPath)) {
        appLog('mail: не найдены файлы PHPMailer в lib/PHPMailer/');
        return false;
    }
    require_once $excPath;
    require_once $mailerPath;
    require_once $smtpPath;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = (string)$s['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)$s['user'];
        $mail->Password   = (string)$s['pass'];
        $mail->SMTPSecure = (string)$s['secure'];
        $mail->Port       = (int)$s['port'];
        $mail->Timeout    = SMTP_TIMEOUT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom((string)$s['from'], SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->Subject = $subject;
        $mail->Body    = $body;

        if ($mail->send()) {
            return true;
        }
        appLog("mail: {$label} не отправил письмо на {$to}");
    } catch (Throwable $e) {
        appLog("mail: {$label} сбой при отправке на {$to} (" . $e->getMessage() . ')');
    }
    return false;
}

/* ---------- Лог приложения ---------- */
function appLog(string $message): void
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0750, true);
    }
    @file_put_contents(LOG_DIR . 'app.log', date('Y-m-d H:i:s') . ' ' . $message . "\n", FILE_APPEND);
}
