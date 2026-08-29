<?php
// GET api/tariffs/ — список | PUT — обновление (только админ)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query('SELECT * FROM tariffs ORDER BY base_fare')->fetchAll();
    Response::json(array_map(fn(array $t) => [
        'id' => $t['id'],
        'type' => $t['type'],
        'name' => $t['name'],
        'description' => $t['description'],
        'baseFare' => (float) $t['base_fare'],
        'pricePerKm' => (float) $t['price_per_km'],
        'pricePerMinute' => (float) $t['price_per_minute'],
        'minimumFare' => (float) $t['minimum_fare'],
        'freeWaitingMinutes' => (float) $t['free_waiting_minutes'],
        'paidWaitingPerMinute' => (float) $t['paid_waiting_per_minute'],
        'nightMultiplier' => (float) $t['night_multiplier'],
        'peakMultiplier' => (float) $t['peak_multiplier'],
        'commissionPercent' => (float) $t['commission_percent'],
        'isActive' => (bool) $t['is_active'],
    ], $rows));
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $claims = Guard::claims();
    Guard::role($claims, 'admin');

    $body = Response::requirePostJson();
    $id = (string) ($body['id'] ?? '');
    $stmt = $db->prepare('SELECT * FROM tariffs WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if (!$t) {
        Response::error('Тариф не найден', 404);
    }

    $db->prepare(
        'UPDATE tariffs SET name = ?, description = ?, base_fare = ?, price_per_km = ?,
         price_per_minute = ?, minimum_fare = ?, night_multiplier = ?, peak_multiplier = ?,
         commission_percent = ?, paid_waiting_per_minute = ?, is_active = ?, updated_at = ?
         WHERE id = ?'
    )->execute([
        (string) ($body['name'] ?? $t['name']),
        (string) ($body['description'] ?? $t['description']),
        (float) ($body['baseFare'] ?? $t['base_fare']),
        (float) ($body['pricePerKm'] ?? $t['price_per_km']),
        (float) ($body['pricePerMinute'] ?? $t['price_per_minute']),
        (float) ($body['minimumFare'] ?? $t['minimum_fare']),
        (float) ($body['nightMultiplier'] ?? $t['night_multiplier']),
        (float) ($body['peakMultiplier'] ?? $t['peak_multiplier']),
        (float) ($body['commissionPercent'] ?? $t['commission_percent']),
        (float) ($body['paidWaitingPerMinute'] ?? $t['paid_waiting_per_minute']),
        isset($body['isActive']) ? ((bool) $body['isActive'] ? 1 : 0) : $t['is_active'],
        Db::utcNow(),
        $id,
    ]);

    $stmt->execute([$id]);
    Response::json($stmt->fetch());
}

Response::error('Метод не поддерживается', 405);
