<?php
// REST API платежей Сбер для клиента и водителя.
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

SberPayments::ensureTables($db);
$claims = Guard::claims();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = $method === 'POST' ? Response::requirePostJson() : [];
$action = strtolower((string) ($_GET['action'] ?? $body['action'] ?? 'config'));

/** Проверка, что платёж принадлежит текущему пользователю/его профилю. */
$paymentForUser = static function (string $id) use ($db, $claims): array {
    $stmt = $db->prepare('SELECT * FROM sber_payments WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) Response::error('Платёж не найден', 404);
    $allowed = in_array($claims['role'] ?? '', ['admin', 'superadmin'], true)
        || (($claims['role'] ?? '') === 'client' && $p['client_id'] === ($claims['uid'] ?? ''))
        || (($claims['role'] ?? '') === 'driver' && $p['driver_id'] === ($claims['driverId'] ?? ''));
    if (!$allowed) Response::error('Нет доступа к этому платежу', 403);
    return $p;
};

if ($action === 'config') {
    Response::json(SberPayments::publicConfig(
        $db,
        ($claims['role'] ?? '') === 'client' ? (string) $claims['uid'] : null
    ));
}

// Последняя завершённая, но неоплаченная карточная поездка клиента
if ($action === 'pending-order') {
    Guard::role($claims, 'client');
    $stmt = $db->prepare(
        "SELECT o.* FROM orders o JOIN transactions t ON t.order_id=o.id
         WHERE o.client_id=? AND o.status='completed' AND o.payment_method='card'
           AND t.status='pending'
         ORDER BY o.completed_at DESC LIMIT 1"
    );
    $stmt->execute([$claims['uid']]);
    $order = $stmt->fetch();
    Response::json($order ? Serialize::order($db, $order) : null);
}

// Клиент оплачивает завершённую поездку банковской картой/СБП через форму Сбера
if ($action === 'pay-order') {
    Response::requireMethod('POST');
    Guard::role($claims, 'client');
    $orderId = (string) ($body['orderId'] ?? '');
    $stmt = $db->prepare('SELECT * FROM orders WHERE id=? AND client_id=? LIMIT 1');
    $stmt->execute([$orderId, $claims['uid']]);
    $order = $stmt->fetch();
    if (!$order) Response::error('Заказ не найден', 404);
    if ($order['status'] !== 'completed') Response::error('Оплата доступна после завершения поездки', 409);
    if ($order['payment_method'] !== 'card') Response::error('В заказе выбран другой способ оплаты', 409);

    // Не создаём второй платёж, если уже есть действующий/оплаченный
    $old = $db->prepare(
        "SELECT * FROM sber_payments WHERE order_id=? AND status IN ('pending','paid')
         ORDER BY created_at DESC LIMIT 1"
    );
    $old->execute([$orderId]);
    if ($p = $old->fetch()) {
        Response::json([
            'id' => $p['id'], 'status' => $p['status'], 'formUrl' => $p['form_url'],
        ]);
    }

    $methodName = strtolower((string) ($body['method'] ?? 'card')) === 'sbp' ? 'sbp' : 'card';
    $amount = (float) ($order['final_price'] ?? $order['estimated_price']);
    Response::json(SberPayments::register(
        $db, 'order', $amount, $orderId, (string) $claims['uid'],
        $order['driver_id'] ?: null, $methodName
    ), 201);
}

// Проверить статус после возврата из платёжной формы
if ($action === 'check') {
    $id = (string) ($_GET['id'] ?? $body['id'] ?? '');
    $paymentForUser($id);
    try {
        $p = SberPayments::reconcile($db, $id);
        Response::json([
            'id' => $p['id'], 'status' => $p['status'], 'amount' => (float) $p['amount'],
            'maskedPan' => $p['masked_pan'] ?? null, 'error' => $p['error'] ?? null,
        ]);
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 502);
    }
}

// Пополнение рабочего баланса водителя (платёжная форма; СБП — если одобрен банком)
if ($action === 'driver-topup') {
    Response::requireMethod('POST');
    Guard::role($claims, 'driver');
    $amount = round((float) ($body['amount'] ?? 0), 2);
    if ($amount < 100 || $amount > 100000) Response::error('Сумма пополнения: от 100 до 100 000 ₽');
    $payMethod = strtolower((string) ($body['method'] ?? 'sbp')) === 'card' ? 'card' : 'sbp';
    Response::json(SberPayments::register(
        $db, 'driver_topup', $amount, null, null,
        (string) $claims['driverId'], $payMethod
    ), 201);
}

// Безналичный кошелёк и заявки водителя
if ($action === 'wallet') {
    Guard::role($claims, 'driver');
    $driverId = (string) $claims['driverId'];
    $wallet = SberPayments::wallet($db, $driverId);
    $stmt = $db->prepare(
        'SELECT * FROM driver_withdrawal_requests WHERE driver_id=? ORDER BY created_at DESC LIMIT 30'
    );
    $stmt->execute([$driverId]);
    Response::json([
        'cashlessBalance' => (float) ($wallet['cashless_balance'] ?? 0),
        'pendingWithdrawal' => (float) ($wallet['pending_withdrawal'] ?? 0),
        'totalCashlessEarned' => (float) ($wallet['total_cashless_earned'] ?? 0),
        'totalPaidOut' => (float) ($wallet['total_paid_out'] ?? 0),
        'withdrawals' => array_map(fn($r) => [
            'id' => $r['id'], 'amount' => (float) $r['amount'], 'phone' => $r['sbp_phone'],
            'bankName' => $r['bank_name'], 'status' => $r['status'],
            'comment' => $r['admin_comment'], 'createdAt' => $r['created_at'],
        ], $stmt->fetchAll()),
    ]);
}

// Заявка самозанятого на вывод по СБП — пока ручная обработка администратором
if ($action === 'withdraw') {
    Response::requireMethod('POST');
    Guard::role($claims, 'driver');
    try {
        $id = SberPayments::requestWithdrawal(
            $db, (string) $claims['driverId'], (float) ($body['amount'] ?? 0),
            (string) ($body['phone'] ?? ''), trim((string) ($body['bankName'] ?? '')),
            preg_replace('/\D/', '', (string) ($body['inn'] ?? ''))
        );
        Response::json(['ok' => true, 'id' => $id, 'status' => 'pending'], 201);
    } catch (\Throwable $e) {
        Response::error($e->getMessage());
    }
}

Response::error('Неизвестное действие: ' . $action);
