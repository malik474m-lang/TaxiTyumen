<?php
// Одноразовый установщик сервера лицензий для shared-хостинга jino.ru.
// После успешной установки создаёт setup.lock и больше не запускается.
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

$dir = __DIR__;
$configFile = $dir . '/license.local.php';
$lockFile = $dir . '/setup.lock';

if (is_file($lockFile)) {
    header('Location: license-admin.php');
    exit;
}

session_name('licsetup');
session_start();
if (empty($_SESSION['setup_csrf'])) $_SESSION['setup_csrf'] = bin2hex(random_bytes(24));
$error = '';
$ok = '';

$requirements = [
    'PHP 7.4 или новее' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO' => extension_loaded('pdo'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'OpenSSL' => extension_loaded('openssl'),
    'mbstring' => extension_loaded('mbstring'),
    'Каталог доступен для записи' => is_writable($dir),
];
$requirementsOk = !in_array(false, $requirements, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['setup_csrf'], $csrf)) {
        $error = 'Недействительный CSRF-токен. Обновите страницу.';
    } elseif (is_file($configFile)) {
        $error = 'license.local.php уже существует. Для повторной установки удалите его через файловый менеджер jino.ru.';
    } elseif (!$requirementsOk) {
        $error = 'Не выполнены системные требования — смотрите список ниже.';
    } else {
        $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
        $port = trim((string) ($_POST['db_port'] ?? '3306'));
        $name = trim((string) ($_POST['db_name'] ?? ''));
        $user = trim((string) ($_POST['db_user'] ?? ''));
        $pass = (string) ($_POST['db_pass'] ?? '');
        $admin = trim((string) ($_POST['admin_user'] ?? 'admin'));
        $adminPass = (string) ($_POST['admin_pass'] ?? '');
        $adminRepeat = (string) ($_POST['admin_repeat'] ?? '');

        if ($name === '' || $user === '') {
            $error = 'Укажите базу MySQL и пользователя.';
        } elseif (strlen($adminPass) < 12) {
            $error = 'Пароль администратора — минимум 12 символов.';
        } elseif ($adminPass !== $adminRepeat) {
            $error = 'Пароли администратора не совпадают.';
        } else {
            try {
                // Сначала проверяем реквизиты, не сохраняя их
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
                    $user, $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $pdo->query('SELECT 1');

                $secret = bin2hex(random_bytes(48));
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $export = static function ($v): string { return var_export($v, true); };
                $content = "<?php\n"
                    . "// Создано install.php " . gmdate('c') . "\n"
                    . "define('LIC_DB_HOST', " . $export($host) . ");\n"
                    . "define('LIC_DB_PORT', " . $export($port) . ");\n"
                    . "define('LIC_DB_NAME', " . $export($name) . ");\n"
                    . "define('LIC_DB_USER', " . $export($user) . ");\n"
                    . "define('LIC_DB_PASS', " . $export($pass) . ");\n"
                    . "define('LIC_SECRET', " . $export($secret) . ");\n"
                    . "define('LIC_ADMIN_USER', " . $export($admin) . ");\n"
                    . "define('LIC_ADMIN_PASS_HASH', " . $export($hash) . ");\n"
                    . "define('LIC_DEBUG', false);\n";

                if (file_put_contents($configFile, $content, LOCK_EX) === false) {
                    throw new RuntimeException('Не удалось записать license.local.php');
                }
                @chmod($configFile, 0600);

                // Загружаем ядро и создаём таблицы
                require_once $dir . '/license-core.php';
                lic_ensure_tables();

                file_put_contents($lockFile,
                    'installed=' . gmdate('c') . "\n", LOCK_EX);
                @chmod($lockFile, 0600);
                $_SESSION = [];
                session_destroy();
                header('Location: license-admin.php?installed=1');
                exit;
            } catch (Throwable $e) {
                if (is_file($configFile) && !is_file($lockFile)) @unlink($configFile);
                $error = 'Ошибка MySQL/установки: ' . $e->getMessage();
            }
        }
    }
}
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Установка сервера лицензий</title>
<style>
body{background:#0a0a0c;color:#f4f4f5;font:14px/1.5 system-ui;margin:0;padding:25px}
.box{max-width:680px;margin:auto;background:#121216;border:1px solid #292932;border-radius:18px;padding:24px}
h1{margin:0 0 6px}.mut{color:#a1a1aa}.warn{color:#fde047}.err{color:#fca5a5;background:#331919;padding:10px;border-radius:8px;margin:12px 0}
.req{display:grid;grid-template-columns:1fr auto;gap:5px;margin:14px 0;padding:12px;background:#18181d;border-radius:10px}
label{display:block;color:#a1a1aa;margin-top:10px}input{width:100%;box-sizing:border-box;background:#18181d;border:1px solid #3f3f46;color:#fff;border-radius:8px;padding:9px;margin-top:4px}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:10px}button{width:100%;margin-top:16px;background:#6366f1;color:white;border:0;border-radius:10px;padding:12px;font-weight:800}
code{color:#c4b5fd}
</style></head><body><div class="box">
<h1>Установка сервера лицензий</h1>
<p class="mut">taxi.license-prog.ru · PHP <?= htmlspecialchars(PHP_VERSION) ?></p>
<?php if (version_compare(PHP_VERSION, '8.1.0', '<')): ?>
<p class="warn">⚠ Сейчас используется PHP <?= htmlspecialchars(PHP_VERSION) ?>. Сервер совместим с 7.4, но эта версия PHP устарела и не получает обновления безопасности. В панели jino.ru переключите домен на PHP 8.2 или 8.3.</p>
<?php endif; ?>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="req">
<?php foreach ($requirements as $label => $passed): ?>
<span><?= htmlspecialchars($label) ?></span><b style="color:<?= $passed ? '#4ade80' : '#f87171' ?>"><?= $passed ? 'OK' : 'НЕТ' ?></b>
<?php endforeach; ?>
</div>
<?php if (is_file($configFile)): ?>
<div class="err">Файл <code>license.local.php</code> уже существует. Не перезаписываю его из соображений безопасности. Если настройки неверны — удалите файл через файловый менеджер jino.ru и обновите эту страницу.</div>
<p><a style="color:#a5b4fc" href="license-admin.php">Проверить текущие настройки</a></p>
<?php else: ?>
<form method="post">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['setup_csrf']) ?>">
<h3>MySQL (база должна быть заранее создана в панели jino.ru)</h3>
<div class="grid"><label>Хост<input name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required></label><label>Порт<input name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" required></label></div>
<label>Имя базы<input name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required></label>
<label>Пользователь MySQL<input name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required></label>
<label>Пароль MySQL<input type="password" name="db_pass" autocomplete="new-password"></label>
<h3 style="margin-top:20px">Администратор лицензий</h3>
<label>Логин<input name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required></label>
<label>Пароль (минимум 12 символов)<input type="password" name="admin_pass" required minlength="12" autocomplete="new-password"></label>
<label>Повторите пароль<input type="password" name="admin_repeat" required minlength="12" autocomplete="new-password"></label>
<button <?= !$requirementsOk ? 'disabled' : '' ?>>Установить</button>
</form>
<?php endif; ?>
</div></body></html>
