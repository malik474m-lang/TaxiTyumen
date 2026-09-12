<?php
// Платёжный контур Сбер: приём карт/СБП C2B, сохранённые карты (binding),
// внутренний счёт системы, безналичный кошелёк водителя и заявки на вывод.
//
// ВАЖНО: автоматические B2C-выплаты по СБП намеренно НЕ реализованы — банк
// пока одобрил только приём платежей. Заявки водителей подтверждаются вручную
// администратором после перевода и получения чека самозанятого.
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class SberPayments
{
    public const TEST_URL = 'https://3dsec.sberbank.ru/payment/rest';
    public const PROD_URL = 'https://securepayments.sberbank.ru/payment/rest';

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS sber_settings (
                id                 TINYINT PRIMARY KEY DEFAULT 1,
                enabled            TINYINT(1) NOT NULL DEFAULT 0,
                test_mode          TINYINT(1) NOT NULL DEFAULT 1,
                user_name          VARCHAR(120) NOT NULL DEFAULT '',
                api_password       VARCHAR(255) NOT NULL DEFAULT '',
                recurring_enabled  TINYINT(1) NOT NULL DEFAULT 0,
                sbp_enabled        TINYINT(1) NOT NULL DEFAULT 0,
                company_name       VARCHAR(160) NOT NULL DEFAULT '',
                updated_at         DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec('INSERT IGNORE INTO sber_settings (id) VALUES (1)');

        $db->exec(
            "CREATE TABLE IF NOT EXISTS sber_payments (
                id                  CHAR(36) PRIMARY KEY,
                purpose             ENUM('order','driver_topup','card_binding') NOT NULL,
                order_id            CHAR(36) NULL,
                client_id           CHAR(36) NULL,
                driver_id           CHAR(36) NULL,
                local_order_number  VARCHAR(32) NOT NULL UNIQUE,
                sber_order_id       VARCHAR(80) NULL UNIQUE,
                amount              DECIMAL(12,2) NOT NULL,
                status              ENUM('created','pending','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'created',
                payment_method      ENUM('card','sbp','binding') NOT NULL DEFAULT 'card',
                form_url            VARCHAR(1000) NULL,
                binding_id          VARCHAR(255) NULL,
                masked_pan          VARCHAR(32) NULL,
                error               VARCHAR(1000) NULL,
                raw_response        MEDIUMTEXT NULL,
                credited_at         DATETIME NULL,
                created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                paid_at             DATETIME NULL,
                updated_at          DATETIME NULL,
                INDEX (order_id), INDEX (client_id), INDEX (driver_id), INDEX (status), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS client_payment_bindings (
                id              CHAR(36) PRIMARY KEY,
                client_id       CHAR(36) NOT NULL,
                binding_id      VARCHAR(255) NOT NULL UNIQUE,
                masked_pan      VARCHAR(32) NULL,
                expiry          VARCHAR(12) NULL,
                label           VARCHAR(80) NULL,
                is_active       TINYINT(1) NOT NULL DEFAULT 1,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NULL,
                INDEX (client_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS driver_wallets (
                driver_id              CHAR(36) PRIMARY KEY,
                cashless_balance       DECIMAL(12,2) NOT NULL DEFAULT 0,
                pending_withdrawal     DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_cashless_earned  DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_paid_out         DECIMAL(12,2) NOT NULL DEFAULT 0,
                updated_at             DATETIME NULL,
                FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS system_ledger (
                id           CHAR(36) PRIMARY KEY,
                payment_id   CHAR(36) NULL,
                order_id     CHAR(36) NULL,
                driver_id    CHAR(36) NULL,
                type         ENUM('payment_received','driver_credit','commission_income','driver_topup','withdrawal','refund','adjustment') NOT NULL,
                amount       DECIMAL(12,2) NOT NULL,
                description  VARCHAR(255) NOT NULL DEFAULT '',
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_payment_type (payment_id, type),
                INDEX (order_id), INDEX (driver_id), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS driver_withdrawal_requests (
                id                    CHAR(36) PRIMARY KEY,
                driver_id             CHAR(36) NOT NULL,
                amount                DECIMAL(12,2) NOT NULL,
                sbp_phone             VARCHAR(30) NOT NULL,
                bank_name             VARCHAR(120) NOT NULL,
                self_employed_inn     VARCHAR(12) NOT NULL,
                status                ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
                admin_comment         VARCHAR(500) NULL,
                self_employed_receipt VARCHAR(255) NULL,
                created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at          DATETIME NULL,
                processed_by          CHAR(36) NULL,
                FOREIGN KEY (driver_id) REFERENCES drivers(id),
                INDEX (status), INDEX (driver_id), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function settings(\PDO $db): array
    {
        self::ensureTables($db);
        $s = $db->query('SELECT * FROM sber_settings WHERE id=1')->fetch() ?: [];
        // Секреты из окружения/config.local.php имеют приоритет над БД
        $envUser = defined('SBER_USERNAME') ? (string) SBER_USERNAME : (getenv('SBER_USERNAME') ?: '');
        $envPass = defined('SBER_PASSWORD') ? (string) SBER_PASSWORD : (getenv('SBER_PASSWORD') ?: '');
        if ($envUser !== '') $s['user_name'] = $envUser;
        if ($envPass !== '') $s['api_password'] = $envPass;
        $s['enabled'] = (bool) ($s['enabled'] ?? false);
        $s['test_mode'] = (bool) ($s['test_mode'] ?? true);
        $s['recurring_enabled'] = (bool) ($s['recurring_enabled'] ?? false);
        $s['sbp_enabled'] = (bool) ($s['sbp_enabled'] ?? false);
        $s['configured'] = $s['enabled']
            && trim((string) ($s['user_name'] ?? '')) !== ''
            && trim((string) ($s['api_password'] ?? '')) !== '';
        $s['base_url'] = $s['test_mode'] ? self::TEST_URL : self::PROD_URL;
        return $s;
    }

    public static function saveSettings(\PDO $db, array $data): void
    {
        self::ensureTables($db);
        // Берём сохранённый пароль напрямую из БД: секрет из окружения
        // не копируем обратно в таблицу при сохранении формы.
        $currentPassword = (string) ($db->query('SELECT api_password FROM sber_settings WHERE id=1')->fetchColumn() ?: '');
        $password = trim((string) ($data['api_password'] ?? ''));
        if ($password === '') $password = $currentPassword;
        $db->prepare(
            'UPDATE sber_settings SET enabled=?,test_mode=?,user_name=?,api_password=?,
             recurring_enabled=?,sbp_enabled=?,company_name=?,updated_at=? WHERE id=1'
        )->execute([
            !empty($data['enabled']) ? 1 : 0,
            !empty($data['test_mode']) ? 1 : 0,
            mb_substr(trim((string) ($data['user_name'] ?? '')), 0, 120),
            mb_substr($password, 0, 255),
            !empty($data['recurring_enabled']) ? 1 : 0,
            !empty($data['sbp_enabled']) ? 1 : 0,
            mb_substr(trim((string) ($data['company_name'] ?? '')), 0, 160),
            Db::utcNow(),
        ]);
    }

    /** Публичная конфигурация без секретов. */
    public static function publicConfig(\PDO $db, ?string $clientId = null): array
    {
        $s = self::settings($db);
        $bindings = [];
        if ($clientId && $s['recurring_enabled']) {
            $stmt = $db->prepare(
                'SELECT id,masked_pan,expiry,label FROM client_payment_bindings
                 WHERE client_id=? AND is_active=1 ORDER BY created_at DESC'
            );
            $stmt->execute([$clientId]);
            $bindings = array_map(fn($b) => [
                'id' => $b['id'], 'maskedPan' => $b['masked_pan'],
                'expiry' => $b['expiry'], 'label' => $b['label'],
            ], $stmt->fetchAll());
        }
        return [
            'enabled' => (bool) $s['configured'],
            'testMode' => (bool) $s['test_mode'],
            'recurringEnabled' => (bool) $s['recurring_enabled'],
            'sbpEnabled' => (bool) $s['sbp_enabled'],
            'bindings' => $bindings,
        ];
    }

    private static function authParams(array $s): array
    {
        return ['userName' => $s['user_name'], 'password' => $s['api_password']];
    }

    /** HTTP POST form-urlencoded к REST-шлюзу Сбера. */
    private static function request(\PDO $db, string $method, array $params): array
    {
        $s = self::settings($db);
        if (!$s['configured']) throw new \RuntimeException('Эквайринг Сбера не настроен или выключен');
        $url = rtrim((string) $s['base_url'], '/') . '/' . $method;
        $payload = http_build_query(array_merge(self::authParams($s), $params));
        $started = microtime(true);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 20, 'ignore_errors' => true,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                . "Accept: application/json\r\nUser-Agent: TaxiTyumen/1.0\r\n",
            'content' => $payload,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $http = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $h, $m)) $http = (int) $m[1];
        }
        $json = $raw !== false ? json_decode($raw, true) : null;
        try {
            $db->prepare(
                'INSERT INTO service_call_logs(service,action,request_summary,status,http_code,response_body,duration_ms)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                'sber-acquiring', $method,
                'order=' . ($params['orderNumber'] ?? $params['orderId'] ?? ''),
                is_array($json) && empty($json['errorCode']) ? 'success' : 'failed',
                $http, mb_substr((string) $raw, 0, 5000),
                (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable) {
        }
        if (!is_array($json)) throw new \RuntimeException('Сбер не ответил: HTTP ' . $http);
        return $json;
    }

    private static function localNumber(string $prefix): string
    {
        return strtoupper($prefix) . gmdate('ymdHis') . substr(str_replace('-', '', Db::uuid()), 0, 8);
    }

    /** Регистрация оплаты на платёжной странице Сбера (register.do). */
    public static function register(
        \PDO $db, string $purpose, float $amount, ?string $orderId,
        ?string $clientId, ?string $driverId, string $method = 'card'
    ): array {
        self::ensureTables($db);
        if ($amount < 1) throw new \RuntimeException('Сумма должна быть не менее 1 ₽');
        $s = self::settings($db);
        if (!$s['configured']) throw new \RuntimeException('Платежи Сбера пока не подключены');
        if ($method === 'sbp' && !$s['sbp_enabled']) {
            throw new \RuntimeException('Приём по СБП ещё не включён в договоре Сбера');
        }

        $id = Db::uuid();
        $prefix = $purpose === 'driver_topup' ? 'TOP' : ($purpose === 'card_binding' ? 'BND' : 'TRP');
        $number = self::localNumber($prefix);
        $db->prepare(
            'INSERT INTO sber_payments
             (id,purpose,order_id,client_id,driver_id,local_order_number,amount,status,payment_method)
             VALUES (?,?,?,?,?,?,?,\'created\',?)'
        )->execute([$id, $purpose, $orderId, $clientId, $driverId, $number, round($amount, 2), $method]);

        $return = rtrim(PUBLIC_BASE_URL, '/') . '/api/sber-return.php?payment=' . rawurlencode($id) . '&result=success';
        $fail = rtrim(PUBLIC_BASE_URL, '/') . '/api/sber-return.php?payment=' . rawurlencode($id) . '&result=fail';
        $description = match ($purpose) {
            'driver_topup' => 'Пополнение баланса водителя',
            'card_binding' => 'Привязка карты клиента',
            default => 'Оплата поездки такси',
        };
        $params = [
            'orderNumber' => $number,
            'amount' => (int) round($amount * 100),
            'currency' => 643,
            'returnUrl' => $return,
            'failUrl' => $fail,
            'language' => 'ru',
            'pageView' => 'MOBILE',
            'description' => $description,
        ];
        // clientId включает сценарий связок на стороне Сбера, только если банк его разрешил
        if ($clientId && $s['recurring_enabled']) $params['clientId'] = $clientId;

        try {
            $response = self::request($db, 'register.do', $params);
            $error = (string) ($response['errorMessage'] ?? '');
            if (!empty($response['errorCode']) || empty($response['orderId']) || empty($response['formUrl'])) {
                throw new \RuntimeException($error ?: 'Сбер не зарегистрировал платёж');
            }
            $db->prepare(
                "UPDATE sber_payments SET sber_order_id=?,form_url=?,status='pending',raw_response=?,updated_at=? WHERE id=?"
            )->execute([
                $response['orderId'], $response['formUrl'],
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                Db::utcNow(), $id,
            ]);
            return ['id' => $id, 'status' => 'pending', 'formUrl' => $response['formUrl']];
        } catch (\Throwable $e) {
            $db->prepare("UPDATE sber_payments SET status='failed',error=?,updated_at=? WHERE id=?")
                ->execute([mb_substr($e->getMessage(), 0, 1000), Db::utcNow(), $id]);
            throw $e;
        }
    }

    /** Получить статус в Сбере и провести деньги во внутреннем учёте один раз. */
    public static function reconcile(\PDO $db, string $paymentId): array
    {
        self::ensureTables($db);
        $stmt = $db->prepare('SELECT * FROM sber_payments WHERE id=? LIMIT 1');
        $stmt->execute([$paymentId]);
        $p = $stmt->fetch();
        if (!$p) throw new \RuntimeException('Платёж не найден');
        if ($p['status'] === 'paid') return $p;
        if (!$p['sber_order_id']) return $p;

        $response = self::request($db, 'getOrderStatusExtended.do', ['orderId' => $p['sber_order_id']]);
        $orderStatus = isset($response['orderStatus']) ? (int) $response['orderStatus'] : -1;
        $status = match ($orderStatus) {
            // Используется одностадийный register.do: оплата подтверждена
            // только статусом 2. Статус 1 означает лишь холд, не доход.
            2 => 'paid',
            1 => 'pending',
            3 => 'cancelled',
            4 => 'refunded',
            default => (!empty($response['errorCode']) ? 'failed' : 'pending'),
        };
        $binding = (string) ($response['bindingInfo']['bindingId'] ?? $response['bindingId'] ?? '');
        $masked = (string) ($response['cardAuthInfo']['maskedPan'] ?? '');
        $db->prepare(
            'UPDATE sber_payments SET status=?,binding_id=?,masked_pan=?,raw_response=?,error=?,
             paid_at=IF(?=\'paid\',COALESCE(paid_at,?),paid_at),updated_at=? WHERE id=?'
        )->execute([
            $status, $binding ?: null, $masked ?: null,
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $response['errorMessage'] ?? null,
            $status, Db::utcNow(), Db::utcNow(), $paymentId,
        ]);
        if ($status === 'paid') self::credit($db, $paymentId, $binding, $masked);
        $stmt->execute([$paymentId]);
        return $stmt->fetch();
    }

    /** Финансовое проведение подтверждённого платежа, идемпотентно. */
    private static function credit(\PDO $db, string $paymentId, string $binding, string $masked): void
    {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM sber_payments WHERE id=? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $p = $stmt->fetch();
            if (!$p || $p['credited_at'] !== null) { $db->commit(); return; }

            $amount = (float) $p['amount'];
            self::ledger($db, $paymentId, $p['order_id'], $p['driver_id'],
                'payment_received', $amount, 'Поступление через Сбер');

            if ($p['purpose'] === 'driver_topup' && $p['driver_id']) {
                $d = $db->prepare('SELECT balance FROM drivers WHERE id=? FOR UPDATE');
                $d->execute([$p['driver_id']]);
                $balance = (float) ($d->fetchColumn() ?: 0) + $amount;
                $db->prepare('UPDATE drivers SET balance=? WHERE id=?')->execute([$balance, $p['driver_id']]);
                $db->prepare(
                    "INSERT INTO balance_transactions(id,driver_id,type,amount,balance_after,description,created_by)
                     VALUES (?,?,'topup',?,?,?,?)"
                )->execute([Db::uuid(), $p['driver_id'], $amount, $balance, 'Пополнение через Сбер/СБП', 'sber']);
                self::ledger($db, $paymentId, null, $p['driver_id'], 'driver_topup', $amount,
                    'Пополнение рабочего баланса водителя');
            }

            if ($p['purpose'] === 'order' && $p['order_id']) {
                $o = $db->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
                $o->execute([$p['order_id']]);
                $order = $o->fetch();
                if ($order && $order['driver_id']) {
                    $tf = $db->prepare('SELECT commission_percent FROM tariffs WHERE type=? LIMIT 1');
                    $tf->execute([$order['tariff']]);
                    $percent = (float) ($tf->fetchColumn() ?: 15);
                    $commission = round($amount * $percent / 100, 2);
                    $driverNet = round($amount - $commission, 2);
                    self::ensureWallet($db, $order['driver_id']);
                    $db->prepare(
                        'UPDATE driver_wallets SET cashless_balance=cashless_balance+?,
                         total_cashless_earned=total_cashless_earned+?,updated_at=? WHERE driver_id=?'
                    )->execute([$driverNet, $driverNet, Db::utcNow(), $order['driver_id']]);
                    self::ledger($db, $paymentId, $order['id'], $order['driver_id'],
                        'driver_credit', $driverNet, 'Безналичный заработок водителя');
                    self::ledger($db, $paymentId, $order['id'], $order['driver_id'],
                        'commission_income', $commission, 'Комиссия системы');
                    $db->prepare(
                        "UPDATE transactions SET status='completed',external_transaction_id=?,completed_at=? WHERE order_id=?"
                    )->execute([$p['sber_order_id'], Db::utcNow(), $order['id']]);
                }
            }

            if ($binding !== '' && $p['client_id']) {
                $db->prepare(
                    'INSERT INTO client_payment_bindings(id,client_id,binding_id,masked_pan,label)
                     VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE masked_pan=VALUES(masked_pan),is_active=1,updated_at=?'
                )->execute([
                    Db::uuid(), $p['client_id'], $binding, $masked ?: null,
                    $masked ? 'Карта ' . $masked : 'Привязанная карта', Db::utcNow(),
                ]);
            }
            $db->prepare('UPDATE sber_payments SET credited_at=? WHERE id=?')
                ->execute([Db::utcNow(), $paymentId]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function ledger(\PDO $db, ?string $paymentId, ?string $orderId, ?string $driverId,
        string $type, float $amount, string $description): void
    {
        $db->prepare(
            'INSERT IGNORE INTO system_ledger(id,payment_id,order_id,driver_id,type,amount,description)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([Db::uuid(), $paymentId, $orderId, $driverId, $type, $amount, $description]);
    }

    public static function ensureWallet(\PDO $db, string $driverId): void
    {
        $db->prepare('INSERT IGNORE INTO driver_wallets(driver_id) VALUES (?)')->execute([$driverId]);
    }

    public static function wallet(\PDO $db, string $driverId): array
    {
        self::ensureTables($db); self::ensureWallet($db, $driverId);
        $stmt = $db->prepare('SELECT * FROM driver_wallets WHERE driver_id=?');
        $stmt->execute([$driverId]);
        return $stmt->fetch() ?: [];
    }

    /** Создать заявку самозанятого водителя на ручной вывод по СБП. */
    public static function requestWithdrawal(\PDO $db, string $driverId, float $amount,
        string $phone, string $bank, string $inn): string
    {
        self::ensureTables($db);
        if ($amount < 100) throw new \RuntimeException('Минимальная сумма вывода — 100 ₽');
        $normalizedPhone = Auth::normalizePhone($phone);
        if (strlen($normalizedPhone) < 11) throw new \RuntimeException('Укажите корректный телефон СБП');
        if (trim($bank) === '') throw new \RuntimeException('Укажите банк получателя');
        if (!preg_match('/^\d{12}$/', $inn)) throw new \RuntimeException('ИНН самозанятого должен содержать 12 цифр');
        $db->beginTransaction();
        try {
            self::ensureWallet($db, $driverId);
            $stmt = $db->prepare('SELECT * FROM driver_wallets WHERE driver_id=? FOR UPDATE');
            $stmt->execute([$driverId]);
            $wallet = $stmt->fetch();
            if ((float) $wallet['cashless_balance'] < $amount) {
                throw new \RuntimeException('Недостаточно средств в безналичном кошельке');
            }
            $id = Db::uuid();
            $db->prepare(
                "INSERT INTO driver_withdrawal_requests
                 (id,driver_id,amount,sbp_phone,bank_name,self_employed_inn,status)
                 VALUES (?,?,?,?,?,?,'pending')"
            )->execute([$id, $driverId, $amount, $normalizedPhone, mb_substr($bank, 0, 120), $inn]);
            $db->prepare(
                'UPDATE driver_wallets SET cashless_balance=cashless_balance-?,
                 pending_withdrawal=pending_withdrawal+?,updated_at=? WHERE driver_id=?'
            )->execute([$amount, $amount, Db::utcNow(), $driverId]);
            $db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Ручное решение администратора по заявке (пока B2C API не одобрен). */
    public static function processWithdrawal(\PDO $db, string $id, string $status,
        string $adminId, string $comment = '', string $receipt = ''): void
    {
        if (!in_array($status, ['paid', 'rejected'], true)) throw new \RuntimeException('Неверный статус выплаты');
        if ($status === 'paid' && trim($receipt) === '') {
            throw new \RuntimeException('Для выплаты самозанятому укажите номер чека');
        }
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM driver_withdrawal_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $r = $stmt->fetch();
            if (!$r || $r['status'] !== 'pending') throw new \RuntimeException('Заявка уже обработана или не найдена');
            $amount = (float) $r['amount'];
            self::ensureWallet($db, $r['driver_id']);
            if ($status === 'paid') {
                $db->prepare(
                    'UPDATE driver_wallets SET pending_withdrawal=pending_withdrawal-?,
                     total_paid_out=total_paid_out+?,updated_at=? WHERE driver_id=?'
                )->execute([$amount, $amount, Db::utcNow(), $r['driver_id']]);
                self::ledger($db, null, null, $r['driver_id'], 'withdrawal', -$amount,
                    'Ручная выплата самозанятому по СБП');
            } else {
                $db->prepare(
                    'UPDATE driver_wallets SET pending_withdrawal=pending_withdrawal-?,
                     cashless_balance=cashless_balance+?,updated_at=? WHERE driver_id=?'
                )->execute([$amount, $amount, Db::utcNow(), $r['driver_id']]);
            }
            $db->prepare(
                'UPDATE driver_withdrawal_requests SET status=?,admin_comment=?,
                 self_employed_receipt=?,processed_at=?,processed_by=? WHERE id=?'
            )->execute([$status, mb_substr($comment, 0, 500), mb_substr($receipt, 0, 255) ?: null,
                Db::utcNow(), $adminId, $id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
