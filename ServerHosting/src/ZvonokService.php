<?php
// Порт TaxiService.API/Services/AutoCallService.cs — реальный Zvonok.com API.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/NotificationService.php';

final class ZvonokService
{
    private const CALL_URL = 'https://zvonok.com/manager/cabapi_external/api/v1/phones/call/';
    private const BALANCE_URL = 'https://zvonok.com/manager/cabapi_external/api/v1/balance/';

    public static function callClientOnDriverArrived(\PDO $db, array $order): array
    {
        $settings = AutoCall::getSettings($db);
        $message = self::formatMessage($db, (string) ($settings['message_template'] ?? ''), $order, (int) ($settings['free_waiting_minutes'] ?? 5));

        // In-app уведомление отправляет NotificationService независимо от провайдера
        if (!(bool) $settings['enabled'] || ($settings['provider'] ?? '') !== 'zvonok') {
            self::log($db, 'call', $order['order_number'], 'skipped', null, 'Провайдер Zvonok выключен', 0);
            return ['status' => 'skipped', 'message' => $message];
        }
        if (empty($settings['zvonok_api_key']) || empty($settings['zvonok_campaign_id'])) {
            self::log($db, 'call', $order['order_number'], 'skipped', null, 'API-ключ или campaign_id не настроены', 0);
            return ['status' => 'skipped', 'message' => $message];
        }

        $phone = self::clientPhone($db, $order);
        if (!$phone) {
            self::log($db, 'call', $order['order_number'], 'failed', null, 'Телефон клиента не найден', 0);
            return ['status' => 'failed', 'message' => 'Телефон клиента не найден'];
        }

        $payload = http_build_query([
            'public_key' => $settings['zvonok_api_key'],
            'phone' => preg_replace('/\D/', '', Auth::normalizePhone($phone)),
            'campaign_id' => $settings['zvonok_campaign_id'],
            'text' => $message,
        ]);
        $started = microtime(true);
        [$code, $raw] = self::request(self::CALL_URL, 'POST', $payload, 'application/x-www-form-urlencoded');
        $duration = (int) round((microtime(true) - $started) * 1000);
        $ok = $code >= 200 && $code < 300;
        self::log($db, 'call', $order['order_number'] . ' / ' . $phone, $ok ? 'success' : 'failed', $code, $raw, $duration);

        // Журналируем звонок как уведомление конкретному клиенту
        if ($order['client_id']) {
            $db->prepare(
                "INSERT INTO notifications
                 (id,recipient_id,recipient_role,order_id,type,title,message,channel,delivery_status,provider_response)
                 VALUES (?,?,'client',?,'DriverArrivedCall','Автодозвон: такси прибыло',?,'call',?,?)"
            )->execute([
                Db::uuid(), $order['client_id'], $order['id'], $message,
                $ok ? 'sent' : 'failed', mb_substr($raw, 0, 5000),
            ]);
        }
        return ['status' => $ok ? 'sent' : 'failed', 'httpCode' => $code, 'response' => $raw, 'message' => $message];
    }

    public static function checkBalance(\PDO $db): array
    {
        $settings = AutoCall::getSettings($db);
        if (empty($settings['zvonok_api_key'])) {
            return ['configured' => false, 'ok' => false, 'balance' => 0, 'message' => 'Ключ Zvonok не настроен'];
        }
        $started = microtime(true);
        [$code, $raw] = self::request(
            self::BALANCE_URL . '?public_key=' . urlencode($settings['zvonok_api_key']),
            'GET'
        );
        $duration = (int) round((microtime(true) - $started) * 1000);
        $json = json_decode($raw, true);
        $balance = is_array($json) && isset($json['balance']) ? (float) $json['balance'] : null;
        $ok = $code >= 200 && $code < 300 && $balance !== null;
        if ($ok) {
            $db->prepare('UPDATE auto_call_settings SET zvonok_balance=?,balance_checked_at=? WHERE id=?')
                ->execute([$balance, Db::utcNow(), $settings['id']]);
        }
        self::log($db, 'balance', 'account', $ok ? 'success' : 'failed', $code, $raw, $duration);
        return [
            'configured' => true,
            'ok' => $ok,
            'balance' => $balance ?? 0,
            'message' => $ok ? 'Zvonok доступен' : 'Ошибка Zvonok API',
            'durationMs' => $duration,
            'response' => mb_substr($raw, 0, 1000),
        ];
    }

    public static function formatMessage(\PDO $db, string $template, array $order, int $freeMinutes): string
    {
        if ($template === '') {
            $template = 'Ваше такси прибыло! {CarColor} {CarBrand} {CarModel}, номер {LicensePlate}. Бесплатное ожидание: {FreeWaitingMinutes} минут.';
        }
        $driver = null;
        if ($order['driver_id']) {
            $stmt = $db->prepare(
                'SELECT d.*,u.first_name,u.last_name FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=? LIMIT 1'
            );
            $stmt->execute([$order['driver_id']]);
            $driver = $stmt->fetch() ?: null;
        }
        $clientName = $order['client_name'] ?: '';
        if (!$clientName && $order['client_id']) {
            $stmt = $db->prepare('SELECT first_name FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$order['client_id']]);
            $clientName = (string) ($stmt->fetchColumn() ?: '');
        }
        return strtr($template, [
            '{CarColor}' => $driver['car_color'] ?? '',
            '{CarBrand}' => $driver['car_brand'] ?? '',
            '{CarModel}' => $driver['car_model'] ?? '',
            '{LicensePlate}' => $driver['license_plate'] ?? '',
            '{FreeWaitingMinutes}' => (string) $freeMinutes,
            '{OrderNumber}' => (string) $order['order_number'],
            '{ClientName}' => $clientName,
        ]);
    }

    private static function clientPhone(\PDO $db, array $order): ?string
    {
        if (!empty($order['client_phone'])) return $order['client_phone'];
        if (!$order['client_id']) return null;
        $stmt = $db->prepare('SELECT phone FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$order['client_id']]);
        return $stmt->fetchColumn() ?: null;
    }

    private static function request(string $url, string $method, ?string $body = null, string $contentType = 'application/json'): array
    {
        $headers = "User-Agent: TaxiTyumen/1.0\r\n";
        if ($body !== null) {
            $headers .= 'Content-Type: ' . $contentType . "\r\nContent-Length: " . strlen($body) . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'method' => $method,
                'ignore_errors' => true,
                'header' => $headers,
                'content' => $body ?? '',
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, $raw !== false ? $raw : 'Ошибка соединения'];
    }

    private static function log(\PDO $db, string $action, string $summary, string $status, ?int $code, string $response, int $duration): void
    {
        try {
            $db->prepare(
                'INSERT INTO service_call_logs
                 (service,action,request_summary,status,http_code,response_body,duration_ms)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute(['zvonok', $action, mb_substr($summary, 0, 500), $status, $code, mb_substr($response, 0, 5000), $duration]);
        } catch (\Throwable) {
        }
    }
}
