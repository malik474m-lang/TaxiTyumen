<?php
// Диагностика API и внешних сервисов: MySQL, OSRM, sms.ru, Zvonok, storage, realtime.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

admin_require($db);
$checks = [];
$message = '';

function service_badge(?bool $ok, string $yes='Работает', string $no='Ошибка', string $unknown='Не проверено'): string
{
    if ($ok === true) return '<span class="chip ok">● ' . h($yes) . '</span>';
    if ($ok === false) return '<span class="chip bad">● ' . h($no) . '</span>';
    return '<span class="chip warn">● ' . h($unknown) . '</span>';
}

// Базовые проверки без внешних запросов
$started=microtime(true);
try{$version=$db->query('SELECT VERSION()')->fetchColumn();$checks['mysql']=['ok'=>true,'detail'=>'MySQL '.$version.' · '.TAXI_DB_NAME,'ms'=>(int)round((microtime(true)-$started)*1000)];}
catch(Throwable $e){$checks['mysql']=['ok'=>false,'detail'=>$e->getMessage(),'ms'=>0];}

$storage=BrandingLogo::storageDir();if(!is_dir($storage))@mkdir($storage,0755,true);
$checks['storage']=['ok'=>is_dir($storage)&&is_writable($storage),'detail'=>'uploads/branding · '.(is_writable($storage)?'запись разрешена':'нет прав записи'),'ms'=>null];
$lastEvent=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM events')->fetchColumn();
$checks['realtime']=['ok'=>true,'detail'=>'MySQL polling · последнее событие #'.$lastEvent,'ms'=>null];
$settings=AutoCall::getSettings($db);
$checks['sms']=['ok'=>SMS_API_ID!==''?null:false,'detail'=>SMS_API_ID!==''?'Ключ настроен, нажмите «Проверить всё»':'SMS_API_ID не настроен','ms'=>null];
$zConfigured=!empty($settings['zvonok_api_key'])&&!empty($settings['zvonok_campaign_id']);
$checks['zvonok']=['ok'=>$zConfigured?null:false,'detail'=>$zConfigured?'Ключ и кампания настроены':'Ключ или campaign_id не настроены','ms'=>null];
$checks['osrm']=['ok'=>null,'detail'=>ini_get('allow_url_fopen')?'Исходящие HTTP разрешены':'allow_url_fopen выключен','ms'=>null];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $cmd=(string)($_POST['cmd']??'check_all');
    if($cmd==='check_all'||$cmd==='check_osrm'){
        $s=microtime(true);$url='https://router.project-osrm.org/route/v1/driving/65.5271,57.1459;65.5825,57.1378?overview=false';
        $ctx=stream_context_create(['http'=>['timeout'=>7,'ignore_errors'=>true,'header'=>"User-Agent: TaxiTyumen/1.0\r\n"]]);
        $raw=@file_get_contents($url,false,$ctx);$json=$raw!==false?json_decode($raw,true):null;$ok=is_array($json)&&!empty($json['routes'][0]);
        $checks['osrm']=['ok'=>$ok,'detail'=>$ok?'Маршрутизация по дорогам доступна':'Недоступен — работает fallback Haversine × 1.3','ms'=>(int)round((microtime(true)-$s)*1000)];
        $db->prepare('INSERT INTO service_call_logs(service,action,request_summary,status,response_body,duration_ms) VALUES (?,?,?,?,?,?)')->execute(['osrm','route_probe','Tyumen station → Goodwin',$ok?'success':'failed',$raw!==false?mb_substr($raw,0,1000):'connection failed',$checks['osrm']['ms']]);
    }
    if(($cmd==='check_all'||$cmd==='check_sms')&&SMS_API_ID!==''){
        $r=SmsService::check($db);$checks['sms']=['ok'=>$r['ok'],'detail'=>$r['message'].(isset($r['balance'])&&$r['balance']!==null?' · баланс '.$r['balance'].' ₽':''),'ms'=>$r['durationMs']??null];
    }
    if(($cmd==='check_all'||$cmd==='check_zvonok')&&$zConfigured){
        $r=ZvonokService::checkBalance($db);$checks['zvonok']=['ok'=>$r['ok'],'detail'=>$r['message'].' · баланс '.money((float)($r['balance']??0)),'ms'=>$r['durationMs']??null];
    }
    $message='Проверка выполнена в '.date('H:i:s');
}

$logs=$db->query('SELECT * FROM service_call_logs ORDER BY created_at DESC LIMIT 100')->fetchAll();
$endpoints=[
['POST','/api/auth/login.php','Авторизация и HMAC-токен'],['POST','/api/auth/register.php','Регистрация клиента/водителя'],['POST','/api/auth/sms.php','SMS-код send/verify'],
['GET/POST','/api/notifications.php','Уведомления, прочтение, admin-send'],['GET/POST','/api/chat.php','Чат заказа + read'],
['GET/POST','/api/orders/','Списки и создание заказа'],['POST','/api/orders/action.php','Жизненный цикл заказа'],['GET/POST','/api/drivers/','Водители и координаты'],
['GET/PUT','/api/tariffs/','Тарифы'],['POST','/api/pricing.php','OSRM-расчёт цены'],['GET/PUT','/api/branding.php','Серверный брендинг'],
['GET/POST','/api/branding-logo.php','Логотип бренда'],['GET/POST','/api/operators/shift.php','Смены операторов'],['GET/PUT','/api/autocall.php','Эскалация и Zvonok'],
['GET','/api/stats.php','Статистика'],['GET','/api/export/orders.php','CSV'],['GET','/api/events.php','Realtime polling']
];

layout_header('API и сервисы','services');
?>
<div class="flex between"><div><h1>API и внешние сервисы</h1><p class="mut">Диагностика инфраструктуры и журнал провайдеров</p></div>
<form method="post"><input type="hidden" name="cmd" value="check_all"><button class="btn">Проверить всё</button></form></div>
<?php if($message):?><div class="flash" style="margin-top:14px">✓ <?=h($message)?></div><?php endif;?>

<div class="grid q4" style="margin-top:18px">
<?php foreach([
'mysql'=>['MySQL','База, пользователи, заказы'],'osrm'=>['OSRM','Маршруты и расстояния'],'sms'=>['sms.ru','SMS-коды и рассылки'],
'zvonok'=>['Zvonok','Автодозвон клиенту'],'storage'=>['Хранилище','Логотипы брендов'],'realtime'=>['Realtime','События приложений']
] as $key=>[$name,$desc]):$c=$checks[$key];?>
<div class="card"><div class="flex between"><b style="font-size:16px"><?=h($name)?></b><?=service_badge($c['ok'])?></div><div class="mut" style="margin-top:8px"><?=h($desc)?></div><div style="margin-top:8px;font-size:12px"><?=h($c['detail'])?></div><?php if($c['ms']!==null):?><div class="mut" style="margin-top:5px"><?=$c['ms']?> мс</div><?php endif;?></div>
<?php endforeach;?>
</div>

<div class="grid q2" style="margin-top:14px">
<div class="card" style="overflow-x:auto"><div class="flex between"><h3>Контракты API</h3><a href="../api/index.php" target="_blank" class="btn ghost sm">JSON health ↗</a></div>
<table style="margin-top:8px"><thead><tr><th>Метод</th><th>URL</th><th>Назначение</th></tr></thead><tbody><?php foreach($endpoints as [$method,$url,$desc]):?><tr><td><span class="chip info"><?=h($method)?></span></td><td><code><?=h($url)?></code></td><td class="mut"><?=h($desc)?></td></tr><?php endforeach;?></tbody></table></div>
<div class="card" style="overflow-x:auto"><h3>Журнал внешних вызовов</h3><table style="margin-top:8px"><thead><tr><th>Дата</th><th>Сервис</th><th>Действие</th><th>Статус</th><th>HTTP / время</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td class="mut"><?=h(fmt_date($l['created_at']))?></td><td><b><?=h($l['service'])?></b></td><td><?=h($l['action'])?><div class="mut"><?=h((string)$l['request_summary'])?></div></td><td><span class="chip <?=$l['status']==='success'?'ok':($l['status']==='failed'?'bad':'warn')?>"><?=h($l['status'])?></span></td><td><?=h((string)($l['http_code']??'—'))?><div class="mut"><?=h((string)($l['duration_ms']??'—'))?> мс</div></td></tr><?php endforeach;?><?php if(!$logs):?><tr><td colspan="5" class="mut">Проверок ещё не было</td></tr><?php endif;?></tbody></table></div>
</div>

<?php layout_footer();
