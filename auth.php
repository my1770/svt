<?php
declare(strict_types=1);
/* Вход по email + коду из письма (как в edo) */
require_once __DIR__ . '/functions.php';

startSession();
if (currentUser()) {
    header('Location: index.php');
    exit;
}
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= h($csrf) ?>">
<title>Вход — Учёт СВТ v <?= h(APP_VERSION) ?></title>
<link rel="stylesheet" href="css/app.css?v=<?= (int)@filemtime(__DIR__ . '/css/app.css') ?>">
<script defer src="js/app.js?v=<?= (int)@filemtime(__DIR__ . '/js/app.js') ?>"></script>
<script defer src="js/vendor/alpine.min.js"></script>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-sm" x-data="loginForm()">
    <div class="bg-white rounded-[24px] shadow-[0_20px_60px_rgba(0,119,182,0.12)] p-8">
        <h1 class="text-2xl font-bold text-[#0c4a6e] mb-1">Учёт СВТ</h1>
        <p class="text-sm text-[#64748b] mb-5">Вход по email и коду из письма</p>

        <div x-show="step === 1">
            <label class="block text-sm font-medium text-[#475569] mb-1">Email</label>
            <input type="email" x-model="email" @keydown.enter.prevent="sendCode()"
                   class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-[18px] py-[14px] mb-4 bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"
                   placeholder="you@school.moscow" autocomplete="email">
            <button @click="sendCode()" :disabled="busy"
                    class="w-full rounded-[10px] bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white font-semibold py-3 shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-300 disabled:opacity-50 disabled:hover:translate-y-0">
                <span x-show="!busy">Получить код</span>
                <span x-show="busy">Отправка…</span>
            </button>
        </div>

        <div x-show="step === 2" x-cloak>
            <p class="text-sm text-[#475569] mb-3">Код отправлен на <b x-text="email"></b></p>
            <label class="block text-sm font-medium text-[#475569] mb-1">Код из письма</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" x-model="code"
                   @keydown.enter.prevent="verify()"
                   class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-[18px] py-[14px] mb-4 bg-white text-center text-2xl tracking-[0.5em] focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"
                   placeholder="······" autocomplete="one-time-code">
            <button @click="verify()" :disabled="busy"
                    class="w-full rounded-[10px] bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white font-semibold py-3 mb-2 shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-300 disabled:opacity-50 disabled:hover:translate-y-0">
                <span x-show="!busy">Войти</span>
                <span x-show="busy">Проверка…</span>
            </button>
            <button @click="step = 1; code = ''" class="w-full text-sm text-[#0077B6] hover:text-[#005a8c] hover:underline py-1 transition-colors duration-200">
                Изменить email / отправить код повторно
            </button>
        </div>
    </div>
</div>
<script>
function loginForm() {
    return {
        step: 1, email: '', code: '', busy: false,
        async sendCode() {
            if (!this.email) { App.toast('Введите email', 'error'); return; }
            this.busy = true;
            try {
                await App.api('api_send_code.php', { method: 'POST', body: { email: this.email } });
                this.step = 2;
                App.toast('Код отправлен на почту', 'success');
            } catch (e) { App.toast(e.message, 'error'); }
            this.busy = false;
        },
        async verify() {
            if (!this.code) { App.toast('Введите код', 'error'); return; }
            this.busy = true;
            try {
                const r = await App.api('api_verify_code.php', { method: 'POST', body: { email: this.email, code: this.code } });
                location.href = r.redirect || 'index.php';
            } catch (e) {
                App.toast(e.message, 'error');
                this.busy = false;
            }
        },
    };
}
</script>
</body>
</html>
