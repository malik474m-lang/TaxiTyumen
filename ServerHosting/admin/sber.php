<?php
// Сбер: эквайринг, внутренний счёт, кошельки водителей и ручные выплаты СБП.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'sber');
Guard::superadmin(['role' => $admin['role']]);
SberPayments::ensureTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = (string) ($_POST['cmd'] ?? '');
    try {
        if ($cmd === 'settings') {
            SberPayments::saveSettings($db, $_POST);
            header('Location: sber.php?ok=' . urlencode('Настройки Сбера сохранены'));
            exit;
        }
        if ($cmd === 'withdraw') {
            SberPayments::processWithdrawal(
                $db, (string) $_POST['id'], (string) $_POST['decision'], (string) $admin['id'],
                trim((string) ($_POST['comment'] ?? '')),
                trim((string) ($_POST['receipt'] ?? ''))
            );
            header('Location: sber.php?ok=' . urlencode('Заявка обработана'));
            exit;
        }
        if ($cmd === 'sync') {
            SberPayments::reconcile($db, (string) $_POST['id']);
            header('Location: sber.php?ok=' . urlencode('Статус платежа обновлён'));
            exit;
        }
    } catch (Throwable $e) {
        header('Location: sber.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$s = SberPayments::settings($db);
$dates = date_filter('p.created_at');
$stmt = $db->prepare(
    "SELECT p.*,o.order_number,u.first_name,u.last_name
     FROM sber_payments p
     LEFT JOIN orders o ON o.id=p.order_id
     LEFT JOIN users u ON u.id=p.client_id
     WHERE 1=1" . $dates['sql'] . " ORDER BY p.created_at DESC LIMIT 300"
);
$stmt->execute($dates['params']);
$payments = $stmt->fetchAll();

$wallets = $db->query(
    "SELECT w.*,d.license_plate,u.first_name,u.last_name
     FROM driver_wallets w JOIN drivers d ON d.id=w.driver_id JOIN users u ON u.id=d.user_id
     ORDER BY w.cashless_balance DESC LIMIT 200"
)->fetchAll();

$withdrawals = $db->query(
    "SELECT r.*,d.license_plate,u.first_name,u.last_name
     FROM driver_withdrawal_requests r
     JOIN drivers d ON d.id=r.driver_id JOIN users u ON u.id=d.user_id
     ORDER BY FIELD(r.status,'pending','approved','paid','rejected'),r.created_at DESC LIMIT 200"
)->fetchAll();

$ledger = $db->query(
    "SELECT
       COALESCE(SUM(CASE WHEN type='payment_received' THEN amount ELSE 0 END),0) received,
       COALESCE(SUM(CASE WHEN type='driver_credit' THEN amount ELSE 0 END),0) driver_credit,
       COALESCE(SUM(CASE WHEN type='commission_income' THEN amount ELSE 0 END),0) commission,
       COALESCE(SUM(CASE WHEN type='withdrawal' THEN -amount ELSE 0 END),0) paid_out
     FROM system_ledger"
)->fetch() ?: [];

layout_header('Платежи Сбер', 'sber');
?>
<div class="flex between">
  <div><h1>Платежи Сбер</h1><p class="mut">Эквайринг ИП · клиенты платят системе · водители-самозанятые</p></div>
  <span class="chip <?= $s['configured'] ? 'ok' : 'bad' ?>">
    <?= $s['configured'] ? ($s['test_mode'] ? 'Тестовый контур' : 'Боевой контур') : 'Выключено / нет реквизитов' ?>
  </span>
</div>
<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string)$_GET['ok']) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?><div class="flash" style="margin-top:14px;color:#fca5a5">Ошибка: <?= h((string)$_GET['error']) ?></div><?php endif; ?>

<div class="card" style="margin-top:18px;border-color:rgba(250,204,21,.35)">
  <h3>Текущий юридический режим</h3>
  <p class="mut" style="margin-top:6px">
    Статус: <b>ИП</b>. Водители: <b>самозанятые</b>. Сбер пока одобрил только
    <b>приём платежей</b>. Поэтому автоматическая B2C-выплата по СБП заблокирована:
    водитель создаёт заявку, администратор переводит вручную и фиксирует чек самозанятого.
    После одобрения B2C этот журнал подключается к API без изменения балансов.
    Перед боевым включением обязательно согласуйте онлайн-кассу и фискализацию
    по 54-ФЗ (Сбер/ОФД или передача orderBundle).
  </p>
</div>

<div class="grid2" style="margin-top:18px">
  <form method="post" class="card">
    <input type="hidden" name="cmd" value="settings">
    <h3>Подключение эквайринга</h3>
    <label class="mut" style="display:flex;gap:8px;margin:12px 0"><input type="checkbox" name="enabled" value="1" <?= $s['enabled']?'checked':'' ?>> Включить платежи Сбер</label>
    <label class="mut" style="display:flex;gap:8px;margin:12px 0"><input type="checkbox" name="test_mode" value="1" <?= $s['test_mode']?'checked':'' ?>> Тестовый контур (3dsec.sberbank.ru)</label>
    <label class="mut">Логин магазина (*-api)<input name="user_name" value="<?= h((string)$s['user_name']) ?>" autocomplete="off"></label>
    <label class="mut">Пароль API<input type="password" name="api_password" placeholder="оставьте пустым, чтобы не менять" autocomplete="new-password"></label>
    <label class="mut">Название ИП<input name="company_name" value="<?= h((string)($s['company_name']??'')) ?>"></label>
    <label class="mut" style="display:flex;gap:8px;margin:12px 0"><input type="checkbox" name="sbp_enabled" value="1" <?= $s['sbp_enabled']?'checked':'' ?>> СБП C2B одобрено банком и включено</label>
    <label class="mut" style="display:flex;gap:8px;margin:12px 0"><input type="checkbox" name="recurring_enabled" value="1" <?= $s['recurring_enabled']?'checked':'' ?>> Привязки/рекуррентные карты одобрены банком</label>
    <button class="btn">Сохранить</button>
    <p class="mut" style="font-size:11px;margin-top:10px">Секреты также можно задать безопаснее через SBER_USERNAME / SBER_PASSWORD в окружении. Без логина и пароля система не вызывает Сбер.</p>
  </form>

  <div class="card">
    <h3>Внутренний счёт системы</h3>
    <div class="stat-big" style="color:#6ee7b7"><?= money((float)($ledger['received']??0)) ?></div><div class="mut">принято через Сбер</div>
    <hr style="border:0;border-top:1px solid var(--line);margin:14px 0">
    <div>Начислено водителям: <b><?= money((float)($ledger['driver_credit']??0)) ?></b></div>
    <div>Комиссия системы: <b style="color:#fde047"><?= money((float)($ledger['commission']??0)) ?></b></div>
    <div>Выплачено вручную: <b><?= money((float)($ledger['paid_out']??0)) ?></b></div>
    <p class="mut" style="margin-top:12px;font-size:11px">Финансовые записи идемпотентны: повторный callback/проверка статуса не начисляет деньги повторно.</p>
  </div>
</div>

<div class="card" style="margin-top:18px;overflow-x:auto">
  <h3>Заявки самозанятых на вывод по СБП</h3>
  <table style="margin-top:10px"><thead><tr><th>Дата / водитель</th><th>Сумма</th><th>СБП</th><th>ИНН</th><th>Статус</th><th>Обработка</th></tr></thead><tbody>
  <?php foreach($withdrawals as $r): ?>
    <tr><td><?=h(fmt_date($r['created_at']))?><div class="mut"><?=h($r['first_name'].' '.$r['last_name'].' · '.$r['license_plate'])?></div></td>
    <td><b><?=money((float)$r['amount'])?></b></td><td><?=h($r['sbp_phone'])?><div class="mut"><?=h($r['bank_name'])?></div></td><td><?=h($r['self_employed_inn'])?></td>
    <td><span class="chip <?=$r['status']==='paid'?'ok':($r['status']==='rejected'?'bad':'warn')?>"><?=h($r['status'])?></span></td><td>
    <?php if($r['status']==='pending'): ?><form method="post" style="display:grid;gap:5px;min-width:230px"><input type="hidden" name="cmd" value="withdraw"><input type="hidden" name="id" value="<?=h($r['id'])?>"><input name="comment" placeholder="Комментарий / номер перевода"><input name="receipt" placeholder="Номер чека самозанятого"><div class="flex"><button class="btn sm" name="decision" value="paid">Выплачено</button><button class="btn sm ghost" name="decision" value="rejected">Отклонить</button></div></form><?php else: ?><div class="mut"><?=h((string)$r['admin_comment'])?></div><div class="mut">Чек: <?=h((string)$r['self_employed_receipt'])?></div><?php endif; ?>
    </td></tr>
  <?php endforeach; ?><?php if(!$withdrawals):?><tr><td colspan="6" class="mut">Заявок нет</td></tr><?php endif;?></tbody></table>
</div>

<div class="card" style="margin-top:18px;overflow-x:auto">
  <h3>Безналичные кошельки водителей</h3>
  <table style="margin-top:10px"><thead><tr><th>Водитель</th><th>Доступно</th><th>В заявках</th><th>Всего заработано</th><th>Выплачено</th></tr></thead><tbody>
  <?php foreach($wallets as $w):?><tr><td><?=h($w['first_name'].' '.$w['last_name'])?><div class="mut"><?=h($w['license_plate'])?></div></td><td><b><?=money((float)$w['cashless_balance'])?></b></td><td><?=money((float)$w['pending_withdrawal'])?></td><td><?=money((float)$w['total_cashless_earned'])?></td><td><?=money((float)$w['total_paid_out'])?></td></tr><?php endforeach;?>
  <?php if(!$wallets):?><tr><td colspan="5" class="mut">Безналичных начислений пока нет</td></tr><?php endif;?></tbody></table>
</div>

<div class="card" style="margin-top:18px;overflow-x:auto">
  <div class="flex between"><h3>Платежи</h3><span class="mut"><?=count($payments)?> записей</span></div>
  <?php date_filter_form($dates); ?>
  <table style="margin-top:10px"><thead><tr><th>Дата / номер</th><th>Назначение</th><th>Заказ / клиент</th><th>Сумма</th><th>Статус</th><th></th></tr></thead><tbody>
  <?php foreach($payments as $p):?><tr><td><?=h(fmt_date($p['created_at']))?><div class="mut" style="font-family:monospace"><?=h($p['local_order_number'])?></div></td><td><?=h($p['purpose'])?><div class="mut"><?=h($p['payment_method'])?></div></td><td><?=h((string)($p['order_number']??''))?><div class="mut"><?=h(trim((string)($p['first_name']??'').' '.(string)($p['last_name']??'')))?></div></td><td><b><?=money((float)$p['amount'])?></b></td><td><span class="chip <?=$p['status']==='paid'?'ok':($p['status']==='failed'?'bad':'warn')?>"><?=h($p['status'])?></span><?php if($p['error']):?><div class="mut" style="color:#fca5a5"><?=h(mb_substr($p['error'],0,120))?></div><?php endif;?></td><td><?php if($p['status']==='pending'):?><form method="post"><input type="hidden" name="cmd" value="sync"><input type="hidden" name="id" value="<?=h($p['id'])?>"><button class="btn sm ghost">Проверить</button></form><?php endif;?></td></tr><?php endforeach;?>
  <?php if(!$payments):?><tr><td colspan="6" class="mut">Платежей пока нет</td></tr><?php endif;?></tbody></table>
</div>
<?php layout_footer();
