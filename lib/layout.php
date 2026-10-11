<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';

/* Общая шапка страницы: doctype, head, верхняя панель, нижний таббар */
function pageTop(string $title, array $user): void
{
    $csrf = csrfToken();
    $role = (string)($user['role'] ?? 'viewer');
    $isAdmin = in_array($role, ['admin', 'economist'], true);
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $cssV = (int)@filemtime(__DIR__ . '/../css/app.css');
    $jsV = (int)@filemtime(__DIR__ . '/../js/app.js');

    $tabs = [
        ['href' => 'index.php',  'icon' => '🏠', 'label' => 'Главная', 'key' => 'index.php'],
        ['href' => 'scan.php',   'icon' => '📷', 'label' => 'Скан',    'key' => 'scan.php'],
        ['href' => 'report.php', 'icon' => '📊', 'label' => 'Отчёт',   'key' => 'report.php'],
    ];
    if ($isAdmin) {
        $tabs[] = ['href' => 'admin.php', 'icon' => '⚙️', 'label' => 'Админ', 'key' => 'admin.php'];
    }
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf" content="<?= h($csrf) ?>">
<meta name="theme-color" content="#0077B6">
<title><?= h($title) ?> — Учёт СВТ v <?= h(APP_VERSION) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/svg+xml" href="icon.svg">
<link rel="stylesheet" href="css/app.css?v=<?= $cssV ?>">
<script src="js/app.js?v=<?= $jsV ?>" defer></script>
<script src="js/vendor/alpine.min.js" defer></script>
</head>
<body class="min-h-screen pb-20">
<header class="bg-white/95 backdrop-blur-md border-b border-slate-200 shadow-[0_2px_12px_rgba(0,0,0,0.06)] sticky top-0 z-40">
  <div class="max-w-5xl mx-auto px-4 py-3 flex items-center gap-3">
    <a href="index.php" class="text-lg font-extrabold tracking-tight shrink-0 text-[#0c4a6e]">Учёт СВТ</a>
    <span class="text-[#64748b] text-sm truncate flex-1"><?= h($title) ?></span>
    <span class="hidden sm:block text-sm text-[#64748b] truncate max-w-[16rem]"><?= h((string)($user['full_name'] ?? '')) ?></span>
    <a href="logout.php" class="shrink-0 text-sm bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-3 py-1.5 transition-all duration-200">Выйти</a>
  </div>
</header>
<nav class="fixed bottom-0 left-0 right-0 z-40 bg-white/95 backdrop-blur-md border-t border-slate-200 shadow-[0_-2px_12px_rgba(0,0,0,0.06)]">
  <div class="max-w-5xl mx-auto flex">
    <?php foreach ($tabs as $tab): ?>
      <?php $active = $current === $tab['key']; ?>
      <a href="<?= h($tab['href']) ?>"
         class="flex-1 flex flex-col items-center gap-0.5 py-2 text-xs transition-colors duration-200 <?= $active ? 'text-[#0077B6] font-semibold' : 'text-slate-500' ?>">
        <span class="text-xl leading-none"><?= h($tab['icon']) ?></span>
        <span><?= h($tab['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>
<main class="max-w-5xl mx-auto px-4 py-4">
    <?php
}

function pageBottom(): void
{
    ?>
</main>
</body>
</html>
    <?php
}
