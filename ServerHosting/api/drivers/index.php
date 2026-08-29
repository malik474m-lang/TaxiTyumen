<?php
// GET /api/drivers/ — список водителей (?online=1 — только на линии)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Simulate::advance($db);
DriverTimeout::tick($db);

$onlineOnly = ($_GET['online'] ?? '') === '1';
// Архивные водители не участвуют в работе сервиса
$sql = 'SELECT d.*,u.first_name,u.last_name,u.phone AS user_phone,u.rating AS user_rating
        FROM drivers d JOIN users u ON u.id=d.user_id
        WHERE u.is_archived = 0'
    . ($onlineOnly ? " AND d.status<>'offline'" : '')
    . ' ORDER BY d.status';
$rows = $db->query($sql)->fetchAll();
Response::json(array_map(fn(array $d) => Serialize::driver($d), $rows));
