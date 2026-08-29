<?php
// Заказы: таблица всех заказов + отмена + ссылка на CSV
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cmd'] ?? '') === 'cancel') {
    $id = (string) ($_POST['id'] ?? '');
    $order = $db->prepare("SELECT driver_id FROM orders WHERE id = ?");
    $order->execute([$id]);
    $row = $order->fetch();
    if ($row) {
        $db->prepare("UPDATE orders SET status='cancelled', cancelled_at = ?, cancellation_reason = ? WHERE id = ?")
            ->execute([Db::utcNow(), 'Отменено администратором', $id]);
        if (!empty($row['driver_id'])) {
            $db->prepare("UPDATE drivers SET status='available', current_order_id=NULL WHERE id = ?")
                ->execute([$row['driver_id']]);
        }
    }
    Bus::publish('orders');
    header('Location: orders.php?ok=' . urlencode('Заказ отменён'));
    exit;
}

$statusFilter = (string) ($_GET['status'] ?? '');
$where = $statusFilter !== '' ? 'WHERE status = ?' : '';
$stmt = $db->prepare("SELECT * FROM orders $where ORDER BY created_at DESC LIMIT 200");
$stmt->execute($statusFilter !== '' ? [$statusFilter] : []);
$rows = $stmt->fetchAll();

$statusChip = function (string $s): string {
    $cls = match ($s) {
        'completed' => 'ok',
        'cancelled' => 'bad',
        'in_progress' => 'info',
        'driver_arrived' => 'info',
        'driver_assigned', 'driver_en_route' => 'violet',
        default => 'warn',
    };
    return '<span class="chip ' . $cls . '">' . h(Taxi::STATUS_TEXT[$s] ?? $s) . '</span>';
};

layout_header('Заказы', 'orders');
?>
<div class="flex between">
  <div>
    <h1>Заказы</h1>
    <p class="mut"><?= count($rows) ?> последних</p>
  </div>
  <form method="get" class="inline">
    <select name="status" onchange="this.form.submit()">
      <option value="">Все статусы</option>
      <?php foreach (Taxi::STATUS_TEXT as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <a class="btn ghost" href="export.php">⬇ Экспорт CSV</a>
</div>

<div class="card" style="margin-top:18px;overflow-x:auto">
<table>
  <thead><tr>
    <th>Номер / дата</th><th>Статус</th><th>Маршрут</th><th>Клиент</th><th>Водитель</th><th style="text-align:right">Цена</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $o): $esc = !empty($o['escalated_at']); ?>
    <tr style="<?= $esc ? 'background:rgba(248,113,113,.06)' : '' ?>">
      <td>
        <div style="font-family:monospace;font-size:11px;color:#a1a1aa"><?= h(substr((string) $o['order_number'], -18)) ?></div>
        <div class="mut" style="font-size:11px"><?= h(fmt_date($o['created_at'])) ?> · <?= $o['source'] === 'operator_app' ? 'диспетчерская' : 'приложение' ?></div>
        <?php if ($esc): ?><span class="chip bad">эскалация</span><?php endif; ?>
      </td>
      <td><?= $statusChip($o['status']) ?></td>
      <td>
        <div><b><?= h((string) $o['pickup_address']) ?></b></div>
        <div class="mut">→ <?= h((string) ($o['destination_address'] ?? '—')) ?></div>
      </td>
      <td>
        <div><?= h((string) ($o['client_name'] ?? '—')) ?></div>
        <div class="mut" style="font-size:11px"><?= h((string) ($o['client_phone'] ?? '')) ?></div>
      </td>
      <td>
        <?php if ($o['driver_id']):
            $d = $db->prepare('SELECT u.first_name, u.last_name, d.license_plate FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=?');
            $d->execute([$o['driver_id']]); $drv = $d->fetch();
            if ($drv): ?>
            <div><?= h($drv['first_name'] . ' ' . $drv['last_name']) ?></div>
            <span class="plate" style="font-size:11px"><?= h($drv['license_plate']) ?></span>
        <?php endif; else: ?>
          <span class="mut">—</span>
        <?php endif; ?>
      </td>
      <td style="text-align:right">
        <div style="font-weight:900"><?= h(money((float) ($o['final_price'] ?? $o['estimated_price']))) ?></div>
        <div class="mut" style="font-size:11px"><?= h(Taxi::TARIFF_NAMES[$o['tariff']] ?? '') ?></div>
      </td>
      <td>
        <?php if (!in_array($o['status'], ['completed', 'cancelled'], true)): ?>
        <form method="post" class="inline" onsubmit="return confirm('Отменить заказ?')">
          <input type="hidden" name="cmd" value="cancel">
          <input type="hidden" name="id" value="<?= h($o['id']) ?>">
          <button class="btn danger sm" type="submit">Отменить</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="mut" style="text-align:center;padding:40px">Заказов нет</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php layout_footer();
