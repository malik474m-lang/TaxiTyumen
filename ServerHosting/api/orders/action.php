<?php
// POST api/orders/action.php — жизненный цикл заказа
// (AcceptOrderAsync / RejectOrderAsync / UpdateOrderStatusAsync / CompleteOrderAsync /
//  CancelOrderAsync / ForceAssignOrderAsync + BalanceService)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Response::requireMethod('POST');

$body = Response::requirePostJson();
$id = (string) ($body['id'] ?? '');
$action = (string) ($body['action'] ?? '');

$load = function () use ($db, $id) {
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
};

$result = function () use ($db, $load) {
    Bus::publish('orders');
    Response::json(Serialize::order($db, $load()));
};

$order = $load();
if (!$order) {
    Response::error('Заказ не найден', 404);
}

$claims = Guard::claims();
$driverActions = ['accept', 'reject', 'en_route', 'arrived', 'start', 'complete'];

if (in_array($action, $driverActions, true)) {
    $requestedDriverId = (string) ($body['driverId'] ?? $order['driver_id'] ?? '');
    if (($claims['role'] ?? '') !== 'driver' || ($claims['driverId'] ?? '') !== $requestedDriverId) {
        Response::error('Действие доступно только водителю от своего имени', 403);
    }
    if (in_array($action, ['en_route', 'arrived', 'start', 'complete'], true) && $order['driver_id'] !== $claims['driverId']) {
        Response::error('Этот заказ принадлежит другому водителю', 403);
    }
} elseif ($action === 'assign') {
    Guard::role($claims, 'operator', 'admin');
} elseif ($action === 'cancel') {
    $isOwner = $order['client_id'] !== null && $order['client_id'] === ($claims['uid'] ?? '');
    $isAssignedDriver = ($claims['role'] ?? '') === 'driver'
        && $order['driver_id'] !== null
        && $order['driver_id'] === ($claims['driverId'] ?? '');
    if (!$isOwner && !$isAssignedDriver) {
        Guard::role($claims, 'operator', 'admin');
    }
} elseif ($action === 'rate') {
    if ($order['client_id'] !== ($claims['uid'] ?? '')) {
        Response::error('Оценить поездку может только клиент заказа', 403);
    }
}

$chargeTransaction = function (string $driverId, string $orderId, string $type, float $amount, string $description, ?string $createdBy = null) use ($db) {
    $d = $db->prepare('SELECT balance FROM drivers WHERE id = ?');
    $d->execute([$driverId]);
    $balance = (float) ($d->fetchColumn() ?: 0);
    $newBalance = $balance + $amount;
    $db->prepare('UPDATE drivers SET balance = ? WHERE id = ?')->execute([$newBalance, $driverId]);
    $db->prepare(
        'INSERT INTO balance_transactions (id, driver_id, order_id, type, amount, balance_after, description, created_by)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([Db::uuid(), $driverId, $orderId, $type, $amount, $newBalance, $description, $createdBy]);
    return $newBalance;
};

switch ($action) {
    case 'accept': {
        $driverId = (string) ($body['driverId'] ?? '');
        if (!in_array($order['status'], ['searching', 'no_driver_found'], true)) {
            Response::error('Заказ уже принят или недоступен', 409);
        }
        $d = $db->prepare('SELECT * FROM drivers WHERE id = ? LIMIT 1');
        $d->execute([$driverId]);
        $driver = $d->fetch();
        if (!$driver) {
            Response::error('Водитель не найден', 404);
        }
        if ($driver['balance'] < $driver['min_balance_for_orders']) {
            Response::error(sprintf(
                'Недостаточно средств на балансе. Баланс: %.0f руб., минимум: %.0f руб.',
                $driver['balance'],
                $driver['min_balance_for_orders']
            ));
        }
        $db->prepare("UPDATE orders SET driver_id = ?, status = 'driver_assigned', accepted_at = ? WHERE id = ?")
            ->execute([$driverId, Db::utcNow(), $id]);
        $db->prepare("UPDATE drivers SET status = 'on_route', current_order_id = ? WHERE id = ?")
            ->execute([$id, $driverId]);
        $result();
    }

    case 'reject': {
        $driverId = (string) ($body['driverId'] ?? '');
        $reason = $body['reason'] ?? null;
        $db->prepare('INSERT INTO order_rejections (id, order_id, driver_id, reason) VALUES (?,?,?,?)')
            ->execute([Db::uuid(), $id, $driverId, $reason]);

        if ($order['driver_id'] === $driverId) {
            $db->prepare("UPDATE orders SET driver_id = NULL, status = 'searching', accepted_at = NULL WHERE id = ?")
                ->execute([$id]);
            $db->prepare("UPDATE drivers SET status = 'available', current_order_id = NULL,
                        cancelled_trips = cancelled_trips + 1 WHERE id = ?")
                ->execute([$driverId]);
        }

        // Штраф за отказ
        $d = $db->prepare('SELECT rejection_penalty FROM drivers WHERE id = ?');
        $d->execute([$driverId]);
        $penalty = (float) ($d->fetchColumn() ?: 0);
        if ($penalty > 0) {
            $chargeTransaction($driverId, $id, 'penalty', -$penalty, 'Штраф за отказ от заказа');
        }
        $nameStmt = $db->prepare(
            'SELECT CONCAT(u.first_name," ",u.last_name) FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=?'
        );
        $nameStmt->execute([$driverId]);
        NotificationService::notifyOperatorsDriverRejected(
            $db, $order, (string) ($nameStmt->fetchColumn() ?: 'Водитель'), $reason
        );
        NotificationService::notifyOperatorsOrderUpdate($db, $load());
        $result();
    }

    case 'en_route': {
        $db->prepare("UPDATE orders SET status = 'driver_en_route' WHERE id = ?")
            ->execute([$id]);
        NotificationService::notifyOperatorsOrderUpdate($db, $load());
        $result();
    }

    case 'arrived': {
        $db->prepare("UPDATE orders SET status = 'driver_arrived', driver_arrived_at = ? WHERE id = ?")
            ->execute([Db::utcNow(), $id]);
        $fresh = $load();
        NotificationService::notifyClientDriverArrived($db, $fresh);
        ZvonokService::callClientOnDriverArrived($db, $fresh);
        NotificationService::notifyOperatorsOrderUpdate($db, $fresh);
        $result();
    }

    case 'start': {
        $db->prepare("UPDATE orders SET status = 'in_progress', trip_started_at = ? WHERE id = ?")
            ->execute([Db::utcNow(), $id]);
        if (!empty($order['driver_id'])) {
            $db->prepare("UPDATE drivers SET status = 'in_trip' WHERE id = ?")
                ->execute([$order['driver_id']]);
        }
        NotificationService::notifyOperatorsOrderUpdate($db, $load());
        $result();
    }

    case 'complete': {
        $finalPrice = (float) ($body['finalPrice'] ?? $order['estimated_price']) ?: (float) $order['estimated_price'];
        $db->prepare("UPDATE orders SET status = 'completed', completed_at = ?, final_price = ? WHERE id = ?")
            ->execute([Db::utcNow(), $finalPrice, $id]);

        if (!empty($order['driver_id'])) {
            $db->prepare(
                "UPDATE drivers SET status = 'available', current_order_id = NULL,
                 completed_trips = completed_trips + 1,
                 total_earnings = total_earnings + ?, today_earnings = today_earnings + ?
                 WHERE id = ?"
            )->execute([$finalPrice, $finalPrice, $order['driver_id']]);

            // Комиссия тарифа
            $t = $db->prepare('SELECT commission_percent FROM tariffs WHERE type = ? LIMIT 1');
            $t->execute([$order['tariff']]);
            $percent = (float) ($t->fetchColumn() ?: 15);
            $commission = round($finalPrice * $percent / 100, 2);
            $chargeTransaction(
                $order['driver_id'], $id, 'commission', -$commission,
                sprintf('Комиссия %s (%.0f руб.)', rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.') . '%', $finalPrice)
            );
        }
        $result();
    }

    case 'cancel': {
        $db->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ?, cancellation_reason = ? WHERE id = ?")
            ->execute([Db::utcNow(), $body['reason'] ?? null, $id]);
        if (!empty($order['driver_id'])) {
            $db->prepare("UPDATE drivers SET status = 'available', current_order_id = NULL WHERE id = ?")
                ->execute([$order['driver_id']]);
        }
        $result();
    }

    case 'assign': {
        $driverId = (string) ($body['driverId'] ?? '');
        if (in_array($order['status'], ['completed', 'cancelled'], true)) {
            Response::error('Заказ уже завершён или отменён', 409);
        }
        $d = $db->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1');
        $d->execute([$driverId]);
        if (!$d->fetch()) {
            Response::error('Водитель не найден', 404);
        }
        if (!empty($order['driver_id']) && $order['driver_id'] !== $driverId) {
            $db->prepare("UPDATE drivers SET status = 'available', current_order_id = NULL WHERE id = ?")
                ->execute([$order['driver_id']]);
        }
        $db->prepare("UPDATE orders SET driver_id = ?, status = 'driver_assigned', accepted_at = ? WHERE id = ?")
            ->execute([$driverId, Db::utcNow(), $id]);
        $db->prepare("UPDATE drivers SET status = 'on_route', current_order_id = ? WHERE id = ?")
            ->execute([$id, $driverId]);
        $fresh = $load();
        NotificationService::notifyClientOrderAccepted($db, $fresh);
        NotificationService::notifyDriverForceAssigned($db, $driverId, $fresh);
        NotificationService::notifyOperatorsOrderUpdate($db, $fresh);
        $result();
    }

    case 'rate': {
        $rating = max(1, min(5, (int) ($body['rating'] ?? 5)));
        $db->prepare('UPDATE orders SET client_rating = ? WHERE id = ?')->execute([$rating, $id]);

        // Пересчёт рейтинга водителя по всем оценённым поездкам
        if (!empty($order['driver_id'])) {
            $avg = $db->prepare(
                'SELECT AVG(client_rating) FROM orders WHERE driver_id = ? AND client_rating IS NOT NULL'
            );
            $avg->execute([$order['driver_id']]);
            $value = $avg->fetchColumn();
            if ($value !== null && $value !== false) {
                $d = $db->prepare('SELECT user_id FROM drivers WHERE id = ?');
                $d->execute([$order['driver_id']]);
                if ($uid = $d->fetchColumn()) {
                    $db->prepare('UPDATE users SET rating = ? WHERE id = ?')
                        ->execute([round((float) $value, 1), $uid]);
                }
            }
        }
        $result();
    }

    default:
        Response::error("Неизвестный action: $action");
}
