<?php
// ВРЕМЕННАЯ ДИАГНОСТИКА: поймает Throwable по выбранному route (включается ServerHosting/diag.ok)
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$marker = dirname(__DIR__) . '/diag.ok';
if (!is_file($marker)) {
    http_response_code(404);
    echo json_encode(['error' => 'Выключено: создайте пустой файл ServerHosting/diag.ok'], JSON_UNESCAPED_UNICODE);
    exit;
}
$route = (string) ($_GET['route'] ?? '');
$capture = ['route' => $route, 'thrown' => null, 'lastError' => null];
register_shutdown_function(static function () use (&$capture) {
    $err = error_get_last();
    if (is_array($err) && $capture['lastError'] === null) {
        $capture['lastError'] = 'type ' . $err['type'] . ': ' . $err['message'] . ' в ' . $err['file'] . ':' . $err['line'];
        if (!headers_sent()) {
            http_response_code(500);
            echo json_encode($capture, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    }
});
ob_start();
try {
    $_GET['route'] = $route;
    if (isset($_GET['method'])) $_SERVER['REQUEST_METHOD'] = strtoupper((string) $_GET['method']);
    require __DIR__ . '/router.php';
    ob_end_clean();
} catch (\Throwable $t) {
    $capture['thrown'] = get_class($t) . ': ' . $t->getMessage() . ' в ' . $t->getFile() . ':' . $t->getLine();
    ob_end_clean();
    http_response_code(500);
    echo json_encode($capture, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
