<?php
// Лицензия на сервер такси: статус, ввод ключа, принудительная проверка.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'license');
LicenseClient::ensureTable();

$licenseMessage = '';
$licenseError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = (string) ($_POST['cmd'] ?? '');

    if ($cmd === 'activate') {
        $key = strtoupper(trim((string) ($_POST['license_key'] ?? '')));
        if ($key === '') {
            $licenseError = 'Введите ключ лицензии';
        } else {
            $result = LicenseClient::activateKey($key);
            if ($result['status'] === 'valid') {
                $licenseMessage = 'Лицензия активирована. Действует до '
                    . $result['expiresAt'];
            } else {
                $licenseError = 'Лицензия не активирована: '
                    . ($result['lastReason'] ?: 'причина неизвестна');
            }
        }
    }

    if ($cmd === 'check') {
        $result = LicenseClient::check();
        if ($result['status'] === 'valid') {
            $licenseMessage = 'Лицензия подтверждена. Действует до ' . $result['expiresAt'];
        } else {
            $licenseError = 'Лицензия недействительна: ' . $result['lastReason'];
        }
    }

    if ($cmd === 'remove') {
        LicenseClient::setKey('');
        $licenseMessage = 'Ключ лицензии удалён';
    }
}

$lic = LicenseClient::status();

layout_header('Лицензия', 'license');
?>
<div class="flex between">
  <div>
    <h1>Лицензия</h1>
    <p class="mut">Ключ лицензии на серверную часть системы такси</p>
  </div>
  <?php
  $cls = match ($lic['status']) {
    'valid' => 'ok', 'unchecked' => 'warn', default => 'bad'
  };
  $label = match ($lic['status']) {
    'valid' => 'Действует', 'unchecked' => 'Не проверена',
    'expired' => 'Истекла', 'suspended' => 'Приостановлена',
    'invalid' => 'Недействительна', default => $lic['status']
  };
  ?>
  <span class="chip <?= $cls ?>"><?= $label ?></span>
</div>

<?php if ($licenseMessage): ?>
  <div class="flash" style="margin-top:14px">✓ <?= h($licenseMessage) ?></div>
<?php endif; ?>
<?php if ($licenseError): ?>
  <div class="flash" style="margin-top:14px;color:#fca5a5">Ошибка: <?= h($licenseError) ?></div>
<?php endif; ?>

<?php if ($lic['status'] !== 'valid'): ?>
<div class="card" style="margin-top:18px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.06)">
  <h3 style="color:#fca5a5">⚠ Сервер такси заблокирован</h3>
  <p class="mut" style="margin-top:8px">
    <?php if ($lic['status'] === 'unchecked' && LicenseClient::key() === ''): ?>
      Ключ лицензии не задан. Введите ключ, выданный поставщиком.
    <?php elseif ($lic['status'] === 'expired'): ?>
      Срок лицензии истёк<?= $lic['expiresAt'] ? ' ' . $lic['expiresAt'] : '' ?>.
      API и приложения не работают. Введите новый ключ.
    <?php else: ?>
      <?= h($lic['lastReason'] ?: 'Лицензия недействительна') ?>.
      API и приложения не работают. Введите новый ключ.
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>

<div class="grid2" style="margin-top:18px">
  <div class="card">
    <h3>Ключ лицензии</h3>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="cmd" value="activate">
      <label class="mut">Ключ (формат: XXXXX-XXXXX-XXXXX-XXXXX-XXXXX)
        <input name="license_key" placeholder="ABCDE-FGHJK-LMNPR-STUVW-XYZ23"
               value="<?= h(LicenseClient::key()) ?>"
               style="font-family:monospace;font-size:14px;text-transform:uppercase">
      </label>
      <div class="flex" style="gap:8px;margin-top:12px">
        <button class="btn">Активировать</button>
        <?php if (LicenseClient::key() !== ''): ?>
          <button name="cmd" value="check" class="btn ghost">Проверить сейчас</button>
          <button name="cmd" value="remove" class="btn danger"
                  onclick="return confirm('Удалить ключ лицензии?')">Удалить</button>
        <?php endif; ?>
      </div>
    </form>
    <p class="mut" style="font-size:11px;margin-top:10px">
      Проверка выполняется автоматически раз в сутки.
      При недоступности сервера лицензий действует льготный период 3 дня.
    </p>
  </div>

  <div class="card">
    <h3>Текущая лицензия</h3>
    <table style="margin-top:10px">
      <tr><td class="mut">Статус</td><td><b><?= h($lic['status']) ?></b></td></tr>
      <tr><td class="mut">Действует до</td><td><?= $lic['expiresAt'] ?: '—' ?></td></tr>
      <tr><td class="mut">Тариф</td><td><?= h($lic['plan']) ?: '—' ?></td></tr>
      <tr><td class="mut">Максимум водителей</td><td><?= $lic['maxDrivers'] ?: '—' ?></td></tr>
      <tr><td class="mut">Клиент</td><td><?= h($lic['customerName']) ?: '—' ?></td></tr>
      <tr><td class="mut">Последняя проверка</td><td><?= $lic['lastCheckAt'] ?: '—' ?></td></tr>
      <?php if ($lic['lastReason']): ?>
      <tr><td class="mut">Причина</td><td style="color:#fca5a5"><?= h($lic['lastReason']) ?></td></tr>
      <?php endif; ?>
    </table>
    <p class="mut" style="font-size:11px;margin-top:10px">
      Сервер лицензий: <?= h(defined('LICENSE_SERVER') ? LICENSE_SERVER : 'taxi.license-prog.ru') ?>
    </p>
  </div>
</div>
<?php layout_footer();
