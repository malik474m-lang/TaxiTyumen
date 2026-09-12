<?php
// Места и организации: справочник для подсказок пассажиру.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'places');
Places::ensureTables($db);
$service = ServiceSettings::get($db);
$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = (string) ($_POST['cmd'] ?? '');
    try {
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
        if ($cmd === 'import') {
            $radius = max(5, min(60, (int) ($_POST['radius'] ?? 25)));
            $importResult = Places::importFromOsm(
                $db,
                (float) $service['center_latitude'],
                (float) $service['center_longitude'],
                $radius
            );
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
$stmt = $db->prepare("SELECT * FROM places $where ORDER BY usage_count DESC, name ASC LIMIT 300");
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
      существующие записи и не создаёт дубликатов. Занимает до минуты.
    </p>
    <form method="post" style="margin-top:12px" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Импортируем…'">
      <input type="hidden" name="cmd" value="import">
      <label class="mut">Радиус поиска, км
        <input type="number" name="radius" value="25" min="5" max="60">
      </label>
      <button class="btn" style="margin-top:10px">Импортировать организации</button>
    </form>
    <div style="margin-top:14px">
      <?php foreach (Places::CATEGORIES as $key => $meta): ?>
        <span class="chip" style="margin:2px"><?= h($meta[0]) ?>: <?= (int) ($stats['byCategory'][$key] ?? 0) ?></span>
      <?php endforeach; ?>
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
        <td><?= h((string) $p['address']) ?: '<span class="mut">—</span>' ?></td>
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
<?php layout_footer();
