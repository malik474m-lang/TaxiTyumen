<?php
// GET  api/options.php — публичный справочник активных опций (пульт + приложения)
// GET  api/options.php?action=all — весь справочник, включая выключенные (admin)
// POST api/options.php?action=save|create|delete — редактирование (admin)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

Options::ensureTables($db);
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

// ── Публичный список: приложения рендерят опции и цены прямо отсюда ─────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === '') {
    Response::json(array_map(
        static fn(array $o) => ['code' => $o['code'], 'name' => $o['name'], 'price' => $o['price']],
        Options::all($db, true)
    ));
}

// ── Дальше только администрирование ─────────────────────────────────────────
$claims = Guard::claims();
Guard::role($claims, 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'all') {
    Response::json(Options::all($db, false));
}

Response::requireMethod('POST');
$body = Response::requirePostJson();

if ($action === 'save') {
    $code = (string) ($body['code'] ?? '');
    $name = mb_substr(trim((string) ($body['name'] ?? '')), 0, 120);
    if ($name === '') Response::error('Укажите название опции');
    Options::update($db, $code, $name, max(0.0, (float) ($body['price'] ?? 0)), !empty($body['isActive']));
    Response::json(['ok' => true]);
}

if ($action === 'create') {
    $code = strtolower(trim((string) ($body['code'] ?? '')));
    if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code)) {
        Response::error('Код опции: латиница, цифры и _, начинается с буквы');
    }
    $name = mb_substr(trim((string) ($body['name'] ?? '')), 0, 120);
    if ($name === '') Response::error('Укажите название опции');
    Options::create($db, $code, $name, max(0.0, (float) ($body['price'] ?? 0)));
    Response::json(['ok' => true, 'code' => $code], 201);
}

if ($action === 'delete') {
    Options::delete($db, (string) ($body['code'] ?? ''));
    Response::json(['ok' => true]);
}

Response::error('Неизвестный action');
