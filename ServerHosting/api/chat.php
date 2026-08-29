<?php
// GET api/chat?orderId= — история | POST — отправить сообщение
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$msgDto = function (array $m): array {
    return [
        'id' => $m['id'],
        'orderId' => $m['order_id'],
        'senderId' => $m['sender_id'],
        'senderName' => $m['sender_name'],
        'senderRole' => $m['sender_role'],
        'text' => $m['text'],
        'isRead' => (bool) ($m['is_read'] ?? false),
        'readAt' => $m['read_at'] ?? null,
        'createdAt' => $m['created_at'],
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $claims = Guard::claims();
    $orderId = (string) ($_GET['orderId'] ?? '');
    if ($orderId === '') {
        Response::error('orderId обязателен');
    }
    // Читать чат может участник заказа или персонал
    $o = $db->prepare(
        'SELECT o.client_id,d.user_id AS driver_user_id FROM orders o
         LEFT JOIN drivers d ON d.id=o.driver_id WHERE o.id=? LIMIT 1'
    );
    $o->execute([$orderId]);
    $participant = $o->fetch();
    if (!$participant) Response::error('Заказ не найден', 404);
    $allowed = in_array($claims['role'] ?? '', ['operator', 'admin'], true)
        || ($participant['client_id'] ?? null) === ($claims['uid'] ?? '')
        || ($participant['driver_user_id'] ?? null) === ($claims['uid'] ?? '');
    if (!$allowed) Response::error('Вы не являетесь участником этого чата', 403);

    $stmt = $db->prepare('SELECT * FROM chat_messages WHERE order_id = ? ORDER BY created_at ASC LIMIT 100');
    $stmt->execute([$orderId]);
    Response::json(array_map($msgDto, $stmt->fetchAll()));
}

$claims = Guard::claims();
$body = Response::requirePostJson();
$orderId = (string) ($body['orderId'] ?? '');
$senderId = (string) ($body['senderId'] ?? '');
$text = trim((string) ($body['text'] ?? ''));
$action = (string) ($body['action'] ?? 'send');

if ($action === 'read') {
    if ($orderId === '') Response::error('orderId обязателен');
    $check = $db->prepare(
        'SELECT o.client_id,d.user_id AS driver_user_id FROM orders o
         LEFT JOIN drivers d ON d.id=o.driver_id WHERE o.id=? LIMIT 1'
    );
    $check->execute([$orderId]);
    $participant = $check->fetch();
    if (!$participant) Response::error('Заказ не найден', 404);
    $allowed = in_array($claims['role'] ?? '', ['operator', 'admin'], true)
        || ($participant['client_id'] ?? null) === ($claims['uid'] ?? '')
        || ($participant['driver_user_id'] ?? null) === ($claims['uid'] ?? '');
    if (!$allowed) Response::error('Вы не являетесь участником этого чата', 403);

    $db->prepare(
        'UPDATE chat_messages SET is_read=1,read_at=?
         WHERE order_id=? AND sender_id<>? AND is_read=0'
    )->execute([Db::utcNow(), $orderId, $claims['uid']]);
    Response::json(['ok' => true, 'marked' => (int) $db->query('SELECT ROW_COUNT()')->fetchColumn()]);
}

if ($orderId === '' || $senderId === '' || $text === '') {
    Response::error('orderId, senderId и text обязательны');
}
if (($claims['uid'] ?? '') !== $senderId) {
    Response::error('Нельзя писать от чужого имени', 403);
}

$o = $db->prepare(
    'SELECT o.client_id,d.user_id AS driver_user_id FROM orders o
     LEFT JOIN drivers d ON d.id=o.driver_id WHERE o.id=? LIMIT 1'
);
$o->execute([$orderId]);
$participant = $o->fetch();
if (!$participant) {
    Response::error('Заказ не найден', 404);
}
$allowed = in_array($claims['role'] ?? '', ['operator', 'admin'], true)
    || ($participant['client_id'] ?? null) === ($claims['uid'] ?? '')
    || ($participant['driver_user_id'] ?? null) === ($claims['uid'] ?? '');
if (!$allowed) {
    Response::error('Вы не являетесь участником этого чата', 403);
}

$u = $db->prepare('SELECT first_name, last_name, role FROM users WHERE id = ? LIMIT 1');
$u->execute([$senderId]);
$sender = $u->fetch() ?: ['first_name' => '—', 'last_name' => '', 'role' => 'client'];

$id = Db::uuid();
$senderName = trim($sender['first_name'] . ' ' . $sender['last_name']);
$db->prepare(
    'INSERT INTO chat_messages (id, order_id, sender_id, sender_name, sender_role, text)
     VALUES (?,?,?,?,?,?)'
)->execute([$id, $orderId, $senderId, $senderName, $sender['role'], $text]);

// Уведомляем второго участника чата
$recipientId = ($participant['client_id'] ?? null) === $senderId
    ? ($participant['driver_user_id'] ?? null)
    : ($participant['client_id'] ?? null);
if ($recipientId) {
    NotificationService::create(
        $db, (string) $recipientId, 'NewChatMessage',
        'Новое сообщение · ' . $senderName, $text, $orderId,
        ['messageId'=>$id,'orderId'=>$orderId,'senderId'=>$senderId,'senderRole'=>$sender['role'],'senderName'=>$senderName,'text'=>$text,'createdAt'=>Db::utcNow()]
    );
}

Bus::publish('chat');
$stmt = $db->prepare('SELECT * FROM chat_messages WHERE id = ?');
$stmt->execute([$id]);
$messageDto = $msgDto($stmt->fetch());
if (!empty($GLOBALS['chat_compat_response'])) {
    Response::json(['messageId' => $id]);
}
Response::json($messageDto, 201);
