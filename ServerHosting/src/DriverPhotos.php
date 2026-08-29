<?php
// Фото водителя: портрет, водительское удостоверение, автомобиль.
// Приватное хранилище: файлы отдаются только персоналу через API-контроллер.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class DriverPhotos
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const KINDS = [
        'driver'  => ['column' => 'photo_driver',  'label' => 'Фото водителя'],
        'license' => ['column' => 'photo_license', 'label' => 'Водительское удостоверение'],
        'car'     => ['column' => 'photo_car',     'label' => 'Фото автомобиля'],
    ];

    private const MIME_EXT = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public static function storageDir(): string
    {
        return dirname(__DIR__) . '/uploads/drivers';
    }

    public static function column(string $kind): string
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \RuntimeException('Неизвестный тип фото');
        }
        return self::KINDS[$kind]['column'];
    }

    public static function store(\PDO $db, string $driverId, string $kind, array $file): string
    {
        $column = self::column($kind);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Файл должен быть не больше 5 МБ');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Файл не является корректной HTTP-загрузкой');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            throw new \RuntimeException('Разрешены только PNG, JPEG и WebP');
        }
        if (@getimagesize($tmp) === false) {
            throw new \RuntimeException('Файл не является изображением');
        }

        $dir = self::storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог uploads/drivers');
        }

        $old = self::currentFile($db, $driverId, $kind);
        $name = $driverId . '-' . $kind . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('Хостинг не разрешил сохранить файл');
        }
        @chmod($dir . '/' . $name, 0644);

        $db->prepare("UPDATE drivers SET `$column` = ? WHERE id = ?")->execute([$name, $driverId]);
        self::unlinkSafe($old);
        return $name;
    }

    public static function remove(\PDO $db, string $driverId, string $kind): void
    {
        $column = self::column($kind);
        $old = self::currentFile($db, $driverId, $kind);
        $db->prepare("UPDATE drivers SET `$column` = NULL WHERE id = ?")->execute([$driverId]);
        self::unlinkSafe($old);
    }

    public static function currentFile(\PDO $db, string $driverId, string $kind): ?string
    {
        $column = self::column($kind);
        $stmt = $db->prepare("SELECT `$column` FROM drivers WHERE id = ? LIMIT 1");
        $stmt->execute([$driverId]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function absolutePath(?string $name): ?string
    {
        if (!$name || basename($name) !== $name) {
            return null;
        }
        $path = self::storageDir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    public static function url(string $driverId, string $kind, ?string $name): ?string
    {
        if (!$name) {
            return null;
        }
        return '/api/drivers/photo.php?driverId=' . rawurlencode($driverId)
            . '&kind=' . rawurlencode($kind)
            . '&v=' . substr(sha1($name), 0, 8);
    }

    public static function mimeFor(string $path): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    /** Удаление всех фото при удалении водителя. */
    public static function removeAll(\PDO $db, string $driverId): void
    {
        foreach (array_keys(self::KINDS) as $kind) {
            try {
                self::remove($db, $driverId, $kind);
            } catch (\Throwable) {
            }
        }
    }

    private static function unlinkSafe(?string $name): void
    {
        if (is_string($name) && $name !== '' && basename($name) === $name) {
            @unlink(self::storageDir() . '/' . $name);
        }
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает лимит хостинга',
            UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью',
            UPLOAD_ERR_NO_FILE => 'Выберите файл',
            UPLOAD_ERR_NO_TMP_DIR => 'На хостинге отсутствует временная папка',
            UPLOAD_ERR_CANT_WRITE => 'Хостинг не разрешил запись файла',
            default => 'Ошибка загрузки файла (' . $code . ')',
        };
    }
}
