<?php
// Единый бренд сервиса: название, город, регион, центр карты, часовой пояс,
// имя SMS-отправителя. Единственный источник истины для всех интерфейсов.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class ServiceSettings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'service_name'      => 'Такси Тюмень',
        'city_name'         => 'Тюмень',
        'region_name'       => 'Тюменская область',
        'region_code'       => '72',
        'support_phone'     => '+7 (3452) 000-000',
        'center_latitude'   => 57.1522,
        'center_longitude'  => 65.5272,
        'utc_offset'        => 5,
        'sms_sender_name'   => 'Такси Тюмень',
        // SMS-оповещения пассажира (0/1)
        'sms_on_assigned'   => 1,
        'sms_on_arrived'    => 1,
    ];

    public static function ensureTable(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS service_settings (
                id CHAR(36) PRIMARY KEY,
                service_name VARCHAR(80) NOT NULL,
                city_name VARCHAR(80) NOT NULL,
                region_name VARCHAR(120) NOT NULL,
                region_code VARCHAR(10) NOT NULL DEFAULT '',
                support_phone VARCHAR(30) NULL,
                center_latitude DOUBLE NOT NULL,
                center_longitude DOUBLE NOT NULL,
                utc_offset INT NOT NULL DEFAULT 5,
                sms_sender_name VARCHAR(80) NOT NULL,
                sms_on_assigned TINYINT(1) NOT NULL DEFAULT 1,
                sms_on_arrived TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Миграция уже установленных баз
        foreach ([
            'sms_on_assigned' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER sms_sender_name",
            'sms_on_arrived'  => "TINYINT(1) NOT NULL DEFAULT 1 AFTER sms_on_assigned",
        ] as $column => $definition) {
            try {
                $exists = $db->prepare(
                    'SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
                );
                $exists->execute(['service_settings', $column]);
                if ((int) $exists->fetchColumn() === 0) {
                    $db->exec("ALTER TABLE service_settings ADD COLUMN `$column` $definition");
                }
            } catch (\Throwable) {
            }
        }
    }

    public static function get(\PDO $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::ensureTable($db);
        $row = $db->query('SELECT * FROM service_settings LIMIT 1')->fetch();
        if (!$row) {
            $db->prepare(
                'INSERT INTO service_settings
                 (id,service_name,city_name,region_name,region_code,support_phone,
                  center_latitude,center_longitude,utc_offset,sms_sender_name,
                  sms_on_assigned,sms_on_arrived)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute(array_merge([Db::uuid()], array_values(self::DEFAULTS)));
            $row = $db->query('SELECT * FROM service_settings LIMIT 1')->fetch();
        }
        self::$cache = array_merge(self::DEFAULTS, $row ?: []);
        return self::$cache;
    }

    public static function update(\PDO $db, array $fields): array
    {
        $current = self::get($db);
        $name = trim((string) ($fields['serviceName'] ?? $current['service_name']));
        if ($name === '') {
            $name = $current['service_name'];
        }
        $city = trim((string) ($fields['city'] ?? $current['city_name'])) ?: $current['city_name'];
        $region = trim((string) ($fields['region'] ?? $current['region_name'])) ?: $current['region_name'];
        $regionCode = trim((string) ($fields['regionCode'] ?? $current['region_code']));
        $phone = trim((string) ($fields['supportPhone'] ?? $current['support_phone']));
        $centerLat = (float) ($fields['centerLat'] ?? $current['center_latitude']);
        $centerLng = (float) ($fields['centerLng'] ?? $current['center_longitude']);
        $utcOffset = max(-11, min(13, (int) ($fields['utcOffset'] ?? $current['utc_offset'])));
        $smsName = trim((string) ($fields['smsSenderName'] ?? $current['sms_sender_name'])) ?: $name;
        $smsAssigned = array_key_exists('smsOnAssigned', $fields)
            ? (!empty($fields['smsOnAssigned']) ? 1 : 0)
            : (int) ($current['sms_on_assigned'] ?? 1);
        $smsArrived = array_key_exists('smsOnArrived', $fields)
            ? (!empty($fields['smsOnArrived']) ? 1 : 0)
            : (int) ($current['sms_on_arrived'] ?? 1);

        if ($centerLat < -90 || $centerLat > 90 || $centerLng < -180 || $centerLng > 180) {
            throw new RuntimeException('Координаты центра вне допустимого диапазона');
        }

        $db->prepare(
            'UPDATE service_settings SET service_name=?,city_name=?,region_name=?,region_code=?,
             support_phone=?,center_latitude=?,center_longitude=?,utc_offset=?,sms_sender_name=?,
             sms_on_assigned=?,sms_on_arrived=?,updated_at=? WHERE id=?'
        )->execute([
            mb_substr($name, 0, 80), mb_substr($city, 0, 80), mb_substr($region, 0, 120),
            mb_substr($regionCode, 0, 10), $phone !== '' ? mb_substr($phone, 0, 30) : null,
            $centerLat, $centerLng, $utcOffset, mb_substr($smsName, 0, 80),
            $smsAssigned, $smsArrived, Db::utcNow(), $current['id'],
        ]);

        self::$cache = null;
        return self::get($db);
    }

    // Час суток в городе сервиса (для ночного/пикового тарифа)
    public static function localHour(\PDO $db): int
    {
        $settings = self::get($db);
        return ((int) gmdate('G') + (int) $settings['utc_offset'] + 24) % 24;
    }

    public static function toDto(array $s): array
    {
        return [
            'smsOnAssigned'  => (bool) ($s['sms_on_assigned'] ?? true),
            'smsOnArrived'   => (bool) ($s['sms_on_arrived'] ?? true),
            'serviceName'    => $s['service_name'],
            'city'           => $s['city_name'],
            'region'         => $s['region_name'],
            'regionCode'     => $s['region_code'],
            'supportPhone'   => $s['support_phone'],
            'centerLat'      => (float) $s['center_latitude'],
            'centerLng'      => (float) $s['center_longitude'],
            'utcOffset'      => (int) $s['utc_offset'],
            'smsSenderName'  => $s['sms_sender_name'],
            'updatedAt'      => $s['updated_at'] ?? null,
        ];
    }
}
