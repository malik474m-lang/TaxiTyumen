<?php
// GET /api/telephony/logs.php?orderId=&limit= — журнал звонков (персонал)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

$claims = Guard::claims();
Guard::role($claims, 'operator', 'admin');
Telephony::ensureTables($db);

$limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$orderId = (string) ($_GET['orderId'] ?? '');
$sql = 'SELECT * FROM call_logs' . ($orderId !== '' ? ' WHERE order_id = ?' : '')
    . ' ORDER BY created_at DESC LIMIT ' . $limit;
$stmt = $db->prepare($sql);
$stmt->execute($orderId !== '' ? [$orderId] : []);

Response::json(array_map(fn(array $c) => [
    'id' => $c['id'],
    'scenario' => $c['scenario'],
    'direction' => $c['direction'],
    'externalId' => $c['external_id'],
    'fromNumber' => $c['from_number'],
    'toNumber' => $c['to_number'],
    'orderId' => $c['order_id'],
    'status' => $c['status'],
    'duration' => $c['duration'] !== null ? (int) $c['duration'] : null,
    'recordUrl' => $c['record_url'],
    'createdAt' => $c['created_at'],
    'updatedAt' => $c['updated_at'],
], $stmt->fetchAll()));
