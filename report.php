<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/layout.php';
$user = requireAuth();
$isAdmin = ($user['role'] ?? '') === 'admin';
pageTop('Отчёт по зданиям', $user);
?>
<div x-data="reportPage(<?= $isAdmin ? 'true' : 'false' ?>)" x-init="init()" class="space-y-4">

  <section class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
    <!-- Переключатель режимов -->
    <div class="flex rounded-[14px] overflow-hidden border-2 border-[#e2e8f0] text-sm">
      <button @click="mode = 'matrix'" class="flex-1 px-3 py-2.5 font-medium transition-all duration-200"
              :class="mode === 'matrix' ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white' : 'bg-white text-[#475569] hover:bg-[#f0f9ff]'">Матричный отчёт</button>
      <button @click="mode = 'equipment'" class="flex-1 px-3 py-2.5 font-medium transition-all duration-200"
              :class="mode === 'equipment' ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white' : 'bg-white text-[#475569] hover:bg-[#f0f9ff]'">По технике</button>
      <button @click="mode = 'movements'" class="flex-1 px-3 py-2.5 font-medium transition-all duration-200"
              :class="mode === 'movements' ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white' : 'bg-white text-[#475569] hover:bg-[#f0f9ff]'">Перемещения</button>
      <button x-show="isAdmin" @click="openJournal()" class="flex-1 px-3 py-2.5 font-medium transition-all duration-200"
              :class="mode === 'journal' ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white' : 'bg-white text-[#475569] hover:bg-[#f0f9ff]'">Журнал</button>
    </div>

    <!-- Матрица: выбор здания -->
    <div x-show="mode === 'matrix'" class="space-y-3">
      <select x-model="buildingId" @change="loadReport()"
              class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
        <option value="">— Выберите здание —</option>
        <template x-for="b in buildings" :key="b.id">
          <option :value="b.id" x-text="b.address"></option>
        </template>
      </select>
      <div class="flex items-center gap-3" x-show="report">
        <label class="flex items-center gap-2 text-sm cursor-pointer text-[#475569]">
          <input type="checkbox" x-model="accordionMode" class="rounded accent-[#0077B6] w-4 h-4">
          Аккордеон по кабинетам
        </label>
        <a x-show="buildingId" :href="'api_report_excel.php?building_id=' + encodeURIComponent(buildingId)"
           class="ml-auto bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] px-4 py-2 text-sm font-medium shadow hover:shadow-[0_4px_12px_rgba(16,185,129,0.35)] hover:-translate-y-0.5 transition-all duration-200">
          Скачать Excel
        </a>
      </div>
    </div>

    <!-- По технике: выбор наименования -->
    <div x-show="mode === 'equipment'" class="space-y-3">
      <select x-model="eqName" @change="loadEquipmentReport()"
              class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
        <option value="">— Выберите наименование техники —</option>
        <template x-for="n in eqNames" :key="n">
          <option :value="n" x-text="n"></option>
        </template>
      </select>
    </div>

    <!-- Перемещения: выбор здания -->
    <div x-show="mode === 'movements'" class="space-y-3">
      <select x-model="movBuildingId" @change="loadMovements()"
              class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
        <option value="">— Выберите здание —</option>
        <template x-for="b in buildings" :key="b.id">
          <option :value="b.id" x-text="b.address"></option>
        </template>
      </select>
    </div>

    <!-- Журнал: фильтр по строкам -->
    <div x-show="mode === 'journal'" class="space-y-3">
      <input x-model="journalQ" @input="debouncedJournalFilter()" type="search"
             placeholder="Поиск по журналу: название, ФИО, детали…"
             class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
    </div>
  </section>

  <div x-show="loading" class="text-sm text-[#64748b]">Загрузка отчёта…</div>

  <!-- ================= Матричный отчёт ================= -->
  <template x-if="report && mode === 'matrix'">
    <div class="space-y-3">
      <div class="text-sm text-[#64748b]" x-text="report.building ? report.building.address : ''"></div>

      <!-- Табличный режим: sticky первая колонка + горизонтальный скролл -->
      <div x-show="!accordionMode" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white overflow-hidden">
        <div class="overflow-x-auto">
          <table class="border-collapse text-xs whitespace-nowrap w-full">
            <thead>
              <tr class="bg-gradient-to-r from-[#00B4D8] to-[#0077B6] text-white">
                <th class="sticky left-0 z-10 bg-[#00B4D8] px-3 py-2 text-left border border-[#0077B6] min-w-[7rem]">
                  Кабинет
                </th>
                <template x-for="col in report.columns" :key="col.key">
                  <th class="px-3 py-2 text-left border border-[#0077B6] font-medium" x-text="col.name"></th>
                </template>
              </tr>
              <!-- Итоги сразу под шапкой -->
              <tr class="bg-amber-50 font-bold">
                <td class="sticky left-0 z-10 bg-amber-50 px-3 py-2 border border-slate-200">Итого</td>
                <template x-for="col in report.columns" :key="'t' + col.key">
                  <td class="px-3 py-2 border border-slate-200 text-center"
                      x-text="report.totals[col.key] || 0"></td>
                </template>
              </tr>
            </thead>
            <tbody>
              <template x-for="(row, idx) in report.rows" :key="row.room_id">
                <tr class="align-top" :class="idx % 2 ? 'bg-slate-50' : 'bg-white'">
                  <td class="sticky left-0 z-10 px-3 py-2 border border-slate-200 font-medium"
                      :class="idx % 2 ? 'bg-slate-50' : 'bg-white'">
                    <a :href="'room.php?id=' + row.room_id + '&building=' + buildingId" class="text-[#0077B6] hover:underline">
                      <div x-text="row.room_number"></div>
                    </a>
                    <div class="text-[10px] text-slate-400" x-show="row.plan_number" x-text="row.plan_number"></div>
                  </td>
                  <template x-for="col in report.columns" :key="col.key">
                    <td class="px-3 py-2 border border-slate-200 whitespace-pre-line"
                        x-html="cellHtml(row, col.key)"></td>
                  </template>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Аккордеон-режим (мобильный) -->
      <div x-show="accordionMode" class="space-y-2">
        <template x-for="row in report.rows" :key="row.room_id">
          <details class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white overflow-hidden">
            <summary class="px-4 py-3 font-medium text-sm cursor-pointer flex items-center justify-between">
              <span>
                Кабинет
                <a :href="'room.php?id=' + row.room_id + '&building=' + buildingId" @click.stop class="text-[#0077B6] hover:underline"><b x-text="row.room_number"></b></a>
                <span class="text-xs text-slate-400" x-show="row.plan_number" x-text="'(план: ' + row.plan_number + ')'"></span>
              </span>
              <span class="text-xs text-slate-400" x-text="roomTotal(row) + ' ед.'"></span>
            </summary>
            <div class="px-4 pb-3 space-y-2 border-t border-slate-100 pt-2">
              <template x-for="col in report.columns" :key="col.key">
                <div x-show="(row.cells[col.key] || []).length" class="text-sm">
                  <div class="font-medium text-xs text-slate-500" x-text="col.name"></div>
                  <div class="whitespace-pre-line" x-html="cellHtml(row, col.key)"></div>
                </div>
              </template>
            </div>
          </details>
        </template>
      </div>
    </div>
  </template>

  <!-- ================= Отчёт «По технике» ================= -->
  <template x-if="eqReport && mode === 'equipment'">
    <div class="space-y-3">
      <div class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 flex items-center justify-between">
        <div class="font-bold text-[#0c4a6e] break-words flex-1" x-text="eqReport.name"></div>
        <span class="text-xs bg-[#e0f2fe] text-[#0077B6] rounded-full px-3 py-1 shrink-0" x-text="'Всего: ' + eqReport.total"></span>
      </div>

      <template x-for="b in eqBuildings()" :key="'b' + b.building_id">
        <div class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white overflow-hidden">
          <!-- Здание: наименование + всего единиц -->
          <div class="px-4 py-3 bg-gradient-to-r from-[#00B4D8] to-[#0077B6] text-white flex items-center justify-between gap-2">
            <div class="text-sm font-medium min-w-0 break-words" x-text="b.building || '—'"></div>
            <span class="text-xs shrink-0" x-text="b.total + ' ед.'"></span>
          </div>
          <!-- Кабинеты здания -->
          <template x-for="(g, ri) in b.rooms" :key="'b' + b.building_id + '|' + g.room_id">
            <div :class="ri ? 'border-t border-slate-100' : ''">
              <div class="px-4 py-2 bg-[#f8fafc] border-b border-slate-100 flex items-center justify-between gap-2">
                <div class="text-sm min-w-0">
                  <span class="text-slate-500">каб. <b x-text="g.room || '—'"></b></span>
                </div>
                <span class="text-xs text-slate-400 shrink-0" x-text="g.units.length + ' ед.'"></span>
              </div>
              <ul class="divide-y divide-slate-100">
                <template x-for="u in g.units" :key="u.id">
                  <li class="px-4 py-2.5 flex items-center justify-between gap-2">
                    <div class="text-sm min-w-0">
                      <span x-show="u.inventory_number">инв: <b x-text="u.inventory_number"></b></span>
                      <span x-show="u.serial_number"> · сер: <b x-text="u.serial_number"></b></span>
                      <span x-show="!u.inventory_number && !u.serial_number" class="text-slate-400">б/н</span>
                      <span x-show="u.mac_address" class="text-xs text-slate-500 block">MAC: <span x-text="u.mac_address"></span></span>
                    </div>
                    <button @click="openUnitMenu(u, g)" class="w-8 h-8 shrink-0 rounded-[10px] bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] text-lg leading-none transition-all duration-200">⋮</button>
                  </li>
                </template>
              </ul>
            </div>
          </template>
        </div>
      </template>
      <div x-show="!eqReport.total" class="rounded-[20px] bg-white shadow-[0_4px_20px_rgba(0,0,0,0.04)] p-4 text-sm text-[#64748b]">
        Ничего не найдено.
      </div>
    </div>
  </template>

  <!-- ================= Перемещения по зданию ================= -->
  <template x-if="mode === 'movements'">
    <div class="space-y-2">
      <div x-show="movLoading" class="text-sm text-[#64748b]">Загрузка…</div>
      <div x-show="!movLoading && movBuildingId && !movements.length" class="rounded-[20px] bg-white shadow-[0_4px_20px_rgba(0,0,0,0.04)] p-4 text-sm text-[#64748b]">
        Перемещений не было.
      </div>
      <template x-for="m in movements" :key="m.id">
        <article class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-1">
          <div class="text-xs text-slate-400" x-text="m.created_at + (m.user ? ' · ' + m.user : '')"></div>
          <div class="text-sm font-medium break-words" x-text="m.short_name || m.long_name_1c || '—'"></div>
          <div class="text-xs text-slate-500">
            Инв.: <b x-text="m.inventory_number || '—'"></b>
            <span x-show="m.serial_number"> · Сер.: <b x-text="m.serial_number"></b></span>
            <span x-show="m.comment"> · прим.: <span x-text="m.comment"></span></span>
          </div>
          <div class="text-xs text-slate-500 break-words">
            <span x-text="'из ' + (m.from_building || '—') + ', каб. ' + (m.from_room || '—') + ' → '"></span>
            <a x-show="m.to_room_id" :href="'room.php?id=' + m.to_room_id + '&building=' + (m.to_building_id || '')"
               class="text-[#0077B6] hover:underline"
               x-text="'в ' + (m.to_building || '—') + ', каб. ' + (m.to_room || '—')"></a>
            <span x-show="!m.to_room_id" x-text="'в ' + (m.to_building || '—') + ', каб. ' + (m.to_room || '—')"></span>
          </div>
        </article>
      </template>
    </div>
  </template>

  <!-- ================= Журнал действий (admin) ================= -->
  <template x-if="mode === 'journal'">
    <div class="space-y-2">
      <div x-show="journalLoading" class="text-sm text-[#64748b]">Загрузка журнала…</div>
      <div x-show="!journalLoading && !journal.length" class="rounded-[20px] bg-white shadow-[0_4px_20px_rgba(0,0,0,0.04)] p-4 text-sm text-[#64748b]"
           x-text="journalQ.trim().length > 2 ? 'Ничего не найдено.' : 'В журнале пока нет записей.'"></div>
      <template x-for="e in journal" :key="e.id">
        <article class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-1">
          <div class="text-xs text-slate-400" x-text="e.created_at + (e.user ? ' · ' + e.user : '')"></div>
          <div class="text-sm font-medium" x-text="journalActionLabel(e)"></div>
          <div class="text-sm break-words" x-text="e.entity_name || '—'"></div>
          <div class="text-xs text-slate-500 break-words" x-show="e.details" x-text="e.details"></div>
        </article>
      </template>
      <div class="flex justify-center pt-1" x-show="journalHasMore && !journalLoading">
        <button @click="loadJournal()"
                class="bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-8 py-2 text-sm font-medium shadow transition-all duration-200">Ещё</button>
      </div>
    </div>
  </template>

  <!-- Bottom-sheet: действия с техникой (как в карточке кабинета) -->
  <div x-show="unitSheet" x-cloak class="fixed inset-0 z-50 flex items-end justify-center" @keydown.escape.window="closeUnitSheet()">
    <div class="absolute inset-0 bg-black/40" @click="closeUnitSheet()"></div>
    <div class="relative bg-white w-full max-w-lg rounded-t-[24px] shadow-[0_20px_60px_rgba(0,119,182,0.12)] p-4 space-y-3 max-h-[88vh] overflow-y-auto">
      <template x-if="unitSheet">
        <div class="space-y-3">
          <div>
            <div class="font-bold text-[#0c4a6e]" x-text="unitSheet.short_name || unitSheet.long_name_1c || '—'"></div>
            <div class="text-sm">
              <span x-show="unitSheet.inventory_number">инв: <b x-text="unitSheet.inventory_number"></b></span>
              <span x-show="unitSheet.serial_number"> · сер: <b x-text="unitSheet.serial_number"></b></span>
            </div>
            <div class="text-xs text-slate-500" x-text="(unitSheet._building || '') + (unitSheet._room ? ' · каб. ' + unitSheet._room : '')"></div>
          </div>

          <!-- Меню -->
          <div x-show="unitMode === 'menu'" class="space-y-2">
            <button @click="unitStartMove()" class="w-full bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-3 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] transition-all duration-200">Переместить</button>
            <button x-show="isAdmin" @click="unitStartEdit()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-3 py-2.5 text-sm font-medium transition-all duration-200">✏️ Редактировать</button>
            <button @click="unitLoadHistory()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-3 py-2.5 text-sm font-medium transition-all duration-200">История перемещений</button>
          </div>

          <!-- Перемещение: шаг 1 — здание -->
          <div x-show="unitMode === 'move_building'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 1. Выберите здание</h3>
            <template x-for="b in buildings" :key="b.id">
              <button @click="unitPickBuilding(b)" class="w-full text-left bg-white border-2 border-[#e2e8f0] rounded-[14px] px-3 py-2.5 text-sm hover:border-[#00B4D8] hover:bg-[#f0f9ff] transition-all duration-200" x-text="b.address"></button>
            </template>
          </div>

          <!-- Перемещение: шаг 2 — кабинет -->
          <div x-show="unitMode === 'move_room'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 2. Кабинет в «<span x-text="unitMoveBuilding && unitMoveBuilding.address"></span>»</h3>
            <input x-model="unitNewRoom" placeholder="…или введите новый кабинет"
                   class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
            <div class="max-h-60 overflow-y-auto space-y-1">
              <template x-for="r in unitMoveRooms" :key="r.id">
                <button @click="unitRoomId = r.id; unitNewRoom = ''"
                        class="w-full text-left rounded-[10px] px-3 py-2 text-sm border-2 transition-all duration-200"
                        :class="unitRoomId === r.id ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white border-transparent' : 'bg-white border-[#e2e8f0] hover:border-[#00B4D8]'"
                        x-text="r.room_number"></button>
              </template>
            </div>
            <div class="flex gap-2">
              <button @click="unitMode = 'move_building'" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
              <button @click="unitMode = 'move_comment'" :disabled="!unitRoomId && !unitNewRoom.trim()"
                      class="flex-1 bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">Далее</button>
            </div>
          </div>

          <!-- Перемещение: шаг 3 — комментарий -->
          <div x-show="unitMode === 'move_comment'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 3. Комментарий (необязательно)</h3>
            <textarea x-model="unitComment" rows="2" class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
            <div class="flex gap-2">
              <button @click="unitMode = 'move_room'" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
              <button @click="unitSubmitMove()" :disabled="unitSaving" class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">Подтвердить</button>
            </div>
          </div>

          <!-- Редактирование -->
          <div x-show="unitMode === 'edit'" class="space-y-2">
            <input x-model="unitEdit.long_name_1c" placeholder="Название"
                   class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
            <textarea x-model="unitEdit.aliases" rows="3" placeholder="Сопоставление (каждое значение с новой строки)"
                      class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
            <input x-model="unitEdit.inventory_number" placeholder="Инвентарный номер" @blur="unitCheckDupes()"
                   class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
            <input x-model="unitEdit.serial_number" placeholder="Серийный номер" @blur="unitCheckDupes()"
                   class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
            <input x-model="unitEdit.mac_address" placeholder="MAC-адрес" @blur="unitCheckDupes()"
                   class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
            <div x-show="unitDupes.length" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="'Такие данные уже есть у другой техники:\n' + unitDupes.join('\n')"></div>
            <div x-show="unitEditError" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="unitEditError"></div>
            <div x-show="unitScopeAsk" class="rounded-[14px] bg-amber-50 border-2 border-amber-200 p-3 space-y-2">
              <div class="text-sm text-amber-800">Такого названия ещё нет в базе. Как применить?</div>
              <button @click="unitSaveEdit('all')" :disabled="unitSaving" class="w-full bg-amber-500 hover:bg-amber-600 text-white rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40"
                      x-text="'Изменить у всей техники с прежним названием (' + unitScopeAffected + ' ед.)'"></button>
              <button @click="unitSaveEdit('single')" :disabled="unitSaving" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">Создать как новое название (только эта единица)</button>
            </div>
            <div class="flex gap-2" x-show="!unitScopeAsk">
              <button @click="unitMode = 'menu'" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
              <button @click="unitSaveEdit('')" :disabled="unitSaving || unitDupes.length > 0 || !unitEdit.long_name_1c.trim()"
                      class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">Сохранить</button>
            </div>
          </div>

          <!-- История -->
          <div x-show="unitMode === 'history'" class="space-y-2">
            <h3 class="font-semibold text-sm text-[#0c4a6e]">История перемещений</h3>
            <ul class="text-sm divide-y divide-slate-100">
              <template x-for="m in unitHistory" :key="m.id">
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
            <button @click="unitMode = 'menu'" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
          </div>

          <button @click="closeUnitSheet()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Закрыть</button>
        </div>
      </template>
    </div>
  </div>
</div>

<script>
function reportPage(isAdmin) {
  return {
    isAdmin: !!isAdmin,
    mode: 'matrix',
    buildings: [],
    buildingId: '',
    report: null,
    loading: false,
    accordionMode: window.innerWidth < 640,
    eqNames: [],
    eqName: '',
    eqReport: null,
    movBuildingId: '',
    movements: [],
    movLoading: false,
    journal: [],
    journalLoading: false,
    journalLoaded: false,
    journalQ: '',
    journalOffset: 0,
    journalHasMore: false,
    /* Bottom-sheet */
    unitSheet: null,
    unitMode: 'menu',
    unitSaving: false,
    unitMoveBuilding: null,
    unitMoveRooms: [],
    unitRoomId: null,
    unitNewRoom: '',
    unitComment: '',
    unitEdit: { long_name_1c: '', aliases: '', inventory_number: '', serial_number: '', mac_address: '' },
    unitDupes: [],
    unitEditError: '',
    unitScopeAsk: false,
    unitScopeAffected: 0,
    unitHistory: [],

    async init() {
      this.debouncedJournalFilter = App.debounce(() => this.applyJournalFilter(), 300);
      try {
        const d = await App.api('api_buildings.php');
        this.buildings = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
      try {
        const d = await App.api('api_report.php?names=1');
        this.eqNames = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
    },
    async loadReport() {
      this.report = null;
      if (!this.buildingId) return;
      this.loading = true;
      try {
        const d = await App.api('api_report.php?building_id=' + encodeURIComponent(this.buildingId));
        this.report = d;
      } catch (e) { App.toast(e.message, 'error'); }
      this.loading = false;
    },
    async loadEquipmentReport() {
      this.eqReport = null;
      if (!this.eqName) return;
      this.loading = true;
      try {
        const d = await App.api('api_report.php?equipment=' + encodeURIComponent(this.eqName));
        this.eqReport = d;
      } catch (e) { App.toast(e.message, 'error'); }
      this.loading = false;
    },
    async loadMovements() {
      this.movements = [];
      if (!this.movBuildingId) return;
      this.movLoading = true;
      try {
        const d = await App.api('api_report.php?movements=1&building_id=' + encodeURIComponent(this.movBuildingId));
        this.movements = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
      this.movLoading = false;
    },
    openJournal() {
      this.mode = 'journal';
      if (!this.journalLoaded) this.loadJournal();
    },
    applyJournalFilter() {
      if (this.mode !== 'journal' || this.journalLoading) return;
      const q = this.journalQ.trim();
      /* 1-2 символа: не фильтруем и не обновляем вывод (как и сервер игнорирует q короче 3) */
      if (q.length > 0 && q.length <= 2) return;
      this.journal = [];
      this.journalOffset = 0;
      this.journalHasMore = false;
      this.journalLoaded = false;
      this.loadJournal();
    },
    async loadJournal() {
      this.journalLoading = true;
      try {
        const p = new URLSearchParams({ journal: '1', limit: '40', offset: String(this.journalOffset) });
        const q = this.journalQ.trim();
        if (q.length > 2) p.set('q', q);
        const d = await App.api('api_report.php?' + p.toString());
        const items = d.items || [];
        this.journal = this.journalOffset === 0 ? items : this.journal.concat(items);
        this.journalOffset += items.length;
        this.journalHasMore = !!d.has_more;
        this.journalLoaded = true;
      } catch (e) { App.toast(e.message, 'error'); }
      this.journalLoading = false;
    },
    journalActionLabel(e) {
      const ent = { equipment: 'техники', room: 'кабинета', building: 'здания' }[e.entity] || e.entity;
      const act = { create: 'Добавление', update: 'Изменение', move: 'Перемещение', delete: 'Удаление', write_off: 'Списание' }[e.action] || e.action;
      return act + ' ' + ent;
    },
    /* Группировка отчёта «По технике» по зданиям: здание → кабинеты → единицы
       (сервер уже сортирует группы по зданию/кабинету — порядок сохраняется) */
    eqBuildings() {
      if (!this.eqReport || !this.eqReport.groups) return [];
      const map = new Map();
      for (const g of this.eqReport.groups) {
        const key = g.building_id === null ? 'null' : String(g.building_id);
        if (!map.has(key)) {
          map.set(key, { building_id: g.building_id, building: g.building, total: 0, rooms: [] });
        }
        const b = map.get(key);
        b.rooms.push(g);
        b.total += (g.units || []).length;
      }
      return [...map.values()];
    },
    cellHtml(row, colKey) {
      const nums = (row.cells && row.cells[colKey]) || [];
      return nums.map(n => App.esc(n)).join('<br>');
    },
    roomTotal(row) {
      let n = 0;
      for (const k in (row.cells || {})) n += (row.cells[k] || []).length;
      return n;
    },

    /* ---------- Bottom-sheet: действия с техникой ---------- */
    openUnitMenu(u, g) {
      this.unitSheet = Object.assign({ _building: g.building || '', _room: g.room || '' }, u);
      this.unitMode = 'menu';
      this.unitHistory = [];
      this.unitScopeAsk = false;
      this.unitEditError = '';
      this.unitDupes = [];
    },
    closeUnitSheet() {
      this.unitSheet = null;
      this.unitMode = 'menu';
      this.unitScopeAsk = false;
      this.unitDupes = [];
      this.unitEditError = '';
    },
    unitStartMove() {
      this.unitMode = 'move_building';
      this.unitMoveBuilding = null;
      this.unitMoveRooms = [];
      this.unitRoomId = null;
      this.unitNewRoom = '';
      this.unitComment = '';
    },
    async unitPickBuilding(b) {
      this.unitMoveBuilding = b;
      this.unitRoomId = null;
      this.unitNewRoom = '';
      this.unitMode = 'move_room';
      try {
        const d = await App.api('api_rooms.php?building_id=' + encodeURIComponent(b.id));
        this.unitMoveRooms = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
    },
    async unitSubmitMove() {
      this.unitSaving = true;
      try {
        await App.api('api_move.php', {
          method: 'POST',
          body: {
            equipment_id: this.unitSheet.id,
            to_building_id: this.unitMoveBuilding.id,
            to_room_id: this.unitRoomId || undefined,
            new_room: this.unitNewRoom.trim() || undefined,
            comment: this.unitComment.trim() || undefined
          }
        });
        App.toast('Техника перемещена', 'success');
        this.closeUnitSheet();
        this.loadEquipmentReport();
      } catch (e) { App.toast(e.message, 'error'); }
      this.unitSaving = false;
    },
    unitStartEdit() {
      this.unitMode = 'edit';
      this.unitEditError = '';
      this.unitScopeAsk = false;
      this.unitDupes = [];
      this.unitEdit = {
        long_name_1c: this.unitSheet.long_name_1c || this.unitSheet.short_name || '',
        aliases: this.unitSheet.aliases || '',
        inventory_number: this.unitSheet.inventory_number || '',
        serial_number: this.unitSheet.serial_number || '',
        mac_address: this.unitSheet.mac_address || ''
      };
    },
    async unitCheckDupes() {
      const p = new URLSearchParams({ dupes: '1', exclude_id: this.unitSheet.id });
      if (this.unitEdit.inventory_number.trim()) p.set('inventory_number', this.unitEdit.inventory_number.trim());
      if (this.unitEdit.serial_number.trim()) p.set('serial_number', this.unitEdit.serial_number.trim());
      if (this.unitEdit.mac_address.trim()) p.set('mac_address', this.unitEdit.mac_address.trim());
      if (!p.has('inventory_number') && !p.has('serial_number') && !p.has('mac_address')) {
        this.unitDupes = [];
        return;
      }
      try {
        const d = await App.api('api_equipment.php?' + p.toString());
        this.unitDupes = d.conflicts || [];
      } catch (e) { this.unitDupes = []; }
    },
    async unitSaveEdit(scope) {
      if (this.unitDupes.length) return;
      this.unitSaving = true;
      this.unitEditError = '';
      try {
        const d = await App.api('api_equipment.php', {
          method: 'POST',
          body: {
            action: 'update',
            id: this.unitSheet.id,
            long_name_1c: this.unitEdit.long_name_1c,
            aliases: this.unitEdit.aliases,
            inventory_number: this.unitEdit.inventory_number,
            serial_number: this.unitEdit.serial_number,
            mac_address: this.unitEdit.mac_address,
            rename_scope: scope || undefined
          }
        });
        if (d.need_scope) {
          this.unitScopeAsk = true;
          this.unitScopeAffected = d.affected || 0;
          this.unitSaving = false;
          return;
        }
        App.toast('Техника обновлена', 'success');
        this.closeUnitSheet();
        this.loadEquipmentReport();
      } catch (e) { this.unitEditError = e.message; }
      this.unitSaving = false;
    },
    async unitLoadHistory() {
      this.unitMode = 'history';
      try {
        const d = await App.api('api_equipment.php?id=' + encodeURIComponent(this.unitSheet.id));
        this.unitHistory = d.movements || [];
      } catch (e) { App.toast(e.message, 'error'); }
    }
  };
}
</script>
<?php pageBottom(); ?>
