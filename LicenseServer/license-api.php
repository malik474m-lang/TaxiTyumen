<?php
// Публичный API сервера лицензий: проверка, активация.
// Сервер такси вызывает эти эндпоинты раз в сутки.
require_once __DIR__ . '/license-core.php';

lic_ensure_tables();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower((string) ($_GET['action'] ?? ''));
$body = $method === 'POST' ? (json_decode(file_get_contents('php://input') ?: '', true) ?: []) : [];
$ip = BruteGuard::clientIp();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if (lic_api_rate_limited($ip)) {
    header('Retry-After: 60');
    lic_error('Слишком много запросов. Повторите через минуту.', 429);
}

// ── GET /license-api.php?action=check&key=XXXXX&domain=xxx ──────────────
// Проверка валидности лицензии. Вызывается сервером такси раз в сутки.
if ($action === 'check' || $action === '') {
    if ($method !== 'GET') lic_error('Метод GET', 405);
    $key = trim((string) ($_GET['key'] ?? ''));
    $domain = strtolower(trim((string) ($_GET['domain'] ?? '')));
    if ($key === '' || $domain === '') lic_error('key и domain обязательны');

    $stmt = lic_db()->prepare('SELECT * FROM licenses WHERE license_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $lic = $stmt->fetch();

    if (!$lic) {
        lic_log_event(null, 'check-failed', $ip, "key=$key domain=$domain not found");
        lic_json(['valid' => false, 'reason' => 'invalid_key',
            'message' => 'Лицензия с таким ключом не найдена'], 404);
    }

    $now = time();
    $expires = strtotime($lic['expires_at'] . ' UTC');

    // Обновляем статистику
    lic_db()->prepare(
        'UPDATE licenses SET last_check_at = NOW(), last_check_ip = ?,
         check_count = check_count + 1 WHERE id = ?'
    )->execute([$ip, $lic['id']]);

    if ($lic['status'] === 'revoked') {
        lic_log_event($lic['id'], 'check-revoked', $ip);
        lic_json(['valid' => false, 'reason' => 'revoked',
            'message' => 'Лицензия отозвана. Обратитесь к поставщику'], 403);
    }

    if ($lic['status'] === 'suspended') {
        lic_log_event($lic['id'], 'check-suspended', $ip);
        lic_json(['valid' => false, 'reason' => 'suspended',
            'message' => 'Лицензия приостановлена'], 403);
    }

    if ($expires !== false && $expires < $now) {
        lic_db()->prepare("UPDATE licenses SET status='expired',updated_at=NOW() WHERE id=?")
            ->execute([$lic['id']]);
        lic_log_event($lic['id'], 'check-expired', $ip);
        lic_json(['valid' => false, 'reason' => 'expired',
            'message' => 'Срок лицензии истёк ' . $lic['expires_at'],
            'expiredAt' => $lic['expires_at']], 403);
    }

    // Домен должен совпадать с доменом лицензии (защита от передачи ключа)
    $licDomain = strtolower($lic['domain']);
    if ($licDomain !== '' && $domain !== $licDomain) {
        lic_log_event($lic['id'], 'check-domain-mismatch', $ip, "expected=$licDomain got=$domain");
        lic_json(['valid' => false, 'reason' => 'domain_mismatch',
            'message' => 'Лицензия выдана для другого домена'], 403);
    }

    lic_log_event($lic['id'], 'check-ok', $ip);
    lic_json([
        'valid' => true,
        'plan' => $lic['plan'],
        'maxDrivers' => (int) $lic['max_drivers'],
        'expiresAt' => $lic['expires_at'],
        'customerName' => $lic['customer_name'],
        'nextCheckAfter' => 86400,
    ]);
}

// ── POST /license-api.php?action=activate ────────────────────────────────
// Первая активация: сервер такси привязывает ключ к своему домену.
if ($action === 'activate') {
    if ($method !== 'POST') lic_error('Метод POST', 405);
    $key = trim((string) ($body['key'] ?? ''));
    $domain = strtolower(trim((string) ($body['domain'] ?? '')));
    if ($key === '' || $domain === '') lic_error('key и domain обязательны');

    $stmt = lic_db()->prepare('SELECT * FROM licenses WHERE license_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $lic = $stmt->fetch();

    if (!$lic) {
        lic_log_event(null, 'activate-failed', $ip, "key=$key not found");
        lic_json(['valid' => false, 'reason' => 'invalid_key'], 404);
    }

    $now = time();
    $expires = strtotime($lic['expires_at'] . ' UTC');
    if ($expires !== false && $expires < $now) {
        lic_json(['valid' => false, 'reason' => 'expired',
            'message' => 'Срок лицензии истёк'], 403);
    }

    if ($lic['status'] !== 'active') {
        lic_json(['valid' => false, 'reason' => $lic['status']], 403);
    }

    // Первый домен, привязавший ключ, становится владельцем лицензии
    $licDomain = strtolower($lic['domain']);
    if ($licDomain === '') {
        lic_db()->prepare('UPDATE licenses SET domain = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$domain, $lic['id']]);
        $lic['domain'] = $domain;
        lic_log_event($lic['id'], 'activate-bound', $ip, "domain=$domain");
    } elseif ($licDomain !== $domain) {
        lic_log_event($lic['id'], 'activate-mismatch', $ip, "expected=$licDomain got=$domain");
        lic_json(['valid' => false, 'reason' => 'domain_mismatch',
            'message' => 'Ключ уже привязан к другому домену'], 403);
    }

    lic_log_event($lic['id'], 'activate-ok', $ip);
    lic_json([
        'valid' => true,
        'plan' => $lic['plan'],
        'maxDrivers' => (int) $lic['max_drivers'],
        'expiresAt' => $lic['expires_at'],
        'customerName' => $lic['customer_name'],
        'nextCheckAfter' => 86400,
    ]);
}

// ── GET /license-api.php?action=health ────────────────────────────────────
if ($action === 'health') {
    lic_json([
        'service' => 'TaxiTyumen License Server',
        'status' => 'online',
        'time' => gmdate('c'),
    ]);
}

lic_error('Неизвестное действие: ' . ($action ?: '—'), 404);
