<?php
// Админ-панель сервера лицензий: логин с 2FA, управление лицензиями.
require_once __DIR__ . '/license-core.php';

// ── Совместимость при частичном обновлении файлов ─────────────────────────
// На shared-хостинге новый license-admin.php мог быть скопирован поверх
// старого license-core.php. Тогда новые имена функций отсутствуют и PHP
// раньше падал с fatal error. Версия 286241e использовала старые имена —
// создаём безопасные алиасы прямо здесь.
if (!defined('LIC_DEBUG')) define('LIC_DEBUG', false);

if (!function_exists('lic_admin_totp_secret') && function_exists('lic_totp_secret')) {
    function lic_admin_totp_secret(): string
    {
        return lic_totp_secret();
    }
}
if (!function_exists('lic_admin_save_totp') && function_exists('lic_save_totp_secret')) {
    function lic_admin_save_totp(string $secret): void
    {
        lic_save_totp_secret($secret);
    }
}
if (!function_exists('lic_csrf_token')) {
    function lic_csrf_token(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
        return (string) $_SESSION['csrf'];
    }
}
if (!function_exists('lic_verify_csrf')) {
    function lic_verify_csrf(): bool
    {
        $provided = (string) ($_POST['_csrf'] ?? '');
        return $provided !== '' && hash_equals(lic_csrf_token(), $provided);
    }
}

// Если ядро ещё старше и безопасно совместить его невозможно — понятная
// диагностика вместо fatal error. Секреты и внутренние пути не раскрываем.
$missingCoreApi = [];
foreach ([
    'lic_ensure_tables', 'lic_db', 'lic_generate_key', 'lic_hash_key',
    'lic_admin_totp_secret', 'lic_admin_save_totp', 'lic_csrf_token',
    'lic_verify_csrf'
] as $requiredFunction) {
    if (!function_exists($requiredFunction)) $missingCoreApi[] = $requiredFunction;
}
$bruteApiOk = class_exists('BruteGuard')
    && method_exists('BruteGuard', 'failedCount');
if ($missingCoreApi || !$bruteApiOk) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8">'
       . '<title>Требуется обновление</title><body style="background:#0a0a0c;'
       . 'color:#f4f4f5;font:15px/1.6 system-ui;padding:30px">'
       . '<div style="max-width:720px;margin:auto;background:#121216;border:'
       . '1px solid #333;border-radius:16px;padding:24px">'
       . '<h1>Файлы сервера лицензий разных версий</h1>'
       . '<p style="color:#fca5a5">Обновите одним комплектом файлы '
       . '<b>license-core.php</b>, <b>license-admin.php</b> и '
       . '<b>license-api.php</b>.</p>'
       . '<p>Файл <b>license.local.php</b> и базу MySQL не удаляйте.</p>'
       . '<p style="color:#a1a1aa">После замены обновите страницу Ctrl+F5.</p>'
       . '</div></body></html>';
    exit;
}

// Понятная диагностика вместо пустого HTTP 500 при ошибке БД/конфига
try {
    lic_ensure_tables();
} catch (Throwable $e) {
    http_response_code(503);
    $msg = LIC_DEBUG ? $e->getMessage()
        : 'Не удалось подключиться к MySQL. Проверьте license.local.php.';
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Настройка сервера лицензий</title>'
       . '<body style="background:#0a0a0c;color:#f4f4f5;font:15px/1.6 system-ui;padding:30px">'
       . '<div style="max-width:700px;margin:auto;background:#121216;border:1px solid #333;border-radius:16px;padding:24px">'
       . '<h1>Сервер лицензий не настроен</h1><p style="color:#fca5a5">'
       . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p>Откройте <a style="color:#a5b4fc" href="install.php">install.php</a> и завершите настройку.</p>'
       . '</div></body></html>';
    exit;
}

// Без пароля админки отправляем в одноразовый установщик
if (LIC_ADMIN_PASS_HASH === '') {
    header('Location: install.php');
    exit;
}

session_name('licadmin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// Защитные заголовки дублируют .htaccess на случай отключённого mod_headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
    . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
    . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

// ── Выход ────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
            $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: license-admin.php');
    exit;
}

// ── Логин: пароль → отдельный шаг TOTP ──────────────────────────────────
$loginError = '';
$loggedIn = !empty($_SESSION['admin_logged_in']);
// Сессия старой версии без сохранённого TOTP не считается 2FA-входом.
if ($loggedIn && lic_admin_totp_secret() === '') {
    $_SESSION = [];
    $loggedIn = false;
}
// 30 минут бездействия → повторный пароль + 2FA
if ($loggedIn) {
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity === 0 || time() - $lastActivity > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
        $loggedIn = false;
        $loginError = 'Сессия истекла. Войдите снова.';
    } else {
        $_SESSION['last_activity'] = time();
    }
}
$totpRequired = !$loggedIn && !empty($_SESSION['2fa_pending'])
    && (int) ($_SESSION['2fa_expires'] ?? 0) >= time();
$pendingUser = (string) ($_SESSION['2fa_pending'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cmd'] ?? '') === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (BruteGuard::isLocked($username)) {
        $loginError = 'Слишком много неудачных попыток. Повторите через '
            . BruteGuard::remainingLockout($username) . ' мин.';
    } else {
        $valid = hash_equals((string) LIC_ADMIN_USER, $username)
            && LIC_ADMIN_PASS_HASH !== ''
            && password_verify($password, LIC_ADMIN_PASS_HASH);

        if (!$valid) {
            BruteGuard::record($username, false);
            lic_log_event(null, 'admin-login-failed', BruteGuard::clientIp(), "user=$username");
            $left = max(0, BruteGuard::MAX_ATTEMPTS - BruteGuard::failedCount($username));
            $loginError = "Неверный логин или пароль. Осталось попыток: $left";
        } else {
            // Пароль больше не передаём скрытым полем на втором шаге
            $_SESSION['2fa_pending'] = $username;
            $_SESSION['2fa_expires'] = time() + 300;
            if (lic_admin_totp_secret() === '' && empty($_SESSION['setup_totp_secret'])) {
                $_SESSION['setup_totp_secret'] = Totp::generateSecret();
            }
            $pendingUser = $username;
            $totpRequired = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cmd'] ?? '') === 'verify2fa') {
    $username = (string) ($_SESSION['2fa_pending'] ?? '');
    $expires = (int) ($_SESSION['2fa_expires'] ?? 0);
    $code = trim((string) ($_POST['totp'] ?? ''));

    if ($username === '' || $expires < time()) {
        unset($_SESSION['2fa_pending'], $_SESSION['2fa_expires'], $_SESSION['setup_totp_secret']);
        $loginError = 'Время подтверждения истекло. Введите пароль снова.';
        $totpRequired = false;
    } elseif (BruteGuard::isLocked($username)) {
        $loginError = 'Слишком много неудачных попыток. Повторите через '
            . BruteGuard::remainingLockout($username) . ' мин.';
        $totpRequired = true;
    } else {
        $savedSecret = lic_admin_totp_secret();
        $secret = $savedSecret !== '' ? $savedSecret
            : (string) ($_SESSION['setup_totp_secret'] ?? '');

        if ($secret !== '' && Totp::verify($secret, $code)) {
            // Первый успешный код завершает настройку и сохраняет TOTP в БД
            if ($savedSecret === '') lic_admin_save_totp($secret);
            BruteGuard::record($username, true);
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = $username;
            $_SESSION['last_activity'] = time();
            unset($_SESSION['2fa_pending'], $_SESSION['2fa_expires'], $_SESSION['setup_totp_secret']);
            lic_log_event(null, 'admin-login-ok', BruteGuard::clientIp(), "user=$username");
            header('Location: license-admin.php');
            exit;
        }

        BruteGuard::record($username, false);
        lic_log_event(null, 'admin-2fa-failed', BruteGuard::clientIp(), "user=$username");
        $left = max(0, BruteGuard::MAX_ATTEMPTS - BruteGuard::failedCount($username));
        $loginError = "Неверный код 2FA. Осталось попыток: $left";
        $totpRequired = true;
    }
}

$loggedIn = !empty($_SESSION['admin_logged_in']);
$totpSecret = lic_admin_totp_secret();
if ($totpSecret === '') $totpSecret = (string) ($_SESSION['setup_totp_secret'] ?? '');

// ── POST-действия (только после логина) ─────────────────────────────────
if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lic_verify_csrf()) {
        http_response_code(403);
        exit('Недействительный CSRF-токен. Обновите страницу и повторите действие.');
    }
    $cmd = (string) ($_POST['cmd'] ?? '');

    if ($cmd === 'create') {
        $key = lic_generate_key();
        $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
        $customer = trim((string) ($_POST['customer_name'] ?? ''));
        $email = trim((string) ($_POST['customer_email'] ?? ''));
        $plan = in_array($_POST['plan'] ?? '', ['trial','standard','pro'], true) ? $_POST['plan'] : 'standard';
        $maxDrivers = max(1, min(10000, (int) ($_POST['max_drivers'] ?? 50)));
        $months = max(1, min(60, (int) ($_POST['months'] ?? 12)));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($domain === '') $domain = ''; // домен привяжется при первой активации

        $id = lic_uuid();
        lic_db()->prepare(
            'INSERT INTO licenses
             (id,license_key,license_key_hint,domain,customer_name,customer_email,plan,max_drivers,
              issued_at,expires_at,status,notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id, lic_hash_key($key), substr($key,0,5) . '-…-' . substr($key,-5),
            $domain, $customer, $email, $plan, $maxDrivers,
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s', strtotime("+$months months")),
            'active', $notes,
        ]);

        // Полный ключ не попадает в URL/access-лог и показывается один раз.
        $_SESSION['new_license_key'] = $key;
        lic_log_event($id, 'created', BruteGuard::clientIp(), "domain=$domain");
        header('Location: license-admin.php?created=1');
        exit;
    }

    if ($cmd === 'revoke' || $cmd === 'suspend' || $cmd === 'activate') {
        $id = (string) ($_POST['id'] ?? '');
        $status = $cmd === 'revoke' ? 'revoked' : ($cmd === 'suspend' ? 'suspended' : 'active');
        lic_db()->prepare('UPDATE licenses SET status=?,updated_at=NOW() WHERE id=?')
            ->execute([$status, $id]);
        lic_log_event($id, $status, BruteGuard::clientIp());
        header('Location: license-admin.php?ok=' . urlencode('Статус изменён'));
        exit;
    }

    if ($cmd === 'extend') {
        $id = (string) ($_POST['id'] ?? '');
        $months = max(1, min(60, (int) ($_POST['months'] ?? 12)));
        $read = lic_db()->prepare('SELECT expires_at,status FROM licenses WHERE id=? LIMIT 1');
        $read->execute([$id]);
        $row = $read->fetch();
        if ($row) {
            $expires = strtotime($row['expires_at'] . ' UTC');
            $base = max(time(), $expires === false ? time() : $expires);
            $newExpires = gmdate('Y-m-d H:i:s', strtotime("+$months months", $base));
            $newStatus = $row['status'] === 'expired' ? 'active' : $row['status'];
            lic_db()->prepare(
                'UPDATE licenses SET expires_at=?,status=?,updated_at=NOW() WHERE id=?'
            )->execute([$newExpires, $newStatus, $id]);
        }
        lic_log_event($id, 'extended', BruteGuard::clientIp(), "+$months months");
        header('Location: license-admin.php?ok=' . urlencode("Продлено на $months мес."));
        exit;
    }

    if ($cmd === 'delete') {
        $id = (string) ($_POST['id'] ?? '');
        lic_db()->prepare('DELETE FROM licenses WHERE id = ?')->execute([$id]);
        lic_log_event($id, 'deleted', BruteGuard::clientIp());
        header('Location: license-admin.php?ok=' . urlencode('Лицензия удалена'));
        exit;
    }
}

$newLicenseKey = (string) ($_SESSION['new_license_key'] ?? '');
unset($_SESSION['new_license_key']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Сервер лицензий — TaxiTyumen</title>
<style>
:root{--brand:#6366f1;--ink:#0a0a0c;--panel:#121216;--line:rgba(255,255,255,.08)}
*{box-sizing:border-box;margin:0}
body{background:#0a0a0c;color:#f4f4f5;font:14px/1.5 system-ui,sans-serif;min-height:100vh}
a{color:#a5b4fc}
.login{max-width:420px;margin:10vh auto;padding:28px;background:var(--panel);
  border:1px solid var(--line);border-radius:18px}
.login h1{font-size:22px;margin-bottom:6px}
.login .mut{color:#71717a;font-size:13px}
.login input{width:100%;background:#18181d;border:1px solid var(--line);color:#f4f4f5;
  border-radius:9px;padding:10px 12px;font:inherit;margin-top:6px}
.login button{width:100%;margin-top:14px;background:var(--brand);color:#fff;border:0;
  border-radius:10px;padding:12px;font-weight:700;font-size:15px;cursor:pointer}
.err{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.4);
  color:#fca5a5;border-radius:8px;padding:10px;margin-top:10px;font-size:13px}
.ok{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.4);
  color:#6ee7b7;border-radius:8px;padding:10px;margin:10px 0;font-size:13px}
.wrap{max-width:1100px;margin:0 auto;padding:20px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:20px;margin-top:14px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;font-size:10px;letter-spacing:.12em;text-transform:uppercase;
  color:#71717a;padding:10px 12px;border-bottom:1px solid var(--line)}
td{padding:11px 12px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:top}
.chip{display:inline-flex;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800}
.ok-c{background:rgba(52,211,153,.12);color:#6ee7b7}
.bad-c{background:rgba(248,113,113,.12);color:#fca5a5}
.warn-c{background:rgba(250,204,21,.12);color:#fde047}
.key{font-family:monospace;font-size:12px;color:#c4b5fd}
form.inline{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
input,select,textarea{background:#18181d;border:1px solid var(--line);color:#f4f4f5;
  border-radius:8px;padding:8px 10px;font:inherit}
.btn{background:var(--brand);color:#fff;border:0;border-radius:8px;padding:8px 14px;
  font-weight:700;font-size:13px;cursor:pointer}
.btn.ghost{background:transparent;border:1px solid var(--line);color:#a1a1aa}
.btn.danger{background:rgba(248,113,113,.2);color:#fca5a5}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
h3{margin-bottom:10px}
.totp-qr{background:#fff;border-radius:12px;padding:12px;margin:12px 0;display:inline-block}
.mut{color:#71717a;font-size:12px}
</style>
</head>
<body>
<?php if (!$loggedIn && !$totpRequired): ?>
<div class="login">
  <h1>🔐 Сервер лицензий</h1>
  <p class="mut">TaxiTyumen — управление лицензиями</p>
  <?php if ($loginError): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="cmd" value="login">
    <input name="username" placeholder="Логин" required autocomplete="username">
    <input name="password" type="password" placeholder="Пароль" required autocomplete="current-password">
    <button>Войти</button>
  </form>
</div>

<?php elseif ($totpRequired && !$loggedIn): ?>
<div class="login">
  <h1>📱 Код подтверждения</h1>
  <?php $isTotpSetup = lic_admin_totp_secret() === '' && !empty($_SESSION['setup_totp_secret']); ?>
  <?php if ($isTotpSetup): ?>
    <p class="mut">Первичная настройка. Отсканируйте QR-код приложением Google Authenticator или FreeOTP:</p>
    <div class="totp-qr"><div id="totpQr"></div></div>
    <p class="mut">Или добавьте ключ вручную:<br>
      <code style="color:#c4b5fd;font-size:14px"><?= htmlspecialchars($totpSecret, ENT_QUOTES, 'UTF-8') ?></code></p>
    <script src="assets/qrcode.min.js"></script>
    <script>
      new QRCode(document.getElementById('totpQr'), {
        text: <?= json_encode(Totp::provisioningUri($totpSecret, 'TaxiLicense'), JSON_UNESCAPED_SLASHES) ?>,
        width: 180, height: 180,
        correctLevel: QRCode.CorrectLevel.M
      });
    </script>
  <?php else: ?>
    <p class="mut">Введите код из Google Authenticator или FreeOTP.</p>
  <?php endif; ?>
  <?php if ($loginError): ?><div class="err"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="cmd" value="verify2fa">
    <input name="totp" placeholder="6-значный код" required inputmode="numeric"
           pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus>
    <button>Подтвердить</button>
  </form>
</div>

<?php else: ?>
<div class="wrap">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <div><h1>Лицензии TaxiTyumen</h1>
    <p class="mut">Проверка лицензий: раз в сутки</p></div>
    <a href="?action=logout" class="btn ghost">Выйти</a>
  </div>

  <?php if (!empty($_GET['ok'])): ?><div class="ok">✓ <?= htmlspecialchars($_GET['ok'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($newLicenseKey !== ''): ?>
  <div class="card" style="border-color:rgba(74,222,128,.5)">
    <h3 style="color:#6ee7b7">Новая лицензия создана</h3>
    <p class="mut">Скопируйте ключ сейчас. В базе хранится только HMAC-хэш — повторно полный ключ показать невозможно.</p>
    <div class="key" id="newKey" style="font-size:18px;padding:12px;background:#18181d;border-radius:9px;margin-top:10px;word-break:break-all">
      <?= htmlspecialchars($newLicenseKey, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <button type="button" class="btn" style="margin-top:8px"
      onclick="navigator.clipboard.writeText(document.getElementById('newKey').textContent.trim());this.textContent='Скопировано'">Скопировать ключ</button>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3>Выдать новую лицензию</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:10px">
      <input type="hidden" name="cmd" value="create">
      <input name="customer_name" placeholder="Клиент (ИП/ООО)" required>
      <input name="customer_email" type="email" placeholder="Email">
      <input name="domain" placeholder="Домен (пусто = привяжется сам)">
      <select name="plan">
        <option value="trial">Триал</option>
        <option value="standard" selected>Стандарт</option>
        <option value="pro">PRO</option>
      </select>
      <input name="max_drivers" type="number" value="50" min="1" max="10000" placeholder="Макс. водителей">
      <input name="months" type="number" value="12" min="1" max="60" placeholder="Срок, мес.">
      <input name="notes" placeholder="Заметка">
      <button class="btn">Выдать</button>
    </form>
  </div>

  <?php
  $search = trim((string) ($_GET['q'] ?? ''));
  $where = $search !== ''
    ? 'WHERE license_key=? OR license_key_hint LIKE ? OR domain LIKE ? OR customer_name LIKE ?'
    : '';
  $params = $search !== ''
    ? [lic_hash_key(strtoupper($search)), "%$search%", "%$search%", "%$search%"]
    : [];
  $stmt = lic_db()->prepare("SELECT * FROM licenses $where ORDER BY created_at DESC LIMIT 200");
  $stmt->execute($params);
  $licenses = $stmt->fetchAll();

  $stats = lic_db()->query(
    "SELECT
      COALESCE(SUM(status='active'),0) AS active,
      COALESCE(SUM(status='expired'),0) AS expired,
      COALESCE(SUM(status='revoked'),0) AS revoked,
      COALESCE(SUM(status='suspended'),0) AS suspended,
      COUNT(*) AS total
     FROM licenses"
  )->fetch();
  ?>

  <div class="grid2" style="margin-top:14px">
    <div class="card"><div class="mut">Активных</div><div style="font-size:28px;font-weight:900;color:#6ee7b7"><?= (int)$stats['active'] ?></div></div>
    <div class="card"><div class="mut">Истекших</div><div style="font-size:28px;font-weight:900;color:#fca5a5"><?= (int)$stats['expired'] ?></div></div>
    <div class="card"><div class="mut">Приостановлено</div><div style="font-size:28px;font-weight:900;color:#fde047"><?= (int)$stats['suspended'] ?></div></div>
    <div class="card"><div class="mut">Всего</div><div style="font-size:28px;font-weight:900"><?= (int)$stats['total'] ?></div></div>
  </div>

  <div class="card" style="overflow-x:auto">
    <form method="get" class="inline" style="margin-bottom:10px">
      <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Поиск по ключу, домену, клиенту">
      <button class="btn ghost">Найти</button>
    </form>
    <table>
      <thead><tr>
        <th>Ключ</th><th>Клиент</th><th>Домен</th><th>Тариф</th>
        <th>Водителей</th><th>Действует до</th><th>Статус</th><th>Проверок</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($licenses as $l):
        $cls = $l['status'] === 'active' ? 'ok-c'
          : (in_array($l['status'], ['expired','revoked'], true) ? 'bad-c' : 'warn-c');
      ?>
        <tr>
          <td><span class="key"><?= htmlspecialchars($l['license_key_hint'] ?: 'скрыт', ENT_QUOTES, 'UTF-8') ?></span></td>
          <td><?= htmlspecialchars($l['customer_name']) ?>
            <div class="mut"><?= htmlspecialchars($l['customer_email']) ?></div></td>
          <td><?= $l['domain'] ? htmlspecialchars($l['domain']) : '<span class="mut">—</span>' ?></td>
          <td><?= htmlspecialchars($l['plan']) ?></td>
          <td><?= (int)$l['max_drivers'] ?></td>
          <td><?= $l['expires_at'] ?>
            <div class="mut">выдана <?= $l['issued_at'] ?></div></td>
          <td><span class="chip <?= $cls ?>"><?= $l['status'] ?></span></td>
          <td><?= (int)$l['check_count'] ?>
            <div class="mut"><?= $l['last_check_at'] ?: '—' ?></div></td>
          <td>
            <form method="post" class="inline">
              <input type="hidden" name="id" value="<?= $l['id'] ?>">
              <?php if ($l['status'] !== 'active'): ?>
                <button name="cmd" value="activate" class="btn ghost" title="Возобновить">▶</button>
              <?php else: ?>
                <button name="cmd" value="suspend" class="btn ghost" title="Приостановить">⏸</button>
              <?php endif; ?>
              <input name="months" type="number" value="12" min="1" max="60" style="width:60px" title="Месяцев">
              <button name="cmd" value="extend" class="btn ghost" title="Продлить">+</button>
              <button name="cmd" value="revoke" class="btn danger" title="Отозвать">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$licenses): ?>
        <tr><td colspan="9" class="mut" style="text-align:center;padding:30px">Лицензий нет</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3>Интеграция с сервером такси</h3>
    <p class="mut">Введите ключ лицензии в админке такси: раздел «Лицензия»,
      либо задайте его в config.local.php сервера такси:</p>
    <pre style="background:#18181d;border-radius:8px;padding:14px;margin-top:8px;
font-size:12px;overflow-x:auto;color:#c4b5fd">define('LICENSE_KEY', 'ВАШ-КЛЮЧ-ЛИЦЕНЗИИ');</pre>
  </div>
</div>
<?php endif; ?>
<script>
(function(){
  var token = <?= json_encode(lic_csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
  document.querySelectorAll('form[method="post"]').forEach(function(form){
    if (form.querySelector('input[name="_csrf"]')) return;
    var input=document.createElement('input');
    input.type='hidden'; input.name='_csrf'; input.value=token;
    form.appendChild(input);
  });
})();
</script>
</body>
</html>
