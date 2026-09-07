<?php
// Справочник клиентов: автосохранение при заказе и подстановка имени по телефону.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class ClientDirectory
{
    /**
     * Находит клиента по телефону или создаёт нового.
     * Возвращает id пользователя-клиента (или null, если создать не удалось).
     */
    public static function ensure(\PDO $db, string $phone, ?string $name = null): ?string
    {
        $phone = Auth::normalizePhone($phone);
        if (strlen(preg_replace('/\D/', '', $phone)) < 11) {
            return null;
        }

        try {
            $variants = self::phoneVariants($phone);
            $in = implode(',', array_fill(0, count($variants), '?'));
            // Ищем по всем форматам телефона: иначе старый аккаунт с «сырым»
            // номером (8… / 7…) не находился и создавался дубликат клиента.
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE phone IN ($in) LIMIT 1");
            $stmt->execute($variants);
            $existing = $stmt->fetch();

            if ($existing) {
                // Имя уточняем, только если раньше его не знали, а оператор ввёл настоящее.
                $currentName = trim((string) $existing['first_name']);
                $incoming = self::cleanName($name);
                if ($incoming !== null && ($currentName === '' || $currentName === 'Клиент')) {
                    $db->prepare('UPDATE users SET first_name = ? WHERE id = ?')
                        ->execute([$incoming, $existing['id']]);
                }
                return (string) $existing['id'];
            }

            // Нового клиента заводим без пароля: вход возможен по SMS-коду.
            $id = Db::uuid();
            $db->prepare(
                'INSERT INTO users (id, phone, first_name, last_name, password_hash, role, is_active, created_at)
                 VALUES (?,?,?,?,?,?,1,?)'
            )->execute([
                $id,
                $phone,
                self::cleanName($name) ?? 'Клиент',
                '',
                Auth::hashPassword(bin2hex(random_bytes(8))),
                'client',
                Db::utcNow(),
            ]);
            return $id;
        } catch (\Throwable $e) {
            error_log('[ClientDirectory] ensure: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Телефон во всех форматах, в которых он мог попасть в базу:
     * нормализованный +7…, а также «сырые» 8… и 7… из старых заказов.
     *
     * @return string[]
     */
    private static function phoneVariants(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone);
        $variants = [$phone];
        if (strlen($digits) === 11) {
            $tail = substr($digits, -10);
            $variants[] = '+7' . $tail;
            $variants[] = '8' . $tail;
            $variants[] = '7' . $tail;
        }
        return array_values(array_unique($variants));
    }

    /** Данные клиента по телефону для автоподстановки в форму оператора. */
    public static function lookup(\PDO $db, string $phone): ?array
    {
        $phone = Auth::normalizePhone($phone);
        if (strlen(preg_replace('/\D/', '', $phone)) < 11) {
            return null;
        }
        $variants = self::phoneVariants($phone);
        $in = implode(',', array_fill(0, count($variants), '?'));

        try {
            // 1) Аккаунт клиента: ищем по всем форматам телефона.
            $stmt = $db->prepare(
                "SELECT id, phone, first_name, last_name, is_blocked
                 FROM users WHERE phone IN ($in) AND role = 'client'
                 ORDER BY created_at ASC LIMIT 1"
            );
            $stmt->execute($variants);
            $user = $stmt->fetch();

            if (!$user) {
                // 2) Аккаунта нет (заказы заводились до справочника):
                //    ищем клиента по телефону в истории заказов.
                $fromOrders = $db->prepare(
                    "SELECT client_id, client_name FROM orders
                     WHERE client_phone IN ($in) AND client_phone IS NOT NULL AND client_phone <> ''
                     ORDER BY created_at DESC LIMIT 1"
                );
                $fromOrders->execute($variants);
                $orderRow = $fromOrders->fetch();
                if (!$orderRow) {
                    return null;
                }

                // Лениво дозаводим аккаунт, чтобы следующие заказы шли по справочнику.
                if (!empty($orderRow['client_id'])) {
                    $uid = $db->prepare('SELECT id, phone, first_name, last_name, is_blocked FROM users WHERE id = ? LIMIT 1');
                    $uid->execute([(string) $orderRow['client_id']]);
                    $user = $uid->fetch() ?: null;
                }
                if (!$user) {
                    $newId = self::ensure($db, $phone, (string) ($orderRow['client_name'] ?? 'Клиент'));
                    if ($newId === null) {
                        return null;
                    }
                    $uid = $db->prepare('SELECT id, phone, first_name, last_name, is_blocked FROM users WHERE id = ? LIMIT 1');
                    $uid->execute([$newId]);
                    $user = $uid->fetch() ?: null;
                    if (!$user) {
                        return null;
                    }
                }
            }

            // Последний адрес подачи — частая подсказка для повторного заказа.
            $last = $db->prepare(
                "SELECT pickup_address, destination_address, pickup_entrance
                 FROM orders WHERE client_id = ? OR client_phone IN ($in)
                 ORDER BY created_at DESC LIMIT 1"
            );
            $last->execute(array_merge([(string) $user['id']], $variants));
            $lastOrder = $last->fetch() ?: [];

            $trips = $db->prepare(
                "SELECT COUNT(*) FROM orders
                 WHERE (client_id = ? OR client_phone IN ($in)) AND status = 'completed'"
            );
            $trips->execute(array_merge([(string) $user['id']], $variants));

            return [
                'found' => true,
                'clientId' => (string) $user['id'],
                'phone' => (string) $user['phone'],
                'name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
                'firstName' => (string) $user['first_name'],
                'isBlocked' => (bool) $user['is_blocked'],
                'completedTrips' => (int) $trips->fetchColumn(),
                'lastPickupAddress' => $lastOrder['pickup_address'] ?? null,
                'lastPickupEntrance' => $lastOrder['pickup_entrance'] ?? null,
                'lastDestinationAddress' => $lastOrder['destination_address'] ?? null,
            ];
        } catch (\Throwable $e) {
            error_log('[ClientDirectory] lookup: ' . $e->getMessage());
            return null;
        }
    }

    private static function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '' || mb_strtolower($name) === 'клиент') {
            return null;
        }
        return mb_substr($name, 0, 60);
    }
}
