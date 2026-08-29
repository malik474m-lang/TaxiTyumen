<?php
// Дешёвый realtime для shared-хостинга: события в таблице,
// клиенты поллят api/events.php?since=<lastId> (аналог SSE/SigbalR — без воркеров)
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Bus
{
    public static function publish(string $type): void
    {
        try {
            Db::pdo()->prepare('INSERT INTO events (id, type) VALUES (NULL, ?)')
                ->execute([$type]);
        } catch (\Throwable) {
        }
    }
}
