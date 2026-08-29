<?php
// GET api/drivers/ — список водителей (для оператора/админки; ?online=1 — только на линии)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Simulate::advance($db);

$onlineOnly = ($_GET['online'] ?? '') === '1';
$sql = 'SELECT d.*, u.first_name, u.last_name, u.phone AS user_phone, u.rating AS user_rating
        FROM drivers d JOIN users u ON u.id = d.user_id'
    . ($onlineOnly ? " WHERE d.status != 'offline'" : '')
    . ' ORDER BY d.status';
$rows = $db->query($sql)->fetchAll();

Response::json(array_map(function (array $d) {
    return [
        'id' => $d['id'],
        'userId' => $d['user_id'],
        'name' => $d['first_name'] . ' ' . $d['last_name'],
        'phone' => $d['user_phone'],
        'rating' => (float) $d['user_rating'],
        'carBrand' => $d['car_brand'],
        'carModel' => $d['car_model'],
        'carColor' => $d['car_color'],
        'carDisplay' => $d['car_color'] . ' ' . $d['car_brand'] . ' ' . $d['car_model'],
        'licensePlate' => $d['license_plate'],
        'carYear' => (int) $d['car_year'],
        'status' => $d['status'],
        'statusText' => Taxi::DRIVER_STATUS_TEXT[$d['status']] ?? $d['status'],
        'isVerified' => (bool) $d['is_verified'],
        'latitude' => (float) $d['latitude'],
        'longitude' => (float) $d['longitude'],
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
}, $rows));
