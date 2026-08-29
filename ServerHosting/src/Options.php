<?php
// Опции заказа (OrderOption.cs)
declare(strict_types=1);

final class Options
{
    public const LIST = [
        ['code' => 'child_seat',    'name' => 'Детское кресло',      'price' => 50],
        ['code' => 'pet',           'name' => 'Перевозка животного', 'price' => 70],
        ['code' => 'meeting_sign',  'name' => 'Встреча с табличкой', 'price' => 100],
        ['code' => 'extra_luggage', 'name' => 'Крупный багаж',       'price' => 30],
        ['code' => 'non_smoking',   'name' => 'Некурящий салон',     'price' => 0],
    ];

    public static function resolve(array $codes): array
    {
        $out = [];
        foreach (self::LIST as $opt) {
            if (in_array($opt['code'], $codes, true)) {
                $out[] = $opt;
            }
        }
        return $out;
    }

    public static function total(array $codes): float
    {
        $sum = 0.0;
        foreach (self::LIST as $opt) {
            if (in_array($opt['code'], $codes, true)) {
                $sum += $opt['price'];
            }
        }
        return $sum;
    }
}
