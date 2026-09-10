<?php
// Управление провайдерами геокодинга: включение/выключение, порядок
// и выбор основного. Настройки хранятся в БД и правятся из админки.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class GeoProviders
{
    /**
     * Реестр провайдеров.
     * keyName — ключ в разделе «API-ключи» (null = ключ не нужен).
     */
    public const REGISTRY = [
        'dadata' => [
            'label' => 'DaData',
            'hint' => 'Официальный реестр адресов РФ (ФИАС). Самые точные подсказки по России.',
            'keyName' => 'dadata',
            'defaultOrder' => 10,
        ],
        'photon' => [
            'label' => 'Photon / OpenStreetMap',
            'hint' => 'Бесплатное автодополнение без ключа. Работает по всему миру.',
            'keyName' => null,
            'defaultOrder' => 20,
        ],
        'yandex' => [
            'label' => 'Яндекс Геокодер',
            'hint' => 'HTTP Геокодер Яндекс Карт. Требует ключ «Яндекс Карты».',
            'keyName' => 'yandex_maps',
            'defaultOrder' => 30,
        ],
        'opencage' => [
            'label' => 'OpenCage Data',
            'hint' => 'Резервный геокодер OSM. Не поддерживает автодополнение — держите последним.',
            'keyName' => 'opencage',
            'defaultOrder' => 40,
        ],
        'tomtom' => [
            'label' => 'TomTom Search',
            'hint' => 'Поиск адресов и объектов TomTom. Работает, если в разделе «TomTom» '
                . 'включены сервисы Search / Reverse Geocoding.',
            'keyName' => 'tomtom_traffic',
            'defaultOrder' => 50,
        ],
    ];

    private static ?array $cache = null;

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS geo_providers (
                provider VARCHAR(30) PRIMARY KEY,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 100,
                updated_at DATETIME NULL,
                updated_by CHAR(36) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Сид значениями по умолчанию; повторный вызов не меняет настройки админа
        $seed = $db->prepare(
            'INSERT IGNORE INTO geo_providers (provider, is_enabled, sort_order) VALUES (?,1,?)'
        );
        foreach (self::REGISTRY as $name => $meta) {
            $seed->execute([$name, (int) $meta['defaultOrder']]);
        }
    }

    /** Настройки всех провайдеров в порядке приоритета. */
    public static function all(\PDO $db): array
    {
        if (self::$cache !== null) return self::$cache;

        self::ensureTables($db);
        $rows = [];
        try {
            foreach ($db->query('SELECT * FROM geo_providers')->fetchAll() as $row) {
                $rows[(string) $row['provider']] = $row;
            }
        } catch (\Throwable) {
        }

        $out = [];
        foreach (self::REGISTRY as $name => $meta) {
            $row = $rows[$name] ?? null;
            $keyName = $meta['keyName'];
            $hasKey = $keyName === null || api_key($keyName) !== '';
            $out[$name] = [
                'name' => $name,
                'label' => $meta['label'],
                'hint' => $meta['hint'],
                'keyName' => $keyName,
                'hasKey' => $hasKey,
                'enabled' => $row ? (bool) $row['is_enabled'] : true,
                'order' => (int) ($row['sort_order'] ?? $meta['defaultOrder']),
            ];
        }

        uasort($out, fn(array $a, array $b) => $a['order'] <=> $b['order']);
        self::$cache = $out;
        return $out;
    }

    /**
     * Провайдеры, готовые к работе: включены и имеют ключ (если он требуется).
     * Возвращает имена в порядке приоритета — первый является основным.
     */
    public static function active(\PDO $db): array
    {
        $names = [];
        foreach (self::all($db) as $name => $p) {
            if ($p['enabled'] && $p['hasKey']) $names[] = $name;
        }
        return $names;
    }

    public static function isActive(\PDO $db, string $provider): bool
    {
        return in_array($provider, self::active($db), true);
    }

    /** Основной провайдер — первый в списке активных. */
    public static function primary(\PDO $db): ?string
    {
        return self::active($db)[0] ?? null;
    }

    public static function setEnabled(\PDO $db, string $provider, bool $enabled, ?string $userId = null): void
    {
        if (!isset(self::REGISTRY[$provider])) {
            throw new \RuntimeException('Неизвестный провайдер: ' . $provider);
        }
        self::ensureTables($db);
        $db->prepare(
            'INSERT INTO geo_providers (provider, is_enabled, sort_order, updated_at, updated_by)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),
             updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)'
        )->execute([
            $provider, $enabled ? 1 : 0,
            (int) self::REGISTRY[$provider]['defaultOrder'], Db::utcNow(), $userId,
        ]);
        self::$cache = null;
    }

    /** Сделать провайдера основным: он получает наивысший приоритет. */
    public static function setPrimary(\PDO $db, string $provider, ?string $userId = null): void
    {
        if (!isset(self::REGISTRY[$provider])) {
            throw new \RuntimeException('Неизвестный провайдер: ' . $provider);
        }
        self::ensureTables($db);

        $order = 10;
        $stmt = $db->prepare(
            'INSERT INTO geo_providers (provider, is_enabled, sort_order, updated_at, updated_by)
             VALUES (?,1,?,?,?)
             ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order),
             updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)'
        );
        // Выбранный — первым, остальные сохраняют относительный порядок
        $stmt->execute([$provider, $order, Db::utcNow(), $userId]);

        $current = self::all($db);
        self::$cache = null;
        foreach ($current as $name => $p) {
            if ($name === $provider) continue;
            $order += 10;
            $db->prepare(
                'INSERT INTO geo_providers (provider, is_enabled, sort_order, updated_at, updated_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order),
                 updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)'
            )->execute([$name, $p['enabled'] ? 1 : 0, $order, Db::utcNow(), $userId]);
        }
        self::$cache = null;
    }

    /** Переместить провайдера вверх или вниз в очереди опроса. */
    public static function move(\PDO $db, string $provider, int $direction, ?string $userId = null): void
    {
        $names = array_keys(self::all($db));
        $index = array_search($provider, $names, true);
        if ($index === false) return;

        $target = $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= count($names)) return;

        [$names[$index], $names[$target]] = [$names[$target], $names[$index]];

        $order = 10;
        $current = self::all($db);
        self::$cache = null;
        foreach ($names as $name) {
            $db->prepare(
                'INSERT INTO geo_providers (provider, is_enabled, sort_order, updated_at, updated_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order),
                 updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)'
            )->execute([$name, $current[$name]['enabled'] ? 1 : 0, $order, Db::utcNow(), $userId]);
            $order += 10;
        }
        self::$cache = null;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
