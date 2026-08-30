<?php
// Монитор чата водителей автопарка: живой поток (polling 4 с) + модерация.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'fleet');
FleetChat::ensureTables($db);

// Модерация: удаление сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (string) ($_POST['id'] ?? '');
    if ($id !== '') {
        FleetChat::remove($db, $id);
        Bus::publish('fleet');
    }
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }
    header('Location: fleet-chat.php?ok=' . urlencode('Сообщение удалено'));
    exit;
}

// JSON-режим для живого обновления (только свежие сообщения)
if (isset($_GET['ajax'])) {
    $after = (int) ($_GET['after'] ?? 0);
    $rows = FleetChat::history($db, $after);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'messages' => array_map([FleetChat::class, 'dto'], $rows),
        'now' => (int) round(microtime(true) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = FleetChat::history($db);
layout_header('Чат водителей', 'fleet');
?>
<div class="flex between">
  <div><h1>Чат водителей</h1><p class="mut">Общий канал автопарка · обновление каждые 4 секунды</p></div>
  <span class="chip ok" id="liveChip"><span id="msgCount"><?= count($rows) ?></span> сообщений · <span id="liveAt">live</span></span>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>

<div class="card" style="margin-top:14px">
  <div id="chatList" style="display:grid;gap:8px;max-height:65vh;overflow-y:auto">
  </div>
  <div id="emptyState" class="mut" style="text-align:center;padding:35px;display:none">Водители пока молчат</div>
</div>

<script>
var lastMs = 0;
var list = document.getElementById('chatList');
var emptyState = document.getElementById('emptyState');
var initial = <?= json_encode(array_map([FleetChat::class, 'dto'], $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML}
function fmtTime(iso){var d=new Date(iso);return isNaN(d)?'':d.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'})}
function msOf(m){var t=Date.parse(m.createdAt);return isNaN(t)?0:t}

function renderMsg(m){
  var row=document.createElement('div');
  row.className='fleet-msg';row.dataset.id=m.id;
  row.style.cssText='display:flex;gap:10px;align-items:flex-start;padding:10px 12px;background:#0f0f13;border:1px solid var(--line);border-radius:12px';
  row.innerHTML=
    '<div style="flex:1;min-width:0">'+
      '<div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">'+
        '<span style="font-weight:800;font-size:12px;color:#fde047">'+esc(m.senderName)+
          '<span style="font-weight:500;color:#71717a;margin-left:6px">'+esc(m.carInfo)+'</span></span>'+
        '<span style="font-size:11px;color:#52525b;flex-shrink:0">'+fmtTime(m.createdAt)+'</span>'+
      '</div>'+
      '<div style="margin-top:3px;font-size:14px;color:#e4e4e7;word-break:break-word">'+esc(m.text)+'</div>'+
    '</div>'+
    '<button type="button" class="btn ghost" style="padding:6px 8px;font-size:11px;color:#fca5a5;flex-shrink:0" '+
      'onclick="delMsg(\''+esc(m.id)+'\',this)" title="Удалить сообщение">✕</button>';
  return row;
}
function updateCount(){
  var n=list.children.length;
  document.getElementById('msgCount').textContent=n;
  emptyState.style.display=n?'none':'block';
}
function appendAll(items){
  items.forEach(function(m){
    var t=msOf(m);if(t>lastMs)lastMs=t;
    if(list.querySelector('[data-id="'+m.id+'"]'))return;
    list.appendChild(renderMsg(m));
    list.scrollTop=list.scrollHeight;
  });
  updateCount();
}
function poll(){
  fetch('fleet-chat.php?ajax=1&after='+lastMs,{credentials:'same-origin'})
    .then(function(r){return r.json()})
    .then(function(d){
      if(d&&d.messages)appendAll(d.messages);
      document.getElementById('liveAt').textContent=new Date().toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    })
    .catch(function(){});
}
function delMsg(id,btn){
  if(!confirm('Удалить это сообщение?'))return;
  var fd=new FormData();fd.append('id',id);fd.append('ajax','1');
  fetch('fleet-chat.php',{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json()})
    .then(function(d){if(d&&d.ok){list.querySelectorAll('[data-id="'+id+'"]').forEach(function(e){e.remove()});updateCount();}})
    .catch(function(){});
}
appendAll(initial);
list.scrollTop=list.scrollHeight;
if(initial.length){lastMs=initial.reduce(function(a,m){return Math.max(a,msOf(m))},0)}
setInterval(poll,4000);poll();
</script>

<?php layout_footer();
