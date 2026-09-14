<?php
// ═══════════════════════════════════════════════════════════════════════════
// TaxiTyumen — установщик чистой установки на новый домен.
// Разместите этот файл в корне домена рядом с папками api/, admin/, src/, sql/.
// Откройте https://ВАШ-ДОМЕН/install.php и следуйте шагам.
//
// После успешной установки УДАЛИТЕ этот файл с хостинга!
// ═══════════════════════════════════════════════════════════════════════════
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('UTC');
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$lockFile = __DIR__ . '/install.lock';
$configFile = __DIR__ . '/config.local.php';
$doneFile = __DIR__ . '/install.done';

// Уже установлено
if (is_file($lockFile)) {
    echo render('Уже установлено', '<p>Система уже установлена. Файл <code>install.lock</code> существует.</p>'
        . '<p>Для повторной установки удалите <code>install.lock</code> через файловый менеджер.</p>'
        . '<p><a href="/admin/login.php">Войти в админку</a></p>');
    exit;
}

session_name('taxiinstall');
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf'];
$error = '';
$ok = '';
$step = 1;

$requirements = [
    'PHP 8.0+' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
    'OpenSSL' => extension_loaded('openssl'),
    'JSON' => extension_loaded('json'),
    'Каталог доступен для записи' => is_writable(__DIR__),
    'sql/schema.sql найден' => is_file(__DIR__ . '/sql/schema.sql'),
    'src/Db.php найден' => is_file(__DIR__ . '/src/Db.php'),
];
$reqOk = !in_array(false, $requirements, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $p)) {
        $error = 'Недействительный CSRF-токен. Обновите страницу.';
    } elseif (!$reqOk) {
        $error = 'Не выполнены системные требования — смотрите список ниже.';
    } else {
        $dbHost = trim((string) ($_POST['db_host'] ?? 'localhost'));
        $dbPort = trim((string) ($_POST['db_port'] ?? '3306'));
        $dbName = trim((string) ($_POST['db_name'] ?? ''));
        $dbUser = trim((string) ($_POST['db_user'] ?? ''));
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $domain  = strtolower(trim((string) ($_POST['domain'] ?? '')));
        $saLogin = trim((string) ($_POST['sa_login'] ?? ''));
        $saPass  = (string) ($_POST['sa_pass'] ?? '');
        $saRepeat = (string) ($_POST['sa_repeat'] ?? '');

        if ($dbName === '' || $dbUser === '') {
            $error = 'Укажите имя базы и пользователя MySQL.';
        } elseif ($domain === '' || !filter_var('https://' . $domain, FILTER_VALIDATE_URL)) {
            $error = 'Укажите корректный домен (например: taxi.example.ru).';
        } elseif ($saLogin === '' || strlen($saPass) < 12) {
            $error = 'Логин супер-администратора не пустой, пароль — минимум 12 символов.';
        } elseif ($saPass !== $saRepeat) {
            $error = 'Пароли супер-администратора не совпадают.';
        } else {
            try {
                // Подключаемся без указания БД — она может ещё не существовать
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbHost, $dbPort),
                    $dbUser, $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $pdo->exec(sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '', $dbName)
                ));
                $pdo->exec(sprintf('USE `%s`', str_replace('`', '', $dbName)));

                // Применяем полную схему
                $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
                if ($sql === false) throw new RuntimeException('sql/schema.sql не найден');
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt !== '') $pdo->exec($stmt);
                }

                // Генерируем секреты
                $authSecret = bin2hex(random_bytes(48));
                $saHash = password_hash($saPass, PASSWORD_DEFAULT);

                // Создаём superadmin через Access
                require_once __DIR__ . '/src/Db.php';
                require_once __DIR__ . '/src/Auth.php';
                require_once __DIR__ . '/src/Access.php';

                $uid = bin2hex(random_bytes(16));
                $uid = sprintf('%s-%s-%s-%s-%s',
                    substr($uid,0,8), substr($uid,8,4), substr($uid,12,4),
                    substr($uid,16,4), substr($uid,20,12));
                $techPhone = '+70000000001';
                $ins = $pdo->prepare(
                    "INSERT INTO users (id, phone, username, first_name, last_name, password_hash, role, is_phone_verified, is_active)
                     VALUES (?,?,?,?,?,?, 'superadmin', 1, 1)
                     ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role='superadmin'"
                );
                $ins->execute([$uid, $techPhone, $saLogin, 'Супер', 'Администратор', $saHash]);

                // Записываем маркер установки для Access
                $pdo->prepare(
                    "CREATE TABLE IF NOT EXISTS system_state (
                        state_key VARCHAR(60) PRIMARY KEY,
                        state_value VARCHAR(255) NOT NULL,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                )->execute();
                $pdo->prepare(
                    'INSERT INTO system_state (state_key, state_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)'
                )->execute(['superadmin_installed', $uid]);

                // Создаём config.local.php
                $e = static function ($v): string { return var_export($v, true); };
                $config = "<?php\n"
                    . "// Создано install.php " . gmdate('c') . "\n"
                    . "// Домен: {$domain}\n\n"
                    . "define('TAXI_DB_HOST', " . $e($dbHost) . ");\n"
                    . "define('TAXI_DB_PORT', " . $e($dbPort) . ");\n"
                    . "define('TAXI_DB_NAME', " . $e($dbName) . ");\n"
                    . "define('TAXI_DB_USER', " . $e($dbUser) . ");\n"
                    . "define('TAXI_DB_PASS', " . $e($dbPass) . ");\n\n"
                    . "// Секрет подписи токенов — НЕ меняйте после установки!\n"
                    . "define('AUTH_SECRET', " . $e($authSecret) . ");\n\n"
                    . "// Публичный URL и CORS для нового домена\n"
                    . "define('PUBLIC_BASE_URL', 'https://{$domain}');\n"
                    . "define('CORS_ORIGIN', 'https://{$domain}');\n\n"
                    . "// Супер-администратор (для восстановления)\n"
                    . "define('SUPERADMIN_PASSWORD', " . $e($saPass) . ");\n\n"
                    . "// Ключ лицензии — введите в админке или здесь\n"
                    . "define('LICENSE_KEY', '');\n"
                    . "define('LICENSE_SERVER', 'https://taxi.license-prog.ru');\n\n"
                    . "// Тюмень UTC+5\n"
                    . "define('CITY_UTC_OFFSET', 5);\n";

                if (file_put_contents($configFile, $config, LOCK_EX) === false) {
                    throw new RuntimeException('Не удалось записать config.local.php');
                }
                @chmod($configFile, 0600);

                // Блокируем повторную установку
                file_put_contents($lockFile,
                    'installed=' . gmdate('c') . "\ndomain={$domain}\n", LOCK_EX);
                @chmod($lockFile, 0600);

                $ok = 'Установка завершена успешно!';
                $step = 3;
            } catch (Throwable $e) {
                $error = 'Ошибка: ' . $e->getMessage();
                if (is_file($configFile) && !is_file($lockFile)) @unlink($configFile);
            }
        }
    }
}

function render(string $title, string $body): string
{
    return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{background:#0a0a0c;color:#f4f4f5;font:15px/1.6 system-ui;padding:25px}'
        . '.box{max-width:760px;margin:auto;background:#121216;border:1px solid #292932;border-radius:18px;padding:26px}'
        . 'h1{margin:0 0 10px}.mut{color:#a1a1aa}.err{color:#fca5a5;background:#331919;'
        . 'padding:12px;border-radius:8px;margin:12px 0}.ok{color:#6ee7b7;background:#0f2e1a;'
        . 'padding:12px;border-radius:8px;margin:12px 0}label{display:block;color:#a1a1aa;margin-top:12px}'
        . 'input{width:100%;box-sizing:border-box;background:#18181d;border:1px solid #3f3f46;'
        . 'color:#fff;border-radius:8px;padding:10px;margin-top:4px;font:inherit}'
        . 'button{width:100%;margin-top:18px;background:#facc15;color:#0a0a0c;border:0;'
        . 'border-radius:10px;padding:13px;font-weight:800;font-size:16px;cursor:pointer}'
        . '.req{display:grid;grid-template-columns:1fr auto;gap:4px;margin:14px 0;'
        . 'padding:12px;background:#18181d;border-radius:10px;font-size:13px}'
        . '.grid{display:grid;grid-template-columns:3fr 1fr;gap:10px}'
        . 'code{color:#c4b5fd}a{color:#a5b4fc}</style></head><body><div class="box">'
        . $body . '</div></body></html>';
}

if ($step === 3) {
    echo render('Установка завершена', "<div class=\"ok\">✓ {$ok}</div>"
        . '<h3>Дальнейшие шаги:</h3>'
        . '<ol style="color:#a1a1aa;line-height:1.9">'
        . '<li><b style="color:#fca5a5">Удалите install.php с хостинга!</b></li>'
        . '<li>Войдите в админку: <a href="/admin/login.php">/admin/login.php</a></li>'
        . '<li>Логин супер-администратора: <code>' . htmlspecialchars($saLogin ?? '') . '</code></li>'
        . '<li>В разделе «Бренд сервиса» укажите название, город и телефон</li>'
        . '<li>В разделе «API-ключи» добавьте ключи DaData, TomTom, Яндекс Карт</li>'
        . '<li>В разделе «Лицензия» введите ключ лицензии</li>'
        . '<li>Создайте водителей, операторов и админов через админку</li>'
        . '</ol>');
} else {
    $reqHtml = '';
    foreach ($requirements as $label => $passed) {
        $reqHtml .= '<span>' . htmlspecialchars($label) . '</span>'
            . '<b style="color:' . ($passed ? '#4ade80' : '#f87171') . '">'
            . ($passed ? 'OK' : 'НЕТ') . '</b>';
    }
    echo render('Установка TaxiTyumen',
        ($error !== '' ? "<div class=\"err\">{$error}</div>" : '')
        . '<h1>Установка системы такси</h1>'
        . '<p class="mut">Чистая установка на новый домен, без тестовых данных</p>'
        . '<div class="req">' . $reqHtml . '</div>'
        . '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
        . '<h3>Подключение к MySQL</h3>'
        . '<p class="mut">База создаётся автоматически. Пользователь должен иметь права CREATE.</p>'
        . '<div class="grid">'
        . '<label>Хост<input name="db_host" value="localhost" required></label>'
        . '<label>Порт<input name="db_port" value="3306" required></label>'
        . '</div>'
        . '<label>Имя базы<input name="db_name" placeholder="taxi_new" required></label>'
        . '<label>Пользователь<input name="db_user" required></label>'
        . '<label>Пароль<input type="password" name="db_pass" autocomplete="new-password"></label>'
        . '<h3 style="margin-top:24px">Домен</h3>'
        . '<label>Домен без https://<input name="domain" placeholder="taxi.example.ru" required></label>'
        . '<h3 style="margin-top:24px">Супер-администратор</h3>'
        . '<label>Логин<input name="sa_login" value="superadmin" required></label>'
        . '<label>Пароль (мин. 12 символов)<input type="password" name="sa_pass" required minlength="12" autocomplete="new-password"></label>'
        . '<label>Повторите пароль<input type="password" name="sa_repeat" required minlength="12" autocomplete="new-password"></label>'
        . '<button' . (!$reqOk ? ' disabled' : '') . '>Установить</button>'
        . '</form>');
}
