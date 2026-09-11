<?php
// Встроенный SMS-шлюз: телефон на Android с SIM-картой отправляет сообщения
// сервиса. Здесь сервис включается, выдаётся токен устройства и виден журнал.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'smsgw');
SmsGateway::ensureTables($db);

$newToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = (string) ($_POST['cmd'] ?? '');

    if ($cmd === 'save') {
        SmsGateway::save($db, [
            'enabled' => !empty($_POST['enabled']),
            'device_name' => $_POST['device_name'] ?? '',
            'poll_seconds' => $_POST['poll_seconds'] ?? 10,
            'daily_limit' => $_POST['daily_limit'] ?? 0,
        ]);
        header('Location: sms-gateway.php?ok=' . urlencode('Настройки сохранены'));
        exit;
    }

    if ($cmd === 'token') {
        // Токен показывается один раз — сразу после генерации
        $newToken = SmsGateway::regenerateToken($db);
    }

    if ($cmd === 'test') {
        $phone = Auth::normalizePhone((string) ($_POST['phone'] ?? ''));
        $text = trim((string) ($_POST['message'] ?? '')) ?: 'Проверка SMS-шлюза';
        if (strlen($phone) < 11) {
            header('Location: sms-gateway.php?error=' . urlencode('Укажите корректный номер'));
            exit;
        }
        SmsGateway::enqueue($db, $phone, $text, 'test');
        header('Location: sms-gateway.php?ok=' . urlencode('Тестовое сообщение поставлено в очередь'));
        exit;
    }

    if ($cmd === 'retry') {
        $count = SmsGateway::retryFailed($db, ($_POST['id'] ?? '') ?: null);
        header('Location: sms-gateway.php?ok=' . urlencode("Повтор отправки: $count шт."));
        exit;
    }
}

$settings = SmsGateway::settings($db);
$stats = SmsGateway::stats($db);
SmsGateway::requeueStale($db);

// Журнал очереди с фильтрами по датам и статусу
$gwDates = date_filter('created_at');
$statusFilter = (string) ($_GET['st'] ?? '');
$where = 'WHERE 1=1';
$params = [];
if (in_array($statusFilter, ['queued', 'sending', 'sent', 'failed'], true)) {
    $where .= ' AND status = ?';
    $params[] = $statusFilter;
}
$where .= $gwDates['sql'];
$params = array_merge($params, $gwDates['params']);

$stmt = $db->prepare("SELECT * FROM sms_gateway_queue $where ORDER BY created_at DESC LIMIT 300");
$stmt->execute($params);
$queue = $stmt->fetchAll();

$apiBase = rtrim(PUBLIC_BASE_URL, '/');

layout_header('SMS-шлюз', 'smsgw');
?>
<div class="flex between">
  <div>
    <h1>Встроенный SMS-шлюз</h1>
    <p class="mut">Сообщения отправляет телефон на Android с обычной SIM-картой — без оплаты внешних сервисов</p>
  </div>
  <div class="flex" style="gap:8px">
    <span class="chip <?= $settings['enabled'] ? 'ok' : '' ?>"><?= $settings['enabled'] ? 'Включён' : 'Выключен' ?></span>
    <span class="chip <?= $settings['online'] ? 'ok' : 'bad' ?>">
      <?= $settings['online'] ? 'Телефон на связи' : 'Телефон не отвечает' ?>
    </span>
  </div>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">
    Ошибка: <?= h((string) $_GET['error']) ?>
  </div>
<?php endif; ?>

<?php if ($newToken !== null): ?>
  <div class="card" style="margin-top:16px;border-color:rgba(250,204,21,.5)">
    <h3>Новый токен устройства</h3>
    <p class="mut">Скопируйте его в приложение-шлюз на телефоне. Токен показывается только сейчас.</p>
    <div style="margin-top:10px;padding:12px;background:#18181d;border-radius:10px;
                font-family:monospace;font-size:15px;word-break:break-all;color:#fde047">
      <?= h($newToken) ?>
    </div>
    <p class="mut" style="margin-top:8px">Адрес сервера для приложения: <b><?= h($apiBase) ?></b></p>
  </div>
<?php endif; ?>

<div class="grid2" style="margin-top:18px">
  <div class="card">
    <h3>Настройки сервиса</h3>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="cmd" value="save">
      <label class="mut" style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
        <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
        Включить SMS-шлюз (иначе сообщения уходят через sms.ru)
      </label>
      <label class="mut">Название устройства
        <input name="device_name" value="<?= h((string) ($settings['device_name'] ?? '')) ?>" placeholder="напр. Redmi диспетчерской">
      </label>
      <label class="mut">Интервал опроса сервера, секунд
        <input type="number" name="poll_seconds" min="3" max="120" value="<?= (int) ($settings['poll_seconds'] ?? 10) ?>">
      </label>
      <label class="mut">Лимит SMS в сутки (0 — без ограничения)
        <input type="number" name="daily_limit" min="0" value="<?= (int) ($settings['daily_limit'] ?? 0) ?>">
      </label>
      <button class="btn" style="margin-top:12px">Сохранить</button>
    </form>
  </div>

  <div class="card">
    <h3>Устройство</h3>
    <table style="margin-top:10px">
      <tr><td class="mut">Название</td><td><b><?= h((string) ($settings['device_name'] ?? '—')) ?></b></td></tr>
      <tr><td class="mut">Номер SIM</td><td><?= h((string) ($settings['device_phone'] ?? '—')) ?></td></tr>
      <tr><td class="mut">Батарея</td><td><?= $settings['battery'] !== null ? (int) $settings['battery'] . ' %' : '—' ?></td></tr>
      <tr><td class="mut">Последняя связь</td><td><?= h(fmt_date($settings['last_seen_at'] ?? null)) ?></td></tr>
      <tr><td class="mut">Токен</td><td><?= ($settings['device_token'] ?? '') !== '' ? '<span class="chip ok">выдан</span>' : '<span class="chip bad">не выдан</span>' ?></td></tr>
    </table>
    <form method="post" style="margin-top:12px"
          onsubmit="return confirm('Выдать новый токен? Старое устройство перестанет получать задания.')">
      <input type="hidden" name="cmd" value="token">
      <button class="btn ghost">Выдать новый токен</button>
    </form>
    <p class="mut" style="margin-top:10px;font-size:12px">
      Приложение-шлюз: <code>TaxiSmsGateway</code> в репозитории. Укажите в нём адрес
      <b><?= h($apiBase) ?></b> и токен — телефон начнёт принимать задания.
    </p>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <h3>Очередь сообщений</h3>
  <div class="flex" style="gap:18px;margin-top:10px;flex-wrap:wrap">
    <div><div class="mut" style="font-size:11px">В очереди</div><b style="font-size:22px"><?= $stats['queued'] ?></b></div>
    <div><div class="mut" style="font-size:11px">Отправляется</div><b style="font-size:22px;color:#7dd3fc"><?= $stats['sending'] ?></b></div>
    <div><div class="mut" style="font-size:11px">Отправлено</div><b style="font-size:22px;color:#6ee7b7"><?= $stats['sent'] ?></b></div>
    <div><div class="mut" style="font-size:11px">Ошибки</div><b style="font-size:22px;color:#fca5a5"><?= $stats['failed'] ?></b></div>
    <div><div class="mut" style="font-size:11px">Сегодня отправлено</div><b style="font-size:22px"><?= $stats['sentToday'] ?></b></div>
  </div>

  <form method="post" class="inline" style="margin-top:14px;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="cmd" value="test">
    <input name="phone" placeholder="+7 9XX XXX-XX-XX" style="width:180px" required>
    <input name="message" placeholder="Текст проверки" style="width:260px">
    <button class="btn sm">Отправить тест</button>
  </form>
  <?php if ($stats['failed'] > 0): ?>
    <form method="post" class="inline" style="margin-top:8px">
      <input type="hidden" name="cmd" value="retry">
      <button class="btn sm ghost">Повторить все неудачные (<?= $stats['failed'] ?>)</button>
    </form>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:18px;overflow-x:auto">
  <div class="flex between">
    <h3>Журнал отправок</h3>
    <form method="get">
      <input type="hidden" name="from" value="<?= h($gwDates['from']) ?>">
      <input type="hidden" name="to" value="<?= h($gwDates['to']) ?>">
      <select name="st" onchange="this.form.submit()">
        <option value="">Все статусы</option>
        <?php foreach (['queued' => 'В очереди', 'sending' => 'Отправляется', 'sent' => 'Отправлено', 'failed' => 'Ошибка'] as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php date_filter_form($gwDates, ['st' => $statusFilter]); ?>
  <p class="mut" style="margin:8px 0;font-size:12px">Записей: <?= count($queue) ?></p>

  <table>
    <thead><tr><th>Создано</th><th>Телефон</th><th>Сообщение</th><th>Статус</th><th>Отправлено</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($queue as $q): ?>
      <tr>
        <td class="mut"><?= h(fmt_date($q['created_at'])) ?>
          <div class="mut" style="font-size:11px"><?= h((string) $q['purpose']) ?></div>
        </td>
        <td><b><?= h($q['phone']) ?></b></td>
        <td style="max-width:340px"><?= h(mb_substr((string) $q['message'], 0, 160)) ?>
          <?php if (!empty($q['error'])): ?>
            <div class="mut" style="color:#fca5a5;font-size:11px"><?= h((string) $q['error']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?php
          $cls = match ($q['status']) {
              'sent' => 'ok', 'failed' => 'bad', 'sending' => 'info', default => 'warn',
          };
          $txt = match ($q['status']) {
              'sent' => 'Отправлено', 'failed' => 'Ошибка',
              'sending' => 'Отправляется', 'cancelled' => 'Отменено', default => 'В очереди',
          };
          ?>
          <span class="chip <?= $cls ?>"><?= $txt ?></span>
          <?php if ((int) $q['attempts'] > 1): ?>
            <div class="mut" style="font-size:11px">попыток: <?= (int) $q['attempts'] ?></div>
          <?php endif; ?>
        </td>
        <td class="mut"><?= h(fmt_date($q['sent_at'] ?? null)) ?></td>
        <td>
          <?php if ($q['status'] === 'failed'): ?>
            <form method="post" class="inline">
              <input type="hidden" name="cmd" value="retry">
              <input type="hidden" name="id" value="<?= h($q['id']) ?>">
              <button class="btn sm ghost">Повторить</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$queue): ?>
      <tr><td colspan="6" class="mut" style="text-align:center;padding:30px">Сообщений пока нет</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php layout_footer();
