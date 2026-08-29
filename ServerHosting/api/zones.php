<?php
// GET  /api/zones.php — зоны, матрица цен и настройки (staff)
// PUT  — настройки зональной тарификации (admin)
// POST — create|update|delete зоны, set_price (admin)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$claims = Guard::claims();
Guard::role($claims, 'operator', 'admin');
Zones::ensureTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Проверка принадлежности точки зоне: ?lat=&lng=
    if (isset($_GET['lat'], $_GET['lng'])) {
        $zone = Zones::findZone($db, (float) $_GET['lat'], (float) $_GET['lng']);
        Response::json(['zone' => $zone ? [
            'id' => $zone['id'], 'name' => $zone['name'], 'color' => $zone['color'],
        ] : null]);
    }

    $zones = array_map(fn(array $z) => [
        'id' => $z['id'],
        'name' => $z['name'],
        'color' => $z['color'],
        'priority' => (int) $z['priority'],
        'isActive' => (bool) $z['is_active'],
        'points' => $z['points'],
    ], Zones::activeZones($db));

    $all = $db->query('SELECT * FROM zones ORDER BY priority DESC, name')->fetchAll();
    $settings = Zones::settings($db);

    Response::json([
        'enabled' => (bool) $settings['enabled'],
        'applyMultipliers' => (bool) $settings['apply_multipliers'],
        'addOptions' => (bool) $settings['add_options'],
        'fallbackToTariff' => (bool) $settings['fallback_to_tariff'],
        'activeZones' => $zones,
        'zones' => array_map(fn(array $z) => [
            'id' => $z['id'],
            'name' => $z['name'],
            'color' => $z['color'],
            'priority' => (int) $z['priority'],
            'isActive' => (bool) $z['is_active'],
            'points' => Zones::decodePolygon((string) $z['polygon']),
        ], $all),
        'prices' => Zones::priceMatrix($db),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    Guard::role($claims, 'admin');
    $body = Response::requirePostJson();
    $s = Zones::updateSettings($db, $body);
    Bus::publish('zones');
    Response::json([
        'enabled' => (bool) $s['enabled'],
        'applyMultipliers' => (bool) $s['apply_multipliers'],
        'addOptions' => (bool) $s['add_options'],
        'fallbackToTariff' => (bool) $s['fallback_to_tariff'],
    ]);
}

Response::requireMethod('POST');
Guard::role($claims, 'admin');
$body = Response::requirePostJson();
$action = (string) ($body['action'] ?? '');

try {
    if ($action === 'create' || $action === 'update') {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') Response::error('Укажите название зоны');
        $points = Zones::normalizePolygon($body['points'] ?? $body['polygon'] ?? []);
        $polygon = json_encode($points, JSON_UNESCAPED_SLASHES);
        $color = mb_substr((string) ($body['color'] ?? '#38bdf8'), 0, 9);
        $priority = (int) ($body['priority'] ?? 0);
        $isActive = array_key_exists('isActive', $body) ? ((bool) $body['isActive'] ? 1 : 0) : 1;

        if ($action === 'create') {
            $id = Db::uuid();
            $db->prepare(
                'INSERT INTO zones (id,name,color,polygon,priority,is_active) VALUES (?,?,?,?,?,?)'
            )->execute([$id, mb_substr($name, 0, 80), $color, $polygon, $priority, $isActive]);
        } else {
            $id = (string) ($body['id'] ?? '');
            $db->prepare(
                'UPDATE zones SET name=?,color=?,polygon=?,priority=?,is_active=?,updated_at=? WHERE id=?'
            )->execute([mb_substr($name, 0, 80), $color, $polygon, $priority, $isActive, Db::utcNow(), $id]);
        }
        Bus::publish('zones');
        Response::json(['ok' => true, 'id' => $id, 'points' => count($points)]);
    }

    if ($action === 'delete') {
        Zones::deleteZone($db, (string) ($body['id'] ?? ''));
        Bus::publish('zones');
        Response::json(['ok' => true]);
    }

    if ($action === 'set_price') {
        $price = $body['price'] === null || $body['price'] === '' ? null : (float) $body['price'];
        Zones::setPrice(
            $db,
            (string) ($body['fromZoneId'] ?? ''),
            (string) ($body['toZoneId'] ?? ''),
            Taxi::normalizeTariff($body['tariff'] ?? 'economy'),
            $price
        );
        Bus::publish('zones');
        Response::json(['ok' => true]);
    }
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}

Response::error("Неизвестный action: $action");
