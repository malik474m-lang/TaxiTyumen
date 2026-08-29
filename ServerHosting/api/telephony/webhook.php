<?php
// POST /api/telephony/webhook.php?secret=... — приём событий звонков от провайдера.
// Публичный эндпоинт: защита общим секретом из настроек телефонии.
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

$settings = Telephony::settings($db);
$secret = (string) $settings['webhook_secret'];
if ($secret !== '') {
    $provided = (string) ($_GET['secret'] ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
    if (!hash_equals($secret, $provided)) {
        Response::error('Неверный секрет вебхука', 403);
    }
}

$raw = file_get_contents('php://input') ?: '';
$event = json_decode($raw, true);
if (!is_array($event)) {
    $event = $_POST ?: [];
}
if (!$event) {
    Response::error('Пустое тело вебхука');
}

$result = Telephony::applyWebhook($db, $event);

// Событие «клиент не ответил» полезно операторам в реальном времени
$status = strtolower((string) ($event['status'] ?? $event['state'] ?? ''));
if (in_array($status, ['no_answer', 'noanswer', 'busy', 'failed', 'cancel'], true)) {
    try {
        NotificationService::sendToRole(
            $db, 'operator', 'CallFailed', 'Звонок не состоялся',
            sprintf('%s → %s: %s',
                (string) ($event['from'] ?? $event['src'] ?? '—'),
                (string) ($event['to'] ?? $event['dst'] ?? '—'),
                $status)
        );
    } catch (Throwable) {
    }
}

Bus::publish('telephony');
Response::json(['ok' => true] + $result);
