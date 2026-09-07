<?php
// Автоматический старт платного простоя.
// Как только после «Я на месте» истекает бесплатное ожидание тарифа,
// счётчик включается сам — водителю не нужно нажимать кнопку.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Bus.php';

final class WaitingTimer
{
    /**
     * Включает простой для заказов, где бесплатное ожидание уже вышло.
     *
     * Модель тарификации:
     *  - при автостарте отсчёт начинается ровно в момент окончания бесплатных минут,
     *    поэтому waiting_seconds содержит ТОЛЬКО платное время;
     *  - флаг waiting_auto_started=1 говорит биллингу не вычитать бесплатные минуты
     *    повторно (иначе клиент получил бы их дважды).
     */
    public static function tick(\PDO $db): int
    {
        try {
            $rows = $db->query(
                "SELECT o.id, o.driver_arrived_at, o.tariff,
                        COALESCE(t.free_waiting_minutes, 0) AS free_minutes
                 FROM orders o
                 LEFT JOIN tariffs t ON t.type = o.tariff
                 WHERE o.status = 'driver_arrived'
                   AND o.driver_arrived_at IS NOT NULL
                   AND o.waiting_started_at IS NULL
                   AND o.waiting_seconds = 0
                   AND o.waiting_auto_started = 0"
            )->fetchAll();
        } catch (\Throwable $e) {
            error_log('[WaitingTimer] select: ' . $e->getMessage());
            return 0;
        }

        $started = 0;
        foreach ($rows as $row) {
            $arrivedTs = strtotime((string) $row['driver_arrived_at'] . ' UTC');
            if ($arrivedTs === false) {
                continue;
            }
            $freeSeconds = (int) round(((float) $row['free_minutes']) * 60);
            $freeEndsTs = $arrivedTs + $freeSeconds;

            // Бесплатное время ещё не закончилось — ждём следующего тика.
            if (time() < $freeEndsTs) {
                continue;
            }

            try {
                // Точка отсчёта — момент окончания бесплатного ожидания,
                // а не время срабатывания тика: клиент не платит за задержку опроса.
                $stmt = $db->prepare(
                    "UPDATE orders
                     SET waiting_started_at = ?, waiting_auto_started = 1
                     WHERE id = ?
                       AND status = 'driver_arrived'
                       AND waiting_started_at IS NULL
                       AND waiting_auto_started = 0"
                );
                $stmt->execute([gmdate('Y-m-d H:i:s', $freeEndsTs), $row['id']]);
                if ($stmt->rowCount() > 0) {
                    $started++;
                }
            } catch (\Throwable $e) {
                error_log('[WaitingTimer] update: ' . $e->getMessage());
            }
        }

        if ($started > 0) {
            Bus::publish('orders');
        }
        return $started;
    }

    /**
     * Секунды платного простоя для расчёта стоимости.
     * При автостарте бесплатные минуты уже исключены из накопления.
     */
    public static function billableSeconds(array $order, int $freeMinutes): int
    {
        $total = (int) ($order['waiting_seconds'] ?? 0);
        if (!empty($order['waiting_started_at'])) {
            $startedTs = strtotime((string) $order['waiting_started_at'] . ' UTC');
            if ($startedTs !== false) {
                $total += max(0, time() - $startedTs);
            }
        }

        if (!empty($order['waiting_auto_started'])) {
            return max(0, $total);
        }
        return max(0, $total - $freeMinutes * 60);
    }

    /** Полное накопленное время простоя (для истории и чека). */
    public static function totalSeconds(array $order): int
    {
        $total = (int) ($order['waiting_seconds'] ?? 0);
        if (!empty($order['waiting_started_at'])) {
            $startedTs = strtotime((string) $order['waiting_started_at'] . ' UTC');
            if ($startedTs !== false) {
                $total += max(0, time() - $startedTs);
            }
        }
        return max(0, $total);
    }
}
