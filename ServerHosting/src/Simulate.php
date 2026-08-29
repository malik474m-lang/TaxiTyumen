<?php
// Серверная GPS-симуляция водителей (в оригинале координаты шлёт MAUI-приложение)
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Taxi.php';

final class Simulate
{
    private const SPEED_KM_PER_SEC = 0.09;

    public static function advance(\PDO $db): void
    {
        try {
            $drivers = $db->query(
                'SELECT * FROM drivers WHERE current_order_id IS NOT NULL'
            )->fetchAll();

            $nowMs = (int) round(microtime(true) * 1000);
            foreach ($drivers as $d) {
                $lastMs = $d['last_location_update']
                    ? strtotime($d['last_location_update'] . ' UTC') * 1000
                    : 0;
                $elapsedSec = max(($nowMs - $lastMs) / 1000, 0);
                if ($elapsedSec < 2.5) {
                    continue;
                }

                $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
                $stmt->execute([$d['current_order_id']]);
                $order = $stmt->fetch();
                if (!$order) {
                    continue;
                }

                $target = null;
                if (in_array($order['status'], ['driver_assigned', 'driver_en_route'], true)) {
                    $target = [(float) $order['pickup_latitude'], (float) $order['pickup_longitude']];
                } elseif ($order['status'] === 'in_progress' && $order['destination_latitude'] !== null) {
                    $target = [(float) $order['destination_latitude'], (float) $order['destination_longitude']];
                }
                if (!$target) {
                    continue;
                }

                $dist = Taxi::getDistanceKm(
                    (float) $d['latitude'], (float) $d['longitude'],
                    $target[0], $target[1]
                );
                if ($dist < 0.02) {
                    continue;
                }

                $step = min($dist, max($elapsedSec * self::SPEED_KM_PER_SEC, 0.05));
                $ratio = $step / $dist;
                $db->prepare(
                    'UPDATE drivers SET latitude = ?, longitude = ?, last_location_update = ? WHERE id = ?'
                )->execute([
                    $d['latitude'] + ($target[0] - $d['latitude']) * $ratio,
                    $d['longitude'] + ($target[1] - $d['longitude']) * $ratio,
                    Db::utcNow(),
                    $d['id'],
                ]);
            }
        } catch (\Throwable) {
        }
    }
}
