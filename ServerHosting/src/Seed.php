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
        ServiceSettings::get($db);
        self::ensureSuperadmin($db);

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

    /**
     * Супер-администратор создаётся один раз при первичной установке.
     * Если запись удалили из БД — система блокируется (Access::integrity),
     * восстановление только через SUPERADMIN_RECOVERY в config.local.php.
     */
    private static function ensureSuperadmin(\PDO $db): void
    {
        Access::ensureTables($db);
        Telephony::ensureTables($db);
        Zones::ensureTables($db);
        FleetChat::ensureTables($db);
        $exists = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='superadmin'")->fetchColumn();
        $marker = Access::state($db, Access::MARKER_KEY);
        $recovery = defined('SUPERADMIN_RECOVERY') && SUPERADMIN_RECOVERY === true;

        if ($exists === 0 && ($marker === null || $recovery)) {
            $login = Access::SUPERADMIN_LOGIN;
            $password = defined('SUPERADMIN_PASSWORD') && SUPERADMIN_PASSWORD !== ''
                ? SUPERADMIN_PASSWORD
                : 'Malik9091868294';
            $id = Db::uuid();
            // Технический телефон: вход выполняется по логину
            $phone = '+70000000001';
            $db->prepare(
                "INSERT INTO users (id, phone, username, first_name, last_name, password_hash, role, is_phone_verified, is_active)
                 VALUES (?,?,?,?,?,?, 'superadmin', 1, 1)
                 ON DUPLICATE KEY UPDATE username=VALUES(username), password_hash=VALUES(password_hash), role='superadmin'"
            )->execute([$id, $phone, $login, 'Супер', 'Администратор', Auth::hashPassword($password)]);

            $idStmt = $db->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
            $idStmt->execute([$login]);
            Access::setState($db, Access::MARKER_KEY, (string) ($idStmt->fetchColumn() ?: $id));
        }

        Access::installGuards($db);
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
        // Базовая таблица должна существовать до addColumn (обновление со старых релизов)
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

        // Роль супер-администратора и вход по логину
        self::addColumn($db, 'users', 'username', "VARCHAR(60) NULL AFTER phone");
        $roleStmt = $db->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'"
        );
        $roleStmt->execute();
        if (!str_contains((string) ($roleStmt->fetchColumn() ?: ''), "'superadmin'")) {
            $db->exec(
                "ALTER TABLE users MODIFY role
                 ENUM('client','driver','operator','admin','superadmin') NOT NULL DEFAULT 'client'"
            );
        }
        $idxStmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='users_username_unique'"
        );
        $idxStmt->execute();
        if ((int) $idxStmt->fetchColumn() === 0) {
            try {
                $db->exec('ALTER TABLE users ADD UNIQUE INDEX users_username_unique (username)');
            } catch (\Throwable) {
            }
        }
        Access::ensureTables($db);

        // Архив персонала и фото водителя
        self::addColumn($db, 'users', 'is_archived', "TINYINT(1) NOT NULL DEFAULT 0 AFTER is_phone_verified");
        self::addColumn($db, 'users', 'archived_at', "DATETIME NULL AFTER is_archived");
        self::addColumn($db, 'users', 'archived_by', "CHAR(36) NULL AFTER archived_at");
        self::addColumn($db, 'users', 'archive_reason', "VARCHAR(255) NULL AFTER archived_by");
        self::addColumn($db, 'drivers', 'photo_driver', "VARCHAR(255) NULL AFTER accept_sbp");
        self::addColumn($db, 'drivers', 'photo_license', "VARCHAR(255) NULL AFTER photo_driver");
        self::addColumn($db, 'drivers', 'photo_car', "VARCHAR(255) NULL AFTER photo_license");

        // Новые поля профиля водителя из исходной Driver.cs
        self::addColumn($db, 'drivers', 'license_expiry', "DATETIME NULL AFTER driver_license");
        self::addColumn($db, 'drivers', 'verified_at', "DATETIME NULL AFTER is_verified");
        self::addColumn($db, 'drivers', 'speed', "DOUBLE NULL AFTER longitude");
        self::addColumn($db, 'drivers', 'bearing', "DOUBLE NULL AFTER speed");
        self::addColumn($db, 'drivers', 'rating', "DOUBLE NOT NULL DEFAULT 5 AFTER last_location_update");
        self::addColumn($db, 'drivers', 'payment_phone', "VARCHAR(20) NULL AFTER rejection_penalty");
        self::addColumn($db, 'drivers', 'payment_bank_name', "VARCHAR(80) NULL AFTER payment_phone");
        self::addColumn($db, 'drivers', 'payment_card_holder', "VARCHAR(120) NULL AFTER payment_bank_name");
        self::addColumn($db, 'drivers', 'accept_card_transfer', "TINYINT(1) NOT NULL DEFAULT 1 AFTER payment_card_holder");
        self::addColumn($db, 'drivers', 'accept_sbp', "TINYINT(1) NOT NULL DEFAULT 1 AFTER accept_card_transfer");

        // Простой по просьбе пассажира (поминутная тарификация при завершении)
        self::addColumn($db, 'tariffs', 'free_waiting_minutes', "DOUBLE NOT NULL DEFAULT 3 AFTER commission_percent");
        self::addColumn($db, 'tariffs', 'paid_waiting_per_minute', "DOUBLE NOT NULL DEFAULT 0 AFTER free_waiting_minutes");
        self::addColumn($db, 'orders', 'waiting_started_at', "DATETIME NULL AFTER cancelled_at");
        self::addColumn($db, 'orders', 'waiting_seconds', "INT NOT NULL DEFAULT 0 AFTER waiting_started_at");
        self::addColumn($db, 'orders', 'waiting_cost', "DOUBLE NOT NULL DEFAULT 0 AFTER waiting_seconds");

        // Загружаемый логотип для серверного брендинга
        self::addColumn($db, 'branding_settings', 'logo_path', "VARCHAR(255) NULL AFTER logo_icon");

        // Зональная тарификация в заказе
        self::addColumn($db, 'orders', 'pricing_mode', "ENUM('tariff','zone') NOT NULL DEFAULT 'tariff' AFTER actual_distance");
        self::addColumn($db, 'orders', 'from_zone_id', "CHAR(36) NULL AFTER pricing_mode");
        self::addColumn($db, 'orders', 'to_zone_id', "CHAR(36) NULL AFTER from_zone_id");

        // Дополнительные поля заказа из исходного Order.cs
        self::addColumn($db, 'orders', 'actual_distance', "DOUBLE NULL AFTER estimated_duration");
        self::addColumn($db, 'orders', 'cancelled_by_user_id', "CHAR(36) NULL AFTER cancellation_reason");
        self::addColumn($db, 'orders', 'client_review', "VARCHAR(2000) NULL AFTER driver_rating");
        self::addColumn($db, 'orders', 'driver_review', "VARCHAR(2000) NULL AFTER client_review");

        // Поля смен и накопительной статистики операторов
        self::addColumn($db, 'operator_shifts', 'profile_id', "CHAR(36) NULL AFTER operator_id");
        self::addColumn($db, 'operator_shifts', 'hours_worked', "DOUBLE NOT NULL DEFAULT 0 AFTER ended_at");
        self::addColumn($db, 'operator_shifts', 'orders_accepted', "INT NOT NULL DEFAULT 0 AFTER hours_worked");
        self::addColumn($db, 'operator_shifts', 'earned', "DOUBLE NOT NULL DEFAULT 0 AFTER orders_accepted");
        self::addColumn($db, 'operator_profiles', 'total_orders_accepted', "INT NOT NULL DEFAULT 0 AFTER fixed_monthly");
        self::addColumn($db, 'operator_profiles', 'total_earnings', "DOUBLE NOT NULL DEFAULT 0 AFTER total_orders_accepted");
        self::addColumn($db, 'operator_profiles', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER total_earnings");
        self::addColumn($db, 'auto_call_settings', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER message_template");

        // Полные типы BalanceTransaction (ALTER только один раз, не на каждом API-запросе)
        $enumStmt = $db->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='balance_transactions' AND COLUMN_NAME='type'"
        );
        $enumStmt->execute();
        $columnType = (string) ($enumStmt->fetchColumn() ?: '');
        if (!str_contains($columnType, "'refund'") || !str_contains($columnType, "'bonus'")) {
            $db->exec("ALTER TABLE balance_transactions MODIFY type ENUM('topup','commission','penalty','refund','bonus') NOT NULL");
        }

        // Прочтение сообщений из исходного ChatMessage.IsRead
        self::addColumn($db, 'chat_messages', 'is_read', "TINYINT(1) NOT NULL DEFAULT 0 AFTER text");
        self::addColumn($db, 'chat_messages', 'read_at', "DATETIME NULL AFTER is_read");

        // Полные настройки Zvonok из исходного AutoCallSettings
        self::addColumn($db, 'auto_call_settings', 'provider', "VARCHAR(30) NOT NULL DEFAULT 'signalr' AFTER auto_assign_radius_km");
        self::addColumn($db, 'auto_call_settings', 'zvonok_api_key', "VARCHAR(255) NULL AFTER provider");
        self::addColumn($db, 'auto_call_settings', 'zvonok_campaign_id', "VARCHAR(100) NULL AFTER zvonok_api_key");
        self::addColumn($db, 'auto_call_settings', 'zvonok_balance', "DOUBLE NOT NULL DEFAULT 0 AFTER zvonok_campaign_id");
        self::addColumn($db, 'auto_call_settings', 'balance_checked_at', "DATETIME NULL AFTER zvonok_balance");
        self::addColumn($db, 'auto_call_settings', 'free_waiting_minutes', "INT NOT NULL DEFAULT 5 AFTER balance_checked_at");
        self::addColumn($db, 'auto_call_settings', 'message_template', "VARCHAR(1000) NOT NULL DEFAULT 'Ваше такси прибыло! {CarColor} {CarBrand} {CarModel}, номер {LicensePlate}. Бесплатное ожидание: {FreeWaitingMinutes} минут.' AFTER free_waiting_minutes");

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

        // GPS-история водителей (DriverLocationHistory.cs)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS driver_location_history (
                id CHAR(36) PRIMARY KEY,
                driver_id CHAR(36) NOT NULL,
                order_id CHAR(36) NULL,
                latitude DOUBLE NOT NULL,
                longitude DOUBLE NOT NULL,
                speed DOUBLE NULL,
                bearing DOUBLE NULL,
                timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
                INDEX driver_time_idx (driver_id,timestamp), INDEX order_idx (order_id), INDEX time_idx (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Промежуточные точки маршрута (RoutePoint.cs)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS route_points (
                id CHAR(36) PRIMARY KEY,
                order_id CHAR(36) NOT NULL,
                address VARCHAR(500) NOT NULL,
                latitude DOUBLE NOT NULL,
                longitude DOUBLE NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                INDEX order_sort_idx (order_id,sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Платёжная транзакция заказа (Transaction.cs)
        $db->exec(
            "CREATE TABLE IF NOT EXISTS transactions (
                id CHAR(36) PRIMARY KEY,
                order_id CHAR(36) NOT NULL UNIQUE,
                amount DOUBLE NOT NULL,
                method ENUM('cash','card','bonus') NOT NULL,
                status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
                external_transaction_id VARCHAR(200) NULL,
                failure_reason VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                INDEX status_idx (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Персистентный NotificationService вместо только эфемерного SignalR
        $db->exec(
            "CREATE TABLE IF NOT EXISTS notifications (
                id CHAR(36) PRIMARY KEY,
                recipient_id CHAR(36) NULL,
                recipient_role ENUM('client','driver','operator','admin') NULL,
                order_id CHAR(36) NULL,
                type VARCHAR(60) NOT NULL,
                title VARCHAR(160) NOT NULL,
                message VARCHAR(1000) NOT NULL,
                payload TEXT NULL,
                channel ENUM('in_app','sms','call') NOT NULL DEFAULT 'in_app',
                delivery_status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'sent',
                provider_response TEXT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME NULL,
                created_by CHAR(36) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (recipient_id), INDEX (recipient_role), INDEX (order_id), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Диагностика внешних API и провайдеров
        $db->exec(
            "CREATE TABLE IF NOT EXISTS service_call_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                service VARCHAR(40) NOT NULL,
                action VARCHAR(60) NOT NULL,
                request_summary VARCHAR(500) NULL,
                status ENUM('success','failed','skipped') NOT NULL,
                http_code INT NULL,
                response_body TEXT NULL,
                duration_ms INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (service), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
