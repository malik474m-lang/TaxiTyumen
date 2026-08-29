<?php
// Водители: финансы, пополнение баланса, верификация
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = (string) ($_POST['cmd'] ?? '');
    $id = (string) ($_POST['id'] ?? '');

    $d = $db->prepare('SELECT * FROM drivers WHERE id = ? LIMIT 1');
    $d->execute([$id]);
    $driver = $d->fetch();

    if ($driver) {
        if ($cmd === 'topup') {
            $amount = (float) ($_POST['amount'] ?? 0);
            if ($amount > 0) {
                $newBalance = $driver['balance'] + $amount;
                $db->prepare('UPDATE drivers SET balance = ? WHERE id = ?')->execute([$newBalance, $id]);
                $db->prepare(
                    "INSERT INTO balance_transactions (id, driver_id, type, amount, balance_after, description, created_by)
                     VALUES (?,?,?,?,?,?,?,?)"
                )->execute([Db::uuid(), $id, 'topup', $amount, $newBalance, sprintf('Пополнение +%.0f руб.', $amount), 'admin: ' . $admin['first_name']]);
                header('Location: drivers.php?ok=' . urlencode('Баланс пополнен: ' . round($newBalance) . ' ₽'));
                exit;
            }
        }
        if ($cmd === 'verify') {
            $db->prepare('UPDATE drivers SET is_verified = ? WHERE id = ?')
                ->execute([$driver['is_verified'] ? 0 : 1, $id]);
            header('Location: drivers.php?ok=' . urlencode('Верификация обновлена'));
            exit;
        }
    }
}

$rows = $db->query(
    'SELECT d.*, u.first_name, u.last_name, u.phone, u.rating
     FROM drivers d JOIN users u ON u.id = d.user_id ORDER BY d.status'
)->fetchAll();

$statusChip = function (string $s): string {
    $cls = $s === 'offline' ? 'mut' : ($s === 'available' ? 'ok' : 'info');
    $style = $s === 'offline' ? 'background:rgba(255,255,255,.07);color:#a1a1aa' : '';
    return '<span class="chip ' . $cls . '"' . ($style ? ' style="' . $style . '"' : '') . '>'
        . h(Taxi::DRIVER_STATUS_TEXT[$s] ?? $s) . '</span>';
};

layout_header('Водители', 'drivers');
?>
<h1>Водители</h1>
<p class="mut"><?= count($rows) ?> автомобилей в системе</p>

<?php if (!empty($_GET['ok'])): ?>
<div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div>
<?php endif; ?>

<div class="grid q2" style="margin-top:18px">
<?php foreach ($rows as $d):
    $low = $d['balance'] < $d['min_balance_for_orders'];
?>
  <div class="card">
    <div class="flex between">
      <div>
        <div style="font-weight:900;font-size:16px">
          <?= h($d['first_name'] . ' ' . $d['last_name']) ?>
          <?php if ($d['is_verified']): ?><span title="Верифицирован" style="color:#7dd3fc">✓</span><?php endif; ?>
        </div>
        <div class="mut" style="font-size:12px">
          <?= h($d['car_color'] . ' ' . $d['car_brand'] . ' ' . $d['car_model']) ?> ·
          <span class="plate"><?= h($d['license_plate']) ?></span>
        </div>
      </div>
      <?= $statusChip($d['status']) ?>
    </div>

    <div class="flex" style="margin-top:14px;gap:14px">
      <div>
        <div class="mut" style="font-size:11px">Баланс</div>
        <div style="font-weight:900;font-size:18px;color:<?= $low ? '#fca5a5' : '#fde047' ?>"><?= h(money((float) $d['balance'])) ?></div>
      </div>
      <div>
        <div class="mut" style="font-size:11px">Поездок</div>
        <div style="font-weight:900;font-size:18px"><?= (int) $d['completed_trips'] ?></div>
      </div>
      <div>
        <div class="mut" style="font-size:11px">Заработано</div>
        <div style="font-weight:900;font-size:18px;color:#6ee7b7"><?= h(money((float) $d['total_earnings'])) ?></div>
      </div>
      <div>
        <div class="mut" style="font-size:11px">Рейтинг</div>
        <div style="font-weight:900;font-size:18px">★ <?= h(number_format((float) $d['rating'], 1)) ?></div>
      </div>
    </div>
    <?php if ($low): ?>
      <div class="mut" style="font-size:12px;color:#fca5a5;margin-top:8px">
        ⚠ Ниже минимума <?= h(money((float) $d['min_balance_for_orders'])) ?> — не может принимать заказы
      </div>
    <?php endif; ?>

    <div class="flex" style="margin-top:14px">
      <form method="post" class="inline">
        <input type="hidden" name="cmd" value="topup">
        <input type="hidden" name="id" value="<?= h($d['id']) ?>">
        <select name="amount" style="width:110px">
          <option value="300">+300 ₽</option>
          <option value="500" selected>+500 ₽</option>
          <option value="1000">+1000 ₽</option>
          <option value="2000">+2000 ₽</option>
        </select>
        <button class="btn sm" type="submit">Пополнить</button>
      </form>
      <form method="post">
        <input type="hidden" name="cmd" value="verify">
        <input type="hidden" name="id" value="<?= h($d['id']) ?>">
        <button class="btn ghost sm" type="submit"><?= $d['is_verified'] ? 'Снять верификацию' : 'Верифицировать' ?></button>
      </form>
    </div>

    <?php
      $tx = $db->prepare('SELECT * FROM balance_transactions WHERE driver_id = ? ORDER BY created_at DESC LIMIT 3');
      $tx->execute([$d['id']]);
      $txRows = $tx->fetchAll();
      if ($txRows):
    ?>
    <div style="margin-top:12px;border-top:1px solid rgba(255,255,255,.07);padding-top:10px">
      <div class="mut" style="font-size:11px;margin-bottom:6px">Последние транзакции:</div>
      <?php foreach ($txRows as $t): ?>
      <div class="flex between" style="font-size:12px;padding:3px 0">
        <span class="mut"><?= h((string) $t['description']) ?> · <?= h(fmt_date($t['created_at'])) ?></span>
        <b style="color:<?= $t['amount'] >= 0 ? '#6ee7b7' : '#fca5a5' ?>"><?= ($t['amount'] >= 0 ? '+' : '') . round((float) $t['amount']) ?> ₽</b>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<?php layout_footer();
