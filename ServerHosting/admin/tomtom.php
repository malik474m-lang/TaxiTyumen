<?php
// Сервисы TomTom: включение/выключение каждого, расход дневной квоты и тесты.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'tomtom');
$error = '';
TomTom::ensureTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cmd = (string) ($_POST['cmd'] ?? '');
        $service = (string) ($_POST['service'] ?? '');
        $label = TomTom::REGISTRY[$service]['label'] ?? $service;

        if ($cmd === 'toggle') {
            $enabled = !empty($_POST['enabled']);
            TomTom::setEnabled($db, $service, $enabled, (string) $admin['id']);
            Bus::publish('branding');   // приложения перечитают карты
            header('Location: tomtom.php?ok=' . urlencode(
                $label . ($enabled ? ' — включён' : ' — выключен')));
            exit;
        }

        if ($cmd === 'bulk') {
            $enabled = !empty($_POST['enabled']);
            foreach (array_keys(TomTom::REGISTRY) as $key) {
                TomTom::setEnabled($db, $key, $enabled, (string) $admin['id']);
            }
            Bus::publish('branding');
            header('Location: tomtom.php?ok=' . urlencode(
                $enabled ? 'Включены все сервисы' : 'Выключены все сервисы'));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Живая проверка одного сервиса
$probeKey = (string) ($_GET['test'] ?? '');
$probe = null;
if ($probeKey !== '' && isset(TomTom::REGISTRY[$probeKey])) {
    $probe = TomTom::diagnose($db, $probeKey, $serviceSettings);
}

$services = TomTom::all($db);
$usedToday = TomTom::apiUsedToday($db);
$activeCount = count(array_filter($services, fn($s) => $s['active']));

layout_header('TomTom', 'tomtom');
?>
<?php if (!empty($_GET['ok'])): ?><div class="flash"><?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="flash" style="border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5"><?= h($error) ?></div>
<?php endif; ?>

<div class="flex between" style="margin-top:18px">
  <div>
    <h1>Сервисы TomTom</h1>
    <p class="mut" style="margin-top:4px;max-width:860px">
      Один ключ из кабинета MyTomTom обслуживает все сервисы. Каждый включается
      отдельно: выключенный не вызывается вообще, и система работает на прежних
      источниках (OSRM, DaData, Photon, Яндекс, OpenCage, тайлы OpenStreetMap).
      Бесплатный тариф — 50 000 тайлов и 2 500 остальных запросов в сутки.
    </p>
  </div>
  <div style="text-align:right">
    <span class="chip <?= TomTom::hasKey() ? 'ok' : 'bad' ?>">
      ключ: <?= TomTom::hasKey() ? 'задан' : 'не задан' ?>
    </span>
    <div style="margin-top:6px">
      <span class="chip <?= $activeCount ? 'ok' : 'warn' ?>">
        активно: <?= $activeCount ?> из <?= count($services) ?>
      </span>
    </div>
  </div>
</div>

<?php if (!TomTom::hasKey()): ?>
  <div class="card" style="margin-top:14px;border-color:rgba(250,204,21,.35)">
    Ключ TomTom не задан — все сервисы простаивают.
    Получите бесплатный ключ на <b>developer.tomtom.com</b> и внесите его в разделе
    <a href="api-keys.php">«API-ключи»</a>.
  </div>
<?php endif; ?>

<div class="card" style="margin-top:14px">
  <div class="flex between">
    <div>
      <b>Расход за сегодня:</b>
      <?= $usedToday ?> из <?= TomTom::FREE_DAILY_API ?> нетайловых запросов
      <?php if ($usedToday >= TomTom::FREE_DAILY_API): ?>
        <span class="chip bad">лимит исчерпан — пауза до полуночи UTC</span>
      <?php elseif ($usedToday > TomTom::FREE_DAILY_API * 0.8): ?>
        <span class="chip warn">приближается лимит</span>
      <?php endif; ?>
      <div class="mut" style="font-size:12px;margin-top:4px">
        Тайлы (карта, пробки, происшествия) запрашиваются самими приложениями
        и в этот счётчик не попадают — их лимит 50 000 в сутки.
      </div>
    </div>
    <div style="white-space:nowrap">
      <form method="post" class="inline">
        <input type="hidden" name="cmd" value="bulk"><input type="hidden" name="enabled" value="1">
        <button class="btn sm">Включить все</button>
      </form>
      <form method="post" class="inline">
        <input type="hidden" name="cmd" value="bulk">
        <button class="btn danger sm">Выключить все</button>
      </form>
    </div>
  </div>
</div>

<?php if ($probe !== null): ?>
  <div class="card" style="margin-top:14px;border-color:<?= $probe['ok'] ? 'rgba(74,222,128,.4)' : 'rgba(248,113,113,.4)' ?>">
    <b>Проверка «<?= h(TomTom::REGISTRY[$probeKey]['label']) ?>»:</b>
    <span class="chip <?= $probe['ok'] ? 'ok' : 'bad' ?>"><?= $probe['ok'] ? 'работает' : 'ошибка' ?></span>
    <div class="mut" style="margin-top:6px"><?= h((string) $probe['message']) ?></div>
  </div>
<?php endif; ?>

<div class="card" style="margin-top:14px">
  <table>
    <thead><tr>
      <th>Сервис</th><th style="width:110px">Тип</th>
      <th style="width:120px">Состояние</th><th style="width:110px">Запросов</th>
      <th style="width:210px">Управление</th>
    </tr></thead>
    <tbody>
    <?php foreach ($services as $key => $s): ?>
      <tr>
        <td>
          <b><?= h($s['label']) ?></b>
          <div class="mut" style="font-size:12px;margin-top:2px"><?= h($s['hint']) ?></div>
        </td>
        <td>
          <span class="chip"><?= $s['kind'] === 'tile' ? 'тайлы' : 'запросы' ?></span>
        </td>
        <td>
          <?php if (!$s['enabled']): ?>
            <span class="chip bad">выключен</span>
          <?php elseif (!$s['hasKey']): ?>
            <span class="chip warn">ждёт ключ</span>
          <?php else: ?>
            <span class="chip ok">работает</span>
          <?php endif; ?>
        </td>
        <td><?= $s['kind'] === 'tile' ? '<span class="mut">—</span>' : (int) $s['usedToday'] ?></td>
        <td style="white-space:nowrap">
          <form method="post" class="inline">
            <input type="hidden" name="cmd" value="toggle">
            <input type="hidden" name="service" value="<?= h($key) ?>">
            <input type="hidden" name="enabled" value="<?= $s['enabled'] ? '' : '1' ?>">
            <button class="btn <?= $s['enabled'] ? 'danger' : '' ?> sm">
              <?= $s['enabled'] ? 'Выключить' : 'Включить' ?>
            </button>
          </form>
          <?php if ($s['active']): ?>
            <a class="btn sm" href="tomtom.php?test=<?= h($key) ?>">Проверить</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-top:14px">
  <b>Как это работает</b>
  <ul class="mut" style="margin:8px 0 0 18px;line-height:1.7">
    <li><b>Пробки и происшествия</b> — слои в приложении водителя, кнопки поверх карты.</li>
    <li><b>Маршрут с учётом пробок</b> — заменяет OSRM: точнее время подачи и поездки.
        При сбое или исчерпании квоты система сама возвращается к OSRM.</li>
    <li><b>Поиск и адрес по координатам</b> — работают в общей цепочке
        <a href="geocoding.php">провайдеров геокодинга</a>: там же задаётся приоритет.</li>
    <li><b>Матрица, привязка трека, изохроны</b> — серверные функции: время подачи
        по пробкам, честный километраж по дорогам и зоны доступности.</li>
  </ul>
</div>
<?php layout_footer(); ?>
