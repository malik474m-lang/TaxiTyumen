<?php
// API-ключи внешних сервисов: вводятся прямо из панели, хранятся в БД
// и имеют приоритет над config.local.php. Правка файлов на хостинге не нужна.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'apikeys');
$error = '';
ApiKeys::ensureTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cmd = (string) ($_POST['cmd'] ?? '');

        if ($cmd === 'save') {
            $name = (string) ($_POST['key_name'] ?? '');
            ApiKeys::set($db, $name, (string) ($_POST['key_value'] ?? ''), (string) $admin['id']);
            $label = ApiKeys::REGISTRY[$name]['label'] ?? $name;
            header('Location: api-keys.php?ok=' . urlencode('Ключ сохранён: ' . $label));
            exit;
        }

        if ($cmd === 'reset_pool') {
            KeyPool::reset($db, (string) ($_POST['service'] ?? ''));
            header('Location: api-keys.php?ok=' . urlencode('Блокировки ключей сняты — все ключи снова в работе'));
            exit;
        }

        if ($cmd === 'clear') {
            $name = (string) ($_POST['key_name'] ?? '');
            ApiKeys::set($db, $name, '', (string) $admin['id']);
            header('Location: api-keys.php?ok=' . urlencode('Ключ удалён из базы (вернулось значение из файла, если оно есть)'));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Проверка «на месте»: дергаем реальные сервисы текущими ключами
$probe = [];
if (($_GET['check'] ?? '') === '1') {
    $probe['geocoding'] = GeocodingService::check($db);
    $probe['sms'] = SmsService::check($db);
}

$sourceChip = static function (string $src): string {
    return match ($src) {
        'db' => '<span class="chip ok">из базы</span>',
        'file' => '<span class="chip info">из config.local.php</span>',
        default => '<span class="chip bad">не задан</span>',
    };
};

layout_header('API-ключи', 'apikeys');
?>
<?php if (!empty($_GET['ok'])): ?><div class="flash"><?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="flash" style="border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5"><?= h($error) ?></div>
<?php endif; ?>

<div class="flex between" style="margin-top:18px">
  <div>
    <h1>Ключи внешних сервисов</h1>
    <p class="mut" style="margin-top:4px;max-width:760px">
      Ключи вводятся здесь и сохраняются в базе — заходить на хостинг и править
      <code>config.local.php</code> больше не нужно. Значение из базы всегда имеет приоритет
      над файлом; очистка поля возвращает работу на файловый ключ (если он есть).
    </p>
  </div>
  <a class="btn ghost" href="api-keys.php?check=1">Проверить сервисы</a>
</div>

<?php if ($probe): ?>
<div class="grid q2" style="margin-top:14px">
  <div class="card">
    <div class="flex between"><b>Геокодинг</b>
      <span class="chip <?= !empty($probe['geocoding']['ok']) ? 'ok' : 'bad' ?>"><?= !empty($probe['geocoding']['ok']) ? 'работает' : 'нет ответа' ?></span>
    </div>
    <div class="mut" style="margin-top:6px"><?= h((string) ($probe['geocoding']['message'] ?? '')) ?>
      <?php if (!empty($probe['geocoding']['sources'])): ?><br>источники: <?= h(implode(', ', $probe['geocoding']['sources'])) ?><?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="flex between"><b>SMS</b>
      <span class="chip <?= !empty($probe['sms']['ok']) ? 'ok' : 'warn' ?>"><?= !empty($probe['sms']['ok']) ? 'работает' : 'демо-режим' ?></span>
    </div>
    <div class="mut" style="margin-top:6px"><?= h((string) ($probe['sms']['message'] ?? '')) ?></div>
  </div>
</div>
<?php endif; ?>

<div class="grid q2" style="margin-top:14px">
<?php foreach (ApiKeys::REGISTRY as $name => $meta):
    $current = ApiKeys::get($db, $name);
    $src = ApiKeys::source($db, $name); ?>
  <div class="card">
    <div class="flex between">
      <b><?= h($meta['label']) ?></b>
      <?= $sourceChip($src) ?>
    </div>
    <div class="mut" style="margin-top:6px;font-size:12px"><?= h($meta['hint']) ?></div>
    <?php if ($current !== ''): ?>
      <div class="mut" style="margin-top:8px;font-size:12px">
        <?= !empty($meta['multi']) ? 'Ключей в пуле: <b>' . ApiKeys::count($current) . '</b> · ' : 'Текущее значение: ' ?>
        <code><?= h(ApiKeys::mask($current)) ?></code>
      </div>
    <?php endif; ?>
    <?php if (!empty($meta['public'])): ?>
      <div class="chip warn" style="margin-top:8px">публичный ключ — ограничьте домен в кабинете сервиса</div>
    <?php endif; ?>

    <?php if (!empty($meta['multi'])):
        $pool = KeyPool::status($db, $name, $current);
        $freeCount = count(array_filter($pool, fn($k) => !$k['blocked'])); ?>
      <?php if ($pool): ?>
      <table style="margin-top:12px">
        <thead><tr><th>#</th><th>Ключ</th><th>Статус</th><th>Успешных</th><th>Ошибок</th></tr></thead>
        <tbody>
        <?php foreach ($pool as $k): ?>
          <tr>
            <td><?= (int) $k['index'] ?></td>
            <td><code><?= h($k['tail']) ?></code></td>
            <td>
              <?php if ($k['blocked']): ?>
                <span class="chip warn"><?= $k['lastStatus'] === 'quota_exceeded' ? 'квота исчерпана' : ($k['lastStatus'] === 'invalid_key' ? 'отклонён' : 'пауза') ?></span>
                <div class="mut" style="font-size:11px">до <?= fmt_date($k['blockedUntil']) ?></div>
              <?php else: ?>
                <span class="chip ok">в работе</span>
              <?php endif; ?>
            </td>
            <td><?= (int) $k['requestsOk'] ?></td>
            <td><?= (int) $k['requestsFailed'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="flex between" style="margin-top:8px">
        <span class="mut" style="font-size:12px">Доступно сейчас: <b><?= $freeCount ?></b> из <?= count($pool) ?> · суммарный лимит ≈ <?= count($pool) * 2500 ?> запросов/сутки</span>
        <form method="post" class="inline">
          <input type="hidden" name="cmd" value="reset_pool">
          <input type="hidden" name="service" value="<?= h($name) ?>">
          <button class="btn ghost sm">Снять блокировки</button>
        </form>
      </div>
      <?php endif; ?>

      <form method="post" style="margin-top:12px">
        <input type="hidden" name="cmd" value="save">
        <input type="hidden" name="key_name" value="<?= h($name) ?>">
        <textarea name="key_value" rows="4" autocomplete="off" spellcheck="false"
                  placeholder="Один ключ в строке — можно несколько аккаунтов"
                  style="width:100%;font-family:ui-monospace,monospace;font-size:12px"></textarea>
        <div class="mut" style="font-size:11px;margin-top:4px">Сохранение заменяет весь список ключей.</div>
        <button class="btn sm" style="margin-top:8px">Сохранить список</button>
      </form>
    <?php else: ?>
    <form method="post" class="flex" style="margin-top:12px;flex-wrap:nowrap;gap:8px">
      <input type="hidden" name="cmd" value="save">
      <input type="hidden" name="key_name" value="<?= h($name) ?>">
      <input name="key_value" placeholder="<?= $current !== '' ? 'Введите новый ключ, чтобы заменить' : 'Вставьте ключ' ?>"
             autocomplete="off" spellcheck="false" style="flex:1;font-family:ui-monospace,monospace">
      <button class="btn sm">Сохранить</button>
    </form>
    <?php endif; ?>
    <?php if ($src === 'db'): ?>
      <form method="post" style="margin-top:8px" onsubmit="return confirm('Удалить ключ из базы? Останется значение из config.local.php, если оно задано.')">
        <input type="hidden" name="cmd" value="clear">
        <input type="hidden" name="key_name" value="<?= h($name) ?>">
        <button class="btn danger sm">Удалить из базы</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<div class="card" style="margin-top:16px">
  <b>Ключи в других разделах</b>
  <p class="mut" style="margin-top:6px">Эти сервисы имеют собственные настройки и уже редактируются из панели:</p>
  <div class="flex" style="margin-top:10px">
    <a class="btn ghost sm" href="autocall.php">Автодозвон Zvonok →</a>
    <a class="btn ghost sm" href="telephony.php">SIP-телефония →</a>
  </div>
</div>

<?php layout_footer(); ?>
