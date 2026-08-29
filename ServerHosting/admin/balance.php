<?php
// Балансы — порт TaxiAdmin/Balance.razor: сводка водителей + журнал транзакций.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

admin_require($db);

$type = (string) ($_GET['type'] ?? '');
$where = in_array($type, ['topup', 'commission', 'penalty'], true) ? 'WHERE bt.type=?' : '';
$stmt = $db->prepare(
    "SELECT bt.*, u.first_name, u.last_name, d.license_plate
     FROM balance_transactions bt
     JOIN drivers d ON d.id=bt.driver_id
     JOIN users u ON u.id=d.user_id
     $where ORDER BY bt.created_at DESC LIMIT 300"
);
$stmt->execute($where ? [$type] : []);
$transactions = $stmt->fetchAll();

$summary = $db->query(
    "SELECT
      COALESCE(SUM(balance),0) AS total_balance,
      COALESCE(SUM(CASE WHEN balance < min_balance_for_orders THEN 1 ELSE 0 END),0) AS low_count,
      COALESCE(SUM(total_earnings),0) AS total_earnings
     FROM drivers"
)->fetch();
$flow = $db->query(
    "SELECT
      COALESCE(SUM(CASE WHEN type='topup' THEN amount ELSE 0 END),0) AS topups,
      COALESCE(SUM(CASE WHEN type='commission' THEN -amount ELSE 0 END),0) AS commissions,
      COALESCE(SUM(CASE WHEN type='penalty' THEN -amount ELSE 0 END),0) AS penalties
     FROM balance_transactions WHERE created_at>=UTC_DATE()"
)->fetch();

$drivers = $db->query(
    'SELECT d.id,d.balance,d.min_balance_for_orders,d.total_earnings,d.completed_trips,d.license_plate,
     u.first_name,u.last_name FROM drivers d JOIN users u ON u.id=d.user_id ORDER BY d.balance ASC'
)->fetchAll();

layout_header('Балансы', 'balance');
?>
<h1>Балансы и транзакции</h1>
<p class="mut">Единый финансовый журнал водителей</p>

<div class="grid q4" style="margin-top:18px">
  <div class="card"><div class="mut">Средства на балансах</div><div class="stat-big" style="color:#fde047"><?= money((float) $summary['total_balance']) ?></div></div>
  <div class="card"><div class="mut">Пополнено сегодня</div><div class="stat-big" style="color:#6ee7b7"><?= money((float) $flow['topups']) ?></div></div>
  <div class="card"><div class="mut">Комиссий сегодня</div><div class="stat-big" style="color:#7dd3fc"><?= money((float) $flow['commissions']) ?></div></div>
  <div class="card"><div class="mut">Штрафов сегодня</div><div class="stat-big" style="color:#fca5a5"><?= money((float) $flow['penalties']) ?></div><div class="mut">Низкий баланс: <?= (int) $summary['low_count'] ?></div></div>
</div>

<div class="grid q2" style="margin-top:14px">
  <div class="card" style="overflow-x:auto">
    <div class="flex between" style="margin-bottom:10px"><h3>Водители</h3><a class="btn ghost sm" href="drivers.php">Управление</a></div>
    <table><thead><tr><th>Водитель</th><th>Баланс</th><th>Минимум</th><th>Поездки</th></tr></thead><tbody>
    <?php foreach ($drivers as $d): $low=$d['balance']<$d['min_balance_for_orders']; ?>
      <tr><td><b><?= h($d['first_name'].' '.$d['last_name']) ?></b><div class="mut"><?= h($d['license_plate']) ?></div></td>
      <td style="font-weight:900;color:<?= $low?'#fca5a5':'#fde047' ?>"><?= money((float)$d['balance']) ?></td>
      <td><?= money((float)$d['min_balance_for_orders']) ?></td><td><?= (int)$d['completed_trips'] ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>

  <div class="card" style="overflow-x:auto">
    <div class="flex between" style="margin-bottom:10px">
      <h3>Транзакции</h3>
      <form method="get"><select name="type" onchange="this.form.submit()"><option value="">Все типы</option><option value="topup" <?= $type==='topup'?'selected':'' ?>>Пополнения</option><option value="commission" <?= $type==='commission'?'selected':'' ?>>Комиссии</option><option value="penalty" <?= $type==='penalty'?'selected':'' ?>>Штрафы</option></select></form>
    </div>
    <table><thead><tr><th>Дата / водитель</th><th>Описание</th><th style="text-align:right">Сумма</th></tr></thead><tbody>
    <?php foreach ($transactions as $t): ?>
      <tr><td><div><?= h(fmt_date($t['created_at'])) ?></div><div class="mut"><?= h($t['first_name'].' '.$t['last_name'].' · '.$t['license_plate']) ?></div></td>
      <td><?= h($t['description']) ?><div class="mut">После: <?= money((float)$t['balance_after']) ?></div></td>
      <td style="text-align:right;font-weight:900;color:<?= $t['amount']>=0?'#6ee7b7':'#fca5a5' ?>"><?= $t['amount']>=0?'+':'' ?><?= money((float)$t['amount']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$transactions): ?><tr><td colspan="3" class="mut" style="text-align:center;padding:30px">Транзакций нет</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>

<?php layout_footer();
