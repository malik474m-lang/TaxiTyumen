<?php
// Пул API-ключей одного сервиса с автопереключением при исчерпании квоты.
// Кейс OpenCage: бесплатный тариф — 2500 запросов/сутки на аккаунт, поэтому
// в админке можно указать несколько ключей: пул сам обходит их по кругу и
// временно исключает те, что вернули 402 (quota) или 429 (rate limit).
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class KeyPool
{
    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS api_key_pool_state (
                service VARCHAR(40) NOT NULL,
                key_hash CHAR(40) NOT NULL,
                key_tail VARCHAR(12) NOT NULL DEFAULT '',
                blocked_until DATETIME NULL,
                last_status VARCHAR(40) NULL,
                requests_ok INT NOT NULL DEFAULT 0,
                requests_failed INT NOT NULL DEFAULT 0,
                last_used_at DATETIME NULL,
                PRIMARY KEY (service, key_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** Разбор поля админки: ключи через запятую, точку с запятой или перенос строки. */
    public static function parse(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $keys = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $keys, true)) {
                $keys[] = $part;
            }
        }
        return $keys;
    }

    public static function hash(string $key): string
    {
        return sha1($key);
    }

    public static function tail(string $key): string
    {
        return mb_strlen($key) > 4 ? '…' . mb_substr($key, -4) : $key;
    }

    /** Ключи сервиса в порядке использования: сначала свободные, заблокированные — в конец. */
    public static function available(\PDO $db, string $service, string $raw): array
    {
        $keys = self::parse($raw);
        if (!$keys) return [];
        self::ensureTables($db);

        $blocked = [];
        try {
            $stmt = $db->prepare(
                'SELECT key_hash FROM api_key_pool_state
                 WHERE service = ? AND blocked_until IS NOT NULL AND blocked_until > ?'
            );
            $stmt->execute([$service, Db::utcNow()]);
            foreach ($stmt->fetchAll() as $row) {
                $blocked[(string) $row['key_hash']] = true;
            }
        } catch (\Throwable) {
        }

        $free = [];
        foreach ($keys as $key) {
            if (!isset($blocked[self::hash($key)])) {
                $free[] = $key;
            }
        }
        // Все исчерпаны — пробуем всё равно (вдруг квота уже сброшена на стороне сервиса)
        return $free ?: $keys;
    }

    /** Ключ исчерпал квоту: блокируем до сброса (для OpenCage — ближайшая полночь UTC). */
    public static function block(\PDO $db, string $service, string $key, int $seconds, string $status): void
    {
        self::ensureTables($db);
        $until = gmdate('Y-m-d H:i:s', time() + max(60, $seconds));
        try {
            $db->prepare(
                'INSERT INTO api_key_pool_state
                 (service, key_hash, key_tail, blocked_until, last_status, requests_failed, last_used_at)
                 VALUES (?,?,?,?,?,1,?)
                 ON DUPLICATE KEY UPDATE blocked_until=VALUES(blocked_until), last_status=VALUES(last_status),
                 requests_failed=requests_failed+1, last_used_at=VALUES(last_used_at)'
            )->execute([$service, self::hash($key), self::tail($key), $until, $status, Db::utcNow()]);
        } catch (\Throwable) {
        }
    }

    /** Успешный вызов: снимаем блокировку и считаем статистику. */
    public static function success(\PDO $db, string $service, string $key): void
    {
        self::ensureTables($db);
        try {
            $db->prepare(
                'INSERT INTO api_key_pool_state
                 (service, key_hash, key_tail, blocked_until, last_status, requests_ok, last_used_at)
                 VALUES (?,?,?,NULL,?,1,?)
                 ON DUPLICATE KEY UPDATE blocked_until=NULL, last_status=VALUES(last_status),
                 requests_ok=requests_ok+1, last_used_at=VALUES(last_used_at)'
            )->execute([$service, self::hash($key), self::tail($key), 'ok', Db::utcNow()]);
        } catch (\Throwable) {
        }
    }

    /** Состояние всех ключей сервиса — для страницы «API-ключи». */
    public static function status(\PDO $db, string $service, string $raw): array
    {
        self::ensureTables($db);
        $rows = [];
        try {
            $stmt = $db->prepare('SELECT * FROM api_key_pool_state WHERE service = ?');
            $stmt->execute([$service]);
            foreach ($stmt->fetchAll() as $row) {
                $rows[(string) $row['key_hash']] = $row;
            }
        } catch (\Throwable) {
        }

        $now = Db::utcNow();
        $out = [];
        foreach (self::parse($raw) as $index => $key) {
            $row = $rows[self::hash($key)] ?? null;
            $blockedUntil = $row['blocked_until'] ?? null;
            $isBlocked = $blockedUntil !== null && $blockedUntil > $now;
            $out[] = [
                'index' => $index + 1,
                'tail' => self::tail($key),
                'blocked' => $isBlocked,
                'blockedUntil' => $isBlocked ? $blockedUntil : null,
                'lastStatus' => $row['last_status'] ?? null,
                'requestsOk' => (int) ($row['requests_ok'] ?? 0),
                'requestsFailed' => (int) ($row['requests_failed'] ?? 0),
                'lastUsedAt' => $row['last_used_at'] ?? null,
            ];
        }
        return $out;
    }

    /** Сброс блокировок сервиса вручную из админки. */
    public static function reset(\PDO $db, string $service): void
    {
        self::ensureTables($db);
        try {
            $db->prepare('UPDATE api_key_pool_state SET blocked_until = NULL WHERE service = ?')
                ->execute([$service]);
        } catch (\Throwable) {
        }
    }

    /** Секунд до ближайшей полуночи UTC — момент сброса суточной квоты OpenCage. */
    public static function secondsUntilUtcMidnight(): int
    {
        return max(60, strtotime(gmdate('Y-m-d') . ' 23:59:59 UTC') + 1 - time());
    }
}
