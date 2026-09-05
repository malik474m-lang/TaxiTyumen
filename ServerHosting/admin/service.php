<?php
// Бренд сервиса: название, город, регион, центр карты, часовой пояс, SMS-подпись.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'service');
// Дополнительная защита: бренд сервиса меняет только супер-администратор
if (!Access::isSuperadmin($admin)) {
    http_response_code(403);
    layout_header('Нет доступа', 'index');
    echo '<div class="card" style="margin-top:20px"><h1>Раздел супер-администратора</h1>'
        . '<p class="mut" style="margin-top:8px">Бренд сервиса недоступен обычному администратору.</p>'
        . '<a class="btn ghost" style="margin-top:14px" href="index.php">← На дашборд</a></div>';
    layout_footer();
    exit;
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Чекбоксы: невыставленные в POST не приходят — передаём явно
        $payload = $_POST;
        $payload['smsOnAssigned'] = !empty($_POST['smsOnAssigned']);
        $payload['smsOnArrived'] = !empty($_POST['smsOnArrived']);
        ServiceSettings::update($db, $payload);
        Bus::publish('branding');
        header('Location: service.php?ok=' . urlencode('Бренд сервиса сохранён — применён во всех приложениях'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$s = ServiceSettings::get($db);
$offset = (int) $s['utc_offset'];
$offsetText = sprintf('UTC%+d', $offset);
$localHour = ((int) gmdate('G') + $offset + 24) % 24;
$isNight = $localHour >= 23 || $localHour < 6;
$isPeak = ($localHour >= 7 && $localHour < 9) || ($localHour >= 17 && $localHour < 19);
$tariffs = $db->query('SELECT * FROM tariffs WHERE is_active=1 ORDER BY base_fare')->fetchAll();

layout_header('Бренд сервиса', 'service');
?>
<div class="flex between">
  <div>
    <h1>Бренд сервиса</h1>
    <p class="mut">Название, город и параметры применяются во всех приложениях и уведомлениях</p>
  </div>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?= h($error) ?></div><?php endif; ?>

<form method="post" class="grid q2" style="margin-top:18px">
  <div class="card">
    <h3 style="margin-bottom:14px">Основное</h3>
    <label class="mut">Название сервиса<input name="serviceName" value="<?= h($s['service_name']) ?>" required maxlength="80"></label>
    <label class="mut" style="margin-top:10px">Город<input name="city" value="<?= h($s['city_name']) ?>" required maxlength="80"></label>
    <label class="mut" style="margin-top:10px">Регион / область<input name="region" value="<?= h($s['region_name']) ?>" maxlength="120"></label>
    <label class="mut" style="margin-top:10px">Код региона (на плитке)<input name="regionCode" value="<?= h($s['region_code']) ?>" maxlength="10" placeholder="72"></label>
    <label class="mut" style="margin-top:10px">Телефон поддержки<input name="supportPhone" value="<?= h((string) $s['support_phone']) ?>" maxlength="30"></label>
    <label class="mut" style="margin-top:10px">Подпись в SMS<input name="smsSenderName" value="<?= h($s['sms_sender_name']) ?>" maxlength="80"></label>
    <div class="mut" style="font-size:11px;margin-top:6px">Пример: «1234 — ваш код <?= h($s['sms_sender_name']) ?>»</div>

    <h3 style="font-weight:900;font-size:15px;margin:18px 0 8px">SMS-оповещения пассажира</h3>
    <label class="mut" style="display:flex;gap:8px;align-items:center;margin-top:8px">
      <input type="checkbox" name="smsOnAssigned" value="1" <?= !empty($s['sms_on_assigned']) ? 'checked' : '' ?>>
      Назначен автомобиль (марка, цвет, госномер, время подачи)
    </label>
    <label class="mut" style="display:flex;gap:8px;align-items:center;margin-top:8px">
      <input type="checkbox" name="smsOnArrived" value="1" <?= !empty($s['sms_on_arrived']) ? 'checked' : '' ?>>
      Автомобиль подан («Вас ожидает автомобиль…», бесплатное ожидание)
    </label>
    <div class="mut" style="font-size:11px;margin-top:6px">
      Требуется SMS_API_ID (sms.ru) в config.local.php. Время бесплатного ожидания
      берётся из тарифа заказа (раздел «Тарифы» → «Простой бесплатно, мин»).
    </div>
  </div>

  <div class="card">
    <h3 style="margin-bottom:14px">География и время</h3>
    <div class="grid" style="grid-template-columns:1fr 1fr">
      <label class="mut">Центр карты — широта<input type="number" step="0.0001" name="centerLat" value="<?= h((string) $s['center_latitude']) ?>" required></label>
      <label class="mut">Центр карты — долгота<input type="number" step="0.0001" name="centerLng" value="<?= h((string) $s['center_longitude']) ?>" required></label>
    </div>
    <label class="mut" style="margin-top:10px">Часовой пояс (UTC сдвиг)<input type="number" name="utcOffset" min="-11" max="13" value="<?= $offset ?>" required></label>
    <div class="mut" style="font-size:11px;margin-top:6px">
      Сейчас в <?= h($s['city_name']) ?>: <b><?= str_pad((string) $localHour, 2, '0', STR_PAD_LEFT) ?>:<?= gmdate('i') ?></b> (<?= h($offsetText) ?>)
      <?php if ($isNight): ?> · ночной тариф активен
      <?php elseif ($isPeak): ?> · час пик активен
      <?php else: ?> · обычный тариф<?php endif; ?>
    </div>
    <div style="margin-top:14px;padding:12px;background:#0f0f13;border-radius:12px">
      <div class="mut" style="font-size:11px;margin-bottom:6px">Центр используется для:</div>
      <ul style="margin-left:16px;font-size:12px;color:#a1a1aa;line-height:1.7">
        <li>подбора адреса, когда DaData/Яндекс Геокодер недоступны</li>
        <li>стартовых координат новых водителей</li>
        <li>поиска ближайших водителей при новом заказе</li>
        <li>центра поиска Яндекс Геокодера</li>
      </ul>
    </div>
    <div class="mut" style="margin-top:12px;font-size:12px">
      Активные тарифы: <?= implode(', ', array_map(fn($t) => h($t['name']), $tariffs)) ?: '—' ?>
    </div>
  </div>

  <button class="btn" type="submit" style="grid-column:1/-1">Сохранить бренд сервиса</button>
</form>

<div class="grid q2" style="margin-top:14px">
  <div class="card">
    <div class="mut" style="font-size:11px;text-transform:uppercase;letter-spacing:.15em;margin-bottom:10px">Так выглядит шапка приложений</div>
    <div class="flex">
      <div class="tile" style="width:42px;height:42px;border-radius:11px;background:#facc15;color:#0a0a0c;display:flex;align-items:center;justify-content:center;font-size:20px">🚕</div>
      <div>
        <div style="font-weight:900"><?= h($s['service_name']) ?></div>
        <div style="font-size:10px;font-weight:800;letter-spacing:.2em;text-transform:uppercase;color:#facc15"><?= h($s['region_code'] ? $s['region_code'] . ' регион' : $s['city_name']) ?></div>
      </div>
    </div>
    <?php if ($s['support_phone']): ?>
    <div class="mut" style="margin-top:12px;font-size:12px">Поддержка: <?= h((string) $s['support_phone']) ?></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="mut" style="font-size:11px;text-transform:uppercase;letter-spacing:.15em;margin-bottom:10px">Пример SMS клиенту</div>
    <div style="padding:12px;background:#0f0f13;border-radius:12px;font-size:13px">«1234 — ваш код <?= h($s['sms_sender_name']) ?>»</div>
    <div class="mut" style="margin-top:10px;font-size:12px">
      Время в городе сервиса учитывается при расчёте ночного (23:00–06:00)
      и пикового (07–09, 17–19) коэффициентов тарифов.
    </div>
  </div>
</div>

<?php layout_footer();
