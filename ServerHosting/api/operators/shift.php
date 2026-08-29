<?php
// Shift-учёт операторов: GET — текущая смена | POST {action: start|end}
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

$claims = Guard::claims();
Guard::role($claims, 'operator', 'admin');
$uid = (string) $claims['uid'];

$openShift = function () use ($db, $uid) {
    $stmt = $db->prepare(
        'SELECT * FROM operator_shifts WHERE operator_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1'
    );
    $stmt->execute([$uid]);
    return $stmt->fetch() ?: null;
};

$stats = function (string $since) use ($db, $uid) {
    $created = $db->prepare('SELECT COUNT(*) FROM orders WHERE operator_id = ? AND created_at >= ?');
    $created->execute([$uid, $since]);
    $completed = $db->prepare(
        "SELECT COUNT(*) FROM orders WHERE operator_id = ? AND created_at >= ? AND status = 'completed'"
    );
    $completed->execute([$uid, $since]);
    return [
        'ordersCreated' => (int) $created->fetchColumn(),
        'ordersCompleted' => (int) $completed->fetchColumn(),
    ];
};

$shiftDto = function (array $s): array {
    return [
        'id' => $s['id'],
        'operatorId' => $s['operator_id'],
        'startedAt' => $s['started_at'],
        'endedAt' => $s['ended_at'],
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $shift = $openShift();
    if (!$shift) {
        Response::json(['active' => false]);
    }
    Response::json(array_merge(['active' => true, 'shift' => $shiftDto($shift)], $stats($shift['started_at'])));
}

$body = Response::requirePostJson();
$action = (string) ($body['action'] ?? '');
$shift = $openShift();

if ($action === 'start') {
    if ($shift) {
        Response::json(array_merge(['active' => true, 'shift' => $shiftDto($shift), 'already' => true], $stats($shift['started_at'])));
    }
    $id = Db::uuid();
    $db->prepare('INSERT INTO operator_shifts (id, operator_id) VALUES (?,?)')->execute([$id, $uid]);
    Response::json([
        'active' => true,
        'shift' => ['id' => $id, 'operatorId' => $uid, 'startedAt' => Db::utcNow(), 'endedAt' => null],
        'ordersCreated' => 0,
        'ordersCompleted' => 0,
    ]);
}

if ($action === 'end') {
    if (!$shift) {
        Response::json(['active' => false]);
    }
    $db->prepare('UPDATE operator_shifts SET ended_at = ? WHERE id = ?')
        ->execute([Db::utcNow(), $shift['id']]);
    Response::json(array_merge(
        ['active' => false, 'startedAt' => $shift['started_at'], 'endedAt' => Db::utcNow()],
        $stats($shift['started_at'])
    ));
}

Response::error("Неизвестный action: $action");
