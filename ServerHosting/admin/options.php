<?php
// Опции заказа: названия, цены, доступность. Справочник сразу подтягивается
// пультом оператора и приложением клиента — без обновления приложений.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'options');
$error = '';
Options::ensureTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cmd = (string) ($_POST['cmd'] ?? '');

        if ($cmd === 'save') {
            $code = (string) ($_POST['code'] ?? '');
            $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
            if ($name === '') throw new RuntimeException('Укажите название опции');
            Options::update($db, $code, $name, max(0.0, (float) ($_POST['price'] ?? 0)), !empty($_POST['is_active']));
            header('Location: options.php?ok=' . urlencode('Сохранено: ' . $name));
            exit;
        }

        if ($cmd === 'create') {
            $code = strtolower(trim((string) ($_POST['new_code'] ?? '')));
            if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code)) {
                throw new RuntimeException('Код: латиница, цифры и _, начинается с буквы (напр. child_seat)');
            }
            $name = mb_substr(trim((string) ($_POST['new_name'] ?? '')), 0, 120);
            if ($name === '') throw new RuntimeException('Укажите название опции');
            Options::create($db, $code, $name, max(0.0, (float) ($_POST['new_price'] ?? 0)));
            header('Location: options.php?ok=' . urlencode('Опция добавлена: ' . $name . ' (' . $code . ')'));
            exit;
        }

        if ($cmd === 'delete') {
            $code = (string) ($_POST['code'] ?? '');
            Options::delete($db, $code);
            header('Location: options.php?ok=' . urlencode('Опция удалена: ' . $code));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$options = Options::all($db, false);
$activeCount = count(array_filter($options, fn($o) => $o['isActive']));

layout_header('Опции заказа', 'options');
?>
<?php if (!empty($_GET['ok'])): ?><div class="flash"><?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="flash" style="border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5"><?= h($error) ?></div>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <div class="flex between">
    <h1 style="font-size:20px">Справочник опций</h1>
    <span class="chip <?= $activeCount > 0 ? 'ok' : 'bad' ?>">активно: <?= $activeCount ?> из <?= count($options) ?></span>
  </div>
  <p class="mut" style="margin-top:6px">
    Цены и названия отсюда сразу видны в пульте оператора и приложении клиента (при следующем запуске):
    обе программы загружают справочник с сервера через <code>GET /api/options.php</code>.
    Расчёт стоимости заказа сервер тоже берёт из этой таблицы — нигде в коде цены больше не зашиты.
  </p>

  <table style="margin-top:14px">
    <thead><tr><th style="width:170px">Код</th><th>Название и цена</th><th style="width:210px"></th></tr></thead>
    <tbody>
    <?php foreach ($options as $o): ?>
      <tr>
        <td><code><?= h($o['code']) ?></code></td>
        <td>
          <form method="post" class="flex" style="flex-wrap:nowrap;gap:8px">
            <input type="hidden" name="cmd" value="save">
            <input type="hidden" name="code" value="<?= h($o['code']) ?>">
            <input name="name" value="<?= h($o['name']) ?>" style="flex:1">
            <input type="number" step="1" min="0" name="price" value="<?= h((string) round($o['price'])) ?>" style="width:110px;text-align:right" title="Цена, ₽">
            <label class="flex" style="gap:5px;white-space:nowrap">
              <input type="checkbox" name="is_active" value="1" <?= $o['isActive'] ? 'checked' : '' ?> style="width:auto"> вкл
            </label>
            <button class="btn sm">Сохранить</button>
          </form>
        </td>
        <td style="text-align:right">
          <form method="post" class="inline" onsubmit="return confirm('Удалить опцию из справочника? История заказов сохранит её снимки.')">
            <input type="hidden" name="cmd" value="delete">
            <input type="hidden" name="code" value="<?= h($o['code']) ?>">
            <button class="btn danger sm">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$options): ?>
      <tr><td colspan="3" class="mut" style="text-align:center;padding:22px">Справочник пуст.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-top:16px;max-width:560px">
  <h1 style="font-size:18px">Новая опция</h1>
  <p class="mut" style="margin-top:4px">Появится в пульте и приложении автоматически при следующем запуске.</p>
  <form method="post" class="grid" style="margin-top:10px">
    <input type="hidden" name="cmd" value="create">
    <div>
      <div class="mut">Код (латиница, напр. wifi)</div>
      <input name="new_code" placeholder="wifi" required>
    </div>
    <div>
      <div class="mut">Название для клиента</div>
      <input name="new_name" placeholder="Wi-Fi в салоне" required>
    </div>
    <div>
      <div class="mut">Цена, ₽ (0 — бесплатно)</div>
      <input type="number" step="1" min="0" name="new_price" value="0">
    </div>
    <button class="btn">Добавить опцию</button>
  </form>
</div>

<?php layout_footer(); ?>
