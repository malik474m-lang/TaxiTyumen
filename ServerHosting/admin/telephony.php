<?php
// Телефония: настройки провайдера (Plusofon API v1), тестовый звонок, журнал.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'telephony');
$error = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cmd = (string) ($_POST['cmd'] ?? 'save');

        if ($cmd === 'save') {
            Telephony::update($db, [
                'enabled' => !empty($_POST['enabled']),
                'provider' => $_POST['provider'] ?? 'plusofon',
                'baseUrl' => $_POST['base_url'] ?? '',
                'clientId' => $_POST['client_id'] ?? '',
                'apiToken' => $_POST['api_token'] ?? '',
                'callerNumber' => $_POST['caller_number'] ?? '',
                'endpointCall' => $_POST['endpoint_call'] ?? '',
                'endpointFlashCall' => $_POST['endpoint_flash_call'] ?? '',
                'endpointBalance' => $_POST['endpoint_balance'] ?? '',
                'webhookSecret' => $_POST['webhook_secret'] ?? '',
                'callOnArrival' => !empty($_POST['call_on_arrival']),
                'recordCalls' => !empty($_POST['record_calls']),
            ]);
            Bus::publish('telephony');
            header('Location: telephony.php?ok=' . urlencode('Настройки телефонии сохранены'));
            exit;
        }

        if ($cmd === 'balance') {
            $result = Telephony::checkBalance($db);
        }

        if ($cmd === 'test_call') {
            $first = trim((string) ($_POST['first'] ?? ''));
            $second = trim((string) ($_POST['second'] ?? ''));
            if ($first === '' || $second === '') {
                throw new RuntimeException('Укажите оба номера для тестового соединения');
            }
            $result = Telephony::connect($db, $first, $second, 'test', ['userId' => $admin['id']]);
        }

        if ($cmd === 'flash_call') {
            $phone = trim((string) ($_POST['phone'] ?? ''));
            if ($phone === '') throw new RuntimeException('Укажите номер для Flash Call');
            $result = Telephony::flashCall($db, $phone, ['userId' => $admin['id']]);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$s = Telephony::settings($db);
$logs = $db->query('SELECT * FROM call_logs ORDER BY created_at DESC LIMIT 100')->fetchAll();
$webhookUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'ваш-домен')
    . '/api/telephony/webhook.php'
    . ($s['webhook_secret'] !== '' ? '?secret=' . rawurlencode((string) $s['webhook_secret']) : '');

layout_header('Телефония', 'telephony');
?>
<div class="flex between">
  <div><h1>Телефония</h1><p class="mut">Plusofon API v1 и совместимые провайдеры: соединение абонентов, Flash Call, журнал</p></div>
  <span class="chip <?= Telephony::isConfigured($s) ? 'ok' : 'warn' ?>">
    <?= Telephony::isConfigured($s) ? 'Подключена' : 'Не настроена' ?>
  </span>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?= h($error) ?></div><?php endif; ?>
<?php if ($result): ?>
<div class="flash" style="margin-top:14px;border-color:rgba(125,211,252,.35);background:rgba(56,189,248,.08);color:#7dd3fc">
  Результат: <b><?= h((string) ($result['status'] ?? ($result['ok'] ?? false ? 'ok' : 'error'))) ?></b>
  <?php if (isset($result['balance'])): ?> · баланс <?= h((string) $result['balance']) ?><?php endif; ?>
  <?php if (!empty($result['httpCode'])): ?> · HTTP <?= (int) $result['httpCode'] ?><?php endif; ?>
  <div class="mut" style="margin-top:6px;font-size:11px;white-space:pre-wrap"><?= h(mb_substr((string) ($result['response'] ?? $result['message'] ?? ''), 0, 500)) ?></div>
</div>
<?php endif; ?>

<form method="post" class="grid q2" style="margin-top:18px">
  <input type="hidden" name="cmd" value="save">
  <div class="card">
    <h3 style="margin-bottom:12px">Подключение</h3>
    <label class="flex between" style="padding:11px;background:#0f0f13;border-radius:11px">
      <span><b>Телефония включена</b><div class="mut">Звонки из админки и по событиям заказа</div></span>
      <input type="checkbox" name="enabled" value="1" <?= $s['enabled'] ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#facc15">
    </label>
    <label class="mut" style="display:block;margin-top:10px">Провайдер
      <select name="provider">
        <option value="plusofon" <?= $s['provider'] === 'plusofon' ? 'selected' : '' ?>>Plusofon</option>
        <option value="custom" <?= $s['provider'] === 'custom' ? 'selected' : '' ?>>Другой (совместимый REST)</option>
      </select>
    </label>
    <label class="mut" style="display:block;margin-top:10px">Base URL API
      <input name="base_url" value="<?= h((string) $s['base_url']) ?>" placeholder="https://api.plusofon.ru/rest/v1">
    </label>
    <label class="mut" style="display:block;margin-top:10px">Client ID (идентификатор клиента/приложения)
      <input name="client_id" value="<?= h((string) $s['client_id']) ?>">
    </label>
    <label class="mut" style="display:block;margin-top:10px">API-токен
      <input type="password" name="api_token" autocomplete="new-password"
        placeholder="<?= trim((string) $s['api_token']) !== '' ? 'Токен сохранён — оставьте пустым' : 'Bearer-токен из личного кабинета' ?>">
    </label>
    <label class="mut" style="display:block;margin-top:10px">Номер сервиса (АОН)
      <input name="caller_number" value="<?= h((string) $s['caller_number']) ?>" placeholder="+7...">
    </label>
    <label class="flex between" style="margin-top:10px;padding:11px;background:#0f0f13;border-radius:11px">
      <span>Запись разговоров</span>
      <input type="checkbox" name="record_calls" value="1" <?= $s['record_calls'] ? 'checked' : '' ?> style="width:18px;height:18px">
    </label>
    <label class="flex between" style="margin-top:9px;padding:11px;background:#0f0f13;border-radius:11px">
      <span><b>Звонок при прибытии</b><div class="mut">Соединять водителя с клиентом автоматически</div></span>
      <input type="checkbox" name="call_on_arrival" value="1" <?= $s['call_on_arrival'] ? 'checked' : '' ?> style="width:18px;height:18px">
    </label>
  </div>

  <div class="card">
    <h3 style="margin-bottom:12px">Методы и вебхук</h3>
    <p class="mut" style="margin-bottom:10px">
      Пути методов возьмите из документации вашего тарифа Plusofon — код их не хардкодит.
    </p>
    <label class="mut" style="display:block">Метод соединения абонентов
      <input name="endpoint_call" value="<?= h((string) $s['endpoint_call']) ?>" placeholder="/call/callback">
    </label>
    <label class="mut" style="display:block;margin-top:10px">Метод Flash Call
      <input name="endpoint_flash_call" value="<?= h((string) $s['endpoint_flash_call']) ?>" placeholder="/flash-call/create">
    </label>
    <label class="mut" style="display:block;margin-top:10px">Метод баланса
      <input name="endpoint_balance" value="<?= h((string) $s['endpoint_balance']) ?>" placeholder="/customer/balance">
    </label>
    <label class="mut" style="display:block;margin-top:10px">Секрет вебхука
      <input name="webhook_secret" value="<?= h((string) $s['webhook_secret']) ?>" placeholder="случайная строка">
    </label>
    <div style="margin-top:12px;padding:11px;background:#0f0f13;border-radius:11px">
      <div class="mut" style="font-size:11px">URL вебхука для личного кабинета провайдера:</div>
      <code style="font-size:11px;word-break:break-all"><?= h($webhookUrl) ?></code>
    </div>
    <div style="margin-top:10px;padding:11px;background:#0f0f13;border-radius:11px">
      <span class="mut">Баланс:</span>
      <b style="color:#fde047"><?= $s['balance'] !== null ? h((string) $s['balance']) : '—' ?></b>
      <span class="mut"> · <?= h(fmt_date($s['balance_checked_at'])) ?></span>
    </div>
  </div>

  <button class="btn" type="submit" style="grid-column:1/-1">Сохранить настройки телефонии</button>
</form>

<div class="grid q2" style="margin-top:14px">
  <form method="post" class="card">
    <input type="hidden" name="cmd" value="test_call">
    <h3 style="margin-bottom:10px">Тестовое соединение</h3>
    <p class="mut" style="margin-bottom:10px">Провайдер позвонит первому номеру, затем соединит со вторым.</p>
    <label class="mut" style="display:block">Первый номер (кому звоним сначала)<input name="first" placeholder="+7..." required></label>
    <label class="mut" style="display:block;margin-top:10px">Второй номер<input name="second" placeholder="+7..." required></label>
    <button class="btn" style="width:100%;margin-top:12px">Позвонить</button>
  </form>

  <div>
    <form method="post" class="card">
      <input type="hidden" name="cmd" value="flash_call">
      <h3 style="margin-bottom:10px">Flash Call</h3>
      <p class="mut" style="margin-bottom:10px">Подтверждение номера входящим звонком вместо SMS.</p>
      <label class="mut" style="display:block">Номер<input name="phone" placeholder="+7..." required></label>
      <button class="btn ghost" style="width:100%;margin-top:12px">Отправить Flash Call</button>
    </form>
    <form method="post" class="card" style="margin-top:14px">
      <input type="hidden" name="cmd" value="balance">
      <h3 style="margin-bottom:10px">Проверка связи</h3>
      <p class="mut" style="margin-bottom:10px">Запрос баланса подтверждает корректность токена и Base URL.</p>
      <button class="btn ghost" style="width:100%">Проверить баланс</button>
    </form>
  </div>
</div>

<div class="card" style="margin-top:14px;overflow-x:auto">
  <div class="flex between" style="margin-bottom:10px"><h3>Журнал звонков</h3><span class="mut"><?= count($logs) ?> последних</span></div>
  <table>
    <thead><tr><th>Дата</th><th>Сценарий</th><th>Номера</th><th>Статус</th><th>Длительность</th><th>Запись</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $c): ?>
      <tr>
        <td class="mut"><?= h(fmt_date($c['created_at'])) ?></td>
        <td><span class="chip info"><?= h($c['scenario']) ?></span><div class="mut"><?= h((string) ($c['external_id'] ?? '')) ?></div></td>
        <td><?= h((string) $c['from_number']) ?><div class="mut">→ <?= h((string) $c['to_number']) ?></div></td>
        <td><span class="chip <?= in_array($c['status'], ['answered','completed','success','queued'], true) ? 'ok' : ($c['status'] === 'skipped' ? 'warn' : 'bad') ?>"><?= h($c['status']) ?></span></td>
        <td><?= $c['duration'] !== null ? (int) $c['duration'] . ' c' : '—' ?></td>
        <td><?php if ($c['record_url']): ?><a href="<?= h($c['record_url']) ?>" target="_blank">Прослушать</a><?php else: ?><span class="mut">—</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="6" class="mut" style="text-align:center;padding:30px">Звонков ещё не было</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php layout_footer();
