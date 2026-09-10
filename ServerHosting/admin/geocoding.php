<?php
// Геокодинг: включение/выключение провайдеров, порядок опроса и основной.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'geocoding');
$error = '';
GeoProviders::ensureTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cmd = (string) ($_POST['cmd'] ?? '');
        $provider = (string) ($_POST['provider'] ?? '');
        $label = GeoProviders::REGISTRY[$provider]['label'] ?? $provider;

        if ($cmd === 'toggle') {
            $enabled = !empty($_POST['enabled']);
            GeoProviders::setEnabled($db, $provider, $enabled, (string) $admin['id']);
            header('Location: geocoding.php?ok=' . urlencode(
                $label . ($enabled ? ' — включён' : ' — выключен')));
            exit;
        }

        if ($cmd === 'primary') {
            GeoProviders::setPrimary($db, $provider, (string) $admin['id']);
            header('Location: geocoding.php?ok=' . urlencode('Основной провайдер: ' . $label));
            exit;
        }

        if ($cmd === 'move') {
            GeoProviders::move($db, $provider, (int) ($_POST['dir'] ?? 1), (string) $admin['id']);
            header('Location: geocoding.php?ok=' . urlencode('Порядок опроса изменён'));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Живая проверка выбранной конфигурации
$probe = null;
$testQuery = trim((string) ($_GET['q'] ?? ''));
if ($testQuery !== '') {
    $probe = GeocodingService::search($db, $testQuery);
}

$providers = GeoProviders::all($db);
$active = GeoProviders::active($db);
$primary = GeoProviders::primary($db);

layout_header('Геокодинг', 'geocoding');
?>
<?php if (!empty($_GET['ok'])): ?><div class="flash"><?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="flash" style="border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5"><?= h($error) ?></div>
<?php endif; ?>

<div class="flex between" style="margin-top:18px">
  <div>
    <h1>Провайдеры геокодинга</h1>
    <p class="mut" style="margin-top:4px;max-width:820px">
      Провайдеры опрашиваются сверху вниз: первый активный — основной.
      Если он не дал результата или подсказок мало, подключается следующий.
      Настройки действуют на подсказки адресов в пульте, приложениях
      и на определение координат заказа.
    </p>
  </div>
  <span class="chip <?= $active ? 'ok' : 'bad' ?>">
    активно: <?= count($active) ?> из <?= count($providers) ?>
  </span>
</div>

<div class="card" style="margin-top:14px">
  <table>
    <thead><tr>
      <th style="width:60px">Приоритет</th><th>Провайдер</th>
      <th style="width:150px">Ключ</th><th style="width:120px">Состояние</th>
      <th style="width:230px">Управление</th>
    </tr></thead>
    <tbody>
    <?php $position = 0; foreach ($providers as $name => $p): $position++; ?>
      <tr>
        <td>
          <b><?= $position ?></b>
          <?php if ($name === $primary): ?>
            <div><span class="chip ok" style="margin-top:4px">основной</span></div>
          <?php endif; ?>
        </td>
        <td>
          <b><?= h($p['label']) ?></b>
          <div class="mut" style="font-size:12px;margin-top:2px"><?= h($p['hint']) ?></div>
        </td>
        <td>
          <?php if ($p['keyName'] === null): ?>
            <span class="chip ok">не требуется</span>
          <?php elseif ($p['hasKey']): ?>
            <span class="chip ok">задан</span>
          <?php else: ?>
            <span class="chip bad">нет ключа</span>
            <div class="mut" style="font-size:11px"><a href="api-keys.php">добавить →</a></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!$p['enabled']): ?>
            <span class="chip bad">выключен</span>
          <?php elseif (!$p['hasKey']): ?>
            <span class="chip warn">ждёт ключ</span>
          <?php else: ?>
            <span class="chip ok">работает</span>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <form method="post" class="inline">
            <input type="hidden" name="cmd" value="toggle">
            <input type="hidden" name="provider" value="<?= h($name) ?>">
            <input type="hidden" name="enabled" value="<?= $p['enabled'] ? '' : '1' ?>">
            <button class="btn <?= $p['enabled'] ? 'danger' : '' ?> sm">
              <?= $p['enabled'] ? 'Выключить' : 'Включить' ?>
            </button>
          </form>
          <?php if ($p['enabled'] && $p['hasKey'] && $name !== $primary): ?>
            <form method="post" class="inline">
              <input type="hidden" name="cmd" value="primary">
              <input type="hidden" name="provider" value="<?= h($name) ?>">
              <button class="btn ghost sm">Сделать основным</button>
            </form>
          <?php endif; ?>
          <form method="post" class="inline">
            <input type="hidden" name="cmd" value="move">
            <input type="hidden" name="provider" value="<?= h($name) ?>">
            <input type="hidden" name="dir" value="-1">
            <button class="btn ghost sm" <?= $position === 1 ? 'disabled' : '' ?>>↑</button>
          </form>
          <form method="post" class="inline">
            <input type="hidden" name="cmd" value="move">
            <input type="hidden" name="provider" value="<?= h($name) ?>">
            <input type="hidden" name="dir" value="1">
            <button class="btn ghost sm" <?= $position === count($providers) ? 'disabled' : '' ?>>↓</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!$active): ?>
    <div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">
      Все провайдеры выключены — подсказки адресов и определение координат работать не будут.
    </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:16px">
  <h3 style="font-size:16px">Проверка подсказок</h3>
  <p class="mut" style="margin-top:4px">Введите адрес и убедитесь, что настройки дают нужный результат.</p>
  <form method="get" class="flex" style="margin-top:10px;flex-wrap:nowrap;gap:8px">
    <input name="q" value="<?= h($testQuery) ?>" placeholder="Например: Республики 52" style="flex:1">
    <button class="btn">Проверить</button>
  </form>

  <?php if ($probe !== null): ?>
    <?php if (!$probe): ?>
      <div class="mut" style="margin-top:12px">Ничего не найдено. Проверьте активные провайдеры и ключи.</div>
    <?php else: ?>
      <table style="margin-top:12px">
        <thead><tr><th>Адрес</th><th style="width:180px">Координаты</th><th style="width:120px">Источник</th></tr></thead>
        <tbody>
        <?php foreach ($probe as $item): ?>
          <tr>
            <td><?= h((string) $item['displayName']) ?></td>
            <td class="mut"><?= number_format((float) $item['latitude'], 5) ?>, <?= number_format((float) $item['longitude'], 5) ?></td>
            <td><span class="chip info"><?= h((string) $item['source']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php layout_footer(); ?>
