<?php
// Порт DataSeeder.cs: тарифы Тюмени, персонал, демо-водители + настройки
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Branding.php';

final class Seed
{
    public static function ensure(\PDO $db): void
    {
        // Runtime-миграции для уже установленной базы — повторный install.php не нужен
        self::migrateExistingDatabase($db);

        // Тарифы
        $count = (int) $db->query('SELECT COUNT(*) FROM tariffs')->fetchColumn();
        if ($count === 0) {
            $stmt = $db->prepare(
                'INSERT INTO tariffs (id, type, name, description, base_fare, price_per_km,
                 price_per_minute, minimum_fare, free_waiting_minutes, paid_waiting_per_minute,
                 night_multiplier, peak_multiplier)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach (
                [
                    ['economy',  'Эконом',  'Бюджетные поездки по городу',    49,  10, 3, 99,  3, 4, 1.3, 1.5],
                    ['comfort',  'Комфорт', 'Комфортные авто, кондиционер',   99,  16, 5, 179, 5, 5, 1.3, 1.4],
                    ['business', 'Бизнес',  'Авто бизнес-класса',            199,  25, 8, 349, 5, 8, 1.2, 1.3],
                    ['minivan',  'Минивэн', 'Для больших компаний, 6+ мест', 149,  20, 5, 249, 5, 5, 1.3, 1.5],
                ] as $t
            ) {
                $stmt->execute([Db::uuid(), ...$t]);
            }
        }

        // Персонал (админ + оператор)
        $adminCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        if ($adminCount === 0) {
            $db->prepare(
                'INSERT INTO users (id, phone, first_name, last_name, email, password_hash, role, is_phone_verified)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                Db::uuid(), '+79001234567', 'Админ', 'Системы',
                'admin@taxityumen.ru', Auth::hashPassword('Admin123!'), 'admin', 1,
            ]);
        }
        $operatorCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='operator'")->fetchColumn();
        if ($operatorCount === 0) {
            $db->prepare(
                'INSERT INTO users (id, phone, first_name, last_name, email, password_hash, role, is_phone_verified)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                Db::uuid(), '+79001234568', 'Мария', 'Диспетчер',
                'operator@taxityumen.ru', Auth::hashPassword('Operator123!'), 'operator', 1,
            ]);
        }

        // Демо-водители
        $driverCount = (int) $db->query('SELECT COUNT(*) FROM drivers')->fetchColumn();
        if ($driverCount === 0) {
            $demo = [
                ['Алексей', 'Иванов',   '+79221000001', 'Kia',    'Rio',      'Белый',       'А123ВС72', 2021, 600, 57.1580, 65.5340, 1],
                ['Дмитрий', 'Петров',   '+79221000002', 'Hyundai','Solaris',  'Серебристый', 'В456ОР72', 2022, 420, 57.1380, 65.5605, 1],
                ['Сергей',  'Сидоров',  '+79221000003', 'Toyota', 'Camry',    'Чёрный',      'Е789КХ72', 2023, 900, 57.1225, 65.5908, 1],
                ['Андрей',  'Кузнецов', '+79221000004', 'Skoda',  'Octavia',  'Синий',       'М234ТУ72', 2020, 350, 57.1654, 65.4749, 0],
                ['Игорь',   'Васильев', '+79221000005', 'Volkswagen','Multivan','Серый',     'Х567УТ72', 2022, 750, 57.0951, 65.5691, 0],
            ];
            $uStmt = $db->prepare(
                'INSERT INTO users (id, phone, first_name, last_name, password_hash, role, is_phone_verified, rating)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $dStmt = $db->prepare(
                'INSERT INTO drivers (id, user_id, car_brand, car_model, car_color, license_plate, car_year,
                 is_verified, status, latitude, longitude, balance, rejection_penalty, last_location_update)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($demo as [$fn, $ln, $phone, $brand, $model, $color, $plate, $year, $balance, $lat, $lng, $online]) {
                $uid = Db::uuid();
                $uStmt->execute([$uid, $phone, $fn, $ln, Auth::hashPassword('Driver123!'), 'driver', 1, 4.8]);
                $dStmt->execute([
                    Db::uuid(), $uid, $brand, $model, $color, $plate, $year,
                    1, $online ? 'available' : 'offline', $lat, $lng, $balance, 50, Db::utcNow(),
                ]);
            }
        }

        // Демо-клиент
        $client = $db->query("SELECT id FROM users WHERE phone='+79221112233' LIMIT 1")->fetch();
        if (!$client) {
            $db->prepare(
                'INSERT INTO users (id, phone, first_name, last_name, password_hash, role, is_phone_verified)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([Db::uuid(), '+79221112233', 'Демо', 'Клиент', Auth::hashPassword('Client123!'), 'client', 1]);
        }

        // Настройки автодозвона
        $ac = (int) $db->query('SELECT COUNT(*) FROM auto_call_settings')->fetchColumn();
        if ($ac === 0) {
            $db->prepare('INSERT INTO auto_call_settings (id) VALUES (?)')->execute([Db::uuid()]);
        }

        // Брендинг
        Branding::ensureSeeded($db);

        // Профили оплаты для всех операторов
        $operators = $db->query("SELECT id FROM users WHERE role = 'operator'")->fetchAll();
        $profileStmt = $db->prepare(
            'INSERT IGNORE INTO operator_profiles (id, user_id) VALUES (?, ?)'
        );
        foreach ($operators as $operator) {
            $profileStmt->execute([Db::uuid(), $operator['id']]);
        }
    }

    private static function hasColumn(\PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function addColumn(\PDO $db, string $table, string $column, string $definition): void
    {
        if (!self::hasColumn($db, $table, $column)) {
            // table/column/definition задаются только константами ниже, не пользовательским вводом
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    private static function migrateExistingDatabase(\PDO $db): void
    {
        // Новые поля профиля водителя из исходной Driver.cs
        self::addColumn($db, 'drivers', 'payment_phone', "VARCHAR(20) NULL AFTER rejection_penalty");
        self::addColumn($db, 'drivers', 'payment_bank_name', "VARCHAR(80) NULL AFTER payment_phone");
        self::addColumn($db, 'drivers', 'payment_card_holder', "VARCHAR(120) NULL AFTER payment_bank_name");
        self::addColumn($db, 'drivers', 'accept_card_transfer', "TINYINT(1) NOT NULL DEFAULT 1 AFTER payment_card_holder");
        self::addColumn($db, 'drivers', 'accept_sbp', "TINYINT(1) NOT NULL DEFAULT 1 AFTER accept_card_transfer");

        // Загружаемый логотип для серверного брендинга
        self::addColumn($db, 'branding_settings', 'logo_path', "VARCHAR(255) NULL AFTER logo_icon");

        // Схемы оплаты операторов из исходного OperatorProfile
        $db->exec(
            "CREATE TABLE IF NOT EXISTS operator_profiles (
                id CHAR(36) PRIMARY KEY,
                user_id CHAR(36) NOT NULL UNIQUE,
                scheme ENUM('per_order','per_hour','per_day','fixed_monthly') NOT NULL DEFAULT 'per_order',
                rate_per_order DOUBLE NOT NULL DEFAULT 30,
                rate_per_hour DOUBLE NOT NULL DEFAULT 150,
                rate_per_day DOUBLE NOT NULL DEFAULT 1500,
                fixed_monthly DOUBLE NOT NULL DEFAULT 30000,
                updated_at DATETIME NULL,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
