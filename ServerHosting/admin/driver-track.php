<?php
// Просмотр GPS-трека водителя/заказа в админке.
declare(strict_types=1);
require_once __DIR__.'/_init.php';admin_require($db, 'drivers');
$id=(string)($_GET['id']??'');$orderId=(string)($_GET['order']??'');
$d=$db->prepare('SELECT d.*,u.first_name,u.last_name FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=?');$d->execute([$id]);$driver=$d->fetch();if(!$driver){http_response_code(404);exit('Водитель не найден');}
$sql='SELECT * FROM driver_location_history WHERE driver_id=?';$params=[$id];if($orderId!==''){$sql.=' AND order_id=?';$params[]=$orderId;}$sql.=' ORDER BY timestamp DESC LIMIT 1000';$s=$db->prepare($sql);$s->execute($params);$points=array_reverse($s->fetchAll());$distance=0.0;for($i=1;$i<count($points);$i++)$distance+=Taxi::getDistanceKm((float)$points[$i-1]['latitude'],(float)$points[$i-1]['longitude'],(float)$points[$i]['latitude'],(float)$points[$i]['longitude']);
$orderRows=$db->prepare('SELECT id,order_number,status,created_at FROM orders WHERE driver_id=? ORDER BY created_at DESC LIMIT 50');$orderRows->execute([$id]);$orders=$orderRows->fetchAll();
layout_header('GPS-трек','drivers');
?>
<div class="flex between"><div><h1>GPS-трек · <?=h($driver['first_name'].' '.$driver['last_name'])?></h1><p class="mut"><?=h($driver['license_plate'])?> · точек <?=count($points)?> · <?=number_format($distance,2)?> км</p></div><a class="btn ghost" href="drivers.php">← Водители</a></div>
<form method="get" class="flex" style="margin:16px 0"><input type="hidden" name="id" value="<?=h($id)?>"><select name="order"><option value="">Все точки</option><?php foreach($orders as $o):?><option value="<?=h($o['id'])?>" <?=$orderId===$o['id']?'selected':''?>><?=h($o['order_number'].' · '.(Taxi::STATUS_TEXT[$o['status']]??$o['status']).' · '.fmt_date($o['created_at']))?></option><?php endforeach;?></select><button class="btn ghost">Показать</button></form>
<div class="card" style="overflow-x:auto"><table><thead><tr><th>Время</th><th>Широта</th><th>Долгота</th><th>Скорость</th><th>Курс</th><th>Заказ</th></tr></thead><tbody><?php foreach(array_reverse($points) as $p):?><tr><td><?=h(fmt_date($p['timestamp']))?></td><td><?=number_format((float)$p['latitude'],6)?></td><td><?=number_format((float)$p['longitude'],6)?></td><td><?=$p['speed']!==null?number_format((float)$p['speed']*3.6,1).' км/ч':'—'?></td><td><?=$p['bearing']!==null?number_format((float)$p['bearing'],0).'°':'—'?></td><td class="mut"><?=h((string)$p['order_id'])?></td></tr><?php endforeach;?><?php if(!$points):?><tr><td colspan="6" class="mut" style="padding:40px;text-align:center">GPS-точек ещё нет. Они сохраняются приложением водителя каждые 5 секунд.</td></tr><?php endif;?></tbody></table></div>
<?php layout_footer();
