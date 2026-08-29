<?php
// Пароли (BCrypt, как AuthService.cs) + HMAC-токены сессии (совместимо с web-версией)
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

final class Auth
{
    public const TOKEN_TTL_MS = 24 * 3600 * 1000;

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($digits, '8') && strlen($digits) === 11) {
            return '+7' . substr($digits, 1);
        }
        if (str_starts_with($digits, '7') && strlen($digits) === 11) {
            return '+' . $digits;
        }
        if (strlen($digits) === 10) {
            return '+7' . $digits;
        }
        return str_starts_with($raw, '+') ? $raw : '+' . $digits;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // Формат как в web-session: base64url(payload).base64url(hmac-sha256)
    public static function signToken(string $uid, string $role, ?string $driverId = null): string
    {
        $payload = json_encode([
            'uid' => $uid,
            'role' => $role,
            'driverId' => $driverId,
            'exp' => (int) round(microtime(true) * 1000) + self::TOKEN_TTL_MS,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $body = self::b64url($payload);
        $sig = self::b64url(hash_hmac('sha256', $body, AUTH_SECRET, true));
        return $body . '.' . $sig;
    }

    public static function readClaims(): ?array
    {
        $header = self::authorizationHeader();
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $parts = explode('.', substr($header, 7));
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        $expected = self::b64url(hash_hmac('sha256', $body, AUTH_SECRET, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        if (!is_array($payload) || empty($payload['uid'])) {
            return null;
        }
        if ((int) round(microtime(true) * 1000) > (int) ($payload['exp'] ?? 0)) {
            return null;
        }
        return $payload;
    }

    private static function authorizationHeader(): ?string
    {
        // Стандартный вариант
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
        }
        // Apache + PHP-FPM
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    return trim((string) $value);
                }
            }
        }
        // Apache RewriteRule http авторизация
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }
        return null;
    }
}
