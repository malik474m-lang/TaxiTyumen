<?php
// Интеграция телефонии (Plusofon API v1 и совместимые провайдеры).
// Авторизация: Authorization: Bearer <token> + Client-ID.
// Пути методов настраиваются в админке — их значения берутся из ЛК провайдера.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';

final class Telephony
{
    public const DEFAULTS = [
        'enabled'            => 0,
        'provider'           => 'plusofon',
        'base_url'           => 'https://api.plusofon.ru/rest/v1',
        'client_id'          => '',
        'api_token'          => '',
        'caller_number'      => '',
        'endpoint_call'      => '/call/callback',
        'endpoint_flash_call' => '/flash-call/create',
        'endpoint_balance'   => '/customer/balance',
        'webhook_secret'     => '',
        'call_on_arrival'    => 0,
        'record_calls'       => 1,
    ];

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS telephony_settings (
                id CHAR(36) PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                provider VARCHAR(30) NOT NULL DEFAULT 'plusofon',
                base_url VARCHAR(255) NOT NULL DEFAULT 'https://api.plusofon.ru/rest/v1',
                client_id VARCHAR(120) NOT NULL DEFAULT '',
                api_token VARCHAR(255) NOT NULL DEFAULT '',
                caller_number VARCHAR(30) NOT NULL DEFAULT '',
                endpoint_call VARCHAR(120) NOT NULL DEFAULT '/call/callback',
                endpoint_flash_call VARCHAR(120) NOT NULL DEFAULT '/flash-call/create',
                endpoint_balance VARCHAR(120) NOT NULL DEFAULT '/customer/balance',
                webhook_secret VARCHAR(120) NOT NULL DEFAULT '',
                call_on_arrival TINYINT(1) NOT NULL DEFAULT 0,
                record_calls TINYINT(1) NOT NULL DEFAULT 1,
                balance DOUBLE NULL,
                balance_checked_at DATETIME NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS call_logs (
                id CHAR(36) PRIMARY KEY,
                provider VARCHAR(30) NOT NULL DEFAULT 'plusofon',
                scenario VARCHAR(40) NOT NULL DEFAULT 'manual',
                direction ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
                external_id VARCHAR(120) NULL,
                from_number VARCHAR(30) NULL,
                to_number VARCHAR(30) NULL,
                order_id CHAR(36) NULL,
                driver_id CHAR(36) NULL,
                user_id CHAR(36) NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'queued',
                duration INT NULL,
                record_url VARCHAR(500) NULL,
                payload MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX ext_idx (external_id), INDEX order_idx (order_id), INDEX created_idx (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function settings(\PDO $db): array
    {
        self::ensureTables($db);
        $row = $db->query('SELECT * FROM telephony_settings LIMIT 1')->fetch();
        if (!$row) {
            $db->prepare('INSERT INTO telephony_settings (id) VALUES (?)')->execute([Db::uuid()]);
            $row = $db->query('SELECT * FROM telephony_settings LIMIT 1')->fetch();
        }
        return array_merge(self::DEFAULTS, $row ?: []);
    }

    public static function update(\PDO $db, array $fields, bool $keepTokenIfEmpty = true): array
    {
        $s = self::settings($db);
        $token = trim((string) ($fields['apiToken'] ?? ''));
        $sql = 'UPDATE telephony_settings SET enabled=?, provider=?, base_url=?, client_id=?,
                caller_number=?, endpoint_call=?, endpoint_flash_call=?, endpoint_balance=?,
                webhook_secret=?, call_on_arrival=?, record_calls=?, updated_at=?';
        $params = [
            !empty($fields['enabled']) ? 1 : 0,
            mb_substr((string) ($fields['provider'] ?? $s['provider']), 0, 30),
            rtrim(mb_substr((string) ($fields['baseUrl'] ?? $s['base_url']), 0, 255), '/'),
            mb_substr((string) ($fields['clientId'] ?? $s['client_id']), 0, 120),
            Auth::normalizePhone((string) ($fields['callerNumber'] ?? $s['caller_number'])),
            mb_substr((string) ($fields['endpointCall'] ?? $s['endpoint_call']), 0, 120),
            mb_substr((string) ($fields['endpointFlashCall'] ?? $s['endpoint_flash_call']), 0, 120),
            mb_substr((string) ($fields['endpointBalance'] ?? $s['endpoint_balance']), 0, 120),
            mb_substr((string) ($fields['webhookSecret'] ?? $s['webhook_secret']), 0, 120),
            !empty($fields['callOnArrival']) ? 1 : 0,
            !empty($fields['recordCalls']) ? 1 : 0,
            Db::utcNow(),
        ];
        if ($token !== '' || !$keepTokenIfEmpty) {
            $sql .= ', api_token=?';
            $params[] = $token;
        }
        $sql .= ' WHERE id=?';
        $params[] = $s['id'];
        $db->prepare($sql)->execute($params);
        return self::settings($db);
    }

    public static function isConfigured(array $s): bool
    {
        return (int) $s['enabled'] === 1
            && trim((string) $s['api_token']) !== ''
            && trim((string) $s['caller_number']) !== '';
    }

    /**
     * Соединение двух абонентов (клиент ↔ водитель/оператор).
     * Провайдер сначала звонит первому номеру, затем соединяет со вторым.
     */
    public static function connect(
        \PDO $db,
        string $firstPhone,
        string $secondPhone,
        string $scenario = 'manual',
        array $context = []
    ): array {
        $s = self::settings($db);
        if (!self::isConfigured($s)) {
            return self::log($db, $s, $scenario, $firstPhone, $secondPhone, 'skipped',
                'Телефония выключена или не настроена', null, $context);
        }
        $from = Auth::normalizePhone($firstPhone);
        $to = Auth::normalizePhone($secondPhone);
        if (strlen(preg_replace('/\D/', '', $from)) < 11 || strlen(preg_replace('/\D/', '', $to)) < 11) {
            return self::log($db, $s, $scenario, $from, $to, 'failed', 'Некорректные номера', null, $context);
        }

        $payload = [
            'first_call'  => self::msisdn($from),
            'second_call' => self::msisdn($to),
            'src'         => self::msisdn((string) $s['caller_number']),
            'record'      => (bool) $s['record_calls'],
        ];
        [$code, $raw] = self::request($s, (string) $s['endpoint_call'], 'POST', $payload);
        $json = json_decode($raw, true);
        $ok = $code >= 200 && $code < 300;
        $externalId = is_array($json)
            ? (string) ($json['id'] ?? $json['call_id'] ?? $json['data']['id'] ?? '')
            : '';

        return self::log(
            $db, $s, $scenario, $from, $to,
            $ok ? 'queued' : 'failed',
            $raw, $externalId !== '' ? $externalId : null, $context, $code
        );
    }

    /** Flash Call — подтверждение номера входящим звонком (код = хвост номера). */
    public static function flashCall(\PDO $db, string $phone, array $context = []): array
    {
        $s = self::settings($db);
        if (!self::isConfigured($s)) {
            return ['status' => 'skipped', 'message' => 'Телефония выключена или не настроена'];
        }
        $to = Auth::normalizePhone($phone);
        [$code, $raw] = self::request($s, (string) $s['endpoint_flash_call'], 'POST', [
            'phone' => self::msisdn($to),
        ]);
        $json = json_decode($raw, true);
        $ok = $code >= 200 && $code < 300;
        self::log($db, $s, 'flash_call', (string) $s['caller_number'], $to,
            $ok ? 'queued' : 'failed', $raw, null, $context, $code);

        return [
            'status' => $ok ? 'sent' : 'failed',
            'httpCode' => $code,
            // Провайдер возвращает номер, с которого поступит звонок, и/или проверочный код
            'callerId' => is_array($json) ? ($json['phone'] ?? $json['data']['phone'] ?? null) : null,
            'code' => is_array($json) ? ($json['code'] ?? $json['data']['code'] ?? null) : null,
            'response' => mb_substr($raw, 0, 1000),
        ];
    }

    public static function checkBalance(\PDO $db): array
    {
        $s = self::settings($db);
        if (trim((string) $s['api_token']) === '') {
            return ['configured' => false, 'ok' => false, 'message' => 'API-токен не задан'];
        }
        $started = microtime(true);
        [$code, $raw] = self::request($s, (string) $s['endpoint_balance'], 'GET');
        $duration = (int) round((microtime(true) - $started) * 1000);
        $json = json_decode($raw, true);
        $balance = null;
        foreach (['balance', 'amount', 'sum'] as $key) {
            if (is_array($json) && isset($json[$key])) { $balance = (float) $json[$key]; break; }
            if (is_array($json) && isset($json['data'][$key])) { $balance = (float) $json['data'][$key]; break; }
        }
        $ok = $code >= 200 && $code < 300;
        if ($ok) {
            $db->prepare('UPDATE telephony_settings SET balance=?, balance_checked_at=? WHERE id=?')
                ->execute([$balance, Db::utcNow(), $s['id']]);
        }
        self::serviceLog($db, 'balance', 'account', $ok ? 'success' : 'failed', $code, $raw, $duration);
        return [
            'configured' => true,
            'ok' => $ok,
            'balance' => $balance,
            'durationMs' => $duration,
            'message' => $ok
                ? 'Телефония доступна'
                : ($code === 0
                    ? 'Нет соединения с API телефонии: ' . mb_substr($raw, 0, 200)
                    : ($code === 401 || $code === 403
                        ? 'Неверный API-токен или Client ID (HTTP ' . $code . ')'
                        : ($code === 404
                            ? 'Адрес метода неверный (HTTP 404) — нажмите «Диагностика подключения»'
                            : 'Ошибка API телефонии, HTTP ' . $code))),
            'response' => mb_substr($raw, 0, 800),
        ];
    }

    /** Обновление статуса звонка по вебхуку провайдера. */
    public static function applyWebhook(\PDO $db, array $event): array
    {
        self::ensureTables($db);
        $externalId = (string) ($event['id'] ?? $event['call_id'] ?? $event['uuid'] ?? '');
        $status = (string) ($event['status'] ?? $event['state'] ?? $event['event'] ?? 'unknown');
        $duration = isset($event['duration']) ? (int) $event['duration'] : null;
        $record = (string) ($event['record'] ?? $event['record_url'] ?? $event['recording'] ?? '');
        $from = (string) ($event['from'] ?? $event['src'] ?? $event['caller'] ?? '');
        $to = (string) ($event['to'] ?? $event['dst'] ?? $event['called'] ?? '');

        $existing = null;
        if ($externalId !== '') {
            $stmt = $db->prepare('SELECT * FROM call_logs WHERE external_id=? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$externalId]);
            $existing = $stmt->fetch() ?: null;
        }

        if ($existing) {
            $db->prepare(
                'UPDATE call_logs SET status=?, duration=?, record_url=?, payload=?, updated_at=? WHERE id=?'
            )->execute([
                mb_substr($status, 0, 40), $duration,
                $record !== '' ? mb_substr($record, 0, 500) : $existing['record_url'],
                json_encode($event, JSON_UNESCAPED_UNICODE), Db::utcNow(), $existing['id'],
            ]);
            return ['updated' => true, 'id' => $existing['id']];
        }

        $id = Db::uuid();
        $db->prepare(
            'INSERT INTO call_logs (id,provider,scenario,direction,external_id,from_number,to_number,
             status,duration,record_url,payload) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id, 'plusofon', 'webhook',
            !empty($event['direction']) && $event['direction'] === 'in' ? 'inbound' : 'outbound',
            $externalId !== '' ? $externalId : null,
            $from !== '' ? Auth::normalizePhone($from) : null,
            $to !== '' ? Auth::normalizePhone($to) : null,
            mb_substr($status, 0, 40), $duration,
            $record !== '' ? mb_substr($record, 0, 500) : null,
            json_encode($event, JSON_UNESCAPED_UNICODE),
        ]);
        return ['created' => true, 'id' => $id];
    }

    /** Формат номера для API: 79991234567 */
    private static function msisdn(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '8') && strlen($digits) === 11) {
            $digits = '7' . substr($digits, 1);
        }
        return $digits;
    }

    /** Заголовки авторизации Plusofon: Bearer-токен + идентификатор приложения. */
    private static function authHeaders(array $s): array
    {
        $headers = [
            'Authorization: Bearer ' . trim((string) $s['api_token']),
            'Accept: application/json',
            'User-Agent: TaxiService/1.0',
        ];
        $client = trim((string) ($s['client_id'] ?? ''));
        if ($client !== '') {
            $headers[] = 'Client: ' . $client;      // основной заголовок Plusofon
            $headers[] = 'Client-ID: ' . $client;   // совместимость со старым форматом
        }
        return $headers;
    }

    /**
     * HTTP-запрос к API телефонии. cURL — основной транспорт (работает на
     * хостингах с выключенным allow_url_fopen), file_get_contents — резерв.
     * Возвращает [httpCode, body]; при сетевой ошибке code=0 и текст причины.
     */
    private static function request(array $s, string $path, string $method, ?array $payload = null): array
    {
        $url = rtrim((string) $s['base_url'], '/') . '/' . ltrim($path, '/');
        $headers = self::authHeaders($s);

        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        // ── cURL ────────────────────────────────────────────────────────────
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                return [0, 'Сеть недоступна: ' . ($err !== '' ? $err : 'curl error')];
            }
            return [$code, (string) $raw];
        }

        // ── Резерв: file_get_contents ───────────────────────────────────────
        if (!ini_get('allow_url_fopen')) {
            return [0, 'На хостинге нет cURL и выключен allow_url_fopen — включите один из них в PHP-настройках'];
        }
        if ($body !== null) {
            $headers[] = 'Content-Length: ' . strlen($body);
        }
        $ctx = stream_context_create(['http' => [
            'timeout' => 15,
            'method' => $method,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body ?? '',
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                $code = (int) $m[1];
            }
        }
        if ($raw === false) {
            return [$code, 'Не удалось соединиться с ' . $url];
        }
        return [$code, (string) $raw];
    }

    /**
     * Диагностика подключения: перебирает известные базовые адреса и методы
     * баланса Plusofon и показывает, какая комбинация отвечает 2xx.
     * Позволяет настроить интеграцию без разработчика.
     */
    public static function diagnose(\PDO $db): array
    {
        $s = self::settings($db);
        $token = trim((string) $s['api_token']);
        $rows = [];

        $env = [
            'curl' => function_exists('curl_init'),
            'allowUrlFopen' => (bool) ini_get('allow_url_fopen'),
            'tokenSet' => $token !== '',
            'clientIdSet' => trim((string) $s['client_id']) !== '',
            'callerNumberSet' => trim((string) $s['caller_number']) !== '',
        ];
        if ($token === '') {
            return ['env' => $env, 'rows' => [], 'best' => null,
                'message' => 'Введите API-токен из личного кабинета Плюсофон и сохраните настройки'];
        }

        $bases = array_values(array_unique(array_filter([
            rtrim((string) $s['base_url'], '/'),
            'https://api.plusofon.ru/rest/v1',
            'https://restapi.plusofon.ru/api/v1',
            'https://api.plusofon.ru/api/v1',
        ])));
        $paths = array_values(array_unique(array_filter([
            (string) $s['endpoint_balance'],
            '/customer/balance',
            '/account/balance',
            '/balance',
        ])));

        $best = null;
        foreach ($bases as $base) {
            foreach ($paths as $path) {
                $probe = $s;
                $probe['base_url'] = $base;
                $started = microtime(true);
                [$code, $raw] = self::request($probe, $path, 'GET');
                $ms = (int) round((microtime(true) - $started) * 1000);
                $ok = $code >= 200 && $code < 300;
                $rows[] = [
                    'base' => $base,
                    'path' => $path,
                    'httpCode' => $code,
                    'ok' => $ok,
                    'durationMs' => $ms,
                    'response' => mb_substr(trim($raw), 0, 220),
                ];
                if ($ok && $best === null) {
                    $best = ['baseUrl' => $base, 'endpointBalance' => $path];
                }
            }
            if ($best !== null) break; // рабочая база найдена — дальше не перебираем
        }

        $message = $best !== null
            ? 'Подключение работает: ' . $best['baseUrl'] . $best['endpointBalance']
            : 'Ни один адрес не ответил успешно — проверьте токен, Client ID и доступ к api.plusofon.ru с хостинга';

        return ['env' => $env, 'rows' => $rows, 'best' => $best, 'message' => $message];
    }

    private static function log(
        \PDO $db, array $s, string $scenario, string $from, string $to,
        string $status, string $raw, ?string $externalId, array $context, int $httpCode = 0
    ): array {
        self::ensureTables($db);
        $id = Db::uuid();
        $db->prepare(
            'INSERT INTO call_logs (id,provider,scenario,direction,external_id,from_number,to_number,
             order_id,driver_id,user_id,status,payload) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id, (string) $s['provider'], $scenario, 'outbound', $externalId,
            $from, $to,
            $context['orderId'] ?? null, $context['driverId'] ?? null, $context['userId'] ?? null,
            $status, mb_substr($raw, 0, 5000),
        ]);
        self::serviceLog($db, $scenario, $from . ' → ' . $to,
            $status === 'failed' ? 'failed' : ($status === 'skipped' ? 'skipped' : 'success'),
            $httpCode, $raw, 0);
        return ['status' => $status, 'callLogId' => $id, 'externalId' => $externalId,
                'httpCode' => $httpCode, 'response' => mb_substr($raw, 0, 1000)];
    }

    private static function serviceLog(\PDO $db, string $action, string $summary, string $status, int $code, string $raw, int $ms): void
    {
        try {
            $db->prepare(
                'INSERT INTO service_call_logs (service,action,request_summary,status,http_code,response_body,duration_ms)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute(['telephony', $action, mb_substr($summary, 0, 500), $status, $code ?: null, mb_substr($raw, 0, 5000), $ms]);
        } catch (\Throwable) {
        }
    }
}
