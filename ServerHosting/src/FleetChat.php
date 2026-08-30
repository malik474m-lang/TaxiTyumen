<?php
// Общий чат водителей автопарка (паритет с веб-портом TaxiTyumen.Web):
// единый канал «водитель ↔ водители», снапшот имени и машины автора.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class FleetChat
{
    public const HISTORY_LIMIT = 150;
    public const MAX_TEXT = 500;
    public const MIN_INTERVAL_MS = 1500; // анти-спам: не чаще 1 сообщения в 1.5 с

    /** Идемпотентное создание таблицы — для уже установленных хостингов. */
    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS fleet_messages (
                id          CHAR(36) PRIMARY KEY,
                sender_id   CHAR(36) NOT NULL,
                sender_name VARCHAR(120) NOT NULL DEFAULT '',
                car_info    VARCHAR(160) NOT NULL DEFAULT '',
                text        VARCHAR(500) NOT NULL,
                created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                FOREIGN KEY (sender_id) REFERENCES users(id),
                INDEX (sender_id),
                INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function canRead(?string $role): bool
    {
        return in_array($role, ['driver', 'operator', 'admin', 'superadmin'], true);
    }

    /** Последние сообщения; $afterMs > 0 — только новее метки (мс от эпохи). */
    public static function history(\PDO $db, int $afterMs = 0): array
    {
        self::ensureTables($db);
        if ($afterMs > 0) {
            // Порог как строка UTC с миллисекундами: FROM_UNIXTIME зависит от
            // часового пояса MySQL-сервера и давал рассинхрон -> бесконечные дубли у клиентов
            $threshold = gmdate('Y-m-d H:i:s.', intdiv($afterMs, 1000))
                . sprintf('%03d', $afterMs % 1000);
            $stmt = $db->prepare(
                'SELECT * FROM fleet_messages WHERE created_at > ?
                 ORDER BY created_at ASC LIMIT ?'
            );
            $stmt->bindValue(1, $threshold, \PDO::PARAM_STR);
            $stmt->bindValue(2, self::HISTORY_LIMIT, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }
        $limit = self::HISTORY_LIMIT;
        $rows = $db->query(
            "SELECT * FROM (SELECT * FROM fleet_messages ORDER BY created_at DESC LIMIT $limit) t
             ORDER BY created_at ASC"
        );
        return $rows->fetchAll();
    }

    /** Мс от эпохи последнего сообщения отправителя (0 — не писал). */
    public static function lastSentMs(\PDO $db, string $senderId): int
    {
        $stmt = $db->prepare(
            'SELECT UNIX_TIMESTAMP(created_at) * 1000 FROM fleet_messages
             WHERE sender_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$senderId]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : self::utcMs((string) $value);
    }

    public static function post(\PDO $db, string $senderId, string $senderName, string $carInfo, string $text): array
    {
        $id = Db::uuid();
        $createdAt = gmdate('Y-m-d H:i:s.') . sprintf('%03d', (int) (microtime(true) * 1000) % 1000);
        $db->prepare(
            'INSERT INTO fleet_messages (id, sender_id, sender_name, car_info, text, created_at) VALUES (?,?,?,?,?,?)'
        )->execute([$id, $senderId, $senderName, $carInfo, $text, $createdAt]);
        $stmt = $db->prepare('SELECT * FROM fleet_messages WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function remove(\PDO $db, string $id): bool
    {
        $stmt = $db->prepare('DELETE FROM fleet_messages WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Мс эпохи из метки 'Y-m-d H:i:s[.fff]' в UTC (независимо от TZ хостинга). */
    private static function utcMs(string $ts): int
    {
        $ms = 0;
        if (preg_match('/\.(\d{1,3})$/', $ts, $m)) {
            $ms = (int) str_pad($m[1], 3, '0', STR_PAD_RIGHT);
        }
        $sec = strtotime($ts . ' UTC');
        return $sec === false ? 0 : $sec * 1000 + $ms;
    }

    /** DTO единой формы (как в Next.js-версии): метка строго UTC 'Z' с миллисекундами. */
    public static function dto(array $m): array
    {
        $ms = self::utcMs((string) $m['created_at']);
        $createdAt = $ms > 0
            ? gmdate('Y-m-d\TH:i:s.', intdiv($ms, 1000)) . sprintf('%03dZ', $ms % 1000)
            : (string) $m['created_at'];
        return [
            'id' => $m['id'],
            'senderId' => $m['sender_id'],
            'senderName' => $m['sender_name'],
            'carInfo' => $m['car_info'],
            'text' => $m['text'],
            'createdAt' => $createdAt,
        ];
    }
}
