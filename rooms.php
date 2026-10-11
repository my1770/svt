<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/layout.php';
$user = requireAuth();
$isAdmin = ($user['role'] ?? '') === 'admin';
/* Предвыбранное здание (при возврате из карточки кабинета) */
$preselect = (int)($_GET['building'] ?? 0);
pageTop('Кабинеты', $user);
?>
<div x-data="roomsPage(<?= $isAdmin ? 'true' : 'false' ?>, <?= $preselect ?>)" x-init="init()" class="space-y-4">

  <section class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
    <select x-model="buildingId" @change="onBuildingChange()"
            class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
      <option value="">— Выберите здание —</option>
      <template x-for="b in buildings" :key="b.id">
        <option :value="b.id" x-text="b.address + ' (' + b.equipment_count + ' ед.)'"></option>
      </template>
    </select>
  </section>

  <!-- Добавление кабинета (админ) -->
  <template x-if="isAdmin">
    <button x-show="buildingId && !creatingRoom" @click="startCreateRoom()"
            class="w-full text-left rounded-[14px] border-2 border-dashed border-[#bae6fd] px-3 py-2.5 text-sm text-[#0077B6] hover:bg-[#f0f9ff] hover:border-[#00B4D8] transition-all duration-200">
      + Добавить кабинет
    </button>
  </template>
  <div x-show="creatingRoom" x-cloak class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
    <h3 class="font-semibold text-sm text-[#0c4a6e]">Новый кабинет</h3>
    <input x-model="roomForm.room_number" placeholder="Название кабинета"
           class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
    <textarea x-model="roomForm.aliases" rows="3" placeholder="Сопоставление (каждое значение с новой строки)"
              class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
    <div x-show="roomFormError" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="roomFormError"></div>
    <div class="flex gap-2">
      <button @click="creatingRoom = false" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Отмена</button>
      <button @click="saveCreateRoom()" :disabled="saving || !roomForm.room_number.trim()"
              class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium shadow transition-all duration-200 disabled:opacity-40"
              x-text="saving ? 'Сохранение…' : 'Сохранить'"></button>
    </div>
  </div>

  <div x-show="loadingRooms" class="text-sm text-[#64748b]">Загрузка кабинетов…</div>

  <section class="space-y-2">
    <template x-for="r in rooms" :key="r.id">
      <a :href="'room.php?id=' + r.id + '&building=' + buildingId"
         class="flex items-center justify-between rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 border-2 border-transparent hover:border-[#bae6fd] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,119,182,0.15)] transition-all duration-200">
        <span class="font-medium text-sm">
          Кабинет <b x-text="r.room_number"></b>
          <span class="text-xs text-slate-400" x-show="r.plan_number" x-text="'(план: ' + r.plan_number + ')'"></span>
        </span>
        <span class="text-xs bg-[#e0f2fe] text-[#0077B6] rounded-full px-2 py-0.5" x-text="r.equipment_count + ' ед.'"></span>
      </a>
    </template>
    <div x-show="buildingId && !loadingRooms && !rooms.length" class="rounded-[20px] bg-white shadow-[0_4px_20px_rgba(0,0,0,0.04)] p-4 text-sm text-[#64748b]">
      В этом здании кабинетов нет.
    </div>
  </section>
</div>

<script>
function roomsPage(isAdmin, preselect) {
  return {
    isAdmin: !!isAdmin,
    buildings: [],
    buildingId: preselect > 0 ? String(preselect) : '',
    rooms: [],
    loadingRooms: false,
    creatingRoom: false,
    roomForm: { room_number: '', aliases: '' },
    roomFormError: '',
    saving: false,

    init() {
      this.loadBuildings();
      if (this.buildingId) this.loadRooms();
    },
    async loadBuildings() {
      try {
        const d = await App.api('api_buildings.php');
        this.buildings = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
    },
    onBuildingChange() {
      this.creatingRoom = false;
      /* Состояние выбора — в URL: возврат «назад» из карточки кабинета
         вернёт на эту страницу с тем же выбранным зданием */
      const url = this.buildingId ? '?building=' + encodeURIComponent(this.buildingId) : location.pathname;
      window.history.replaceState(null, '', url);
      this.loadRooms();
    },
    async loadRooms() {
      this.rooms = [];
      if (!this.buildingId) return;
      this.loadingRooms = true;
      try {
        const d = await App.api('api_rooms.php?building_id=' + encodeURIComponent(this.buildingId));
        this.rooms = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
      this.loadingRooms = false;
    },
    startCreateRoom() {
      this.roomForm = { room_number: '', aliases: '' };
      this.roomFormError = '';
      this.creatingRoom = true;
    },
    async saveCreateRoom() {
      this.saving = true;
      this.roomFormError = '';
      try {
        await App.api('api_rooms.php', {
          method: 'POST',
          body: {
            action: 'create',
            building_id: this.buildingId,
            room_number: this.roomForm.room_number,
            aliases: this.roomForm.aliases
          }
        });
        App.toast('Кабинет добавлен', 'success');
        this.creatingRoom = false;
        this.loadRooms();
        this.loadBuildings();
      } catch (e) {
        this.roomFormError = e.message;
      }
      this.saving = false;
    }
  };
}
</script>
<?php pageBottom(); ?>
