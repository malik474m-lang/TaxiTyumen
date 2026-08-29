<?php
// Центр сообщений: ручные in-app/SMS отправки пользователю, роли или всем.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $target = (string) ($_POST['target'] ?? 'user');
        $channel = (string) ($_POST['channel'] ?? 'in_app');
        $title = trim((string) ($_POST['title'] ?? 'Сообщение от службы такси'));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '') throw new RuntimeException('Введите текст сообщения');

        $channels = $channel === 'both' ? ['in_app', 'sms'] : [$channel];
        $sent = 0;
        foreach ($channels as $ch) {
            if ($target === 'user') {
                $recipientId = (string) ($_POST['recipient_id'] ?? '');
                if ($recipientId === '') throw new RuntimeException('Выберите получателя');
                NotificationService::create($db, $recipientId, 'AdminMessage', $title, $message, null, [], $ch, $admin['id']);
                $sent++;
            } elseif ($target === 'role') {
                $role = (string) ($_POST['role'] ?? 'client');
                if (!in_array($role, ['client', 'driver', 'operator'], true)) throw new RuntimeException('Неизвестная группа');
                $sent += NotificationService::sendToRole($db, $role, 'AdminMessage', $title, $message, null, [], $ch, $admin['id']);
            } elseif ($target === 'all') {
                $sent += NotificationService::sendBroadcast($db, 'AdminMessage', $title, $message, $ch, $admin['id']);
            } else {
                throw new RuntimeException('Неизвестный тип получателя');
            }
        }
        header('Location: messages.php?ok=' . urlencode("Создано отправок: $sent"));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$preselect = (string) ($_GET['recipient'] ?? '');
$users = $db->query(
    "SELECT u.id,u.first_name,u.last_name,u.phone,u.role,u.is_blocked,
      d.license_plate FROM users u LEFT JOIN drivers d ON d.user_id=u.id
     WHERE u.role IN ('client','driver','operator') ORDER BY u.role,u.last_name,u.first_name LIMIT 1000"
)->fetchAll();

$history = $db->query(
    "SELECT n.*,u.first_name,u.last_name,u.phone
     FROM notifications n LEFT JOIN users u ON u.id=n.recipient_id
     ORDER BY n.created_at DESC LIMIT 200"
)->fetchAll();

$templates = [
    'Технические работы' => ['Технические работы', 'Сервис временно недоступен из-за технических работ. Мы сообщим о восстановлении работы.'],
    'Промокод' => ['Подарок от Такси Тюмень', 'Для вас действует специальное предложение. Подробности в приложении.'],
    'Документы водителя' => ['Обновите документы', 'Проверьте срок действия водительского удостоверения и данные автомобиля в профиле.'],
    'Низкий баланс' => ['Пополните баланс', 'Баланс ниже минимального уровня для приёма заказов. Пополните его в приложении.'],
];

layout_header('Сообщения', 'messages');
?>
<div class="flex between">
  <div><h1>Сообщения и уведомления</h1><p class="mut">Ручная отправка клиентам, водителям и операторам</p></div>
  <span class="chip <?= SMS_API_ID !== '' ? 'ok' : 'warn' ?>">SMS: <?= SMS_API_ID !== '' ? 'sms.ru настроен' : 'не настроен' ?></span>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?= h($error) ?></div><?php endif; ?>

<div class="grid q2" style="margin-top:18px">
  <form method="post" class="card" id="messageForm">
    <h3 style="font-weight:900;font-size:16px;margin-bottom:14px">Новое сообщение</h3>

    <div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
      <label class="mut">Кому
        <select name="target" id="target" onchange="toggleTarget()">
          <option value="user">Конкретному пользователю</option>
          <option value="role">Группе по роли</option>
          <option value="all">Всем активным пользователям</option>
        </select>
      </label>
      <label class="mut">Канал
        <select name="channel">
          <option value="in_app">В приложении</option>
          <option value="sms">SMS</option>
          <option value="both">В приложении + SMS</option>
        </select>
      </label>
    </div>

    <label class="mut" id="userWrap" style="display:block;margin-top:12px">Пользователь
      <select name="recipient_id">
        <option value="">— выберите —</option>
        <?php $lastRole=''; foreach ($users as $u): if ($u['role']!==$lastRole): if($lastRole):?></optgroup><?php endif; $lastRole=$u['role']; ?>
          <optgroup label="<?= h(['client'=>'Клиенты','driver'=>'Водители','operator'=>'Операторы'][$u['role']] ?? $u['role']) ?>">
        <?php endif; ?>
          <option value="<?= h($u['id']) ?>" <?= $u['is_blocked'] ? 'disabled' : '' ?>><?= h($u['first_name'].' '.$u['last_name'].' · '.$u['phone'].($u['license_plate']?' · '.$u['license_plate']:'')) ?></option>
        <?php endforeach; if($lastRole):?></optgroup><?php endif; ?>
      </select>
    </label>

    <label class="mut" id="phoneWrap" style="display:none;margin-top:12px">Телефон клиента
      <input name="phone" placeholder="+7 (___) ___-__-__">
    </label>

    <label class="mut" id="roleWrap" style="display:none;margin-top:12px">Группа
      <select name="role"><option value="client">Все клиенты</option><option value="driver">Все водители</option><option value="operator">Все операторы</option></select>
    </label>

    <label class="mut" style="display:block;margin-top:12px">Шаблон
      <select id="template" onchange="applyTemplate()"><option value="">— без шаблона —</option><?php foreach($templates as $name=>$values):?><option value="<?=h($name)?>"><?=h($name)?></option><?php endforeach;?></select>
    </label>
    <label class="mut" style="display:block;margin-top:12px">Заголовок<input name="title" id="title" value="Сообщение от службы такси" maxlength="160"></label>
    <label class="mut" style="display:block;margin-top:12px">Текст<textarea name="message" id="message" rows="6" maxlength="1000" required></textarea></label>
    <div class="mut" style="font-size:11px;margin-top:6px">In-app сообщение появится через `/api/notifications.php`; SMS требует `SMS_API_ID`.</div>
    <button class="btn" type="submit" style="width:100%;margin-top:14px" onclick="return confirm('Отправить сообщение выбранным получателям?')">Отправить</button>
  </form>

  <div class="card">
    <h3 style="font-weight:900;font-size:16px;margin-bottom:10px">Как доставляется</h3>
    <div style="display:grid;gap:8px">
      <div style="padding:12px;background:#0f0f13;border-radius:12px"><b style="color:#7dd3fc">В приложении</b><div class="mut">Сохраняется в MySQL, получает конкретный пользователь по Bearer-токену. Есть счётчик непрочитанных и отметка прочтения.</div></div>
      <div style="padding:12px;background:#0f0f13;border-radius:12px"><b style="color:#6ee7b7">SMS</b><div class="mut">Отправляется через sms.ru. Ответ провайдера и HTTP-код сохраняются в журнале сервисов.</div></div>
      <div style="padding:12px;background:#0f0f13;border-radius:12px"><b style="color:#fde047">Автоматически</b><div class="mut">Назначение, прибытие, завершение, отмена, новый заказ и отказ водителя создают системные уведомления сами.</div></div>
    </div>
  </div>
</div>

<div class="card" style="margin-top:14px;overflow-x:auto">
  <div class="flex between" style="margin-bottom:10px"><h3>История отправок</h3><span class="mut"><?= count($history) ?> последних</span></div>
  <table><thead><tr><th>Дата</th><th>Получатель</th><th>Тип / канал</th><th>Сообщение</th><th>Доставка</th><th>Прочитано</th></tr></thead><tbody>
  <?php foreach($history as $n):?>
    <tr><td class="mut"><?=h(fmt_date($n['created_at']))?></td>
    <td><b><?=h(trim(($n['first_name']??'').' '.($n['last_name']??'')) ?: '—')?></b><div class="mut"><?=h((string)($n['phone']??''))?></div></td>
    <td><span class="chip info"><?=h($n['type'])?></span><div class="mut"><?=h($n['channel'])?></div></td>
    <td><b><?=h($n['title'])?></b><div class="mut" style="max-width:360px;white-space:normal"><?=h($n['message'])?></div></td>
    <td><span class="chip <?=$n['delivery_status']==='sent'?'ok':($n['delivery_status']==='failed'?'bad':'warn')?>"><?=h($n['delivery_status'])?></span></td>
    <td><?=$n['is_read']?'<span class="chip ok">Да</span>':'<span class="chip warn">Нет</span>'?></td></tr>
  <?php endforeach;?>
  <?php if(!$history):?><tr><td colspan="6" class="mut" style="text-align:center;padding:35px">Сообщений пока нет</td></tr><?php endif;?>
  </tbody></table>
</div>

<script>
var templates=<?=json_encode($templates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
function toggleTarget(){var v=document.getElementById('target').value;document.getElementById('userWrap').style.display=v==='user'?'block':'none';document.getElementById('roleWrap').style.display=v==='role'?'block':'none'}
function applyTemplate(){var v=document.getElementById('template').value;if(!v||!templates[v])return;document.getElementById('title').value=templates[v][0];document.getElementById('message').value=templates[v][1]}
</script>

<?php layout_footer();
