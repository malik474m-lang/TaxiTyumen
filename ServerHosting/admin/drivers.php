<?php
// Водители — порт TaxiAdmin/Drivers.razor:
// добавление, авто/ВУ, баланс, штраф, реквизиты оплаты, верификация, блокировка.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'drivers');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = (string) ($_POST['cmd'] ?? '');
    $id = (string) ($_POST['id'] ?? '');

    try {
        if ($cmd === 'add') {
            $phone = Auth::normalizePhone((string) ($_POST['phone'] ?? ''));
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $brand = trim((string) ($_POST['car_brand'] ?? ''));
            $model = trim((string) ($_POST['car_model'] ?? ''));
            $plate = mb_strtoupper(trim((string) ($_POST['license_plate'] ?? '')));

            if (strlen(preg_replace('/\D/', '', $phone)) < 11 || $firstName === '' || $brand === '' || $model === '' || $plate === '') {
                throw new RuntimeException('Заполните телефон, имя, марку, модель и госномер');
            }
            if (strlen($password) < 6) {
                throw new RuntimeException('Пароль — минимум 6 символов');
            }
            $exists = $db->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
            $exists->execute([$phone]);
            if ($exists->fetch()) {
                throw new RuntimeException('Пользователь с таким телефоном уже существует');
            }

            $uid = Db::uuid();
            $driverId = Db::uuid();
            $initialBalance = max(0, (float) ($_POST['balance'] ?? 0));

            $db->beginTransaction();
            $db->prepare(
                "INSERT INTO users (id, phone, first_name, last_name, password_hash, role, is_phone_verified)
                 VALUES (?,?,?,?,?,'driver',1)"
            )->execute([$uid, $phone, $firstName, $lastName, Auth::hashPassword($password)]);

            $db->prepare(
                "INSERT INTO drivers
                 (id, user_id, car_brand, car_model, car_color, license_plate, car_year,
                   driver_license, license_expiry, is_verified, verified_at, status, latitude, longitude, balance,
                   min_balance_for_orders, rejection_penalty, payment_phone, payment_bank_name,
                   payment_card_holder, accept_card_transfer, accept_sbp, last_location_update)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,'offline',?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $driverId, $uid, $brand, $model,
                trim((string) ($_POST['car_color'] ?? 'Белый')) ?: 'Белый',
                $plate,
                max(1980, min(2100, (int) ($_POST['car_year'] ?? date('Y')))),
                trim((string) ($_POST['driver_license'] ?? '')),
                !empty($_POST['license_expiry'])
                    ? $_POST['license_expiry'] . ' 23:59:59'
                    : gmdate('Y-m-d H:i:s', time() + 5 * 365 * 86400),
                !empty($_POST['is_verified']) ? 1 : 0,
                !empty($_POST['is_verified']) ? Db::utcNow() : null,
                Taxi::CITY_LAT, Taxi::CITY_LNG,
                $initialBalance,
                max(0, (float) ($_POST['min_balance_for_orders'] ?? 100)),
                max(0, (float) ($_POST['rejection_penalty'] ?? 0)),
                trim((string) ($_POST['payment_phone'] ?? '')) ?: null,
                trim((string) ($_POST['payment_bank_name'] ?? '')) ?: null,
                trim((string) ($_POST['payment_card_holder'] ?? '')) ?: null,
                !empty($_POST['accept_card_transfer']) ? 1 : 0,
                !empty($_POST['accept_sbp']) ? 1 : 0,
                Db::utcNow(),
            ]);

            if ($initialBalance > 0) {
                $db->prepare(
                    "INSERT INTO balance_transactions
                     (id, driver_id, type, amount, balance_after, description, created_by)
                     VALUES (?,?,'topup',?,?,?,?)"
                )->execute([
                    Db::uuid(), $driverId, $initialBalance, $initialBalance,
                    'Стартовый баланс', 'admin: ' . $admin['first_name'],
                ]);
            }
            $db->commit();
            Bus::publish('drivers');
            header('Location: drivers.php?ok=' . urlencode('Водитель добавлен: ' . $firstName . ' ' . $lastName));
            exit;
        }

        $d = $db->prepare(
            'SELECT d.*, u.id AS uid, u.is_blocked FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=? LIMIT 1'
        );
        $d->execute([$id]);
        $driver = $d->fetch();
        if (!$driver) {
            throw new RuntimeException('Водитель не найден');
        }

        if ($cmd === 'topup') {
            $amount = (float) ($_POST['amount'] ?? 0);
            if ($amount <= 0) {
                throw new RuntimeException('Сумма пополнения должна быть больше нуля');
            }
            $newBalance = (float) $driver['balance'] + $amount;
            $db->prepare('UPDATE drivers SET balance=? WHERE id=?')->execute([$newBalance, $id]);
            $db->prepare(
                "INSERT INTO balance_transactions
                 (id, driver_id, type, amount, balance_after, description, created_by)
                 VALUES (?,?,'topup',?,?,?,?)"
            )->execute([
                Db::uuid(), $id, $amount, $newBalance,
                sprintf('Пополнение +%.0f руб.', $amount), 'admin: ' . $admin['first_name'],
            ]);
            Bus::publish('drivers');
            header('Location: drivers.php?ok=' . urlencode('Баланс пополнен: ' . round($newBalance) . ' ₽'));
            exit;
        }

        if ($cmd === 'verify') {
            $verified = $driver['is_verified'] ? 0 : 1;
            $db->prepare('UPDATE drivers SET is_verified=?,verified_at=? WHERE id=?')
                ->execute([$verified, $verified ? Db::utcNow() : null, $id]);
            Bus::publish('drivers');
            header('Location: drivers.php?ok=' . urlencode('Верификация обновлена'));
            exit;
        }

        if ($cmd === 'block') {
            $blocked = $driver['is_blocked'] ? 0 : 1;
            $db->prepare('UPDATE users SET is_blocked=?, block_reason=? WHERE id=?')
                ->execute([$blocked, $blocked ? 'Заблокирован администратором' : null, $driver['uid']]);
            if ($blocked) {
                $db->prepare("UPDATE drivers SET status='offline', current_order_id=NULL WHERE id=?")
                    ->execute([$id]);
            }
            Bus::publish('drivers');
            header('Location: drivers.php?ok=' . urlencode($blocked ? 'Водитель заблокирован' : 'Водитель разблокирован'));
            exit;
        }

        if ($cmd === 'delete') {
            if (!empty($driver['current_order_id'])) {
                throw new RuntimeException('Нельзя удалить водителя с активным заказом');
            }
            $db->beginTransaction();
            $db->prepare('UPDATE orders SET driver_id=NULL WHERE driver_id=?')->execute([$id]);
            $db->prepare('DELETE FROM order_rejections WHERE driver_id=?')->execute([$id]);
            $db->prepare('DELETE FROM balance_transactions WHERE driver_id=?')->execute([$id]);
            $db->prepare('DELETE FROM driver_location_history WHERE driver_id=?')->execute([$id]);
            $db->prepare('DELETE FROM notifications WHERE recipient_id=?')->execute([$driver['uid']]);
            $db->prepare('DELETE FROM drivers WHERE id=?')->execute([$id]);
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$driver['uid']]);
            $db->commit();
            Bus::publish('drivers');
            header('Location: drivers.php?ok=' . urlencode('Водитель удалён'));exit;
        }

        if ($cmd === 'update') {
            $db->beginTransaction();
            $db->prepare('UPDATE users SET first_name=?, last_name=? WHERE id=?')->execute([
                trim((string) ($_POST['first_name'] ?? '')),
                trim((string) ($_POST['last_name'] ?? '')),
                $driver['uid'],
            ]);
            $db->prepare(
                'UPDATE drivers SET car_brand=?, car_model=?, car_color=?, license_plate=?, car_year=?,
                 driver_license=?, license_expiry=?, min_balance_for_orders=?, rejection_penalty=?, payment_phone=?,
                 payment_bank_name=?, payment_card_holder=?, accept_card_transfer=?, accept_sbp=? WHERE id=?'
            )->execute([
                trim((string) ($_POST['car_brand'] ?? '')),
                trim((string) ($_POST['car_model'] ?? '')),
                trim((string) ($_POST['car_color'] ?? '')),
                mb_strtoupper(trim((string) ($_POST['license_plate'] ?? ''))),
                (int) ($_POST['car_year'] ?? date('Y')),
                trim((string) ($_POST['driver_license'] ?? '')),
                !empty($_POST['license_expiry']) ? $_POST['license_expiry'] . ' 23:59:59' : null,
                max(0, (float) ($_POST['min_balance_for_orders'] ?? 100)),
                max(0, (float) ($_POST['rejection_penalty'] ?? 0)),
                trim((string) ($_POST['payment_phone'] ?? '')) ?: null,
                trim((string) ($_POST['payment_bank_name'] ?? '')) ?: null,
                trim((string) ($_POST['payment_card_holder'] ?? '')) ?: null,
                !empty($_POST['accept_card_transfer']) ? 1 : 0,
                !empty($_POST['accept_sbp']) ? 1 : 0,
                $id,
            ]);
            $db->commit();
            Bus::publish('drivers');
            header('Location: drivers.php?ok=' . urlencode('Данные водителя сохранены'));
            exit;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

$rows = $db->query(
    'SELECT d.*, u.first_name, u.last_name, u.phone, u.rating, u.is_blocked
     FROM drivers d JOIN users u ON u.id=d.user_id ORDER BY u.last_name, u.first_name'
)->fetchAll();

$statusChip = function (string $s): string {
    $cls = $s === 'offline' ? '' : ($s === 'available' ? 'ok' : 'info');
    $style = $s === 'offline' ? ' style="background:rgba(255,255,255,.07);color:#a1a1aa"' : '';
    return '<span class="chip ' . $cls . '"' . $style . '>' . h(Taxi::DRIVER_STATUS_TEXT[$s] ?? $s) . '</span>';
};

layout_header('Водители', 'drivers');
?>
<div class="flex between">
  <div><h1>Водители</h1><p class="mut"><?= count($rows) ?> профилей в системе</p></div>
  <span class="chip info">На линии: <?= count(array_filter($rows, fn($d) => $d['status'] !== 'offline')) ?></span>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?= h($error) ?></div><?php endif; ?>

<details class="card" style="margin-top:18px" <?= $error ? 'open' : '' ?>>
  <summary style="cursor:pointer;font-weight:900;font-size:16px;color:#7dd3fc">＋ Добавить водителя</summary>
  <form method="post" style="margin-top:16px">
    <input type="hidden" name="cmd" value="add">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
      <label class="mut">Телефон<input name="phone" placeholder="+79991112233" required></label>
      <label class="mut">Имя<input name="first_name" required></label>
      <label class="mut">Фамилия<input name="last_name"></label>
      <label class="mut">Пароль<input name="password" value="Driver123!" required></label>
      <label class="mut">Марка<input name="car_brand" placeholder="Kia" required></label>
      <label class="mut">Модель<input name="car_model" placeholder="Rio" required></label>
      <label class="mut">Цвет<input name="car_color" value="Белый"></label>
      <label class="mut">Госномер<input name="license_plate" placeholder="А123ВС72" required></label>
      <label class="mut">Год<input type="number" name="car_year" value="<?= date('Y') ?>"></label>
      <label class="mut">Водительское удостоверение<input name="driver_license"></label>
      <label class="mut">ВУ действует до<input type="date" name="license_expiry" value="<?= gmdate('Y-m-d', time()+5*365*86400) ?>"></label>
      <label class="mut">Стартовый баланс, ₽<input type="number" name="balance" value="500"></label>
      <label class="mut">Минимум для заказов, ₽<input type="number" name="min_balance_for_orders" value="100"></label>
      <label class="mut">Штраф за отказ, ₽<input type="number" name="rejection_penalty" value="50"></label>
      <label class="mut">Телефон выплат<input name="payment_phone"></label>
      <label class="mut">Банк<input name="payment_bank_name"></label>
      <label class="mut">Получатель<input name="payment_card_holder"></label>
    </div>
    <div class="flex" style="margin-top:14px">
      <label><input type="checkbox" name="is_verified" value="1" style="width:auto"> Сразу верифицировать</label>
      <label><input type="checkbox" name="accept_card_transfer" value="1" checked style="width:auto"> Перевод на карту</label>
      <label><input type="checkbox" name="accept_sbp" value="1" checked style="width:auto"> СБП</label>
      <button class="btn" type="submit" style="margin-left:auto">Добавить водителя</button>
    </div>
  </form>
</details>

<div class="grid q2" style="margin-top:14px">
<?php foreach ($rows as $d): $low = $d['balance'] < $d['min_balance_for_orders']; ?>
  <div class="card" style="<?= $d['is_blocked'] ? 'border-color:rgba(248,113,113,.35)' : '' ?>">
    <div class="flex between">
      <div>
        <div style="font-weight:900;font-size:16px">
          <?= h($d['first_name'] . ' ' . $d['last_name']) ?>
          <?= $d['is_verified'] ? '<span title="Верифицирован" style="color:#7dd3fc">✓</span>' : '' ?>
        </div>
        <div class="mut"><?= h($d['phone']) ?> · <?= h($d['car_color'] . ' ' . $d['car_brand'] . ' ' . $d['car_model']) ?></div>
        <span class="plate"><?= h($d['license_plate']) ?></span>
      </div>
      <div style="text-align:right"><?= $statusChip($d['status']) ?><?= $d['is_blocked'] ? '<br><span class="chip bad" style="margin-top:5px">Заблокирован</span>' : '' ?></div>
    </div>

    <div class="flex" style="margin-top:14px;gap:18px">
      <div><div class="mut">Баланс</div><b style="font-size:18px;color:<?= $low ? '#fca5a5' : '#fde047' ?>"><?= h(money((float) $d['balance'])) ?></b></div>
      <div><div class="mut">Поездок</div><b style="font-size:18px"><?= (int) $d['completed_trips'] ?></b></div>
      <div><div class="mut">Заработано</div><b style="font-size:18px;color:#6ee7b7"><?= h(money((float) $d['total_earnings'])) ?></b></div>
      <div><div class="mut">Рейтинг</div><b style="font-size:18px">★ <?= number_format((float) $d['rating'], 1) ?></b></div>
    </div>

    <div class="flex" style="margin-top:14px">
      <a class="btn ghost sm" href="messages.php?recipient=<?= h($d['user_id']) ?>">Написать</a>
      <a class="btn ghost sm" href="driver-track.php?id=<?=h($d['id'])?>">GPS-трек</a>
      <form method="post" class="inline">
        <input type="hidden" name="cmd" value="topup"><input type="hidden" name="id" value="<?= h($d['id']) ?>">
        <input type="number" name="amount" value="500" min="1" style="width:90px"><button class="btn sm">Пополнить</button>
      </form>
      <form method="post"><input type="hidden" name="cmd" value="verify"><input type="hidden" name="id" value="<?= h($d['id']) ?>"><button class="btn ghost sm"><?= $d['is_verified'] ? 'Снять верификацию' : 'Верифицировать' ?></button></form>
      <form method="post"><input type="hidden" name="cmd" value="block"><input type="hidden" name="id" value="<?= h($d['id']) ?>"><button class="btn <?= $d['is_blocked'] ? 'ghost' : 'danger' ?> sm"><?= $d['is_blocked'] ? 'Разблокировать' : 'Заблокировать' ?></button></form>
    </div>

    <details style="margin-top:14px;border-top:1px solid rgba(255,255,255,.07);padding-top:10px">
      <summary class="mut" style="cursor:pointer;font-weight:700">Редактировать профиль, авто и реквизиты</summary>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="cmd" value="update"><input type="hidden" name="id" value="<?= h($d['id']) ?>">
        <div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
          <label class="mut">Имя<input name="first_name" value="<?= h($d['first_name']) ?>"></label>
          <label class="mut">Фамилия<input name="last_name" value="<?= h($d['last_name']) ?>"></label>
          <label class="mut">Марка<input name="car_brand" value="<?= h($d['car_brand']) ?>"></label>
          <label class="mut">Модель<input name="car_model" value="<?= h($d['car_model']) ?>"></label>
          <label class="mut">Цвет<input name="car_color" value="<?= h($d['car_color']) ?>"></label>
          <label class="mut">Госномер<input name="license_plate" value="<?= h($d['license_plate']) ?>"></label>
          <label class="mut">Год<input type="number" name="car_year" value="<?= (int) $d['car_year'] ?>"></label>
          <label class="mut">ВУ<input name="driver_license" value="<?= h($d['driver_license']) ?>"></label>
          <label class="mut">ВУ действует до<input type="date" name="license_expiry" value="<?= h($d['license_expiry'] ? substr($d['license_expiry'],0,10) : '') ?>"></label>
          <label class="mut">Минимум баланса<input type="number" name="min_balance_for_orders" value="<?= h((string) $d['min_balance_for_orders']) ?>"></label>
          <label class="mut">Штраф за отказ<input type="number" name="rejection_penalty" value="<?= h((string) $d['rejection_penalty']) ?>"></label>
          <label class="mut">Телефон выплат<input name="payment_phone" value="<?= h((string) $d['payment_phone']) ?>"></label>
          <label class="mut">Банк<input name="payment_bank_name" value="<?= h((string) $d['payment_bank_name']) ?>"></label>
          <label class="mut" style="grid-column:1/-1">Получатель<input name="payment_card_holder" value="<?= h((string) $d['payment_card_holder']) ?>"></label>
        </div>
        <div class="flex" style="margin-top:10px">
          <label><input type="checkbox" name="accept_card_transfer" value="1" <?= $d['accept_card_transfer'] ? 'checked' : '' ?> style="width:auto"> Карта</label>
          <label><input type="checkbox" name="accept_sbp" value="1" <?= $d['accept_sbp'] ? 'checked' : '' ?> style="width:auto"> СБП</label>
          <button class="btn sm" style="margin-left:auto">Сохранить</button>
        </div>
      </form>
    </details>
  </div>
<?php endforeach; ?>
</div>

<?php layout_footer();
