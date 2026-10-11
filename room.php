<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/layout.php';
$user = requireAuth();
$roomId = (int)($_GET['id'] ?? 0);
$isAdmin = ($user['role'] ?? '') === 'admin';
/* Перемещение доступно администратору и экономисту — режим «Несколько» есть смысл показывать им */
$canMove = in_array($user['role'], ['admin', 'economist'], true);
/* Здание для возврата на страницу списка кабинетов (передано из rooms.php) */
$backBuilding = (int)($_GET['building'] ?? 0);
pageTop('Карточка кабинета', $user);
?>
<div x-data="roomPage(<?= $roomId ?>, <?= $isAdmin ? 'true' : 'false' ?>, <?= $canMove ? 'true' : 'false' ?>, <?= $backBuilding ?>)" x-init="init()" class="space-y-4">

  <div x-show="loading" class="text-sm text-slate-500">Загрузка…</div>
  <div x-show="error" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-red-50 text-red-700 p-4 text-sm" x-text="error"></div>

  <template x-if="room">
    <div class="space-y-4">
      <!-- Заголовок кабинета -->
      <section class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4">
        <button x-show="canBack" @click="goBack()"
                class="mb-2 text-sm text-[#0077B6] hover:underline">← Назад</button>
        <div class="text-xs text-[#64748b]" x-text="room.building"></div>
        <h1 class="text-xl font-bold text-[#0c4a6e]">Кабинет <span x-text="room.room_number"></span></h1>
        <div class="text-xs text-slate-400" x-show="room.plan_number" x-text="'Номер по плану: ' + room.plan_number"></div>
        <div class="text-xs text-slate-500 mt-0.5" x-show="room.mol_name">МОЛ: <span x-text="room.mol_name"></span></div>
        <div class="text-xs text-slate-400 mt-0.5" x-show="room.aliases" x-text="room.aliases ? 'Сопоставления: ' + room.aliases.split('\n').join(', ') : ''"></div>
        <div class="mt-3 flex flex-wrap gap-2">
          <template x-if="isAdmin">
            <button @click="startCreateItem()"
                    class="bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] px-4 py-2 text-sm font-medium shadow-[0_4px_16px_rgba(16,185,129,0.3)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(16,185,129,0.4)] transition-all duration-200">
              + Добавить СВТ
            </button>
          </template>
          <a :href="'scan.php?room=' + room.id"
             class="inline-block bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-4 py-2 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200">
            📷 Сканировать
          </a>
          <template x-if="canMove || isAdmin">
            <button @click="toggleMultiSelect()"
                    class="bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-4 py-2 text-sm font-medium transition-all duration-200"
                    x-text="multiSelect ? 'Отмена выбора' : '✓ Несколько'"></button>
          </template>
          <template x-if="isAdmin">
            <button @click="startEdit()"
                    class="bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-4 py-2 text-sm font-medium transition-all duration-200">
              ✏️ Редактировать
            </button>
          </template>
          <template x-if="isAdmin">
            <button @click="deleteRoom()"
                    class="bg-[#dc2626] hover:bg-[#b91c1c] text-white rounded-[10px] px-4 py-2 text-sm font-medium transition-all duration-200">
              🗑 Удалить
            </button>
          </template>
        </div>
      </section>

      <!-- Редактирование кабинета (админ) -->
      <section x-show="editing" x-cloak class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
        <h2 class="font-semibold text-sm text-[#0c4a6e]">Редактирование кабинета</h2>
        <div>
          <label class="block text-xs text-[#64748b] mb-1">Название</label>
          <input x-model="edit.room_number"
                 class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
        </div>
        <div>
          <label class="block text-xs text-[#64748b] mb-1">Сопоставление (каждое значение с новой строки; по ним кабинет находится при сверке с 1С)</label>
          <textarea x-model="edit.aliases" rows="4" placeholder="например:&#10;113а&#10;Кабинет информатики"
                    class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
        </div>
        <div x-show="editError" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="editError"></div>
        <div class="flex gap-2">
          <button @click="editing = false" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Отмена</button>
          <button @click="saveEdit()" :disabled="saving"
                  class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium shadow transition-all duration-200 disabled:opacity-40"
                  x-text="saving ? 'Сохранение…' : 'Сохранить'"></button>
        </div>
      </section>

      <!-- Список техники -->
      <section class="space-y-2">
        <div class="text-sm text-[#64748b]" x-text="'Техники: ' + items.length"></div>
        <template x-for="item in items" :key="item.id">
          <article @click="multiSelect ? toggleSelect(item) : openItem(item)"
                   class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 cursor-pointer border-2 transition-all duration-200"
                   :class="multiSelect
                     ? (isSel(item.id) ? 'border-[#0077B6] bg-[#f0f9ff]' : 'border-transparent hover:border-[#bae6fd]')
                     : 'border-transparent hover:border-[#bae6fd] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,119,182,0.15)]'">
            <div class="flex items-start justify-between gap-2">
              <label class="flex items-start gap-2 cursor-pointer min-w-0" x-show="multiSelect" @click.stop>
                <input type="checkbox" class="mt-0.5 rounded accent-[#0077B6] w-5 h-5 shrink-0"
                       :checked="isSel(item.id)" @change="toggleSelect(item)">
              </label>
              <div class="font-semibold text-sm min-w-0 break-words" x-text="item.short_name || item.long_name_1c || '—'"></div>
              <span x-show="item.status === 'written_off'"
                    class="text-xs bg-slate-200 text-slate-600 rounded-full px-2 py-0.5 shrink-0">списано</span>
            </div>
            <div class="text-xs text-slate-500 mt-0.5" x-show="item.short_name && item.long_name_1c && item.short_name !== item.long_name_1c" x-text="item.long_name_1c"></div>
            <div class="text-sm mt-1.5 space-y-0.5">
              <div x-show="item.inventory_number">Инв.: <b x-text="item.inventory_number"></b></div>
              <div x-show="item.serial_number">Сер.: <b x-text="item.serial_number"></b></div>
              <div x-show="item.mac_address" class="text-xs text-slate-500">MAC: <span x-text="item.mac_address"></span></div>
              <div x-show="item.note" class="text-xs text-slate-500">Прим.: <span x-text="item.note"></span></div>
            </div>
          </article>
        </template>
        <div x-show="!loading && room && !items.length" class="rounded-[20px] bg-white shadow-[0_4px_20px_rgba(0,0,0,0.04)] p-4 text-sm text-[#64748b]">
          В кабинете нет техники.
        </div>
      </section>

      <!-- Плавающая панель действий с выбранными -->
      <div x-show="multiSelect && selectedCount > 0" x-cloak class="fixed left-0 right-0 bottom-20 z-40 flex justify-center px-4 pointer-events-none">
        <div class="pointer-events-auto w-full max-w-lg rounded-[20px] bg-white border border-slate-100 shadow-[0_10px_40px_rgba(12,74,110,0.2)] px-4 py-3 flex items-center justify-between gap-3">
          <span class="text-sm font-semibold text-[#0c4a6e]" x-text="selectedCount + ' выбрано'"></span>
          <button @click="openMultiActions()"
                  class="bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] px-4 py-2 text-sm font-medium shadow-[0_4px_16px_rgba(16,185,129,0.3)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(16,185,129,0.4)] transition-all duration-200">
            Действия с выбранными
          </button>
        </div>
      </div>
    </div>
  </template>

  <!-- Bottom-sheet: действия / перемещение / история -->
  <div x-show="sheet" x-cloak class="fixed inset-0 z-50 flex items-end justify-center" @keydown.escape.window="closeSheet()">
    <div class="absolute inset-0 bg-black/40" @click="closeSheet()"></div>
    <div class="relative bg-white w-full max-w-lg rounded-t-[24px] shadow-[0_20px_60px_rgba(0,119,182,0.12)] p-4 space-y-3 max-h-[88vh] overflow-y-auto">
      <template x-if="sheet">
        <div class="space-y-3">
          <div>
            <div class="font-bold text-[#0c4a6e]" x-text="multiSelect ? 'Выбрано единиц: ' + selectedCount : (sheet.short_name || sheet.long_name_1c)"></div>
            <div class="text-sm" x-show="!multiSelect">
              <span x-show="sheet.inventory_number">инв: <b x-text="sheet.inventory_number"></b></span>
              <span x-show="sheet.serial_number"> · сер: <b x-text="sheet.serial_number"></b></span>
            </div>
          </div>

          <!-- Главное меню действий -->
          <div x-show="mode === 'menu'" class="space-y-2">
            <button @click="startMove()" class="w-full bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-3 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200">
              Переместить
            </button>
            <template x-if="isAdmin">
              <button @click="startEditItem()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-3 py-2.5 text-sm font-medium transition-all duration-200">
                ✏️ Редактировать
              </button>
            </template>
            <button @click="loadHistory()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-3 py-2.5 text-sm font-medium transition-all duration-200">
              История перемещений
            </button>
            <template x-if="isAdmin && sheet.status !== 'written_off'">
              <button @click="writeoffSingle()" class="w-full bg-[#fee2e2] hover:bg-[#fecaca] text-[#b91c1c] rounded-[10px] px-3 py-2.5 text-sm font-medium transition-all duration-200">
                Списать
              </button>
            </template>
          </div>

          <!-- Редактирование / добавление техники (админ) -->
          <div x-show="mode === 'edit'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]" x-text="eqEdit.id ? 'Редактирование техники' : 'Новая техника в кабинете'"></h3>
            <div>
              <label class="block text-xs text-[#64748b] mb-1">Название</label>
              <input x-model="eqEdit.long_name_1c" @input="onNameInput()" @focus="onNameInput()"
                     @blur="setTimeout(() => closeSuggestions(), 150)"
                     autocomplete="off"
                     class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              <!-- подсказки в потоке: не перекрывают остальные поля и кнопку -->
              <ul x-show="nameSuggestions.length" class="mt-1 rounded-[14px] border-2 border-[#e2e8f0] bg-white max-h-40 overflow-y-auto divide-y divide-slate-100">
                <template x-for="s in nameSuggestions" :key="s">
                  <li @mousedown.prevent="pickName(s)" class="px-3 py-2 text-sm cursor-pointer hover:bg-[#f0f9ff] active:bg-[#e0f2fe]" x-text="s"></li>
                </template>
              </ul>
            </div>
            <div>
              <label class="block text-xs text-[#64748b] mb-1">Сопоставление (названия техники из 1С, каждое с новой строки; по ним эта техника находится при сверке с 1С)</label>
              <textarea x-model="eqEdit.aliases" rows="4" placeholder="например:&#10;Моноблок HP 290 G9 Microtower Business PC&#10;МФУ Samsung SL-M2870FD/XEV"
                        class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
            </div>
            <div>
              <label class="block text-xs text-[#64748b] mb-1">Инвентарный номер</label>
              <input x-model="eqEdit.inventory_number" @blur="checkDupes()" autocomplete="off"
                     class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
            </div>
            <div>
              <label class="block text-xs text-[#64748b] mb-1">Серийный номер</label>
              <input x-model="eqEdit.serial_number" @blur="checkDupes()" autocomplete="off"
                     class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
            </div>
            <div>
              <label class="block text-xs text-[#64748b] mb-1">MAC-адрес</label>
              <input x-model="eqEdit.mac_address" @blur="checkDupes()" autocomplete="off"
                     class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
            </div>
            <div>
              <label class="block text-xs text-[#64748b] mb-1">Примечание</label>
              <textarea x-model="eqEdit.note" rows="3" placeholder="Свободное примечание к технике…"
                        class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
            </div>
            <div x-show="dupes.length" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="'Такие данные уже есть у другой техники:\n' + dupes.join('\n')"></div>
            <div x-show="editError" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="editError"></div>
            <!-- Предупреждение об уникальном названии (только при редактировании) -->
            <div x-show="scopeAsk" class="rounded-[14px] bg-amber-50 border-2 border-amber-200 p-3 space-y-2">
              <div class="text-sm text-amber-800">Такого названия ещё нет в базе. Как применить?</div>
              <button @click="saveEditItem('all')" :disabled="saving"
                      class="w-full bg-amber-500 hover:bg-amber-600 text-white rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40"
                      x-text="'Изменить у всей техники с прежним названием (' + scopeAffected + ' ед.)'"></button>
              <button @click="saveEditItem('single')" :disabled="saving"
                      class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">
                Создать как новое название (только эта единица)
              </button>
            </div>
            <div class="flex gap-2" x-show="!scopeAsk">
              <button @click="closeSheet()" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Отмена</button>
              <button @click="saveEditItem('')" :disabled="saving || dupes.length > 0 || !eqEdit.long_name_1c.trim()"
                      class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium shadow transition-all duration-200 disabled:opacity-40"
                      x-text="saving ? 'Сохранение…' : 'Сохранить'"></button>
            </div>
          </div>

          <!-- Перемещение: шаг 1 — здание -->
          <div x-show="mode === 'move_building'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 1. Выберите здание</h3>
            <template x-for="b in buildings" :key="b.id">
              <button @click="pickBuilding(b)"
                      class="w-full text-left bg-white border-2 border-[#e2e8f0] rounded-[14px] px-3 py-2.5 text-sm hover:border-[#00B4D8] hover:bg-[#f0f9ff] transition-all duration-200"
                      x-text="b.address"></button>
            </template>
          </div>

          <!-- Перемещение: шаг 2 — кабинет -->
          <div x-show="mode === 'move_room'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 2. Кабинет в «<span x-text="moveBuilding && moveBuilding.address"></span>»</h3>
            <div x-show="loadingRooms" class="text-sm text-[#64748b]">Загрузка кабинетов…</div>
            <div class="max-h-60 overflow-y-auto space-y-1">
              <template x-for="r in moveRooms" :key="r.id">
                <button @click="move.to_room_id = r.id; move.new_room = ''"
                        class="w-full text-left rounded-[10px] px-3 py-2 text-sm border-2 transition-all duration-200"
                        :class="move.to_room_id === r.id ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white border-transparent shadow-[0_4px_16px_rgba(0,180,216,0.35)]' : 'bg-white border-[#e2e8f0] hover:border-[#00B4D8]'">
                  <span x-text="r.room_number"></span>
                  <span class="text-xs opacity-70" x-show="r.plan_number" x-text="' (план: ' + r.plan_number + ')'"></span>
                </button>
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

          <!-- Перемещение: шаг 3 — комментарий -->
          <div x-show="mode === 'move_comment'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 3. Комментарий (необязательно)</h3>
            <textarea x-model="move.comment" rows="2" placeholder="Причина перемещения…"
                      class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
            <div class="flex gap-2">
              <button @click="mode = 'move_room'" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
              <button @click="submitMove()" :disabled="saving"
                      class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium shadow hover:shadow-[0_4px_12px_rgba(16,185,129,0.35)] transition-all duration-200 disabled:opacity-40"
                      x-text="saving ? 'Сохранение…' : 'Подтвердить'"></button>
            </div>
          </div>

          <!-- История -->
          <div x-show="mode === 'history'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">История перемещений</h3>
            <div x-show="historyLoading" class="text-sm text-[#64748b]">Загрузка…</div>
            <div x-show="!historyLoading && !history.length" class="text-sm text-[#64748b]">Перемещений не было.</div>
            <ul class="text-sm divide-y divide-slate-100">
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
            <button @click="mode = 'menu'" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
          </div>

          <!-- Действия с выбранными (режим «Несколько») -->
          <div x-show="mode === 'multi'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">Действия с выбранными (<span x-text="selectedCount"></span>)</h3>
            <button @click="startMove()" class="w-full bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-3 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] transition-all duration-200">
              Переместить
            </button>
            <template x-if="isAdmin">
              <button @click="writeoffConfirm(selectedItems)" class="w-full bg-[#fee2e2] hover:bg-[#fecaca] text-[#b91c1c] rounded-[10px] px-3 py-2.5 text-sm font-medium transition-all duration-200">
                Списать
              </button>
            </template>
            <button @click="closeMultiActions()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад к выбору</button>
          </div>

          <!-- Подтверждение списания -->
          <div x-show="mode === 'writeoff_confirm'" class="space-y-3">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">Списание техники</h3>
            <div class="rounded-[14px] bg-red-50 border-2 border-red-200 p-3 text-sm text-red-800">
              <span x-text="writeoffCount"></span> ед. техники будут отмечены как списанные.
              <div class="text-red-600 text-xs mt-1">Списанная техника исключается из отчётов и сводок. Действие необратимо.</div>
            </div>
            <div>
              <label class="block text-xs text-[#64748b] mb-1">Комментарий (необязательно)</label>
              <textarea x-model="writeoffComment" rows="2" placeholder="Причина списания…"
                        class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
            </div>
            <div class="flex gap-2">
              <button @click="cancelWriteoff()" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Отмена</button>
              <button @click="submitWriteoff()" :disabled="saving"
                      class="flex-1 bg-[#dc2626] hover:bg-[#b91c1c] text-white rounded-[10px] py-2 text-sm font-medium shadow transition-all duration-200 disabled:opacity-40"
                      x-text="saving ? 'Списание…' : 'Подтвердить списание'"></button>
            </div>
          </div>

          <button x-show="mode !== 'multi' && mode !== 'writeoff_confirm'" @click="closeSheet()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Закрыть</button>
        </div>
      </template>
    </div>
  </div>
</div>

<script>
function roomPage(roomId, isAdmin, canMove, backBuilding) {
  return {
    roomId: roomId,
    isAdmin: !!isAdmin,
    canMove: !!canMove,
    backBuilding: backBuilding > 0 ? backBuilding : 0,
    canBack: backBuilding > 0 || window.history.length > 1,
    room: null,
    items: [],
    loading: true,
    error: '',
    editing: false,
    edit: { room_number: '', aliases: '' },
    editError: '',
    sheet: null,
    mode: 'menu',
    buildings: [],
    moveBuilding: null,
    moveRooms: [],
    loadingRooms: false,
    move: { to_room_id: null, new_room: '', comment: '' },
    saving: false,
    history: [],
    historyLoading: false,
    eqEdit: { id: null, long_name_1c: '', aliases: '', inventory_number: '', serial_number: '', mac_address: '', note: '' },
    nameSuggestions: [],
    dupes: [],
    scopeAsk: false,
    scopeAffected: 0,
    editError: '',
    /* Режим «Несколько»: множественный выбор и действия */
    multiSelect: false,
    selectedIds: [],
    /* Списание (одиночное или массовое) */
    writeoffItems: [],
    writeoffComment: '',

    init() { this.load(); },

    goBack() {
      if (this.backBuilding > 0) {
        window.location.href = 'rooms.php?building=' + encodeURIComponent(this.backBuilding);
      } else {
        window.history.back();
      }
    },

    async load() {
      this.loading = true;
      this.error = '';
      try {
        const d = await App.api('api_room_equipment.php?room_id=' + encodeURIComponent(this.roomId));
        this.room = d.room;
        this.items = d.items || [];
      } catch (e) {
        this.error = e.message;
      }
      this.loading = false;
    },

    openItem(item) {
      this.sheet = item;
      this.mode = 'menu';
      this.history = [];
    },
    closeSheet() {
      this.sheet = null;
      this.mode = 'menu';
      this.scopeAsk = false;
      this.editError = '';
      this.nameSuggestions = [];
      this.dupes = [];
      this.writeoffItems = [];
    },

    /* ---------- Режим «Несколько» ---------- */
    get selectedCount() { return this.selectedIds.length; },
    get selectedItems() {
      const ids = new Set(this.selectedIds);
      return this.items.filter(i => ids.has(i.id) && i.status !== 'written_off');
    },
    toggleMultiSelect() {
      this.multiSelect = !this.multiSelect;
      if (!this.multiSelect) this.selectedIds = [];
      this.mode = 'menu';
      this.sheet = null;
    },
    isSel(id) { return this.selectedIds.includes(id); },
    toggleSelect(item) {
      if (item.status === 'written_off') return;
      const i = this.selectedIds.indexOf(item.id);
      if (i >= 0) this.selectedIds.splice(i, 1);
      else this.selectedIds.push(item.id);
    },
    openMultiActions() {
      if (!this.selectedCount) return;
      this.sheet = { _multi: true };
      this.mode = 'multi';
    },
    closeMultiActions() {
      this.sheet = null;
      this.mode = 'menu';
    },

    /* ---------- Списание (одиночное и массовое) ---------- */
    writeoffSingle() {
      this.writeoffItems = this.sheet ? [this.sheet] : [];
      this.writeoffComment = '';
      this.mode = 'writeoff_confirm';
    },
    writeoffConfirm(items) {
      this.writeoffItems = items || [];
      this.writeoffComment = '';
      this.mode = 'writeoff_confirm';
    },
    get writeoffCount() { return this.writeoffItems.length; },
    cancelWriteoff() {
      if (this.sheet && this.sheet._multi) this.mode = 'multi';
      else if (this.multiSelect) this.mode = 'menu';
      else this.mode = 'menu';
    },
    async submitWriteoff() {
      const ids = this.writeoffItems.map(i => i.id).filter(Boolean);
      if (!ids.length) return;
      this.saving = true;
      try {
        await App.api('api_writeoff.php', {
          method: 'POST',
          body: { equipment_ids: ids, comment: this.writeoffComment.trim() || undefined }
        });
        App.toast('Техника списана', 'success');
        this.writeoffItems = [];
        this.mode = 'menu';
        this.sheet = null;
        if (this.multiSelect) this.selectedIds = this.selectedIds.filter(id => !ids.includes(id));
        this.load();
      } catch (e) { App.toast(e.message, 'error'); }
      this.saving = false;
    },

    async startMove() {
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
    /* Какие единицы перемещаются: одна (sheet) или все выбранные (режим «Несколько») */
    moveItems() {
      if (this.sheet && this.sheet._multi) return this.selectedItems;
      if (this.sheet && this.sheet.id) return [this.sheet];
      return [];
    },
    async submitMove() {
      const items = this.moveItems();
      if (!items.length) return;
      this.saving = true;
      try {
        await App.api('api_move.php', {
          method: 'POST',
          body: {
            equipment_ids: items.map(i => i.id),
            to_building_id: this.moveBuilding.id,
            to_room_id: this.move.to_room_id || undefined,
            new_room: this.move.new_room.trim() || undefined,
            comment: this.move.comment.trim() || undefined
          }
        });
        const n = items.length;
        App.toast(n > 1 ? 'Перемещено единиц: ' + n : 'Техника перемещена', 'success');
        this.closeSheet();
        if (this.multiSelect) this.selectedIds = [];
        this.load();
      } catch (e) { App.toast(e.message, 'error'); }
      this.saving = false;
    },

    async loadHistory() {
      this.mode = 'history';
      this.historyLoading = true;
      try {
        const d = await App.api('api_equipment.php?id=' + encodeURIComponent(this.sheet.id));
        this.history = d.movements || [];
      } catch (e) { App.toast(e.message, 'error'); }
      this.historyLoading = false;
    },

    async startEditItem() {
      this.mode = 'edit';
      this.editError = '';
      this.scopeAsk = false;
      this.dupes = [];
      this.nameSuggestions = [];
      this.eqEdit = {
        id: this.sheet.id,
        long_name_1c: this.sheet.long_name_1c || this.sheet.short_name || '',
        aliases: this.sheet.aliases || '',
        inventory_number: this.sheet.inventory_number || '',
        serial_number: this.sheet.serial_number || '',
        mac_address: this.sheet.mac_address || '',
        note: this.sheet.note || ''
      };
    },

    startCreateItem() {
      this.mode = 'edit';
      this.editError = '';
      this.scopeAsk = false;
      this.dupes = [];
      this.nameSuggestions = [];
      this.eqEdit = { id: null, long_name_1c: '', aliases: '', inventory_number: '', serial_number: '', mac_address: '', note: '' };
      /* Sheet рендерится только при непустом sheet */
      this.sheet = { id: null, long_name_1c: '', short_name: '' };
    },

    /* Проверка введённых данных на дубли при переходе в следующее поле / перед сохранением */
    async checkDupes() {
      const p = new URLSearchParams({ dupes: '1' });
      if (this.eqEdit.inventory_number.trim()) p.set('inventory_number', this.eqEdit.inventory_number.trim());
      if (this.eqEdit.serial_number.trim()) p.set('serial_number', this.eqEdit.serial_number.trim());
      if (this.eqEdit.mac_address.trim()) p.set('mac_address', this.eqEdit.mac_address.trim());
      if (this.eqEdit.id) p.set('exclude_id', this.eqEdit.id);
      if (!p.has('inventory_number') && !p.has('serial_number') && !p.has('mac_address')) {
        this.dupes = [];
        return;
      }
      try {
        const d = await App.api('api_equipment.php?' + p.toString());
        this.dupes = d.conflicts || [];
      } catch (e) { this.dupes = []; }
    },
    onNameInput() {
      this.nameSuggestions = [];
      const q = this.eqEdit.long_name_1c.trim();
      if (q.length < 2) return;
      clearTimeout(this._nameT);
      this._nameT = setTimeout(async () => {
        try {
          const d = await App.api('api_equipment.php?names=' + encodeURIComponent(q));
          this.nameSuggestions = (d.items || []).filter(n => n !== this.eqEdit.long_name_1c.trim());
        } catch (e) { /* молча */ }
      }, 300);
    },
    pickName(n) {
      this.eqEdit.long_name_1c = n;
      this.nameSuggestions = [];
    },
    closeSuggestions() {
      this.nameSuggestions = [];
    },
    async saveEditItem(scope) {
      if (this.dupes.length) return;
      this.saving = true;
      this.editError = '';
      try {
        let body;
        if (this.eqEdit.id) {
          body = {
            action: 'update',
            id: this.eqEdit.id,
            long_name_1c: this.eqEdit.long_name_1c,
            aliases: this.eqEdit.aliases,
            inventory_number: this.eqEdit.inventory_number,
            serial_number: this.eqEdit.serial_number,
            mac_address: this.eqEdit.mac_address,
            note: this.eqEdit.note,
            rename_scope: scope || undefined
          };
        } else {
          body = {
            action: 'create',
            long_name_1c: this.eqEdit.long_name_1c,
            aliases: this.eqEdit.aliases,
            inventory_number: this.eqEdit.inventory_number,
            serial_number: this.eqEdit.serial_number,
            mac_address: this.eqEdit.mac_address,
            note: this.eqEdit.note,
            building_id: this.room.building_id,
            room_id: this.roomId
          };
        }
        const d = await App.api('api_equipment.php', { method: 'POST', body });
        if (d.need_scope) {
          this.scopeAsk = true;
          this.scopeAffected = d.affected || 0;
          this.saving = false;
          return;
        }
        App.toast(this.eqEdit.id ? 'Техника обновлена' : 'Техника добавлена', 'success');
        this.scopeAsk = false;
        this.closeSheet();
        this.load();
      } catch (e) {
        this.editError = e.message;
      }
      this.saving = false;
    },

    startEdit() {
      this.edit = { room_number: this.room.room_number, aliases: this.room.aliases || '' };
      this.editError = '';
      this.editing = true;
    },
    async saveEdit() {
      this.saving = true;
      this.editError = '';
      try {
        await App.api('api_rooms.php', {
          method: 'POST',
          body: { action: 'update', id: this.roomId, room_number: this.edit.room_number, aliases: this.edit.aliases }
        });
        App.toast('Кабинет сохранён', 'success');
        this.editing = false;
        this.load();
      } catch (e) {
        this.editError = e.message;
      }
      this.saving = false;
    },
    async deleteRoom() {
      if (!confirm('Удалить кабинет «' + this.room.room_number + '»?')) return;
      try {
        await App.api('api_rooms.php', { method: 'POST', body: { action: 'delete', id: this.roomId } });
        App.toast('Кабинет удалён', 'success');
        location.href = 'index.php';
      } catch (e) { App.toast(e.message, 'error'); }
    }
  };
}
</script>
<?php pageBottom(); ?>
