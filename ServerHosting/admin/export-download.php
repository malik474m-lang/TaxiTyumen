<?php
// CSV-экспорт заказов / водителей / балансов за период (cookie admin session).
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
admin_require($db, 'export');

$type=in_array(($_GET['type']??''),['orders','drivers','balance'],true)?(string)$_GET['type']:'orders';
$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['from']??''))?$_GET['from']:gmdate('Y-m-d',time()-30*86400);
$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['to']??''))?$_GET['to']:gmdate('Y-m-d');
$fromSql=$from.' 00:00:00';$toSql=gmdate('Y-m-d H:i:s',strtotime($to.' 00:00:00 UTC')+86400);
$esc=fn($v):string=>'"'.str_replace('"','""',$v===null?'':(string)$v).'"';
$line=function(array $v)use($esc){echo implode(';',array_map($esc,$v))."\r\n";};
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="taxi-'.$type.'-'.$from.'-'.$to.'.csv"');echo "\xEF\xBB\xBF";

if($type==='orders'){
$stmt=$db->prepare("SELECT o.*,CONCAT(c.first_name,' ',c.last_name) client_full,c.phone registered_phone,CONCAT(du.first_name,' ',du.last_name) driver_full,d.license_plate,d.car_brand,d.car_model FROM orders o LEFT JOIN users c ON c.id=o.client_id LEFT JOIN drivers d ON d.id=o.driver_id LEFT JOIN users du ON du.id=d.user_id WHERE o.created_at>=? AND o.created_at<? ORDER BY o.created_at DESC");$stmt->execute([$fromSql,$toSql]);
$line(['Номер','Статус','Клиент','Телефон','Откуда','Куда','Тариф','Цена','Итог','Водитель','Авто','Создан','Завершён','Источник','Факт. км','Отзыв клиента']);
foreach($stmt->fetchAll() as $o)$line([$o['order_number'],Taxi::STATUS_TEXT[$o['status']]??$o['status'],$o['client_full']?:$o['client_name'],$o['registered_phone']?:$o['client_phone'],$o['pickup_address'],$o['destination_address'],Taxi::TARIFF_NAMES[$o['tariff']]??$o['tariff'],$o['estimated_price'],$o['final_price'],$o['driver_full'],trim(($o['car_brand']??'').' '.($o['car_model']??'').' '.($o['license_plate']??'')),$o['created_at'],$o['completed_at'],$o['source'],$o['actual_distance'],$o['client_review']]);
}elseif($type==='drivers'){
$rows=$db->query("SELECT d.*,u.first_name,u.last_name,u.phone,u.rating,u.is_blocked FROM drivers d JOIN users u ON u.id=d.user_id ORDER BY u.last_name")->fetchAll();
$line(['Имя','Телефон','Авто','Госномер','ВУ','ВУ до','Статус','Рейтинг','Баланс','Минимум','Штраф','Поездки','Заработок','Верифицирован','Верифицирован дата','Заблокирован','Банк','Телефон выплат']);
foreach($rows as $d)$line([$d['first_name'].' '.$d['last_name'],$d['phone'],$d['car_brand'].' '.$d['car_model'].' ('.$d['car_color'].')',$d['license_plate'],$d['driver_license'],$d['license_expiry'],Taxi::DRIVER_STATUS_TEXT[$d['status']]??$d['status'],$d['rating'],$d['balance'],$d['min_balance_for_orders'],$d['rejection_penalty'],$d['completed_trips'],$d['total_earnings'],$d['is_verified']?'Да':'Нет',$d['verified_at'],$d['is_blocked']?'Да':'Нет',$d['payment_bank_name'],$d['payment_phone']]);
}else{
$stmt=$db->prepare("SELECT bt.*,CONCAT(u.first_name,' ',u.last_name) driver_name,d.license_plate FROM balance_transactions bt JOIN drivers d ON d.id=bt.driver_id JOIN users u ON u.id=d.user_id WHERE bt.created_at>=? AND bt.created_at<? ORDER BY bt.created_at DESC");$stmt->execute([$fromSql,$toSql]);
$line(['Дата','Водитель','Госномер','Тип','Сумма','Баланс после','Описание','Кем']);
foreach($stmt->fetchAll() as $t)$line([$t['created_at'],$t['driver_name'],$t['license_plate'],$t['type'],$t['amount'],$t['balance_after'],$t['description'],$t['created_by']]);
}
exit;
