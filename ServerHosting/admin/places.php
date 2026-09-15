<?php
// Места и организации: справочник для подсказок пассажиру.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'places');
Places::ensureTables($db);
$service = ServiceSettings::get($db);
$importResult = null;
$startAutoFill = !empty($_GET['autofill']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = (string) ($_POST['cmd'] ?? '');
    try {
        // Короткий AJAX-пакет: 2 адреса ≈ 7 секунд, shared-хостинг не оборвёт.
        if ($cmd === 'fill-address-batch') {
            $batch = Places::fillAddressBatch($db, 2);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($cmd === 'retry-addresses') {
            $count = Places::retryFailedAddresses($db);
            header('Location: places.php?autofill=1&ok=' . urlencode("Повторная проверка: $count мест"));
            exit;
        }
        if ($cmd === 'save') {
            Places::save($db, $_POST);
            header('Location: places.php?ok=' . urlencode('Место сохранено'));
            exit;
        }
        if ($cmd === 'delete') {
            Places::delete($db, (string) $_POST['id']);
            header('Location: places.php?ok=' . urlencode('Место удалено'));
            exit;
        }
        // ── Пакетный импорт через AJAX: одна категория за HTTP-запрос ──────
        // 14 категорий × ~10 сек = 140 сек суммарно, но каждый запрос
        // длится 10-15 сек и НЕ превышает LSAPI timeout 300 на jino.ru.
        if ($cmd === 'import-batch') {
            $categoryIndex = max(0, (int) ($_POST['category_index'] ?? 0));
            $radius = max(5, min(60, (int) ($_POST['radius'] ?? 25)));
            $batch = Places::importCategoryBatch(
                $db,
                (float) $service['center_latitude'],
                (float) $service['center_longitude'],
                $radius,
                $categoryIndex
            );
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($cmd === 'import') {
            // Пакетный режим: JS вызывает import-batch в цикле
            $importResult = ['imported' => 0, 'updated' => 0, 'skipped' => 0,
                'error' => null, 'batchMode' => true];
        }
        if ($cmd === 'import-region') {
            // Тоже пакетный режим (bbox передаётся в JS)
            $importResult = ['imported' => 0, 'updated' => 0, 'skipped' => 0,
                'error' => null, 'batchMode' => true];
        }
        if ($cmd === 'import-csv' && !empty($_FILES['csvfile']['tmp_name'])) {
            $content = (string) file_get_contents($_FILES['csvfile']['tmp_name']);
            // Поддерживаем UTF-8 BOM от Excel
            if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
            $csvResult = Places::importFromCsv($db, $content);
            header('Location: places.php?autofill=1&ok=' . urlencode(sprintf(
                'CSV загружен: добавлено %d, пропущено %d',
                $csvResult['imported'], $csvResult['skipped']
            ) . (!empty($csvResult['error']) ? ' · ' . $csvResult['error'] : '')));
            exit;
        }
        if ($cmd === 'fill-addresses') {
            // Совместимость со старой кнопкой/закладкой
            header('Location: places.php?autofill=1');
            exit;
        }
    } catch (Throwable $e) {
        header('Location: places.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$category = (string) ($_GET['category'] ?? '');
$where = 'WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (search_name LIKE ? OR aliases LIKE ? OR address LIKE ?)';
    $needle = '%' . Places::normalize($q) . '%';
    $params[] = $needle; $params[] = $needle; $params[] = '%' . $q . '%';
}
if (isset(Places::CATEGORIES[$category])) {
    $where .= ' AND category = ?';
    $params[] = $category;
}
$stmt = $db->prepare("SELECT * FROM places $where ORDER BY (address IS NULL OR address = '') DESC, usage_count DESC, name ASC LIMIT 300");
$stmt->execute($params);
$places = $stmt->fetchAll();
$stats = Places::stats($db);

$edit = null;
if (!empty($_GET['edit'])) {
    $e = $db->prepare('SELECT * FROM places WHERE id=? LIMIT 1');
    $e->execute([(string) $_GET['edit']]);
    $edit = $e->fetch() ?: null;
}

layout_header('Места и организации', 'places');
?>
<div class="flex between">
  <div>
    <h1>Места и организации</h1>
    <p class="mut">Пассажир ищет «Гудвин» или «Киномакс» — система подставляет точный адрес и координаты</p>
  </div>
  <span class="chip ok"><?= (int) $stats['total'] ?> активных</span>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="flash" style="margin-top:14px;color:#fca5a5">Ошибка: <?= h((string) $_GET['error']) ?></div>
<?php endif; ?>
<?php if ($importResult !== null): ?>
  <div class="flash" style="margin-top:14px">
    <?php if ($importResult['error']): ?>
      <span style="color:#fca5a5">Импорт: <?= h((string) $importResult['error']) ?></span>
    <?php else: ?>
      ✓ Импорт из OpenStreetMap: добавлено <?= (int) $importResult['imported'] ?>,
      обновлено <?= (int) $importResult['updated'] ?>, пропущено <?= (int) $importResult['skipped'] ?>
      <?php if (!empty($importResult['addressesFilled'])): ?>
        <br>✓ Адреса определены автоматически: <?= (int) $importResult['addressesFilled'] ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="grid2" style="margin-top:18px">
  <div class="card">
    <h3>Импорт из OpenStreetMap</h3>
    <p class="mut" style="font-size:12px;margin-top:6px">
      Бесплатно и без ключей (лицензия ODbL). Загружает ТРЦ, кинотеатры, вокзалы,
      больницы, кафе, гостиницы, банки и другие организации вокруг
      <b><?= h((string) $service['city_name']) ?></b>. Повторный импорт обновляет
      существующие записи и не создаёт дубликатов. После импорта адреса заполняются в фоне — не закрывайте страницу.
    </p>
    <button type="button" id="importBtn" class="btn" style="width:100%" onclick="startImport()">
      Импортировать организации
    </button>
    <div id="importProgress" style="display:none;margin-top:10px;padding:12px;
         background:#18181d;border:1px solid var(--line);border-radius:10px">
      <div class="flex between">
        <b id="importCategory">Запуск…</b>
        <span id="importPercent" class="chip info">0%</span>
      </div>
      <div style="height:8px;background:#2a2a32;border-radius:999px;margin-top:8px;overflow:hidden">
        <div id="importBar" style="height:100%;width:0;background:#6366f1;transition:.3s"></div>
      </div>
      <div id="importStats" class="mut" style="font-size:11px;margin-top:6px">
        Загружаю категории из OpenStreetMap…
      </div>
    </div>
    <label class="mut" style="margin-top:10px">Радиус поиска, км
      <input type="number" id="importRadius" value="25" min="5" max="60" style="width:80px">
    </label>

    <?php
    $noAddress = Places::countWithoutAddress($db);
    $pendingAddresses = Places::countPendingAddresses($db);
    $failedAddresses = Places::countFailedAddresses($db);
    if ($noAddress > 0): ?>
    <div style="margin-top:10px">
      <?php if ($pendingAddresses > 0): ?>
        <button type="button" id="fillAddressBtn" class="btn ghost" style="width:100%"
                onclick="startAddressFill()">
          Определить адреса автоматически (<?= $pendingAddresses ?> в очереди)
        </button>
      <?php endif; ?>

      <div id="addressProgress" style="display:none;margin-top:10px;padding:12px;
           background:#18181d;border:1px solid var(--line);border-radius:10px">
        <div class="flex between">
          <b id="addressProgressTitle">Заполняем адреса…</b>
          <span id="addressProgressPercent" class="chip info">0%</span>
        </div>
        <div style="height:8px;background:#2a2a32;border-radius:999px;margin-top:8px;overflow:hidden">
          <div id="addressProgressBar" style="height:100%;width:0;background:#4ade80;transition:.2s"></div>
        </div>
        <div id="addressProgressText" class="mut" style="font-size:11px;margin-top:7px">
          Не закрывайте эту страницу. Обработка идёт короткими пакетами и не зависнет по таймауту.
        </div>
      </div>

      <?php if ($failedAddresses > 0): ?>
        <form method="post" style="margin-top:8px">
          <input type="hidden" name="cmd" value="retry-addresses">
          <button class="btn sm ghost" style="width:100%">
            Повторить нераспознанные адреса (<?= $failedAddresses ?>)
          </button>
        </form>
      <?php endif; ?>

      <p class="mut" style="font-size:11px;margin-top:6px">
        Каждый запрос обрабатывает 2 организации (около 7 секунд), затем
        автоматически запускается следующий. <?= $failedAddresses > 0
          ? 'Нераспознанные точки не зацикливаются — их можно повторить отдельной кнопкой.'
          : '' ?>
      </p>
    </div>
    <?php endif; ?>
    <div style="margin-top:14px">
      <?php foreach (Places::CATEGORIES as $key => $meta): ?>
        <span class="chip" style="margin:2px"><?= h($meta[0]) ?>: <?= (int) ($stats['byCategory'][$key] ?? 0) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h3>Загрузить из CSV-файла</h3>
    <p class="mut" style="font-size:12px;margin-top:6px">
      Формат: <code>название;адрес;широта;долгота;категория</code>.
      Разделитель — точка с запятой или запятая. Первая строка (заголовок) пропускается.
    </p>
    <form method="post" enctype="multipart/form-data" style="margin-top:10px">
      <input type="hidden" name="cmd" value="import-csv">
      <input type="file" name="csvfile" accept=".csv,.txt" required
             style="background:#18181d;border:1px solid var(--line);color:#f4f4f5;
                    border-radius:9px;padding:8px;width:100%">
      <button class="btn" style="margin-top:10px">Загрузить CSV</button>
    </form>
    <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px">
      <p class="mut" style="font-size:12px"><b>Дополнительно:</b></p>
      <form method="post" style="margin-top:8px"
            onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Импортируем всю область…'">
        <input type="hidden" name="cmd" value="import-region">
        <button class="btn ghost" style="width:100%">
          Импорт по всей области (Тюмень + Тюменский район)
        </button>
        <p class="mut" style="font-size:11px;margin-top:6px">
          Загружает ВСЕ организации из OSM по прямоугольной области,
          покрывающей весь Тюменский район. Импорт может занять несколько минут, затем автоматически запустится заполнение адресов.
        </p>
      </form>
      <p class="mut" style="font-size:11px;margin-top:14px">
        <b>Другие источники:</b><br>
        1. <a href="https://overpass-turbo.eu/" target="_blank" rel="noopener">overpass-turbo.eu</a> —
        интерактивная карта: выберите область, вставьте запрос ниже, нажмите «Выполнить»,
        затем «Экспорт» → «Скачать как GeoJSON» или «CSV».
      </p>
      <details style="margin-top:8px">
        <summary class="mut" style="font-size:11px;cursor:pointer">Показать запрос для Overpass Turbo</summary>
        <pre style="background:#18181d;border-radius:8px;padding:10px;margin-top:6px;
font-size:10px;overflow-x:auto;white-space:pre-wrap;color:#c4b5fd">[out:json][timeout:120];
(
  nwr["shop"](56.85,64.80,57.45,66.30)["name"];
  nwr["amenity"](56.85,64.80,57.45,66.30)["name"];
  nwr["tourism"](56.85,64.80,57.45,66.30)["name"];
  nwr["leisure"](56.85,64.80,57.45,66.30)["name"];
  nwr["office"](56.85,64.80,57.45,66.30)["name"];
);
out center tags;</pre>
      </details>
      <p class="mut" style="font-size:11px;margin-top:10px">
        2. <a href="https://download.geofabrik.de/russia/ural-fed-district.html" target="_blank" rel="noopener">Geofabrik — УФО (.osm.pbf)</a> —
        полный снимок OSM. Нужен инструмент для обработки (osmium, QGIS).
      </p>
    </div>
  </div>

  <form method="post" class="card">
    <input type="hidden" name="cmd" value="save">
    <input type="hidden" name="id" value="<?= h((string) ($edit['id'] ?? '')) ?>">
    <h3><?= $edit ? 'Изменить место' : 'Добавить место вручную' ?></h3>
    <label class="mut">Название<input name="name" required value="<?= h((string) ($edit['name'] ?? '')) ?>" placeholder="ТРЦ Гудвин"></label>
    <label class="mut">Другие названия через запятую
      <input name="aliases" value="<?= h((string) ($edit['aliases'] ?? '')) ?>" placeholder="гудвин, goodwin">
    </label>
    <label class="mut">Адрес<input name="address" value="<?= h((string) ($edit['address'] ?? '')) ?>" placeholder="ул. Максима Горького, 70"></label>
    <label class="mut">Категория
      <select name="category">
        <?php foreach (Places::CATEGORIES as $key => $meta): ?>
          <option value="<?= h($key) ?>" <?= ($edit['category'] ?? '') === $key ? 'selected' : '' ?>><?= h($meta[0]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="flex" style="gap:10px">
      <label class="mut" style="flex:1">Широта<input name="latitude" required value="<?= h((string) ($edit['latitude'] ?? '')) ?>" placeholder="57.1378"></label>
      <label class="mut" style="flex:1">Долгота<input name="longitude" required value="<?= h((string) ($edit['longitude'] ?? '')) ?>" placeholder="65.5825"></label>
    </div>
    <label class="mut" style="display:flex;gap:8px;margin:10px 0">
      <input type="checkbox" name="is_active" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?>> Показывать в подсказках
    </label>
    <button class="btn"><?= $edit ? 'Сохранить' : 'Добавить' ?></button>
    <?php if ($edit): ?><a class="btn ghost" href="places.php">Отмена</a><?php endif; ?>
  </form>
</div>

<div class="card" style="margin-top:18px;overflow-x:auto">
  <div class="flex between">
    <h3>Справочник</h3>
    <form method="get" class="flex" style="gap:8px">
      <input name="q" value="<?= h($q) ?>" placeholder="Поиск по названию">
      <select name="category" onchange="this.form.submit()">
        <option value="">Все категории</option>
        <?php foreach (Places::CATEGORIES as $key => $meta): ?>
          <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn sm">Найти</button>
    </form>
  </div>
  <p class="mut" style="margin:8px 0;font-size:12px">Показано: <?= count($places) ?></p>
  <table>
    <thead><tr><th>Название</th><th>Категория</th><th>Адрес</th><th>Координаты</th><th>Запросов</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($places as $p): ?>
      <tr style="<?= $p['is_active'] ? '' : 'opacity:.5' ?>">
        <td><b><?= h((string) $p['name']) ?></b>
          <?php if (!$p['is_active']): ?><span class="chip bad">скрыто</span><?php endif; ?>
          <div class="mut" style="font-size:11px"><?= h((string) $p['source']) ?></div>
        </td>
        <td><?= h(Places::CATEGORIES[$p['category']][0] ?? (string) $p['category']) ?></td>
        <td>
          <?php if (!empty($p['address'])): ?>
            <?= h((string) $p['address']) ?>
          <?php elseif (($p['address_status'] ?? 'pending') === 'failed'): ?>
            <span class="chip bad" title="<?= h((string) ($p['address_error'] ?? '')) ?>">не распознано</span>
            <div class="mut" style="font-size:10px"><?= h((string) ($p['address_error'] ?? 'Геокодер не нашёл адрес')) ?></div>
          <?php else: ?>
            <span class="chip warn">в очереди</span>
          <?php endif; ?>
        </td>
        <td class="mut" style="font-family:monospace;font-size:11px">
          <?= h(number_format((float) $p['latitude'], 5, '.', '')) ?>,
          <?= h(number_format((float) $p['longitude'], 5, '.', '')) ?>
        </td>
        <td><?= (int) $p['usage_count'] ?></td>
        <td>
          <div class="flex" style="gap:5px">
            <a class="btn sm ghost" href="places.php?edit=<?= h((string) $p['id']) ?>">Изменить</a>
            <form method="post" onsubmit="return confirm('Удалить место?')">
              <input type="hidden" name="cmd" value="delete">
              <input type="hidden" name="id" value="<?= h((string) $p['id']) ?>">
              <button class="btn sm danger">Удалить</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$places): ?>
      <tr><td colspan="6" class="mut" style="text-align:center;padding:30px">
        Мест не найдено. Нажмите «Импортировать организации».
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<script>
// ── Фоновое пакетное заполнение адресов ───────────────────────────────────
var addressFillRunning = false;
var addressTotal = <?= (int) ($pendingAddresses ?? 0) ?>;
var addressProcessed = 0;
var addressFilled = 0;
var addressSkipped = 0;
var addressFailed = 0;
var addressCsrf = <?= json_encode(admin_csrf_token(), JSON_UNESCAPED_SLASHES) ?>;

function updateAddressProgress(remaining){
  var done = Math.max(0, addressTotal - remaining);
  var pct = addressTotal > 0 ? Math.round(done * 100 / addressTotal) : 100;
  var panel = document.getElementById('addressProgress');
  if (!panel) return;
  panel.style.display = 'block';
  document.getElementById('addressProgressBar').style.width = pct + '%';
  document.getElementById('addressProgressPercent').textContent = pct + '%';
  document.getElementById('addressProgressText').textContent =
    'Обработано: ' + addressProcessed
    + ' · адресов вставлено: ' + addressFilled
    + ' · не найдено: ' + addressSkipped
    + ' · ошибок: ' + addressFailed
    + ' · осталось: ' + remaining;
}

async function startAddressFill(){
  if (addressFillRunning) return;
  addressFillRunning = true;
  var btn = document.getElementById('fillAddressBtn');
  if (btn){ btn.disabled = true; btn.textContent = 'Заполняем адреса…'; }
  updateAddressProgress(addressTotal);

  try {
    while (addressFillRunning) {
      var body = new URLSearchParams();
      body.set('_csrf', addressCsrf);
      body.set('cmd', 'fill-address-batch');
      body.set('limit', '2');

      var response = await fetch('places.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString()
      });
      if (!response.ok) throw new Error(await response.text() || ('HTTP ' + response.status));
      var data = await response.json();

      addressProcessed += Number(data.processed || 0);
      addressFilled += Number(data.filled || 0);
      addressSkipped += Number(data.skipped || 0);
      addressFailed += Number(data.failed || 0);
      updateAddressProgress(Number(data.remaining || 0));

      if (data.done) {
        addressFillRunning = false;
        document.getElementById('addressProgressTitle').textContent = 'Заполнение завершено';
        document.getElementById('addressProgressPercent').className = 'chip ok';
        document.getElementById('addressProgressPercent').textContent = 'Готово';
        document.getElementById('addressProgressBar').style.width = '100%';
        document.getElementById('addressProgressText').textContent =
          'Адресов вставлено: ' + addressFilled
          + ' · не удалось определить: ' + Number(data.failedTotal || 0)
          + '. Страница обновится через 2 секунды.';
        setTimeout(function(){
          location.href = 'places.php?ok=' + encodeURIComponent(
            'Адреса заполнены: ' + addressFilled
            + ', нераспознано: ' + Number(data.failedTotal || 0)
          );
        }, 2000);
        break;
      }

      // Небольшая пауза между пакетами, чтобы не перегружать PHP/геокодер
      await new Promise(function(resolve){ setTimeout(resolve, 250); });
    }
  } catch (e) {
    addressFillRunning = false;
    if (btn){ btn.disabled = false; btn.textContent = 'Продолжить заполнение адресов'; }
    document.getElementById('addressProgressTitle').textContent = 'Обработка прервана';
    document.getElementById('addressProgressPercent').className = 'chip bad';
    document.getElementById('addressProgressText').textContent =
      'Ошибка: ' + (e && e.message ? e.message : e)
      + '. Нажмите «Продолжить» — уже заполненные адреса не потеряются.';
  }
}

<?php if ($startAutoFill && ($pendingAddresses ?? 0) > 0): ?>
// После импорта запускать адресное заполнение автоматически
setTimeout(startAddressFill, 400);
<?php endif; ?>
</script>
<script>
var importRunning = false;
var importCsrf = <?= json_encode(admin_csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
var importTotals = {imported: 0, updated: 0, skipped: 0, failed: 0};

async function startImport(){
    if (importRunning) return;
    importRunning = true;
    var btn = document.getElementById('importBtn');
    btn.disabled = true; btn.textContent = 'Импортируем…';
    document.getElementById('importProgress').style.display = 'block';

    var radius = document.getElementById('importRadius').value || 25;
    var index = 0;

    try {
        while (true) {
            var body = new URLSearchParams();
            body.set('_csrf', importCsrf);
            body.set('cmd', 'import-batch');
            body.set('category_index', String(index));
            body.set('radius', String(radius));

            var resp = await fetch('places.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString()
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var data = await resp.json();

            document.getElementById('importCategory').textContent =
                data.category ? 'Категория: ' + data.category : 'Завершение…';
            importTotals.imported += Number(data.imported || 0);
            importTotals.updated += Number(data.updated || 0);
            importTotals.skipped += Number(data.skipped || 0);
            if (data.error) importTotals.failed++;

            var pct = Math.round(((data.categoryIndex + 1) / data.totalCategories) * 100);
            document.getElementById('importBar').style.width = pct + '%';
            document.getElementById('importPercent').textContent = pct + '%';
            document.getElementById('importStats').textContent =
                'Добавлено: ' + importTotals.imported
                + ' · обновлено: ' + importTotals.updated
                + (importTotals.failed > 0 ? ' · ошибок: ' + importTotals.failed : '');

            if (data.done) break;
            index = data.categoryIndex + 1;
            await new Promise(r => setTimeout(r, 300));
        }

        document.getElementById('importCategory').textContent = 'Импорт завершён';
        document.getElementById('importPercent').className = 'chip ok';
        document.getElementById('importPercent').textContent = 'Готово';
        document.getElementById('importBar').style.width = '100%';
        document.getElementById('importStats').textContent =
            'Всего добавлено: ' + importTotals.imported
            + ' · обновлено: ' + importTotals.updated
            + (importTotals.failed > 0 ? ' · ошибок: ' + importTotals.failed : '')
            + '. Страница обновится…';
        setTimeout(function(){ location.reload(); }, 2000);
    } catch (e) {
        document.getElementById('importCategory').textContent = 'Ошибка импорта';
        document.getElementById('importPercent').className = 'chip bad';
        document.getElementById('importStats').textContent = e.message;
        btn.disabled = false; btn.textContent = 'Повторить импорт';
    }
    importRunning = false;
}
</script>

<?php layout_footer();
