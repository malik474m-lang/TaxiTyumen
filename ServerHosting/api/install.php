<?php
// GET api/install.php — одноразовая установка на хостинге:
// применяет sql/schema.sql и наполняет демо-данными (тарифы/персонал/водители).
// Удалите файл после установки!
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/Db.php';


/**
 * Разбивает schema.sql на SQL-выражения, не принимая точку с запятой
 * внутри комментария или строкового литерала за конец выражения.
 *
 * Простой explode(';', $sql) ломал установку на комментарии:
 *   -- Сервисы TomTom: ...; ключ — в api_settings
 * Остаток текста «ключ — ... CREATE TABLE» отправлялся в MariaDB как SQL.
 *
 * @return array<int,string>
 */
function install_sql_statements(string $sql): array
{
    // BOM в начале файла мешает первому выражению на некоторых MariaDB.
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") $sql = substr($sql, 3);

    $statements = [];
    $current = '';
    $length = strlen($sql);
    $quote = null;          // ' или " или `
    $lineComment = false;   // -- ... / # ...
    $blockComment = false;  // /* ... */

    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';
        $next2 = $i + 2 < $length ? $sql[$i + 2] : '';

        if ($lineComment) {
            if ($ch === "\n") {
                $lineComment = false;
                $current .= "\n";
            }
            continue;
        }
        if ($blockComment) {
            if ($ch === '*' && $next === '/') {
                $blockComment = false;
                $i++;
            }
            continue;
        }

        if ($quote !== null) {
            $current .= $ch;
            if ($ch === '\\' && $i + 1 < $length) {
                // Экранированный символ внутри строки.
                $current .= $sql[++$i];
                continue;
            }
            if ($ch === $quote) {
                // SQL допускает удвоенную кавычку: '' / "" / ``.
                if ($next === $quote) {
                    $current .= $next;
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        // Блочный комментарий.
        if ($ch === '/' && $next === '*') {
            $blockComment = true;
            $i++;
            continue;
        }
        // Строчный -- комментарий: по SQL после -- должен идти пробел/EOL.
        if ($ch === '-' && $next === '-'
            && ($next2 === '' || $next2 === " " || $next2 === "\t"
                || $next2 === "\r" || $next2 === "\n")) {
            $lineComment = true;
            $i++;
            continue;
        }
        // MySQL/MariaDB # комментарий.
        if ($ch === '#') {
            $lineComment = true;
            continue;
        }

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $current .= $ch;
            continue;
        }

        if ($ch === ';') {
            $statement = trim($current);
            if ($statement !== '') $statements[] = $statement;
            $current = '';
            continue;
        }
        $current .= $ch;
    }

    $tail = trim($current);
    if ($tail !== '') $statements[] = $tail;
    return $statements;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new \PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', TAXI_DB_HOST, TAXI_DB_PORT),
        TAXI_DB_USER,
        TAXI_DB_PASS,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
    // Создаём БД, если её нет (нужны права CREATE)
    $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', TAXI_DB_NAME));
    $pdo->exec(sprintf('USE `%s`', TAXI_DB_NAME));

    // Проверяем, не установлено ли уже
    $installed = false;
    try {
        $installed = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
    } catch (\Throwable) {
    }

    $sql = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
    if ($sql === false) {
        throw new \RuntimeException('sql/schema.sql не найден');
    }
    $schemaStatements = install_sql_statements($sql);
    if (!$schemaStatements) {
        throw new \RuntimeException('schema.sql пуст или не содержит SQL-выражений');
    }
    foreach ($schemaStatements as $index => $statement) {
        try {
            $pdo->exec($statement);
        } catch (\Throwable $sqlError) {
            $preview = preg_replace('/\s+/u', ' ', mb_substr($statement, 0, 160));
            throw new \RuntimeException(sprintf(
                'SQL-выражение %d из %d (%s): %s',
                $index + 1, count($schemaStatements), $preview,
                $sqlError->getMessage()
            ), 0, $sqlError);
        }
    }

    foreach (glob(dirname(__DIR__) . '/src/*.php') as $file) {
        require_once $file;
    }
    $db = Db::pdo();
    Seed::ensure($db);

    echo json_encode([
        'ok' => true,
        'alreadyInstalled' => $installed,
        'database' => TAXI_DB_NAME,
        'next' => [
            '1) Удалите api/install.php с хостинга',
            '2) Поменяйте AUTH_SECRET и AUTH-пароли демо-аккаунтов',
            '3) Вход: /api/auth/login.php — админ +79001234567 / Admin123!',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
