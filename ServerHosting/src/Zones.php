<?php
// Зональная тарификация: полигоны зон + матрица фиксированных цен.
// Приоритет: фиксированная цена зоны → обычный расчёт по тарифу.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Zones
{
    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS zones (
                id CHAR(36) PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                color VARCHAR(9) NOT NULL DEFAULT '#38bdf8',
                polygon MEDIUMTEXT NOT NULL,
                priority INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX active_idx (is_active, priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS zone_prices (
                id CHAR(36) PRIMARY KEY,
                from_zone_id CHAR(36) NOT NULL,
                to_zone_id CHAR(36) NOT NULL,
                tariff ENUM('economy','comfort','business','minivan') NOT NULL DEFAULT 'economy',
                price DOUBLE NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_route (from_zone_id, to_zone_id, tariff),
                INDEX from_idx (from_zone_id), INDEX to_idx (to_zone_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS zone_settings (
                id CHAR(36) PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                apply_multipliers TINYINT(1) NOT NULL DEFAULT 0,
                add_options TINYINT(1) NOT NULL DEFAULT 1,
                fallback_to_tariff TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function settings(\PDO $db): array
    {
        self::ensureTables($db);
        $row = $db->query('SELECT * FROM zone_settings LIMIT 1')->fetch();
        if (!$row) {
            $db->prepare('INSERT INTO zone_settings (id) VALUES (?)')->execute([Db::uuid()]);
            $row = $db->query('SELECT * FROM zone_settings LIMIT 1')->fetch();
        }
        return $row ?: ['enabled' => 0, 'apply_multipliers' => 0, 'add_options' => 1, 'fallback_to_tariff' => 1];
    }

    public static function updateSettings(\PDO $db, array $fields): array
    {
        $s = self::settings($db);
        $db->prepare(
            'UPDATE zone_settings SET enabled=?, apply_multipliers=?, add_options=?, fallback_to_tariff=?, updated_at=? WHERE id=?'
        )->execute([
            !empty($fields['enabled']) ? 1 : 0,
            !empty($fields['applyMultipliers']) ? 1 : 0,
            !empty($fields['addOptions']) ? 1 : 0,
            !empty($fields['fallbackToTariff']) ? 1 : 0,
            Db::utcNow(),
            $s['id'],
        ]);
        return self::settings($db);
    }

    /** Все активные зоны, отсортированные по приоритету (выше — важнее). */
    public static function activeZones(\PDO $db): array
    {
        self::ensureTables($db);
        $rows = $db->query(
            'SELECT * FROM zones WHERE is_active = 1 ORDER BY priority DESC, name'
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['points'] = self::decodePolygon((string) $row['polygon']);
        }
        return $rows;
    }

    public static function decodePolygon(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) return [];
        $points = [];
        foreach ($data as $point) {
            if (is_array($point) && count($point) >= 2) {
                $points[] = [(float) $point[0], (float) $point[1]];
            }
        }
        return $points;
    }

    /** Валидация полигона: минимум 3 точки в допустимых координатах. */
    public static function normalizePolygon(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                // Поддержка простого формата "lat,lng; lat,lng; ..."
                $decoded = [];
                foreach (preg_split('/[;\n]+/', $raw) as $line) {
                    $parts = array_map('trim', explode(',', $line));
                    if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                        $decoded[] = [(float) $parts[0], (float) $parts[1]];
                    }
                }
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            throw new \RuntimeException('Полигон зоны должен быть массивом точек [lat, lng]');
        }
        $points = [];
        foreach ($raw as $point) {
            $lat = is_array($point) ? (float) ($point[0] ?? $point['lat'] ?? 0) : 0;
            $lng = is_array($point) ? (float) ($point[1] ?? $point['lng'] ?? 0) : 0;
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw new \RuntimeException('Координаты зоны вне допустимого диапазона');
            }
            $points[] = [round($lat, 6), round($lng, 6)];
        }
        if (count($points) < 3) {
            throw new \RuntimeException('Зона должна содержать минимум 3 точки');
        }
        return $points;
    }

    /** Ray casting: точка внутри полигона. */
    public static function pointInPolygon(float $lat, float $lng, array $points): bool
    {
        $inside = false;
        $count = count($points);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = $points[$i][0];
            $lngI = $points[$i][1];
            $latJ = $points[$j][0];
            $lngJ = $points[$j][1];
            $intersects = (($lngI > $lng) !== ($lngJ > $lng))
                && ($lat < ($latJ - $latI) * ($lng - $lngI) / (($lngJ - $lngI) ?: 1e-12) + $latI);
            if ($intersects) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /** Зона, которой принадлежит точка (первая по приоритету). */
    public static function findZone(\PDO $db, float $lat, float $lng, ?array $zones = null): ?array
    {
        foreach ($zones ?? self::activeZones($db) as $zone) {
            if ($zone['points'] && self::pointInPolygon($lat, $lng, $zone['points'])) {
                return $zone;
            }
        }
        return null;
    }

    /**
     * Фиксированная цена маршрута между зонами.
     * Возвращает null, если зональная тарификация неприменима.
     */
    public static function fixedPrice(
        \PDO $db,
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        string $tariff
    ): ?array {
        $settings = self::settings($db);
        if ((int) $settings['enabled'] !== 1) {
            return null;
        }
        $zones = self::activeZones($db);
        if (!$zones) {
            return null;
        }
        $from = self::findZone($db, $fromLat, $fromLng, $zones);
        $to = self::findZone($db, $toLat, $toLng, $zones);
        if (!$from || !$to) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT price FROM zone_prices
             WHERE from_zone_id = ? AND to_zone_id = ? AND tariff = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$from['id'], $to['id'], $tariff]);
        $price = $stmt->fetchColumn();

        if ($price === false) {
            // Обратное направление, если отдельная цена не задана
            $stmt->execute([$to['id'], $from['id'], $tariff]);
            $price = $stmt->fetchColumn();
        }
        if ($price === false) {
            return null;
        }

        return [
            'price' => (float) $price,
            'fromZone' => ['id' => $from['id'], 'name' => $from['name'], 'color' => $from['color']],
            'toZone' => ['id' => $to['id'], 'name' => $to['name'], 'color' => $to['color']],
            'applyMultipliers' => (bool) $settings['apply_multipliers'],
            'addOptions' => (bool) $settings['add_options'],
        ];
    }

    /** Матрица цен для админки: [from][to][tariff] => price. */
    public static function priceMatrix(\PDO $db): array
    {
        self::ensureTables($db);
        $matrix = [];
        foreach ($db->query('SELECT * FROM zone_prices')->fetchAll() as $row) {
            $matrix[$row['from_zone_id']][$row['to_zone_id']][$row['tariff']] = [
                'price' => (float) $row['price'],
                'isActive' => (bool) $row['is_active'],
            ];
        }
        return $matrix;
    }

    public static function setPrice(
        \PDO $db,
        string $fromZoneId,
        string $toZoneId,
        string $tariff,
        ?float $price
    ): void {
        self::ensureTables($db);
        if ($price === null || $price <= 0) {
            $db->prepare('DELETE FROM zone_prices WHERE from_zone_id=? AND to_zone_id=? AND tariff=?')
                ->execute([$fromZoneId, $toZoneId, $tariff]);
            return;
        }
        $db->prepare(
            'INSERT INTO zone_prices (id, from_zone_id, to_zone_id, tariff, price, updated_at)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE price = VALUES(price), is_active = 1, updated_at = VALUES(updated_at)'
        )->execute([Db::uuid(), $fromZoneId, $toZoneId, $tariff, round($price, 2), Db::utcNow()]);
    }

    public static function deleteZone(\PDO $db, string $zoneId): void
    {
        $db->prepare('DELETE FROM zone_prices WHERE from_zone_id=? OR to_zone_id=?')
            ->execute([$zoneId, $zoneId]);
        $db->prepare('DELETE FROM zones WHERE id=?')->execute([$zoneId]);
    }
}
