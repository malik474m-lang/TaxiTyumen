<?php
// Порт AutoCallService: эскалация «застывших» заказов + автоназначение (тик по запросам)
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Taxi.php';
require_once __DIR__ . '/Bus.php';

final class AutoCall
{
    private const TICK_SECONDS = 10;

    public static function getSettings(\PDO $db): array
    {
        $row = $db->query('SELECT * FROM auto_call_settings LIMIT 1')->fetch();
        if ($row) {
            return $row;
        }
        $id = Db::uuid();
        $db->prepare('INSERT INTO auto_call_settings (id) VALUES (?)')->execute([$id]);
        return $db->query('SELECT * FROM auto_call_settings LIMIT 1')->fetch();
    }

    public static function tick(\PDO $db): void
    {
        try {
            $settings = self::getSettings($db);
            // Троттлинг через last_tick_at в БД (shared-хостинг — много процессов)
            if (!empty($settings['last_tick_at'])) {
                $elapsed = time() - strtotime($settings['last_tick_at'] . ' UTC');
                if ($elapsed < self::TICK_SECONDS) {
                    return;
                }
            }
            $db->prepare('UPDATE auto_call_settings SET last_tick_at = ? WHERE id = ?')
                ->execute([Db::utcNow(), $settings['id']]);
            if (!$settings['enabled']) {
                return;
            }

            $deadline = gmdate('Y-m-d H:i:s', time() - $settings['escalate_after_minutes'] * 60);
            $stuck = $db->prepare(
                "SELECT * FROM orders
                 WHERE (status = 'searching' OR status = 'no_driver_found')
                   AND driver_id IS NULL AND escalated_at IS NULL AND created_at < ?
                 LIMIT 10"
            );
            $stuck->execute([$deadline]);

            foreach ($stuck->fetchAll() as $order) {
                if ($settings['auto_assign_enabled'] && self::tryAutoAssign($db, $order, $settings)) {
                    Bus::publish('orders');
                    continue;
                }
                $db->prepare('UPDATE orders SET escalated_at = ? WHERE id = ?')
                    ->execute([Db::utcNow(), $order['id']]);
                Bus::publish('orders');
            }
        } catch (\Throwable) {
        }
    }

    private static function tryAutoAssign(\PDO $db, array $order, array $settings): bool
    {
        $free = $db->query(
            "SELECT * FROM drivers WHERE status = 'available' AND is_verified = 1"
        )->fetchAll();

        $rejected = $db->prepare('SELECT driver_id FROM order_rejections WHERE order_id = ?');
        $rejected->execute([$order['id']]);
        $rejectedIds = array_map(fn(array $r) => $r['driver_id'], $rejected->fetchAll());

        $best = null;
        foreach ($free as $d) {
            if (in_array($d['id'], $rejectedIds, true) || $d['balance'] < $d['min_balance_for_orders']) {
                continue;
            }
            $dist = Taxi::getDistanceKm(
                (float) $order['pickup_latitude'], (float) $order['pickup_longitude'],
                (float) $d['latitude'], (float) $d['longitude']
            );
            if ($dist <= (float) $settings['auto_assign_radius_km'] && ($best === null || $dist < $best['dist'])) {
                $best = ['driver' => $d, 'dist' => $dist];
            }
        }
        if (!$best) {
            return false;
        }
        $db->prepare(
            "UPDATE orders SET driver_id = ?, status = 'driver_assigned', accepted_at = ? WHERE id = ?"
        )->execute([$best['driver']['id'], Db::utcNow(), $order['id']]);
        $db->prepare(
            "UPDATE drivers SET status = 'on_route', current_order_id = ? WHERE id = ?"
        )->execute([$order['id'], $best['driver']['id']]);

        // Автоназначение должно породить те же уведомления, что ручное назначение
        $freshStmt = $db->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $freshStmt->execute([$order['id']]);
        $fresh = $freshStmt->fetch();
        if ($fresh) {
            NotificationService::notifyClientOrderAccepted($db, $fresh);
            NotificationService::notifyDriverForceAssigned($db, $best['driver']['id'], $fresh);
            NotificationService::notifyOperatorsOrderUpdate($db, $fresh);
        }
        return true;
    }
}
