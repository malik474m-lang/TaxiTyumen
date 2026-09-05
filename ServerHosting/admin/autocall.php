<?php
// Автодозвон — исходный Zvonok AutoCallService + эскалация/автоназначение.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

admin_require($db, 'autocall');
$error='';
$result=null;
$s=AutoCall::getSettings($db);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $cmd=(string)($_POST['cmd']??'save');
        if($cmd==='test_call'){
            $phone=trim((string)($_POST['test_phone']??''));
            if(!$phone) { throw new RuntimeException('Укажите номер для тестового звонка'); }
            require_once dirname(__DIR__).'/src/ZvonokService.php';
            // Проверка Auth::normalizePhone
            $norm = Auth::normalizePhone($phone);
            // Имитируем заказный вызов: тот же URL/payload, что и в боевом звонке
            $settings = AutoCall::getSettings($db);
            $message = ZvonokService::formatMessage($db, (string)($settings['message_template']??''), ['driver_id'=>null,'tariff'=>'economy'], (int)($settings['free_waiting_minutes']??5));
            // надёжно подставим тестовую назначение, если переменные заполнил шаблон
            $message = str_ireplace(['{CarColor}','{CarBrand}','{CarModel}','{LicensePlate}','{FreeWaitingMinutes}'], ['Жёлтый','Kia','Rio','Т888ТЕ72','3'], $message);
            $payloadParams = [
                'public_key'    => $settings['zvonok_api_key'],
                'phone'         => preg_replace('/\D/','', $norm),
                'campaign_id'   => $settings['zvonok_campaign_id'],
                'text'          => $message,
            ];
            $speaker = trim((string)($settings['zvonok_speaker'] ?? ''));
            if ($speaker !== '') $payloadParams['speaker'] = $speaker;
            $payload = http_build_query($payloadParams);
            $started = microtime(true);
            [$code, $raw] = ZvonokService::request('https://zvonok.com/manager/cabapi_external/api/v1/phones/call/', 'POST', $payload, 'application/x-www-form-urlencoded');
            $duration = (int) round((microtime(true) - $started) * 1000);
            ZvonokService::log($db, 'test', $norm, $code >= 200 && $code < 300 ? 'success' : 'failed', $code, $raw, $duration);
            $result = [
                'ok' => $code >= 200 && $code < 300,
                'balance' => null,
                'message' => $code >= 200 && $code < 300 ? 'Тестовый звонок вышел (посмотрите ЛК Zvonok и свой телефон)' : 'Ошибка тестового звонка, HTTP '.$code,
            ] + ['_diag' => [
                'payload' => $payloadParams,
                'http' => $code,
                'response' => $raw,
            ]];
        }
        if($cmd==='check_balance'){
            $result=ZvonokService::checkBalance($db);
            $s=AutoCall::getSettings($db);
        }else{
            $minutes=max(1,min(60,(int)($_POST['escalate_after_minutes']??3)));
            $radius=max(1,min(30,(float)($_POST['auto_assign_radius_km']??5)));
            $provider=in_array(($_POST['provider']??''),['signalr','zvonok'],true)?(string)$_POST['provider']:'signalr';
            $apiKey=trim((string)($_POST['zvonok_api_key']??''));
            $campaign=trim((string)($_POST['zvonok_campaign_id']??''));
            $speaker=trim((string)($_POST['zvonok_speaker']??'Tatyana'));
            $template=trim((string)($_POST['message_template']??''));
            if($template==='')$template='Ваше такси прибыло! {CarColor} {CarBrand} {CarModel}, номер {LicensePlate}. Бесплатное ожидание: {FreeWaitingMinutes} минут.';
            $sql='UPDATE auto_call_settings SET enabled=?,escalate_after_minutes=?,auto_assign_enabled=?,auto_assign_radius_km=?,provider=?,zvonok_campaign_id=?,zvonok_speaker=?,free_waiting_minutes=?,message_template=?,last_tick_at=NULL';
            $params=[!empty($_POST['enabled'])?1:0,$minutes,!empty($_POST['auto_assign_enabled'])?1:0,$radius,$provider,$campaign?:null,$speaker,max(0,min(60,(int)($_POST['free_waiting_minutes']??5))),mb_substr($template,0,1000)];
            if($apiKey!==''){$sql.=',zvonok_api_key=?';$params[]=$apiKey;}
            $sql.=' WHERE id=?';$params[]=$s['id'];
            $db->prepare($sql)->execute($params);
            Bus::publish('autocall');
            header('Location: autocall.php?ok='.urlencode('Настройки автодозвона сохранены'));exit;
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$stuckCount=(int)$db->query("SELECT COUNT(*) FROM orders WHERE (status='searching' OR status='no_driver_found') AND driver_id IS NULL")->fetchColumn();
layout_header('Автодозвон','autocall');
?>
<h1>Автодозвон и распределение</h1>
<p class="mut">Zvonok.com при прибытии + эскалация заказов без водителя</p>
<?php if(!empty($_GET['ok'])):?><div class="flash" style="margin-top:14px">✓ <?=h((string)$_GET['ok'])?></div><?php endif;?>
<?php if($error):?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?=h($error)?></div><?php endif;?>
<?php if(isset($result['_diag'])): ?>
<div class="card" style="margin-top:14px"><h3 style="font-size:14px;margin-bottom:8px">Дно запроса (ответ API)</h3>
<div class="mut" style="font-size:11px;white-space:pre-wrap">Payload: <?=h(json_encode($result['_diag']['payload'],JSON_UNESCAPED_UNICODE))?>

HTTP <?=h((string)$result['_diag']['http'])?>
Ответ: <?=h(mb_substr((string)$result['_diag']['response'],0,400))?></div></div>
<?php endif; ?>
<?php if($result):?><div class="flash" style="margin-top:14px;border-color:<?=$result['ok']?'rgba(52,211,153,.3)':'rgba(248,113,113,.4)'?>;color:<?=$result['ok']?'#6ee7b7':'#fca5a5'?>"><?=h($result['message'])?> · баланс <?=money((float)($result['balance']??0))?></div><?php endif;?>

<form method="post" class="grid q2" style="margin-top:18px">
<input type="hidden" name="cmd" value="save">
<div class="card">
<h3 style="margin-bottom:12px">Распределение заказов</h3>
<label class="flex between" style="padding:11px;background:#0f0f13;border-radius:11px"><span><b>Сервис включён</b><div class="mut">Обработка заказов и уведомления</div></span><input type="checkbox" name="enabled" value="1" <?=$s['enabled']?'checked':''?> style="width:18px;height:18px"></label>
<label class="flex between" style="margin-top:9px;padding:11px;background:#0f0f13;border-radius:11px"><span>Эскалация через (мин)</span><input type="number" name="escalate_after_minutes" min="1" max="60" value="<?=(int)$s['escalate_after_minutes']?>" style="width:95px"></label>
<label class="flex between" style="margin-top:9px;padding:11px;background:#0f0f13;border-radius:11px"><span><b>Автоназначение</b><div class="mut">Ближайшему свободному водителю</div></span><input type="checkbox" name="auto_assign_enabled" value="1" <?=$s['auto_assign_enabled']?'checked':''?> style="width:18px;height:18px"></label>
<label class="flex between" style="margin-top:9px;padding:11px;background:#0f0f13;border-radius:11px"><span>Радиус автоназначения (км)</span><input type="number" name="auto_assign_radius_km" min="1" max="30" step="0.5" value="<?=(float)$s['auto_assign_radius_km']?>" style="width:95px"></label>
<div style="margin-top:13px;padding:12px;background:#0f0f13;border-radius:11px"><div class="mut">Сейчас без водителя</div><div style="font-size:26px;font-weight:900;color:<?=$stuckCount?'#fde047':'#6ee7b7'?>"><?=$stuckCount?></div></div>
</div>

<div class="card">
<div class="flex between"><h3>Zvonok.com</h3><span class="chip <?=!empty($s['zvonok_api_key'])&&!empty($s['zvonok_campaign_id'])?'ok':'warn'?>"><?=!empty($s['zvonok_api_key'])&&!empty($s['zvonok_campaign_id'])?'Настроен':'Не настроен'?></span></div>
<label class="mut" style="display:block;margin-top:12px">Провайдер<select name="provider"><option value="signalr" <?=($s['provider']??'signalr')==='signalr'?'selected':''?>>Только in-app уведомление</option><option value="zvonok" <?=($s['provider']??'')==='zvonok'?'selected':''?>>Zvonok + in-app</option></select></label>
<label class="mut" style="display:block;margin-top:10px">Public API key<input type="password" name="zvonok_api_key" placeholder="<?=!empty($s['zvonok_api_key'])?'Ключ сохранён — оставьте пустым':'Вставьте ключ Zvonok'?>" autocomplete="new-password"></label>
<label class="mut" style="display:block;margin-top:10px">Campaign ID<input name="zvonok_campaign_id" value="<?=h((string)($s['zvonok_campaign_id']??''))?>"></label>

<label class="mut" style="display:block;margin-top:10px">Голос диктора
  <select name="zvonok_speaker">
    <?php $cur=$s['zvonok_speaker']??'Tatyana'; foreach(['Tatyana'=>'Татьяна (женский)','Maxim'=>'Максим (мужской)'] as $val=>$label): ?>
    <option value="<?=$val?>" <?=$cur===$val?'selected':''?>><?=h($label)?></option>
    <?php endforeach; ?>
  </select>
</label>
<label class="mut" style="display:block;margin-top:10px">Бесплатное ожидание, мин<input type="number" name="free_waiting_minutes" min="0" max="60" value="<?=(int)($s['free_waiting_minutes']??5)?>"></label>
<label class="mut" style="display:block;margin-top:10px">Шаблон звонка<textarea name="message_template" rows="5"><?=h((string)($s['message_template']??''))?></textarea></label>
<div class="mut" style="font-size:11px;margin-top:6px">Переменные: {CarColor}, {CarBrand}, {CarModel}, {LicensePlate}, {FreeWaitingMinutes}, {OrderNumber}, {ClientName}</div>
<div style="margin-top:10px;padding:10px;background:#0f0f13;border-radius:10px"><span class="mut">Последний баланс:</span> <b style="color:#fde047"><?=money((float)($s['zvonok_balance']??0))?></b><span class="mut"> · <?=h(fmt_date($s['balance_checked_at']??null))?></span></div>
</div>

<button class="btn" type="submit" style="grid-column:1/-1">Сохранить все настройки</button>
</form>
<form method="post" style="margin-top:10px"><input type="hidden" name="cmd" value="check_balance"><button class="btn ghost">Проверить баланс Zvonok</button></form>

<form method="post" style="margin-top:10px" class="card">
  <input type="hidden" name="cmd" value="test_call">
  <h3 style="margin-bottom:8px">Тест звонка</h3>
  <p class="mut" style="margin-bottom:8px">Отправит звонок со шаблоном из текущих настроек на указанный номер — покажет точный ответ API.</p>
  <label class="mut" style="display:block;margin-bottom:8px">Номер<input name="test_phone" placeholder="+79..."></label>
  <button class="btn" style="width:100%">Позвонить тестово</button>
</form>

<?php layout_footer();
