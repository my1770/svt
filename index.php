<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/layout.php';
$user = requireAuth();
$isAdmin = ($user['role'] ?? '') === 'admin';
pageTop('Главная', $user);
?>
<div x-data="dashboard(<?= $isAdmin ? 'true' : 'false' ?>)" x-init="init()" class="space-y-6">

  <!-- Карточки-кнопки -->
  <div class="grid grid-cols-2 gap-4">
    <a href="rooms.php"
       class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white border-2 border-transparent p-5 flex flex-col items-center gap-2 hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,119,182,0.15)] hover:border-[#bae6fd] active:scale-95 transition-all duration-200">
      <span class="text-3xl">🏢</span>
      <span class="font-semibold text-sm text-[#1e293b]">Кабинеты</span>
    </a>
    <a href="buildings.php"
       class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white border-2 border-transparent p-5 flex flex-col items-center gap-2 hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,119,182,0.15)] hover:border-[#bae6fd] active:scale-95 transition-all duration-200">
      <span class="text-3xl">🏬</span>
      <span class="font-semibold text-sm text-[#1e293b]">Здания</span>
    </a>
    <a href="scan.php"
       class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white border-2 border-transparent p-5 flex flex-col items-center gap-2 hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,119,182,0.15)] hover:border-[#bae6fd] active:scale-95 transition-all duration-200">
      <span class="text-3xl">📷</span>
      <span class="font-semibold text-sm text-[#1e293b]">Инвентаризация</span>
    </a>
    <a href="report.php"
       class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white border-2 border-transparent p-5 flex flex-col items-center gap-2 hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,119,182,0.15)] hover:border-[#bae6fd] active:scale-95 transition-all duration-200">
      <span class="text-3xl">📊</span>
      <span class="font-semibold text-sm text-[#1e293b]">Отчёт</span>
    </a>
  </div>

  <!-- Глобальный поиск -->
  <section class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3" id="search-block">
    <h2 class="font-bold text-[#0c4a6e]">Поиск техники</h2>
    <input x-ref="searchInput" x-model="q" @input="onSearchInput()" type="search"
           placeholder="Название, инвентарный или серийный номер…"
           class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
    <div x-show="searching" class="text-sm text-[#64748b]">Поиск…</div>
    <ul x-show="results.length" class="divide-y divide-slate-100">
      <template x-for="item in results" :key="item.id">
        <li @click="openItem(item)" class="py-2.5 cursor-pointer active:bg-slate-50 rounded-lg px-1">
          <div class="font-medium text-sm" x-text="item.short_name || item.long_name_1c"></div>
          <div class="text-xs text-slate-500">
            <span x-show="item.inventory_number">инв: <b x-text="item.inventory_number"></b></span>
            <span x-show="item.serial_number"> · сер: <b x-text="item.serial_number"></b></span>
          </div>
          <div class="text-xs text-slate-400" x-text="(item.building || '—') + ' · каб. ' + (item.room || '—')"></div>
        </li>
      </template>
    </ul>
    <div x-show="q.length >= 2 && !searching && !results.length" class="text-sm text-[#64748b]">Ничего не найдено</div>
    <button x-show="hasMore" @click="loadMore()" :disabled="searching"
            class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">
      Ещё
    </button>
  </section>

  <!-- Bottom-sheet: действия с найденной техникой -->
  <div x-show="sheet" x-cloak class="fixed inset-0 z-50 flex items-end justify-center" @keydown.escape.window="sheet = null">
    <div class="absolute inset-0 bg-black/40" @click="sheet = null"></div>
    <div class="relative bg-white w-full max-w-lg rounded-t-[24px] shadow-[0_20px_60px_rgba(0,119,182,0.12)] p-4 space-y-3 max-h-[85vh] overflow-y-auto">
      <template x-if="sheet">
        <div class="space-y-3">
          <div>
            <div class="font-bold text-[#0c4a6e]" x-text="sheet.short_name || sheet.long_name_1c"></div>
            <div class="text-xs text-slate-500" x-text="sheet.long_name_1c"></div>
            <div class="text-sm mt-1">
              <span x-show="sheet.inventory_number">инв: <b x-text="sheet.inventory_number"></b></span>
              <span x-show="sheet.serial_number"> · сер: <b x-text="sheet.serial_number"></b></span>
            </div>
            <div class="text-xs text-slate-500" x-text="(sheet.building || '—') + ' · каб. ' + (sheet.room || '—')"></div>
          </div>
          <div class="flex gap-2">
            <a :href="sheet.room_id ? ('room.php?id=' + sheet.room_id) : '#'"
               class="flex-1 text-center bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-3 py-2 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200">
              Открыть кабинет
            </a>
            <button @click="loadHistory(sheet.id)"
                    class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-3 py-2 text-sm font-medium transition-all duration-200">
              История
            </button>
          </div>
          <div x-show="historyLoading" class="text-sm text-slate-500">Загрузка истории…</div>
          <ul x-show="history.length" class="text-sm divide-y divide-slate-100 border-t border-slate-100 pt-2">
            <template x-for="m in history" :key="m.id">
              <li class="py-2">
                <div class="text-xs text-slate-400" x-text="m.created_at + (m.user ? ' · ' + m.user : '')"></div>
                <div>
                  <span x-text="(m.from_building || '—') + ', каб. ' + (m.from_room || '—')"></span>
                  →
                  <span x-text="(m.to_building || '—') + ', каб. ' + (m.to_room || '—')"></span>
                </div>
                <div class="text-xs text-slate-500" x-show="m.comment" x-text="m.comment"></div>
              </li>
            </template>
          </ul>
          <button @click="sheet = null" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Закрыть</button>
        </div>
      </template>
    </div>
  </div>
</div>

<script>
function dashboard(isAdmin) {
  return {
    isAdmin: !!isAdmin,
    saving: false,
    q: '',
    results: [],
    searching: false,
    hasMore: false,
    searchOffset: 0,
    sheet: null,
    history: [],
    historyLoading: false,
    init() {
      this._debouncedSearch = App.debounce(() => this.doSearch(), 300);
    },
    onSearchInput() {
      if (this.q.trim().length < 2) { this.results = []; this.hasMore = false; return; }
      this._debouncedSearch();
    },
    async doSearch() {
      this.searchOffset = 0;
      this.searching = true;
      try {
        const d = await App.api('api_search.php?q=' + encodeURIComponent(this.q.trim()));
        this.results = d.items || [];
        this.hasMore = !!d.has_more;
        this.searchOffset = this.results.length;
      } catch (e) { App.toast(e.message, 'error'); }
      this.searching = false;
    },
    async loadMore() {
      this.searching = true;
      try {
        const d = await App.api('api_search.php?q=' + encodeURIComponent(this.q.trim()) + '&offset=' + this.searchOffset);
        const more = d.items || [];
        this.results = this.results.concat(more);
        this.hasMore = !!d.has_more;
        this.searchOffset += more.length;
      } catch (e) { App.toast(e.message, 'error'); }
      this.searching = false;
    },
    openItem(item) {
      this.sheet = item;
      this.history = [];
    },
    async loadHistory(id) {
      this.historyLoading = true;
      try {
        const d = await App.api('api_equipment.php?id=' + encodeURIComponent(id));
        this.history = d.movements || [];
        if (!this.history.length) App.toast('Перемещений не было', 'info');
      } catch (e) { App.toast(e.message, 'error'); }
      this.historyLoading = false;
    }
  };
}
</script>
<?php pageBottom(); ?>
