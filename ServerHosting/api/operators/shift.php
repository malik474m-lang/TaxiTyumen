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
        'profileId' => $s['profile_id'] ?? null,
        'startedAt' => $s['started_at'],
        'endedAt' => $s['ended_at'],
        'hoursWorked' => (float) ($s['hours_worked'] ?? 0),
        'ordersAccepted' => (int) ($s['orders_accepted'] ?? 0),
        'earned' => (float) ($s['earned'] ?? 0),
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
    $p = $db->prepare('SELECT id FROM operator_profiles WHERE user_id=? LIMIT 1');
    $p->execute([$uid]);
    $profileId = $p->fetchColumn() ?: null;
    $now = Db::utcNow();
    $db->prepare('INSERT INTO operator_shifts (id,operator_id,profile_id,started_at) VALUES (?,?,?,?)')
        ->execute([$id, $uid, $profileId, $now]);
    Response::json([
        'active' => true,
        'shift' => ['id'=>$id,'operatorId'=>$uid,'profileId'=>$profileId,'startedAt'=>$now,'endedAt'=>null,'hoursWorked'=>0,'ordersAccepted'=>0,'earned'=>0],
        'ordersCreated' => 0,
        'ordersCompleted' => 0,
    ]);
}

if ($action === 'end') {
    if (!$shift) {
        Response::json(['active' => false]);
    }
    $endedAt = Db::utcNow();
    $hours = max(0, (strtotime($endedAt . ' UTC') - strtotime($shift['started_at'] . ' UTC')) / 3600);
    $shiftStats = $stats($shift['started_at']);
    $ordersAccepted = (int) $shiftStats['ordersCreated'];
    $p = $db->prepare('SELECT * FROM operator_profiles WHERE user_id=? LIMIT 1');
    $p->execute([$uid]);
    $profile = $p->fetch();
    $earned = 0.0;
    if ($profile) {
        $earned = match ($profile['scheme']) {
            'per_hour' => $hours * (float) $profile['rate_per_hour'],
            'per_day' => (float) $profile['rate_per_day'],
            'fixed_monthly' => (float) $profile['fixed_monthly'] / max(1, (int) date('t')),
            default => $ordersAccepted * (float) $profile['rate_per_order'],
        };
    }
    $db->beginTransaction();
    $db->prepare('UPDATE operator_shifts SET ended_at=?,hours_worked=?,orders_accepted=?,earned=? WHERE id=?')
        ->execute([$endedAt, $hours, $ordersAccepted, $earned, $shift['id']]);
    if ($profile) {
        $db->prepare('UPDATE operator_profiles SET total_orders_accepted=total_orders_accepted+?,total_earnings=total_earnings+? WHERE id=?')
            ->execute([$ordersAccepted, $earned, $profile['id']]);
    }
    $db->commit();
    Response::json(array_merge(
        ['active'=>false,'startedAt'=>$shift['started_at'],'endedAt'=>$endedAt,'hoursWorked'=>$hours,'ordersAccepted'=>$ordersAccepted,'earned'=>$earned],
        $shiftStats
    ));
}

Response::error("Неизвестный action: $action");
