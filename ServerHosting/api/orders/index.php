<?php
// GET  api/orders/  — списки (active/available/history/all/clientActive/driverCurrent)
// POST api/orders/  — создание клиентского заказа (CreateOrderAsync)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Simulate::advance($db);
DriverTimeout::tick($db);
AutoCall::tick($db);
WaitingTimer::tick($db);   // автостарт платного простоя после бесплатных минут

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

    $service = ServiceSettings::get($db);
    $pickupAddress = trim((string) ($body['pickupAddress'] ?? ''));
    if ($pickupAddress === '') {
        Response::error('Укажите адрес подачи');
    }
    $pickupLat = (float) ($body['pickupLatitude'] ?? 0);
    $pickupLng = (float) ($body['pickupLongitude'] ?? 0);
    if ($pickupLat == 0.0) {
        $g = Taxi::geocodeAddress($pickupAddress, $service['center_latitude'], $service['center_longitude']);
        $pickupLat = $g['lat'];
        $pickupLng = $g['lng'];
    }

    $destinationAddress = trim((string) ($body['destinationAddress'] ?? '')) ?: null;
    $destLat = (float) ($body['destinationLatitude'] ?? 0);
    $destLng = (float) ($body['destinationLongitude'] ?? 0);
    if ($destinationAddress && $destLat == 0.0) {
        $g = Taxi::geocodeAddress($destinationAddress, $service['center_latitude'], $service['center_longitude']);
        $destLat = $g['lat'];
        $destLng = $g['lng'];
    }

    $tariff = (string) ($body['tariff'] ?? 'economy');
    $estimatedPrice = 0.0;
    $estimatedDistance = null;
    $estimatedDuration = null;
    $routeGeometry = null;

    $pricingMode = 'tariff';
    $fromZoneId = null;
    $toZoneId = null;
    if ($destinationAddress && $destLat != 0.0) {
        $route = Taxi::getRealRoute($pickupLat, $pickupLng, $destLat, $destLng);
        $t = $db->prepare("SELECT * FROM tariffs WHERE type = ? AND is_active = 1 LIMIT 1");
        $t->execute([$tariff]);
        $tariffRow = $t->fetch();
        if (!$tariffRow) {
            Response::error("Тариф $tariff не найден");
        }
        $p = Taxi::computePrice($tariffRow, (float) $route['distanceKm'], (int) $service['utc_offset']);
        $estimatedPrice = (float) $p['price'];

        // Фиксированная зональная цена имеет приоритет над расчётом по километрам
        $zonePrice = Zones::fixedPrice($db, $pickupLat, $pickupLng, $destLat, $destLng, $tariff);
        if ($zonePrice !== null) {
            $estimatedPrice = $zonePrice['applyMultipliers']
                ? round($zonePrice['price'] * (float) $p['multiplier'])
                : $zonePrice['price'];
            $pricingMode = 'zone';
            $fromZoneId = $zonePrice['fromZone']['id'];
            $toZoneId = $zonePrice['toZone']['id'];
        }
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

    // Опции заказа. Поверх зональной фикс-цены добавляем, только если это
    // разрешено настройкой «Добавлять опции» в разделе «Зоны и цены».
    $optionCodes = array_values(array_filter(
        is_array($body['options'] ?? null) ? $body['options'] : [],
        'is_string'
    ));
    $optionsTotal = Options::total($db, $optionCodes);
    if ($pricingMode === 'zone' && !(int) (Zones::settings($db)['add_options'] ?? 1)) {
        $optionsTotal = 0.0;
    }
    $estimatedPrice += $optionsTotal;

    $orderId = Db::uuid();
    $db->prepare(
        'INSERT INTO orders (id, order_number, client_id, source, pickup_address, pickup_latitude,
         pickup_longitude, pickup_entrance, destination_address, destination_latitude, destination_longitude,
         tariff, estimated_price, estimated_distance, estimated_duration, route_geometry,
         pricing_mode, from_zone_id, to_zone_id,
         comment, passenger_count, payment_method, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $orderId, Taxi::generateOrderNumber(), $clientId, 'client_app',
        $pickupAddress, $pickupLat, $pickupLng, $body['pickupEntrance'] ?? null,
        $destinationAddress, $destLat != 0.0 ? $destLat : null, $destLng != 0.0 ? $destLng : null,
        $tariff, $estimatedPrice, $estimatedDistance, $estimatedDuration, $routeGeometry,
        $pricingMode, $fromZoneId, $toZoneId,
        $body['comment'] ?? null,
        (int) ($body['passengerCount'] ?? 1) ?: 1,
        Taxi::normalizePayment($body['paymentMethod'] ?? 'cash'),
        'searching',
    ]);

    // Частые места поднимаются выше в подсказках пассажиров
    Places::touchByAddress($db, $pickupAddress);
    if ($destinationAddress) Places::touchByAddress($db, $destinationAddress);

    foreach (Options::resolve($db, $optionCodes) as $opt) {
        $db->prepare('INSERT INTO order_options (id, order_id, code, name, price) VALUES (?,?,?,?,?)')
            ->execute([Db::uuid(), $orderId, $opt['code'], $opt['name'], $opt['price']]);
    }
    foreach ((array) ($body['intermediatePoints'] ?? []) as $index => $point) {
        if (!is_array($point) || empty($point['address'])) continue;
        $g = (!empty($point['latitude']) && !empty($point['longitude']))
            ? ['lat'=>(float)$point['latitude'],'lng'=>(float)$point['longitude']]
            : Taxi::geocodeAddress((string)$point['address'], $service['center_latitude'], $service['center_longitude']);
        $db->prepare('INSERT INTO route_points(id,order_id,address,latitude,longitude,sort_order) VALUES (?,?,?,?,?,?)')
            ->execute([Db::uuid(),$orderId,mb_substr((string)$point['address'],0,500),$g['lat'],$g['lng'],(int)$index]);
    }
    $paymentMethod = Taxi::normalizePayment($body['paymentMethod'] ?? 'cash');
    // Карточный заказ нельзя принять, если эквайринг выключен: поездка
    // завершилась бы, но клиенту было бы некуда перечислить деньги.
    if ($paymentMethod === 'card' && !SberPayments::settings($db)['configured']) {
        Response::error('Оплата картой пока недоступна. Выберите наличные.', 409);
    }
    $db->prepare(
        "INSERT INTO transactions(id,order_id,amount,method,status) VALUES (?,?,?,?, 'pending')"
    )->execute([Db::uuid(),$orderId,$estimatedPrice,$paymentMethod]);

    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $createdOrder = $stmt->fetch();
    NotificationService::notifyNearbyDriversNewOrder($db, $createdOrder);
    NotificationService::notifyOperatorsOrderUpdate($db, $createdOrder);
    Bus::publish('orders');
    Response::json(Serialize::order($db, $createdOrder), 201);
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
        // Предварительные заказы попадают в ленту за 30 минут до подачи,
        // чтобы не занимать водителей задолго до времени клиента.
        $rows = $db->query(
            "SELECT * FROM orders
             WHERE (status = 'searching' OR status = 'no_driver_found')
               AND driver_id IS NULL
               AND (scheduled_at IS NULL
                    OR scheduled_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))
             ORDER BY scheduled_at IS NULL DESC, scheduled_at, created_at
             LIMIT 50"
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
        // «Сегодня» — местные сутки города, а не UTC-сутки сервера
        $offSec = (int) (ServiceSettings::get($db)['utc_offset'] ?? 5) * 3600;
        $localDate = gmdate('Y-m-d', time() + $offSec);
        $todayStartUtc = gmdate('Y-m-d H:i:s', strtotime($localDate . ' 00:00:00') - $offSec);
        $stmt = $db->prepare(
            'SELECT * FROM orders WHERE created_at >= ? ORDER BY created_at DESC LIMIT 200'
        );
        $stmt->execute([$todayStartUtc]);
        $rows = $stmt->fetchAll();
        Response::json($serializeMany($rows));

    default:
        Response::error('Неизвестный view');
}
