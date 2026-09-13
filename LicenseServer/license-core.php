<?php
// Сервер лицензий TaxiTyumen: выдаёт, проверяет и отзывает лицензии на
// серверную часть такси. Размещается отдельно (taxi.license-prog.ru).
//
// Без composer, совместимо с PHP 7.4+ и MySQL/PDO. TOTP 2FA (RFC 6238) и защита
// от брутфорса — собственная реализация без внешних библиотек.
declare(strict_types=1);
date_default_timezone_set('UTC');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// ── Конфигурация ─────────────────────────────────────────────────────────
// Секреты храните в license.local.php (игнорируется Git)
$__local = __DIR__ . '/license.local.php';
if (is_file($__local)) require_once $__local;

if (!defined('LIC_DB_HOST')) define('LIC_DB_HOST', getenv('LIC_DB_HOST') ?: 'localhost');
if (!defined('LIC_DB_PORT')) define('LIC_DB_PORT', getenv('LIC_DB_PORT') ?: '3306');
if (!defined('LIC_DB_NAME')) define('LIC_DB_NAME', getenv('LIC_DB_NAME') ?: 'taxi_licenses');
if (!defined('LIC_DB_USER')) define('LIC_DB_USER', getenv('LIC_DB_USER') ?: 'root');
if (!defined('LIC_DB_PASS')) define('LIC_DB_PASS', getenv('LIC_DB_PASS') ?: '');
if (!defined('LIC_SECRET'))  define('LIC_SECRET',  getenv('LIC_SECRET')  ?: 'CHANGE-ME-64-CHARS');
if (!defined('LIC_ADMIN_USER')) define('LIC_ADMIN_USER', getenv('LIC_ADMIN_USER') ?: 'admin');
if (!defined('LIC_ADMIN_PASS_HASH')) define('LIC_ADMIN_PASS_HASH', getenv('LIC_ADMIN_PASS_HASH') ?: '');

// ── БД ────────────────────────────────────────────────────────────────────
function lic_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                LIC_DB_HOST, LIC_DB_PORT, LIC_DB_NAME),
            LIC_DB_USER, LIC_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function lic_ensure_tables(): void
{
    $db = lic_db();
    $db->exec("CREATE TABLE IF NOT EXISTS licenses (
        id                CHAR(36) PRIMARY KEY,
        license_key       VARCHAR(64) NOT NULL UNIQUE,
        domain            VARCHAR(255) NOT NULL,
        customer_name     VARCHAR(160) NOT NULL DEFAULT '',
        customer_email    VARCHAR(160) NOT NULL DEFAULT '',
        plan              ENUM('trial','standard','pro') NOT NULL DEFAULT 'standard',
        max_drivers       INT NOT NULL DEFAULT 50,
        issued_at         DATETIME NOT NULL,
        expires_at        DATETIME NOT NULL,
        status            ENUM('active','suspended','expired','revoked') NOT NULL DEFAULT 'active',
        last_check_at     DATETIME NULL,
        last_check_ip     VARCHAR(45) NULL,
        check_count       INT NOT NULL DEFAULT 0,
        notes             VARCHAR(500) NOT NULL DEFAULT '',
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at        DATETIME NULL,
        INDEX (domain), INDEX (status), INDEX (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS license_events (
        id          BIGINT AUTO_INCREMENT PRIMARY KEY,
        license_id  CHAR(36) NULL,
        event       VARCHAR(40) NOT NULL,
        ip          VARCHAR(45) NULL,
        details     VARCHAR(500) NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (license_id), INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS admin_login_attempts (
        id         BIGINT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(120) NOT NULL,
        ip         VARCHAR(45) NOT NULL,
        success    TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (username, ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Постоянные настройки администратора. TOTP-секрет нельзя хранить
    // только в PHP-сессии: после выхода 2FA переставала работать.
    $db->exec("CREATE TABLE IF NOT EXISTS license_admin_settings (
        id              TINYINT PRIMARY KEY DEFAULT 1,
        totp_secret_enc TEXT NULL,
        totp_enabled    TINYINT(1) NOT NULL DEFAULT 0,
        totp_updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('INSERT IGNORE INTO license_admin_settings (id) VALUES (1)');
}

function lic_uuid(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function lic_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lic_error(string $msg, int $code = 400): void
{
    lic_json(['error' => $msg], $code);
}

function lic_log_event(?string $licenseId, string $event, ?string $ip = null, string $details = ''): void
{
    try {
        lic_db()->prepare(
            'INSERT INTO license_events (license_id,event,ip,details) VALUES (?,?,?,?)'
        )->execute([$licenseId, $event, $ip, mb_substr($details, 0, 500)]);
    } catch (Throwable $e) {}
}

// ── Шифрование TOTP-секрета в БД ─────────────────────────────────────────
function lic_encrypt(string $plain): string
{
    $key = hash('sha256', LIC_SECRET . '|totp', true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) throw new RuntimeException('Не удалось зашифровать 2FA-секрет');
    return base64_encode($iv . $encrypted);
}

function lic_decrypt(?string $value): string
{
    if (!$value) return '';
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $key = hash('sha256', LIC_SECRET . '|totp', true);
    $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key,
        OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

function lic_totp_secret(): string
{
    // Можно задать секрет в license.local.php; иначе берём зашифрованный из БД.
    if (defined('LIC_TOTP_SECRET') && (string) LIC_TOTP_SECRET !== '') {
        return (string) LIC_TOTP_SECRET;
    }
    $row = lic_db()->query(
        'SELECT totp_secret_enc,totp_enabled FROM license_admin_settings WHERE id=1'
    )->fetch();
    if (!$row || (int) $row['totp_enabled'] !== 1) return '';
    return lic_decrypt($row['totp_secret_enc']);
}

function lic_save_totp_secret(string $secret): void
{
    lic_db()->prepare(
        'UPDATE license_admin_settings SET totp_secret_enc=?,totp_enabled=1,
         totp_updated_at=NOW() WHERE id=1'
    )->execute([lic_encrypt($secret)]);
}

// ── TOTP 2FA (RFC 6238, без composer) ────────────────────────────────────
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) $out .= self::ALPHABET[random_int(0, 31)];
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $v = strpos(self::ALPHABET, $c);
            if ($v === false) continue;
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }

    private static function hotp(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $code = ((ord($hash[$offset]) & 0x7f) << 24)
              | ((ord($hash[$offset + 1]) & 0xff) << 16)
              | ((ord($hash[$offset + 2]) & 0xff) << 8)
              | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($code % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        return self::hotp(self::base32Decode($secret), $counter);
    }

    /** Проверка кода с допуском ±1 окно (30 сек). */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) return false;
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $now + $i * 30), $code)) return true;
        }
        return false;
    }

    public static function provisioningUri(string $secret, string $label): string
    {
        return 'otpauth://totp/' . rawurlencode($label)
            . '?secret=' . $secret . '&issuer=' . rawurlencode('TaxiLicense');
    }
}

// ── Защита от брутфорса ───────────────────────────────────────────────────
final class BruteGuard
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;

    public static function clientIp(): string
    {
        // REMOTE_ADDR нельзя подделать обычным HTTP-заголовком. X-Forwarded-For
        // используем только как резерв — иначе злоумышленник обходит лимит,
        // меняя X-Forwarded-For на каждой попытке.
        foreach (['REMOTE_ADDR', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
            $v = $_SERVER[$k] ?? '';
            if ($v !== '') return trim(explode(',', $v)[0]);
        }
        return '0.0.0.0';
    }

    public static function isLocked(string $username): bool
    {
        $db = lic_db();
        return self::failedCount($username) >= self::MAX_ATTEMPTS;
    }

    public static function failedCount(string $username): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::LOCKOUT_MINUTES * 60);
        $stmt = lic_db()->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE username=? AND ip=? AND success=0 AND created_at>?'
        );
        $stmt->execute([$username, self::clientIp(), $cutoff]);
        return (int) $stmt->fetchColumn();
    }

    public static function remainingAttempts(string $username): int
    {
        return max(0, self::MAX_ATTEMPTS - self::failedCount($username));
    }

    public static function record(string $username, bool $success): void
    {
        try {
            lic_db()->prepare(
                'INSERT INTO admin_login_attempts (username, ip, success) VALUES (?,?,?)'
            )->execute([$username, self::clientIp(), $success ? 1 : 0]);

            if ($success) {
                lic_db()->prepare(
                    "DELETE FROM admin_login_attempts
                     WHERE username = ? AND ip = ? AND success = 0"
                )->execute([$username, self::clientIp()]);
            }
        } catch (Throwable $e) {}
    }

    public static function remainingLockout(string $username): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::LOCKOUT_MINUTES * 60);
        $stmt = lic_db()->prepare(
            'SELECT MAX(created_at) FROM admin_login_attempts
             WHERE username=? AND ip=? AND success=0 AND created_at>?'
        );
        $stmt->execute([$username, self::clientIp(), $cutoff]);
        $last = $stmt->fetchColumn();
        if (!$last) return 0;
        $elapsed = (int) floor((time() - strtotime($last . ' UTC')) / 60);
        return max(1, self::LOCKOUT_MINUTES - $elapsed);
    }
}

// ── Ограничение публичного API ───────────────────────────────────────────
function lic_api_rate_limited(string $ip, int $limit = 60): bool
{
    try {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 60);
        $stmt = lic_db()->prepare(
            'SELECT COUNT(*) FROM license_events WHERE ip=? AND created_at>?'
        );
        $stmt->execute([$ip, $cutoff]);
        return (int) $stmt->fetchColumn() >= $limit;
    } catch (Throwable $e) {
        return false;
    }
}

// ── Генерация ключа лицензии ─────────────────────────────────────────────
function lic_generate_key(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $parts = [];
    for ($group = 0; $group < 5; $group++) {
        $part = '';
        for ($i = 0; $i < 5; $i++) $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $parts[] = $part;
    }
    return implode('-', $parts);
}

function lic_hash_key(string $key): string
{
    return hash_hmac('sha256', $key, LIC_SECRET);
}

// ── Токен для админ-сессии ───────────────────────────────────────────────
function lic_session_token(): string
{
    return hash_hmac('sha256', 'admin-session|' . date('Y-m-d'), LIC_SECRET);
}
