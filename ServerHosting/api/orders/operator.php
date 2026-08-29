<?php
// POST api/orders/operator — CreateOrderByOperatorAsync (только оператор/админ)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Response::requireMethod('POST');

$claims = Guard::claims();
Guard::role($claims, 'operator', 'admin');

$body = Response::requirePostJson();
$clientPhone = Auth::normalizePhone((string) ($body['clientPhone'] ?? ''));
$clientName = trim((string) ($body['clientName'] ?? '')) ?: 'Клиент';
$pickupAddress = trim((string) ($body['pickupAddress'] ?? ''));
if (strlen($clientPhone) < 11 || $pickupAddress === '') {
    Response::error('Телефон клиента и адрес подачи обязательны');
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

$tariff = Taxi::normalizeTariff($body['tariff'] ?? 'economy');
$estimatedPrice = 0.0;
$estimatedDistance = null;
$estimatedDuration = null;
$routeGeometry = null;

if ($destinationAddress && $destLat != 0.0) {
    $route = Taxi::getRealRoute($pickupLat, $pickupLng, $destLat, $destLng);
    $t = $db->prepare("SELECT * FROM tariffs WHERE type = ? AND is_active = 1 LIMIT 1");
    $t->execute([$tariff]);
    if ($tariffRow = $t->fetch()) {
        $p = Taxi::computePrice($tariffRow, (float) $route['distanceKm']);
        $estimatedPrice = (float) $p['price'];
        $estimatedDistance = (float) $route['distanceKm'];
        $estimatedDuration = (int) $route['durationMinutes'];
        $routeGeometry = json_encode(
            Taxi::getRouteGeometry($pickupLat, $pickupLng, $destLat, $destLng),
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
    'INSERT INTO orders (id, order_number, operator_id, source, client_phone, client_name,
     pickup_address, pickup_latitude, pickup_longitude, pickup_entrance,
     destination_address, destination_latitude, destination_longitude,
     tariff, estimated_price, estimated_distance, estimated_duration, route_geometry,
     comment, passenger_count, status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $orderId, Taxi::generateOrderNumber(),
    (string) ($body['operatorId'] ?? $claims['uid']), 'operator_app', $clientPhone, $clientName,
    $pickupAddress, $pickupLat, $pickupLng, $body['pickupEntrance'] ?? null,
    $destinationAddress, $destLat != 0.0 ? $destLat : null, $destLng != 0.0 ? $destLng : null,
    $tariff, $estimatedPrice, $estimatedDistance, $estimatedDuration, $routeGeometry,
    $body['comment'] ?? null,
    (int) ($body['passengerCount'] ?? 1) ?: 1,
    'searching',
]);

foreach (Options::resolve($optionCodes) as $opt) {
    $db->prepare('INSERT INTO order_options (id, order_id, code, name, price) VALUES (?,?,?,?,?)')
        ->execute([Db::uuid(), $orderId, $opt['code'], $opt['name'], $opt['price']]);
}
foreach ((array) ($body['intermediatePoints'] ?? []) as $index => $point) {
    if (!is_array($point) || empty($point['address'])) continue;
    $g = (!empty($point['latitude']) && !empty($point['longitude']))
        ? ['lat'=>(float)$point['latitude'],'lng'=>(float)$point['longitude']]
        : Taxi::geocodeAddress((string)$point['address']);
    $db->prepare('INSERT INTO route_points(id,order_id,address,latitude,longitude,sort_order) VALUES (?,?,?,?,?,?)')
        ->execute([Db::uuid(),$orderId,mb_substr((string)$point['address'],0,500),$g['lat'],$g['lng'],(int)$index]);
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
