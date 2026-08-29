<?php
// Проверки прав: роли не пересекаются (аналог Guard-проверок в web-версии)
declare(strict_types=1);

require_once __DIR__ . '/Response.php';

final class Guard
{
    public static function claims(): array
    {
        $claims = Auth::readClaims();
        if ($claims === null) {
            Response::error('Требуется вход', 401);
        }
        return $claims;
    }

    public static function role(array $claims, string ...$roles): void
    {
        if (!in_array($claims['role'] ?? '', $roles, true)) {
            Response::error('Недостаточно прав для этой роли', 403);
        }
    }
}
