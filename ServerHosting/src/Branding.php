<?php
// Серверное брендирование приложений (хранение в MySQL, рендер через API)
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Branding
{
    public const APPS = ['client', 'driver', 'operator'];

    public const DEFAULTS = [
        'client' => [
            'app_name' => 'Приложение клиента',
            'app_code' => 'TaxiClient · Web',
            'hero_title' => 'Заказ такси по Тюмени',
            'hero_subtitle' => 'Живая оценка цены по реальным дорогам, отслеживание водителя на карте и чат с ним.',
            'logo_icon' => 'taxi',
            'primary_color' => '#facc15',
            'primary_text_color' => '#0a0a0c',
            'support_phone' => '+7 (3452) 000-000',
            'features' => ['Заказ за пару минут', 'Реальные тарифы и маршрут', 'Чат с водителем'],
        ],
        'driver' => [
            'app_name' => 'Приложение водителя',
            'app_code' => 'TaxiDriver · Web',
            'hero_title' => 'Работа на линии',
            'hero_subtitle' => 'Лента заказов рядом с вами, управление поездкой в одну кнопку, баланс и комиссии прозрачно.',
            'logo_icon' => 'car',
            'primary_color' => '#34d399',
            'primary_text_color' => '#052e22',
            'support_phone' => '+7 (3452) 000-001',
            'features' => ['Лента доступных заказов', 'Этапы поездки одной кнопкой', 'Баланс, комиссии, заработок'],
        ],
        'operator' => [
            'app_name' => 'Диспетчерская',
            'app_code' => 'TaxiOperator · Web',
            'hero_title' => 'Пульт оператора',
            'hero_subtitle' => 'Приём заказов со звонка, карта автопарка в реальном времени и распределение водителей.',
            'logo_icon' => 'headset',
            'primary_color' => '#38bdf8',
            'primary_text_color' => '#082231',
            'support_phone' => '+7 (3452) 000-002',
            'features' => ['Табло активных заказов', 'Приём заказа со звонка', 'Назначение водителей вручную'],
        ],
    ];

    public static function ensureSeeded(\PDO $db): void
    {
        $existing = $db->query('SELECT app FROM branding_settings')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (self::APPS as $app) {
            if (!in_array($app, $existing, true)) {
                $d = self::DEFAULTS[$app];
                $db->prepare(
                    'INSERT INTO branding_settings
                     (id, app, app_name, app_code, hero_title, hero_subtitle, logo_icon,
                      primary_color, primary_text_color, support_phone, features)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    Db::uuid(), $app, $d['app_name'], $d['app_code'], $d['hero_title'],
                    $d['hero_subtitle'], $d['logo_icon'], $d['primary_color'],
                    $d['primary_text_color'], $d['support_phone'], json_encode($d['features'], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }

    public static function toDto(array $row): array
    {
        return [
            'app' => $row['app'],
            'appName' => $row['app_name'],
            'appCode' => $row['app_code'],
            'heroTitle' => $row['hero_title'],
            'heroSubtitle' => $row['hero_subtitle'],
            'logoIcon' => $row['logo_icon'],
            'primaryColor' => $row['primary_color'],
            'primaryTextColor' => $row['primary_text_color'],
            'supportPhone' => $row['support_phone'],
            'features' => json_decode($row['features'] ?? '[]', true) ?: [],
        ];
    }
}
