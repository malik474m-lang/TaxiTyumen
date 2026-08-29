<?php
// GET /api/services.php — диагностика серверных API/провайдеров (только admin)
// ?check=all|osrm|sms|zvonok — внешний probe по запросу, секреты не возвращаются.
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$claims = Guard::claims();
Guard::role($claims, 'admin');

$check = (string) ($_GET['check'] ?? 'all');
$result = [
    'server' => [
        'ok' => true,
        'php' => PHP_VERSION,
        'time' => gmdate('c'),
        'uploadMaxFilesize' => ini_get('upload_max_filesize'),
        'postMaxSize' => ini_get('post_max_size'),
        'allowUrlFopen' => (bool) ini_get('allow_url_fopen'),
    ],
    'mysql' => ['ok' => false],
    'storage' => ['ok' => false],
    'realtime' => ['ok' => false],
    'sms' => ['configured' => SMS_API_ID !== '', 'ok' => null],
    'geocoding' => ['configured' => DADATA_API_KEY !== '', 'ok' => null],
    'zvonok' => ['configured' => false, 'ok' => null],
    'telephony' => ['configured' => false, 'ok' => null],
    'osrm' => ['configured' => true, 'ok' => null],
];

$started = microtime(true);
try {
    $version = $db->query('SELECT VERSION()')->fetchColumn();
    $result['mysql'] = [
        'ok' => true,
        'version' => $version,
        'database' => TAXI_DB_NAME,
        'durationMs' => (int) round((microtime(true) - $started) * 1000),
    ];
} catch (Throwable $e) {
    $result['mysql'] = ['ok' => false, 'message' => $e->getMessage()];
}

$storage = BrandingLogo::storageDir();
if (!is_dir($storage)) @mkdir($storage, 0755, true);
$result['storage'] = [
    'ok' => is_dir($storage) && is_writable($storage),
    'path' => 'uploads/branding',
    'writable' => is_writable($storage),
];

try {
    Bus::publish('service_check');
    $last = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM events')->fetchColumn();
    $result['realtime'] = ['ok' => $last > 0, 'lastEventId' => $last, 'transport' => 'MySQL polling'];
} catch (Throwable $e) {
    $result['realtime'] = ['ok' => false, 'message' => $e->getMessage()];
}

$tel = Telephony::settings($db);
$result['telephony'] = [
    'configured' => Telephony::isConfigured($tel),
    'ok' => null,
    'provider' => $tel['provider'],
    'cachedBalance' => $tel['balance'] !== null ? (float) $tel['balance'] : null,
    'balanceCheckedAt' => $tel['balance_checked_at'],
];
if ($check === 'all' || $check === 'telephony') {
    if (Telephony::isConfigured($tel)) {
        $result['telephony'] = array_merge($result['telephony'], Telephony::checkBalance($db));
    } else {
        $result['telephony']['message'] = 'Телефония выключена или не настроена';
        $result['telephony']['ok'] = false;
    }
}

$settings = AutoCall::getSettings($db);
$result['zvonok']['configured'] = !empty($settings['zvonok_api_key']) && !empty($settings['zvonok_campaign_id']);
$result['zvonok']['provider'] = $settings['provider'] ?? 'signalr';
$result['zvonok']['cachedBalance'] = (float) ($settings['zvonok_balance'] ?? 0);
$result['zvonok']['balanceCheckedAt'] = $settings['balance_checked_at'] ?? null;

if ($check === 'all' || $check === 'osrm') {
    $s = microtime(true);
    $url = 'https://router.project-osrm.org/route/v1/driving/65.5271,57.1459;65.5825,57.1378?overview=false';
    $ctx = stream_context_create(['http' => ['timeout' => 7, 'ignore_errors' => true, 'header' => "User-Agent: TaxiTyumen/1.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    $json = $raw !== false ? json_decode($raw, true) : null;
    $ok = is_array($json) && !empty($json['routes'][0]);
    $result['osrm'] = [
        'configured' => true,
        'ok' => $ok,
        'message' => $ok ? 'Маршрутизация по дорогам доступна' : 'OSRM недоступен — используется Haversine × 1.3',
        'durationMs' => (int) round((microtime(true) - $s) * 1000),
    ];
}
if (($check === 'all' || $check === 'sms') && SMS_API_ID !== '') {
    $result['sms'] = SmsService::check($db);
}
if ($check === 'all' || $check === 'geocoding') {
    $result['geocoding'] = GeocodingService::check($db);
}
if (($check === 'all' || $check === 'zvonok') && $result['zvonok']['configured']) {
    $result['zvonok'] = array_merge($result['zvonok'], ZvonokService::checkBalance($db));
}

Response::json($result);
