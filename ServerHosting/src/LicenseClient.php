<?php
// Клиент лицензии на сервере такси: проверка ключа раз в сутки.
// Если лицензия истекла — API и админка перестают работать, остаётся
// только страница ввода нового ключа.
declare(strict_types=1);

final class LicenseClient
{
    private const CHECK_INTERVAL = 86400; // раз в сутки

    private static ?array $cached = null;

    private static function db(): PDO
    {
        return Db::pdo();
    }

    public static function ensureTable(): void
    {
        self::db()->exec(
            "CREATE TABLE IF NOT EXISTS license_state (
                id           TINYINT PRIMARY KEY DEFAULT 1,
                license_key  VARCHAR(64) NOT NULL DEFAULT '',
                status       ENUM('valid','expired','invalid','suspended','unchecked') NOT NULL DEFAULT 'unchecked',
                expires_at   DATETIME NULL,
                plan         VARCHAR(20) NOT NULL DEFAULT '',
                max_drivers  INT NOT NULL DEFAULT 0,
                customer_name VARCHAR(160) NOT NULL DEFAULT '',
                last_check_at DATETIME NULL,
                last_attempt_at DATETIME NULL,
                last_reason  VARCHAR(255) NOT NULL DEFAULT '',
                updated_at   DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::db()->exec('INSERT IGNORE INTO license_state (id) VALUES (1)');
        $col = self::db()->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_state'
               AND COLUMN_NAME='last_attempt_at'"
        )->fetchColumn();
        if ((int) $col === 0) {
            self::db()->exec(
                'ALTER TABLE license_state ADD COLUMN last_attempt_at DATETIME NULL AFTER last_check_at'
            );
        }
    }

    /** Ключ из конфига или из БД (введён через админку). */
    public static function key(): string
    {
        $key = defined('LICENSE_KEY') ? (string) LICENSE_KEY : '';
        if ($key !== '') return $key;
        try {
            return (string) self::db()->query(
                'SELECT license_key FROM license_state WHERE id=1'
            )->fetchColumn();
        } catch (Throwable) { return ''; }
    }

    /** Сохранить ключ (ввод через админку или install.php). */
    public static function setKey(string $key): void
    {
        self::ensureTable();
        $key = strtoupper(trim($key));
        self::db()->prepare(
            "UPDATE license_state SET license_key=?,status='unchecked',expires_at=NULL,
             plan='',max_drivers=0,customer_name='',last_check_at=NULL,last_attempt_at=NULL,last_reason='',
             updated_at=NOW() WHERE id=1"
        )->execute([$key]);
        self::$cached = null;
    }

    /** Текущее состояние лицензии (кешируется на время запроса). */
    public static function status(): array
    {
        if (self::$cached !== null) return self::$cached;

        self::ensureTable();
        $row = self::db()->query('SELECT * FROM license_state WHERE id=1')->fetch() ?: [];

        $result = [
            'status' => $row['status'] ?? 'unchecked',
            'expiresAt' => $row['expires_at'] ?? null,
            'plan' => $row['plan'] ?? '',
            'maxDrivers' => (int) ($row['max_drivers'] ?? 0),
            'customerName' => $row['customer_name'] ?? '',
            'lastCheckAt' => $row['last_check_at'] ?? null,
            'lastAttemptAt' => $row['last_attempt_at'] ?? null,
            'lastReason' => $row['last_reason'] ?? '',
        ];
        self::$cached = $result;
        return $result;
    }

    public static function isValid(): bool
    {
        return self::status()['status'] === 'valid';
    }

    /** Запрет добавления водителей сверх тарифа лицензии. */
    public static function assertDriverCapacity(): void
    {
        $license = self::status();
        if ($license['status'] !== 'valid') {
            throw new RuntimeException('Лицензия сервера недействительна');
        }
        $max = (int) ($license['maxDrivers'] ?? 0);
        if ($max <= 0) return; // 0 — лимит не задан сервером лицензий

        $count = (int) self::db()->query(
            "SELECT COUNT(*) FROM drivers d
             JOIN users u ON u.id=d.user_id
             WHERE u.is_archived=0"
        )->fetchColumn();
        if ($count >= $max) {
            throw new RuntimeException(
                "Достигнут лимит лицензии: $max водителей. Продлите тариф или увеличьте лимит."
            );
        }
    }

    /**
     * Проверить лицензию на сервере лицензий.
     * Вызывается раз в сутки; при недоступности сервера лицензий
     * действует льготный период 3 дня (grace period).
     */
    public static function check(bool $activate = false): array
    {
        self::ensureTable();
        // Отдельно отмечаем попытку: при сетевом сбое не меняем время
        // последней УСПЕШНОЙ проверки, иначе льготный период продлевался бы сам.
        self::db()->exec('UPDATE license_state SET last_attempt_at=NOW() WHERE id=1');
        self::$cached = null;
        $key = self::key();
        if ($key === '') {
            self::saveState('invalid', '', '', 0, '', 'Ключ лицензии не задан');
            return self::status();
        }

        $server = defined('LICENSE_SERVER') ? (string) LICENSE_SERVER : '';
        if ($server === '') $server = 'https://taxi.license-prog.ru';
        $server = rtrim($server, '/');

        $domain = strtolower((string) (parse_url(PUBLIC_BASE_URL, PHP_URL_HOST) ?: ''));
        $action = $activate ? 'activate' : 'check';
        $url = $server . '/license-api.php?action=' . $action;
        $payload = json_encode(['key' => $key, 'domain' => $domain], JSON_UNESCAPED_SLASHES);

        // POST: лицензионный ключ не попадает в URL, историю браузера
        // и access-логи прокси/хостинга.
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "User-Agent: TaxiTyumen/1.0\r\n"
                . "Accept: application/json\r\nContent-Type: application/json\r\n",
            'content' => $payload,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $httpCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                $httpCode = (int) $m[1];
            }
        }
        $json = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($json) || $httpCode >= 500 || $httpCode === 0) {
            // Сервер лицензий недоступен/не настроен — льготный период
            $current = self::status();
            $lastCheck = $current['lastCheckAt'];
            $grace = $lastCheck && (time() - strtotime($lastCheck . ' UTC')) < (3 * 86400);
            if ($grace && $current['status'] === 'valid') {
                self::db()->prepare(
                    'UPDATE license_state SET last_reason=?,updated_at=NOW() WHERE id=1'
                )->execute(['Сервер лицензий недоступен — льготный период']);
                self::$cached = null;
                return self::status();
            }
            self::db()->prepare(
                "UPDATE license_state SET status='unchecked',last_reason=?,updated_at=NOW() WHERE id=1"
            )->execute(['Сервер лицензий недоступен — льготный период закончился']);
            self::$cached = null;
            return self::status();
        }

        if (!empty($json['valid'])) {
            self::saveState('valid', $json['expiresAt'] ?? '', $json['plan'] ?? '',
                (int) ($json['maxDrivers'] ?? 0), $json['customerName'] ?? '', '');
        } else {
            $reason = (string) ($json['reason'] ?? 'invalid');
            $message = (string) ($json['message'] ?? 'Лицензия недействительна');
            $statusMap = [
                'expired' => 'expired',
                'revoked' => 'invalid',
                'suspended' => 'suspended',
                'domain_mismatch' => 'invalid',
                'invalid_key' => 'invalid',
            ];
            self::saveState(
                $statusMap[$reason] ?? 'invalid',
                $json['expiredAt'] ?? '',
                '', 0, '', $message
            );
        }
        return self::status();
    }

    /** Проверка, нужна ли суточная проверка. Вызывается из _bootstrap.php. */
    public static function ensureChecked(): void
    {
        try {
            self::ensureTable();
            $row = self::db()->query(
                'SELECT last_check_at,last_attempt_at,status,last_reason FROM license_state WHERE id=1'
            )->fetch();

            // Действующая/невалидная лицензия: раз в сутки.
            // Сетевая ошибка (unchecked): повтор не чаще одного раза в час.
            $networkGrace = $row && strpos((string) ($row['last_reason'] ?? ''), 'льготный период') !== false;
            $interval = ($row && ($row['status'] === 'unchecked' || $networkGrace))
                ? 3600 : self::CHECK_INTERVAL;
            $lastAttempt = $row['last_attempt_at'] ?? null;
            $needsCheck = !$row || $lastAttempt === null
                || (time() - strtotime($lastAttempt . ' UTC')) >= $interval;

            // Даже отсутствие ключа фиксируем как invalid — сервер такси
            // не должен работать бессрочно в статусе unchecked.
            if ($needsCheck) self::check();
        } catch (Throwable) {
            // Ошибка БД лицензии не должна ломать API полностью —
            // проверка повторится при следующем запросе
        }
    }

    /** Принудительная проверка при вводе нового ключа. */
    public static function activateKey(string $key): array
    {
        self::setKey($key);
        return self::check(true);
    }

    private static function saveState(string $status, string $expiresAt,
        string $plan, int $maxDrivers, string $customerName, string $reason): void
    {
        self::db()->prepare(
            'UPDATE license_state SET status=?,expires_at=?,plan=?,max_drivers=?,
             customer_name=?,last_check_at=NOW(),last_reason=?,updated_at=NOW() WHERE id=1'
        )->execute([$status, $expiresAt ?: null, $plan, $maxDrivers, $customerName, $reason]);
        self::$cached = null;
    }

    /** Блокировка API при невалидной лицензии — вызывается из _bootstrap.php. */
    public static function enforce(): void
    {
        $status = self::status();

        // Истечение проверяем локально при каждом запросе: даже если последняя
        // удалённая проверка была сегодня, сервер блокируется ровно в expires_at.
        if ($status['status'] === 'valid' && $status['expiresAt']
            && strtotime($status['expiresAt'] . ' UTC') < time()) {
            self::saveState('expired', (string) $status['expiresAt'],
                (string) $status['plan'], (int) $status['maxDrivers'],
                (string) $status['customerName'], 'Срок лицензии истёк');
            $status = self::status();
        }

        if ($status['status'] === 'valid') return;

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Лицензия недействительна или истекла',
            'licenseStatus' => $status['status'],
            'licenseReason' => $status['lastReason'],
            'expiresAt' => $status['expiresAt'],
            'action' => 'Введите новый ключ лицензии в админке: раздел «Лицензия»',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
