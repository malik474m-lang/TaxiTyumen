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
if (!defined('LIC_DEBUG')) define('LIC_DEBUG', (getenv('LIC_DEBUG') ?: '') === '1');

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
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => true]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function lic_ensure_tables(): void
{
    if (LIC_SECRET === 'CHANGE-ME-64-CHARS' || strlen((string) LIC_SECRET) < 32) {
        throw new RuntimeException(
            'LIC_SECRET не настроен или слишком короткий. Используйте install.php.'
        );
    }
    $db = lic_db();
    $db->exec("CREATE TABLE IF NOT EXISTS licenses (
        id                CHAR(36) PRIMARY KEY,
        license_key       VARCHAR(64) NOT NULL UNIQUE,
        license_key_hint  VARCHAR(16) NOT NULL DEFAULT '',
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

    // Миграция ранней версии: ключи больше не хранятся открыто.
    $col = $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='licenses'
           AND COLUMN_NAME='license_key_hint'"
    )->fetchColumn();
    if ((int) $col === 0) {
        $db->exec("ALTER TABLE licenses ADD COLUMN license_key_hint VARCHAR(16) NOT NULL DEFAULT '' AFTER license_key");
    }
    $rows = $db->query("SELECT id,license_key,license_key_hint FROM licenses WHERE license_key_hint='' LIMIT 1000")->fetchAll();
    $migrate = $db->prepare('UPDATE licenses SET license_key=?,license_key_hint=? WHERE id=?');
    foreach ($rows as $row) {
        $oldKey = (string) $row['license_key'];
        if (strlen($oldKey) === 64 && ctype_xdigit($oldKey)) {
            $migrate->execute([$oldKey, 'скрыт', $row['id']]);
        } else {
            $hint = substr($oldKey, 0, 5) . '-…-' . substr($oldKey, -5);
            $migrate->execute([lic_hash_key(strtoupper($oldKey)), $hint, $row['id']]);
        }
    }

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

    // Постоянное состояние админ-панели. TOTP должен переживать выход,
    // очистку сессии и перезагрузку PHP — раньше он хранился только в $_SESSION.
    $db->exec("CREATE TABLE IF NOT EXISTS license_admin_settings (
        id             TINYINT PRIMARY KEY DEFAULT 1,
        totp_secret    TEXT NULL,
        totp_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        updated_at     DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('INSERT IGNORE INTO license_admin_settings (id) VALUES (1)');

    // Совместимость с параллельной ранней схемой 2FA
    $columns = [];
    foreach ($db->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='license_admin_settings'"
    )->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[(string) $column] = true;
    }
    if (!isset($columns['totp_secret'])) {
        $db->exec('ALTER TABLE license_admin_settings ADD COLUMN totp_secret TEXT NULL');
    }
    if (!isset($columns['totp_confirmed'])) {
        $db->exec("ALTER TABLE license_admin_settings ADD COLUMN totp_confirmed TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!isset($columns['updated_at'])) {
        $db->exec('ALTER TABLE license_admin_settings ADD COLUMN updated_at DATETIME NULL');
    }
    // Ветка 286241e использовала totp_secret_enc/totp_enabled. Переносим
    // зашифрованное значение (алгоритм и LIC_SECRET те же), не раскрывая секрет.
    if (isset($columns['totp_secret_enc']) && isset($columns['totp_enabled'])) {
        $legacy = $db->query(
            'SELECT totp_secret_enc,totp_enabled FROM license_admin_settings WHERE id=1'
        )->fetch();
        if ($legacy && (int) $legacy['totp_enabled'] === 1 && !empty($legacy['totp_secret_enc'])) {
            $db->prepare(
                'UPDATE license_admin_settings
                 SET totp_secret=IF(totp_confirmed=0,?,totp_secret),
                     totp_confirmed=1,updated_at=NOW() WHERE id=1'
            )->execute([$legacy['totp_secret_enc']]);
        }
    }

    // Аварийный сброс 2FA при потере телефона: временно добавьте в
    // license.local.php define('LIC_RESET_2FA', true), откройте страницу
    // один раз и СРАЗУ удалите строку. При следующем входе будет новый QR.
    if (defined('LIC_RESET_2FA') && LIC_RESET_2FA === true) {
        $db->exec(
            'UPDATE license_admin_settings SET totp_secret=NULL,totp_confirmed=0,updated_at=NOW() WHERE id=1'
        );
        if (isset($columns['totp_secret_enc']) && isset($columns['totp_enabled'])) {
            $db->exec(
                'UPDATE license_admin_settings SET totp_secret_enc=NULL,totp_enabled=0 WHERE id=1'
            );
        }
    }
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
    } catch (Throwable $ignored) {}
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
        // REMOTE_ADDR нельзя подменить HTTP-заголовком. X-Real-IP используем
        // только если Apache действительно не передал адрес соединения.
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;

        $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) return $real;
        return '0.0.0.0';
    }

    public static function isLocked(string $username): bool
    {
        $db = lic_db();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM admin_login_attempts
             WHERE username = ? AND ip = ? AND success = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([$username, self::clientIp()]);
        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public static function failedCount(string $username): int
    {
        $stmt = lic_db()->prepare(
            "SELECT COUNT(*) FROM admin_login_attempts
             WHERE username=? AND ip=? AND success=0
               AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([$username, self::clientIp()]);
        return (int) $stmt->fetchColumn();
    }

    /** Публичный API: не более 20 неудачных проверок ключа с IP за час. */
    public static function licenseApiAllowed(): bool
    {
        try {
            $stmt = lic_db()->prepare(
                "SELECT COUNT(*) FROM license_events
                 WHERE ip=? AND event IN ('check-failed','activate-failed','activate-mismatch')
                   AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $stmt->execute([self::clientIp()]);
            return (int) $stmt->fetchColumn() < 20;
        } catch (Throwable $e) {
            return true;
        }
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
        } catch (Throwable $ignored) {}
    }

    public static function remainingLockout(string $username): int
    {
        $db = lic_db();
        $stmt = $db->prepare(
            "SELECT TIMESTAMPDIFF(MINUTE, MAX(created_at), NOW())
             FROM admin_login_attempts
             WHERE username = ? AND ip = ? AND success = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([$username, self::clientIp()]);
        $elapsed = (int) ($stmt->fetchColumn() ?: 0);
        return max(0, self::LOCKOUT_MINUTES - $elapsed);
    }
}

// Общий лимит публичного API: 60 зафиксированных запросов с IP за минуту.
function lic_api_rate_limited(string $ip, int $limit = 60): bool
{
    try {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 60);
        $stmt = lic_db()->prepare(
            'SELECT COUNT(*) FROM license_events WHERE ip=? AND created_at>?'
        );
        $stmt->execute([$ip, $cutoff]);
        return (int) $stmt->fetchColumn() >= $limit;
    } catch (Throwable $ignored) {
        return false;
    }
}

// ── Постоянное хранение TOTP ─────────────────────────────────────────────
function lic_encrypt(string $value): string
{
    if ($value === '') return '';
    $key = hash('sha256', LIC_SECRET . '|totp', true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) throw new RuntimeException('Не удалось зашифровать секрет 2FA');
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

function lic_admin_totp_secret(): string
{
    // Опциональный резервный секрет из license.local.php
    if (defined('LIC_TOTP_SECRET') && (string) LIC_TOTP_SECRET !== '') {
        return (string) LIC_TOTP_SECRET;
    }
    try {
        $row = lic_db()->query(
            'SELECT totp_secret,totp_confirmed FROM license_admin_settings WHERE id=1'
        )->fetch();
        return $row && (int) $row['totp_confirmed'] === 1
            ? lic_decrypt($row['totp_secret']) : '';
    } catch (Throwable $e) { return ''; }
}

function lic_admin_save_totp(string $secret): void
{
    lic_db()->prepare(
        'UPDATE license_admin_settings SET totp_secret=?,totp_confirmed=1,updated_at=NOW() WHERE id=1'
    )->execute([lic_encrypt($secret)]);
}

// CSRF-токен административных форм
function lic_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return (string) $_SESSION['csrf'];
}

function lic_verify_csrf(): bool
{
    $provided = (string) ($_POST['_csrf'] ?? '');
    return $provided !== '' && hash_equals(lic_csrf_token(), $provided);
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
