<?php
// Обзор: ключевые метрики сервиса (порт admin/stats)
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'index');

$scalar = function (string $sql, array $params = []) use ($db) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$activeIn = "'" . implode("','", Taxi::ACTIVE_STATUSES) . "'";

$todayRevenue   = (int) round((float) $scalar("SELECT COALESCE(SUM(final_price),0) FROM orders WHERE status='completed' AND completed_at >= UTC_DATE()"));
$todayOrders    = (int) $scalar("SELECT COUNT(*) FROM orders WHERE created_at >= UTC_DATE()");
$activeOrders   = (int) $scalar("SELECT COUNT(*) FROM orders WHERE status IN ($activeIn)");
$onlineDrivers  = (int) $scalar("SELECT COUNT(*) FROM drivers WHERE status != 'offline'");
$totalDrivers   = (int) $scalar('SELECT COUNT(*) FROM drivers');
$totalClients   = (int) $scalar("SELECT COUNT(*) FROM users WHERE role='client'");
$completedToday = (int) $scalar("SELECT COUNT(*) FROM orders WHERE status='completed' AND completed_at >= UTC_DATE()");
$cancelledToday = (int) $scalar("SELECT COUNT(*) FROM orders WHERE status='cancelled' AND cancelled_at >= UTC_DATE()");
$avgCheck       = (int) round((float) $scalar("SELECT COALESCE(AVG(final_price),0) FROM orders WHERE status='completed'"));

// Выручка по дням (7 суток)
$dailyRows = $db->query(
    "SELECT DATE(completed_at) AS day, COALESCE(SUM(final_price),0) AS revenue
     FROM orders WHERE status='completed' AND completed_at >= DATE_SUB(UTC_DATE(), INTERVAL 6 DAY)
     GROUP BY DATE(completed_at)"
)->fetchAll();
$dailyMap = [];
foreach ($dailyRows as $r) { $dailyMap[$r['day']] = (int) round((float) $r['revenue']); }
$revenueByDay = [];
for ($i = 6; $i >= 0; $i--) {
    $day = gmdate('Y-m-d', time() - $i * 86400);
    $revenueByDay[] = ['day' => $day, 'revenue' => $dailyMap[$day] ?? 0];
}
$maxRevenue = max(1, ...array_column($revenueByDay, 'revenue'));

// Заказы по часам (Тюмень UTC+5)
$hourlyRows = $db->query(
    'SELECT HOUR(created_at + INTERVAL 5 HOUR) AS h, COUNT(*) AS cnt FROM orders GROUP BY h'
)->fetchAll();
$hourlyMap = [];
foreach ($hourlyRows as $r) { $hourlyMap[(int) $r['h']] = (int) $r['cnt']; }
$maxHour = max(1, ...array_values($hourlyMap ?: [1]));

$escalated = (int) $scalar('SELECT COUNT(*) FROM orders WHERE escalated_at IS NOT NULL AND driver_id IS NULL');
$topRoutes = $db->query(
    "SELECT destination_address AS dest, COUNT(*) AS cnt FROM orders
     WHERE destination_address IS NOT NULL GROUP BY destination_address ORDER BY cnt DESC LIMIT 5"
)->fetchAll();
$latestOrders = $db->query(
    "SELECT o.*,CONCAT(u.first_name,' ',u.last_name) AS driver_name,d.license_plate
     FROM orders o LEFT JOIN drivers d ON d.id=o.driver_id LEFT JOIN users u ON u.id=d.user_id
     ORDER BY o.created_at DESC LIMIT 10"
)->fetchAll();

layout_header('Обзор', 'index');
?>
<h1>Сводка сервиса</h1>
<p class="mut"><?= h(date('d.m.Y')) ?> · Тюмень (UTC+5)</p>

<?php if ($escalated > 0): ?>
<div class="flash" style="border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5;margin-top:14px">
  ⚠️ Эскалированных заказов без водителя: <b><?= $escalated ?></b> — проверьте «Заказы» и автодозвон
</div>
<?php endif; ?>

<div class="grid q4" style="margin-top:18px">
  <?php
  foreach ([
    ['Выручка сегодня', money((float) $todayRevenue), 'warn'],
    ['Заказов сегодня', $todayOrders, 'mut'],
    ['Активных сейчас', $activeOrders, 'ok'],
    ['Водителей на линии', "$onlineDrivers / $totalDrivers", 'info'],
    ['Клиентов', $totalClients, 'mut'],
    ['Средний чек', money((float) $avgCheck), 'mut'],
    ['Завершено сегодня', $completedToday, 'ok'],
    ['Отменено сегодня', $cancelledToday, 'bad'],
  ] as [$label, $value, $cls]):
  ?>
  <div class="card">
    <div class="mut" style="font-size:11px;text-transform:uppercase;letter-spacing:.12em;font-weight:700"><?= h($label) ?></div>
    <div class="stat-big <?= $cls === 'warn' ? '' : '' ?>" <?= $cls === 'warn' ? 'style="color:#fde047"' : '' ?>><?= h((string) $value) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid q2" style="margin-top:14px">
  <div class="card">
    <h3 style="font-size:15px;font-weight:800">Выручка по дням</h3>
    <div class="mut">завершённые поездки, 7 суток</div>
    <div class="bars">
      <?php foreach ($revenueByDay as $d): ?>
      <div class="b">
        <div class="bar" style="height:<?= max(3, (int) round($d['revenue'] / $maxRevenue * 100)) ?>px" title="<?= money((float) $d['revenue']) ?>"></div>
        <span><?= h(date('D', strtotime($d['day']))) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <h3 style="font-size:15px;font-weight:800">Заказы по часам</h3>
    <div class="mut">время Тюмени, за всё время</div>
    <div class="bars light">
      <?php for ($h24 = 0; $h24 < 24; $h24++): $cnt = $hourlyMap[$h24] ?? 0; ?>
      <div class="b">
        <div class="bar" style="height:<?= max(3, (int) round($cnt / $maxHour * 100)) ?>px" title="<?= $h24 ?>:00 — <?= $cnt ?>"></div>
        <span><?= $h24 % 4 === 0 ? $h24 : '' ?></span>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<div class="card" style="margin-top:14px">
  <h3 style="font-size:15px;font-weight:800;margin-bottom:12px">Топ направлений</h3>
  <?php if (!$topRoutes): ?>
    <div class="mut">Данных пока нет</div>
  <?php else: ?>
    <table>
      <?php foreach ($topRoutes as $r): ?>
      <tr>
        <td><?= h((string) $r['dest']) ?></td>
        <td style="text-align:right;font-weight:900;color:#fde047"><?= (int) $r['cnt'] ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:14px;overflow-x:auto">
  <div class="flex between" style="margin-bottom:10px"><h3>Последние заказы</h3><a class="btn ghost sm" href="orders.php">Все заказы →</a></div>
  <table><thead><tr><th>Номер / время</th><th>Статус</th><th>Маршрут</th><th>Водитель</th><th style="text-align:right">Цена</th></tr></thead><tbody>
  <?php foreach($latestOrders as $o):?><tr><td><code><?=h(substr($o['order_number'],-18))?></code><div class="mut"><?=h(fmt_date($o['created_at']))?></div></td><td><span class="chip <?=$o['status']==='completed'?'ok':($o['status']==='cancelled'?'bad':'warn')?>"><?=h(Taxi::STATUS_TEXT[$o['status']]??$o['status'])?></span></td><td><b><?=h($o['pickup_address'])?></b><div class="mut">→ <?=h((string)($o['destination_address']??'—'))?></div></td><td><?=h((string)($o['driver_name']??'—'))?><div class="mut"><?=h((string)($o['license_plate']??''))?></div></td><td style="text-align:right;font-weight:900;color:#fde047"><?=money((float)($o['final_price']??$o['estimated_price']))?></td></tr><?php endforeach;?>
  <?php if(!$latestOrders):?><tr><td colspan="5" class="mut" style="text-align:center">Заказов пока нет</td></tr><?php endif;?>
  </tbody></table>
</div>

<?php layout_footer();
