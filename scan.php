<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/layout.php';
$user = requireAuth();
$roomId = (int)($_GET['room'] ?? 0);
pageTop('Инвентаризация', $user);
?>
<div x-data="scanPage(<?= $roomId ?>)" x-init="init()" class="space-y-4">

  <!-- Предупреждение о HTTPS -->
  <div x-show="!isSecure" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-amber-50 border border-amber-300 text-amber-800 p-4 text-sm">
    <b>Камера недоступна.</b> Сканирование работает только по HTTPS (или на localhost).
    Откройте страницу по защищённому соединению.
  </div>

  <section class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
    <div class="flex items-center justify-between gap-2">
      <h1 class="font-bold text-[#0c4a6e]">Сканирование</h1>
      <select x-show="cameras.length > 1 && !running" x-model="cameraId"
              class="rounded-[14px] border-2 border-[#e2e8f0] px-2 py-1.5 text-sm bg-white max-w-[55%] focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
        <template x-for="c in cameras" :key="c.id">
          <option :value="c.id" x-text="c.label || 'Камера'"></option>
        </template>
      </select>
    </div>

    <div id="qr-reader" class="w-full rounded-[14px] overflow-hidden bg-black/5"></div>

    <div class="flex gap-2">
      <button x-show="!running" @click="start()" :disabled="!isSecure"
              class="flex-1 bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-4 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200 disabled:opacity-40">
        ▶ Запустить сканер
      </button>
      <button x-show="running" @click="stop()"
              class="flex-1 bg-[#dc2626] text-white rounded-[10px] px-4 py-2.5 text-sm font-medium hover:bg-[#b91c1c] transition-all duration-200">
        ⏸ Остановить
      </button>
    </div>
    <div class="text-xs text-[#64748b]">
      Режим непрерывный: после каждого считывания сканер готов к следующему коду.
      Последний код: <b x-text="lastCode || '—'"></b>
    </div>
  </section>

  <!-- Результат: найдено -->
  <section x-show="result" class="space-y-3">
    <template x-if="result">
      <div class="space-y-3">
        <article class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-green-50 border border-green-300 p-4">
          <div class="text-xs text-green-700 font-semibold mb-1">✔ Найдено</div>
          <div class="font-bold text-[#0c4a6e]" x-text="result.short_name || result.long_name_1c"></div>
          <div class="text-xs text-[#64748b]" x-text="result.long_name_1c"></div>
          <div class="text-sm mt-1">
            <span x-show="result.inventory_number">инв: <b x-text="result.inventory_number"></b></span>
            <span x-show="result.serial_number"> · сер: <b x-text="result.serial_number"></b></span>
          </div>
          <div class="text-sm mt-1" x-show="resultRoom">
            Числится: <b x-text="resultRoom ? resultRoom.building : ''"></b>, каб. <b x-text="resultRoom ? resultRoom.room_number : ''"></b>
          </div>
          <button @click="openMove()" class="mt-3 bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-4 py-2 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200">
            Переместить
          </button>
        </article>

        <!-- Полный состав кабинета -->
        <article x-show="roomItems.length" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4">
          <h2 class="font-semibold text-sm text-[#0c4a6e] mb-2">
            Состав кабинета <span x-text="resultRoom ? resultRoom.room_number : ''"></span>
            (<span x-text="roomItems.length"></span> ед.)
          </h2>
          <ul class="divide-y divide-slate-100 text-sm">
            <template x-for="it in roomItems" :key="it.id">
              <li class="py-1.5" :class="result && it.id === result.id ? 'bg-green-50 -mx-2 px-2 rounded-lg' : ''">
                <span class="font-medium" x-text="it.short_name || it.long_name_1c"></span>
                <span class="text-xs text-slate-500"
                      x-text="' · ' + (it.inventory_number || it.serial_number || 'б/н')"></span>
              </li>
            </template>
          </ul>
        </article>
      </div>
    </template>
  </section>

  <!-- Bottom-sheet: перемещение -->
  <div x-show="moveOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center" @keydown.escape.window="moveOpen = false">
    <div class="absolute inset-0 bg-black/40" @click="moveOpen = false"></div>
    <div class="relative bg-white w-full max-w-lg rounded-t-[24px] shadow-[0_20px_60px_rgba(0,119,182,0.12)] p-4 space-y-3 max-h-[85vh] overflow-y-auto">
      <h3 class="font-semibold text-sm text-[#0c4a6e]" x-show="mode === 'move_building'">Шаг 1. Выберите здание</h3>

      <div x-show="mode === 'move_building'" class="space-y-1">
        <template x-for="b in buildings" :key="b.id">
          <button @click="pickBuilding(b)"
                  class="w-full text-left bg-white border-2 border-[#e2e8f0] rounded-[14px] px-3 py-2.5 text-sm hover:border-[#00B4D8] hover:bg-[#f0f9ff] transition-all duration-200"
                  x-text="b.address"></button>
        </template>
      </div>

      <div x-show="mode === 'move_room'" class="space-y-2">
        <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 2. Кабинет в «<span x-text="moveBuilding && moveBuilding.address"></span>»</h3>
        <div x-show="loadingRooms" class="text-sm text-[#64748b]">Загрузка кабинетов…</div>
        <div class="max-h-60 overflow-y-auto space-y-1">
          <template x-for="r in moveRooms" :key="r.id">
            <button @click="move.to_room_id = r.id; move.new_room = ''"
                    class="w-full text-left rounded-[10px] px-3 py-2 text-sm border-2 transition-all duration-200"
                    :class="move.to_room_id === r.id ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white border-transparent shadow-[0_4px_16px_rgba(0,180,216,0.35)]' : 'bg-white border-[#e2e8f0] hover:border-[#00B4D8]'"
                    x-text="r.room_number"></button>
          </template>
        </div>
        <input x-model="move.new_room" @input="if (move.new_room.trim()) move.to_room_id = null"
               placeholder="…или введите новый кабинет"
               class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
        <div class="flex gap-2">
          <button @click="mode = 'move_building'" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
          <button @click="mode = 'move_comment'" :disabled="!move.to_room_id && !move.new_room.trim()"
                  class="flex-1 bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] py-2 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] transition-all duration-200 disabled:opacity-40">Далее</button>
        </div>
      </div>

      <div x-show="mode === 'move_comment'" class="space-y-2">
        <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 3. Комментарий (необязательно)</h3>
        <textarea x-model="move.comment" rows="2" class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
        <div class="flex gap-2">
          <button @click="mode = 'move_room'" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
          <button @click="submitMove()" :disabled="saving"
                  class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium shadow hover:shadow-[0_4px_12px_rgba(16,185,129,0.35)] transition-all duration-200 disabled:opacity-40"
                  x-text="saving ? 'Сохранение…' : 'Подтвердить'"></button>
        </div>
      </div>

      <button @click="moveOpen = false" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Закрыть</button>
    </div>
  </div>
</div>

<script src="js/vendor/html5-qrcode.min.js"></script>
<script>
function scanPage(roomId) {
  // сканер в замыкании, вне реактивных данных Alpine (иначе прокси ломает внутреннее состояние Html5Qrcode)
  let scanner = null;
  return {
    roomId: roomId,
    isSecure: location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname),
    running: false,
    cameras: [],
    cameraId: '',
    busy: false,
    lastCode: '',
    lastCodeAt: 0,
    result: null,
    resultRoom: null,
    roomItems: [],
    moveOpen: false,
    mode: 'move_building',
    buildings: [],
    moveBuilding: null,
    moveRooms: [],
    loadingRooms: false,
    move: { to_room_id: null, new_room: '', comment: '' },
    saving: false,

    async init() {
      if (!this.isSecure || typeof Html5Qrcode === 'undefined') return;
      try {
        this.cameras = await Html5Qrcode.getCameras();
        if (this.cameras.length) {
          // предпочитаем заднюю камеру
          const back = this.cameras.find(c => /back|rear|environment/i.test(c.label || ''));
          this.cameraId = (back || this.cameras[this.cameras.length - 1]).id;
        }
      } catch (e) { App.toast('Камеры не найдены: ' + e.message, 'warning'); }
    },

    async start() {
      if (this.running) return;
      const formats = [
        Html5QrcodeSupportedFormats.QR_CODE,
        Html5QrcodeSupportedFormats.CODE_128,
        Html5QrcodeSupportedFormats.CODE_39,
        Html5QrcodeSupportedFormats.EAN_13,
        Html5QrcodeSupportedFormats.EAN_8
      ];
      scanner = scanner || new Html5Qrcode('qr-reader', { formatsToSupport: formats, verbose: false });
      const config = { fps: 10, qrbox: { width: 250, height: 150 } };
      try {
        if (this.cameraId) {
          await scanner.start({ deviceId: { exact: this.cameraId } }, config, (code) => this.onScan(code), () => {});
        } else {
          await scanner.start({ facingMode: 'environment' }, config, (code) => this.onScan(code), () => {});
        }
        this.running = true;
      } catch (e) {
        App.toast('Не удалось запустить камеру: ' + e, 'error');
      }
    },

    async stop() {
      if (!scanner || !this.running) return;
      try { await scanner.stop(); } catch (e) { /* игнорируем */ }
      this.running = false;
    },

    async onScan(code) {
      const now = Date.now();
      // антидребезг: тот же код в течение 4 секунд не обрабатываем повторно
      if (this.busy || (code === this.lastCode && now - this.lastCodeAt < 4000)) return;
      this.busy = true;
      this.lastCode = code;
      this.lastCodeAt = now;
      try {
        const d = await App.api('api_scan.php', { method: 'POST', body: { code: code } });
        App.beep(880, 120);
        App.vibrate(100);
        if (d.found) {
          this.result = d.item;
          this.resultRoom = d.room || null;
          this.roomItems = d.room_items || [];
        } else {
          this.result = null;
          this.resultRoom = null;
          this.roomItems = [];
          App.toast('Не найдено: ' + code, 'error');
        }
      } catch (e) {
        App.toast(e.message, 'error');
      }
      this.busy = false;
    },

    async openMove() {
      this.moveOpen = true;
      this.mode = 'move_building';
      this.move = { to_room_id: null, new_room: '', comment: '' };
      this.moveBuilding = null;
      this.moveRooms = [];
      if (!this.buildings.length) {
        try {
          const d = await App.api('api_buildings.php');
          this.buildings = d.items || [];
        } catch (e) { App.toast(e.message, 'error'); }
      }
    },
    async pickBuilding(b) {
      this.moveBuilding = b;
      this.move.to_room_id = null;
      this.move.new_room = '';
      this.mode = 'move_room';
      this.loadingRooms = true;
      this.moveRooms = [];
      try {
        const d = await App.api('api_rooms.php?building_id=' + encodeURIComponent(b.id));
        this.moveRooms = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
      this.loadingRooms = false;
    },
    async submitMove() {
      if (!this.result) return;
      this.saving = true;
      try {
        await App.api('api_move.php', {
          method: 'POST',
          body: {
            equipment_id: this.result.id,
            to_building_id: this.moveBuilding.id,
            to_room_id: this.move.to_room_id || undefined,
            new_room: this.move.new_room.trim() || undefined,
            comment: this.move.comment.trim() || undefined
          }
        });
        App.toast('Техника перемещена', 'success');
        this.moveOpen = false;
      } catch (e) { App.toast(e.message, 'error'); }
      this.saving = false;
    }
  };
}
</script>
<?php pageBottom(); ?>
