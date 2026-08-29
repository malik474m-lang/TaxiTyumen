<?php
// Общая инициализация админ-панели: ядро API + авторизация по cookie-токену
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once dirname(__DIR__) . '/config.php';
foreach (glob(dirname(__DIR__) . '/src/*.php') as $file) {
    require_once $file;
}

$db = Db::pdo();
Seed::ensure($db);
Simulate::advance($db);
AutoCall::tick($db);

const ADMIN_COOKIE = 'tt_admin';
const ADMIN_PAGE_TITLES = 'Такси Тюмень — Панель администратора';

function admin_hmac_sign(string $data): string
{
    return hash_hmac('sha256', $data, AUTH_SECRET);
}

// Токен-кука: uid.ts.sign — самодостаточная, без PHP-сессий
function admin_issue_cookie(string $uid): void
{
    $ts = (string) time();
    $sign = admin_hmac_sign($uid . '.' . $ts);
    setcookie(ADMIN_COOKIE, "$uid.$ts.$sign", [
        'expires' => $ts + 43200,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function admin_clear_cookie(): void
{
    setcookie(ADMIN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true]);
}

function admin_current(\PDO $db): ?array
{
    $raw = $_COOKIE[ADMIN_COOKIE] ?? '';
    $parts = explode('.', $raw);
    if (count($parts) !== 3) {
        return null;
    }
    [$uid, $ts, $sign] = $parts;
    if (!hash_equals(admin_hmac_sign($uid . '.' . $ts), $sign)) {
        return null;
    }
    if (time() - (int) $ts > 43200) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin' AND is_blocked = 0 LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function admin_require(\PDO $db): array
{
    $admin = admin_current($db);
    if (!$admin) {
        header('Location: login.php');
        exit;
    }
    return $admin;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function money(?float $v): string
{
    return $v === null ? '—' : round($v) . ' ₽';
}

function fmt_date(?string $d): string
{
    if (!$d) {
        return '—';
    }
    return date('d.m H:i', strtotime($d . ' UTC'));
}

// ── Фирменный лэйаут ────────────────────────────────────────────────────────

function layout_header(string $title, string $active): void
{
    $nav = [
        'index'    => ['index.php', 'Обзор'],
        'orders'   => ['orders.php', 'Заказы'],
        'drivers'  => ['drivers.php', 'Водители'],
        'tariffs'  => ['tariffs.php', 'Тарифы'],
        'branding' => ['branding.php', 'Брендинг'],
        'autocall' => ['autocall.php', 'Автодозвон'],
    ];
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — Такси Тюмень</title>
<style>
:root{--brand:#facc15;--ink:#0a0a0c;--panel:#121216;--line:rgba(255,255,255,.08)}
*{box-sizing:border-box;margin:0}
body{background:radial-gradient(900px 500px at 85% -10%,rgba(250,204,21,.07),transparent 60%),#0a0a0c;
  color:#f4f4f5;font:14px/1.5 "Segoe UI",system-ui,sans-serif;min-height:100vh}
a{color:#facc15;text-decoration:none}
.wrap{max-width:1180px;margin:0 auto;padding:24px 20px 60px}
header.top{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 20px;
  background:rgba(10,10,12,.85);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:10;backdrop-filter:blur(10px)}
.logo{display:flex;align-items:center;gap:10px;font-weight:900;letter-spacing:-.02em}
.logo .tile{width:36px;height:36px;border-radius:10px;background:var(--brand);color:var(--ink);
  display:flex;align-items:center;justify-content:center;font-size:19px;box-shadow:0 6px 20px rgba(250,204,21,.3)}
.logo small{display:block;font-size:10px;letter-spacing:.2em;color:#facc15;font-weight:700;text-transform:uppercase}
nav{display:flex;gap:6px;flex-wrap:wrap}
nav a{color:#a1a1aa;font-weight:700;font-size:13px;padding:8px 14px;border-radius:10px;
  border:1px solid transparent;transition:.15s}
nav a:hover{color:#fafafa;border-color:var(--line)}
nav a.on{background:var(--brand);color:var(--ink)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:20px}
.grid{display:grid;gap:14px}
.grid.q4{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.grid.q2{grid-template-columns:repeat(auto-fit,minmax(340px,1fr))}
h1{font-size:24px;font-weight:900;letter-spacing:-.02em;margin-bottom:4px}
.mut{color:#71717a;font-size:13px}
.stat-big{font-size:30px;font-weight:900;letter-spacing:-.03em;margin-top:6px}
.chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;
  font-size:11px;font-weight:800;white-space:nowrap}
.ok{background:rgba(52,211,153,.12);color:#6ee7b7}
.warn{background:rgba(250,204,21,.12);color:#fde047}
.bad{background:rgba(248,113,113,.12);color:#fca5a5}
.info{background:rgba(56,189,248,.12);color:#7dd3fc}
.violet{background:rgba(167,139,250,.12);color:#c4b5fd}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#71717a;
  padding:10px 12px;border-bottom:1px solid var(--line)}
td{padding:11px 12px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:top}
tr:hover td{background:rgba(255,255,255,.02)}
input,select,textarea{background:#0f0f13;border:1px solid rgba(255,255,255,.1);border-radius:10px;
  padding:9px 12px;color:#fafafa;font-size:13px;width:100%;outline:none;transition:.15s}
input:focus,select:focus,textarea:focus{border-color:rgba(250,204,21,.6);box-shadow:0 0 0 3px rgba(250,204,21,.12)}
select option{background:#17171c}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;background:var(--brand);
  color:var(--ink);font-weight:800;border:none;border-radius:10px;padding:9px 16px;font-size:13px;
  cursor:pointer;transition:.15s;box-shadow:0 3px 16px rgba(250,204,21,.2)}
.btn:hover{filter:brightness(1.1)}
.btn.ghost{background:rgba(255,255,255,.06);color:#d4d4d8;border:1px solid var(--line);box-shadow:none}
.btn.danger{background:rgba(239,68,68,.14);color:#fca5a5;border:1px solid rgba(239,68,68,.3);box-shadow:none}
.btn.sm{padding:6px 11px;font-size:12px;border-radius:8px}
form.inline{display:inline-flex;gap:6px;align-items:center}
.bars{display:flex;height:130px;align-items:flex-end;gap:6px;margin-top:12px}
.bars .b{flex:1;display:flex;flex-direction:column;justify-content:flex-end;text-align:center}
.bars .bar{border-radius:5px 5px 0 0;background:linear-gradient(180deg,#facc15,#facc1588);min-height:3px}
.bars .b span{font-size:10px;color:#71717a;margin-top:5px;font-weight:700}
.bars.light .bar{background:linear-gradient(180deg,#38bdf8,#38bdf877)}
.flash{margin-bottom:16px;padding:12px 16px;border-radius:12px;font-weight:700;font-size:13px;
  border:1px solid rgba(52,211,153,.3);background:rgba(52,211,153,.08);color:#6ee7b7}
.flex{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.between{justify-content:space-between}
.plate{display:inline-block;border:2px solid #52525b;background:#27272a;border-radius:8px;
  padding:3px 10px;font-weight:900;letter-spacing:.12em;font-size:13px}
footer{margin-top:40px;font-size:11px;color:#52525b;text-align:center}
.checker{height:8px;width:120px;border-radius:99px;margin:30px auto 12px;
  background-image:repeating-conic-gradient(#facc15 0% 25%,transparent 0% 50%);background-size:12px 12px;opacity:.7}
</style>
</head>
<body>
<header class="top">
  <div class="logo">
    <div class="tile">🚕</div>
    <div>ТАКСИ ТЮМЕНЬ<small>панель администратора</small></div>
  </div>
  <nav>
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= $href ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
    <a href="logout.php" style="color:#fca5a5">Выйти</a>
  </nav>
</header>
<main class="wrap">
<?php
}

function layout_footer(): void
{
    ?>
<div class="checker"></div>
<footer>TaxiTyumen ServerHosting · PHP + MySQL · Тюмень UTC+5</footer>
</main>
</body>
</html>
<?php
}
