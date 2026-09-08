<?php
// Ключи внешних сервисов: хранятся в БД и редактируются из админки
// («API-ключи»). Значение из БД имеет приоритет над константой config.local.php,
// поэтому переезд на панель не ломает существующие установки: пока в БД пусто,
// работает прежний ключ из файла.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class ApiKeys
{
    /** Реестр ключей: константа-фолбэк, подпись и подсказка для админки. */
    public const REGISTRY = [
        'yandex_maps' => [
            'label' => 'Яндекс Карты (JS API + HTTP Геокодер)',
            'const' => 'YANDEX_MAPS_API_KEY',
            'hint' => 'developer.tech.yandex.ru — сервис «JavaScript API и HTTP Геокодер». Ограничьте ключ доменом.',
            'public' => true,   // ключ по архитектуре отдаётся браузеру
        ],
        'dadata' => [
            'label' => 'DaData (подсказки адресов РФ)',
            'const' => 'DADATA_API_KEY',
            'hint' => 'dadata.ru → Личный кабинет → API-ключ. Основной источник адресов и ФИАС.',
            'public' => false,
        ],
        'opencage' => [
            'label' => 'OpenCage Data (резервный геокодер, OSM)',
            'const' => 'OPENCAGE_API_KEY',
            'hint' => 'opencagedata.com — бесплатный триал 2500 запросов/сутки.',
            'public' => false,
        ],
        'sms_ru' => [
            'label' => 'sms.ru (SMS-коды и рассылки)',
            'const' => 'SMS_API_ID',
            'hint' => 'sms.ru → Настройки → API ID. Без ключа система работает в демо-режиме (код в ответе).',
            'public' => false,
        ],
    ];

    private static ?array $cache = null;

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS api_settings (
                key_name VARCHAR(40) PRIMARY KEY,
                key_value VARCHAR(500) NOT NULL DEFAULT '',
                updated_at DATETIME NULL,
                updated_by CHAR(36) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** Значения из БД (пустые строки отбрасываются — работает фолбэк на файл). */
    private static function fromDb(\PDO $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            self::ensureTables($db);
            foreach ($db->query('SELECT key_name, key_value FROM api_settings')->fetchAll() as $row) {
                $value = trim((string) $row['key_value']);
                if ($value !== '') {
                    self::$cache[(string) $row['key_name']] = $value;
                }
            }
        } catch (\Throwable) {
        }
        return self::$cache;
    }

    /** Значение из константы config.local.php (или пусто). */
    public static function fromFile(string $name): string
    {
        $const = self::REGISTRY[$name]['const'] ?? null;
        return ($const !== null && defined($const)) ? trim((string) constant($const)) : '';
    }

    /** Итоговый ключ: БД имеет приоритет, иначе файл. */
    public static function get(\PDO $db, string $name): string
    {
        return self::fromDb($db)[$name] ?? self::fromFile($name);
    }

    /** Откуда взят ключ: db | file | none — для отображения в админке. */
    public static function source(\PDO $db, string $name): string
    {
        if (isset(self::fromDb($db)[$name])) return 'db';
        return self::fromFile($name) !== '' ? 'file' : 'none';
    }

    /** Сохранение из админки; пустая строка удаляет запись (возврат к файлу). */
    public static function set(\PDO $db, string $name, string $value, ?string $userId = null): void
    {
        if (!isset(self::REGISTRY[$name])) {
            throw new \RuntimeException('Неизвестный ключ: ' . $name);
        }
        self::ensureTables($db);
        $value = trim($value);
        if ($value === '') {
            $db->prepare('DELETE FROM api_settings WHERE key_name = ?')->execute([$name]);
        } else {
            $db->prepare(
                'INSERT INTO api_settings (key_name, key_value, updated_at, updated_by) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE key_value=VALUES(key_value), updated_at=VALUES(updated_at),
                 updated_by=VALUES(updated_by)'
            )->execute([$name, mb_substr($value, 0, 500), Db::utcNow(), $userId]);
        }
        self::$cache = null;   // сбрасываем кеш процесса
    }

    /** Маскировка для показа в интерфейсе: MjA1…c4f2 */
    public static function mask(string $value): string
    {
        $len = mb_strlen($value);
        if ($len === 0) return '';
        if ($len <= 8) return str_repeat('•', $len);
        return mb_substr($value, 0, 4) . str_repeat('•', min(12, $len - 8)) . mb_substr($value, -4);
    }
}

/**
 * Короткий помощник: api_key('yandex_maps').
 * Соединение берётся из уже открытого singleton — отдельного подключения нет.
 */
function api_key(string $name): string
{
    try {
        return ApiKeys::get(Db::pdo(), $name);
    } catch (\Throwable) {
        return ApiKeys::fromFile($name);
    }
}
