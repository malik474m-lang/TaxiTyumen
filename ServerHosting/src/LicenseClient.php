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
                last_reason  VARCHAR(255) NOT NULL DEFAULT '',
                updated_at   DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::db()->exec('INSERT IGNORE INTO license_state (id) VALUES (1)');
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
        self::db()->prepare(
            "UPDATE license_state SET license_key=?,status='unchecked',
             last_check_at=NULL,last_reason='',updated_at=NOW() WHERE id=1"
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
            'lastReason' => $row['last_reason'] ?? '',
        ];
        self::$cached = $result;
        return $result;
    }

    public static function isValid(): bool
    {
        return self::status()['status'] === 'valid';
    }

    /**
     * Проверить лицензию на сервере лицензий.
     * Вызывается раз в сутки; при недоступности сервера лицензий
     * действует льготный период 3 дня (grace period).
     */
    public static function check(): array
    {
        self::ensureTable();
        $key = self::key();
        if ($key === '') {
            self::saveState('invalid', '', '', 0, '', 'Ключ лицензии не задан');
            return self::status();
        }

        $server = defined('LICENSE_SERVER') ? (string) LICENSE_SERVER : '';
        if ($server === '') $server = 'https://taxi.license-prog.ru';
        $server = rtrim($server, '/');

        $domain = parse_url(PUBLIC_BASE_URL, PHP_URL_HOST) ?: '';
        $url = $server . '/license-api.php?action=check&key=' . rawurlencode($key)
            . '&domain=' . rawurlencode($domain);

        $ctx = stream_context_create(['http' => [
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "User-Agent: TaxiTyumen/1.0\r\nAccept: application/json\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $json = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($json)) {
            // Сервер лицензий недоступен — льготный период
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
            // Льготный период закончился — сервер блокируется до связи
            // с сервером лицензий или ввода нового корректного ключа.
            self::saveState('invalid', '', '', 0, '',
                'Сервер лицензий недоступен более 3 дней');
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
                'SELECT last_check_at, status FROM license_state WHERE id=1'
            )->fetch();

            $needsCheck = !$row
                || $row['last_check_at'] === null
                || (time() - strtotime($row['last_check_at'] . ' UTC')) >= self::CHECK_INTERVAL
                || $row['status'] !== 'valid';

            if ($needsCheck && self::key() !== '') {
                self::check();
            }
        } catch (Throwable) {
            // Ошибка БД лицензии не должна ломать API полностью —
            // проверка повторится при следующем запросе
        }
    }

    /**
     * Первая активация: не просто проверяет, а привязывает ключ к домену
     * на сервере лицензий. Раньше вызывался только check, и ключ с пустым
     * доменом можно было перенести на другой сервер.
     */
    public static function activateKey(string $key): array
    {
        self::setKey($key);
        $server = defined('LICENSE_SERVER') ? (string) LICENSE_SERVER : '';
        if ($server === '') $server = 'https://taxi.license-prog.ru';
        $domain = parse_url(PUBLIC_BASE_URL, PHP_URL_HOST) ?: '';
        $url = rtrim($server, '/') . '/license-api.php?action=activate';
        $payload = json_encode(['key' => $key, 'domain' => $domain],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n"
                . "Accept: application/json\r\n"
                . "User-Agent: TaxiTyumen/1.0\r\n",
            'content' => $payload,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $json = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($json)) {
            self::saveState('invalid', '', '', 0, '',
                'Сервер лицензий недоступен — ключ не активирован');
            return self::status();
        }
        if (!empty($json['valid'])) {
            self::saveState('valid', $json['expiresAt'] ?? '', $json['plan'] ?? '',
                (int) ($json['maxDrivers'] ?? 0), $json['customerName'] ?? '', '');
        } else {
            $reason = (string) ($json['reason'] ?? 'invalid');
            $status = $reason === 'expired' ? 'expired'
                : ($reason === 'suspended' ? 'suspended' : 'invalid');
            self::saveState($status, $json['expiredAt'] ?? '', '', 0, '',
                (string) ($json['message'] ?? 'Лицензия недействительна'));
        }
        return self::status();
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
        if ($status['status'] === 'valid' || $status['status'] === 'unchecked') return;

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Лицензия недействительна или истекла',
            'licenseStatus' => $status['status'],
            'licenseReason' => $status['lastReason'],
            'expiresAt' => $status['expiresAt'],
            'action' => 'Введите новый ключ лицензии в админке: Бренд сервиса → Лицензия',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
