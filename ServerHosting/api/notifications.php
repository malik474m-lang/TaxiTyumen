<?php
// GET  /api/notifications.php?unread=1&limit=50 — уведомления текущего пользователя
// POST /api/notifications.php — read/read_all (себе) или send (только admin)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$claims = Guard::claims();
$uid = (string) $claims['uid'];

$toDto = function (array $n): array {
    return [
        'id' => $n['id'],
        'recipientId' => $n['recipient_id'],
        'recipientRole' => $n['recipient_role'],
        'orderId' => $n['order_id'],
        'type' => $n['type'],
        'title' => $n['title'],
        'message' => $n['message'],
        'payload' => $n['payload'] ? (json_decode($n['payload'], true) ?: null) : null,
        'channel' => $n['channel'],
        'deliveryStatus' => $n['delivery_status'],
        'providerResponse' => $n['provider_response'],
        'isRead' => (bool) $n['is_read'],
        'readAt' => $n['read_at'],
        'createdAt' => $n['created_at'],
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $unread = ($_GET['unread'] ?? '') === '1';
    $sql = 'SELECT * FROM notifications WHERE recipient_id=?'
        . ($unread ? ' AND is_read=0' : '')
        . ' ORDER BY created_at DESC LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();
    $countStmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND is_read=0');
    $countStmt->execute([$uid]);
    Response::json([
        'items' => array_map($toDto, $rows),
        'unreadCount' => (int) $countStmt->fetchColumn(),
    ]);
}

Response::requireMethod('POST');
$body = Response::requirePostJson();
$action = (string) ($body['action'] ?? '');

if ($action === 'read') {
    $id = (string) ($body['id'] ?? '');
    $db->prepare('UPDATE notifications SET is_read=1,read_at=? WHERE id=? AND recipient_id=?')
        ->execute([Db::utcNow(), $id, $uid]);
    Response::json(['ok' => true]);
}

if ($action === 'read_all') {
    $db->prepare('UPDATE notifications SET is_read=1,read_at=? WHERE recipient_id=? AND is_read=0')
        ->execute([Db::utcNow(), $uid]);
    Response::json(['ok' => true]);
}

if ($action === 'send') {
    Guard::role($claims, 'admin');
    $target = (string) ($body['target'] ?? 'user'); // user | role | all
    $title = trim((string) ($body['title'] ?? 'Сообщение от службы такси'));
    $message = trim((string) ($body['message'] ?? ''));
    $channel = in_array(($body['channel'] ?? ''), ['in_app', 'sms'], true)
        ? (string) $body['channel'] : 'in_app';
    if ($message === '') Response::error('Введите текст сообщения');

    $count = 0;
    if ($target === 'user') {
        $recipientId = (string) ($body['recipientId'] ?? '');
        if ($recipientId === '') Response::error('Выберите получателя');
        NotificationService::create($db, $recipientId, 'AdminMessage', $title, $message, null, [], $channel, $uid);
        $count = 1;
    } elseif ($target === 'role') {
        $role = (string) ($body['role'] ?? 'client');
        if (!in_array($role, ['client', 'driver', 'operator'], true)) Response::error('Неизвестная роль');
        $count = NotificationService::sendToRole($db, $role, 'AdminMessage', $title, $message, null, [], $channel, $uid);
    } elseif ($target === 'all') {
        $count = NotificationService::sendBroadcast($db, 'AdminMessage', $title, $message, $channel, $uid);
    } else {
        Response::error('Неизвестный тип получателя');
    }
    Response::json(['ok' => true, 'sent' => $count, 'channel' => $channel]);
}

Response::error("Неизвестный action: $action");
