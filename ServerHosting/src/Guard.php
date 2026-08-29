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
        $role = (string) ($claims['role'] ?? '');
        // Супер-админ обладает всеми правами администратора
        if ($role === 'superadmin' && in_array('admin', $roles, true)) {
            return;
        }
        if (!in_array($role, $roles, true)) {
            Response::error('Недостаточно прав для этой роли', 403);
        }
    }

    /** Действия, доступные только супер-администратору. */
    public static function superadmin(array $claims): void
    {
        if (($claims['role'] ?? '') !== 'superadmin') {
            Response::error('Действие доступно только супер-администратору', 403);
        }
    }
}
