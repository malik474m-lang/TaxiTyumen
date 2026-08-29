<?php
// GET api/branding?app=client — публично | GET (все) — админ | PUT — админ
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $app = (string) ($_GET['app'] ?? '');
    if (in_array($app, Branding::APPS, true)) {
        $stmt = $db->prepare('SELECT * FROM branding_settings WHERE app = ? LIMIT 1');
        $stmt->execute([$app]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Бренд не найден', 404);
        }
        Response::json(Branding::toDto($row));
    }

    $claims = Guard::claims();
    Guard::role($claims, 'admin');
    $rows = $db->query('SELECT * FROM branding_settings')->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[$r['app']] = Branding::toDto($r);
    }
    Response::json(array_values(array_map(fn($a) => $map[$a] ?? null, Branding::APPS)));
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $claims = Guard::claims();
    Guard::role($claims, 'admin');

    $body = Response::requirePostJson();
    $app = (string) ($body['app'] ?? '');
    if (!in_array($app, Branding::APPS, true)) {
        Response::error('Неизвестное приложение');
    }

    $stmt = $db->prepare('SELECT * FROM branding_settings WHERE app = ? LIMIT 1');
    $stmt->execute([$app]);
    $existing = Branding::toDto($stmt->fetch() ?: throw new \RuntimeException('бренд не найден'));

    $features = $existing['features'];
    if (isset($body['features']) && is_array($body['features'])) {
        $features = array_values(array_slice(array_filter($body['features'], 'is_string'), 0, 5));
    }

    $db->prepare(
        'UPDATE branding_settings
         SET app_name = ?, app_code = ?, hero_title = ?, hero_subtitle = ?, logo_icon = ?,
             primary_color = ?, primary_text_color = ?, support_phone = ?, features = ?, updated_at = ?
         WHERE app = ?'
    )->execute([
        mb_substr((string) ($body['appName'] ?? $existing['appName']), 0, 60),
        mb_substr((string) ($body['appCode'] ?? $existing['appCode']), 0, 60),
        mb_substr((string) ($body['heroTitle'] ?? $existing['heroTitle']), 0, 120),
        mb_substr((string) ($body['heroSubtitle'] ?? $existing['heroSubtitle']), 0, 300),
        mb_substr((string) ($body['logoIcon'] ?? $existing['logoIcon']), 0, 40),
        mb_substr((string) ($body['primaryColor'] ?? $existing['primaryColor']), 0, 9),
        mb_substr((string) ($body['primaryTextColor'] ?? $existing['primaryTextColor']), 0, 9),
        array_key_exists('supportPhone', $body)
            ? ($body['supportPhone'] !== null ? mb_substr((string) $body['supportPhone'], 0, 30) : null)
            : $existing['supportPhone'],
        json_encode($features, JSON_UNESCAPED_UNICODE),
        Db::utcNow(),
        $app,
    ]);

    Bus::publish('branding');
    $stmt->execute([$app]);
    Response::json(Branding::toDto($stmt->fetch()));
}

Response::error('Метод не поддерживается', 405);
