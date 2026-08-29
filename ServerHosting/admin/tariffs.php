<?php
// Тарифы: просмотр и редактирование (из Tariffs.razor)
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (string) ($_POST['id'] ?? '');
    $db->prepare(
        'UPDATE tariffs SET name=?, description=?, base_fare=?, price_per_km=?, price_per_minute=?,
         minimum_fare=?, night_multiplier=?, peak_multiplier=?, commission_percent=?,
         paid_waiting_per_minute=?, is_active=?, updated_at=? WHERE id=?'
    )->execute([
        mb_substr((string) ($_POST['name'] ?? ''), 0, 40),
        mb_substr((string) ($_POST['description'] ?? ''), 0, 200),
        (float) ($_POST['base_fare'] ?? 0),
        (float) ($_POST['price_per_km'] ?? 0),
        (float) ($_POST['price_per_minute'] ?? 0),
        (float) ($_POST['minimum_fare'] ?? 0),
        (float) ($_POST['night_multiplier'] ?? 1),
        (float) ($_POST['peak_multiplier'] ?? 1),
        (float) ($_POST['commission_percent'] ?? 15),
        (float) ($_POST['paid_waiting_per_minute'] ?? 0),
        !empty($_POST['is_active']) ? 1 : 0,
        Db::utcNow(),
        $id,
    ]);
    header('Location: tariffs.php?ok=' . urlencode('Тариф сохранён'));
    exit;
}

$rows = $db->query('SELECT * FROM tariffs ORDER BY base_fare')->fetchAll();

layout_header('Тарифы', 'tariffs');
?>
<h1>Тарифы Тюмени</h1>
<p class="mut">Изменения применяются к новым расчётам немедленно</p>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>

<div class="grid q2" style="margin-top:18px">
<?php foreach ($rows as $t): ?>
  <form method="post" class="card">
    <input type="hidden" name="id" value="<?= h($t['id']) ?>">
    <div class="flex between">
      <input name="name" value="<?= h($t['name']) ?>" style="max-width:180px;font-weight:900;font-size:17px">
      <label class="mut" style="font-size:12px;display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?> style="width:auto">
        Активен
      </label>
    </div>
    <input name="description" value="<?= h($t['description']) ?>" style="margin-top:8px;font-size:12px">

    <table style="margin-top:14px">
      <?php foreach ([
          ['base_fare', 'Посадка, ₽', $t['base_fare']],
          ['price_per_km', 'За км, ₽', $t['price_per_km']],
          ['price_per_minute', 'За минуту, ₽', $t['price_per_minute']],
          ['minimum_fare', 'Минимум, ₽', $t['minimum_fare']],
          ['night_multiplier', 'Ночной ×', $t['night_multiplier']],
          ['peak_multiplier', 'Час пик ×', $t['peak_multiplier']],
          ['commission_percent', 'Комиссия, %', $t['commission_percent']],
          ['paid_waiting_per_minute', 'Ожидание, ₽/мин', $t['paid_waiting_per_minute']],
      ] as [$field, $label, $value]): ?>
      <tr>
        <td class="mut" style="border:0;padding:6px 0"><?= h($label) ?></td>
        <td style="border:0;padding:6px 0;width:110px">
          <input type="number" step="0.1" name="<?= h($field) ?>" value="<?= h((string) $value) ?>" style="text-align:right">
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <button class="btn" type="submit" style="margin-top:12px;width:100%">Сохранить «<?= h($t['name']) ?>»</button>
  </form>
<?php endforeach; ?>
</div>

<?php layout_footer();
