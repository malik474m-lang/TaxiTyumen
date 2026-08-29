<?php
// POST /api/telephony/call.php — соединить абонентов через телефонию.
// Сценарии: order (клиент ↔ водитель), client (оператор ↔ клиент), custom.
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Response::requireMethod('POST');
$claims = Guard::claims();
Guard::role($claims, 'operator', 'admin');

$body = Response::requirePostJson();
$scenario = (string) ($body['scenario'] ?? 'custom');
$context = [];
$first = '';   // кому звоним первым (инициатор разговора)
$second = '';  // с кем соединяем

if ($scenario === 'order') {
    $orderId = (string) ($body['orderId'] ?? '');
    $stmt = $db->prepare(
        'SELECT o.*, u.phone AS client_user_phone, du.phone AS driver_phone, d.id AS drv_id
         FROM orders o
         LEFT JOIN users u ON u.id = o.client_id
         LEFT JOIN drivers d ON d.id = o.driver_id
         LEFT JOIN users du ON du.id = d.user_id
         WHERE o.id = ? LIMIT 1'
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) Response::error('Заказ не найден', 404);
    if (empty($order['driver_phone'])) Response::error('Водитель ещё не назначен на заказ');

    $clientPhone = $order['client_user_phone'] ?: $order['client_phone'];
    if (!$clientPhone) Response::error('У заказа нет телефона клиента');

    // Сначала поднимаем водителя, затем соединяем с клиентом
    $first = (string) $order['driver_phone'];
    $second = (string) $clientPhone;
    $context = ['orderId' => $order['id'], 'driverId' => $order['drv_id'], 'userId' => $order['client_id']];
} elseif ($scenario === 'client') {
    $userId = (string) ($body['userId'] ?? '');
    $u = $db->prepare('SELECT id, phone FROM users WHERE id = ? LIMIT 1');
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) Response::error('Пользователь не найден', 404);

    $operator = $db->prepare('SELECT phone FROM users WHERE id = ? LIMIT 1');
    $operator->execute([$claims['uid']]);
    $operatorPhone = (string) ($operator->fetchColumn() ?: '');
    if ($operatorPhone === '') Response::error('У вашей учётной записи нет телефона');

    $first = $operatorPhone;
    $second = (string) $user['phone'];
    $context = ['userId' => $user['id']];
} else {
    $first = (string) ($body['first'] ?? '');
    $second = (string) ($body['second'] ?? '');
    if ($first === '' || $second === '') {
        Response::error('Укажите оба номера: first и second');
    }
    $context = ['orderId' => $body['orderId'] ?? null];
}

$result = Telephony::connect($db, $first, $second, $scenario, $context);
Bus::publish('telephony');

if (($result['status'] ?? '') === 'failed') {
    Response::json($result, 502);
}
Response::json($result);
