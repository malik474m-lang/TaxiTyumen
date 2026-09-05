<?php
// Персистентный порт INotificationService + ручные сообщения из админки.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Bus.php';
require_once __DIR__ . '/SmsService.php';
require_once __DIR__ . '/Taxi.php';

final class NotificationService
{
    public static function create(
        \PDO $db,
        string $recipientId,
        string $type,
        string $title,
        string $message,
        ?string $orderId = null,
        array $payload = [],
        string $channel = 'in_app',
        ?string $createdBy = null
    ): array {
        $delivery = 'sent';
        $providerResponse = null;

        if ($channel === 'sms') {
            $stmt = $db->prepare('SELECT phone FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$recipientId]);
            $phone = $stmt->fetchColumn();
            if ($phone) {
                $result = SmsService::send($db, (string) $phone, $message);
                $delivery = $result['status'];
                $providerResponse = $result['response'] ?? null;
            } else {
                $delivery = 'failed';
                $providerResponse = 'У пользователя нет телефона';
            }
        }

        $id = Db::uuid();
        $roleStmt = $db->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        $roleStmt->execute([$recipientId]);
        $role = $roleStmt->fetchColumn() ?: null;

        $db->prepare(
            'INSERT INTO notifications
             (id, recipient_id, recipient_role, order_id, type, title, message, payload,
              channel, delivery_status, provider_response, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id, $recipientId, $role, $orderId, $type,
            mb_substr($title, 0, 160), mb_substr($message, 0, 1000),
            $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $channel, $delivery, $providerResponse, $createdBy,
        ]);
        Bus::publish('notifications');
        return ['id' => $id, 'deliveryStatus' => $delivery];
    }

    public static function sendToRole(
        \PDO $db,
        string $role,
        string $type,
        string $title,
        string $message,
        ?string $orderId = null,
        array $payload = [],
        string $channel = 'in_app',
        ?string $createdBy = null
    ): int {
        $stmt = $db->prepare("SELECT id FROM users WHERE role=? AND is_active=1 AND is_blocked=0 AND is_archived=0");
        $stmt->execute([$role]);
        $count = 0;
        foreach ($stmt->fetchAll() as $user) {
            self::create($db, $user['id'], $type, $title, $message, $orderId, $payload, $channel, $createdBy);
            $count++;
        }
        return $count;
    }

    public static function sendBroadcast(
        \PDO $db,
        string $type,
        string $title,
        string $message,
        string $channel = 'in_app',
        ?string $createdBy = null
    ): int {
        $users = $db->query('SELECT id FROM users WHERE is_active=1 AND is_blocked=0 AND is_archived=0')->fetchAll();
        $count = 0;
        foreach ($users as $user) {
            self::create($db, $user['id'], $type, $title, $message, null, [], $channel, $createdBy);
            $count++;
        }
        return $count;
    }

    public static function notifyNearbyDriversNewOrder(\PDO $db, array $order, float $radiusKm = 10): int
    {
        $drivers = $db->query(
            "SELECT d.id,d.user_id,d.latitude,d.longitude FROM drivers d
             JOIN users u ON u.id=d.user_id
             WHERE d.status='available' AND d.is_verified=1
               AND d.balance>=d.min_balance_for_orders
               AND u.is_active=1 AND u.is_blocked=0 AND u.is_archived=0"
        )->fetchAll();
        $items = [];
        foreach ($drivers as $driver) {
            $distance = Taxi::getDistanceKm(
                (float) $order['pickup_latitude'], (float) $order['pickup_longitude'],
                (float) $driver['latitude'], (float) $driver['longitude']
            );
            if ($distance <= $radiusKm) {
                $items[] = ['driver' => $driver, 'distance' => $distance];
            }
        }
        usort($items, fn($a, $b) => $a['distance'] <=> $b['distance']);
        $count = 0;
        foreach (array_slice($items, 0, 3) as $item) {
            self::create(
                $db, $item['driver']['user_id'], 'NewOrderAvailable',
                'Новый заказ рядом',
                sprintf('%s → %s · %.0f ₽', $order['pickup_address'], $order['destination_address'] ?: 'адрес уточнит клиент', $order['estimated_price']),
                $order['id'],
                self::orderPayload($order)
            );
            $count++;
        }
        return $count;
    }

    public static function notifyClientOrderAccepted(\PDO $db, array $order): void
    {
        if (!$order['client_id']) return;
        $driver = self::driverPayload($db, $order['driver_id']);
        // Расчётное время подачи по расстоянию водитель → точка подачи
        $eta = null;
        if ($driver && isset($driver['latitude'], $driver['longitude'])) {
            $km = Taxi::getDistanceKm(
                (float) $driver['latitude'], (float) $driver['longitude'],
                (float) $order['pickup_latitude'], (float) $order['pickup_longitude']
            );
            $eta = max(2, (int) ceil($km / 0.4)); // ~24 км/ч по городу
        }

        $text = $driver
            ? sprintf(
                '%s %s, номер %s%s',
                $driver['carColor'], $driver['carModel'], $driver['licensePlate'],
                $eta ? sprintf('. Подача ~%d мин', $eta) : ''
            )
            : 'Водитель принял ваш заказ';

        self::create(
            $db, $order['client_id'], 'OrderStatusChanged', 'Водитель назначен',
            $text,
            $order['id'], ['status' => 'DriverAssigned', 'driver' => $driver, 'etaMinutes' => $eta]
        );

        if ($driver) {
            self::smsToClient(
                $db, $order,
                sprintf(
                    'Заказ принят. %s %s, гос. номер %s%s. Водитель: %s.',
                    $driver['carColor'], $driver['carModel'], $driver['licensePlate'],
                    $eta ? sprintf(', подача ~%d мин', $eta) : '',
                    $driver['name']
                ),
                'sms_on_assigned'
            );
        }
    }

    public static function notifyClientDriverArrived(\PDO $db, array $order): void
    {
        if (!$order['client_id']) return;
        $driver = self::driverPayload($db, $order['driver_id']);
        $freeMin = self::freeWaitMinutes($db, $order);
        $text = $driver
            ? sprintf(
                'Вас ожидает автомобиль: %s %s %s, гос. номер %s. Бесплатное ожидание %d мин.',
                $driver['carColor'], $driver['carBrand'] ?? '', $driver['carModel'],
                $driver['licensePlate'], $freeMin
            )
            : 'Водитель ожидает вас в точке подачи.';
        $text = preg_replace('/\s+/u', ' ', $text);

        self::create(
            $db, $order['client_id'], 'DriverArrivedNotification', 'Ваше такси прибыло',
            $text,
            $order['id'], ['status' => 'DriverArrived', 'driver' => $driver, 'freeWaitingMinutes' => $freeMin]
        );

        if ($driver) {
            self::smsToClient($db, $order, $text, 'sms_on_arrived');
        }
    }

    public static function notifyClientTripCompleted(\PDO $db, array $order): void
    {
        if (!$order['client_id']) return;
        self::create(
            $db, $order['client_id'], 'OrderStatusChanged', 'Поездка завершена',
            sprintf('Итоговая стоимость: %.0f ₽. Спасибо за поездку!', $order['final_price'] ?? $order['estimated_price']),
            $order['id'], ['status' => 'Completed', 'finalPrice' => (float) ($order['final_price'] ?? $order['estimated_price'])]
        );
    }

    public static function notifyClientOrderCancelled(\PDO $db, array $order, ?string $reason): void
    {
        if (!$order['client_id']) return;
        self::create(
            $db, $order['client_id'], 'OrderStatusChanged', 'Заказ отменён',
            $reason ? 'Причина: ' . $reason : 'Заказ был отменён.',
            $order['id'], ['status' => 'Cancelled', 'reason' => $reason]
        );
    }

    public static function notifyDriverForceAssigned(\PDO $db, string $driverId, array $order): void
    {
        $stmt = $db->prepare('SELECT user_id FROM drivers WHERE id=? LIMIT 1');
        $stmt->execute([$driverId]);
        if ($userId = $stmt->fetchColumn()) {
            self::create(
                $db, (string) $userId, 'ForceAssignedOrder', 'Вам назначен заказ',
                sprintf('%s → %s · %.0f ₽', $order['pickup_address'], $order['destination_address'] ?: 'адрес уточнит клиент', $order['estimated_price']),
                $order['id'], self::orderPayload($order)
            );
        }
    }

    public static function notifyOperatorsOrderUpdate(\PDO $db, array $order): void
    {
        self::sendToRole(
            $db, 'operator', 'OrderUpdated',
            'Заказ ' . $order['order_number'],
            (Taxi::STATUS_TEXT[$order['status']] ?? $order['status']) . ': ' . $order['pickup_address'],
            $order['id'], self::orderPayload($order)
        );
    }

    public static function notifyOperatorsDriverRejected(\PDO $db, array $order, string $driverName, ?string $reason): void
    {
        self::sendToRole(
            $db, 'operator', 'DriverRejectedOrder', 'Водитель отказался от заказа',
            sprintf('%s · %s · %s', $order['order_number'], $driverName, $reason ?: 'Без причины'),
            $order['id'], ['reason' => $reason, 'driverName' => $driverName]
        );
    }

    private static function orderPayload(array $order): array
    {
        return [
            'orderId' => $order['id'],
            'orderNumber' => $order['order_number'],
            'pickupAddress' => $order['pickup_address'],
            'pickupEntrance' => $order['pickup_entrance'] ?? null,
            'destinationAddress' => $order['destination_address'],
            'estimatedPrice' => (float) $order['estimated_price'],
            'tariff' => ucfirst($order['tariff']),
            'comment' => $order['comment'] ?? null,
            'passengerCount' => (int) ($order['passenger_count'] ?? 1),
            'createdAt' => $order['created_at'],
            'status' => $order['status'],
        ];
    }

    /** Телефон пассажира: из профиля клиента либо из операторского заказа. */
    private static function clientPhone(\PDO $db, array $order): ?string
    {
        $phone = trim((string) ($order['client_phone'] ?? ''));
        if ($phone !== '') return $phone;
        if (empty($order['client_id'])) return null;
        $stmt = $db->prepare('SELECT phone FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$order['client_id']]);
        $found = $stmt->fetchColumn();
        return $found ? (string) $found : null;
    }

    /**
     * SMS пассажиру о статусе заказа. Управляется настройками сервиса
     * (sms_on_assigned / sms_on_arrived) — админ включает нужные события.
     */
    private static function smsToClient(\PDO $db, array $order, string $text, string $settingKey): void
    {
        try {
            $settings = ServiceSettings::get($db);
            if ((int) ($settings[$settingKey] ?? 0) !== 1) return;
            $phone = self::clientPhone($db, $order);
            if (!$phone) return;
            SmsService::send($db, $phone, $text);
        } catch (\Throwable) {
            // SMS не должна ломать основной сценарий заказа
        }
    }

    /** Свободное ожидание по тарифу заказа (минуты). */
    private static function freeWaitMinutes(\PDO $db, array $order): int
    {
        try {
            $stmt = $db->prepare('SELECT free_waiting_minutes FROM tariffs WHERE type = ? LIMIT 1');
            $stmt->execute([$order['tariff'] ?? 'economy']);
            $v = $stmt->fetchColumn();
            return $v === false ? 3 : (int) round((float) $v);
        } catch (\Throwable) {
            return 3;
        }
    }

    private static function driverPayload(\PDO $db, ?string $driverId): ?array
    {
        if (!$driverId) return null;
        $stmt = $db->prepare(
            'SELECT d.*,u.first_name,u.last_name,u.phone,u.rating FROM drivers d
             JOIN users u ON u.id=d.user_id WHERE d.id=? LIMIT 1'
        );
        $stmt->execute([$driverId]);
        $d = $stmt->fetch();
        if (!$d) return null;
        return [
            'driverId' => $d['id'],
            'name' => $d['first_name'] . ' ' . $d['last_name'],
            'phone' => $d['phone'],
            'carBrand' => $d['car_brand'],
            'carModel' => $d['car_model'],
            'carColor' => $d['car_color'],
            'licensePlate' => $d['license_plate'],
            'rating' => (float) $d['rating'],
            'latitude' => (float) $d['latitude'],
            'longitude' => (float) $d['longitude'],
        ];
    }
}
