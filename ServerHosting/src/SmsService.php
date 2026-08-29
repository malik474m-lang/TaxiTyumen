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
        if (SMS_API_ID === '') {
            self::log($db, 'send', $phone, 'skipped', null, 'SMS_API_ID не настроен', 0);
            return ['status' => 'skipped', 'response' => 'SMS_API_ID не настроен'];
        }

        $started = microtime(true);
        $url = 'https://sms.ru/sms/send?api_id=' . urlencode(SMS_API_ID)
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
        if (SMS_API_ID === '') {
            return ['configured' => false, 'ok' => false, 'message' => 'SMS_API_ID не настроен'];
        }
        $started = microtime(true);
        $url = 'https://sms.ru/my/balance?api_id=' . urlencode(SMS_API_ID) . '&json=1';
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
