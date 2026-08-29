<?php
// MySQL PDO-соединение (singleton)
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

final class Db
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                TAXI_DB_HOST,
                TAXI_DB_PORT,
                TAXI_DB_NAME
            );
            self::$pdo = new \PDO($dsn, TAXI_DB_USER, TAXI_DB_PASS, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // UTC внутри БД — как в .NET-версии
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
