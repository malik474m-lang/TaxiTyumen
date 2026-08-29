<?php
// Вход в админ-панель (server-rendered)
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

// Уже авторизован — в панель
if (admin_current($db)) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = Auth::normalizePhone((string) ($_POST['phone'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = $db->prepare("SELECT * FROM users WHERE phone = ? AND role = 'admin' LIMIT 1");
    $stmt->execute([$phone]);
    $user = $stmt->fetch();

    if (!$user || !Auth::verifyPassword($password, $user['password_hash'])) {
        $error = 'Неверный телефон или пароль администратора';
    } elseif ($user['is_blocked']) {
        $error = 'Аккаунт заблокирован: ' . ($user['block_reason'] ?? '');
    } else {
        admin_issue_cookie($user['id']);
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — Такси Тюмень</title>
<style>
*{box-sizing:border-box;margin:0}
body{background:radial-gradient(1000px 600px at 50% -20%,rgba(250,204,21,.09),transparent 60%),#0a0a0c;
  color:#f4f4f5;font:14px/1.5 "Segoe UI",system-ui,sans-serif;min-height:100vh;
  display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#121216;border:1px solid rgba(255,255,255,.09);border-radius:20px;
  padding:32px;width:100%;max-width:380px}
.tile{width:52px;height:52px;border-radius:14px;background:#facc15;color:#0a0a0c;font-size:26px;
  display:flex;align-items:center;justify-content:center;margin:0 auto 16px;
  box-shadow:0 10px 30px rgba(250,204,21,.35)}
h1{text-align:center;font-size:20px;font-weight:900;letter-spacing:-.02em}
.sub{text-align:center;color:#71717a;font-size:12px;margin:4px 0 22px;
  text-transform:uppercase;letter-spacing:.2em}
input{background:#0f0f13;border:1px solid rgba(255,255,255,.1);border-radius:12px;
  padding:12px 15px;color:#fafafa;font-size:14px;width:100%;outline:none;margin-bottom:12px;transition:.15s}
input:focus{border-color:rgba(250,204,21,.6);box-shadow:0 0 0 3px rgba(250,204,21,.12)}
button{width:100%;background:#facc15;color:#0a0a0c;font-weight:800;border:none;border-radius:12px;
  padding:13px;font-size:14px;cursor:pointer;box-shadow:0 4px 20px rgba(250,204,21,.22)}
button:hover{filter:brightness(1.1)}
.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5;
  border-radius:10px;padding:11px 14px;font-size:13px;font-weight:600;margin-bottom:14px}
.demo{margin-top:18px;text-align:center;font-size:11px;color:#52525b}
.demo code{color:#a1a1aa}
</style>
</head>
<body>
<div class="card">
  <div class="tile">🚕</div>
  <h1>Панель администратора</h1>
  <div class="sub">Такси Тюмень · 72 регион</div>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <input name="phone" placeholder="+7 (___) ___-__-__" required autofocus value="<?= h($_POST['phone'] ?? '') ?>">
    <input type="password" name="password" placeholder="Пароль" required>
    <button type="submit">Войти</button>
  </form>
  <div class="demo">демо: <code>+79001234567</code> · <code>Admin123!</code></div>
</div>
</body>
</html>
