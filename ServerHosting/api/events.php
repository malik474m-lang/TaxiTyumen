<?php
// GET api/events.php?since=<lastId> — лёгкий realtime-поллинг (аналог SSE для хостинга)
// Клиент хранит lastId и опрашивает каждые 2–4 секунды
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$since = (int) ($_GET['since'] ?? 0);
$rows = $db->prepare('SELECT id, type FROM events WHERE id > ? LIMIT 500');
$rows->execute([$since]);
$events = $rows->fetchAll();

$types = [];
$maxId = $since;
foreach ($events as $e) {
    $types[$e['type']] = true;
    $maxId = max($maxId, (int) $e['id']);
}
// Последний known id, если событий не было
if (!$events) {
    $last = $db->query('SELECT COALESCE(MAX(id), 0) FROM events')->fetchColumn();
    $maxId = max($maxId, (int) $last);
}

Response::json([
    'lastId' => $maxId,
    'changed' => array_keys($types),
    'serverTime' => gmdate('c'),
]);
