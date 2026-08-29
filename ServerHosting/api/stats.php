<?php
// GET api/stats — сводная статистика админки (только админ)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$claims = Guard::claims();
Guard::role($claims, 'admin');

$activeIn = "'" . implode("','", Taxi::ACTIVE_STATUSES) . "'";
$scalar = function (string $sql, array $params = []) use ($db) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};

// Выручка по дням — 7 суток
$dailyRows = $db->query(
    "SELECT DATE(completed_at) AS day, COALESCE(SUM(final_price), 0) AS revenue, COUNT(*) AS cnt
     FROM orders WHERE status = 'completed' AND completed_at >= DATE_SUB(UTC_DATE(), INTERVAL 6 DAY)
     GROUP BY DATE(completed_at)"
)->fetchAll();
$dailyMap = [];
foreach ($dailyRows as $r) {
    $dailyMap[$r['day']] = $r;
}
$revenueByDay = [];
for ($i = 6; $i >= 0; $i--) {
    $day = gmdate('Y-m-d', time() - $i * 86400);
    $r = $dailyMap[$day] ?? null;
    $revenueByDay[] = [
        'day' => $day,
        'revenue' => (int) round((float) ($r['revenue'] ?? 0)),
        'count' => (int) ($r['cnt'] ?? 0),
    ];
}

// Заказы по часам (Тюмень UTC+5)
$hourlyRows = $db->query(
    'SELECT HOUR(created_at + INTERVAL 5 HOUR) AS h, COUNT(*) AS cnt FROM orders GROUP BY h'
)->fetchAll();
$hourlyMap = [];
foreach ($hourlyRows as $r) {
    $hourlyMap[(int) $r['h']] = (int) $r['cnt'];
}
$ordersByHour = [];
for ($h = 0; $h < 24; $h++) {
    $ordersByHour[] = ['hour' => $h, 'count' => $hourlyMap[$h] ?? 0];
}

$topRoutes = $db->query(
    "SELECT destination_address AS `to`, COUNT(*) AS cnt FROM orders
     WHERE destination_address IS NOT NULL AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
     GROUP BY destination_address ORDER BY cnt DESC LIMIT 5"
)->fetchAll();

$byTariff = $db->query(
    'SELECT tariff, COUNT(*) AS cnt, COALESCE(SUM(final_price), 0) AS revenue
     FROM orders GROUP BY tariff'
)->fetchAll();

Response::json([
    'totalOrders' => (int) $scalar('SELECT COUNT(*) FROM orders'),
    'todayOrders' => (int) $scalar("SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"),
    'todayRevenue' => (int) round((float) $scalar(
        "SELECT COALESCE(SUM(final_price), 0) FROM orders WHERE status = 'completed' AND completed_at >= UTC_DATE()"
    )),
    'activeOrders' => (int) $scalar("SELECT COUNT(*) FROM orders WHERE status IN ($activeIn)"),
    'onlineDrivers' => (int) $scalar("SELECT COUNT(*) FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.status != 'offline' AND u.is_archived=0"),
    'totalDrivers' => (int) $scalar('SELECT COUNT(*) FROM drivers d JOIN users u ON u.id=d.user_id WHERE u.is_archived=0'),
    'totalClients' => (int) $scalar("SELECT COUNT(*) FROM users WHERE role = 'client' AND is_archived = 0"),
    'completedToday' => (int) $scalar("SELECT COUNT(*) FROM orders WHERE status = 'completed' AND completed_at >= UTC_DATE()"),
    'cancelledToday' => (int) $scalar("SELECT COUNT(*) FROM orders WHERE status = 'cancelled' AND cancelled_at >= UTC_DATE()"),
    'avgCheck' => (int) round((float) $scalar("SELECT COALESCE(AVG(final_price), 0) FROM orders WHERE status = 'completed'")),
    'topRoutes' => array_map(fn(array $r) => ['to' => $r['to'], 'count' => (int) $r['cnt']], $topRoutes),
    'byTariff' => array_map(fn(array $r) => ['tariff' => $r['tariff'], 'count' => (int) $r['cnt'], 'revenue' => (float) $r['revenue']], $byTariff),
    'revenueByDay' => $revenueByDay,
    'ordersByHour' => $ordersByHour,
]);
