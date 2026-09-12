<?php
// Справочник мест и организаций: ТРЦ, кинотеатры, вокзалы, больницы, кафе.
// Пассажир часто не знает адрес («Гудвин», «Киномакс»), поэтому поиск идёт
// по названию, а в заказ подставляется точный адрес с координатами.
//
// Источники: встроенный список ориентиров, импорт из OpenStreetMap (ODbL)
// и ручные записи администратора.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Places
{
    /** Категории справочника: ключ => [название, теги OSM для импорта]. */
    public const CATEGORIES = [
        'mall'       => ['ТРЦ и магазины',     ['shop=mall', 'shop=department_store', 'shop=supermarket']],
        'cinema'     => ['Кино и театры',      ['amenity=cinema', 'amenity=theatre']],
        'transport'  => ['Вокзалы и аэропорт', ['aeroway=aerodrome', 'railway=station', 'amenity=bus_station']],
        'medicine'   => ['Медицина',           ['amenity=hospital', 'amenity=clinic', 'amenity=doctors']],
        'education'  => ['Образование',        ['amenity=university', 'amenity=college', 'amenity=school']],
        'food'       => ['Кафе и рестораны',   ['amenity=restaurant', 'amenity=cafe', 'amenity=fast_food']],
        'hotel'      => ['Гостиницы',          ['tourism=hotel', 'tourism=hostel']],
        'sport'      => ['Спорт и отдых',      ['leisure=sports_centre', 'leisure=fitness_centre', 'leisure=stadium']],
        'service'    => ['Услуги и банки',     ['amenity=bank', 'amenity=pharmacy', 'amenity=fuel', 'amenity=post_office']],
        'government' => ['Госучреждения',      ['amenity=townhall', 'office=government', 'amenity=police']],
        'other'      => ['Прочее',             []],
    ];

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS places (
                id          CHAR(36) PRIMARY KEY,
                name        VARCHAR(160) NOT NULL,
                search_name VARCHAR(160) NOT NULL,
                aliases     VARCHAR(400) NOT NULL DEFAULT '',
                category    VARCHAR(30)  NOT NULL DEFAULT 'other',
                address     VARCHAR(255) NOT NULL DEFAULT '',
                latitude    DOUBLE NOT NULL,
                longitude   DOUBLE NOT NULL,
                osm_id      VARCHAR(40) NULL,
                source      VARCHAR(20) NOT NULL DEFAULT 'manual',
                is_active   TINYINT(1) NOT NULL DEFAULT 1,
                usage_count INT NOT NULL DEFAULT 0,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME NULL,
                UNIQUE KEY uniq_osm (osm_id),
                INDEX (is_active, category), INDEX (search_name), INDEX (usage_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::seedBuiltIn($db);
    }

    /** Встроенные ориентиры города — чтобы поиск работал сразу после обновления. */
    private static function seedBuiltIn(\PDO $db): void
    {
        try {
            $exists = (int) $db->query("SELECT COUNT(*) FROM places WHERE source='builtin'")->fetchColumn();
            if ($exists > 0) return;
        } catch (\Throwable) {
            return;
        }

        $categoryOf = static function (string $name): string {
            $n = mb_strtolower($name);
            if (str_contains($n, 'трц') || str_contains($n, 'тц')) return 'mall';
            if (str_contains($n, 'аэропорт') || str_contains($n, 'вокзал')) return 'transport';
            if (str_contains($n, 'театр') || str_contains($n, 'дк ')) return 'cinema';
            if (str_contains($n, 'тюмгу') || str_contains($n, 'университет')) return 'education';
            return 'other';
        };

        $stmt = $db->prepare(
            'INSERT IGNORE INTO places (id,name,search_name,category,latitude,longitude,source)
             VALUES (?,?,?,?,?,?,\'builtin\')'
        );
        foreach (Taxi::PLACES as $p) {
            $name = (string) $p['name'];
            // Улицы с домами не место, а адрес — их ищет обычный геокодер
            if (preg_match('/^ул\./u', $name)) continue;
            $stmt->execute([
                Db::uuid(), $name, self::normalize($name), $categoryOf($name),
                (float) $p['lat'], (float) $p['lng'],
            ]);
        }
    }

    /** Нормализация названия: регистр, ё/е, кавычки и лишние символы. */
    public static function normalize(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $v = str_replace(['ё', '«', '»', '"', "'", '’'], ['е', '', '', '', '', ''], $v);
        $v = preg_replace('/[^а-яa-z0-9]+/u', ' ', $v) ?? $v;
        return trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
    }

    /**
     * Поиск мест по названию. Возвращает элементы в формате подсказок
     * геокодера: displayName содержит и название, и адрес.
     */
    public static function search(\PDO $db, string $query, int $limit = 5): array
    {
        $q = self::normalize($query);
        if (mb_strlen($q) < 2) return [];

        try {
            self::ensureTables($db);
            // Отсекаем служебные слова, чтобы «трц гудвин» находил «Гудвин»
            $words = array_values(array_filter(
                explode(' ', $q),
                static fn(string $w) => mb_strlen($w) >= 2
                    && !in_array($w, ['трц', 'тц', 'тд', 'ул', 'дом', 'кафе', 'магазин'], true)
            ));
            $needle = $words ? implode(' ', $words) : $q;

            // Плейсхолдеры не повторяются: PDO настроен на нативные
            // подготовленные запросы (ATTR_EMULATE_PREPARES=false), и одно
            // и то же имя параметра дважды вызвало бы ошибку MySQL.
            $stmt = $db->prepare(
                "SELECT *,
                    CASE
                        WHEN search_name = ? THEN 0
                        WHEN search_name LIKE ? THEN 1
                        WHEN search_name LIKE ? THEN 2
                        ELSE 3
                    END AS match_rank
                 FROM places
                 WHERE is_active = 1
                   AND (search_name LIKE ? OR aliases LIKE ?)
                 ORDER BY match_rank ASC, usage_count DESC, name ASC
                 LIMIT $limit"
            );
            $contains = '%' . $needle . '%';
            $stmt->execute([$needle, $needle . '%', $contains, $contains, $contains]);

            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $label = (string) $row['name'];
                $address = trim((string) $row['address']);
                $out[] = [
                    'displayName' => $address !== '' ? $label . ', ' . $address : $label,
                    'fullAddress' => $address !== '' ? $address : $label,
                    'latitude' => (float) $row['latitude'],
                    'longitude' => (float) $row['longitude'],
                    'source' => 'place',
                    'hasCoordinates' => true,
                    'verifiedLocal' => true,
                    'placeId' => $row['id'],
                    'placeCategory' => $row['category'],
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Отметить использование места — популярные поднимаются в подсказках. */
    public static function touch(\PDO $db, string $placeId): void
    {
        try {
            $db->prepare('UPDATE places SET usage_count = usage_count + 1 WHERE id = ?')
                ->execute([$placeId]);
        } catch (\Throwable) {
        }
    }

    /**
     * Отметить использование места по адресу заказа: адрес приходит в виде
     * «Название, улица, дом», поэтому сверяем начало строки с названием.
     */
    public static function touchByAddress(\PDO $db, string $address): void
    {
        try {
            $normalized = self::normalize($address);
            if ($normalized === '') return;
            $stmt = $db->prepare(
                'SELECT id FROM places
                 WHERE is_active = 1 AND search_name <> \'\' AND ? LIKE CONCAT(search_name, \'%\')
                 ORDER BY CHAR_LENGTH(search_name) DESC LIMIT 1'
            );
            $stmt->execute([$normalized]);
            if ($id = $stmt->fetchColumn()) self::touch($db, (string) $id);
        } catch (\Throwable) {
        }
    }

    public static function save(\PDO $db, array $data): string
    {
        self::ensureTables($db);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') throw new \RuntimeException('Укажите название места');
        $lat = (float) ($data['latitude'] ?? 0);
        $lng = (float) ($data['longitude'] ?? 0);
        if ($lat == 0.0 || $lng == 0.0) throw new \RuntimeException('Укажите координаты места');

        $category = (string) ($data['category'] ?? 'other');
        if (!isset(self::CATEGORIES[$category])) $category = 'other';

        $id = (string) ($data['id'] ?? '');
        $aliases = self::normalize((string) ($data['aliases'] ?? ''));
        $address = mb_substr(trim((string) ($data['address'] ?? '')), 0, 255);

        if ($id !== '') {
            $db->prepare(
                'UPDATE places SET name=?,search_name=?,aliases=?,category=?,address=?,
                 latitude=?,longitude=?,is_active=?,updated_at=? WHERE id=?'
            )->execute([
                $name, self::normalize($name), $aliases, $category, $address,
                $lat, $lng, !empty($data['is_active']) ? 1 : 0, Db::utcNow(), $id,
            ]);
            return $id;
        }

        $id = Db::uuid();
        $db->prepare(
            'INSERT INTO places (id,name,search_name,aliases,category,address,latitude,longitude,source,is_active)
             VALUES (?,?,?,?,?,?,?,?,\'manual\',1)'
        )->execute([$id, $name, self::normalize($name), $aliases, $category, $address, $lat, $lng]);
        return $id;
    }

    public static function delete(\PDO $db, string $id): void
    {
        $db->prepare('DELETE FROM places WHERE id = ?')->execute([$id]);
    }

    /**
     * Импорт организаций из OpenStreetMap (Overpass API) вокруг города.
     * Данные ODbL: бесплатно, без ключей и лимитов по договору.
     *
     * @return array{imported:int,updated:int,skipped:int,error:?string}
     */
    public static function importFromOsm(\PDO $db, float $lat, float $lng, float $radiusKm = 25): array
    {
        self::ensureTables($db);
        $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'error' => null];

        // Собираем один запрос по всем категориям сразу — Overpass не любит частые вызовы
        $filters = [];
        foreach (self::CATEGORIES as $meta) {
            foreach ($meta[1] as $tag) {
                [$key, $value] = explode('=', $tag, 2);
                $filters[] = sprintf('nwr["%s"="%s"]["name"](around:%d,%F,%F);',
                    $key, $value, (int) ($radiusKm * 1000), $lat, $lng);
            }
        }
        $query = "[out:json][timeout:120];(" . implode('', $filters) . ");out center tags;";

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 180,
            'ignore_errors' => true,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                . "User-Agent: TaxiTyumen/1.0 (+" . PUBLIC_BASE_URL . ")\r\n",
            'content' => http_build_query(['data' => $query]),
        ]]);
        $raw = @file_get_contents('https://overpass-api.de/api/interpreter', false, $ctx);
        $json = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($json) || !isset($json['elements'])) {
            $result['error'] = 'Overpass API не ответил. Повторите позже.';
            return $result;
        }

        $categoryByTag = [];
        foreach (self::CATEGORIES as $key => $meta) {
            foreach ($meta[1] as $tag) $categoryByTag[$tag] = $key;
        }

        $insert = $db->prepare(
            'INSERT INTO places (id,name,search_name,category,address,latitude,longitude,osm_id,source,is_active)
             VALUES (?,?,?,?,?,?,?,?,\'osm\',1)
             ON DUPLICATE KEY UPDATE name=VALUES(name),search_name=VALUES(search_name),
               category=VALUES(category),address=VALUES(address),
               latitude=VALUES(latitude),longitude=VALUES(longitude),updated_at=NOW()'
        );

        foreach ($json['elements'] as $el) {
            $tags = $el['tags'] ?? [];
            $name = trim((string) ($tags['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 160) { $result['skipped']++; continue; }

            $pLat = (float) ($el['lat'] ?? $el['center']['lat'] ?? 0);
            $pLng = (float) ($el['lon'] ?? $el['center']['lon'] ?? 0);
            if ($pLat == 0.0 || $pLng == 0.0) { $result['skipped']++; continue; }

            $category = 'other';
            foreach ($categoryByTag as $tag => $key) {
                [$tagKey, $tagValue] = explode('=', $tag, 2);
                if (($tags[$tagKey] ?? null) === $tagValue) { $category = $key; break; }
            }

            // Адрес из OSM, если он проставлен
            $street = trim((string) ($tags['addr:street'] ?? ''));
            $house = trim((string) ($tags['addr:housenumber'] ?? ''));
            $address = $street !== '' ? trim($street . ($house !== '' ? ', ' . $house : '')) : '';

            $osmId = (string) ($el['type'] ?? 'node') . '/' . (string) ($el['id'] ?? '');
            $existing = $db->prepare('SELECT id FROM places WHERE osm_id = ? LIMIT 1');
            $existing->execute([$osmId]);
            $isUpdate = (bool) $existing->fetchColumn();

            $insert->execute([
                Db::uuid(), $name, self::normalize($name), $category,
                mb_substr($address, 0, 255), $pLat, $pLng, $osmId,
            ]);
            $isUpdate ? $result['updated']++ : $result['imported']++;
        }
        return $result;
    }

    /** Сводка по справочнику для админки. */
    public static function stats(\PDO $db): array
    {
        self::ensureTables($db);
        $total = (int) $db->query('SELECT COUNT(*) FROM places WHERE is_active=1')->fetchColumn();
        $byCategory = [];
        foreach ($db->query(
            'SELECT category, COUNT(*) c FROM places WHERE is_active=1 GROUP BY category'
        )->fetchAll() as $row) {
            $byCategory[(string) $row['category']] = (int) $row['c'];
        }
        return ['total' => $total, 'byCategory' => $byCategory];
    }
}
