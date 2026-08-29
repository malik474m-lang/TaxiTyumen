<?php
// GET admin/export.php — CSV-выгрузка заказов по cookie-сессии панели (не требует Bearer)
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

admin_require($db);

$rows = $db->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 1000')->fetchAll();

$escape = fn($v): string => '"' . str_replace('"', '""', $v === null ? '' : (string) $v) . '"';
$fmt = fn(?string $d): string => $d ? date('d.m.Y, H:i', strtotime($d . ' UTC')) : '';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="taxi-tyumen-orders-' . gmdate('Y-m-d') . '.csv"');
echo "\xEF\xBB\xBF";

echo implode(';', array_map($escape, ['Номер заказа', 'Дата создания', 'Статус', 'Откуда', 'Куда', 'Тариф', 'Оценка, руб', 'Итог, руб', 'Оплата', 'Клиент', 'Телефон', 'Источник'])) . "\r\n";

foreach ($rows as $o) {
    echo implode(';', array_map($escape, [
        $o['order_number'],
        $fmt($o['created_at']),
        Taxi::STATUS_TEXT[$o['status']] ?? $o['status'],
        $o['pickup_address'],
        $o['destination_address'] ?? '',
        Taxi::TARIFF_NAMES[$o['tariff']] ?? $o['tariff'],
        (int) round((float) $o['estimated_price']),
        $o['final_price'] !== null ? (int) round((float) $o['final_price']) : '',
        Taxi::PAYMENT_NAMES[$o['payment_method']] ?? $o['payment_method'],
        $o['client_name'] ?? '',
        $o['client_phone'] ?? '',
        $o['source'] === 'operator_app' ? 'Диспетчерская' : 'Приложение',
    ])) . "\r\n";
}
exit;
