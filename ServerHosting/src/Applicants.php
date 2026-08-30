<?php
// Анкеты соискателей на должность водителя со своим автомобилем.
// Публичный приём с сайта-лендинга (домен первого уровня) → модерация в админке
// → одобрение создаёт учётную запись водителя с профилем и авто.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Applicants
{
    public const MAX_BYTES = 6 * 1024 * 1024;

    /** Требуемые фото анкеты: 2 документа + автомобиль с четырёх сторон. */
    public const PHOTOS = [
        'photo_license'   => 'Водительское удостоверение',
        'photo_selfie'    => 'Фотография водителя',
        'photo_car_front' => 'Автомобиль спереди',
        'photo_car_back'  => 'Автомобиль сзади',
        'photo_car_left'  => 'Автомобиль слева',
        'photo_car_right' => 'Автомобиль справа',
    ];

    private const MIME_EXT = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public static function storageDir(): string
    {
        return dirname(__DIR__) . '/uploads/applications';
    }

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS driver_applications (
                id              CHAR(36) PRIMARY KEY,
                first_name      VARCHAR(60) NOT NULL,
                last_name       VARCHAR(60) NOT NULL,
                middle_name     VARCHAR(60) NULL,
                phone           VARCHAR(20) NOT NULL,
                birth_date      DATE NULL,
                city            VARCHAR(80) NULL,
                license_number  VARCHAR(40) NULL,
                license_expiry  DATE NULL,
                experience_years INT NOT NULL DEFAULT 0,
                car_brand       VARCHAR(40) NOT NULL,
                car_model       VARCHAR(40) NOT NULL,
                car_color       VARCHAR(30) NOT NULL DEFAULT '',
                car_year        INT NOT NULL DEFAULT 2015,
                license_plate   VARCHAR(15) NOT NULL,
                has_child_seat  TINYINT(1) NOT NULL DEFAULT 0,
                child_seat_note VARCHAR(120) NULL,
                comment         VARCHAR(500) NULL,
                photo_license   VARCHAR(255) NULL,
                photo_selfie    VARCHAR(255) NULL,
                photo_car_front VARCHAR(255) NULL,
                photo_car_back  VARCHAR(255) NULL,
                photo_car_left  VARCHAR(255) NULL,
                photo_car_right VARCHAR(255) NULL,
                status          ENUM('new','in_review','contacted','approved','rejected') NOT NULL DEFAULT 'new',
                review_note     VARCHAR(500) NULL,
                reviewed_by     CHAR(36) NULL,
                reviewed_at     DATETIME NULL,
                created_user_id CHAR(36) NULL,
                source_ip       VARCHAR(45) NULL,
                created_at      DATETIME NOT NULL,
                INDEX (status), INDEX (phone), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $dir = self::storageDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
    }

    /** Сохранение файла анкеты; возвращает имя файла в приватном хранилище. */
    public static function storePhoto(string $applicationId, string $field, array $file): string
    {
        if (!isset(self::PHOTOS[$field])) {
            throw new \RuntimeException('Неизвестный тип фото');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::PHOTOS[$field] . ': файл не загружен');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \RuntimeException(self::PHOTOS[$field] . ': размер до 6 МБ');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException(self::PHOTOS[$field] . ': некорректная загрузка');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null || @getimagesize($tmp) === false) {
            throw new \RuntimeException(self::PHOTOS[$field] . ': только PNG, JPEG или WebP');
        }

        self::ensureDir();
        $name = $applicationId . '_' . $field . '.' . $ext;
        if (!@move_uploaded_file($tmp, self::storageDir() . '/' . $name)) {
            throw new \RuntimeException(self::PHOTOS[$field] . ': не удалось сохранить файл');
        }
        return $name;
    }

    private static function ensureDir(): void
    {
        $dir = self::storageDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
    }

    public static function statusLabel(string $status): string
    {
        return [
            'new' => 'Новая',
            'in_review' => 'На рассмотрении',
            'contacted' => 'Связались',
            'approved' => 'Одобрена',
            'rejected' => 'Отклонена',
        ][$status] ?? $status;
    }

    public static function dto(array $a): array
    {
        $photos = [];
        foreach (array_keys(self::PHOTOS) as $field) {
            $photos[$field] = !empty($a[$field]);
        }
        return [
            'id' => $a['id'],
            'firstName' => $a['first_name'],
            'lastName' => $a['last_name'],
            'middleName' => $a['middle_name'],
            'name' => trim($a['last_name'] . ' ' . $a['first_name'] . ' ' . (string) $a['middle_name']),
            'phone' => $a['phone'],
            'city' => $a['city'],
            'licenseNumber' => $a['license_number'],
            'licenseExpiry' => $a['license_expiry'],
            'experienceYears' => (int) $a['experience_years'],
            'carBrand' => $a['car_brand'],
            'carModel' => $a['car_model'],
            'carColor' => $a['car_color'],
            'carYear' => (int) $a['car_year'],
            'licensePlate' => $a['license_plate'],
            'hasChildSeat' => (bool) $a['has_child_seat'],
            'childSeatNote' => $a['child_seat_note'],
            'comment' => $a['comment'],
            'photos' => $photos,
            'status' => $a['status'],
            'statusText' => self::statusLabel((string) $a['status']),
            'reviewNote' => $a['review_note'],
            'createdUserId' => $a['created_user_id'],
            'createdAt' => $a['created_at'],
        ];
    }

    /**
     * Одобрение: создаёт пользователя-водителя и профиль авто из анкеты.
     * Возвращает [userId, phone, password] — пароль показывается администратору один раз.
     */
    public static function approve(\PDO $db, array $app, string $adminId): array
    {
        $phone = Auth::normalizePhone((string) $app['phone']);

        $exists = $db->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $exists->execute([$phone]);
        if ($exists->fetchColumn()) {
            throw new \RuntimeException('Пользователь с таким телефоном уже есть — откройте раздел «Водители»');
        }

        // Пароль для первой выдачи водителю (менять после входа)
        $password = 'Taxi' . random_int(100000, 999999);
        $userId = Db::uuid();
        $driverId = Db::uuid();

        $db->prepare(
            'INSERT INTO users (id, phone, first_name, last_name, password_hash, role, is_active, is_phone_verified, created_at)
             VALUES (?,?,?,?,?,?,1,1,?)'
        )->execute([
            $userId, $phone, $app['first_name'], $app['last_name'],
            Auth::hashPassword($password), 'driver', Db::utcNow(),
        ]);

        $db->prepare(
            'INSERT INTO drivers (id, user_id, car_brand, car_model, car_color, license_plate, car_year,
             driver_license, is_verified, status, latitude, longitude, balance)
             VALUES (?,?,?,?,?,?,?,?,1,\'offline\',57.1522,65.5272,0)'
        )->execute([
            $driverId, $userId, $app['car_brand'], $app['car_model'], $app['car_color'] ?: 'Не указан',
            $app['license_plate'], (int) $app['car_year'], (string) ($app['license_number'] ?? ''),
        ]);

        // Фото анкеты переносим в карточку водителя (приватное хранилище водителей)
        try {
            $map = [
                'photo_selfie' => 'photo_driver',
                'photo_license' => 'photo_license',
                'photo_car_front' => 'photo_car',
            ];
            $targetDir = DriverPhotos::storageDir();
            if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
            foreach ($map as $from => $column) {
                if (empty($app[$from])) continue;
                $src = self::storageDir() . '/' . $app[$from];
                if (!is_file($src)) continue;
                $ext = pathinfo($src, PATHINFO_EXTENSION);
                $name = $driverId . '_' . $column . '.' . $ext;
                if (@copy($src, $targetDir . '/' . $name)) {
                    $db->prepare("UPDATE drivers SET `$column` = ? WHERE id = ?")->execute([$name, $driverId]);
                }
            }
        } catch (\Throwable) {
        }

        $db->prepare(
            "UPDATE driver_applications SET status='approved', reviewed_by=?, reviewed_at=?, created_user_id=? WHERE id=?"
        )->execute([$adminId, Db::utcNow(), $userId, $app['id']]);

        Bus::publish('applications');

        return ['userId' => $userId, 'driverId' => $driverId, 'phone' => $phone, 'password' => $password];
    }
}
