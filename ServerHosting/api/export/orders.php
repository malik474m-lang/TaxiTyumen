<?php
// GET api/export/orders.php — выгрузка заказов в CSV (UTF-8 BOM + ;). Только админ.
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

$claims = Guard::claims();
Guard::role($claims, 'admin');

$rows = $db->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 1000')->fetchAll();

$escape = function ($v): string {
    $s = $v === null ? '' : (string) $v;
    return '"' . str_replace('"', '""', $s) . '"';
};

$fmt = function (?string $d): string {
    return $d ? date('d.m.Y, H:i', strtotime($d . ' UTC')) : '';
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="taxi-tyumen-orders-' . gmdate('Y-m-d') . '.csv"');

echo "\xEF\xBB\xBF"; // BOM для Excel

$header = ['Номер заказа', 'Дата создания', 'Статус', 'Откуда', 'Куда', 'Тариф', 'Оценка цены, руб',
    'Итог, руб', 'Оплата', 'Клиент', 'Телефон', 'Источник'];
echo implode(';', array_map($escape, $header)) . "\r\n";

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
