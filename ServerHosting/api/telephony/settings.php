<?php
// GET  /api/telephony/settings.php — настройки телефонии (secret не отдаётся)
// PUT  — изменение (admin) | POST {action:check_balance|flash_call}
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

$claims = Guard::claims();
Guard::role($claims, 'operator', 'admin');

$dto = function (array $s): array {
    return [
        'enabled' => (bool) $s['enabled'],
        'provider' => $s['provider'],
        'baseUrl' => $s['base_url'],
        'clientId' => $s['client_id'],
        'tokenConfigured' => trim((string) $s['api_token']) !== '',
        'callerNumber' => $s['caller_number'],
        'endpointCall' => $s['endpoint_call'],
        'endpointFlashCall' => $s['endpoint_flash_call'],
        'endpointBalance' => $s['endpoint_balance'],
        'webhookConfigured' => trim((string) $s['webhook_secret']) !== '',
        'callOnArrival' => (bool) $s['call_on_arrival'],
        'recordCalls' => (bool) $s['record_calls'],
        'balance' => $s['balance'] !== null ? (float) $s['balance'] : null,
        'balanceCheckedAt' => $s['balance_checked_at'],
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Response::json($dto(Telephony::settings($db)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = Response::requirePostJson();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'check_balance') {
        Guard::role($claims, 'admin');
        Response::json(Telephony::checkBalance($db));
    }
    if ($action === 'flash_call') {
        $phone = (string) ($body['phone'] ?? '');
        if ($phone === '') Response::error('Укажите телефон');
        Response::json(Telephony::flashCall($db, $phone, ['userId' => $claims['uid']]));
    }
    Response::error("Неизвестный action: $action");
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    Guard::role($claims, 'admin');
    $body = Response::requirePostJson();
    Response::json($dto(Telephony::update($db, $body)));
}

Response::error('Метод не поддерживается', 405);
