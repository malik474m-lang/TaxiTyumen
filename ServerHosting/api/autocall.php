<?php
// GET /api/autocall.php — настройки (operator/admin; секрет не отдаётся)
// PUT /api/autocall.php — изменение (admin)
// POST {action:check_balance} — проверка Zvonok (admin)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$dto=function(array $s):array{return[
'id'=>$s['id'],'enabled'=>(bool)$s['enabled'],'escalateAfterMinutes'=>(int)$s['escalate_after_minutes'],
'autoAssignEnabled'=>(bool)$s['auto_assign_enabled'],'autoAssignRadiusKm'=>(float)$s['auto_assign_radius_km'],
'provider'=>$s['provider']??'signalr','zvonokConfigured'=>!empty($s['zvonok_api_key'])&&!empty($s['zvonok_campaign_id']),
'zvonokCampaignId'=>$s['zvonok_campaign_id']??null,'zvonokBalance'=>(float)($s['zvonok_balance']??0),
'balanceCheckedAt'=>$s['balance_checked_at']??null,'freeWaitingMinutes'=>(int)($s['free_waiting_minutes']??5),
'messageTemplate'=>$s['message_template']??''
];};

if($_SERVER['REQUEST_METHOD']==='GET'){
$claims=Guard::claims();Guard::role($claims,'operator','admin');Response::json($dto(AutoCall::getSettings($db)));
}
if($_SERVER['REQUEST_METHOD']==='POST'){
$claims=Guard::claims();Guard::role($claims,'admin');$body=Response::requirePostJson();
if(($body['action']??'')==='check_balance')Response::json(ZvonokService::checkBalance($db));
Response::error('Неизвестный action');
}
if($_SERVER['REQUEST_METHOD']==='PUT'){
$claims=Guard::claims();Guard::role($claims,'admin');$body=Response::requirePostJson();$s=AutoCall::getSettings($db);
$provider=in_array(($body['provider']??''),['signalr','zvonok'],true)?(string)$body['provider']:($s['provider']??'signalr');
$sql='UPDATE auto_call_settings SET enabled=?,escalate_after_minutes=?,auto_assign_enabled=?,auto_assign_radius_km=?,provider=?,zvonok_campaign_id=?,free_waiting_minutes=?,message_template=?,last_tick_at=NULL';
$params=[isset($body['enabled'])?((bool)$body['enabled']?1:0):$s['enabled'],max(1,min(60,(int)($body['escalateAfterMinutes']??$s['escalate_after_minutes']))),isset($body['autoAssignEnabled'])?((bool)$body['autoAssignEnabled']?1:0):$s['auto_assign_enabled'],max(1,min(30,(float)($body['autoAssignRadiusKm']??$s['auto_assign_radius_km']))),$provider,$body['zvonokCampaignId']??$s['zvonok_campaign_id'],max(0,min(60,(int)($body['freeWaitingMinutes']??$s['free_waiting_minutes']))),mb_substr((string)($body['messageTemplate']??$s['message_template']),0,1000)];
$key=trim((string)($body['zvonokApiKey']??''));if($key!==''){$sql.=',zvonok_api_key=?';$params[]=$key;}$sql.=' WHERE id=?';$params[]=$s['id'];
$db->prepare($sql)->execute($params);Bus::publish('autocall');Response::json($dto(AutoCall::getSettings($db)));
}
Response::error('Метод не поддерживается',405);
