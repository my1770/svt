<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/layout.php';
$user = requireRole('admin', 'economist');
$isAdmin = $user['role'] === 'admin';
pageTop('Администрирование', $user);
?>
<div x-data="adminPage(<?= $isAdmin ? 'true' : 'false' ?>)" x-init="init()" class="space-y-4">

  <!-- Табы -->
  <div class="flex gap-1 bg-white rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] p-1 overflow-x-auto">
    <template x-for="t in tabs" :key="t.key">
      <button @click="tab = t.key"
              class="flex-1 whitespace-nowrap rounded-[10px] px-3 py-2 text-sm font-medium transition-all duration-200"
              :class="tab === t.key ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white shadow-[0_4px_16px_rgba(0,180,216,0.35)]' : 'text-[#475569] hover:bg-[#f1f5f9]'"
              x-text="t.label"></button>
    </template>
  </div>

  <!-- ================= Импорт 1С ================= -->
  <section x-show="tab === 'import1c'" class="space-y-4">
    <div class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
      <h2 class="font-bold text-[#0c4a6e]">Импорт файла 1С (Основной.xlsx)</h2>
      <input type="file" accept=".xlsx,.xls" x-ref="file1c"
             class="w-full text-sm rounded-[14px] border-2 border-dashed border-[#e2e8f0] bg-[#f8fafc] px-3 py-2.5 cursor-pointer hover:border-[#00B4D8] hover:bg-[#f0f9ff] transition-all duration-200 file:mr-3 file:rounded-[10px] file:border-0 file:bg-gradient-to-br file:from-[#00B4D8] file:to-[#0077B6] file:text-white file:px-4 file:py-2 file:font-medium">
      <button @click="upload1c()" :disabled="imp1c.loading"
              class="bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-4 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200 disabled:opacity-40"
              x-text="imp1c.loading ? 'Загрузка и разбор…' : 'Загрузить'"></button>
      <div x-show="imp1c.loading" class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
        <div class="edo-progress h-2 rounded-full w-full"></div>
      </div>
    </div>

    <!-- Гео-сопоставления: неизвестные 1С адреса и кабинеты -->
    <div x-show="imp1c.batchId && (imp1c.geoUnknownB.length || imp1c.geoUnknownR.length)" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
      <h3 class="font-semibold text-sm text-[#0c4a6e]">Сопоставление адресов и кабинетов из 1С</h3>
      <div class="text-xs text-amber-700 bg-amber-50 rounded-[14px] p-3">
        В файле есть адреса и кабинеты, которых нет в базе. Из 1С ничего не создаётся — выберите соответствующее здание/кабинет, значение попадёт в поле «Сопоставление». После сохранения анализ перезапустится.
      </div>
      <template x-for="u in imp1c.geoUnknownB" :key="'b' + u.address">
        <div class="border-2 border-[#e2e8f0] rounded-[14px] p-3 space-y-2">
          <div class="text-sm font-medium break-words" x-text="u.address"></div>
          <div class="text-xs text-slate-400" x-text="'Строк в файле: ' + u.count"></div>
          <select x-model="u.building_id" @change="onGeoBuildingChange(u)"
                  class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-2 py-1.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
            <option value="">— выберите здание —</option>
            <template x-for="b in imp1c.geoBuildings" :key="b.id">
              <option :value="b.id" x-text="b.address"></option>
            </template>
          </select>
        </div>
      </template>
      <template x-for="u in imp1c.geoUnknownR" :key="'r' + u.address + '|' + u.room">
        <div class="border-2 border-[#e2e8f0] rounded-[14px] p-3 space-y-2">
          <div class="text-sm font-medium break-words">
            Кабинет <b x-text="u.room"></b>
            <span class="text-xs text-slate-400" x-text="'· ' + u.address + ' · строк: ' + u.count"></span>
          </div>
          <select x-model="u.room_id"
                  class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-2 py-1.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
            <option value="">— выберите кабинет —</option>
            <template x-for="r in (imp1c.geoRooms[u.address] || [])" :key="r.id">
              <option :value="r.id" x-text="r.room_number"></option>
            </template>
          </select>
        </div>
      </template>
      <div x-show="imp1c.geoErrors.length" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line"
           x-text="imp1c.geoErrors.join('\n')"></div>
      <button @click="saveGeo()" :disabled="imp1c.geoSaving"
              class="w-full bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-4 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200 disabled:opacity-40"
              x-text="imp1c.geoSaving ? 'Сохранение…' : 'Сохранить сопоставления'"></button>
    </div>

    <!-- Экран маппинга -->
    <div x-show="imp1c.status === 'mapping_required'" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
      <h3 class="font-semibold text-sm text-[#0c4a6e]">Новые наименования из 1С — назначьте короткий тип</h3>
      <div class="text-xs text-slate-500" x-text="'Партия: ' + imp1c.batchId"></div>
      <template x-for="m in imp1c.mappings" :key="m.long_name_1c">
        <div class="border-2 border-[#e2e8f0] rounded-[14px] p-3 space-y-2">
          <div class="text-sm font-medium break-words" x-text="m.long_name_1c"></div>
          <div class="text-xs text-slate-400" x-text="'Строк в файле: ' + m.count"></div>
          <div class="flex gap-2">
            <select x-model="m.short_type_id" class="flex-1 rounded-[14px] border-2 border-[#e2e8f0] px-2 py-1.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
              <option value="">— тип —</option>
              <template x-for="t in imp1c.types" :key="t.id">
                <option :value="t.id" x-text="t.short_name"></option>
              </template>
            </select>
            <input x-model="m.new_short_name" placeholder="или новый тип"
                   class="flex-1 rounded-[14px] border-2 border-[#e2e8f0] px-2 py-1.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
          </div>
        </div>
      </template>
      <button @click="submitMappings()" :disabled="imp1c.saving"
              class="w-full bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-4 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200 disabled:opacity-40"
              x-text="imp1c.saving ? 'Сохранение…' : 'Сохранить соответствия'"></button>
    </div>

    <!-- Готов к сверке -->
    <div x-show="imp1c.status === 'awaiting_confirm'" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
      <h3 class="font-semibold text-sm text-[#0c4a6e]">Файл разобран. Все наименования сопоставлены.</h3>
      <button @click="confirm1c()" :disabled="imp1c.saving"
              class="w-full bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] px-4 py-2.5 text-sm font-medium shadow hover:shadow-[0_4px_12px_rgba(16,185,129,0.35)] transition-all duration-200 disabled:opacity-40"
              x-text="imp1c.saving ? 'Сверка…' : 'Запустить сверку'"></button>
    </div>

    <!-- Результат -->
    <div x-show="imp1c.stats" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-green-50 border border-green-300 p-4 space-y-1">
      <h3 class="font-semibold text-sm text-green-800">Сверка завершена</h3>
      <template x-if="imp1c.stats">
        <ul class="text-sm text-green-900">
          <li>Новые поступления: <b x-text="imp1c.stats.new_arrival"></b></li>
          <li>Списание: <b x-text="imp1c.stats.write_off"></b></li>
          <li>Незафиксированные перемещения: <b x-text="imp1c.stats.unrecorded_move"></b></li>
          <li>Подтверждено в 1С: <b x-text="imp1c.stats.seen_updated"></b></li>
        </ul>
      </template>
      <a href="#" @click.prevent="tab = 'disc'" class="text-sm text-[#0077B6] hover:text-[#005a8c] underline transition-colors duration-200">Перейти к расхождениям →</a>
    </div>
  </section>

  <!-- ================= Импорт остатков ================= -->
  <section x-show="tab === 'stock'" class="space-y-4">
    <div class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
      <h2 class="font-bold text-[#0c4a6e]">Первоначальный импорт остатков (файл матрицы)</h2>
      <select x-model="stock.buildingId" class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
        <option value="">— Выберите здание —</option>
        <template x-for="b in buildings" :key="b.id">
          <option :value="b.id" x-text="b.address"></option>
        </template>
        <option value="__new__">+ Новое здание…</option>
      </select>
      <input x-show="stock.buildingId === '__new__'" x-model="stock.newAddress"
             placeholder="Адрес нового здания"
             class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
      <input type="file" accept=".xlsx,.xls" x-ref="fileStock"
             class="w-full text-sm rounded-[14px] border-2 border-dashed border-[#e2e8f0] bg-[#f8fafc] px-3 py-2.5 cursor-pointer hover:border-[#00B4D8] hover:bg-[#f0f9ff] transition-all duration-200 file:mr-3 file:rounded-[10px] file:border-0 file:bg-gradient-to-br file:from-[#00B4D8] file:to-[#0077B6] file:text-white file:px-4 file:py-2 file:font-medium">
      <button @click="uploadStock()" :disabled="stock.loading"
              class="bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-4 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200 disabled:opacity-40"
              x-text="stock.loading ? 'Разбор файла…' : 'Загрузить и разобрать'"></button>
    </div>

    <!-- Статистика разбора -->
    <div x-show="stock.stats" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-1">
      <h3 class="font-semibold text-sm text-[#0c4a6e]">Результат разбора</h3>
      <template x-if="stock.stats">
        <ul class="text-sm">
          <li>Кабинетов: <b x-text="stock.stats.rooms"></b></li>
          <li>Единиц техники: <b x-text="stock.stats.items"></b></li>
          <li>Без номера (б/н): <b x-text="stock.stats.bn_count"></b></li>
          <li>MAC-адресов: <b x-text="stock.stats.mac_count"></b></li>
          <li x-show="stock.totalsCheck" :class="stock.totalsCheck && stock.totalsCheck.ok ? 'text-green-700' : 'text-red-600'">
            Сверка с контрольной строкой: <b x-text="stock.totalsCheck && stock.totalsCheck.ok ? 'совпадает' : 'есть расхождения'"></b>
          </li>
          <template x-if="stock.totalsCheck && !stock.totalsCheck.ok">
            <li class="text-xs text-red-600 whitespace-pre-line" x-text="(stock.totalsCheck.mismatches || []).join('\n')"></li>
          </template>
        </ul>
      </template>
    </div>

    <!-- Неоднозначные номера -->
    <div x-show="stock.ambiguous.length || stock.bn.length" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
      <h3 class="font-semibold text-sm text-[#0c4a6e]">Уточните неоднозначные номера</h3>
      <p class="text-xs text-slate-500" x-show="stock.ambiguous.length">По умолчанию номер считается серийным. Переключите на «инвентарный», если это инвентарный номер.</p>
      <template x-for="a in stock.ambiguous" :key="a.id">
        <div class="border-2 border-[#e2e8f0] rounded-[14px] p-3 flex flex-col sm:flex-row sm:items-center gap-2">
          <div class="flex-1 text-sm">
            <b x-text="a.value"></b>
            <span class="text-xs text-slate-400" x-text="' · каб. ' + a.room + ' · ' + a.short_type"></span>
          </div>
          <div class="flex rounded-[10px] overflow-hidden border-2 border-[#e2e8f0] text-xs">
            <button @click="a.kind = 'serial'"
                    class="px-3 py-1.5 transition-all duration-200" :class="a.kind === 'serial' ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white' : 'bg-white text-[#475569]'">серийный</button>
            <button @click="a.kind = 'inventory'"
                    class="px-3 py-1.5 transition-all duration-200" :class="a.kind === 'inventory' ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white' : 'bg-white text-[#475569]'">инвентарный</button>
          </div>
        </div>
      </template>

      <!-- Значения «Без номера (б/н)» -->
      <div x-show="stock.bn.length" class="space-y-2 pt-1">
        <h4 class="text-sm font-semibold text-[#0c4a6e]">Без номера (б/н)</h4>
        <div class="overflow-x-auto rounded-[14px] border-2 border-[#e2e8f0]">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-[#f8fafc] text-xs text-[#64748b]">
                <th class="text-left px-3 py-2 border-b border-[#e2e8f0]">Кабинет</th>
                <th class="text-left px-3 py-2 border-b border-[#e2e8f0]">Тип техники</th>
                <th class="text-right px-3 py-2 border-b border-[#e2e8f0]">Кол-во</th>
              </tr>
            </thead>
            <tbody>
              <template x-for="b in stock.bn" :key="b.room + '|' + b.short_type">
                <tr class="border-b border-slate-100 last:border-0">
                  <td class="px-3 py-1.5" x-text="b.room"></td>
                  <td class="px-3 py-1.5" x-text="b.short_type"></td>
                  <td class="px-3 py-1.5 text-right" x-text="b.count"></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div x-show="stock.batchId" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4">
      <button @click="confirmStock()" :disabled="stock.saving"
              class="w-full bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] px-4 py-2.5 text-sm font-medium shadow hover:shadow-[0_4px_12px_rgba(16,185,129,0.35)] transition-all duration-200 disabled:opacity-40"
              x-text="stock.saving ? 'Импорт…' : 'Подтвердить импорт'"></button>
    </div>

    <div x-show="stock.doneStats" class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-green-50 border border-green-300 p-4 space-y-1">
      <h3 class="font-semibold text-sm text-green-800">Импорт завершён</h3>
      <template x-if="stock.doneStats">
        <ul class="text-sm text-green-900">
          <li>Создано кабинетов: <b x-text="stock.doneStats.rooms_created"></b></li>
          <li>Создано единиц: <b x-text="stock.doneStats.equipment_created"></b></li>
          <li>Дубликатов пропущено: <b x-text="stock.doneStats.duplicates"></b></li>
        </ul>
      </template>
      <!-- Список пропущенных дублей -->
      <div x-show="stock.duplicatesList.length" class="pt-2 space-y-1">
        <h4 class="text-sm font-semibold text-amber-800">Пропущенные дубликаты:</h4>
        <div class="overflow-x-auto rounded-[14px] border border-amber-300 bg-white">
          <table class="w-full text-xs">
            <thead>
              <tr class="bg-amber-50 text-amber-900">
                <th class="text-left px-3 py-1.5 border-b border-amber-200">Кабинет</th>
                <th class="text-left px-3 py-1.5 border-b border-amber-200">Тип техники</th>
                <th class="text-left px-3 py-1.5 border-b border-amber-200">Номер</th>
              </tr>
            </thead>
            <tbody>
              <template x-for="(d, i) in stock.duplicatesList" :key="i">
                <tr class="border-b border-slate-100 last:border-0">
                  <td class="px-3 py-1.5" x-text="d.room"></td>
                  <td class="px-3 py-1.5" x-text="d.short_type"></td>
                  <td class="px-3 py-1.5" x-text="(d.kind === 'inventory' ? 'инв: ' : 'с/н: ') + d.number"></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= Расхождения ================= -->
  <section x-show="tab === 'disc'" class="space-y-4">
    <div class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 flex flex-wrap gap-2 items-center">
      <select x-model="disc.type" @change="loadDisc()" class="rounded-[14px] border-2 border-[#e2e8f0] px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
        <option value="">Все типы</option>
        <option value="new_arrival">Новые поступления</option>
        <option value="write_off">Списание</option>
        <option value="unrecorded_move">Перемещения</option>
      </select>
      <select x-model="disc.resolved" @change="loadDisc()" class="rounded-[14px] border-2 border-[#e2e8f0] px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
        <option value="0">Нерешённые</option>
        <option value="1">Решённые</option>
        <option value="all">Все</option>
      </select>
      <select x-model="disc.buildingId" @change="loadDisc()"
              class="rounded-[14px] border-2 border-[#e2e8f0] px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
        <option value="">Все здания</option>
        <template x-for="b in buildings" :key="b.id">
          <option :value="b.id" x-text="b.address"></option>
        </template>
      </select>
      <a :href="discExcelUrl()" @click.prevent="if (disc.items.length) window.location.href = discExcelUrl()"
         class="bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] px-4 py-2 text-sm font-medium shadow hover:shadow-[0_4px_12px_rgba(16,185,129,0.35)] transition-all duration-200"
         :class="disc.items.length ? '' : 'opacity-40 pointer-events-none'">Скачать Excel</a>
      <button @click="loadDisc()" class="ml-auto bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-4 py-2 text-sm transition-all duration-200">Обновить</button>
    </div>

    <div x-show="disc.loading" class="text-sm text-slate-500">Загрузка…</div>
    <div x-show="!disc.loading && !disc.items.length" class="rounded-[20px] bg-white shadow-[0_4px_20px_rgba(0,0,0,0.04)] p-4 text-sm text-[#64748b]">
      Расхождений нет.
    </div>

    <template x-for="d in disc.items" :key="d.id">
      <article class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-2">
        <div class="flex items-center justify-between gap-2">
          <span class="text-xs font-semibold rounded-full px-2 py-0.5"
                :class="{
                  'bg-green-100 text-green-700': d.type === 'new_arrival',
                  'bg-red-100 text-red-700': d.type === 'write_off',
                  'bg-amber-100 text-amber-700': d.type === 'unrecorded_move'
                }"
                x-text="discTypeLabel(d.type)"></span>
          <div class="flex items-center gap-2">
            <span class="text-xs text-slate-400" x-text="d.created_at"></span>
            <button x-show="d.equipment" @click="openDiscMenu(d)"
                    class="w-8 h-8 rounded-[10px] bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] text-lg leading-none transition-all duration-200">⋮</button>
            <button x-show="!d.equipment && d.type === 'new_arrival' && isAdmin" @click="openDiscAdd(d)"
                    title="Создать новую единицу техники"
                    class="w-8 h-8 rounded-[10px] bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] text-lg leading-none transition-all duration-200">⋮</button>
          </div>
        </div>

        <!-- Сравнение: база против 1С -->
        <div class="space-y-1.5 text-sm">
          <div>
            <span class="text-xs font-semibold text-slate-500">В базе:</span>
            <template x-if="d.equipment">
              <div class="break-words" x-text="discEqLine(d.equipment)"></div>
            </template>
            <template x-if="!d.equipment">
              <div class="text-slate-400">— отсутствует —</div>
            </template>
          </div>
          <div>
            <span class="text-xs font-semibold text-slate-500">В 1С:</span>
            <div x-html="disc1cLine(d)"></div>
          </div>
          <div x-show="d.type === 'new_arrival' && d.alias_match" class="rounded-[14px] bg-amber-50 border border-amber-200 p-2.5">
            <span class="text-xs font-semibold text-amber-800">В базе по сопоставлению: </span>
            <span class="text-xs text-amber-900 break-words"
                  x-text="discAliasLine(d)"></span>
          </div>
        </div>

        <div x-show="d.resolved" class="text-xs text-slate-400">Решено</div>
      </article>
    </template>

    <!-- Bottom-sheet: действия с техникой (как в карточке кабинета) -->
    <div x-show="discSheet" x-cloak class="fixed inset-0 z-50 flex items-end justify-center" @keydown.escape.window="closeDiscSheet()">
      <div class="absolute inset-0 bg-black/40" @click="closeDiscSheet()"></div>
      <div class="relative bg-white w-full max-w-lg rounded-t-[24px] shadow-[0_20px_60px_rgba(0,119,182,0.12)] p-4 space-y-3 max-h-[88vh] overflow-y-auto">
        <template x-if="discSheet">
          <div class="space-y-3">
            <div>
              <div class="font-bold text-[#0c4a6e]" x-text="discSheet.short_name || discSheet.long_name_1c || '—'"></div>
              <div class="text-sm">
                <span x-show="discSheet.inventory_number">инв: <b x-text="discSheet.inventory_number"></b></span>
                <span x-show="discSheet.serial_number"> · сер: <b x-text="discSheet.serial_number"></b></span>
              </div>
            </div>

            <!-- Меню -->
            <div x-show="discMode === 'menu'" class="space-y-2">
              <button @click="discStartMove()" class="w-full bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-3 py-2.5 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] transition-all duration-200">Переместить</button>
              <button x-show="isAdmin" @click="discStartEdit()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-3 py-2.5 text-sm font-medium transition-all duration-200">✏️ Редактировать</button>
              <button @click="discLoadHistory()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] px-3 py-2.5 text-sm font-medium transition-all duration-200">История перемещений</button>
            </div>

            <!-- Перемещение: шаг 1 — здание -->
            <div x-show="discMode === 'move_building'" class="space-y-2">
              <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 1. Выберите здание</h3>
              <template x-for="b in buildings" :key="b.id">
                <button @click="discPickBuilding(b)" class="w-full text-left bg-white border-2 border-[#e2e8f0] rounded-[14px] px-3 py-2.5 text-sm hover:border-[#00B4D8] hover:bg-[#f0f9ff] transition-all duration-200" x-text="b.address"></button>
              </template>
            </div>

            <!-- Перемещение: шаг 2 — кабинет -->
            <div x-show="discMode === 'move_room'" class="space-y-2">
              <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 2. Кабинет в «<span x-text="discMoveBuilding && discMoveBuilding.address"></span>»</h3>
              <input x-model="discNewRoom" placeholder="…или введите новый кабинет"
                     class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              <div class="max-h-60 overflow-y-auto space-y-1">
                <template x-for="r in discMoveRooms" :key="r.id">
                  <button @click="discRoomId = r.id; discNewRoom = ''"
                          class="w-full text-left rounded-[10px] px-3 py-2 text-sm border-2 transition-all duration-200"
                          :class="discRoomId === r.id ? 'bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white border-transparent' : 'bg-white border-[#e2e8f0] hover:border-[#00B4D8]'"
                          x-text="r.room_number"></button>
                </template>
              </div>
              <div class="flex gap-2">
                <button @click="discMode = 'move_building'" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
                <button @click="discMode = 'move_comment'" :disabled="!discRoomId && !discNewRoom.trim()"
                        class="flex-1 bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">Далее</button>
              </div>
            </div>

            <!-- Перемещение: шаг 3 — комментарий -->
            <div x-show="discMode === 'move_comment'" class="space-y-2">
              <h3 class="font-semibold text-sm text-[#0c4a6e]">Шаг 3. Комментарий (необязательно)</h3>
              <textarea x-model="discComment" rows="2" class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
              <div class="flex gap-2">
                <button @click="discMode = 'move_room'" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
                <button @click="discSubmitMove()" :disabled="discSaving" class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">Подтвердить</button>
              </div>
            </div>

            <!-- Редактирование -->
            <div x-show="discMode === 'edit'" class="space-y-2">
              <input x-model="discEdit.long_name_1c" placeholder="Название"
                     class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              <textarea x-model="discEdit.aliases" rows="3" placeholder="Сопоставление (каждое значение с новой строки)"
                        class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
              <input x-model="discEdit.inventory_number" placeholder="Инвентарный номер" @blur="discCheckDupes()"
                     class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              <input x-model="discEdit.serial_number" placeholder="Серийный номер" @blur="discCheckDupes()"
                     class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              <input x-model="discEdit.mac_address" placeholder="MAC-адрес" @blur="discCheckDupes()"
                     class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              <div x-show="discDupes.length" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="'Такие данные уже есть у другой техники:\n' + discDupes.join('\n')"></div>
              <div x-show="discEditError" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="discEditError"></div>
              <div x-show="discScopeAsk" class="rounded-[14px] bg-amber-50 border-2 border-amber-200 p-3 space-y-2">
                <div class="text-sm text-amber-800">Такого названия ещё нет в базе. Как применить?</div>
                <button @click="discSaveEdit('all')" :disabled="discSaving" class="w-full bg-amber-500 hover:bg-amber-600 text-white rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40"
                        x-text="'Изменить у всей техники с прежним названием (' + discScopeAffected + ' ед.)'"></button>
                <button @click="discSaveEdit('single')" :disabled="discSaving" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">Создать как новое название (только эта единица)</button>
              </div>
              <div class="flex gap-2" x-show="!discScopeAsk">
                <button @click="discMode = 'menu'" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
                <button @click="discSaveEdit('')" :disabled="discSaving || discDupes.length > 0 || !discEdit.long_name_1c.trim()"
                        class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium transition-all duration-200 disabled:opacity-40">Сохранить</button>
              </div>
            </div>

            <!-- Создание новой единицы (новое поступление) -->
            <div x-show="discMode === 'add'" class="space-y-2">
              <h3 class="font-semibold text-sm text-[#0c4a6e]">Создание новой единицы техники</h3>
              <div class="text-xs rounded-[14px] p-2.5"
                   :class="discAdd.matched ? 'bg-blue-50 text-blue-800' : 'bg-slate-50 text-slate-600'"
                   x-text="discAdd.matched
                     ? 'Найдено в базе по сопоставлению: «' + discAdd.matchedName + '» — наименование и сопоставление взяты из базы, остальные данные — из 1С'
                     : 'В базе по сопоставлению не найдено — все поля заполнены данными из 1С'"></div>
              <div>
                <label class="block text-xs text-[#64748b] mb-1">Наименование</label>
                <input x-model="discAdd.name" class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              </div>
              <div>
                <label class="block text-xs text-[#64748b] mb-1">Сопоставление (каждое значение с новой строки)</label>
                <textarea x-model="discAdd.aliases" rows="3"
                          class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200"></textarea>
              </div>
              <div>
                <label class="block text-xs text-[#64748b] mb-1">Инвентарный номер</label>
                <input x-model="discAdd.inventory_number" @blur="discCheckAddDupes()"
                       class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              </div>
              <div>
                <label class="block text-xs text-[#64748b] mb-1">Серийный номер</label>
                <input x-model="discAdd.serial_number" @blur="discCheckAddDupes()"
                       class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              </div>
              <div>
                <label class="block text-xs text-[#64748b] mb-1">MAC-адрес</label>
                <input x-model="discAdd.mac_address" @blur="discCheckAddDupes()"
                       class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] focus:shadow-[0_0_0_4px_rgba(0,180,216,0.12)] transition-all duration-200">
              </div>
              <div>
                <label class="block text-xs text-[#64748b] mb-1">Здание</label>
                <select x-model="discAdd.building_id" @change="discLoadAddRooms()"
                        class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
                  <option value="">— Выберите здание —</option>
                  <template x-for="b in buildings" :key="b.id">
                    <option :value="b.id" x-text="b.address"></option>
                  </template>
                </select>
              </div>
              <div x-show="discAdd.building_id">
                <label class="block text-xs text-[#64748b] mb-1">Кабинет</label>
                <select x-model="discAdd.room_id"
                        class="w-full rounded-[14px] border-2 border-[#e2e8f0] px-4 py-2.5 text-sm bg-white focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
                  <option value="">— Без кабинета —</option>
                  <template x-for="r in discAddRooms" :key="r.id">
                    <option :value="r.id" x-text="r.room_number"></option>
                  </template>
                </select>
              </div>
              <div x-show="discAddDupes.length" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line"
                   x-text="'Такие данные уже есть у другой техники:\n' + discAddDupes.join('\n')"></div>
              <div x-show="discAddError" class="rounded-[14px] bg-red-50 text-red-700 p-3 text-sm whitespace-pre-line" x-text="discAddError"></div>
              <div class="flex gap-2">
                <button @click="closeDiscSheet()" class="flex-1 bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Отмена</button>
                <button @click="discSubmitAdd()" :disabled="discSaving || !discAdd.name.trim() || !discAdd.building_id || discAddDupes.length > 0"
                        class="flex-1 bg-gradient-to-br from-[#10b981] to-[#059669] text-white rounded-[10px] py-2 text-sm font-medium shadow transition-all duration-200 disabled:opacity-40"
                        x-text="discSaving ? 'Создание…' : 'Создать'"></button>
              </div>
            </div>

            <!-- История -->
            <div x-show="discMode === 'history'" class="space-y-2">
              <h3 class="font-semibold text-sm text-[#0c4a6e]">История перемещений</h3>
              <ul class="text-sm divide-y divide-slate-100">
                <template x-for="m in discHistory" :key="m.id">
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
              <button @click="discMode = 'menu'" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Назад</button>
            </div>

            <button @click="closeDiscSheet()" class="w-full bg-[#f1f5f9] hover:bg-[#e2e8f0] text-[#475569] rounded-[10px] py-2 text-sm transition-all duration-200">Закрыть</button>
          </div>
        </template>
      </div>
    </div>
  </section>

  <!-- ================= Пользователи ================= -->
  <section x-show="tab === 'users'" class="space-y-4">
    <div class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white p-4 space-y-3">
      <div class="flex items-center justify-between gap-2">
        <h2 class="font-bold text-[#0c4a6e]">Пользователи</h2>
        <button @click="syncUsers()" :disabled="users.syncing"
                class="bg-gradient-to-br from-[#00B4D8] to-[#0077B6] text-white rounded-[10px] px-4 py-2 text-sm font-medium shadow-[0_4px_16px_rgba(0,180,216,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(0,180,216,0.45)] transition-all duration-200 disabled:opacity-40"
                x-text="users.syncing ? 'Синхронизация…' : 'Обновить'"></button>
      </div>
      <p class="text-xs text-slate-500">
        Список зеркалирует организацию Yandex 360: «Обновить» добавляет новых и удаляет уволенных.
        <span x-show="!users.canManage">Назначать права может только администратор системы (<span x-text="users.superEmail"></span>).</span>
      </p>
    </div>
    <div x-show="users.loading" class="text-sm text-slate-500">Загрузка…</div>
    <div class="rounded-[20px] shadow-[0_4px_20px_rgba(0,0,0,0.04)] bg-white overflow-hidden" x-show="users.items.length">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gradient-to-b from-[#f8fafc] to-[#f1f5f9] text-left text-xs text-[#475569]">
              <th class="px-3 py-2">ФИО</th>
              <th class="px-3 py-2">Email</th>
              <th class="px-3 py-2">Роль</th>
              <th class="px-3 py-2">Активен</th>
              <th class="px-3 py-2">Последний вход</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <template x-for="u in users.items" :key="u.id">
              <tr>
                <td class="px-3 py-2">
                  <div class="font-medium" x-text="u.full_name || '—'"></div>
                </td>
                <td class="px-3 py-2 text-xs" x-text="u.email"></td>
                <td class="px-3 py-2">
                  <select :value="u.role" @change="updateUser(u, { role: $event.target.value })"
                          :disabled="!users.canManage"
                          class="rounded-[10px] border-2 border-[#e2e8f0] px-2 py-1 text-xs bg-white disabled:opacity-50 focus:outline-none focus:border-[#00B4D8] transition-all duration-200">
                    <option value="admin">admin</option>
                    <option value="economist">economist</option>
                    <option value="viewer">viewer</option>
                  </select>
                </td>
                <td class="px-3 py-2">
                  <button @click="updateUser(u, { is_active: u.is_active ? 0 : 1 })"
                          :disabled="!users.canManage"
                          class="w-10 h-6 rounded-full relative transition disabled:opacity-50"
                          :class="u.is_active ? 'bg-green-500' : 'bg-slate-300'">
                    <span class="absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-all"
                          :class="u.is_active ? 'left-[1.125rem]' : 'left-0.5'"></span>
                  </button>
                </td>
                <td class="px-3 py-2 text-xs text-slate-400" x-text="u.last_login || '—'"></td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<script>
function adminPage(isAdmin) {
  return {
    isAdmin: isAdmin,
    tab: 'import1c',
    buildings: [],

    get tabs() {
      const t = [
        { key: 'import1c', label: 'Импорт 1С' },
        { key: 'stock', label: 'Остатки' },
        { key: 'disc', label: 'Расхождения' },
        { key: 'users', label: 'Пользователи' }
      ];
      return t;
    },

    imp1c: {
      loading: false, saving: false, batchId: '', status: '', mappings: [], types: [], stats: null,
      geoBuildings: [], geoRooms: {}, geoUnknownB: [], geoUnknownR: [], geoErrors: [], geoSaving: false
    },
    stock: {
      loading: false, saving: false, buildingId: '', newAddress: '',
      batchId: '', stats: null, totalsCheck: null, ambiguous: [], bn: [], doneStats: null, duplicatesList: []
    },
    disc: { loading: false, type: '', resolved: '0', buildingId: '', items: [] },
    users: { loading: false, syncing: false, items: [], canManage: false, superEmail: '' },

    async init() {
      try {
        const d = await App.api('api_buildings.php');
        this.buildings = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
      this.loadDisc();
      this.loadUsers();
    },

    /* ---------- Импорт 1С ---------- */
    async upload1c() {
      const f = this.$refs.file1c.files[0];
      if (!f) { App.toast('Выберите файл', 'warning'); return; }
      const fd = new FormData();
      fd.append('file', f);
      this.imp1c.loading = true;
      this.imp1c.stats = null;
      try {
        const d = await App.api('api_import_1c.php', { method: 'POST', body: fd });
        this.imp1c.batchId = d.batch_id;
        this.imp1c.status = d.status;
        await this.loadGeo();
        if (d.status === 'mapping_required') {
          await this.loadMapping();
        } else if (d.status === 'awaiting_confirm') {
          App.toast('Файл разобран, можно запускать сверку', 'success');
        }
      } catch (e) { App.toast(e.message, 'error'); }
      this.imp1c.loading = false;
    },
    async loadMapping() {
      try {
        const d = await App.api('api_mapping.php?batch_id=' + encodeURIComponent(this.imp1c.batchId));
        this.imp1c.types = d.types || [];
        this.imp1c.mappings = (d.unknown || []).map(u => ({
          long_name_1c: u.long_name_1c, count: u.count, short_type_id: '', new_short_name: ''
        }));
      } catch (e) { App.toast(e.message, 'error'); }
    },
    async submitMappings() {
      const payload = this.imp1c.mappings
        .filter(m => m.short_type_id || m.new_short_name.trim())
        .map(m => ({
          long_name_1c: m.long_name_1c,
          short_type_id: m.short_type_id || undefined,
          new_short_name: m.new_short_name.trim() || undefined
        }));
      if (!payload.length) { App.toast('Заполните хотя бы одно соответствие', 'warning'); return; }
      this.imp1c.saving = true;
      try {
        const d = await App.api('api_mapping.php', {
          method: 'POST',
          body: { batch_id: this.imp1c.batchId, mappings: payload }
        });
        this.imp1c.status = d.batch_status;
        if (d.batch_status === 'mapping_required') {
          App.toast('Осталось несопоставленных: ' + d.remaining_unknown, 'warning');
          await this.loadMapping();
        } else {
          App.toast('Все наименования сопоставлены', 'success');
        }
      } catch (e) { App.toast(e.message, 'error'); }
      this.imp1c.saving = false;
    },
    async confirm1c() {
      this.imp1c.saving = true;
      try {
        const d = await App.api('api_import_1c.php', {
          method: 'POST',
          body: { batch_id: this.imp1c.batchId, confirm: true }
        });
        this.imp1c.status = 'completed';
        this.imp1c.stats = d.stats;
        App.toast('Сверка завершена', 'success');
        this.loadDisc();
      } catch (e) { App.toast(e.message, 'error'); }
      this.imp1c.saving = false;
    },

    /* ---------- Гео-сопоставления (адреса/кабинеты 1С -> база) ---------- */
    async loadGeo() {
      if (!this.imp1c.batchId) return;
      try {
        const d = await App.api('api_import_1c.php', {
          method: 'POST',
          body: { action: 'geo_status', batch_id: this.imp1c.batchId }
        });
        this.imp1c.geoUnknownB = (d.unknown_buildings || []).map(u => ({ address: u.address, count: u.count, building_id: '' }));
        this.imp1c.geoUnknownR = (d.unknown_rooms || []).map(u => ({ address: u.address, room: u.room, count: u.count, building_id: u.building_id, room_id: '' }));
        this.imp1c.geoErrors = [];
        if (!this.imp1c.geoBuildings.length) {
          const b = await App.api('api_buildings.php');
          this.imp1c.geoBuildings = b.items || [];
        }
        /* Кабинеты для адресов с уже известным зданием */
        for (const u of this.imp1c.geoUnknownR) {
          if (u.building_id) await this.loadGeoRooms(u.address, u.building_id);
        }
      } catch (e) { App.toast(e.message, 'error'); }
    },
    async loadGeoRooms(address, buildingId) {
      try {
        const d = await App.api('api_rooms.php?building_id=' + encodeURIComponent(buildingId));
        this.imp1c.geoRooms[address] = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
    },
    async onGeoBuildingChange(u) {
      u.room_id = '';
      if (u.building_id) {
        await this.loadGeoRooms(u.address, u.building_id);
        /* Обновить список кабинетов для всех неизвестных кабинетов этого адреса */
        for (const r of this.imp1c.geoUnknownR) {
          if (r.address === u.address) r.building_id = parseInt(u.building_id, 10);
        }
      }
    },
    async saveGeo() {
      const buildingMap = {};
      for (const u of this.imp1c.geoUnknownB) {
        if (u.building_id) buildingMap[u.address] = parseInt(u.building_id, 10);
      }
      const roomMap = this.imp1c.geoUnknownR
        .filter(u => u.room_id)
        .map(u => ({ address: u.address, room: u.room, room_id: parseInt(u.room_id, 10) }));
      if (!Object.keys(buildingMap).length && !roomMap.length) {
        App.toast('Выберите хотя бы одно соответствие', 'warning');
        return;
      }
      this.imp1c.geoSaving = true;
      try {
        const d = await App.api('api_import_1c.php', {
          method: 'POST',
          body: { action: 'resolve_geo', batch_id: this.imp1c.batchId, building_map: buildingMap, room_map: roomMap }
        });
        this.imp1c.geoErrors = d.errors || [];
        this.imp1c.geoUnknownB = (d.unknown_buildings || []).map(u => ({ address: u.address, count: u.count, building_id: '' }));
        this.imp1c.geoUnknownR = (d.unknown_rooms || []).map(u => ({ address: u.address, room: u.room, count: u.count, building_id: u.building_id, room_id: '' }));
        for (const u of this.imp1c.geoUnknownR) {
          if (u.building_id) await this.loadGeoRooms(u.address, u.building_id);
        }
        if (!this.imp1c.geoUnknownB.length && !this.imp1c.geoUnknownR.length) {
          App.toast('Все адреса и кабинеты сопоставлены', 'success');
        } else {
          App.toast('Сохранено. Осталось несопоставленных: ' + (this.imp1c.geoUnknownB.length + this.imp1c.geoUnknownR.length), 'warning');
        }
      } catch (e) { App.toast(e.message, 'error'); }
      this.imp1c.geoSaving = false;
    },

    /* ---------- Импорт остатков ---------- */
    async uploadStock() {
      if (!this.stock.buildingId) { App.toast('Выберите здание', 'warning'); return; }
      if (this.stock.buildingId === '__new__' && !this.stock.newAddress.trim()) {
        App.toast('Введите адрес нового здания', 'warning'); return;
      }
      const f = this.$refs.fileStock.files[0];
      if (!f) { App.toast('Выберите файл', 'warning'); return; }
      const fd = new FormData();
      fd.append('file', f);
      if (this.stock.buildingId === '__new__') {
        fd.append('new_address', this.stock.newAddress.trim());
      } else {
        fd.append('building_id', this.stock.buildingId);
      }
      this.stock.loading = true;
      this.stock.batchId = '';
      this.stock.stats = null;
      this.stock.totalsCheck = null;
      this.stock.ambiguous = [];
      this.stock.bn = [];
      this.stock.doneStats = null;
      this.stock.duplicatesList = [];
      try {
        const d = await App.api('api_import_stock.php', { method: 'POST', body: fd });
        this.stock.batchId = d.batch_id;
        this.stock.stats = d.stats;
        this.stock.totalsCheck = d.totals_check || null;
        this.stock.bn = d.bn || [];
        this.stock.ambiguous = (d.ambiguous || []).map(a => ({
          id: a.id, room: a.room, short_type: a.short_type, value: a.value,
          kind: a.suggested || 'serial'
        }));
      } catch (e) { App.toast(e.message, 'error'); }
      this.stock.loading = false;
    },
    async confirmStock() {
      this.stock.saving = true;
      try {
        const d = await App.api('api_import_stock.php', {
          method: 'POST',
          body: {
            batch_id: this.stock.batchId,
            confirm: true,
            resolutions: this.stock.ambiguous.map(a => ({ id: a.id, kind: a.kind }))
          }
        });
        this.stock.doneStats = d.stats;
        this.stock.duplicatesList = d.duplicates_list || [];
        this.stock.batchId = '';
        App.toast('Остатки импортированы', 'success');
      } catch (e) { App.toast(e.message, 'error'); }
      this.stock.saving = false;
    },

    /* ---------- Расхождения ---------- */
    async loadDisc() {
      this.disc.loading = true;
      try {
        let url = 'api_discrepancies.php?resolved=' + encodeURIComponent(this.disc.resolved);
        if (this.disc.type) url += '&type=' + encodeURIComponent(this.disc.type);
        if (this.disc.buildingId) url += '&building_id=' + encodeURIComponent(this.disc.buildingId);
        const d = await App.api(url);
        this.disc.items = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
      this.disc.loading = false;
    },
    discTypeLabel(t) {
      return { new_arrival: 'Новое поступление', write_off: 'Списание', unrecorded_move: 'Перемещение' }[t] || t;
    },
    /* Строка «В базе по сопоставлению» (d.alias_match может быть null/undefined) */
    discAliasLine(d) {
      const m = d && d.alias_match ? d.alias_match : null;
      if (!m) return '';
      return (m.short_name || m.name || '—') + (m.building ? ' — ' + m.building + ', каб. ' + (m.room || '—') : '');
    },
    /* Строка «В базе»: Наименование · Здание · Кабинет · Инв. · Сер. */
    discEqLine(eq) {
      const parts = [];
      parts.push(eq.short_name || eq.long_name_1c || '—');
      parts.push(eq.building || '—');
      parts.push('каб. ' + (eq.room || '—'));
      parts.push('инв: ' + (eq.inventory_number || '—'));
      parts.push('сер: ' + (eq.serial_number || '—'));
      return parts.join(' · ');
    },
    /* Строка «В 1С»: отличающиеся поля подсвечены */
    disc1cLine(d) {
      const det = d.details || {};
      const eq = d.equipment || {};
      if (d.type === 'write_off') {
        return '<div class="text-slate-400">— отсутствует (нет в файле 1С) —</div>';
      }
      let name1c, bld1c, room1c, inv1c, ser1c;
      if (d.type === 'unrecorded_move') {
        const to = det.to || {};
        name1c = eq.short_name || eq.long_name_1c || '—';
        bld1c = to.building || '—';
        room1c = to.room || '—';
        inv1c = eq.inventory_number || '—';
        ser1c = eq.serial_number || '—';
      } else {
        name1c = det.long_name_1c || '—';
        bld1c = det.building || '—';
        room1c = det.room || '—';
        inv1c = det.inventory_number || '—';
        ser1c = det.serial_number || '—';
      }
      const diff = (a, b) => a !== b;
      const esc = App.esc;
      const span = (v, changed) => changed
        ? '<span class="text-amber-700 font-semibold">' + esc(v) + ' ⧗</span>'
        : '<span>' + esc(v) + '</span>';
      return '<div class="break-words">' + [
        span(name1c, eq.id ? diff(String(name1c), String(eq.short_name || eq.long_name_1c || '')) : false),
        span(bld1c, eq.id ? diff(String(bld1c), String(eq.building || '')) : false),
        span('каб. ' + room1c, eq.id ? diff(String(room1c), String(eq.room || '')) : false),
        span('инв: ' + inv1c, eq.id ? diff(String(inv1c), String(eq.inventory_number || '')) : false),
        span('сер: ' + ser1c, eq.id ? diff(String(ser1c), String(eq.serial_number || '')) : false),
      ].join(' · ') + '</div>';
    },
    discExcelUrl() {
      let url = 'api_discrepancies_excel.php?resolved=' + encodeURIComponent(this.disc.resolved);
      if (this.disc.type) url += '&type=' + encodeURIComponent(this.disc.type);
      if (this.disc.buildingId) url += '&building_id=' + encodeURIComponent(this.disc.buildingId);
      return url;
    },

    /* ---------- Действия с техникой из расхождений (bottom-sheet) ---------- */
    discSheet: null,
    discMode: 'menu',
    discSaving: false,
    discMoveBuilding: null,
    discMoveRooms: [],
    discRoomId: null,
    discNewRoom: '',
    discComment: '',
    discEdit: { long_name_1c: '', aliases: '', inventory_number: '', serial_number: '', mac_address: '' },
    discDupes: [],
    discEditError: '',
    discScopeAsk: false,
    discScopeAffected: 0,
    discHistory: [],
    /* Создание единицы из «Нового поступления» */
    discAdd: { name: '', aliases: '', inventory_number: '', serial_number: '', mac_address: '', building_id: '', room_id: '', matched: false, matchedName: '' },
    discAddRooms: [],
    discAddDupes: [],
    discAddError: '',

    openDiscMenu(d) {
      this.discSheet = Object.assign({}, d.equipment);
      this.discMode = 'menu';
      this.discHistory = [];
      this.discScopeAsk = false;
      this.discEditError = '';
      this.discDupes = [];
    },
    /* «Новое поступление»: создание новой единицы.
       Если в базе найдена единица, у которой название 1С есть в «Сопоставлении» —
       наименование и сопоставление берём из неё, остальное — из 1С.
       Иначе — все поля из 1С. */
    openDiscAdd(d) {
      const det = d.details || {};
      const m = d.alias_match || null;
      this.discSheet = { _add: true, long_name_1c: det.long_name_1c || '' };
      this.discMode = 'add';
      this.discAdd = {
        name: m ? (m.name || det.long_name_1c || '') : (det.long_name_1c || ''),
        aliases: m ? (m.aliases || '') : '',
        inventory_number: det.inventory_number || '',
        serial_number: det.serial_number || '',
        mac_address: det.mac_address || '',
        building_id: det.building_id || '',
        room_id: det.room_id || '',
        matched: !!m,
        matchedName: m ? (m.name || '') : ''
      };
      this.discAddRooms = [];
      this.discAddDupes = [];
      this.discAddError = '';
      if (this.discAdd.building_id) {
        this.discLoadAddRooms();
      }
    },
    async discLoadAddRooms() {
      this.discAddRooms = [];
      if (!this.discAdd.building_id) {
        this.discAdd.room_id = '';
        return;
      }
      try {
        const d = await App.api('api_rooms.php?building_id=' + encodeURIComponent(this.discAdd.building_id));
        this.discAddRooms = d.items || [];
        if (this.discAdd.room_id && !this.discAddRooms.some(r => r.id === this.discAdd.room_id)) {
          this.discAdd.room_id = '';
        }
      } catch (e) { App.toast(e.message, 'error'); }
    },
    async discCheckAddDupes() {
      const p = new URLSearchParams({ dupes: '1' });
      if (this.discAdd.inventory_number.trim()) p.set('inventory_number', this.discAdd.inventory_number.trim());
      if (this.discAdd.serial_number.trim()) p.set('serial_number', this.discAdd.serial_number.trim());
      if (this.discAdd.mac_address.trim()) p.set('mac_address', this.discAdd.mac_address.trim());
      if (!p.has('inventory_number') && !p.has('serial_number') && !p.has('mac_address')) {
        this.discAddDupes = [];
        return;
      }
      try {
        const d = await App.api('api_equipment.php?' + p.toString());
        this.discAddDupes = d.conflicts || [];
      } catch (e) { this.discAddDupes = []; }
    },
    async discSubmitAdd() {
      this.discSaving = true;
      this.discAddError = '';
      try {
        await App.api('api_equipment.php', {
          method: 'POST',
          body: {
            action: 'create',
            long_name_1c: this.discAdd.name,
            aliases: this.discAdd.aliases,
            inventory_number: this.discAdd.inventory_number,
            serial_number: this.discAdd.serial_number,
            mac_address: this.discAdd.mac_address,
            building_id: this.discAdd.building_id,
            room_id: this.discAdd.room_id || 0
          }
        });
        App.toast('Техника добавлена', 'success');
        this.closeDiscSheet();
        this.loadDisc();
      } catch (e) { this.discAddError = e.message; }
      this.discSaving = false;
    },
    closeDiscSheet() {
      this.discSheet = null;
      this.discMode = 'menu';
      this.discScopeAsk = false;
      this.discDupes = [];
      this.discEditError = '';
      this.discAddRooms = [];
      this.discAddDupes = [];
      this.discAddError = '';
    },
    discStartMove() {
      this.discMode = 'move_building';
      this.discMoveBuilding = null;
      this.discMoveRooms = [];
      this.discRoomId = null;
      this.discNewRoom = '';
      this.discComment = '';
    },
    async discPickBuilding(b) {
      this.discMoveBuilding = b;
      this.discRoomId = null;
      this.discNewRoom = '';
      this.discMode = 'move_room';
      try {
        const d = await App.api('api_rooms.php?building_id=' + encodeURIComponent(b.id));
        this.discMoveRooms = d.items || [];
      } catch (e) { App.toast(e.message, 'error'); }
    },
    async discSubmitMove() {
      this.discSaving = true;
      try {
        await App.api('api_move.php', {
          method: 'POST',
          body: {
            equipment_id: this.discSheet.id,
            to_building_id: this.discMoveBuilding.id,
            to_room_id: this.discRoomId || undefined,
            new_room: this.discNewRoom.trim() || undefined,
            comment: this.discComment.trim() || undefined
          }
        });
        App.toast('Техника перемещена', 'success');
        this.closeDiscSheet();
        this.loadDisc();
      } catch (e) { App.toast(e.message, 'error'); }
      this.discSaving = false;
    },
    discStartEdit() {
      this.discMode = 'edit';
      this.discEditError = '';
      this.discScopeAsk = false;
      this.discDupes = [];
      this.discEdit = {
        long_name_1c: this.discSheet.long_name_1c || this.discSheet.short_name || '',
        aliases: this.discSheet.aliases || '',
        inventory_number: this.discSheet.inventory_number || '',
        serial_number: this.discSheet.serial_number || '',
        mac_address: this.discSheet.mac_address || ''
      };
    },
    async discCheckDupes() {
      const p = new URLSearchParams({ dupes: '1', exclude_id: this.discSheet.id });
      if (this.discEdit.inventory_number.trim()) p.set('inventory_number', this.discEdit.inventory_number.trim());
      if (this.discEdit.serial_number.trim()) p.set('serial_number', this.discEdit.serial_number.trim());
      if (this.discEdit.mac_address.trim()) p.set('mac_address', this.discEdit.mac_address.trim());
      if (!p.has('inventory_number') && !p.has('serial_number') && !p.has('mac_address')) {
        this.discDupes = [];
        return;
      }
      try {
        const d = await App.api('api_equipment.php?' + p.toString());
        this.discDupes = d.conflicts || [];
      } catch (e) { this.discDupes = []; }
    },
    async discSaveEdit(scope) {
      if (this.discDupes.length) return;
      this.discSaving = true;
      this.discEditError = '';
      try {
        const d = await App.api('api_equipment.php', {
          method: 'POST',
          body: {
            action: 'update',
            id: this.discSheet.id,
            long_name_1c: this.discEdit.long_name_1c,
            aliases: this.discEdit.aliases,
            inventory_number: this.discEdit.inventory_number,
            serial_number: this.discEdit.serial_number,
            mac_address: this.discEdit.mac_address,
            rename_scope: scope || undefined
          }
        });
        if (d.need_scope) {
          this.discScopeAsk = true;
          this.discScopeAffected = d.affected || 0;
          this.discSaving = false;
          return;
        }
        App.toast('Техника обновлена', 'success');
        this.closeDiscSheet();
        this.loadDisc();
      } catch (e) { this.discEditError = e.message; }
      this.discSaving = false;
    },
    async discLoadHistory() {
      this.discMode = 'history';
      try {
        const d = await App.api('api_equipment.php?id=' + encodeURIComponent(this.discSheet.id));
        this.discHistory = d.movements || [];
      } catch (e) { App.toast(e.message, 'error'); }
    },

    /* ---------- Пользователи ---------- */
    async loadUsers() {
      this.users.loading = true;
      try {
        const d = await App.api('api_users.php');
        this.users.items = d.items || [];
        this.users.canManage = !!d.can_manage;
        this.users.superEmail = d.super_email || '';
      } catch (e) { App.toast(e.message, 'error'); }
      this.users.loading = false;
    },
    async syncUsers() {
      this.users.syncing = true;
      try {
        const d = await App.api('api_users.php', { method: 'POST', body: { action: 'sync' } });
        App.toast('Синхронизировано с Yandex 360: всего ' + d.total_remote +
                  ', добавлено ' + d.added + ', обновлено ' + d.updated + ', деактивировано ' + d.deactivated, 'success');
        this.loadUsers();
      } catch (e) { App.toast(e.message, 'error'); }
      this.users.syncing = false;
    },
    async updateUser(u, patch) {
      try {
        await App.api('api_users.php', { method: 'POST', body: Object.assign({ id: u.id }, patch) });
        Object.assign(u, patch);
        App.toast('Сохранено', 'success');
      } catch (e) {
        App.toast(e.message, 'error');
        this.loadUsers();
      }
    }
  };
}
</script>
<?php pageBottom(); ?>
