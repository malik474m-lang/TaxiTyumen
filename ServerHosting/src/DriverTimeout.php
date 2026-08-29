<?php
// Request-driven порт DriverTimeoutService (original: проверка каждые 30 сек, threshold 5 мин).
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Bus.php';

final class DriverTimeout
{
    public static function tick(\PDO $db): int
    {
        $threshold = gmdate('Y-m-d H:i:s', time() - 5 * 60);
        $stmt = $db->prepare(
            "SELECT d.*,o.status AS order_status FROM drivers d
             LEFT JOIN orders o ON o.id=d.current_order_id
             WHERE d.status<>'offline'
               AND (d.last_location_update IS NULL OR d.last_location_update<?)"
        );
        $stmt->execute([$threshold]);
        $count = 0;
        foreach ($stmt->fetchAll() as $driver) {
            // Начатую поездку не обрываем по пропаже GPS — ждём ручного завершения/оператора
            if ($driver['order_status'] === 'in_progress') continue;

            if ($driver['current_order_id'] && in_array($driver['order_status'], ['driver_assigned','driver_en_route','driver_arrived'], true)) {
                $db->prepare(
                    "UPDATE orders SET driver_id=NULL,status='searching',accepted_at=NULL WHERE id=?"
                )->execute([$driver['current_order_id']]);
                $fresh = $db->prepare('SELECT * FROM orders WHERE id=?');
                $fresh->execute([$driver['current_order_id']]);
                if ($order = $fresh->fetch()) {
                    NotificationService::notifyOperatorsOrderUpdate($db, $order);
                }
            }
            $db->prepare("UPDATE drivers SET status='offline',current_order_id=NULL WHERE id=?")
                ->execute([$driver['id']]);
            $count++;
        }
        if ($count > 0) Bus::publish('drivers');
        return $count;
    }
}
