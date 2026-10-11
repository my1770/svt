<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/layout.php';
$user = requireAuth();
$isAdmin = ($user['role'] ?? '') === 'admin';
pageTop('Здания', $user);
?>
<div x-data="buildingsPage(<?= $isAdmin ? 'true' : 'false' ?>)" x-init="init()" class="space-y-4">

  <div x-show="loading" class="text-sm text-[#64748b]">Загрузка…</div>

  <template x-if="isAdmin">
    <button @click="startCreate()"
            class="w-full bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-4 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200">
      + Добавить здание
    </button>
  </template>

  <!-- Форма добавления/редактирования -->
  <section x-show="editing" x-cloak class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
    <h2 class="font-semibold text-sm text-[#0c4a6e]" x-text="edit.id ? 'Редактирование здания' : 'Новое здание'"></h2>
    <div>
      <label class="block text-xs text-[#64748b] mb-1">Адрес</label>
      <input x-model="edit.address"
             class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
    </div>
    <div>
      <label class="block text-xs text-[#64748b] mb-1">МОЛ (из активных пользователей)</label>
      <select x-model="edit.mol_user_id"
              class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
        <option value="">— не назначен —</option>
        <template x-for="u in molOptions" :key="u.id">
          <option :value="u.id" x-text="u.full_name + ' (' + u.email + ')'"></option>
        </template>
      </select>
    </div>
    <div>
      <label class="block text-xs text-[#64748b] mb-1">Сопоставление (каждое значение с новой строки; по ним здание находится из «Адрес хранения» в 1С)</label>
      <textarea x-model="edit.aliases" rows="4" placeholder="например:&#10;Судостроительная 43к2&#10;3 здание"
                class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
    </div>
    <div x-show="editError" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="editError"></div>
    <div class="flex gap-2">
      <button @click="editing = false" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Отмена</button>
      <button @click="save()" :disabled="saving"
              class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium shadow transition-all duration-200 disabled:opacity-40"
              x-text="saving ? 'Сохранение…' : 'Сохранить'"></button>
    </div>
  </section>

  <!-- Список зданий -->
  <section class="space-y-2">
    <template x-for="b in items" :key="b.id">
      <article class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="font-semibold text-sm text-[#0c4a6e]" x-text="b.address"></div>
            <div class="text-xs text-slate-500 mt-0.5" x-show="b.mol_name">МОЛ: <span x-text="b.mol_name"></span></div>
            <div class="text-xs text-slate-400 mt-0.5" x-show="b.aliases" x-text="b.aliases ? 'Сопоставления: ' + b.aliases.split('\n').join(', ') : ''"></div>
          </div>
          <span class="text-xs bg-[#e0f2fe] text-[#0077B6] rounded-full px-2 py-0.5 shrink-0" x-text="b.equipment_count + ' ед.'"></span>
        </div>
        <div class="mt-3 flex gap-2" x-show="isAdmin">
          <button @click="startEdit(b)"
                  class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-3 py-2 text-sm font-medium transition-all duration-200">
            ✏️ Редактировать
          </button>
          <button @click="remove(b)"
                  class="bg-[#dc2626] hover:bg-[#b91c1c] text-white rounded-[10px] px-3 py-2 text-sm font-medium transition-all duration-200">
            🗑
          </button>
        </div>
      </article>
    </template>
    <div x-show="!loading && !items.length" class="rounded-[20px] bg-white shadow-[0_4px_20px_rgba(0,0,0,0.04)] p-4 text-sm text-[#64748b]">
      Зданий пока нет.
    </div>
  </section>
</div>

<script>
function buildingsPage(isAdmin) {
  return {
    isAdmin: !!isAdmin,
    items: [],
    molOptions: [],
    loading: true,
    editing: false,
    saving: false,
    edit: { id: null, address: '', aliases: '', mol_user_id: '' },
    editError: '',

    init() { this.load(); },

    async load() {
      this.loading = true;
      try {
        const d = await App.api('api_buildings.php');
        this.items = d.items || [];
        this.molOptions = d.mol_options || [];
      } catch (e) { App.toast(e.message, 'error'); }
      this.loading = false;
    },

    startCreate() {
      this.edit = { id: null, address: '', aliases: '', mol_user_id: '' };
      this.editError = '';
      this.editing = true;
    },
    startEdit(b) {
      this.edit = { id: b.id, address: b.address, aliases: b.aliases || '', mol_user_id: b.mol_user_id || '' };
      this.editError = '';
      this.editing = true;
    },
    async save() {
      this.saving = true;
      this.editError = '';
      try {
        await App.api('api_buildings.php', {
          method: 'POST',
          body: {
            action: this.edit.id ? 'update' : 'create',
            id: this.edit.id || undefined,
            address: this.edit.address,
            aliases: this.edit.aliases,
            mol_user_id: this.edit.mol_user_id || undefined
          }
        });
        App.toast('Здание сохранено', 'success');
        this.editing = false;
        this.load();
      } catch (e) {
        this.editError = e.message;
      }
      this.saving = false;
    },
    async remove(b) {
      if (!confirm('Удалить здание «' + b.address + '»?')) return;
      try {
        await App.api('api_buildings.php', { method: 'POST', body: { action: 'delete', id: b.id } });
        App.toast('Здание удалено', 'success');
        this.load();
      } catch (e) { App.toast(e.message, 'error'); }
    }
  };
}
</script>
<?php pageBottom(); ?>
