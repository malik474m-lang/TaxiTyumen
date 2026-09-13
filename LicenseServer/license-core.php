<?php
// Сервер лицензий TaxiTyumen: выдаёт, проверяет и отзывает лицензии на
// серверную часть такси. Размещается отдельно (taxi.license-prog.ru).
//
// Без composer, чистый PHP 8 + MySQL/PDO. TOTP 2FA (RFC 6238) и защита
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
}

function lic_uuid(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function lic_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lic_error(string $msg, int $code = 400): never
{
    lic_json(['error' => $msg], $code);
}

function lic_log_event(?string $licenseId, string $event, ?string $ip = null, string $details = ''): void
{
    try {
        lic_db()->prepare(
            'INSERT INTO license_events (license_id,event,ip,details) VALUES (?,?,?,?)'
        )->execute([$licenseId, $event, $ip, mb_substr($details, 0, 500)]);
    } catch (Throwable) {}
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
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public static function clientIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            $v = $_SERVER[$k] ?? '';
            if ($v !== '') return trim(explode(',', $v)[0]);
        }
        return '0.0.0.0';
    }

    public static function isLocked(string $username): bool
    {
        $db = lic_db();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM admin_login_attempts
             WHERE username = ? AND ip = ? AND success = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$username, self::clientIp(), self::LOCKOUT_MINUTES]);
        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
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
        } catch (Throwable) {}
    }

    public static function remainingLockout(string $username): int
    {
        $db = lic_db();
        $stmt = $db->prepare(
            "SELECT TIMESTAMPDIFF(MINUTE, MAX(created_at), NOW())
             FROM admin_login_attempts
             WHERE username = ? AND ip = ? AND success = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$username, self::clientIp(), self::LOCKOUT_MINUTES]);
        $elapsed = (int) ($stmt->fetchColumn() ?: 0);
        return max(0, self::LOCKOUT_MINUTES - $elapsed);
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
