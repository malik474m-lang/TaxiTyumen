<?php
// Сериализация сущностей в DTO (порт serializeOrder / serializeUser)
declare(strict_types=1);

require_once __DIR__ . '/Taxi.php';

final class Serialize
{
    public static function order(\PDO $db, array $o): array
    {
        $driverInfo = null;
        if (!empty($o['driver_id'])) {
            $stmt = $db->prepare(
                'SELECT d.*, u.first_name, u.last_name, u.phone AS user_phone, u.rating AS user_rating
                 FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ?'
            );
            $stmt->execute([$o['driver_id']]);
            if ($d = $stmt->fetch()) {
                $driverInfo = [
                    'id' => $d['id'],
                    'name' => $d['first_name'] . ' ' . $d['last_name'],
                    'phone' => $d['user_phone'],
                    'carBrand' => $d['car_brand'],
                    'carModel' => $d['car_model'],
                    'carColor' => $d['car_color'],
                    'licensePlate' => $d['license_plate'],
                    'carDisplay' => $d['car_color'] . ' ' . $d['car_brand'] . ' ' . $d['car_model'],
                    'rating' => (float) $d['user_rating'],
                    'latitude' => (float) $d['latitude'],
                    'longitude' => (float) $d['longitude'],
                    'balance' => (float) $d['balance'],
                    'status' => $d['status'],
                ];
            }
        }

        $clientName = $o['client_name'];
        $clientPhone = $o['client_phone'];
        if (empty($clientName) && !empty($o['client_id'])) {
            $stmt = $db->prepare('SELECT first_name, last_name, phone FROM users WHERE id = ?');
            $stmt->execute([$o['client_id']]);
            if ($c = $stmt->fetch()) {
                $clientName = $c['first_name'] . ' ' . $c['last_name'];
                $clientPhone = $c['phone'];
            }
        }

        $oStmt = $db->prepare('SELECT code, name, price FROM order_options WHERE order_id = ?');
        $oStmt->execute([$o['id']]);
        $options = array_map(
            fn(array $r) => ['code' => $r['code'], 'name' => $r['name'], 'price' => (float) $r['price']],
            $oStmt->fetchAll()
        );

        $routePoints = null;
        if (!empty($o['route_geometry'])) {
            $decoded = json_decode($o['route_geometry'], true);
            if (is_array($decoded)) {
                $routePoints = $decoded;
            }
        }

        return [
            'id' => $o['id'],
            'orderNumber' => $o['order_number'],
            'status' => $o['status'],
            'statusText' => Taxi::STATUS_TEXT[$o['status']] ?? $o['status'],
            'source' => $o['source'],
            'clientId' => $o['client_id'],
            'clientName' => $clientName,
            'clientPhone' => $clientPhone,
            'operatorId' => $o['operator_id'],
            'pickupAddress' => $o['pickup_address'],
            'pickupLatitude' => (float) $o['pickup_latitude'],
            'pickupLongitude' => (float) $o['pickup_longitude'],
            'pickupEntrance' => $o['pickup_entrance'],
            'destinationAddress' => $o['destination_address'],
            'destinationLatitude' => $o['destination_latitude'] !== null ? (float) $o['destination_latitude'] : null,
            'destinationLongitude' => $o['destination_longitude'] !== null ? (float) $o['destination_longitude'] : null,
            'tariff' => $o['tariff'],
            'tariffName' => Taxi::TARIFF_NAMES[$o['tariff']] ?? $o['tariff'],
            'estimatedPrice' => (float) $o['estimated_price'],
            'finalPrice' => $o['final_price'] !== null ? (float) $o['final_price'] : null,
            'estimatedDistance' => $o['estimated_distance'] !== null ? (float) $o['estimated_distance'] : null,
            'estimatedDuration' => $o['estimated_duration'] !== null ? (int) $o['estimated_duration'] : null,
            'routePoints' => $routePoints,
            'paymentMethod' => $o['payment_method'],
            'paymentMethodName' => Taxi::PAYMENT_NAMES[$o['payment_method']] ?? $o['payment_method'],
            'comment' => $o['comment'],
            'cancellationReason' => $o['cancellation_reason'],
            'passengerCount' => (int) $o['passenger_count'],
            'clientRating' => $o['client_rating'] !== null ? (int) $o['client_rating'] : null,
            'escalatedAt' => $o['escalated_at'],
            'createdAt' => $o['created_at'],
            'acceptedAt' => $o['accepted_at'],
            'driverArrivedAt' => $o['driver_arrived_at'],
            'tripStartedAt' => $o['trip_started_at'],
            'completedAt' => $o['completed_at'],
            'cancelledAt' => $o['cancelled_at'],
            'driver' => $driverInfo,
            'options' => $options,
        ];
    }

    public static function user(array $u, ?string $driverId = null): array
    {
        return [
            'id' => $u['id'],
            'phone' => $u['phone'],
            'firstName' => $u['first_name'],
            'lastName' => $u['last_name'],
            'name' => $u['first_name'] . ' ' . $u['last_name'],
            'email' => $u['email'],
            'role' => $u['role'],
            'rating' => (float) $u['rating'],
            'totalTrips' => (int) $u['total_trips'],
            'driverId' => $driverId,
        ];
    }
}
