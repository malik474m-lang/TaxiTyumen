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
$stmt=$db->prepare($sql);$stmt->execute($params);$rows=array_reverse($stmt->fetchAll());

// Километраж: точки GPS + при включённом Snap to Roads — привязка к дорогам
$latlng = array_map(fn($p) => [(float) $p['latitude'], (float) $p['longitude']], $rows);
$dist = TomTom::trackDistance($db, $latlng);

Response::json(['items'=>array_map(fn($p)=>['id'=>$p['id'],'driverId'=>$p['driver_id'],'orderId'=>$p['order_id'],'latitude'=>(float)$p['latitude'],'longitude'=>(float)$p['longitude'],'speed'=>$p['speed']!==null?(float)$p['speed']:null,'bearing'=>$p['bearing']!==null?(float)$p['bearing']:null,'timestamp'=>$p['timestamp']],$rows),'distanceKm'=>round($dist['km'],2),'distanceByGpsKm'=>round($dist['kmGps'],2),'snapped'=>$dist['snapped'],'snappedTrack'=>$dist['geometry'],'count'=>count($rows)]);
