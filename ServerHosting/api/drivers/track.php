<?php
// GET /api/drivers/track.php?driverId=&orderId=&limit=500 — GPS-история.
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';
$claims=Guard::claims();$driverId=(string)($_GET['driverId']??'');$orderId=(string)($_GET['orderId']??'');
if($driverId==='')Response::error('driverId обязателен');
if(($claims['role']??'')==='driver'&&($claims['driverId']??'')!==$driverId)Response::error('Чужой GPS-трек недоступен',403);
if(!in_array($claims['role']??'',['driver','operator','admin'],true))Response::error('GPS-трек доступен водителю и персоналу',403);
$limit=max(1,min(2000,(int)($_GET['limit']??500)));
$sql='SELECT id,driver_id,order_id,latitude,longitude,speed,bearing,timestamp FROM driver_location_history WHERE driver_id=?';$params=[$driverId];
if($orderId!==''){$sql.=' AND order_id=?';$params[]=$orderId;}$sql.=' ORDER BY timestamp DESC LIMIT '.$limit;
$stmt=$db->prepare($sql);$stmt->execute($params);$rows=array_reverse($stmt->fetchAll());$distance=0.0;
for($i=1;$i<count($rows);$i++)$distance+=Taxi::getDistanceKm((float)$rows[$i-1]['latitude'],(float)$rows[$i-1]['longitude'],(float)$rows[$i]['latitude'],(float)$rows[$i]['longitude']);
Response::json(['items'=>array_map(fn($p)=>['id'=>$p['id'],'driverId'=>$p['driver_id'],'orderId'=>$p['order_id'],'latitude'=>(float)$p['latitude'],'longitude'=>(float)$p['longitude'],'speed'=>$p['speed']!==null?(float)$p['speed']:null,'bearing'=>$p['bearing']!==null?(float)$p['bearing']:null,'timestamp'=>$p['timestamp']],$rows),'distanceKm'=>round($distance,2),'count'=>count($rows)]);
