<?php
// Сериализация сущностей в DTO (порт serializeOrder / serializeUser)
declare(strict_types=1);

require_once __DIR__ . '/Taxi.php';

final class Serialize
{
    public static function order(\PDO $db, array $o): array
    {
        $driverInfo = null;
        $driverRow = null;
        if (!empty($o['driver_id'])) {
            $stmt = $db->prepare(
                'SELECT d.*, u.first_name, u.last_name, u.phone AS user_phone, u.rating AS user_rating
                 FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ?'
            );
            $stmt->execute([$o['driver_id']]);
            if ($d = $stmt->fetch()) {
                $driverRow = $d;
                $driverInfo = [
                    'id' => $d['id'],
                    'driverId' => $d['id'],
                    'name' => $d['first_name'] . ' ' . $d['last_name'],
                    'fullName' => $d['first_name'] . ' ' . $d['last_name'],
                    'phone' => $d['user_phone'],
                    'carBrand' => $d['car_brand'],
                    'carModel' => $d['car_model'],
                    'carColor' => $d['car_color'],
                    'licensePlate' => $d['license_plate'],
                    'carDisplay' => $d['car_color'] . ' ' . $d['car_brand'] . ' ' . $d['car_model'],
                    'rating' => (float) ($d['rating'] ?? $d['user_rating']),
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
        $rpStmt = $db->prepare('SELECT id,address,latitude,longitude,sort_order FROM route_points WHERE order_id=? ORDER BY sort_order');
        $rpStmt->execute([$o['id']]);
        $intermediatePoints = array_map(fn(array $p) => [
            'id'=>$p['id'],'address'=>$p['address'],'latitude'=>(float)$p['latitude'],
            'longitude'=>(float)$p['longitude'],'sortOrder'=>(int)$p['sort_order'],
        ], $rpStmt->fetchAll());
        $txStmt = $db->prepare('SELECT * FROM transactions WHERE order_id=? LIMIT 1');
        $txStmt->execute([$o['id']]);
        $transaction = $txStmt->fetch();

        $statusMap = [
            'created'=>'Created','searching'=>'Searching','driver_assigned'=>'DriverAssigned',
            'driver_en_route'=>'DriverEnRoute','driver_arrived'=>'DriverArrived',
            'in_progress'=>'InProgress','completed'=>'Completed','cancelled'=>'Cancelled',
            'no_driver_found'=>'NoDriverFound',
        ];
        $compat = !empty($GLOBALS['order_compat_response']);
        $statusValue = $compat ? ($statusMap[$o['status']] ?? $o['status']) : $o['status'];
        $paymentMap = ['cash'=>'Cash','card'=>'Card','bonus'=>'BonusPoints'];
        $paymentValue = $compat ? ($paymentMap[$o['payment_method']] ?? $o['payment_method']) : $o['payment_method'];
        $paymentInfo = [
            'method' => $paymentValue,
            'paymentPhone' => $driverRow['payment_phone'] ?? null,
            'bankName' => $driverRow['payment_bank_name'] ?? null,
            'cardHolder' => $driverRow['payment_card_holder'] ?? null,
            'acceptSbp' => (bool) ($driverRow['accept_sbp'] ?? false),
            'sbpLink' => !empty($driverRow['payment_phone'])
                ? 'https://qr.nspk.ru/AS1A0000000000000000000000000000?type=02&bank=100000000111&sum=' . round((float) ($o['final_price'] ?? $o['estimated_price']) * 100)
                : null,
            'amount' => (float) ($o['final_price'] ?? $o['estimated_price']),
        ];

        return [
            'id' => $o['id'],
            'orderNumber' => $o['order_number'],
            'status' => $statusValue,
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
            'actualDistance' => isset($o['actual_distance']) && $o['actual_distance'] !== null ? (float) $o['actual_distance'] : null,
            'routePoints' => $routePoints,
            'intermediatePoints' => $intermediatePoints,
            'paymentMethod' => $paymentValue,
            'paymentMethodName' => Taxi::PAYMENT_NAMES[$o['payment_method']] ?? $o['payment_method'],
            'payment' => $paymentInfo,
            'comment' => $o['comment'],
            'cancellationReason' => $o['cancellation_reason'],
            'passengerCount' => (int) $o['passenger_count'],
            'clientRating' => $o['client_rating'] !== null ? (int) $o['client_rating'] : null,
            'driverRating' => $o['driver_rating'] !== null ? (int) $o['driver_rating'] : null,
            'clientReview' => $o['client_review'] ?? null,
            'driverReview' => $o['driver_review'] ?? null,
            'cancelledByUserId' => $o['cancelled_by_user_id'] ?? null,
            'transaction' => $transaction ? [
                'id'=>$transaction['id'],'amount'=>(float)$transaction['amount'],
                'method'=>$transaction['method'],'status'=>$transaction['status'],
                'externalTransactionId'=>$transaction['external_transaction_id'],
                'failureReason'=>$transaction['failure_reason'],
                'createdAt'=>$transaction['created_at'],'completedAt'=>$transaction['completed_at'],
            ] : null,
            'escalatedAt' => $o['escalated_at'],
            'createdAt' => $o['created_at'],
            'acceptedAt' => $o['accepted_at'],
            'driverArrivedAt' => $o['driver_arrived_at'],
            'tripStartedAt' => $o['trip_started_at'],
            'completedAt' => $o['completed_at'],
            'cancelledAt' => $o['cancelled_at'],
            'driver' => $driverInfo,
            'options' => $options,
            // Поля OrderListItem исходного клиента
            'driverInfo' => $driverInfo
                ? $driverInfo['name'] . ' · ' . $driverInfo['carDisplay'] . ' · ' . $driverInfo['licensePlate']
                : null,
            'timeAgo' => self::timeAgo((string) $o['created_at']),
        ];
    }

    private static function timeAgo(string $date): string
    {
        $seconds = max(0, time() - strtotime($date . ' UTC'));
        if ($seconds < 60) return 'только что';
        if ($seconds < 3600) return floor($seconds / 60) . ' мин. назад';
        if ($seconds < 86400) return floor($seconds / 3600) . ' ч. назад';
        return floor($seconds / 86400) . ' дн. назад';
    }

    public static function auth(array $u, ?string $driverId, string $token): array
    {
        return [
            'userId' => $u['id'],
            'token' => $token,
            'refreshToken' => $token,
            'tokenExpiry' => gmdate('c', time() + 86400),
            'firstName' => $u['first_name'],
            'lastName' => $u['last_name'],
            'phone' => $u['phone'],
            'role' => ucfirst($u['role']),
            'driverId' => $driverId,
        ];
    }

    public static function driver(array $d): array
    {
        $statusMap = ['offline'=>'Offline','available'=>'Available','on_route'=>'OnRoute','in_trip'=>'InTrip','busy'=>'Busy'];
        $status = !empty($GLOBALS['driver_compat_response'])
            ? ($statusMap[$d['status']] ?? $d['status'])
            : $d['status'];
        return [
            'id' => $d['id'],
            'driverId' => $d['id'],
            'userId' => $d['user_id'],
            'name' => $d['first_name'] . ' ' . $d['last_name'],
            'fullName' => $d['first_name'] . ' ' . $d['last_name'],
            'phone' => $d['user_phone'] ?? $d['phone'] ?? null,
            'rating' => (float) ($d['rating'] ?? $d['user_rating'] ?? 5),
            'carBrand' => $d['car_brand'],
            'carModel' => $d['car_model'],
            'carColor' => $d['car_color'],
            'carDisplay' => $d['car_color'] . ' ' . $d['car_brand'] . ' ' . $d['car_model'],
            'licensePlate' => $d['license_plate'],
            'carYear' => (int) $d['car_year'],
            'status' => $status,
            'statusText' => Taxi::DRIVER_STATUS_TEXT[$d['status']] ?? $d['status'],
            'isVerified' => (bool) $d['is_verified'],
            'latitude' => (float) $d['latitude'],
            'longitude' => (float) $d['longitude'],
            'speed' => isset($d['speed']) ? (float) $d['speed'] : null,
            'bearing' => isset($d['bearing']) ? (float) $d['bearing'] : null,
            'licenseExpiry' => $d['license_expiry'] ?? null,
            'verifiedAt' => $d['verified_at'] ?? null,
            'balance' => (float) $d['balance'],
            'minBalanceForOrders' => (float) $d['min_balance_for_orders'],
            'rejectionPenalty' => (float) $d['rejection_penalty'],
            'paymentPhone' => $d['payment_phone'] ?? null,
            'paymentBankName' => $d['payment_bank_name'] ?? null,
            'paymentCardHolder' => $d['payment_card_holder'] ?? null,
            'acceptCardTransfer' => (bool) ($d['accept_card_transfer'] ?? true),
            'acceptSbp' => (bool) ($d['accept_sbp'] ?? true),
            'completedTrips' => (int) $d['completed_trips'],
            'cancelledTrips' => (int) $d['cancelled_trips'],
            'totalEarnings' => (float) $d['total_earnings'],
            'todayEarnings' => (float) $d['today_earnings'],
            'currentOrderId' => $d['current_order_id'],
            'lastLocationUpdate' => $d['last_location_update'],
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
