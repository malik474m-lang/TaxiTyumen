<?php
// Карта автопарка: живые позиции водителей, которые сейчас в сети.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'fleetmap');

// Снимаем с линии водителей без GPS дольше 5 минут, чтобы на карте не висели «призраки».
DriverTimeout::tick($db);

/** Водители на линии со свежими координатами. */
$loadOnlineDrivers = static function (\PDO $db): array {
    $rows = $db->query(
        "SELECT d.id, d.latitude, d.longitude, d.status, d.speed, d.bearing,
                d.license_plate, d.car_brand, d.car_model, d.car_color,
                d.current_order_id, d.last_location_update,
                u.first_name, u.last_name, u.phone,
                o.order_number, o.status AS order_status,
                o.pickup_address, o.destination_address
         FROM drivers d
         JOIN users u ON u.id = d.user_id
         LEFT JOIN orders o ON o.id = d.current_order_id
         WHERE d.status <> 'offline'
           AND u.is_archived = 0
           AND u.is_blocked = 0
         ORDER BY d.status, u.last_name"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $updatedAt = $r['last_location_update'] ?? null;
        $ageSeconds = $updatedAt ? max(0, time() - strtotime((string) $updatedAt . ' UTC')) : null;
        $out[] = [
            'id' => (string) $r['id'],
            'name' => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']),
            'phone' => (string) ($r['phone'] ?? ''),
            'car' => trim((string) $r['car_color'] . ' ' . (string) $r['car_brand'] . ' ' . (string) $r['car_model']),
            'plate' => (string) $r['license_plate'],
            'status' => (string) $r['status'],
            'statusText' => Taxi::DRIVER_STATUS_TEXT[$r['status']] ?? (string) $r['status'],
            'lat' => (float) $r['latitude'],
            'lng' => (float) $r['longitude'],
            // Скорость приходит в м/с — показываем км/ч.
            'speed' => $r['speed'] !== null ? round((float) $r['speed'] * 3.6) : null,
            'bearing' => $r['bearing'] !== null ? (float) $r['bearing'] : null,
            'orderNumber' => $r['order_number'] ?? null,
            'orderStatus' => $r['order_status'] ? (Taxi::STATUS_TEXT[$r['order_status']] ?? $r['order_status']) : null,
            'pickup' => $r['pickup_address'] ?? null,
            'destination' => $r['destination_address'] ?? null,
            'ageSeconds' => $ageSeconds,
        ];
    }
    return $out;
};

// Живое обновление для карты (polling).
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'drivers' => $loadOnlineDrivers($db),
        'serverTime' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$drivers = $loadOnlineDrivers($db);
$service = ServiceSettings::get($db);

layout_header('Карта автопарка', 'fleetmap');
?>
<div class="flex between">
  <div><h1>Карта автопарка</h1>
    <p class="mut">Только водители в сети · позиции обновляются каждые 5 секунд</p></div>
  <span class="chip <?= $drivers ? 'ok' : 'warn' ?>" id="fleetCount"><?= count($drivers) ?> на линии</span>
</div>

<?php if (api_key('yandex_maps') === ''): ?>
  <div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">
    Не задан ключ Яндекс Карт (api_key('yandex_maps') в config.local.php) — карта не загрузится.
  </div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:minmax(0,1fr) 340px;gap:14px;margin-top:16px">
  <div class="card" style="padding:0;overflow:hidden">
    <div id="fleetMap" style="width:100%;height:620px;background:#0f0f13"></div>
  </div>

  <div class="card" style="max-height:620px;overflow-y:auto">
    <div class="flex between" style="margin-bottom:10px">
      <h3 style="font-weight:900;font-size:15px">Водители в сети</h3>
      <span class="mut" id="fleetUpdated">—</span>
    </div>
    <div id="fleetList"></div>
  </div>
</div>

<script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU<?= api_key('yandex_maps') !== '' ? '&apikey=' . rawurlencode(api_key('yandex_maps')) : '' ?>"></script>
<script>
var CENTER = [<?= (float) $service['center_latitude'] ?>, <?= (float) $service['center_longitude'] ?>];
var initialDrivers = <?= json_encode($drivers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var map, placemarks = {}, firstFit = true;

function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML}

// Цвет метки по статусу: свободен — зелёный, на заказе — жёлтый/синий.
function presetFor(status){
  if(status==='available') return 'islands#greenAutoCircleIcon';
  if(status==='in_trip')   return 'islands#blueAutoCircleIcon';
  if(status==='on_route')  return 'islands#orangeAutoCircleIcon';
  return 'islands#grayAutoCircleIcon';
}

function balloonFor(d){
  return '<b>'+esc(d.name)+'</b><br>'+esc(d.car)+' · <b>'+esc(d.plate)+'</b><br>'+
    esc(d.statusText)+(d.speed!==null?' · '+esc(d.speed)+' км/ч':'')+
    (d.orderNumber?'<br>Заказ: '+esc(d.orderNumber)+' ('+esc(d.orderStatus||'')+')':'')+
    (d.pickup?'<br>Подача: '+esc(d.pickup):'')+
    '<br><a href="driver-track.php?id='+esc(d.id)+'">GPS-трек →</a>';
}

function renderList(drivers){
  document.getElementById('fleetCount').textContent = drivers.length + ' на линии';
  document.getElementById('fleetUpdated').textContent = new Date().toLocaleTimeString('ru-RU');
  var box = document.getElementById('fleetList');
  if(!drivers.length){
    box.innerHTML = '<div class="mut" style="text-align:center;padding:30px">Сейчас никого нет в сети</div>';
    return;
  }
  box.innerHTML = drivers.map(function(d){
    var stale = d.ageSeconds !== null && d.ageSeconds > 120;
    return '<div style="padding:10px;border:1px solid var(--line);border-radius:11px;margin-bottom:8px;cursor:pointer" onclick="focusDriver(\'' + esc(d.id) + '\')">'+
      '<div style="font-weight:800">'+esc(d.name)+'</div>'+
      '<div class="mut" style="font-size:12px">'+esc(d.car)+' · '+esc(d.plate)+'</div>'+
      '<div style="margin-top:5px"><span class="chip '+(d.status==='available'?'ok':'warn')+'">'+esc(d.statusText)+'</span>'+
      (d.speed!==null?' <span class="mut" style="font-size:12px">'+esc(d.speed)+' км/ч</span>':'')+
      (stale?' <span class="chip bad">нет GPS &gt;2 мин</span>':'')+'</div>'+
      (d.orderNumber?'<div class="mut" style="font-size:12px;margin-top:4px">Заказ '+esc(d.orderNumber)+'</div>':'')+
    '</div>';
  }).join('');
}

function focusDriver(id){
  var pm = placemarks[id];
  if(pm && map){ map.setCenter(pm.geometry.getCoordinates(), 16, {duration:300}); pm.balloon.open(); }
}

function syncDrivers(drivers){
  if(!map) return;
  var seen = {};
  drivers.forEach(function(d){
    seen[d.id] = true;
    var coords = [d.lat, d.lng];
    if(placemarks[d.id]){
      // Двигаем существующую метку — карта не мигает и не перезагружается.
      placemarks[d.id].geometry.setCoordinates(coords);
      placemarks[d.id].properties.set({
        iconCaption: d.plate,
        balloonContent: balloonFor(d)
      });
      placemarks[d.id].options.set('preset', presetFor(d.status));
    } else {
      var pm = new ymaps.Placemark(coords, {
        iconCaption: d.plate,
        balloonContent: balloonFor(d)
      }, { preset: presetFor(d.status) });
      placemarks[d.id] = pm;
      map.geoObjects.add(pm);
    }
  });
  // Ушедшие в offline исчезают с карты.
  Object.keys(placemarks).forEach(function(id){
    if(!seen[id]){ map.geoObjects.remove(placemarks[id]); delete placemarks[id]; }
  });

  if(firstFit && drivers.length){
    firstFit = false;
    var bounds = drivers.map(function(d){ return [d.lat, d.lng]; });
    if(bounds.length === 1) map.setCenter(bounds[0], 14);
    else map.setBounds(ymaps.util.bounds.fromPoints(bounds), {checkZoomRange:true, zoomMargin:60});
  }
  renderList(drivers);
}

function poll(){
  fetch('fleet-map.php?ajax=1', {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){ if(d && d.drivers) syncDrivers(d.drivers); })
    .catch(function(){});
}

if (window.ymaps) {
  ymaps.ready(function(){
    map = new ymaps.Map('fleetMap', {
      center: CENTER, zoom: 12, controls: ['zoomControl','typeSelector','fullscreenControl']
    }, { suppressMapOpenBlock: true });
    syncDrivers(initialDrivers);
    setInterval(poll, 5000);
  });
} else {
  document.getElementById('fleetMap').innerHTML =
    '<div class="mut" style="padding:30px;text-align:center">Не удалось загрузить Яндекс Карты</div>';
  renderList(initialDrivers);
}
</script>

<?php layout_footer();
