<?php
// Порт TaxiService.API/Services/AutoCallService.cs — реальный Zvonok.com API.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/NotificationService.php';

final class ZvonokService
{
    private const CALL_URL = 'https://zvonok.com/manager/cabapi_external/api/v1/phones/call/';
    private const CALL_STATUS_URL = 'https://zvonok.com/manager/cabapi_external/api/v1/phones/call_by_id/';
    // Из официальной документации (https://api.zvonok.com, раздел «Баланс»):
    // GET /manager/cabapi_external/api/v1/users/balance/?public_key=...
    private const BALANCE_URL = 'https://zvonok.com/manager/cabapi_external/api/v1/users/balance/';

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

        // Анти-дубль: Zvonok отклоняет звонки с duplicate_in_process, если номер уже на обзвоне.
        // Ограничиваем повторный вызов одного и того же номера в течение 2 минут.
        $recent = $db->prepare(
            "SELECT COUNT(*) FROM service_call_logs
             WHERE service='zvonok' AND action='call' AND summary LIKE ? AND created_at > ?"
        );
        $recent->execute(['%' . $phone . '%', gmdate('Y-m-d H:i:s', time() - 120)]);
        if ((int) $recent->fetchColumn() > 0) {
            self::log($db, 'call', $order['order_number'] . ' / ' . $phone, 'skipped', null,
                'Дубль для Zvonok: на этот номер уже звонили менее 2 минут назад', 0);
            return ['status' => 'skipped', 'message' => $message];
        }

        $payloadParams = [
            'public_key' => $settings['zvonok_api_key'],
            'phone' => preg_replace('/\D/', '', Auth::normalizePhone($phone)),
            'campaign_id' => $settings['zvonok_campaign_id'],
            'text' => $message,
        ];
        // Голос диктора (Zvonok speaker: Tatyana, Maxim и др.)
        $speaker = trim((string) ($settings['zvonok_speaker'] ?? ''));
        if ($speaker !== '') {
            $payloadParams['speaker'] = $speaker;
        }
        $payload = http_build_query($payloadParams);
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

    /**
     * Фактический статус звонка после постановки в очередь Zvonok.
     * HTTP 200 при создании означает только «принят»; dial_status показывает доставку.
     */
    public static function checkCallStatus(\PDO $db, string $callId): array
    {
        $settings = AutoCall::getSettings($db);
        if (empty($settings['zvonok_api_key'])) {
            return ['ok' => false, 'message' => 'Ключ Zvonok не настроен'];
        }
        $url = self::CALL_STATUS_URL
            . '?public_key=' . urlencode((string) $settings['zvonok_api_key'])
            . '&call_id=' . urlencode($callId)
            . '&expand=1';
        $started = microtime(true);
        [$code, $raw] = self::request($url, 'GET');
        $duration = (int) round((microtime(true) - $started) * 1000);
        $json = json_decode($raw, true);

        // V1 возвращает список даже при запросе одного call_id.
        $call = null;
        if (is_array($json)) {
            if (isset($json[0]) && is_array($json[0])) $call = $json[0];
            elseif (isset($json['data'][0]) && is_array($json['data'][0])) $call = $json['data'][0];
            elseif (isset($json['call_id'])) $call = $json;
        }
        $ok = $code >= 200 && $code < 300 && is_array($call);
        self::log($db, 'status', 'call_id ' . $callId, $ok ? 'success' : 'failed', $code, $raw, $duration);
        if (!$ok) {
            return [
                'ok' => false,
                'httpCode' => $code,
                'message' => self::errorReason($raw) ?? 'Не удалось получить статус звонка',
                'response' => mb_substr($raw, 0, 1000),
            ];
        }

        $dial = isset($call['dial_status']) ? (int) $call['dial_status'] : null;
        $attempts = isset($call['attempts']) && is_array($call['attempts'])
            ? $call['attempts']
            : [];
        $attempt = $attempts ? end($attempts) : null;
        return [
            'ok' => true,
            'callId' => (string) ($call['call_id'] ?? $callId),
            'phone' => (string) ($call['phone'] ?? ''),
            'status' => (string) ($call['status'] ?? ''),
            'dialStatus' => $dial,
            'dialStatusText' => self::dialStatusText($dial),
            'duration' => (int) ($call['duration'] ?? 0),
            'completed' => $call['completed'] ?? null,
            'cost' => is_array($attempt) ? ($attempt['cost'] ?? null) : null,
            'recordedAudio' => is_array($attempt) ? ($attempt['recorded_audio'] ?? null) : null,
            'message' => self::dialStatusText($dial),
        ];
    }

    /** Последний call_id, сохранённый в ответе API (боевой или тестовый звонок). */
    public static function latestCallId(\PDO $db): ?string
    {
        try {
            $rows = $db->query(
                "SELECT response_body FROM service_call_logs
                 WHERE service='zvonok' AND action IN ('call','test') AND status='success'
                 ORDER BY created_at DESC LIMIT 20"
            )->fetchAll();
            foreach ($rows as $row) {
                $json = json_decode((string) ($row['response_body'] ?? ''), true);
                if (is_array($json) && !empty($json['call_id'])) return (string) $json['call_id'];
                if (is_array($json) && !empty($json['data']['call_id'])) return (string) $json['data']['call_id'];
            }
        } catch (\Throwable) {
        }
        return null;
    }

    public static function dialStatusText(?int $code): string
    {
        return [
            0 => 'Ожидает вызова',
            1 => 'Ошибка вызова абонента',
            2 => 'Абонент сбросил звонок',
            3 => 'Не дозвонились (таймаут)',
            4 => 'Абонент занят',
            5 => 'Абонент ответил',
            6 => 'Ответил автоответчик',
            7 => 'Ответил автоответчик',
            8 => 'Некорректная кнопка',
            9 => 'Неизвестный статус',
            10 => 'Ролик завершён без действия',
            11 => 'Пользовательский стоп-лист',
            12 => 'Глобальный стоп-лист',
            13 => 'Ответил, но разговор слишком короткий',
            14 => 'Номер совпадает с Caller ID',
            15 => 'Номер удалён из обзвона',
            18 => 'Внутренняя ошибка Zvonok',
            23 => 'Попытка остановлена из-за отрицательного баланса',
            24 => 'Не найден телефонный транк',
            25 => 'Не найден исходящий номер',
            26 => 'Направление заблокировано',
            29 => 'Не найден провайдер для звонка',
            30 => 'Истекло время жизни звонка',
            31 => 'Попытки закончились',
            35 => 'Дублирующий звонок',
        ][$code ?? -1] ?? ('Статус Zvonok: ' . ($code ?? 'неизвестен'));
    }

    public static function formatMessage(\PDO $db, string $template, array $order, int $freeMinutes): string
    {
        if ($template === '') {
            $template = 'Ваше такси прибыло. Вас ожидает {CarColor} {CarBrand} {CarModel}. Государственный номер: {LicensePlate}. Бесплатное ожидание: {FreeWaitingMinutes} минут.';
        }
        $driver = null;
        if (!empty($order['driver_id'])) {
            $stmt = $db->prepare(
                'SELECT d.*,u.first_name,u.last_name FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=? LIMIT 1'
            );
            $stmt->execute([$order['driver_id']]);
            $driver = $stmt->fetch() ?: null;
        }
        $clientName = (string) ($order['client_name'] ?? '');
        if (!$clientName && !empty($order['client_id'])) {
            $stmt = $db->prepare('SELECT first_name FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$order['client_id']]);
            $clientName = (string) ($stmt->fetchColumn() ?: '');
        }

        $plateRaw = (string) ($driver['license_plate'] ?? '');
        $brandRaw = (string) ($driver['car_brand'] ?? '');
        $minutesText = self::minutesText($freeMinutes);

        $message = strtr($template, [
            '{CarColor}' => (string) ($driver['car_color'] ?? ''),
            '{CarBrand}' => self::speechCarBrand($brandRaw),
            '{CarModel}' => (string) ($driver['car_model'] ?? ''),
            '{LicensePlate}' => self::speechLicensePlate($plateRaw),
            '{LicensePlateRaw}' => $plateRaw,
            '{FreeWaitingMinutes}' => (string) $freeMinutes,
            '{FreeWaitingText}' => $minutesText,
            '{OrderNumber}' => (string) ($order['order_number'] ?? ''),
            '{ClientName}' => $clientName,
        ]);

        // Старые шаблоны содержат «3 минут» — исправляем склонение автоматически.
        $message = preg_replace(
            '/\b' . preg_quote((string) $freeMinutes, '/') . '\s+минут(?:а|ы)?\b/ui',
            $minutesText,
            $message
        ) ?? $message;
        return trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    }

    /** Правильное склонение: 1 минута, 2 минуты, 5 минут. */
    private static function minutesText(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $mod100 = $minutes % 100;
        $mod10 = $minutes % 10;
        if ($mod100 >= 11 && $mod100 <= 14) $word = 'минут';
        elseif ($mod10 === 1) $word = 'минута';
        elseif ($mod10 >= 2 && $mod10 <= 4) $word = 'минуты';
        else $word = 'минут';
        return $minutes . ' ' . $word;
    }

    /** Фонетическая запись российского госномера для синтезатора речи. */
    private static function speechLicensePlate(string $plate): string
    {
        $plate = mb_strtoupper(preg_replace('/[^A-ZА-ЯЁ0-9]/u', '', $plate) ?? '', 'UTF-8');
        if ($plate === '') return 'не указан';

        $letters = [
            'А'=>'а','В'=>'вэ','Е'=>'е','К'=>'ка','М'=>'эм','Н'=>'эн','О'=>'о',
            'Р'=>'эр','С'=>'эс','Т'=>'тэ','У'=>'у','Х'=>'ха',
            'A'=>'а','B'=>'вэ','E'=>'е','K'=>'ка','M'=>'эм','H'=>'эн','O'=>'о',
            'P'=>'эр','C'=>'эс','T'=>'тэ','Y'=>'у','X'=>'ха',
        ];
        $digits = ['0'=>'ноль','1'=>'один','2'=>'два','3'=>'три','4'=>'четыре',
            '5'=>'пять','6'=>'шесть','7'=>'семь','8'=>'восемь','9'=>'девять'];
        $chars = preg_split('//u', $plate, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $spoken = [];
        foreach ($chars as $char) $spoken[] = $digits[$char] ?? $letters[$char] ?? $char;

        if (preg_match('/^[A-ZА-ЯЁ]\d{3}[A-ZА-ЯЁ]{2}(\d{2,3})$/u', $plate, $m)) {
            $regionLength = strlen($m[1]);
            $bodyLength = count($spoken) - $regionLength;
            return implode(' ', array_slice($spoken, 0, $bodyLength))
                . ', регион ' . implode(' ', array_slice($spoken, $bodyLength));
        }
        return implode(' ', $spoken);
    }

    /** Русское произношение распространённых автомобильных марок. */
    private static function speechCarBrand(string $brand): string
    {
        $map = [
            'kia'=>'Киа','hyundai'=>'Хёндэ','renault'=>'Рено','volkswagen'=>'Фольксваген',
            'skoda'=>'Шкода','toyota'=>'Тойота','nissan'=>'Ниссан','chevrolet'=>'Шевроле',
            'ford'=>'Форд','chery'=>'Чери','haval'=>'Хавейл','geely'=>'Джили','exeed'=>'Эксид',
            'lada'=>'Лада','mazda'=>'Мазда','mitsubishi'=>'Митсубиси','mercedes'=>'Мерседес',
            'bmw'=>'Бэ-эм-вэ','audi'=>'Ауди','lexus'=>'Лексус','subaru'=>'Субару',
        ];
        $key = mb_strtolower(trim($brand), 'UTF-8');
        return $map[$key] ?? $brand;
    }

    private static function clientPhone(\PDO $db, array $order): ?string
    {
        if (!empty($order['client_phone'])) return $order['client_phone'];
        if (!$order['client_id']) return null;
        $stmt = $db->prepare('SELECT phone FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$order['client_id']]);
        return $stmt->fetchColumn() ?: null;
    }

    public static function request(string $url, string $method, ?string $body = null, string $contentType = 'application/json'): array    {
        return self::requestRaw($url, $method, $body, $contentType);
    }

    /** Двоичный разбор ответа Zvonok: отделяем ~ реальную ошибку {data} */
    public static function errorReason(string $raw): ?string
    {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            if (($j['status'] ?? '') === 'error' && isset($j['data'])) {
                return is_string($j['data']) ? $j['data'] : json_encode($j['data'], JSON_UNESCAPED_UNICODE);
            }
            if (isset($j['error'])) {
                return is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE);
            }
        }
        return null;
    }

    public static function requestRaw(string $url, string $method, ?string $body = null, string $contentType = 'application/json'): array
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

    public static function log(\PDO $db, string $action, string $summary, string $status, ?int $code, string $response, int $duration): void
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
