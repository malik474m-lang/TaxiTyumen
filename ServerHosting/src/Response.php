<?php
// JSON-ответы и ошибки
declare(strict_types=1);

final class Response
{
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

    public static function requirePostJson(): array
    {
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
