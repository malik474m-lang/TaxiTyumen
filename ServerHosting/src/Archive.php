<?php
// Архив персонала: перенос только после деактивации, восстановление и фильтры.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Archive
{
    /** Проверки перед архивацией водителя. */
    public static function assertDriverArchivable(\PDO $db, array $driver): void
    {
        if ((int) ($driver['is_active'] ?? 1) === 1) {
            throw new \RuntimeException('Сначала деактивируйте водителя, затем переносите в архив');
        }
        if (!empty($driver['current_order_id'])) {
            throw new \RuntimeException('У водителя есть активный заказ — завершите его перед архивацией');
        }
        if (($driver['status'] ?? 'offline') !== 'offline') {
            throw new \RuntimeException('Водитель на линии — переведите его в офлайн');
        }
    }

    /** Проверки перед архивацией оператора. */
    public static function assertOperatorArchivable(\PDO $db, array $operator): void
    {
        if ((int) ($operator['is_active'] ?? 1) === 1) {
            throw new \RuntimeException('Сначала деактивируйте оператора, затем переносите в архив');
        }
        $open = $db->prepare('SELECT COUNT(*) FROM operator_shifts WHERE operator_id = ? AND ended_at IS NULL');
        $open->execute([$operator['id']]);
        if ((int) $open->fetchColumn() > 0) {
            throw new \RuntimeException('У оператора не закрыта смена — завершите её перед архивацией');
        }
    }

    public static function archiveUser(\PDO $db, string $userId, string $adminId, ?string $reason = null): void
    {
        $db->prepare(
            'UPDATE users SET is_archived = 1, archived_at = ?, archived_by = ?, archive_reason = ?
             WHERE id = ? AND role <> ' . "'superadmin'"
        )->execute([Db::utcNow(), $adminId, $reason, $userId]);
    }

    public static function restoreUser(\PDO $db, string $userId): void
    {
        $db->prepare(
            'UPDATE users SET is_archived = 0, archived_at = NULL, archived_by = NULL, archive_reason = NULL
             WHERE id = ?'
        )->execute([$userId]);
    }

    /** Архивные пользователи исключаются из рабочих выборок. */
    public static function activeUserCondition(string $alias = 'u'): string
    {
        return "$alias.is_archived = 0";
    }
}
