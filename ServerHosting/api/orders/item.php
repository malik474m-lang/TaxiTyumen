<?php
// GET api/orders/item.php?id= — GetOrderAsync (+ GPS-симуляция)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Simulate::advance($db);

$id = (string) ($_GET['id'] ?? '');
$stmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    Response::error('Заказ не найден', 404);
}
Response::json(Serialize::order($db, $order));
