<?php
// Серверное хранение логотипов бренда: проверка MIME/размера, случайное имя,
// выдача только через api/branding-logo.php (PHP-файлы загрузить нельзя).
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class BrandingLogo
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const MIME_EXT = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public static function storageDir(): string
    {
        return dirname(__DIR__) . '/uploads/branding';
    }

    public static function store(\PDO $db, string $app, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Логотип должен быть не больше 2 МБ');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Файл не является корректной HTTP-загрузкой');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            throw new \RuntimeException('Разрешены только PNG, JPEG и WebP');
        }

        // Двойная проверка: файл действительно декодируется как изображение
        if (@getimagesize($tmp) === false) {
            throw new \RuntimeException('Загруженный файл не является изображением');
        }

        $dir = self::storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог uploads/branding');
        }

        // Удаляем старый файл только внутри нашего каталога
        $stmt = $db->prepare('SELECT logo_path FROM branding_settings WHERE app = ? LIMIT 1');
        $stmt->execute([$app]);
        $old = $stmt->fetchColumn();

        $name = $app . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
        $target = $dir . '/' . $name;
        if (!move_uploaded_file($tmp, $target)) {
            throw new \RuntimeException('Хостинг не разрешил сохранить файл');
        }
        @chmod($target, 0644);

        $db->prepare('UPDATE branding_settings SET logo_path = ?, updated_at = ? WHERE app = ?')
            ->execute([$name, Db::utcNow(), $app]);

        if (is_string($old) && $old !== '' && basename($old) === $old) {
            @unlink($dir . '/' . $old);
        }
        return $name;
    }

    public static function remove(\PDO $db, string $app): void
    {
        $stmt = $db->prepare('SELECT logo_path FROM branding_settings WHERE app = ? LIMIT 1');
        $stmt->execute([$app]);
        $old = $stmt->fetchColumn();
        $db->prepare('UPDATE branding_settings SET logo_path = NULL, updated_at = ? WHERE app = ?')
            ->execute([Db::utcNow(), $app]);
        if (is_string($old) && $old !== '' && basename($old) === $old) {
            @unlink(self::storageDir() . '/' . $old);
        }
    }

    public static function absolutePath(?string $name): ?string
    {
        if (!$name || basename($name) !== $name) {
            return null;
        }
        $path = self::storageDir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    public static function mimeFor(string $path): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает лимит хостинга',
            UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью',
            UPLOAD_ERR_NO_FILE => 'Выберите файл логотипа',
            UPLOAD_ERR_NO_TMP_DIR => 'На хостинге отсутствует временная папка',
            UPLOAD_ERR_CANT_WRITE => 'Хостинг не разрешил запись файла',
            default => 'Ошибка загрузки файла (' . $code . ')',
        };
    }
}
