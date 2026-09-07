<?php
// POST api/orders/operator — CreateOrderByOperatorAsync (только оператор/админ)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Response::requireMethod('POST');

$claims = Guard::claims();
Guard::role($claims, 'operator', 'admin');

$body = Response::requirePostJson();
$service = ServiceSettings::get($db);
$clientPhone = Auth::normalizePhone((string) ($body['clientPhone'] ?? ''));
$clientName = trim((string) ($body['clientName'] ?? '')) ?: 'Клиент';
$pickupAddress = trim((string) ($body['pickupAddress'] ?? ''));
if (strlen($clientPhone) < 11 || $pickupAddress === '') {
    Response::error('Телефон клиента и адрес подачи обязательны');
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

// Клиент автоматически сохраняется в базе: повторные заказы найдут его по телефону.
$clientId = ClientDirectory::ensure($db, $clientPhone, $clientName);

// Промежуточные точки принимаем в обоих форматах ключей (camelCase и PascalCase).
$rawStops = $body['intermediatePoints'] ?? $body['IntermediatePoints'] ?? [];
$stops = [];
foreach ((array) $rawStops as $point) {
    if (!is_array($point)) continue;
    $address = trim((string) ($point['address'] ?? $point['Address'] ?? ''));
    if ($address === '') continue;
    $lat = (float) ($point['latitude'] ?? $point['Latitude'] ?? 0);
    $lng = (float) ($point['longitude'] ?? $point['Longitude'] ?? 0);
    if ($lat == 0.0 || $lng == 0.0) {
        $g = Taxi::geocodeAddress($address, $service['center_latitude'], $service['center_longitude']);
        $lat = $g['lat'];
        $lng = $g['lng'];
    }
    $stops[] = ['address' => mb_substr($address, 0, 500), 'lat' => $lat, 'lng' => $lng];
}

// Поездка «туда и обратно»: машина возвращает клиента в точку подачи.
$roundTrip = !empty($body['roundTrip'] ?? $body['RoundTrip'] ?? false);

$tariff = Taxi::normalizeTariff($body['tariff'] ?? 'economy');
$pricingMode = 'tariff';
$fromZoneId = null;
$toZoneId = null;
$estimatedPrice = 0.0;
$estimatedDistance = null;
$estimatedDuration = null;
$routeGeometry = null;

if ($destinationAddress && $destLat != 0.0) {
    // Полный маршрут в строгом порядке: подача → промежуточные → назначение,
    // при «туда-обратно» добавляем обратный путь через те же точки.
    $routePoints = [[$pickupLat, $pickupLng]];
    foreach ($stops as $stop) {
        $routePoints[] = [$stop['lat'], $stop['lng']];
    }
    $routePoints[] = [$destLat, $destLng];
    if ($roundTrip) {
        foreach (array_reverse($stops) as $stop) {
            $routePoints[] = [$stop['lat'], $stop['lng']];
        }
        $routePoints[] = [$pickupLat, $pickupLng];
    }

    $route = Taxi::getRouteThrough($routePoints);
    $t = $db->prepare("SELECT * FROM tariffs WHERE type = ? AND is_active = 1 LIMIT 1");
    $t->execute([$tariff]);
    if ($tariffRow = $t->fetch()) {
        $p = Taxi::computePrice($tariffRow, (float) $route['distanceKm'], (int) $service['utc_offset']);
        $estimatedPrice = (float) $p['price'];
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
            Taxi::getRouteGeometryThrough($routePoints),
            JSON_UNESCAPED_SLASHES
        );
    }
}
if ($estimatedPrice == 0.0) {
    $t = $db->prepare('SELECT minimum_fare FROM tariffs WHERE type = ? LIMIT 1');
    $t->execute([$tariff]);
    $estimatedPrice = (float) ($t->fetchColumn() ?: 99);
}

$optionCodes = array_values(array_filter(
    is_array($body['options'] ?? null) ? $body['options'] : [],
    'is_string'
));
$estimatedPrice += Options::total($optionCodes);

$orderId = Db::uuid();
$db->prepare(
    'INSERT INTO orders (id, order_number, operator_id, source, client_id, client_phone, client_name,
     pickup_address, pickup_latitude, pickup_longitude, pickup_entrance,
     destination_address, destination_entrance, destination_latitude, destination_longitude,
     tariff, estimated_price, estimated_distance, estimated_duration, route_geometry,
     pricing_mode, from_zone_id, to_zone_id,
     comment, passenger_count, status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $orderId, Taxi::generateOrderNumber(),
    (string) ($body['operatorId'] ?? $claims['uid']), 'operator_app', $clientId, $clientPhone, $clientName,
    $pickupAddress, $pickupLat, $pickupLng, $body['pickupEntrance'] ?? null,
    $destinationAddress, $body['destinationEntrance'] ?? null,
    $destLat != 0.0 ? $destLat : null, $destLng != 0.0 ? $destLng : null,
    $tariff, $estimatedPrice, $estimatedDistance, $estimatedDuration, $routeGeometry,
    $pricingMode, $fromZoneId, $toZoneId,
    $body['comment'] ?? null,
    (int) ($body['passengerCount'] ?? 1) ?: 1,
    'searching',
]);

foreach (Options::resolve($optionCodes) as $opt) {
    $db->prepare('INSERT INTO order_options (id, order_id, code, name, price) VALUES (?,?,?,?,?)')
        ->execute([Db::uuid(), $orderId, $opt['code'], $opt['name'], $opt['price']]);
}
foreach ($stops as $index => $stop) {
    $db->prepare('INSERT INTO route_points(id,order_id,address,latitude,longitude,sort_order) VALUES (?,?,?,?,?,?)')
        ->execute([Db::uuid(), $orderId, $stop['address'], $stop['lat'], $stop['lng'], (int) $index]);
}
$db->prepare("INSERT INTO transactions(id,order_id,amount,method,status) VALUES (?,?,?,'cash','pending')")
    ->execute([Db::uuid(),$orderId,$estimatedPrice]);

$stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$createdOrder = $stmt->fetch();
NotificationService::notifyNearbyDriversNewOrder($db, $createdOrder);
NotificationService::notifyOperatorsOrderUpdate($db, $createdOrder);
Bus::publish('orders');
Response::json(Serialize::order($db, $createdOrder), 201);
