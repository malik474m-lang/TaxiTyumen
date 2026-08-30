<?php
// ВРЕМЕННАЯ ДИАГНОСТИКА РОУТЕРА. Активируется файлом ServerHosting/diag.ok
// (пустым). Открыть: api/diag.php?route=orders/active — выведет точную ошибку.
// После отладки УДАЛИТЕ diag.php и diag.ok!
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$marker = dirname(__DIR__) . '/diag.ok';
if (!is_file($marker)) {
    http_response_code(404);
    echo json_encode(['error' => 'Диагностика выключена: создайте пустой файл ServerHosting/diag.ok'], JSON_UNESCAPED_UNICODE);
    exit;
}

$capture = ['route' => (string) ($_GET['route'] ?? ''), 'thrown' => null, 'lastError' => null, 'php' => PHP_VERSION, 'output' => null];

register_shutdown_function(static function () use (&$capture) {
    $err = error_get_last();
    if (is_array($err) && ($capture['lastError'] === null)) {
        $capture['lastError'] = 'type ' . $err['type'] . ': ' . $err['message'] . ' в ' . $err['file'] . ':' . $err['line'];
        // Фатал: обычный вывод потерян — дублируем диагностику
        if (!headers_sent()) {
            http_response_code(500);
            echo json_encode($capture, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    }
});

ob_start();
try {
    $_GET['route'] = $capture['route'];
    // Диагностика POST-маршрутов: ?method=POST + JSON в теле запроса к diag.php
    if (isset($_GET['method'])) {
        $_SERVER['REQUEST_METHOD'] = strtoupper((string) $_GET['method']);
    }
    require __DIR__ . '/router.php'; // при успехе завершится exit — увидите обычный ответ
    $capture['output'] = ob_get_clean();
} catch (\Throwable $t) {
    $capture['thrown'] = get_class($t) . ': ' . $t->getMessage() . ' в ' . $t->getFile() . ':' . $t->getLine();
    $capture['output'] = ob_get_clean();
    http_response_code(500);
    echo json_encode($capture, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
