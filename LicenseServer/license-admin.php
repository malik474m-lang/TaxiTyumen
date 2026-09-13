<?php
// Админ-панель сервера лицензий: логин с 2FA, управление лицензиями.
require_once __DIR__ . '/license-core.php';

lic_ensure_tables();
session_name('licadmin');
session_start();

// ── Выход ────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: license-admin.php');
    exit;
}

// ── Логин ────────────────────────────────────────────────────────────────
$loginError = '';
$totpRequired = false;
$pendingUser = $_SESSION['pending_user'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cmd'] ?? '') === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $totp = trim((string) ($_POST['totp'] ?? ''));

    // Проверяем брутфорс ДО сверки пароля
    if (BruteGuard::isLocked($username)) {
        $mins = BruteGuard::remainingLockout($username);
        $loginError = "Слишком много неудачных попыток. Повторите через $mins мин.";
    } else {
        $hash = LIC_ADMIN_PASS_HASH;
        $validUser = $username === LIC_ADMIN_USER;
        $validPass = $hash !== '' && password_verify($password, $hash);

        if (!$validUser || !$validPass) {
            BruteGuard::record($username, false);
            lic_log_event(null, 'admin-login-failed', BruteGuard::clientIp(), "user=$username");
            $remaining = BruteGuard::MAX_ATTEMPTS -
                (BruteGuard::isLocked($username) ? BruteGuard::MAX_ATTEMPTS : 0);
            $loginError = "Неверный логин или пароль. Осталось попыток: $remaining";
        } else {
            // Пароль верен — нужен TOTP
            $secret = $_SESSION['totp_secret'] ?? '';
            if ($secret === '') {
                // Первый вход без настроенного 2FA — генерируем секрет
                $secret = Totp::generateSecret();
                $_SESSION['totp_secret'] = $secret;
                $_SESSION['totp_pending_setup'] = true;
            }

            if ($totp === '' && !empty($_SESSION['totp_pending_setup'])) {
                // Показываем QR для первичной настройки
                BruteGuard::record($username, true);
                $_SESSION['pending_user'] = $username;
                $totpRequired = true;
            } elseif ($totp !== '' && Totp::verify($secret, $totp)) {
                // 2FA пройдена
                BruteGuard::record($username, true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user'] = $username;
                unset($_SESSION['totp_pending_setup'], $_SESSION['pending_user']);
                lic_log_event(null, 'admin-login-ok', BruteGuard::clientIp(), "user=$username");
                header('Location: license-admin.php');
                exit;
            } elseif ($totp !== '') {
                BruteGuard::record($username, false);
                lic_log_event(null, 'admin-2fa-failed', BruteGuard::clientIp(), "user=$username");
                $loginError = 'Неверный код 2FA';
                $totpRequired = true;
            } else {
                $totpRequired = true;
            }
        }
    }
}

// ── Проверка авторизации ────────────────────────────────────────────────
$loggedIn = !empty($_SESSION['admin_logged_in']);
$totpSecret = $_SESSION['totp_secret'] ?? '';

// ── POST-действия (только после логина) ─────────────────────────────────
if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
             (id,license_key,domain,customer_name,customer_email,plan,max_drivers,
              issued_at,expires_at,status,notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$id, $key, $domain, $customer, $email, $plan, $maxDrivers,
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s', strtotime("+$months months")),
            'active', $notes]);

        lic_log_event($id, 'created', BruteGuard::clientIp(), "key=$key domain=$domain");
        header('Location: license-admin.php?ok=' . urlencode("Лицензия создана: $key"));
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
        lic_db()->prepare(
            'UPDATE licenses SET expires_at = DATE_ADD(GREATEST(expires_at, NOW()), INTERVAL ? MONTH),
             status = IF(status = "expired", "active", status), updated_at = NOW() WHERE id = ?'
        )->execute([$months, $id]);
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
  <?php if (!empty($_SESSION['totp_pending_setup'])): ?>
    <p class="mut">Отсканируйте QR-код приложением Google Authenticator или FreeOTP:</p>
    <div class="totp-qr">
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode(Totp::provisioningUri($totpSecret, 'TaxiLicense')) ?>"
           alt="QR" width="180" height="180">
    </div>
    <p class="mut">Или введите секрет вручную:<br>
      <code style="color:#c4b5fd;font-size:14px"><?= $totpSecret ?></code></p>
  <?php endif; ?>
  <?php if ($loginError): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="cmd" value="login">
    <input type="hidden" name="username" value="<?= htmlspecialchars($pendingUser) ?>">
    <input type="hidden" name="password" value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">
    <input name="totp" placeholder="6-значный код" required inputmode="numeric" maxlength="6" autocomplete="one-time-code">
    <button>Подтвердить</button>
  </form>
</div>

<?php else: ?>
<div class="wrap">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <div><h1>Лицензии TaxiTyumen</h1>
    <p class="mut">Сервер: taxi.license-prog.ru · Проверка: раз в сутки</p></div>
    <a href="?action=logout" class="btn ghost">Выйти</a>
  </div>

  <?php if (!empty($_GET['ok'])): ?><div class="ok">✓ <?= htmlspecialchars($_GET['ok']) ?></div><?php endif; ?>

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
  $where = $search !== '' ? 'WHERE license_key LIKE ? OR domain LIKE ? OR customer_name LIKE ?' : '';
  $params = $search !== '' ? ["%$search%","%$search%","%$search%"] : [];
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
      <?php foreach ($licenses as $l): $cls = match($l['status']) {
        'active' => 'ok-c', 'expired' => 'bad-c', 'revoked' => 'bad-c', default => 'warn-c'
      }; ?>
        <tr>
          <td><span class="key"><?= htmlspecialchars($l['license_key']) ?></span></td>
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
    <p class="mut">На сервере такси в config.local.php добавьте:</p>
    <pre style="background:#18181d;border-radius:8px;padding:14px;margin-top:8px;
font-size:12px;overflow-x:auto;color:#c4b5fd">define('LICENSE_KEY', 'ВАШ-КЛЮЧ-ЛИЦЕНЗИИ');
define('LICENSE_SERVER', 'https://taxi.license-prog.ru');</pre>
    <p class="mut">Ключ также можно ввести в админке такси: «Бренд сервиса» → «Лицензия».</p>
  </div>
</div>
<?php endif; ?>
</body>
</html>
