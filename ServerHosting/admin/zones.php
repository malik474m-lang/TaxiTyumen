<?php
// Зоны и фиксированные цены: карта для рисования полигонов + матрица тарифов.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'zones');
$error = '';
Zones::ensureTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cmd = (string) ($_POST['cmd'] ?? '');

        if ($cmd === 'settings') {
            Zones::updateSettings($db, [
                'enabled' => !empty($_POST['enabled']),
                'applyMultipliers' => !empty($_POST['apply_multipliers']),
                'addOptions' => !empty($_POST['add_options']),
                'fallbackToTariff' => !empty($_POST['fallback_to_tariff']),
            ]);
            Bus::publish('zones');
            header('Location: zones.php?ok=' . urlencode('Настройки зональной тарификации сохранены'));
            exit;
        }

        if ($cmd === 'save_zone') {
            $points = Zones::normalizePolygon($_POST['points'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Укажите название зоны');
            $id = trim((string) ($_POST['id'] ?? ''));
            $polygon = json_encode($points, JSON_UNESCAPED_SLASHES);
            if ($id === '') {
                $db->prepare('INSERT INTO zones (id,name,color,polygon,priority,is_active) VALUES (?,?,?,?,?,?)')
                    ->execute([Db::uuid(), mb_substr($name, 0, 80), (string) ($_POST['color'] ?? '#38bdf8'),
                        $polygon, (int) ($_POST['priority'] ?? 0), !empty($_POST['is_active']) ? 1 : 0]);
            } else {
                $db->prepare('UPDATE zones SET name=?,color=?,polygon=?,priority=?,is_active=?,updated_at=? WHERE id=?')
                    ->execute([mb_substr($name, 0, 80), (string) ($_POST['color'] ?? '#38bdf8'), $polygon,
                        (int) ($_POST['priority'] ?? 0), !empty($_POST['is_active']) ? 1 : 0, Db::utcNow(), $id]);
            }
            Bus::publish('zones');
            header('Location: zones.php?ok=' . urlencode('Зона сохранена: ' . $name));
            exit;
        }

        if ($cmd === 'delete_zone') {
            Zones::deleteZone($db, (string) ($_POST['id'] ?? ''));
            Bus::publish('zones');
            header('Location: zones.php?ok=' . urlencode('Зона удалена'));
            exit;
        }

        if ($cmd === 'prices') {
            $saved = 0;
            foreach ((array) ($_POST['price'] ?? []) as $fromId => $toMap) {
                foreach ((array) $toMap as $toId => $tariffMap) {
                    foreach ((array) $tariffMap as $tariff => $value) {
                        $value = trim((string) $value);
                        Zones::setPrice($db, (string) $fromId, (string) $toId,
                            Taxi::normalizeTariff($tariff), $value === '' ? null : (float) $value);
                        if ($value !== '') $saved++;
                    }
                }
            }
            Bus::publish('zones');
            header('Location: zones.php?ok=' . urlencode("Матрица цен сохранена ($saved маршрутов)"));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$settings = Zones::settings($db);
$zones = $db->query('SELECT * FROM zones ORDER BY priority DESC, name')->fetchAll();
foreach ($zones as &$z) { $z['points'] = Zones::decodePolygon((string) $z['polygon']); }
unset($z);
$matrix = Zones::priceMatrix($db);
$tariffs = $db->query('SELECT type, name FROM tariffs WHERE is_active=1 ORDER BY base_fare')->fetchAll();
$service = ServiceSettings::get($db);
$editId = (string) ($_GET['edit'] ?? '');
$editZone = null;
foreach ($zones as $z) { if ($z['id'] === $editId) { $editZone = $z; break; } }

layout_header('Зоны и цены', 'zones');
?>
<?php if (YANDEX_MAPS_API_KEY !== ''): ?>
<script src="https://api-maps.yandex.ru/2.1/?apikey=<?= rawurlencode(YANDEX_MAPS_API_KEY) ?>&lang=ru_RU&coordorder=latlong"></script>
<?php endif; ?>

<div class="flex between">
  <div><h1>Зоны и фиксированные цены</h1><p class="mut">Фиксированная стоимость поездки между зонами вместо расчёта по километрам</p></div>
  <span class="chip <?= $settings['enabled'] ? 'ok' : 'warn' ?>"><?= $settings['enabled'] ? 'Зональные цены включены' : 'Выключены' ?></span>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?= h($error) ?></div><?php endif; ?>

<form method="post" class="card" style="margin-top:18px">
  <input type="hidden" name="cmd" value="settings">
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
    <label class="flex between" style="padding:11px;background:#0f0f13;border-radius:11px">
      <span><b>Включить зональные цены</b><div class="mut">Приоритет над расчётом по км</div></span>
      <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#facc15">
    </label>
    <label class="flex between" style="padding:11px;background:#0f0f13;border-radius:11px">
      <span>Ночь/час пик к фикс. цене<div class="mut">Умножать на коэффициент тарифа</div></span>
      <input type="checkbox" name="apply_multipliers" value="1" <?= $settings['apply_multipliers'] ? 'checked' : '' ?> style="width:18px;height:18px">
    </label>
    <label class="flex between" style="padding:11px;background:#0f0f13;border-radius:11px">
      <span>Добавлять опции<div class="mut">Кресло, животное и т.д.</div></span>
      <input type="checkbox" name="add_options" value="1" <?= $settings['add_options'] ? 'checked' : '' ?> style="width:18px;height:18px">
    </label>
    <label class="flex between" style="padding:11px;background:#0f0f13;border-radius:11px">
      <span>Если зона не найдена<div class="mut">Считать по обычному тарифу</div></span>
      <input type="checkbox" name="fallback_to_tariff" value="1" <?= $settings['fallback_to_tariff'] ? 'checked' : '' ?> style="width:18px;height:18px">
    </label>
  </div>
  <button class="btn" style="margin-top:12px">Сохранить настройки</button>
</form>

<div class="grid q2" style="margin-top:14px">
  <div class="card">
    <h3 style="margin-bottom:10px"><?= $editZone ? 'Редактирование зоны' : 'Новая зона' ?></h3>
    <p class="mut" style="margin-bottom:10px">Кликайте по карте, чтобы поставить точки границы. Минимум 3 точки.</p>
    <div id="map" style="height:340px;border-radius:12px;overflow:hidden;background:#0f0f13">
      <?php if (YANDEX_MAPS_API_KEY === ''): ?>
      <div style="height:100%;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px">
        <div><div style="font-size:32px">🗺️</div><b>API-ключ Яндекс Карт не настроен</b>
        <div class="mut" style="margin-top:7px;max-width:360px">Добавьте <code>YANDEX_MAPS_API_KEY</code> в <code>config.local.php</code> и ограничьте ключ доменом <?= h($_SERVER['HTTP_HOST'] ?? '') ?>.</div></div>
      </div>
      <?php endif; ?>
    </div>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="cmd" value="save_zone">
      <input type="hidden" name="id" value="<?= h($editZone['id'] ?? '') ?>">
      <input type="hidden" name="points" id="pointsField" value="<?= h(json_encode($editZone['points'] ?? [], JSON_UNESCAPED_SLASHES)) ?>">
      <div class="grid" style="grid-template-columns:2fr 1fr 1fr">
        <label class="mut">Название<input name="name" value="<?= h($editZone['name'] ?? '') ?>" required></label>
        <label class="mut">Цвет<input type="color" name="color" value="<?= h($editZone['color'] ?? '#38bdf8') ?>" style="height:42px;padding:3px"></label>
        <label class="mut">Приоритет<input type="number" name="priority" value="<?= (int) ($editZone['priority'] ?? 0) ?>"></label>
      </div>
      <div class="flex" style="margin-top:10px">
        <label><input type="checkbox" name="is_active" value="1" <?= ($editZone['is_active'] ?? 1) ? 'checked' : '' ?> style="width:auto"> Активна</label>
        <span class="mut" id="pointCount" style="font-size:12px"></span>
        <button type="button" class="btn ghost sm" onclick="clearPoints()">Очистить точки</button>
        <button class="btn sm" style="margin-left:auto">Сохранить зону</button>
        <?php if ($editZone): ?><a class="btn ghost sm" href="zones.php">Отмена</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card" style="overflow-x:auto">
    <h3 style="margin-bottom:10px">Зоны (<?= count($zones) ?>)</h3>
    <table><thead><tr><th>Зона</th><th>Точек</th><th>Приоритет</th><th>Статус</th><th></th></tr></thead><tbody>
    <?php foreach ($zones as $z): ?>
      <tr>
        <td><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?= h($z['color']) ?>;margin-right:6px"></span><b><?= h($z['name']) ?></b></td>
        <td><?= count($z['points']) ?></td>
        <td><?= (int) $z['priority'] ?></td>
        <td><?= $z['is_active'] ? '<span class="chip ok">активна</span>' : '<span class="chip warn">выключена</span>' ?></td>
        <td>
          <div class="flex">
            <a class="btn ghost sm" href="zones.php?edit=<?= h($z['id']) ?>">Изменить</a>
            <form method="post" onsubmit="return confirm('Удалить зону и её цены?')"><input type="hidden" name="cmd" value="delete_zone"><input type="hidden" name="id" value="<?= h($z['id']) ?>"><button class="btn danger sm">Удалить</button></form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$zones): ?><tr><td colspan="5" class="mut" style="text-align:center;padding:30px">Зон пока нет — нарисуйте первую на карте</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>

<?php if (count($zones) >= 1): ?>
<form method="post" class="card" style="margin-top:14px;overflow-x:auto">
  <input type="hidden" name="cmd" value="prices">
  <div class="flex between" style="margin-bottom:10px">
    <h3>Матрица фиксированных цен</h3>
    <span class="mut">Пусто = считать по обычному тарифу</span>
  </div>
  <table>
    <thead><tr><th>Откуда → Куда</th><?php foreach ($tariffs as $t): ?><th><?= h($t['name']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($zones as $from): foreach ($zones as $to): ?>
      <tr>
        <td>
          <span style="color:<?= h($from['color']) ?>">■</span> <?= h($from['name']) ?>
          <span class="mut">→</span>
          <span style="color:<?= h($to['color']) ?>">■</span> <?= h($to['name']) ?>
          <?php if ($from['id'] === $to['id']): ?><div class="mut" style="font-size:11px">внутри зоны</div><?php endif; ?>
        </td>
        <?php foreach ($tariffs as $t):
          $value = $matrix[$from['id']][$to['id']][$t['type']]['price'] ?? ''; ?>
        <td><input type="number" step="1" min="0" style="width:100px"
              name="price[<?= h($from['id']) ?>][<?= h($to['id']) ?>][<?= h($t['type']) ?>]"
              value="<?= $value === '' ? '' : (int) round((float) $value) ?>" placeholder="—"></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>
  <button class="btn" style="margin-top:12px">Сохранить матрицу цен</button>
</form>
<?php endif; ?>

<?php if (YANDEX_MAPS_API_KEY !== ''): ?>
<script>
var center = [<?= (float) $service['center_latitude'] ?>, <?= (float) $service['center_longitude'] ?>];
var existing = <?= json_encode(array_map(fn($z) => ['name'=>$z['name'],'color'=>$z['color'],'points'=>$z['points'],'id'=>$z['id']], $zones), JSON_UNESCAPED_UNICODE) ?>;
var editId = <?= json_encode($editZone['id'] ?? null) ?>;
var points = <?= json_encode($editZone['points'] ?? [], JSON_UNESCAPED_SLASHES) ?>;
var map, draft;
var pointMarks = [];

ymaps.ready(function () {
  map = new ymaps.Map('map', {
    center: center,
    zoom: 11,
    type: 'yandex#map',
    controls: ['zoomControl', 'typeSelector', 'searchControl', 'geolocationControl']
  }, {
    suppressMapOpenBlock: true,
    yandexMapDisablePoiInteractivity: true
  });

  existing.forEach(function (z) {
    if (z.id === editId || !z.points.length) return;
    var polygon = new ymaps.Polygon([z.points], {
      hintContent: z.name,
      balloonContent: '<strong>' + escapeHtml(z.name) + '</strong>'
    }, {
      strokeColor: z.color,
      fillColor: z.color + '33',
      strokeWidth: 2,
      fillOpacity: 0.22
    });
    map.geoObjects.add(polygon);
  });

  draft = new ymaps.Polygon([points], {
    hintContent: 'Редактируемая зона'
  }, {
    strokeColor: '#facc15',
    fillColor: '#facc1544',
    strokeWidth: 3,
    fillOpacity: 0.28
  });
  map.geoObjects.add(draft);

  map.events.add('click', function (event) {
    var coords = event.get('coords');
    points.push([+coords[0].toFixed(6), +coords[1].toFixed(6)]);
    redraw();
  });

  redraw();
});

function redraw() {
  if (!map || !draft) return;
  draft.geometry.setCoordinates([points]);
  pointMarks.forEach(function (mark) { map.geoObjects.remove(mark); });
  pointMarks = points.map(function (point, index) {
    var mark = new ymaps.Placemark(point, {
      iconContent: String(index + 1),
      hintContent: 'Точка ' + (index + 1)
    }, {
      preset: 'islands#yellowStretchyIcon',
      draggable: true
    });
    mark.events.add('dragend', function () {
      var coords = mark.geometry.getCoordinates();
      points[index] = [+coords[0].toFixed(6), +coords[1].toFixed(6)];
      document.getElementById('pointsField').value = JSON.stringify(points);
    });
    map.geoObjects.add(mark);
    return mark;
  });
  document.getElementById('pointsField').value = JSON.stringify(points);
  document.getElementById('pointCount').textContent = 'Точек: ' + points.length;
  var bounds = draft.geometry.getBounds();
  if (bounds && points.length >= 2) {
    map.setBounds(bounds, {checkZoomRange: true, zoomMargin: 40});
  }
}

function clearPoints() {
  points = [];
  redraw();
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, function (char) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
  });
}
</script>
<?php endif; ?>

<?php layout_footer();
