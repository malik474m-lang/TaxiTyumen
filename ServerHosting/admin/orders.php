<?php
// Заказы: таблица всех заказов + отмена + ссылка на CSV
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'orders');
$telephony = Telephony::settings($db);
$telephonyReady = Telephony::isConfigured($telephony);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = (string) ($_POST['cmd'] ?? '');
    $id = (string) ($_POST['id'] ?? '');
    $orderStmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $orderStmt->execute([$id]);
    $row = $orderStmt->fetch();

    if ($row && $cmd === 'cancel') {
        $db->prepare("UPDATE orders SET status='cancelled',cancelled_at=?,cancellation_reason=?,cancelled_by_user_id=? WHERE id=?")
            ->execute([Db::utcNow(), 'Отменено администратором', $admin['id'], $id]);
        $db->prepare("UPDATE transactions SET status='refunded',completed_at=? WHERE order_id=?")
            ->execute([Db::utcNow(), $id]);
        if (!empty($row['driver_id'])) {
            $db->prepare("UPDATE drivers SET status='available',current_order_id=NULL WHERE id=?")
                ->execute([$row['driver_id']]);
        }
        $orderStmt->execute([$id]);
        $fresh = $orderStmt->fetch();
        NotificationService::notifyClientOrderCancelled($db, $fresh, 'Отменено администратором');
        NotificationService::notifyOperatorsOrderUpdate($db, $fresh);
        Bus::publish('orders');
        header('Location: orders.php?ok=' . urlencode('Заказ отменён'));
        exit;
    }

    if ($row && $cmd === 'assign') {
        $driverId = (string) ($_POST['driver_id'] ?? '');
        $d = $db->prepare('SELECT id FROM drivers WHERE id=? AND is_verified=1 LIMIT 1');
        $d->execute([$driverId]);
        if (!$d->fetch()) {
            header('Location: orders.php?error=' . urlencode('Выберите верифицированного водителя'));
            exit;
        }
        if (!empty($row['driver_id']) && $row['driver_id'] !== $driverId) {
            $db->prepare("UPDATE drivers SET status='available',current_order_id=NULL WHERE id=?")
                ->execute([$row['driver_id']]);
        }
        $db->prepare("UPDATE orders SET driver_id=?,status='driver_assigned',accepted_at=? WHERE id=?")
            ->execute([$driverId, Db::utcNow(), $id]);
        $db->prepare("UPDATE drivers SET status='on_route',current_order_id=? WHERE id=?")
            ->execute([$id, $driverId]);
        $orderStmt->execute([$id]);
        $fresh = $orderStmt->fetch();
        NotificationService::notifyClientOrderAccepted($db, $fresh);
        NotificationService::notifyDriverForceAssigned($db, $driverId, $fresh);
        NotificationService::notifyOperatorsOrderUpdate($db, $fresh);
        Bus::publish('orders');
        header('Location: orders.php?ok=' . urlencode('Водитель назначен'));
        exit;
    }

    if ($row && $cmd === 'call') {
        if (!$telephonyReady) {
            header('Location: orders.php?error=' . urlencode('Телефония не настроена'));
            exit;
        }
        $pStmt = $db->prepare(
            'SELECT COALESCE(u.phone, o.client_phone) AS client_phone, du.phone AS driver_phone
             FROM orders o LEFT JOIN users u ON u.id=o.client_id
             LEFT JOIN drivers d ON d.id=o.driver_id LEFT JOIN users du ON du.id=d.user_id
             WHERE o.id=? LIMIT 1'
        );
        $pStmt->execute([$id]);
        $phones = $pStmt->fetch();
        if (!$phones || empty($phones['client_phone']) || empty($phones['driver_phone'])) {
            header('Location: orders.php?error=' . urlencode('Нужны телефоны клиента и назначенного водителя'));
            exit;
        }
        $callResult = Telephony::connect(
            $db, (string) $phones['driver_phone'], (string) $phones['client_phone'], 'order',
            ['orderId' => $id, 'driverId' => $row['driver_id'], 'userId' => $row['client_id']]
        );
        header('Location: orders.php?ok=' . urlencode('Звонок инициирован: ' . ($callResult['status'] ?? '')));
        exit;
    }
}

$statusFilter = (string) ($_GET['status'] ?? '');
$where = $statusFilter !== '' ? 'WHERE status = ?' : '';
$stmt = $db->prepare("SELECT * FROM orders $where ORDER BY created_at DESC LIMIT 200");
$stmt->execute($statusFilter !== '' ? [$statusFilter] : []);
$rows = $stmt->fetchAll();
$assignDrivers = $db->query(
    "SELECT d.id,d.status,d.license_plate,u.first_name,u.last_name FROM drivers d
     JOIN users u ON u.id=d.user_id WHERE d.is_verified=1 AND u.is_active=1 AND u.is_blocked=0 AND u.is_archived=0
     ORDER BY FIELD(d.status,'available','on_route','in_trip','busy','offline'),u.last_name"
)->fetchAll();

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

<?php if(!empty($_GET['ok'])):?><div class="flash" style="margin-top:14px">✓ <?=h((string)$_GET['ok'])?></div><?php endif;?>
<?php if(!empty($_GET['error'])):?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?=h((string)$_GET['error'])?></div><?php endif;?>
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
        <form method="post" class="inline" style="margin-bottom:5px">
          <input type="hidden" name="cmd" value="assign"><input type="hidden" name="id" value="<?=h($o['id'])?>">
          <select name="driver_id" required style="width:170px"><option value="">Назначить...</option><?php foreach($assignDrivers as $ad):?><option value="<?=h($ad['id'])?>" <?=$o['driver_id']===$ad['id']?'selected':''?>><?=h($ad['first_name'].' '.$ad['last_name'].' · '.$ad['license_plate'].' · '.(Taxi::DRIVER_STATUS_TEXT[$ad['status']]??$ad['status']))?></option><?php endforeach;?></select>
          <button class="btn sm">Назначить</button>
        </form>
        <?php if ($telephonyReady && $o['driver_id']): ?>
        <form method="post" class="inline">
          <input type="hidden" name="cmd" value="call"><input type="hidden" name="id" value="<?= h($o['id']) ?>">
          <button class="btn ghost sm" title="Соединить водителя с клиентом">📞 Позвонить</button>
        </form>
        <?php endif; ?>
        <?php if ($telephonyReady && $o['driver_id']): ?>
        <form method="post" class="inline">
          <input type="hidden" name="cmd" value="call"><input type="hidden" name="id" value="<?= h($o['id']) ?>">
          <button class="btn ghost sm" title="Соединить водителя с клиентом">📞 Звонок</button>
        </form>
        <?php endif; ?>
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
