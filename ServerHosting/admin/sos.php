<?php
// Мониторинг тревожных кнопок: активные SOS с картой и снятием + история.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'sos');
Sos::ensureTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (string) ($_POST['id'] ?? '');
    if ($id !== '') {
        Sos::resolve($db, $id, (string) $admin['id']);
    }
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }
    header('Location: sos.php?ok=' . urlencode('Тревога снята'));
    exit;
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['alerts' => array_map([Sos::class, 'dto'], Sos::active($db))], JSON_UNESCAPED_UNICODE);
    exit;
}

$active = Sos::active($db);
$history = Sos::history($db, 100);

layout_header('SOS-тревоги', 'sos');
?>
<div class="flex between">
  <div><h1>SOS-тревоги</h1><p class="mut">Тревожная кнопка водителей · автообновление 5 секунд</p></div>
  <span class="chip <?= $active ? 'bad' : 'ok' ?>" id="sosChip"><?= $active ? 'АКТИВНЫХ: ' . count($active) : 'Тревог нет' ?></span>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>

<div id="activeWrap" style="margin-top:16px"></div>

<div class="card" style="margin-top:18px;overflow-x:auto">
  <h3 style="font-weight:900;font-size:16px;margin-bottom:10px">История тревог</h3>
  <table><thead><tr><th>Время</th><th>Водитель</th><th>Автомобиль</th><th>Координаты</th><th>Комментарий</th><th>Статус</th></tr></thead><tbody>
  <?php foreach ($history as $a): $d = Sos::dto($a); ?>
    <tr>
      <td class="mut"><?= h(fmt_date((string) $a['created_at'])) ?></td>
      <td><b><?= h($d['driverName']) ?></b><div class="mut"><?= h((string) $d['driverPhone']) ?></div></td>
      <td class="mut"><?= h($d['carInfo']) ?></td>
      <td><a href="<?= h($d['mapUrl']) ?>" target="_blank" rel="noopener"><?= number_format($d['latitude'], 5) ?>, <?= number_format($d['longitude'], 5) ?></a></td>
      <td class="mut" style="max-width:280px;white-space:normal"><?= h((string) $d['comment']) ?></td>
      <td><span class="chip <?= $d['status'] === 'active' ? 'bad' : 'ok' ?>"><?= $d['status'] === 'active' ? 'Активна' : 'Снята' ?></span></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$history): ?><tr><td colspan="6" class="mut" style="text-align:center;padding:35px">Тревог ещё не было</td></tr><?php endif; ?>
  </tbody></table>
</div>

<script>
var CSRF_TOKEN = <?= json_encode(admin_csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
var wrap = document.getElementById('activeWrap');
var chip = document.getElementById('sosChip');
var initial = <?= json_encode(array_map([Sos::class, 'dto'], $active), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML}
function fmtTime(iso){var d=new Date(iso);return isNaN(d)?'':d.toLocaleString('ru-RU',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})}

function render(alerts){
  chip.textContent = alerts.length ? ('АКТИВНЫХ: ' + alerts.length) : 'Тревог нет';
  chip.className = 'chip ' + (alerts.length ? 'bad' : 'ok');
  if(!alerts.length){wrap.innerHTML='<div class="card mut" style="text-align:center;padding:30px">Активных тревог нет</div>';return}
  wrap.innerHTML = alerts.map(function(a){
    return '<div class="card" style="margin-bottom:10px;border-color:rgba(239,68,68,.45);background:rgba(239,68,68,.07)">'+
      '<div class="flex between" style="gap:12px;flex-wrap:wrap">'+
        '<div><div style="font-size:18px;font-weight:900;color:#fca5a5">🆘 '+esc(a.driverName)+'</div>'+
        '<div class="mut">'+esc(a.carInfo)+' · '+esc(a.driverPhone||'')+'</div>'+
        (a.comment?'<div style="margin-top:6px">«'+esc(a.comment)+'»</div>':'')+
        '<div class="mut" style="margin-top:6px">'+fmtTime(a.createdAt)+' · '+a.latitude.toFixed(5)+', '+a.longitude.toFixed(5)+'</div></div>'+
        '<div style="display:flex;gap:8px;align-items:flex-start">'+
          (a.driverPhone?'<a class="btn ghost" href="tel:'+esc(a.driverPhone)+'">Позвонить</a>':'')+
          '<a class="btn ghost" target="_blank" rel="noopener" href="'+esc(a.mapUrl)+'">На карте</a>'+
          '<button class="btn" onclick="resolveSos(\''+esc(a.id)+'\')">Снять тревогу</button>'+
        '</div>'+
      '</div></div>';
  }).join('');
}
function resolveSos(id){
  if(!confirm('Снять тревогу? Убедитесь, что водителю оказана помощь.'))return;
  var fd=new FormData();fd.append('id',id);fd.append('ajax','1');fd.append('_csrf',CSRF_TOKEN);
  fetch('sos.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()})
    .then(function(){poll()}).catch(function(){});
}
function poll(){
  fetch('sos.php?ajax=1',{credentials:'same-origin'}).then(function(r){return r.json()})
    .then(function(d){if(d&&d.alerts)render(d.alerts)}).catch(function(){});
}
render(initial);
setInterval(poll,5000);
</script>

<?php layout_footer();
