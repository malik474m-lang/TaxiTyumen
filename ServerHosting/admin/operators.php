<?php
// Операторы/диспетчеры — порт TaxiAdmin/Operators.razor:
// создание, блокировка, сброс пароля, схемы оплаты, смены и выработка.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'operators');
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
            if (strlen(preg_replace('/\D/', '', $phone)) < 11 || $firstName === '') {
                throw new RuntimeException('Укажите корректный телефон и имя оператора');
            }
            if (strlen($password) < 6) {
                throw new RuntimeException('Пароль — минимум 6 символов');
            }
            $exists = $db->prepare('SELECT id FROM users WHERE phone=? LIMIT 1');
            $exists->execute([$phone]);
            if ($exists->fetch()) {
                throw new RuntimeException('Пользователь с таким телефоном уже существует');
            }

            $uid = Db::uuid();
            $db->beginTransaction();
            $db->prepare(
                "INSERT INTO users
                 (id, phone, first_name, last_name, email, password_hash, role, is_phone_verified)
                 VALUES (?,?,?,?,?,?,'operator',1)"
            )->execute([
                $uid, $phone, $firstName, $lastName,
                trim((string) ($_POST['email'] ?? '')) ?: null,
                Auth::hashPassword($password),
            ]);
            $db->prepare(
                'INSERT INTO operator_profiles
                 (id, user_id, scheme, rate_per_order, rate_per_hour, rate_per_day, fixed_monthly)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                Db::uuid(), $uid,
                (string) ($_POST['scheme'] ?? 'per_order'),
                max(0, (float) ($_POST['rate_per_order'] ?? 30)),
                max(0, (float) ($_POST['rate_per_hour'] ?? 150)),
                max(0, (float) ($_POST['rate_per_day'] ?? 1500)),
                max(0, (float) ($_POST['fixed_monthly'] ?? 30000)),
            ]);
            $db->commit();
            header('Location: operators.php?ok=' . urlencode('Оператор добавлен: ' . $firstName . ' ' . $lastName));
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM users WHERE id=? AND role='operator' LIMIT 1");
        $stmt->execute([$id]);
        $operator = $stmt->fetch();
        if (!$operator) {
            throw new RuntimeException('Оператор не найден');
        }

        if ($cmd === 'block') {
            $blocked = $operator['is_blocked'] ? 0 : 1;
            $db->prepare('UPDATE users SET is_blocked=?, block_reason=? WHERE id=?')
                ->execute([$blocked, $blocked ? 'Заблокирован администратором' : null, $id]);
            header('Location: operators.php?ok=' . urlencode($blocked ? 'Оператор заблокирован' : 'Оператор разблокирован'));
            exit;
        }

        if ($cmd === 'active') {
            $active = $operator['is_active'] ? 0 : 1;
            $db->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$active, $id]);
            // Закрываем открытую смену при деактивации
            if (!$active) {
                $db->prepare('UPDATE operator_shifts SET ended_at=? WHERE operator_id=? AND ended_at IS NULL')
                    ->execute([Db::utcNow(), $id]);
            }
            header('Location: operators.php?ok=' . urlencode($active ? 'Оператор активирован' : 'Оператор деактивирован'));
            exit;
        }

        if ($cmd === 'archive') {
            Archive::assertOperatorArchivable($db, $operator);
            Archive::archiveUser($db, $targetId ?? (string) $operator['id'], (string) $admin['id'],
                trim((string) ($_POST['reason'] ?? '')) ?: null);
            header('Location: operators.php?ok=' . urlencode('Оператор перенесён в архив'));
            exit;
        }

        if ($cmd === 'restore') {
            Archive::restoreUser($db, (string) $operator['id']);
            header('Location: operators.php?view=archive&ok=' . urlencode('Оператор восстановлен'));
            exit;
        }

        if ($cmd === 'password') {
            $password = (string) ($_POST['new_password'] ?? '');
            if (strlen($password) < 6) {
                throw new RuntimeException('Новый пароль — минимум 6 символов');
            }
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([Auth::hashPassword($password), $id]);
            header('Location: operators.php?ok=' . urlencode('Пароль оператора изменён'));
            exit;
        }

        if ($cmd === 'profile') {
            $allowed = ['per_order', 'per_hour', 'per_day', 'fixed_monthly'];
            $scheme = (string) ($_POST['scheme'] ?? 'per_order');
            if (!in_array($scheme, $allowed, true)) {
                $scheme = 'per_order';
            }
            $db->prepare(
                'INSERT INTO operator_profiles
                 (id, user_id, scheme, rate_per_order, rate_per_hour, rate_per_day, fixed_monthly, updated_at)
                 VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE scheme=VALUES(scheme), rate_per_order=VALUES(rate_per_order),
                 rate_per_hour=VALUES(rate_per_hour), rate_per_day=VALUES(rate_per_day),
                 fixed_monthly=VALUES(fixed_monthly), updated_at=VALUES(updated_at)'
            )->execute([
                Db::uuid(), $id, $scheme,
                max(0, (float) ($_POST['rate_per_order'] ?? 30)),
                max(0, (float) ($_POST['rate_per_hour'] ?? 150)),
                max(0, (float) ($_POST['rate_per_day'] ?? 1500)),
                max(0, (float) ($_POST['fixed_monthly'] ?? 30000)),
                Db::utcNow(),
            ]);
            header('Location: operators.php?ok=' . urlencode('Схема оплаты сохранена'));
            exit;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

$view = ($_GET['view'] ?? '') === 'archive' ? 'archive' : 'active';
$operators = $db->query(
    "SELECT u.*, p.scheme, p.rate_per_order, p.rate_per_hour, p.rate_per_day, p.fixed_monthly
     FROM users u LEFT JOIN operator_profiles p ON p.user_id=u.id
     WHERE u.role='operator' AND u.is_archived=" . ($view === 'archive' ? '1' : '0') . "
     ORDER BY u.last_name, u.first_name"
)->fetchAll();
$archivedOperators = (int) $db->query(
    "SELECT COUNT(*) FROM users WHERE role='operator' AND is_archived=1"
)->fetchColumn();

$monthStart = gmdate('Y-m-01 00:00:00');
$schemeNames = [
    'per_order' => 'За заказ',
    'per_hour' => 'За час',
    'per_day' => 'За день',
    'fixed_monthly' => 'Фикс/месяц',
];

layout_header('Операторы', 'operators');
?>
<div class="flex between">
  <div>
    <h1>Операторы<?= $view === 'archive' ? ' · архив' : '' ?></h1>
    <p class="mut"><?= count($operators) ?> <?= $view === 'archive' ? 'в архиве' : 'учётных записей' ?></p>
  </div>
  <div class="flex">
    <a class="btn <?= $view === 'active' ? '' : 'ghost' ?> sm" href="operators.php">Активные</a>
    <a class="btn <?= $view === 'archive' ? '' : 'ghost' ?> sm" href="operators.php?view=archive">Архив<?= $archivedOperators ? ' · ' . $archivedOperators : '' ?></a>
  </div>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?= h($error) ?></div><?php endif; ?>

<?php if ($view === "active"): ?>
<details class="card" style="margin-top:18px" <?= $error ? 'open' : '' ?>>
  <summary style="cursor:pointer;font-weight:900;font-size:16px;color:#7dd3fc">＋ Добавить оператора</summary>
  <form method="post" style="margin-top:16px">
    <input type="hidden" name="cmd" value="add">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
      <label class="mut">Телефон<input name="phone" placeholder="+7900..." required></label>
      <label class="mut">Имя<input name="first_name" required></label>
      <label class="mut">Фамилия<input name="last_name"></label>
      <label class="mut">Email<input type="email" name="email"></label>
      <label class="mut">Пароль<input name="password" value="Operator123!" required></label>
      <label class="mut">Схема оплаты
        <select name="scheme"><option value="per_order">За заказ</option><option value="per_hour">За час</option><option value="per_day">За день</option><option value="fixed_monthly">Фикс/месяц</option></select>
      </label>
      <label class="mut">За заказ, ₽<input type="number" name="rate_per_order" value="30"></label>
      <label class="mut">За час, ₽<input type="number" name="rate_per_hour" value="150"></label>
      <label class="mut">За день, ₽<input type="number" name="rate_per_day" value="1500"></label>
      <label class="mut">Фикс/месяц, ₽<input type="number" name="fixed_monthly" value="30000"></label>
    </div>
    <button class="btn" type="submit" style="margin-top:14px">Добавить оператора</button>
  </form>
</details>
<?php endif; ?>

<div style="margin-top:14px">
<?php foreach ($operators as $op):
    $shiftStmt = $db->prepare('SELECT * FROM operator_shifts WHERE operator_id=? AND started_at>=? ORDER BY started_at DESC');
    $shiftStmt->execute([$op['id'], $monthStart]);
    $shifts = $shiftStmt->fetchAll();
    $hours = 0.0;
    $days = [];
    foreach ($shifts as $shift) {
        $end = $shift['ended_at'] ? strtotime($shift['ended_at'] . ' UTC') : time();
        $hours += max(0, $end - strtotime($shift['started_at'] . ' UTC')) / 3600;
        $days[substr($shift['started_at'], 0, 10)] = true;
    }
    $ordStmt = $db->prepare('SELECT COUNT(*) FROM orders WHERE operator_id=? AND created_at>=?');
    $ordStmt->execute([$op['id'], $monthStart]);
    $orderCount = (int) $ordStmt->fetchColumn();
    $scheme = $op['scheme'] ?: 'per_order';
    $salary = match ($scheme) {
        'per_hour' => $hours * (float) ($op['rate_per_hour'] ?? 150),
        'per_day' => count($days) * (float) ($op['rate_per_day'] ?? 1500),
        'fixed_monthly' => (float) ($op['fixed_monthly'] ?? 30000),
        default => $orderCount * (float) ($op['rate_per_order'] ?? 30),
    };
?>
  <div class="card" style="margin-bottom:12px;<?= $op['is_blocked'] || !$op['is_active'] ? 'border-color:rgba(248,113,113,.3)' : '' ?>">
    <div class="flex between">
      <div>
        <div style="font-weight:900;font-size:17px"><?= h($op['first_name'] . ' ' . $op['last_name']) ?></div>
        <div class="mut"><?= h($op['phone']) ?><?= $op['email'] ? ' · ' . h($op['email']) : '' ?></div>
      </div>
      <div>
        <?= $op['is_blocked'] ? '<span class="chip bad">Заблокирован</span>' : '' ?>
        <?= !$op['is_active'] ? '<span class="chip bad">Неактивен</span>' : '<span class="chip ok">Активен</span>' ?>
      </div>
    </div>

    <?php if ($view === 'archive'): ?>
    <div class="mut" style="margin-top:10px;padding:10px;background:#0f0f13;border-radius:11px">
      В архиве с <?= h(fmt_date($op['archived_at'])) ?><?= $op['archive_reason'] ? ' · ' . h($op['archive_reason']) : '' ?>
    </div>
    <?php endif; ?>

    <div class="grid q4" style="margin-top:14px">
      <div style="background:#0f0f13;border-radius:12px;padding:12px;text-align:center"><div class="stat-big" style="font-size:22px;color:#fde047"><?= $orderCount ?></div><div class="mut">заказов/мес</div></div>
      <div style="background:#0f0f13;border-radius:12px;padding:12px;text-align:center"><div class="stat-big" style="font-size:22px;color:#7dd3fc"><?= number_format($hours, 1) ?></div><div class="mut">часов/мес</div></div>
      <div style="background:#0f0f13;border-radius:12px;padding:12px;text-align:center"><div class="stat-big" style="font-size:22px;color:#6ee7b7"><?= count($days) ?></div><div class="mut">смен/мес</div></div>
      <div style="background:#0f0f13;border:1px solid #facc15;border-radius:12px;padding:12px;text-align:center"><div class="stat-big" style="font-size:22px;color:#fde047"><?= money($salary) ?></div><div class="mut">зарплата · <?= h($schemeNames[$scheme] ?? '') ?></div></div>
    </div>

    <form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr));margin-top:14px;padding:12px;background:#0f0f13;border-radius:12px">
      <input type="hidden" name="cmd" value="profile"><input type="hidden" name="id" value="<?= h($op['id']) ?>">
      <label class="mut">Схема<select name="scheme"><?php foreach ($schemeNames as $k=>$v): ?><option value="<?= h($k) ?>" <?= $scheme===$k?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
      <label class="mut">За заказ<input type="number" name="rate_per_order" value="<?= h((string) ($op['rate_per_order'] ?? 30)) ?>"></label>
      <label class="mut">За час<input type="number" name="rate_per_hour" value="<?= h((string) ($op['rate_per_hour'] ?? 150)) ?>"></label>
      <label class="mut">За день<input type="number" name="rate_per_day" value="<?= h((string) ($op['rate_per_day'] ?? 1500)) ?>"></label>
      <label class="mut">Фикс/мес<input type="number" name="fixed_monthly" value="<?= h((string) ($op['fixed_monthly'] ?? 30000)) ?>"></label>
      <button class="btn sm" style="align-self:end">Сохранить оплату</button>
    </form>

    <?php if ($shifts): ?>
    <div class="flex" style="margin-top:10px">
      <?php foreach (array_slice($shifts, 0, 7) as $shift):
          $end = $shift['ended_at'] ? strtotime($shift['ended_at'] . ' UTC') : time();
          $duration = max(0, $end - strtotime($shift['started_at'] . ' UTC')) / 3600;
      ?>
      <span class="chip info"><?= h(date('d.m', strtotime($shift['started_at'] . ' UTC'))) ?> · <?= number_format($duration, 1) ?> ч<?= !$shift['ended_at'] ? ' · идёт' : '' ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="flex" style="margin-top:12px">
      <a class="btn ghost sm" href="messages.php?recipient=<?= h($op['id']) ?>">Написать</a>
      <form method="post"><input type="hidden" name="cmd" value="block"><input type="hidden" name="id" value="<?= h($op['id']) ?>"><button class="btn <?= $op['is_blocked'] ? 'ghost' : 'danger' ?> sm"><?= $op['is_blocked'] ? 'Разблокировать' : 'Заблокировать' ?></button></form>
      <form method="post"><input type="hidden" name="cmd" value="active"><input type="hidden" name="id" value="<?= h($op['id']) ?>"><button class="btn ghost sm"><?= $op['is_active'] ? 'Деактивировать' : 'Активировать' ?></button></form>
      <form method="post" class="inline"><input type="hidden" name="cmd" value="password"><input type="hidden" name="id" value="<?= h($op['id']) ?>"><input name="new_password" placeholder="Новый пароль" minlength="6" required style="width:150px"><button class="btn ghost sm">Сменить пароль</button></form>
      <?php if ($view === 'archive'): ?>
        <form method="post"><input type="hidden" name="cmd" value="restore"><input type="hidden" name="id" value="<?=h($op['id'])?>"><button class="btn sm">Вернуть из архива</button></form>
        <form method="post" onsubmit="return confirm('Удалить оператора и историю его смен?')"><input type="hidden" name="cmd" value="delete"><input type="hidden" name="id" value="<?=h($op['id'])?>"><button class="btn danger sm">Удалить навсегда</button></form>
      <?php elseif (!$op['is_active']): ?>
        <form method="post" class="inline" onsubmit="return confirm('Перенести оператора в архив?')">
          <input type="hidden" name="cmd" value="archive"><input type="hidden" name="id" value="<?=h($op['id'])?>">
          <input name="reason" placeholder="Причина" style="width:130px"><button class="btn danger sm">В архив</button>
        </form>
      <?php else: ?>
        <span class="mut" style="font-size:11px">Архив — после деактивации</span>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php layout_footer();
