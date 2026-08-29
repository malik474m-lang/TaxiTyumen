<?php
// GET  api/orders/  — списки (active/available/history/all/clientActive/driverCurrent)
// POST api/orders/  — создание клиентского заказа (CreateOrderAsync)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Simulate::advance($db);
AutoCall::tick($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = Response::requirePostJson();
    $clientId = (string) ($body['clientId'] ?? '');
    if ($clientId === '') {
        Response::error('clientId обязателен');
    }
    $claims = Guard::claims();
    if (($claims['role'] ?? '') !== 'client' || ($claims['uid'] ?? '') !== $clientId) {
        Response::error('Создать заказ может только его клиент', 403);
    }

    $pickupAddress = trim((string) ($body['pickupAddress'] ?? ''));
    if ($pickupAddress === '') {
        Response::error('Укажите адрес подачи');
    }
    $pickupLat = (float) ($body['pickupLatitude'] ?? 0);
    $pickupLng = (float) ($body['pickupLongitude'] ?? 0);
    if ($pickupLat == 0.0) {
        $g = Taxi::geocodeAddress($pickupAddress);
        $pickupLat = $g['lat'];
        $pickupLng = $g['lng'];
    }

    $destinationAddress = trim((string) ($body['destinationAddress'] ?? '')) ?: null;
    $destLat = (float) ($body['destinationLatitude'] ?? 0);
    $destLng = (float) ($body['destinationLongitude'] ?? 0);
    if ($destinationAddress && $destLat == 0.0) {
        $g = Taxi::geocodeAddress($destinationAddress);
        $destLat = $g['lat'];
        $destLng = $g['lng'];
    }

    $tariff = (string) ($body['tariff'] ?? 'economy');
    $estimatedPrice = 0.0;
    $estimatedDistance = null;
    $estimatedDuration = null;
    $routeGeometry = null;

    if ($destinationAddress && $destLat != 0.0) {
        $route = Taxi::getRealRoute($pickupLat, $pickupLng, $destLat, $destLng);
        $t = $db->prepare("SELECT * FROM tariffs WHERE type = ? AND is_active = 1 LIMIT 1");
        $t->execute([$tariff]);
        $tariffRow = $t->fetch();
        if (!$tariffRow) {
            Response::error("Тариф $tariff не найден");
        }
        $p = Taxi::computePrice($tariffRow, (float) $route['distanceKm']);
        $estimatedPrice = (float) $p['price'];
        $estimatedDistance = (float) $route['distanceKm'];
        $estimatedDuration = (int) $route['durationMinutes'];
        $routeGeometry = json_encode(
            Taxi::getRouteGeometry($pickupLat, $pickupLng, $destLat, $destLng),
            JSON_UNESCAPED_SLASHES
        );
    } else {
        $t = $db->prepare('SELECT minimum_fare FROM tariffs WHERE type = ? LIMIT 1');
        $t->execute([$tariff]);
        $estimatedPrice = (float) ($t->fetchColumn() ?: 99);
    }

    // Опции заказа
    $optionCodes = array_values(array_filter(
        is_array($body['options'] ?? null) ? $body['options'] : [],
        'is_string'
    ));
    $estimatedPrice += Options::total($optionCodes);

    $orderId = Db::uuid();
    $db->prepare(
        'INSERT INTO orders (id, order_number, client_id, source, pickup_address, pickup_latitude,
         pickup_longitude, pickup_entrance, destination_address, destination_latitude, destination_longitude,
         tariff, estimated_price, estimated_distance, estimated_duration, route_geometry,
         comment, passenger_count, payment_method, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $orderId, Taxi::generateOrderNumber(), $clientId, 'client_app',
        $pickupAddress, $pickupLat, $pickupLng, $body['pickupEntrance'] ?? null,
        $destinationAddress, $destLat != 0.0 ? $destLat : null, $destLng != 0.0 ? $destLng : null,
        $tariff, $estimatedPrice, $estimatedDistance, $estimatedDuration, $routeGeometry,
        $body['comment'] ?? null,
        (int) ($body['passengerCount'] ?? 1) ?: 1,
        (string) ($body['paymentMethod'] ?? 'cash'),
        'searching',
    ]);

    foreach (Options::resolve($optionCodes) as $opt) {
        $db->prepare('INSERT INTO order_options (id, order_id, code, name, price) VALUES (?,?,?,?,?)')
            ->execute([Db::uuid(), $orderId, $opt['code'], $opt['name'], $opt['price']]);
    }

    Bus::publish('orders');
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    Response::json(Serialize::order($db, $stmt->fetch()), 201);
}

// ── GET-списки ──────────────────────────────────────────────────────────────

$view = (string) ($_GET['view'] ?? 'active');
$activeIn = "'" . implode("','", Taxi::ACTIVE_STATUSES) . "'";

$serializeMany = function (array $rows) use ($db) {
    return array_map(fn(array $o) => Serialize::order($db, $o), $rows);
};

switch ($view) {
    case 'active':
        $rows = $db->query(
            "SELECT * FROM orders WHERE status IN ($activeIn) ORDER BY created_at DESC LIMIT 100"
        )->fetchAll();
        Response::json($serializeMany($rows));

    case 'available': {
        $driverId = (string) ($_GET['driverId'] ?? '');
        $lat = (float) ($_GET['lat'] ?? 0);
        $lng = (float) ($_GET['lng'] ?? 0);
        if ($driverId !== '' && $lat != 0.0) {
            $db->prepare('UPDATE drivers SET latitude = ?, longitude = ?, last_location_update = ? WHERE id = ?')
                ->execute([$lat, $lng, Db::utcNow(), $driverId]);
        }
        $rows = $db->query(
            "SELECT * FROM orders WHERE (status = 'searching' OR status = 'no_driver_found')
             AND driver_id IS NULL ORDER BY created_at LIMIT 50"
        )->fetchAll();
        $out = $serializeMany($rows);
        if ($driverId !== '' && $lat != 0.0) {
            foreach ($out as &$o) {
                $o['distanceToPickup'] = round(
                    Taxi::getDistanceKm($lat, $lng, (float) $o['pickupLatitude'], (float) $o['pickupLongitude']),
                    1
                );
            }
        }
        Response::json($out);
    }

    case 'history': {
        if (!empty($_GET['clientId'])) {
            $stmt = $db->prepare('SELECT * FROM orders WHERE client_id = ? ORDER BY created_at DESC LIMIT 50');
            $stmt->execute([$_GET['clientId']]);
        } elseif (!empty($_GET['driverId'])) {
            $stmt = $db->prepare('SELECT * FROM orders WHERE driver_id = ? ORDER BY created_at DESC LIMIT 50');
            $stmt->execute([$_GET['driverId']]);
        } else {
            Response::error('clientId или driverId обязателен');
        }
        Response::json($serializeMany($stmt->fetchAll()));
    }

    case 'all':
        $rows = $db->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 200')->fetchAll();
        Response::json($serializeMany($rows));

    case 'clientActive': {
        $stmt = $db->prepare(
            "SELECT * FROM orders WHERE client_id = ? AND status IN ($activeIn) ORDER BY created_at DESC LIMIT 5"
        );
        $stmt->execute([(string) ($_GET['clientId'] ?? '')]);
        Response::json($serializeMany($stmt->fetchAll()));
    }

    case 'driverCurrent': {
        $driverId = (string) ($_GET['driverId'] ?? '');
        $d = $db->prepare('SELECT current_order_id FROM drivers WHERE id = ? LIMIT 1');
        $d->execute([$driverId]);
        $orderId = $d->fetchColumn();
        if (!$orderId) {
            Response::json(null);
        }
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order || !in_array($order['status'], Taxi::ACTIVE_STATUSES, true)) {
            Response::json(null);
        }
        Response::json(Serialize::order($db, $order));
    }

    case 'today':
        $rows = $db->query(
            "SELECT * FROM orders WHERE created_at >= CURDATE() ORDER BY created_at DESC LIMIT 200"
        )->fetchAll();
        Response::json($serializeMany($rows));

    default:
        Response::error('Неизвестный view');
}
