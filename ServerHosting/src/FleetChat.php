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
            $stmt = $db->prepare(
                'SELECT * FROM fleet_messages WHERE created_at > FROM_UNIXTIME(? / 1000)
                 ORDER BY created_at ASC LIMIT ?'
            );
            $stmt->bindValue(1, $afterMs, \PDO::PARAM_INT);
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
        return $value === false ? 0 : (int) $value;
    }

    public static function post(\PDO $db, string $senderId, string $senderName, string $carInfo, string $text): array
    {
        $id = Db::uuid();
        $db->prepare(
            'INSERT INTO fleet_messages (id, sender_id, sender_name, car_info, text) VALUES (?,?,?,?,?)'
        )->execute([$id, $senderId, $senderName, $carInfo, $text]);
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

    /** DTO единой формы (как в Next.js-версии). */
    public static function dto(array $m): array
    {
        $ts = strtotime((string) $m['created_at']);
        return [
            'id' => $m['id'],
            'senderId' => $m['sender_id'],
            'senderName' => $m['sender_name'],
            'carInfo' => $m['car_info'],
            'text' => $m['text'],
            'createdAt' => $ts === false ? (string) $m['created_at'] : gmdate('c', $ts),
        ];
    }
}
