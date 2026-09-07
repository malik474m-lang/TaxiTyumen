<?php
// GET api/clients.php?phone=+79... — поиск клиента для автоподстановки в пульте оператора.
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$claims = Guard::claims();
Guard::role($claims, 'operator', 'admin');

$phone = (string) ($_GET['phone'] ?? '');
if ($phone === '') {
    Response::error('Укажите телефон');
}

$client = ClientDirectory::lookup($db, $phone);
Response::json($client ?? ['found' => false]);
