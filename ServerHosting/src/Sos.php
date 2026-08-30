<?php
// Тревожная кнопка водителя (SOS): создание тревоги с координатами,
// широковещательное оповещение всех водителей на линии + диспетчерской/админки.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Sos
{
    public const AUTO_CLOSE_MINUTES = 60; // тревога сама уходит из активных через час

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS sos_alerts (
                id            CHAR(36) PRIMARY KEY,
                driver_id     CHAR(36) NOT NULL,
                user_id       CHAR(36) NOT NULL,
                driver_name   VARCHAR(120) NOT NULL DEFAULT '',
                driver_phone  VARCHAR(20) NULL,
                car_info      VARCHAR(160) NOT NULL DEFAULT '',
                latitude      DOUBLE NOT NULL,
                longitude     DOUBLE NOT NULL,
                order_id      CHAR(36) NULL,
                comment       VARCHAR(300) NULL,
                status        ENUM('active','resolved') NOT NULL DEFAULT 'active',
                resolved_by   CHAR(36) NULL,
                resolved_at   DATETIME NULL,
                created_at    DATETIME(3) NOT NULL,
                INDEX (status), INDEX (driver_id), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** Мс эпохи из метки 'Y-m-d H:i:s[.fff]' в UTC (не зависит от TZ хостинга). */
    public static function utcMs(string $ts): int
    {
        $ms = 0;
        if (preg_match('/\.(\d{1,3})$/', $ts, $m)) {
            $ms = (int) str_pad($m[1], 3, '0', STR_PAD_RIGHT);
        }
        $sec = strtotime($ts . ' UTC');
        return $sec === false ? 0 : $sec * 1000 + $ms;
    }

    private static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s.') . sprintf('%03d', (int) (microtime(true) * 1000) % 1000);
    }

    /** Создать тревогу и оповестить всех водителей + диспетчерскую. */
    public static function raise(
        \PDO $db,
        array $driver,
        array $user,
        float $lat,
        float $lng,
        ?string $orderId,
        ?string $comment
    ): array {
        self::ensureTables($db);

        // Антидубль: активная тревога этого водителя моложе 2 минут — возвращаем её
        $recent = $db->prepare(
            "SELECT * FROM sos_alerts WHERE driver_id=? AND status='active'
             ORDER BY created_at DESC LIMIT 1"
        );
        $recent->execute([$driver['id']]);
        $existing = $recent->fetch();
        if ($existing && (time() * 1000 - self::utcMs((string) $existing['created_at'])) < 120000) {
            // Обновляем координаты уже поднятой тревоги
            $db->prepare('UPDATE sos_alerts SET latitude=?, longitude=? WHERE id=?')
                ->execute([$lat, $lng, $existing['id']]);
            $existing['latitude'] = $lat;
            $existing['longitude'] = $lng;
            return $existing;
        }

        $id = Db::uuid();
        $driverName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $carInfo = trim(($driver['car_brand'] ?? '') . ' ' . ($driver['car_model'] ?? '')
            . ' · ' . ($driver['license_plate'] ?? ''));

        $db->prepare(
            'INSERT INTO sos_alerts
             (id, driver_id, user_id, driver_name, driver_phone, car_info, latitude, longitude, order_id, comment, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id, $driver['id'], $user['id'], $driverName, $user['phone'] ?? null,
            $carInfo, $lat, $lng, $orderId, $comment, self::nowUtc(),
        ]);

        // Оповещение: все водители (кроме автора), операторы и админы
        $title = 'SOS · ' . ($driverName !== '' ? $driverName : 'Водитель');
        $message = sprintf(
            '%s просит помощи. %s Координаты: %.5f, %.5f',
            $carInfo !== '' ? $carInfo : 'Автопарк',
            $comment ? ('«' . $comment . '» ') : '',
            $lat,
            $lng
        );
        $payload = [
            'alertId' => $id,
            'driverId' => $driver['id'],
            'driverName' => $driverName,
            'driverPhone' => $user['phone'] ?? null,
            'carInfo' => $carInfo,
            'latitude' => $lat,
            'longitude' => $lng,
            'orderId' => $orderId,
            'comment' => $comment,
            'mapUrl' => sprintf('https://yandex.ru/maps/?pt=%f,%f&z=17&l=map', $lng, $lat),
        ];

        try {
            $recipients = $db->prepare(
                "SELECT id FROM users
                 WHERE role IN ('driver','operator','admin','superadmin')
                   AND is_active=1 AND is_blocked=0 AND is_archived=0 AND id <> ?"
            );
            $recipients->execute([$user['id']]);
            foreach ($recipients->fetchAll() as $row) {
                NotificationService::create(
                    $db, (string) $row['id'], 'SosAlert', $title, $message, $orderId, $payload
                );
            }
        } catch (\Throwable) {
            // оповещение не должно ронять саму тревогу
        }

        Bus::publish('sos');

        $stmt = $db->prepare('SELECT * FROM sos_alerts WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** Активные тревоги (свежие, не закрытые). */
    public static function active(\PDO $db): array
    {
        self::ensureTables($db);
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::AUTO_CLOSE_MINUTES * 60);
        $stmt = $db->prepare(
            "SELECT * FROM sos_alerts WHERE status='active' AND created_at >= ?
             ORDER BY created_at DESC LIMIT 50"
        );
        $stmt->execute([$cutoff]);
        return $stmt->fetchAll();
    }

    public static function history(\PDO $db, int $limit = 100): array
    {
        self::ensureTables($db);
        $limit = max(1, min(500, $limit));
        return $db->query("SELECT * FROM sos_alerts ORDER BY created_at DESC LIMIT $limit")->fetchAll();
    }

    public static function resolve(\PDO $db, string $id, string $byUserId): bool
    {
        self::ensureTables($db);
        $stmt = $db->prepare(
            "UPDATE sos_alerts SET status='resolved', resolved_by=?, resolved_at=? WHERE id=? AND status='active'"
        );
        $stmt->execute([$byUserId, Db::utcNow(), $id]);
        if ($stmt->rowCount() > 0) {
            Bus::publish('sos');
            return true;
        }
        return false;
    }

    public static function dto(array $a): array
    {
        $ms = self::utcMs((string) $a['created_at']);
        return [
            'id' => $a['id'],
            'driverId' => $a['driver_id'],
            'userId' => $a['user_id'],
            'driverName' => $a['driver_name'],
            'driverPhone' => $a['driver_phone'],
            'carInfo' => $a['car_info'],
            'latitude' => (float) $a['latitude'],
            'longitude' => (float) $a['longitude'],
            'orderId' => $a['order_id'],
            'comment' => $a['comment'],
            'status' => $a['status'],
            'mapUrl' => sprintf('https://yandex.ru/maps/?pt=%f,%f&z=17&l=map', (float) $a['longitude'], (float) $a['latitude']),
            'createdAt' => $ms > 0
                ? gmdate('Y-m-d\TH:i:s.', intdiv($ms, 1000)) . sprintf('%03dZ', $ms % 1000)
                : (string) $a['created_at'],
            'resolvedAt' => $a['resolved_at'],
        ];
    }
}
