<?php
// Настройки автодозвона: эскалация + автоназначение
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $minutes = max(1, min(60, (int) ($_POST['escalate_after_minutes'] ?? 3)));
    $radius = max(1, min(30, (float) ($_POST['auto_assign_radius_km'] ?? 5)));
    $db->prepare(
        'UPDATE auto_call_settings SET enabled=?, escalate_after_minutes=?, auto_assign_enabled=?, auto_assign_radius_km=?'
    )->execute([
        !empty($_POST['enabled']) ? 1 : 0,
        $minutes,
        !empty($_POST['auto_assign_enabled']) ? 1 : 0,
        $radius,
    ]);
    // Сбрасываем last_tick, чтобы тик отработал с новыми параметрами быстрее
    $db->exec('UPDATE auto_call_settings SET last_tick_at = NULL');
    Bus::publish('autocall');
    header('Location: autocall.php?ok=' . urlencode('Настройки сохранены'));
    exit;
}

$s = AutoCall::getSettings($db);
$stuck = $db->prepare(
    "SELECT COUNT(*) FROM orders WHERE (status='searching' OR status='no_driver_found') AND driver_id IS NULL"
);
$stuck->execute([]);
$stuckCount = (int) $stuck->fetchColumn();

layout_header('Автодозвон', 'autocall');
?>
<h1>Автодозвон</h1>
<p class="mut">Порт AutoCallService: застывшие заказы эскалируются операторам либо автоназначаются</p>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>

<div class="grid q2" style="margin-top:18px">
  <form method="post" class="card">
    <label class="flex between" style="padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(255,255,255,.02)">
      <span style="font-weight:700">Сервис включён</span>
      <input type="checkbox" name="enabled" value="1" <?= $s['enabled'] ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#facc15">
    </label>

    <label class="flex between" style="padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(255,255,255,.02);margin-top:10px">
      <span>Эскалация, если водителя нет дольше (мин)</span>
      <input type="number" name="escalate_after_minutes" min="1" max="60" value="<?= (int) $s['escalate_after_minutes'] ?>" style="width:100px">
    </label>

    <label class="flex between" style="padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(255,255,255,.02);margin-top:10px">
      <span style="font-weight:700">Автоназначение ближайшему водителю</span>
      <input type="checkbox" name="auto_assign_enabled" value="1" <?= $s['auto_assign_enabled'] ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#facc15">
    </label>

    <label class="flex between" style="padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(255,255,255,.02);margin-top:10px;<?= $s['auto_assign_enabled'] ? '' : 'opacity:.5' ?>">
      <span>Радиус автоназначения (км)</span>
      <input type="number" name="auto_assign_radius_km" min="1" max="30" step="0.5" value="<?= (float) $s['auto_assign_radius_km'] ?>" style="width:100px">
    </label>

    <button class="btn" type="submit" style="width:100%;margin-top:14px">Сохранить</button>
  </form>

  <div class="card">
    <h3 style="font-weight:900;font-size:16px;margin-bottom:12px">Сейчас без водителя</h3>
    <div class="stat-big <?= $stuckCount > 0 ? '' : 'ok' ?>" style="<?= $stuckCount > 0 ? 'color:#fde047' : '' ?>"><?= $stuckCount ?></div>
    <p class="mut" style="margin-top:8px">
      Тик автодозвона выполняется при любом запросе заказов — каждые ~10 секунд на весь флот.
      Эскалированные заказы подсвечены красным на вкладке «Заказы».
    </p>
    <div class="flex" style="margin-top:14px">
      <a class="btn ghost sm" href="orders.php?status=searching">Открыть «в поиске»</a>
      <a class="btn ghost sm" href="orders.php?status=no_driver_found">«не найден»</a>
    </div>
  </div>
</div>

<?php layout_footer();
