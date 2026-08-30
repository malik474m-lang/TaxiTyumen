<?php
// Роли администрирования: superadmin (полный доступ) и admin (ограниченный).
// Супер-админ управляет видимостью разделов и защищён от удаления.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Access
{
    public const SUPERADMIN_LOGIN = 'Rudakov';
    public const MARKER_KEY = 'superadmin_installed';

    /**
     * Реестр разделов админки.
     * superadminOnly — раздел никогда не виден обычному админу.
     * locked — нельзя скрыть (иначе панель станет неуправляемой).
     */
    public const SECTIONS = [
        'index'     => ['label' => 'Дашборд',        'file' => 'index.php',     'superadminOnly' => false, 'locked' => true],
        'orders'    => ['label' => 'Заказы',         'file' => 'orders.php',    'superadminOnly' => false, 'locked' => false],
        'drivers'   => ['label' => 'Водители',       'file' => 'drivers.php',   'superadminOnly' => false, 'locked' => false],
        'clients'   => ['label' => 'Клиенты',        'file' => 'clients.php',   'superadminOnly' => false, 'locked' => false],
        'operators' => ['label' => 'Операторы',      'file' => 'operators.php', 'superadminOnly' => false, 'locked' => false],
        'messages'  => ['label' => 'Сообщения',      'file' => 'messages.php',  'superadminOnly' => false, 'locked' => false],
        'fleet'     => ['label' => 'Чат водителей',  'file' => 'fleet-chat.php','superadminOnly' => false, 'locked' => false],
        'sos'       => ['label' => 'SOS-тревоги',    'file' => 'sos.php',       'superadminOnly' => false, 'locked' => false],
        'balance'   => ['label' => 'Балансы',        'file' => 'balance.php',   'superadminOnly' => false, 'locked' => false],
        'tariffs'   => ['label' => 'Тарифы',         'file' => 'tariffs.php',   'superadminOnly' => false, 'locked' => false],
        'zones'     => ['label' => 'Зоны и цены',    'file' => 'zones.php',     'superadminOnly' => false, 'locked' => false],
        'stats'     => ['label' => 'Статистика',     'file' => 'stats.php',     'superadminOnly' => false, 'locked' => false],
        'export'    => ['label' => 'Экспорт CSV',    'file' => 'export.php',    'superadminOnly' => false, 'locked' => false],
        'autocall'  => ['label' => 'Автодозвон',     'file' => 'autocall.php',  'superadminOnly' => false, 'locked' => false],
        'telephony' => ['label' => 'Телефония',      'file' => 'telephony.php', 'superadminOnly' => false, 'locked' => false],
        'branding'  => ['label' => 'Приложения',     'file' => 'branding.php',  'superadminOnly' => false, 'locked' => false],
        'services'  => ['label' => 'API и сервисы',  'file' => 'services.php',  'superadminOnly' => false, 'locked' => false],
        'service'   => ['label' => 'Бренд сервиса',  'file' => 'service.php',   'superadminOnly' => true,  'locked' => false],
        'access'    => ['label' => 'Доступ и роли',  'file' => 'access.php',    'superadminOnly' => true,  'locked' => false],
    ];

    public static function isSuperadmin(?array $user): bool
    {
        return ($user['role'] ?? '') === 'superadmin';
    }

    public static function isAdminRole(?string $role): bool
    {
        return in_array($role, ['admin', 'superadmin'], true);
    }

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS system_state (
                state_key VARCHAR(60) PRIMARY KEY,
                state_value VARCHAR(255) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS admin_sections (
                section_key VARCHAR(40) PRIMARY KEY,
                visible_for_admin TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $insert = $db->prepare('INSERT IGNORE INTO admin_sections (section_key, visible_for_admin) VALUES (?,1)');
        foreach (array_keys(self::SECTIONS) as $key) {
            $insert->execute([$key]);
        }
    }

    public static function state(\PDO $db, string $key): ?string
    {
        $stmt = $db->prepare('SELECT state_value FROM system_state WHERE state_key=? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public static function setState(\PDO $db, string $key, string $value): void
    {
        $db->prepare(
            'INSERT INTO system_state (state_key,state_value,updated_at) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE state_value=VALUES(state_value), updated_at=VALUES(updated_at)'
        )->execute([$key, $value, Db::utcNow()]);
    }

    /** Проверка целостности: супер-админ обязан существовать. */
    public static function integrity(\PDO $db): array
    {
        try {
            self::ensureTables($db);
            $count = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='superadmin'")->fetchColumn();
            $marker = self::state($db, self::MARKER_KEY);
            if ($count > 0) {
                return ['ok' => true, 'count' => $count];
            }
            if ($marker === null) {
                // Первичная установка — учётная запись будет создана сидером
                return ['ok' => true, 'count' => 0, 'firstInstall' => true];
            }
            return [
                'ok' => false,
                'count' => 0,
                'message' => 'Учётная запись супер-администратора удалена из базы данных. '
                    . 'Система заблокирована для защиты настроек.',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'count' => 0, 'message' => $e->getMessage()];
        }
    }

    /** Триггеры MySQL: запрет удаления и смены роли супер-админа. */
    public static function installGuards(\PDO $db): void
    {
        try {
            $db->exec('DROP TRIGGER IF EXISTS trg_users_superadmin_delete');
            $db->exec(
                "CREATE TRIGGER trg_users_superadmin_delete BEFORE DELETE ON users FOR EACH ROW
                 BEGIN
                   IF OLD.role = 'superadmin' THEN
                     SIGNAL SQLSTATE '45000'
                     SET MESSAGE_TEXT = 'Удаление супер-администратора запрещено';
                   END IF;
                 END"
            );
            $db->exec('DROP TRIGGER IF EXISTS trg_users_superadmin_update');
            $db->exec(
                "CREATE TRIGGER trg_users_superadmin_update BEFORE UPDATE ON users FOR EACH ROW
                 BEGIN
                   IF OLD.role = 'superadmin' AND NEW.role <> 'superadmin' THEN
                     SIGNAL SQLSTATE '45000'
                     SET MESSAGE_TEXT = 'Смена роли супер-администратора запрещена';
                   END IF;
                   IF OLD.role = 'superadmin' AND NEW.is_active = 0 THEN
                     SIGNAL SQLSTATE '45000'
                     SET MESSAGE_TEXT = 'Деактивация супер-администратора запрещена';
                   END IF;
                 END"
            );
        } catch (\Throwable) {
            // Хостинг может не давать привилегию TRIGGER — защита остаётся на уровне приложения
        }
    }

    /** Видимость разделов: superadmin видит всё, admin — по настройкам. */
    public static function visibleSections(\PDO $db, string $role): array
    {
        self::ensureTables($db);
        if ($role === 'superadmin') {
            return array_keys(self::SECTIONS);
        }
        $rows = $db->query('SELECT section_key, visible_for_admin FROM admin_sections')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['section_key']] = (bool) $row['visible_for_admin'];
        }
        $visible = [];
        foreach (self::SECTIONS as $key => $meta) {
            if ($meta['superadminOnly']) continue;
            if ($meta['locked'] || ($map[$key] ?? true)) $visible[] = $key;
        }
        return $visible;
    }

    public static function canAccess(\PDO $db, string $role, string $section): bool
    {
        if (!isset(self::SECTIONS[$section])) return false;
        return in_array($section, self::visibleSections($db, $role), true);
    }

    public static function setVisibility(\PDO $db, array $enabledKeys): void
    {
        self::ensureTables($db);
        $stmt = $db->prepare('UPDATE admin_sections SET visible_for_admin=?, updated_at=? WHERE section_key=?');
        foreach (self::SECTIONS as $key => $meta) {
            if ($meta['superadminOnly'] || $meta['locked']) {
                continue;
            }
            $stmt->execute([in_array($key, $enabledKeys, true) ? 1 : 0, Db::utcNow(), $key]);
        }
    }
}
