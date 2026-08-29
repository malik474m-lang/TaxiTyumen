<?php
// GET api/chat?orderId= — история | POST — отправить сообщение
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orderId = (string) ($_GET['orderId'] ?? '');
    if ($orderId === '') {
        Response::error('orderId обязателен');
    }
    $stmt = $db->prepare('SELECT * FROM chat_messages WHERE order_id = ? ORDER BY created_at ASC LIMIT 100');
    $stmt->execute([$orderId]);
    Response::json($stmt->fetchAll());
}

$claims = Guard::claims();
$body = Response::requirePostJson();
$orderId = (string) ($body['orderId'] ?? '');
$senderId = (string) ($body['senderId'] ?? '');
$text = trim((string) ($body['text'] ?? ''));

if ($orderId === '' || $senderId === '' || $text === '') {
    Response::error('orderId, senderId и text обязательны');
}
if (($claims['uid'] ?? '') !== $senderId) {
    Response::error('Нельзя писать от чужого имени', 403);
}

$o = $db->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
$o->execute([$orderId]);
if (!$o->fetch()) {
    Response::error('Заказ не найден', 404);
}

$u = $db->prepare('SELECT first_name, last_name, role FROM users WHERE id = ? LIMIT 1');
$u->execute([$senderId]);
$sender = $u->fetch() ?: ['first_name' => '—', 'last_name' => '', 'role' => 'client'];

$id = Db::uuid();
$db->prepare(
    'INSERT INTO chat_messages (id, order_id, sender_id, sender_name, sender_role, text)
     VALUES (?,?,?,?,?,?)'
)->execute([$id, $orderId, $senderId, trim($sender['first_name'] . ' ' . $sender['last_name']), $sender['role'], $text]);

Bus::publish('chat');
$stmt = $db->prepare('SELECT * FROM chat_messages WHERE id = ?');
$stmt->execute([$id]);
Response::json($stmt->fetch(), 201);
