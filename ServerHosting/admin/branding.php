<?php
// Брендинг: редактор внешнего вида трёх приложений
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db);

$app = in_array($appParam = (string) ($_GET['app'] ?? 'client'), Branding::APPS, true) ? $appParam : 'client';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $features = array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['features'] ?? '')))));
    $db->prepare(
        'UPDATE branding_settings SET app_name=?, app_code=?, hero_title=?, hero_subtitle=?,
         logo_icon=?, primary_color=?, primary_text_color=?, support_phone=?, features=?, updated_at=?
         WHERE app = ?'
    )->execute([
        mb_substr((string) ($_POST['app_name'] ?? ''), 0, 60),
        mb_substr((string) ($_POST['app_code'] ?? ''), 0, 60),
        mb_substr((string) ($_POST['hero_title'] ?? ''), 0, 120),
        mb_substr((string) ($_POST['hero_subtitle'] ?? ''), 0, 300),
        mb_substr((string) ($_POST['logo_icon'] ?? 'taxi'), 0, 40),
        mb_substr((string) ($_POST['primary_color'] ?? '#facc15'), 0, 9),
        mb_substr((string) ($_POST['primary_text_color'] ?? '#0a0a0c'), 0, 9),
        trim((string) ($_POST['support_phone'] ?? '')) !== '' ? mb_substr((string) $_POST['support_phone'], 0, 30) : null,
        json_encode(array_slice($features, 0, 5), JSON_UNESCAPED_UNICODE),
        Db::utcNow(),
        $app,
    ]);
    Bus::publish('branding');
    header('Location: branding.php?app=' . urlencode($app) . '&ok=' . urlencode('Брендинг сохранён и применён'));
    exit;
}

$stmt = $db->prepare('SELECT * FROM branding_settings WHERE app = ? LIMIT 1');
$stmt->execute([$app]);
$brand = Branding::toDto($stmt->fetch());

$APP_TITLES = ['client' => 'Клиентское приложение', 'driver' => 'Приложение водителя', 'operator' => 'Диспетчерская'];
$ICONS = ['taxi' => '🚕 Такси', 'car' => '🚗 Авто', 'headset' => '🎧 Гарнитура', 'zap' => '⚡ Молния', 'crown' => '👑 Корона', 'shield' => '🛡 Щит'];

layout_header('Брендинг', 'branding');
?>
<h1>Серверное брендирование</h1>
<p class="mut">Применяется на экран входа и в шапки приложений — рендерится сервером</p>

<nav class="flex" style="margin:16px 0">
  <?php foreach (Branding::APPS as $a): ?>
    <a class="btn <?= $a === $app ? '' : 'ghost' ?>" href="branding.php?app=<?= h($a) ?>"><?= h($APP_TITLES[$a]) ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!empty($_GET['ok'])): ?><div class="flash">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>

<div class="grid q2">
  <form method="post" class="card">
    <h3 style="font-weight:900;font-size:16px;margin-bottom:14px"><?= h($brand['appName']) ?> · внешний вид</h3>

    <label class="mut" style="display:block;font-size:12px;margin-bottom:12px">Название приложения
      <input name="app_name" value="<?= h($brand['appName']) ?>">
    </label>
    <label class="mut" style="display:block;font-size:12px;margin-bottom:12px">Кодовое имя (в шапке/вкладке)
      <input name="app_code" value="<?= h($brand['appCode']) ?>">
    </label>
    <label class="mut" style="display:block;font-size:12px;margin-bottom:12px">Заголовок экрана входа
      <input name="hero_title" value="<?= h($brand['heroTitle']) ?>">
    </label>
    <label class="mut" style="display:block;font-size:12px;margin-bottom:12px">Подзаголовок / описание
      <textarea name="hero_subtitle" rows="3"><?= h($brand['heroSubtitle']) ?></textarea>
    </label>

    <div class="flex" style="gap:14px;flex-wrap:wrap">
      <label class="mut" style="font-size:12px">Логотип<br>
        <select name="logo_icon" style="width:170px;margin-top:4px">
          <?php foreach ($ICONS as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $brand['logoIcon'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="mut" style="font-size:12px">Основной цвет<br>
        <input type="color" name="primary_color" value="<?= h($brand['primaryColor']) ?>"
          style="width:60px;height:40px;padding:3px;border-radius:10px;border:1px solid rgba(255,255,255,.1);background:#0f0f13;margin-top:4px">
      </label>
      <label class="mut" style="font-size:12px">Текст на фоне цвета<br>
        <input type="color" name="primary_text_color" value="<?= h($brand['primaryTextColor']) ?>"
          style="width:60px;height:40px;padding:3px;border-radius:10px;border:1px solid rgba(255,255,255,.1);background:#0f0f13;margin-top:4px">
      </label>
    </div>

    <label class="mut" style="display:block;font-size:12px;margin:14px 0 12px">Телефон поддержки (пусто — скрыть)
      <input name="support_phone" value="<?= h((string) ($brand['supportPhone'] ?? '')) ?>" placeholder="+7 (___) ___-__-__">
    </label>

    <label class="mut" style="display:block;font-size:12px">Преимущества (каждое с новой строки, до 3-х)
      <textarea name="features" rows="4"><?= h(implode("\n", $brand['features'])) ?></textarea>
    </label>

    <button class="btn" type="submit" style="width:100%;margin-top:14px">Сохранить и применить</button>
  </form>

  <!-- Живое превью экрана входа -->
  <div>
    <div class="mut" style="font-size:11px;text-transform:uppercase;letter-spacing:.15em;margin-bottom:10px">Предпросмотр экрана входа</div>
    <div class="card">
      <div class="flex">
        <div class="tile" style="width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;
          background:<?= h($brand['primaryColor']) ?>;color:<?= h($brand['primaryTextColor']) ?>;
          box-shadow:0 8px 26px <?= h($brand['primaryColor']) ?>55">
          <?= h(mb_substr($brand['appName'], 0, 1)) ?>
        </div>
        <div>
          <div style="font-weight:900;font-size:16px"><?= h($brand['appName']) ?></div>
          <div style="font-size:10px;font-weight:800;letter-spacing:.2em;text-transform:uppercase;color:<?= h($brand['primaryColor']) ?>"><?= h($brand['appCode']) ?></div>
        </div>
      </div>
      <h2 style="font-size:22px;font-weight:900;letter-spacing:-.02em;margin-top:16px"><?= h($brand['heroTitle']) ?></h2>
      <p class="mut" style="font-size:13px;margin-top:8px"><?= h($brand['heroSubtitle']) ?></p>
      <div style="margin-top:14px">
        <?php foreach ($brand['features'] as $f): ?>
        <div class="flex" style="gap:8px;padding:8px 10px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;margin-bottom:6px">
          <span style="width:6px;height:6px;border-radius:99px;background:<?= h($brand['primaryColor']) ?>"></span>
          <span style="font-size:12px;font-weight:600"><?= h($f) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn" style="width:100%;margin-top:14px;
        background:<?= h($brand['primaryColor']) ?>;color:<?= h($brand['primaryTextColor']) ?>;
        box-shadow:0 4px 20px <?= h($brand['primaryColor']) ?>40" type="button">Войти</button>
      <?php if (!empty($brand['supportPhone'])): ?>
      <div class="mut" style="font-size:11px;margin-top:12px">Поддержка: <?= h($brand['supportPhone']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php layout_footer();
