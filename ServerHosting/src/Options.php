<?php
// Опции заказа (OrderOption.cs): справочник хранится в БД и редактируется
// из админки («Опции заказа»). Приложения и пульт грузят его с сервера —
// цены меняются без обновления приложений. LIST — значения по умолчанию для сида.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Options
{
    public const LIST = [
        ['code' => 'child_seat',    'name' => 'Детское кресло',      'price' => 50],
        ['code' => 'pet',           'name' => 'Перевозка животного', 'price' => 70],
        ['code' => 'meeting_sign',  'name' => 'Встреча с табличкой', 'price' => 100],
        ['code' => 'extra_luggage', 'name' => 'Крупный багаж',       'price' => 30],
        ['code' => 'non_smoking',   'name' => 'Некурящий салон',     'price' => 0],
    ];

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS order_option_settings (
                code VARCHAR(40) PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                price DOUBLE NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Сид значений по умолчанию; INSERT IGNORE — свежие коды из LIST добавятся
        // в уже работающую базу, админские правки цен не затираются.
        $seed = $db->prepare(
            'INSERT IGNORE INTO order_option_settings (code, name, price, is_active, sort_order) VALUES (?,?,?,1,?)'
        );
        $sort = 0;
        foreach (self::LIST as $opt) {
            $seed->execute([(string) $opt['code'], (string) $opt['name'], (float) $opt['price'], $sort++]);
        }
    }

    /** Справочник опций; onlyActive=false — полный список для админки. */
    public static function all(\PDO $db, bool $onlyActive = true): array
    {
        self::ensureTables($db);
        $sql = 'SELECT * FROM order_option_settings'
            . ($onlyActive ? ' WHERE is_active = 1' : '')
            . ' ORDER BY sort_order, code';
        return array_map(
            static fn(array $r) => [
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
                'price' => (float) $r['price'],
                'isActive' => (bool) $r['is_active'],
                'sortOrder' => (int) $r['sort_order'],
            ],
            $db->query($sql)->fetchAll()
        );
    }

    /** Активные опции по переданным кодам (снимок для order_options). */
    public static function resolve(\PDO $db, array $codes): array
    {
        $codes = array_values(array_filter($codes, 'is_string'));
        $out = [];
        foreach (self::all($db, true) as $opt) {
            if (in_array($opt['code'], $codes, true)) {
                $out[] = ['code' => $opt['code'], 'name' => $opt['name'], 'price' => $opt['price']];
            }
        }
        return $out;
    }

    public static function total(\PDO $db, array $codes): float
    {
        $sum = 0.0;
        foreach (self::resolve($db, $codes) as $opt) {
            $sum += (float) $opt['price'];
        }
        return $sum;
    }

    /** Админка: название/цена/доступность существующей опции. */
    public static function update(\PDO $db, string $code, string $name, float $price, bool $isActive): void
    {
        $stmt = $db->prepare(
            'UPDATE order_option_settings SET name = ?, price = ?, is_active = ?, updated_at = ? WHERE code = ?'
        );
        $stmt->execute([$name, $price, $isActive ? 1 : 0, Db::utcNow(), $code]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Опция не найдена: ' . $code);
        }
    }

    /** Новая опция справочника — приложения подхватят её без обновления. */
    public static function create(\PDO $db, string $code, string $name, float $price): void
    {
        $sort = (int) $db->query('SELECT COALESCE(MAX(sort_order),-1)+1 FROM order_option_settings')->fetchColumn();
        $db->prepare(
            'INSERT INTO order_option_settings (code, name, price, is_active, sort_order, updated_at) VALUES (?,?,?,1,?,?)'
        )->execute([$code, $name, $price, $sort, Db::utcNow()]);
    }

    /** Удаление из справочника; снимки order_options в истории заказов не трогаем. */
    public static function delete(\PDO $db, string $code): void
    {
        $db->prepare('DELETE FROM order_option_settings WHERE code = ?')->execute([$code]);
    }
}
