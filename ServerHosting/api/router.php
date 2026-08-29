<?php
// REST compatibility layer: исходные ASP.NET маршруты → PHP handlers.
// Позволяет TaxiClient/TaxiDriver/TaxiOperator ApiService работать без смены URL-контрактов.
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$route = trim((string) ($_GET['route'] ?? ''), '/');
$routeLower = strtolower($route);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (str_starts_with($routeLower, 'orders')) $GLOBALS['order_compat_response'] = true;
if (str_starts_with($routeLower, 'drivers')) $GLOBALS['driver_compat_response'] = true;
$raw = file_get_contents('php://input') ?: '';
$decoded = json_decode($raw, true);
$body = is_array($decoded) ? $decoded : (!empty($_POST) ? $_POST : []);
// System.Text.Json из C# отправляет PascalCase; PHP/web — camelCase.
// Приводим первую букву ключей к camelCase, сохраняя уже правильные ключи.
if (is_array($body)) {
    $normalized = [];
    foreach ($body as $key => $value) {
        $normalized[is_string($key) ? lcfirst($key) : $key] = $value;
    }
    $body = $normalized;
}

$dispatch = function (string $file, array $override = [], array $query = []) use ($body): never {
    Response::setBodyOverride(array_merge($body, $override));
    foreach ($query as $key => $value) $_GET[$key] = $value;
    require $file;
    exit;
};
$api = __DIR__;

// ── AuthController ──────────────────────────────────────────────────────────
if ($routeLower === 'auth/login') {$GLOBALS['auth_compat_response']=true;$dispatch($api . '/auth/login.php');}
if ($routeLower === 'auth/register') {$GLOBALS['auth_compat_response']=true;$dispatch($api . '/auth/register.php');}
if ($routeLower === 'auth/send-sms') {
    $phone = is_string($decoded) ? $decoded : (string) ($body['phone'] ?? '');
    $dispatch($api . '/auth/sms.php', ['action' => 'send', 'phone' => $phone]);
}
if ($routeLower === 'auth/verify-sms') $dispatch($api . '/auth/sms.php', ['action' => 'verify']);
if ($routeLower === 'auth/refresh') {
    $claims = Auth::decodeToken((string) ($body['token'] ?? ''), true);
    if (!$claims) Response::error('Невалидный токен', 401);
    $stmt = $db->prepare('SELECT * FROM users WHERE id=? AND is_active=1 AND is_blocked=0 LIMIT 1');
    $stmt->execute([$claims['uid']]);
    $user = $stmt->fetch();
    if (!$user) Response::error('Пользователь не найден', 404);
    $driverId = null;
    if ($user['role'] === 'driver') {
        $d=$db->prepare('SELECT id FROM drivers WHERE user_id=? LIMIT 1');$d->execute([$user['id']]);$driverId=$d->fetchColumn()?:null;
    }
    Response::json(['user'=>array_merge(Serialize::user($user,$driverId),['token'=>Auth::signToken($user['id'],$user['role'],$driverId)])]);
}

// ── DriversController ───────────────────────────────────────────────────────
if ($routeLower === 'drivers/online') $dispatch($api . '/drivers/index.php', [], ['online' => '1']);
if (preg_match('#^drivers/([0-9a-f-]+)/(location|status)$#i', $route, $m)) {
    $payload=['id'=>$m[1],'action'=>strtolower($m[2])];
    if (strtolower($m[2])==='location') {
        $payload += [
            'latitude'=>$body['latitude']??$body['Latitude']??0,
            'longitude'=>$body['longitude']??$body['Longitude']??0,
            'speed'=>$body['speed']??$body['Speed']??null,
            'bearing'=>$body['bearing']??$body['Bearing']??null,
            'orderId'=>$body['orderId']??$body['OrderId']??null,
        ];
    } else {
        $status=$decoded;
        if(is_array($decoded))$status=$body['status']??$body['Status']??'offline';
        $map=[0=>'offline',1=>'available',2=>'on_route',3=>'in_trip',4=>'busy'];
        $payload['status']=is_numeric($status)
            ? ($map[(int)$status]??'offline')
            : strtolower(preg_replace('/(?<!^)[A-Z]/','_$0',(string)$status));
    }
    $_SERVER['REQUEST_METHOD']='POST';
    $dispatch($api . '/drivers/action.php',$payload);
}
if (preg_match('#^drivers/([0-9a-f-]+)$#i', $route, $m) && $method==='GET') {
    $stmt=$db->prepare('SELECT d.*,u.first_name,u.last_name,u.phone AS user_phone,u.rating AS user_rating FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=? LIMIT 1');
    $stmt->execute([$m[1]]);$driver=$stmt->fetch();
    if(!$driver)Response::error('Водитель не найден',404);
    Response::json(Serialize::driver($driver));
}

// ── BalanceController ───────────────────────────────────────────────────────
if (preg_match('#^balance/([0-9a-f-]+)(?:/(topup|history))?$#i',$route,$m)) {
    $claims=Guard::claims();$driverId=$m[1];$op=strtolower($m[2]??'');
    if($op==='topup'){
        Guard::role($claims,'operator','admin');$_SERVER['REQUEST_METHOD']='POST';
        $dispatch($api.'/drivers/action.php',['id'=>$driverId,'action'=>'topup','amount'=>$body['amount']??$body['Amount']??0,'createdBy'=>$body['createdBy']??$body['CreatedBy']??'Оператор']);
    }
    if(($claims['role']??'')==='driver'&&($claims['driverId']??'')!==$driverId)Response::error('Чужой баланс недоступен',403);
    if($op==='history')$GLOBALS['balance_history_compat']=true;
    $dispatch($api.'/drivers/action.php',[],['id'=>$driverId,'view'=>$op==='history'?'history':'balance']);
}

// ── ChatController ──────────────────────────────────────────────────────────
if ($routeLower === 'chat/send') {
    $GLOBALS['chat_compat_response']=true;
    $dispatch($api.'/chat.php',[
        'orderId'=>$body['orderId']??$body['OrderId']??'',
        'senderId'=>$body['senderId']??$body['SenderId']??'',
        'text'=>$body['text']??$body['Text']??'',
    ]);
}
if (preg_match('#^chat/([0-9a-f-]+)/read$#i',$route,$m)) {
    $dispatch($api.'/chat.php',['action'=>'read','orderId'=>$m[1]]);
}
if (preg_match('#^chat/([0-9a-f-]+)$#i',$route,$m) && $method==='GET') {
    $dispatch($api.'/chat.php',[],['orderId'=>$m[1]]);
}

// ── OperatorsController ─────────────────────────────────────────────────────
if ($routeLower==='operators/shift/start') $dispatch($api.'/operators/shift.php',['action'=>'start']);
if ($routeLower==='operators/shift/end') $dispatch($api.'/operators/shift.php',['action'=>'end']);

// ── OrdersController ────────────────────────────────────────────────────────
if ($routeLower==='orders' && $method==='POST') $dispatch($api.'/orders/index.php');
if ($routeLower==='orders/active') $dispatch($api.'/orders/index.php',[],['view'=>'active']);
if ($routeLower==='orders/available') $dispatch($api.'/orders/index.php',[],array_merge($_GET,['view'=>'available']));
if ($routeLower==='orders/operator') $dispatch($api.'/orders/operator.php');
if (preg_match('#^orders/history/([0-9a-f-]+)$#i',$route,$m)) {
    $stmt=$db->prepare('SELECT role FROM users WHERE id=?');$stmt->execute([$m[1]]);$role=$stmt->fetchColumn();
    if($role==='driver'){$d=$db->prepare('SELECT id FROM drivers WHERE user_id=?');$d->execute([$m[1]]);$dispatch($api.'/orders/index.php',[],['view'=>'history','driverId'=>$d->fetchColumn()]);}
    $dispatch($api.'/orders/index.php',[],['view'=>'history','clientId'=>$m[1]]);
}
if (preg_match('#^orders/([0-9a-f-]+)/(accept|reject|complete|force-assign|cancel|rate|status)$#i',$route,$m)) {
    $action=strtolower($m[2]);
    $map=['force-assign'=>'assign'];$action=$map[$action]??$action;
    if($action==='status'){
        $status=is_array($decoded)?($body['status']??$body['Status']??0):$decoded;
        $statusMap=[3=>'en_route',4=>'arrived',5=>'start',6=>'complete',7=>'cancel'];
        $textMap=['driverenroute'=>'en_route','driverarrived'=>'arrived','inprogress'=>'start','completed'=>'complete','cancelled'=>'cancel'];
        $rawStatus=strtolower(preg_replace('/[^a-z]/i','',(string)$status));
        $action=$statusMap[(int)$status]??$textMap[$rawStatus]??strtolower((string)$status);
    }
    $dispatch($api.'/orders/action.php',[
        'id'=>$m[1],'action'=>$action,
        'driverId'=>$body['driverId']??$body['DriverId']??$_GET['driverId']??null,
        'reason'=>$body['reason']??$body['Reason']??(is_string($decoded)?$decoded:null),
        'rating'=>$body['rating']??$body['Rating']??null,
        'review'=>$body['review']??$body['Review']??null,
        'isClient'=>$body['isClient']??$body['IsClient']??true,
    ]);
}
if (preg_match('#^orders/([0-9a-f-]+)$#i',$route,$m) && $method==='GET') $dispatch($api.'/orders/item.php',[],['id'=>$m[1]]);

// ── PricingController ───────────────────────────────────────────────────────
if ($routeLower==='pricing/estimate'||$routeLower==='pricing/estimate-all') {
    Response::setBodyOverride([
        'fromLat'=>(float)($_GET['fromLat']??0),'fromLng'=>(float)($_GET['fromLng']??0),
        'toLat'=>(float)($_GET['toLat']??0),'toLng'=>(float)($_GET['toLng']??0),
    ]);
    $GLOBALS['pricing_compat_mode']=$routeLower==='pricing/estimate'?'single':'all';
    $GLOBALS['pricing_compat_tariff']=$_GET['tariff']??0;
    require $api.'/pricing.php';exit;
}

// Новые серверные сервисы (не были в старом .NET-контракте)
if ($routeLower==='notifications') $dispatch($api.'/notifications.php');
if ($routeLower==='services') $dispatch($api.'/services.php');

Response::error('Маршрут API не найден: '.$route,404);
