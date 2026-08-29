<?php
// GET  api/drivers/action.php?id=&view=balance|history — баланс и история
// POST api/drivers/action.php — status | location | topup | verify (права по ролям)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (string) ($_GET['id'] ?? '');
    $view = (string) ($_GET['view'] ?? 'balance');
    $d = $db->prepare('SELECT * FROM drivers WHERE id = ? LIMIT 1');
    $d->execute([$id]);
    $driver = $d->fetch();
    if (!$driver) {
        Response::error('Водитель не найден', 404);
    }

    if ($view === 'history') {
        $t = $db->prepare(
            'SELECT * FROM balance_transactions WHERE driver_id = ? ORDER BY created_at DESC LIMIT 30'
        );
        $t->execute([$id]);
        Response::json([
            'balance' => (float) $driver['balance'],
            'transactions' => array_map(fn(array $tr) => [
                'id' => $tr['id'],
                'driverId' => $tr['driver_id'],
                'orderId' => $tr['order_id'],
                'type' => $tr['type'],
                'amount' => (float) $tr['amount'],
                'balanceAfter' => (float) $tr['balance_after'],
                'description' => $tr['description'],
                'createdBy' => $tr['created_by'],
                'createdAt' => $tr['created_at'],
            ], $t->fetchAll()),
        ]);
    }

    Response::json([
        'balance' => (float) $driver['balance'],
        'minBalanceForOrders' => (float) $driver['min_balance_for_orders'],
        'todayEarnings' => (float) $driver['today_earnings'],
        'totalEarnings' => (float) $driver['total_earnings'],
        'completedTrips' => (int) $driver['completed_trips'],
        'status' => $driver['status'],
        'hasSufficientBalance' => $driver['balance'] >= $driver['min_balance_for_orders'],
    ]);
}

// POST
$body = Response::requirePostJson();
$id = (string) ($body['id'] ?? '');
$action = (string) ($body['action'] ?? '');

$d = $db->prepare('SELECT * FROM drivers WHERE id = ? LIMIT 1');
$d->execute([$id]);
$driver = $d->fetch();
if (!$driver) {
    Response::error('Водитель не найден', 404);
}

$claims = Guard::claims();
if (in_array($action, ['status', 'location'], true)) {
    if (($claims['role'] ?? '') !== 'driver' || ($claims['driverId'] ?? '') !== $id) {
        Response::error('Управлять своим статусом может только сам водитель', 403);
    }
}
if (in_array($action, ['topup', 'verify'], true)) {
    Guard::role($claims, 'admin');
}

switch ($action) {
    case 'status': {
        $status = (string) ($body['status'] ?? 'offline');
        if ($status === 'offline') {
            $db->prepare("UPDATE drivers SET status = 'offline', current_order_id = NULL, last_location_update = ? WHERE id = ?")
                ->execute([Db::utcNow(), $id]);
        } else {
            $db->prepare('UPDATE drivers SET status = ?, last_location_update = ? WHERE id = ?')
                ->execute([$status, Db::utcNow(), $id]);
        }
        Bus::publish('drivers');
        Response::json(['ok' => true, 'status' => $status]);
    }

    case 'location': {
        $lat = (float) ($body['latitude'] ?? 0);
        $lng = (float) ($body['longitude'] ?? 0);
        if ($lat == 0.0 || $lng == 0.0) {
            Response::error('Некорректные координаты');
        }
        $db->prepare('UPDATE drivers SET latitude = ?, longitude = ?, last_location_update = ? WHERE id = ?')
            ->execute([$lat, $lng, Db::utcNow(), $id]);
        Bus::publish('drivers');
        Response::json(['ok' => true]);
    }

    case 'topup': {
        $amount = (float) ($body['amount'] ?? 0);
        if ($amount <= 0) {
            Response::error('Сумма должна быть больше нуля');
        }
        $newBalance = $driver['balance'] + $amount;
        $db->prepare('UPDATE drivers SET balance = ? WHERE id = ?')->execute([$newBalance, $id]);
        $db->prepare(
            "INSERT INTO balance_transactions (id, driver_id, type, amount, balance_after, description, created_by)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            Db::uuid(), $id, 'topup', $amount, $newBalance,
            sprintf('Пополнение +%.0f руб.', $amount),
            (string) ($body['createdBy'] ?? 'admin'),
        ]);
        Bus::publish('drivers');
        Response::json(['ok' => true, 'balance' => $newBalance]);
    }

    case 'verify': {
        $db->prepare('UPDATE drivers SET is_verified = ? WHERE id = ?')
            ->execute([(bool) ($body['isVerified'] ?? true) ? 1 : 0, $id]);
        Response::json(['ok' => true]);
    }

    default:
        Response::error("Неизвестный action: $action");
}
