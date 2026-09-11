<?php
// Встроенный SMS-шлюз: сообщения отправляет Android-телефон с обычной SIM-картой.
//
// Схема работы:
//   1. Сервер кладёт сообщение в очередь (sms_gateway_queue, статус queued);
//   2. Приложение-шлюз на телефоне периодически спрашивает задания
//      (GET /api/sms-gateway.php?action=poll, Bearer-токен устройства);
//   3. Телефон отправляет SMS обычной SIM-картой и подтверждает результат
//      (POST action=ack) — в очереди появляется sent/failed и время доставки.
//
// Сервис целиком включается и выключается в админке; при выключенном шлюзе
// система работает по-прежнему через sms.ru.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class SmsGateway
{
    /** Сколько ждём подтверждения от телефона, прежде чем вернуть задание в очередь. */
    public const LEASE_SECONDS = 120;

    /** Устройство считается «на связи», если отмечалось не дольше этого времени назад. */
    public const ONLINE_SECONDS = 120;

    /** Максимум попыток отправки одного сообщения. */
    public const MAX_ATTEMPTS = 3;

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS sms_gateway_settings (
                id             TINYINT PRIMARY KEY DEFAULT 1,
                enabled        TINYINT(1) NOT NULL DEFAULT 0,
                device_token   VARCHAR(80)  NOT NULL DEFAULT '',
                device_name    VARCHAR(120) NOT NULL DEFAULT '',
                device_phone   VARCHAR(30)  NULL,
                battery        INT          NULL,
                last_seen_at   DATETIME     NULL,
                poll_seconds   INT          NOT NULL DEFAULT 10,
                daily_limit    INT          NOT NULL DEFAULT 0,
                updated_at     DATETIME     NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS sms_gateway_queue (
                id           CHAR(36) PRIMARY KEY,
                phone        VARCHAR(30)  NOT NULL,
                message      VARCHAR(1000) NOT NULL,
                purpose      VARCHAR(40)  NOT NULL DEFAULT 'general',
                status       ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
                attempts     INT          NOT NULL DEFAULT 0,
                error        VARCHAR(500) NULL,
                device_name  VARCHAR(120) NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                taken_at     DATETIME     NULL,
                sent_at      DATETIME     NULL,
                INDEX (status), INDEX (created_at), INDEX (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Единственная строка настроек
        $db->exec('INSERT IGNORE INTO sms_gateway_settings (id, enabled) VALUES (1, 0)');
    }

    public static function settings(\PDO $db): array
    {
        self::ensureTables($db);
        $row = $db->query('SELECT * FROM sms_gateway_settings WHERE id = 1 LIMIT 1')->fetch();
        if (!$row) {
            $db->exec('INSERT IGNORE INTO sms_gateway_settings (id, enabled) VALUES (1, 0)');
            $row = $db->query('SELECT * FROM sms_gateway_settings WHERE id = 1 LIMIT 1')->fetch() ?: [];
        }
        $row['enabled'] = (bool) ($row['enabled'] ?? false);
        $row['online'] = self::isOnline($row);
        return $row;
    }

    public static function isEnabled(\PDO $db): bool
    {
        try {
            return self::settings($db)['enabled'];
        } catch (\Throwable) {
            return false;
        }
    }

    /** Телефон-шлюз на связи (отмечался недавно). */
    public static function isOnline(array $settings): bool
    {
        $seen = $settings['last_seen_at'] ?? null;
        if (!$seen) return false;
        return (time() - strtotime($seen . ' UTC')) <= self::ONLINE_SECONDS;
    }

    public static function save(\PDO $db, array $data): void
    {
        self::ensureTables($db);
        $db->prepare(
            'UPDATE sms_gateway_settings
             SET enabled = ?, device_name = ?, poll_seconds = ?, daily_limit = ?, updated_at = ?
             WHERE id = 1'
        )->execute([
            !empty($data['enabled']) ? 1 : 0,
            mb_substr(trim((string) ($data['device_name'] ?? '')), 0, 120),
            max(3, min(120, (int) ($data['poll_seconds'] ?? 10))),
            max(0, (int) ($data['daily_limit'] ?? 0)),
            Db::utcNow(),
        ]);
    }

    /** Новый токен устройства: старый телефон сразу перестаёт получать задания. */
    public static function regenerateToken(\PDO $db): string
    {
        self::ensureTables($db);
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $db->prepare('UPDATE sms_gateway_settings SET device_token = ?, updated_at = ? WHERE id = 1')
            ->execute([$token, Db::utcNow()]);
        return $token;
    }

    /** Проверка Bearer-токена устройства (константное сравнение). */
    public static function authenticate(\PDO $db, ?string $token): bool
    {
        $settings = self::settings($db);
        $expected = (string) ($settings['device_token'] ?? '');
        return $expected !== '' && is_string($token) && hash_equals($expected, trim($token));
    }

    /** Сколько отправлено за сегодня (для суточного лимита SIM-карты). */
    public static function sentToday(\PDO $db): int
    {
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM sms_gateway_queue
                 WHERE status = 'sent' AND sent_at >= ?"
            );
            $stmt->execute([gmdate('Y-m-d 00:00:00')]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Постановка сообщения в очередь. Возвращает id задания. */
    public static function enqueue(\PDO $db, string $phone, string $message, string $purpose = 'general'): string
    {
        self::ensureTables($db);
        $id = Db::uuid();
        $db->prepare(
            'INSERT INTO sms_gateway_queue (id, phone, message, purpose) VALUES (?,?,?,?)'
        )->execute([$id, $phone, mb_substr($message, 0, 1000), mb_substr($purpose, 0, 40)]);
        return $id;
    }

    /**
     * Выдать телефону задания на отправку. Заказы помечаются 'sending';
     * если подтверждение не пришло за LEASE_SECONDS — задание вернётся в очередь.
     */
    public static function lease(\PDO $db, int $limit = 5, string $deviceName = ''): array
    {
        self::ensureTables($db);
        self::requeueStale($db);

        $settings = self::settings($db);
        $limit = max(1, min(20, $limit));

        // Суточный лимит SIM-карты (0 — без ограничения)
        $dailyLimit = (int) ($settings['daily_limit'] ?? 0);
        if ($dailyLimit > 0) {
            $left = $dailyLimit - self::sentToday($db);
            if ($left <= 0) return [];
            $limit = min($limit, $left);
        }

        $stmt = $db->prepare(
            "SELECT * FROM sms_gateway_queue
             WHERE status = 'queued' AND attempts < ?
             ORDER BY created_at ASC LIMIT $limit"
        );
        $stmt->execute([self::MAX_ATTEMPTS]);
        $rows = $stmt->fetchAll();
        if (!$rows) return [];

        $take = $db->prepare(
            "UPDATE sms_gateway_queue
             SET status = 'sending', taken_at = ?, attempts = attempts + 1, device_name = ?
             WHERE id = ? AND status = 'queued'"
        );
        $out = [];
        foreach ($rows as $row) {
            $take->execute([Db::utcNow(), mb_substr($deviceName, 0, 120) ?: null, $row['id']]);
            if ($take->rowCount() > 0) {
                $out[] = [
                    'id' => $row['id'],
                    'phone' => $row['phone'],
                    'message' => $row['message'],
                ];
            }
        }
        return $out;
    }

    /** Подтверждение результата отправки телефоном. */
    public static function acknowledge(\PDO $db, string $id, bool $success, ?string $error = null): bool
    {
        self::ensureTables($db);
        if ($success) {
            $stmt = $db->prepare(
                "UPDATE sms_gateway_queue SET status='sent', sent_at=?, error=NULL WHERE id=?"
            );
            $stmt->execute([Db::utcNow(), $id]);
            return $stmt->rowCount() > 0;
        }

        // Неудача: пока есть попытки — возвращаем в очередь, иначе фиксируем отказ
        $stmt = $db->prepare(
            "UPDATE sms_gateway_queue
             SET status = IF(attempts >= ?, 'failed', 'queued'), error = ?
             WHERE id = ?"
        );
        $stmt->execute([self::MAX_ATTEMPTS, mb_substr((string) $error, 0, 500), $id]);
        return $stmt->rowCount() > 0;
    }

    /** Телефон отметился: время, батарея, номер SIM. */
    public static function heartbeat(\PDO $db, array $info = []): void
    {
        self::ensureTables($db);
        $db->prepare(
            'UPDATE sms_gateway_settings
             SET last_seen_at = ?,
                 battery = COALESCE(?, battery),
                 device_phone = COALESCE(?, device_phone),
                 device_name = IF(? <> \'\', ?, device_name)
             WHERE id = 1'
        )->execute([
            Db::utcNow(),
            isset($info['battery']) ? max(0, min(100, (int) $info['battery'])) : null,
            isset($info['phone']) && $info['phone'] !== '' ? mb_substr((string) $info['phone'], 0, 30) : null,
            (string) ($info['device'] ?? ''),
            mb_substr((string) ($info['device'] ?? ''), 0, 120),
        ]);
    }

    /** Зависшие задания (телефон взял и не ответил) возвращаем в очередь. */
    public static function requeueStale(\PDO $db): int
    {
        $stmt = $db->prepare(
            "UPDATE sms_gateway_queue
             SET status = IF(attempts >= ?, 'failed', 'queued'),
                 error = 'Устройство не подтвердило отправку'
             WHERE status = 'sending' AND taken_at < ?"
        );
        $stmt->execute([
            self::MAX_ATTEMPTS,
            gmdate('Y-m-d H:i:s', time() - self::LEASE_SECONDS),
        ]);
        return $stmt->rowCount();
    }

    /** Сводка для админки и диагностики. */
    public static function stats(\PDO $db): array
    {
        self::ensureTables($db);
        $row = $db->query(
            "SELECT
                COALESCE(SUM(status='queued'),0)  AS queued,
                COALESCE(SUM(status='sending'),0) AS sending,
                COALESCE(SUM(status='sent'),0)    AS sent,
                COALESCE(SUM(status='failed'),0)  AS failed
             FROM sms_gateway_queue"
        )->fetch() ?: [];
        return [
            'queued' => (int) ($row['queued'] ?? 0),
            'sending' => (int) ($row['sending'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'sentToday' => self::sentToday($db),
        ];
    }

    /** Повторная постановка неудачных сообщений в очередь. */
    public static function retryFailed(\PDO $db, ?string $id = null): int
    {
        self::ensureTables($db);
        if ($id !== null) {
            $stmt = $db->prepare(
                "UPDATE sms_gateway_queue SET status='queued', attempts=0, error=NULL WHERE id=? AND status='failed'"
            );
            $stmt->execute([$id]);
        } else {
            $stmt = $db->query(
                "UPDATE sms_gateway_queue SET status='queued', attempts=0, error=NULL WHERE status='failed'"
            );
        }
        return $stmt->rowCount();
    }
}
