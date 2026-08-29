<?php
// JSON-ответы и ошибки
declare(strict_types=1);

final class Response
{
    private static ?array $bodyOverride = null;

    // Используется REST compatibility-router для адаптации ASP.NET URL к PHP handlers
    public static function setBodyOverride(array $body): void
    {
        self::$bodyOverride = $body;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400): never
    {
        self::json(['error' => $message], $status);
    }

    // Явная блокировка неподходящего метода (в т.ч. открытие POST-URL в браузере)
    public static function requireMethod(string ...$methods): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, $methods, true)) {
            self::error(
                sprintf('Эндпоинт принимает только %s (вы отправили %s)', implode('/', $methods), $method),
                405
            );
        }
    }

    public static function requirePostJson(): array
    {
        if (self::$bodyOverride !== null) {
            return self::$bodyOverride;
        }
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (is_array($body) && $body !== []) {
            return $body;
        }
        // Fallback: классические HTML-формы / Postman form-data / curl -d без заголовка
        if (!empty($_POST)) {
            return $_POST;
        }
        return is_array($body) ? $body : [];
    }
}
