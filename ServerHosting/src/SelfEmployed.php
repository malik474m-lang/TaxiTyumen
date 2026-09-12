<?php
// Чеки самозанятых водителей: проверка статуса в ФНС и автоматическое
// формирование чека при выплате вознаграждения.
//
// ВАЖНО по закону: чек по НПД формирует САМ самозанятый, а не заказчик.
// Поэтому автоматическое формирование возможно только после того, как
// водитель добровольно привязал свой личный кабинет «Мой налог» и разрешил
// сервису создавать чеки от его имени. Без привязки водитель формирует чек
// сам, а система лишь подсказывает сумму и сохраняет ссылку.
//
// Источники:
//  - statusnpd.nalog.ru — официальный публичный API проверки статуса НПД
//    (бесплатно, без регистрации, ограничение 2 запроса в минуту);
//  - lknpd.nalog.ru — API личного кабинета «Мой налог» (используется при
//    добровольной привязке водителем своей учётной записи).
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class SelfEmployed
{
    public const STATUS_API = 'https://statusnpd.nalog.ru/api/v1/tracker/taxpayer_status';
    public const LKNPD_API = 'https://lknpd.nalog.ru/api/v1';

    /** Сколько секунд считаем проверку статуса свежей (сутки). */
    public const STATUS_TTL = 86400;

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS self_employed_accounts (
                driver_id       CHAR(36) PRIMARY KEY,
                inn             VARCHAR(12) NOT NULL,
                display_name    VARCHAR(160) NOT NULL DEFAULT '',
                refresh_token   TEXT NULL,
                device_id       VARCHAR(40) NOT NULL DEFAULT '',
                auto_receipt    TINYINT(1) NOT NULL DEFAULT 0,
                linked_at       DATETIME NULL,
                last_error      VARCHAR(500) NULL,
                npd_status      TINYINT(1) NULL,
                npd_checked_at  DATETIME NULL,
                npd_message     VARCHAR(255) NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NULL,
                FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
                INDEX (inn)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS self_employed_receipts (
                id             CHAR(36) PRIMARY KEY,
                driver_id      CHAR(36) NOT NULL,
                withdrawal_id  CHAR(36) NULL,
                amount         DECIMAL(12,2) NOT NULL,
                receipt_uuid   VARCHAR(60) NULL,
                print_url      VARCHAR(500) NULL,
                json_url       VARCHAR(500) NULL,
                status         ENUM('created','manual','failed','cancelled') NOT NULL DEFAULT 'created',
                source         ENUM('auto','manual') NOT NULL DEFAULT 'auto',
                error          VARCHAR(500) NULL,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                cancelled_at   DATETIME NULL,
                INDEX (driver_id), INDEX (withdrawal_id), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // ── Шифрование токена ───────────────────────────────────────────────────

    private static function encrypt(string $value): string
    {
        $key = hash('sha256', AUTH_SECRET . '|npd', true);
        $iv = random_bytes(16);
        $data = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $data);
    }

    private static function decrypt(?string $value): string
    {
        if (!$value) return '';
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 17) return '';
        $key = hash('sha256', AUTH_SECRET . '|npd', true);
        $out = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $out === false ? '' : $out;
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    /** @return array{code:int,json:?array,raw:string} */
    private static function http(string $url, string $method, ?array $body, ?string $token = null): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json',
            'User-Agent: TaxiTyumen/1.0 (+' . PUBLIC_BASE_URL . ')'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;

        $started = microtime(true);
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $h, $m)) $code = (int) $m[1];
        }
        return [
            'code' => $code,
            'json' => $raw !== false ? json_decode($raw, true) : null,
            'raw' => (string) $raw,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    private static function log(\PDO $db, string $action, string $summary, bool $ok, int $code, string $response, int $ms): void
    {
        try {
            $db->prepare(
                'INSERT INTO service_call_logs(service,action,request_summary,status,http_code,response_body,duration_ms)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute(['fns-npd', $action, $summary, $ok ? 'success' : 'failed', $code,
                mb_substr($response, 0, 3000), $ms]);
        } catch (\Throwable) {
        }
    }

    // ── Проверка статуса самозанятого (официальный API ФНС) ─────────────────

    /**
     * Проверить, является ли ИНН плательщиком НПД на дату.
     * Бесплатный публичный сервис ФНС, ограничение ~2 запроса в минуту.
     *
     * @return array{status:?bool,message:string,checked:bool}
     */
    public static function checkStatus(\PDO $db, string $inn, ?string $date = null): array
    {
        $inn = preg_replace('/\D/', '', $inn) ?? '';
        if (strlen($inn) !== 12) {
            return ['status' => null, 'message' => 'ИНН самозанятого должен содержать 12 цифр', 'checked' => false];
        }
        $date ??= gmdate('Y-m-d', time() + (int) CITY_UTC_OFFSET * 3600);

        $r = self::http(self::STATUS_API, 'POST', ['inn' => $inn, 'requestDate' => $date]);
        $json = $r['json'];
        $ok = $r['code'] >= 200 && $r['code'] < 300 && is_array($json) && array_key_exists('status', $json);
        self::log($db, 'check-status', 'inn=' . $inn, $ok, $r['code'], $r['raw'], $r['ms']);

        if (!$ok) {
            return [
                'status' => null,
                'checked' => false,
                'message' => $r['code'] === 0
                    ? 'Сервис ФНС недоступен (проверьте доступ сервера в интернет)'
                    : 'ФНС ответила ' . $r['code'] . '. Возможно, превышен лимит (2 запроса в минуту).',
            ];
        }
        return [
            'status' => (bool) $json['status'],
            'checked' => true,
            'message' => (string) ($json['message'] ?? ''),
        ];
    }

    /** Проверка с кэшем на сутки — чтобы не упираться в лимит ФНС. */
    public static function statusCached(\PDO $db, string $driverId, string $inn, bool $force = false): array
    {
        self::ensureTables($db);
        $stmt = $db->prepare('SELECT npd_status,npd_checked_at,npd_message FROM self_employed_accounts WHERE driver_id=?');
        $stmt->execute([$driverId]);
        $row = $stmt->fetch();

        if (!$force && $row && $row['npd_checked_at']) {
            $age = time() - strtotime((string) $row['npd_checked_at'] . ' UTC');
            if ($age < self::STATUS_TTL && $row['npd_status'] !== null) {
                return [
                    'status' => (bool) $row['npd_status'],
                    'checked' => true,
                    'cached' => true,
                    'message' => (string) ($row['npd_message'] ?? ''),
                ];
            }
        }

        $result = self::checkStatus($db, $inn);
        if ($result['checked']) {
            $db->prepare(
                'INSERT INTO self_employed_accounts (driver_id,inn,npd_status,npd_checked_at,npd_message)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE npd_status=VALUES(npd_status),
                   npd_checked_at=VALUES(npd_checked_at),npd_message=VALUES(npd_message),updated_at=NOW()'
            )->execute([$driverId, $inn, $result['status'] ? 1 : 0, Db::utcNow(), mb_substr($result['message'], 0, 255)]);
        }
        $result['cached'] = false;
        return $result;
    }

    // ── Привязка личного кабинета «Мой налог» ───────────────────────────────

    private static function deviceInfo(string $deviceId): array
    {
        return [
            'sourceDeviceId' => $deviceId,
            'sourceType' => 'WEB',
            'appVersion' => '1.0.0',
            'metaDetails' => ['userAgent' => 'Mozilla/5.0 (TaxiTyumen)'],
        ];
    }

    /**
     * Привязка кабинета водителя по ИНН и паролю от lknpd.nalog.ru.
     * Пароль НЕ сохраняется: хранится только refresh-токен в шифрованном виде.
     */
    public static function link(\PDO $db, string $driverId, string $inn, string $password): array
    {
        self::ensureTables($db);
        $inn = preg_replace('/\D/', '', $inn) ?? '';
        if (strlen($inn) !== 12) throw new \RuntimeException('ИНН должен содержать 12 цифр');
        if ($password === '') throw new \RuntimeException('Укажите пароль от кабинета «Мой налог»');

        $deviceId = bin2hex(random_bytes(10));
        $r = self::http(self::LKNPD_API . '/auth/lkfl', 'POST', [
            'username' => $inn,
            'password' => $password,
            'deviceInfo' => self::deviceInfo($deviceId),
        ]);
        $json = $r['json'];
        $ok = $r['code'] >= 200 && $r['code'] < 300 && !empty($json['refreshToken']);
        self::log($db, 'link', 'inn=' . $inn, $ok, $r['code'], $r['raw'], $r['ms']);

        if (!$ok) {
            $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
            throw new \RuntimeException($message !== ''
                ? 'ФНС: ' . $message
                : 'Не удалось войти в «Мой налог». Проверьте ИНН и пароль от lknpd.nalog.ru.');
        }

        $name = (string) ($json['profile']['displayName'] ?? '');
        $db->prepare(
            'INSERT INTO self_employed_accounts
             (driver_id,inn,display_name,refresh_token,device_id,auto_receipt,linked_at,last_error)
             VALUES (?,?,?,?,?,1,?,NULL)
             ON DUPLICATE KEY UPDATE inn=VALUES(inn),display_name=VALUES(display_name),
               refresh_token=VALUES(refresh_token),device_id=VALUES(device_id),
               auto_receipt=1,linked_at=VALUES(linked_at),last_error=NULL,updated_at=NOW()'
        )->execute([
            $driverId, $inn, mb_substr($name, 0, 160),
            self::encrypt((string) $json['refreshToken']), $deviceId, Db::utcNow(),
        ]);

        // Сразу проверяем статус НПД официальным сервисом ФНС
        self::statusCached($db, $driverId, $inn, true);
        return ['inn' => $inn, 'displayName' => $name];
    }

    public static function unlink(\PDO $db, string $driverId): void
    {
        self::ensureTables($db);
        $db->prepare(
            'UPDATE self_employed_accounts SET refresh_token=NULL,auto_receipt=0,updated_at=NOW() WHERE driver_id=?'
        )->execute([$driverId]);
    }

    public static function account(\PDO $db, string $driverId): ?array
    {
        self::ensureTables($db);
        $stmt = $db->prepare('SELECT * FROM self_employed_accounts WHERE driver_id=? LIMIT 1');
        $stmt->execute([$driverId]);
        return $stmt->fetch() ?: null;
    }

    /** Свежий access-токен по сохранённому refresh-токену. */
    private static function accessToken(\PDO $db, array $account): string
    {
        $refresh = self::decrypt($account['refresh_token'] ?? null);
        if ($refresh === '') throw new \RuntimeException('Кабинет «Мой налог» не привязан');

        $r = self::http(self::LKNPD_API . '/auth/token', 'POST', [
            'deviceInfo' => self::deviceInfo((string) $account['device_id']),
            'refreshToken' => $refresh,
        ]);
        $json = $r['json'];
        $ok = $r['code'] >= 200 && $r['code'] < 300 && !empty($json['token']);
        self::log($db, 'refresh-token', 'driver=' . $account['driver_id'], $ok, $r['code'], $r['raw'], $r['ms']);

        if (!$ok) {
            $db->prepare('UPDATE self_employed_accounts SET last_error=?,updated_at=NOW() WHERE driver_id=?')
                ->execute(['Сессия «Мой налог» истекла — нужна повторная привязка', $account['driver_id']]);
            throw new \RuntimeException('Сессия «Мой налог» истекла. Водителю нужно привязать кабинет заново.');
        }
        // ФНС может выдать новый refresh-токен — сохраняем его
        if (!empty($json['refreshToken'])) {
            $db->prepare('UPDATE self_employed_accounts SET refresh_token=?,updated_at=NOW() WHERE driver_id=?')
                ->execute([self::encrypt((string) $json['refreshToken']), $account['driver_id']]);
        }
        return (string) $json['token'];
    }

    // ── Формирование чека ───────────────────────────────────────────────────

    /** Локальное время города в формате ISO с часовым поясом. */
    private static function localTime(): string
    {
        $offset = (int) CITY_UTC_OFFSET;
        $sign = $offset >= 0 ? '+' : '-';
        return gmdate('Y-m-d\TH:i:s', time() + $offset * 3600)
            . sprintf('%s%02d:00', $sign, abs($offset));
    }

    /**
     * Создать чек самозанятого на сумму выплаты.
     * Плательщик — ИП сервиса (юридическое лицо), поэтому в чеке указывается
     * его ИНН и название: именно такой чек принимается к учёту расходов.
     *
     * @return array{ok:bool,receiptUuid:?string,printUrl:?string,error:?string}
     */
    public static function createReceipt(
        \PDO $db, string $driverId, float $amount, ?string $withdrawalId = null, string $serviceName = ''
    ): array {
        self::ensureTables($db);
        $id = Db::uuid();
        $account = self::account($db, $driverId);

        $fail = static function (string $error) use ($db, $id, $driverId, $withdrawalId, $amount): array {
            $db->prepare(
                'INSERT INTO self_employed_receipts (id,driver_id,withdrawal_id,amount,status,source,error)
                 VALUES (?,?,?,?,\'failed\',\'auto\',?)'
            )->execute([$id, $driverId, $withdrawalId, $amount, mb_substr($error, 0, 500)]);
            return ['ok' => false, 'receiptUuid' => null, 'printUrl' => null, 'error' => $error];
        };

        if (!$account || empty($account['refresh_token']) || (int) $account['auto_receipt'] !== 1) {
            return $fail('Водитель не привязал кабинет «Мой налог» — чек нужно получить вручную');
        }

        // Перед чеком проверяем статус в ФНС: снятый с учёта самозанятый
        // не вправе выдать чек, а выплата ему потребует НДФЛ и взносов.
        $status = self::statusCached($db, $driverId, (string) $account['inn']);
        if ($status['checked'] && $status['status'] === false) {
            return $fail('ФНС: ИНН не числится плательщиком НПД — выплата как самозанятому невозможна');
        }

        try {
            $token = self::accessToken($db, $account);
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }

        $service = ServiceSettings::get($db);
        $payerName = trim((string) ($service['service_name'] ?? 'Сервис такси'));
        $payerInn = preg_replace('/\D/', '', (string) (defined('COMPANY_INN') ? COMPANY_INN : '')) ?? '';
        $name = $serviceName !== '' ? $serviceName : 'Услуги по перевозке пассажиров';
        $now = self::localTime();

        $client = ['contactPhone' => null, 'displayName' => null, 'incomeType' => 'FROM_INDIVIDUAL', 'inn' => null];
        // ИНН ИП превращает чек в документ для юрлица: только такой чек
        // принимается к расходам заказчика
        if (strlen($payerInn) === 10 || strlen($payerInn) === 12) {
            $client = [
                'contactPhone' => null,
                'displayName' => $payerName,
                'incomeType' => 'FROM_LEGAL_ENTITY',
                'inn' => $payerInn,
            ];
        }

        $r = self::http(self::LKNPD_API . '/income', 'POST', [
            'paymentType' => 'ACCOUNT',
            'ignoreMaxTotalIncomeRestriction' => false,
            'client' => $client,
            'requestTime' => $now,
            'operationTime' => $now,
            'services' => [[
                'name' => mb_substr($name, 0, 200),
                'amount' => round($amount, 2),
                'quantity' => 1,
            ]],
            'totalAmount' => round($amount, 2),
        ], $token);

        $json = $r['json'];
        $uuid = is_array($json) ? (string) ($json['approvedReceiptUuid'] ?? '') : '';
        $ok = $r['code'] >= 200 && $r['code'] < 300 && $uuid !== '';
        self::log($db, 'create-receipt', 'driver=' . $driverId . ' sum=' . $amount, $ok, $r['code'], $r['raw'], $r['ms']);

        if (!$ok) {
            $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
            return $fail($message !== '' ? 'ФНС: ' . $message : 'ФНС не приняла чек (код ' . $r['code'] . ')');
        }

        $printUrl = self::LKNPD_API . '/receipt/' . $account['inn'] . '/' . $uuid . '/print';
        $jsonUrl = self::LKNPD_API . '/receipt/' . $account['inn'] . '/' . $uuid . '/json';
        $db->prepare(
            'INSERT INTO self_employed_receipts
             (id,driver_id,withdrawal_id,amount,receipt_uuid,print_url,json_url,status,source)
             VALUES (?,?,?,?,?,?,?,\'created\',\'auto\')'
        )->execute([$id, $driverId, $withdrawalId, $amount, $uuid, $printUrl, $jsonUrl]);

        return ['ok' => true, 'receiptUuid' => $uuid, 'printUrl' => $printUrl, 'error' => null];
    }

    /** Ручная регистрация чека, который водитель сформировал сам. */
    public static function saveManualReceipt(
        \PDO $db, string $driverId, float $amount, string $receipt, ?string $withdrawalId = null
    ): string {
        self::ensureTables($db);
        $receipt = trim($receipt);
        if ($receipt === '') throw new \RuntimeException('Укажите номер или ссылку на чек');

        // Из ссылки вида .../receipt/ИНН/UUID/print достаём номер чека
        $uuid = $receipt;
        if (preg_match('#/receipt/\d+/([A-Za-z0-9]+)/#', $receipt, $m)) $uuid = $m[1];

        $id = Db::uuid();
        $db->prepare(
            'INSERT INTO self_employed_receipts
             (id,driver_id,withdrawal_id,amount,receipt_uuid,print_url,status,source)
             VALUES (?,?,?,?,?,?,\'manual\',\'manual\')'
        )->execute([
            $id, $driverId, $withdrawalId, $amount, mb_substr($uuid, 0, 60),
            str_starts_with($receipt, 'http') ? mb_substr($receipt, 0, 500) : null,
        ]);
        return $id;
    }

    /** Отмена чека (например, при возврате выплаты). */
    public static function cancelReceipt(\PDO $db, string $receiptId, string $reason = 'Чек сформирован ошибочно'): bool
    {
        self::ensureTables($db);
        $stmt = $db->prepare('SELECT * FROM self_employed_receipts WHERE id=? LIMIT 1');
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch();
        if (!$receipt || $receipt['status'] !== 'created' || empty($receipt['receipt_uuid'])) return false;

        $account = self::account($db, (string) $receipt['driver_id']);
        if (!$account) return false;

        try {
            $token = self::accessToken($db, $account);
        } catch (\Throwable) {
            return false;
        }

        $now = self::localTime();
        $r = self::http(self::LKNPD_API . '/cancel', 'POST', [
            'receiptUuid' => $receipt['receipt_uuid'],
            'comment' => $reason,
            'operationTime' => $now,
            'requestTime' => $now,
            'partnerCode' => null,
        ], $token);
        $ok = $r['code'] >= 200 && $r['code'] < 300;
        self::log($db, 'cancel-receipt', (string) $receipt['receipt_uuid'], $ok, $r['code'], $r['raw'], $r['ms']);

        if ($ok) {
            $db->prepare("UPDATE self_employed_receipts SET status='cancelled',cancelled_at=? WHERE id=?")
                ->execute([Db::utcNow(), $receiptId]);
        }
        return $ok;
    }

    /** Чеки водителя для приложения и админки. */
    public static function receipts(\PDO $db, string $driverId, int $limit = 30): array
    {
        self::ensureTables($db);
        $stmt = $db->prepare(
            "SELECT * FROM self_employed_receipts WHERE driver_id=? ORDER BY created_at DESC LIMIT $limit"
        );
        $stmt->execute([$driverId]);
        return $stmt->fetchAll();
    }
}
