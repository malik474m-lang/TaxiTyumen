<?php
// Единый SMS-сервис (sms.ru): отправка, результат и журнал внешних вызовов.
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/Db.php';

final class SmsService
{
    public static function send(\PDO $db, string $phone, string $message): array
    {
        $phone = Auth::normalizePhone($phone);

        // Встроенный шлюз (Android-телефон с SIM-картой) имеет приоритет:
        // сообщение кладётся в очередь, телефон заберёт его и отправит сам.
        try {
            if (SmsGateway::isEnabled($db)) {
                $id = SmsGateway::enqueue($db, $phone, $message, 'auto');
                self::log($db, 'send', $phone, 'success', null,
                    'Поставлено в очередь SMS-шлюза (' . $id . ')', 0);
                return ['status' => 'sent', 'response' => 'queued:' . $id, 'gateway' => 'device'];
            }
        } catch (\Throwable $e) {
            // Шлюз недоступен — молча уходим наsms.ru
            self::log($db, 'send', $phone, 'failed', null,
                'Ошибка SMS-шлюза: ' . $e->getMessage(), 0);
        }

        if (api_key('sms_ru') === '') {
            self::log($db, 'send', $phone, 'skipped', null,
                'SMS не отправлено: встроенный шлюз выключен и ключ sms.ru не задан', 0);
            return ['status' => 'skipped', 'response' => 'SMS_API_ID не настроен'];
        }

        $started = microtime(true);
        $url = 'https://sms.ru/sms/send?api_id=' . urlencode(api_key('sms_ru'))
            . '&to=' . urlencode($phone)
            . '&msg=' . urlencode($message)
            . '&json=1';
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 8,
                'method' => 'GET',
                'ignore_errors' => true,
                'header' => "User-Agent: TaxiTyumen/1.0\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = self::httpCode($http_response_header ?? []);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $ok = $raw !== false && $code >= 200 && $code < 300;
        $status = $ok ? 'sent' : 'failed';
        $response = $raw !== false ? mb_substr($raw, 0, 2000) : 'Ошибка соединения с sms.ru';
        self::log($db, 'send', $phone, $ok ? 'success' : 'failed', $code, $response, $duration);
        return ['status' => $status, 'response' => $response, 'httpCode' => $code];
    }

    public static function check(\PDO $db): array
    {
        // Включённый встроенный шлюз показываем как основной канал
        try {
            if (SmsGateway::isEnabled($db)) {
                $gw = SmsGateway::settings($db);
                $stats = SmsGateway::stats($db);
                return [
                    'configured' => true,
                    'ok' => (bool) $gw['online'],
                    'message' => $gw['online']
                        ? 'SMS-шлюз: телефон на связи · отправлено сегодня ' . $stats['sentToday']
                        : 'SMS-шлюз включён, но телефон не отвечает',
                    'balance' => null,
                ];
            }
        } catch (\Throwable) {
        }

        if (api_key('sms_ru') === '') {
            return ['configured' => false, 'ok' => false, 'message' => 'SMS_API_ID не настроен'];
        }
        $started = microtime(true);
        $url = 'https://sms.ru/my/balance?api_id=' . urlencode(api_key('sms_ru')) . '&json=1';
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = self::httpCode($http_response_header ?? []);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $json = $raw !== false ? json_decode($raw, true) : null;
        $ok = is_array($json) && ($json['status'] ?? '') === 'OK';
        self::log($db, 'balance', 'account', $ok ? 'success' : 'failed', $code, (string) $raw, $duration);
        return [
            'configured' => true,
            'ok' => $ok,
            'balance' => isset($json['balance']) ? (float) $json['balance'] : null,
            'message' => $ok ? 'sms.ru доступен' : 'Ошибка sms.ru',
            'durationMs' => $duration,
        ];
    }

    private static function httpCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    private static function log(\PDO $db, string $action, string $summary, string $status, ?int $code, string $response, int $duration): void
    {
        try {
            $db->prepare(
                'INSERT INTO service_call_logs
                 (service, action, request_summary, status, http_code, response_body, duration_ms)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute(['sms.ru', $action, $summary, $status, $code, mb_substr($response, 0, 5000), $duration]);
        } catch (\Throwable) {
        }
    }
}
