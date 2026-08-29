<?php
// GET  /api/access.php — разделы админки и их видимость (admin/superadmin)
// PUT  /api/access.php — изменение видимости для роли admin (только superadmin)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$claims = Guard::claims();
Guard::role($claims, 'admin');
$role = (string) $claims['role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Access::ensureTables($db);
    $rows = $db->query('SELECT section_key, visible_for_admin FROM admin_sections')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[$row['section_key']] = (bool) $row['visible_for_admin'];
    }
    $sections = [];
    foreach (Access::SECTIONS as $key => $meta) {
        $sections[] = [
            'key' => $key,
            'label' => $meta['label'],
            'superadminOnly' => $meta['superadminOnly'],
            'locked' => $meta['locked'],
            'visibleForAdmin' => $meta['superadminOnly'] ? false : ($meta['locked'] ? true : ($map[$key] ?? true)),
        ];
    }
    Response::json([
        'role' => $role,
        'sections' => $sections,
        'visibleForMe' => Access::visibleSections($db, $role),
        'integrity' => Access::integrity($db),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    Guard::superadmin($claims);
    $body = Response::requirePostJson();
    $enabled = array_values(array_filter((array) ($body['visibleForAdmin'] ?? []), 'is_string'));
    Access::setVisibility($db, $enabled);
    Bus::publish('access');
    Response::json(['ok' => true, 'visibleForAdmin' => Access::visibleSections($db, 'admin')]);
}

Response::error('Метод не поддерживается', 405);
