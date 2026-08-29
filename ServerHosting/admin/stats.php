<?php
// Расширенная статистика — порт TaxiAdmin/Stats.razor.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

admin_require($db);

$requestedPeriod = (int) ($_GET['days'] ?? 30);
$period = in_array($requestedPeriod, [7, 30, 90, 365], true) ? $requestedPeriod : 30;
$since = gmdate('Y-m-d H:i:s', time() - $period * 86400);

$scalar = function(string $sql,array $p=[])use($db){$s=$db->prepare($sql);$s->execute($p);return $s->fetchColumn();};
$total=(int)$scalar('SELECT COUNT(*) FROM orders WHERE created_at>=?',[$since]);
$completed=(int)$scalar("SELECT COUNT(*) FROM orders WHERE status='completed' AND created_at>=?",[$since]);
$cancelled=(int)$scalar("SELECT COUNT(*) FROM orders WHERE status='cancelled' AND created_at>=?",[$since]);
$revenue=(float)$scalar("SELECT COALESCE(SUM(final_price),0) FROM orders WHERE status='completed' AND created_at>=?",[$since]);
$avg=(float)$scalar("SELECT COALESCE(AVG(final_price),0) FROM orders WHERE status='completed' AND created_at>=?",[$since]);
$conversion=$total>0?round($completed/$total*100,1):0;

$byTariff=$db->prepare("SELECT tariff,COUNT(*) cnt,COALESCE(SUM(final_price),0) revenue,COALESCE(AVG(final_price),0) avg_price FROM orders WHERE created_at>=? GROUP BY tariff ORDER BY cnt DESC");
$byTariff->execute([$since]);$tariffs=$byTariff->fetchAll();
$byStatus=$db->prepare('SELECT status,COUNT(*) cnt FROM orders WHERE created_at>=? GROUP BY status ORDER BY cnt DESC');
$byStatus->execute([$since]);$statuses=$byStatus->fetchAll();
$topDrivers=$db->prepare("SELECT u.first_name,u.last_name,d.license_plate,COUNT(o.id) trips,COALESCE(SUM(o.final_price),0) revenue,u.rating FROM orders o JOIN drivers d ON d.id=o.driver_id JOIN users u ON u.id=d.user_id WHERE o.status='completed' AND o.completed_at>=? GROUP BY d.id,u.first_name,u.last_name,d.license_plate,u.rating ORDER BY trips DESC LIMIT 10");
$topDrivers->execute([$since]);$drivers=$topDrivers->fetchAll();
$topOperators=$db->prepare("SELECT u.first_name,u.last_name,COUNT(o.id) orders_count FROM orders o JOIN users u ON u.id=o.operator_id WHERE o.created_at>=? GROUP BY u.id,u.first_name,u.last_name ORDER BY orders_count DESC LIMIT 10");
$topOperators->execute([$since]);$operators=$topOperators->fetchAll();

layout_header('Статистика','stats');
?>
<div class="flex between"><div><h1>Статистика</h1><p class="mut">Эффективность сервиса за период</p></div>
<form method="get"><select name="days" onchange="this.form.submit()"><?php foreach([7=>'7 дней',30=>'30 дней',90=>'90 дней',365=>'Год'] as $d=>$l): ?><option value="<?=$d?>" <?=$period===$d?'selected':''?>><?=h($l)?></option><?php endforeach;?></select></form></div>

<div class="grid q4" style="margin-top:18px">
<div class="card"><div class="mut">Всего заказов</div><div class="stat-big"><?=$total?></div></div>
<div class="card"><div class="mut">Завершено</div><div class="stat-big" style="color:#6ee7b7"><?=$completed?></div><div class="mut">Конверсия <?=$conversion?>%</div></div>
<div class="card"><div class="mut">Выручка</div><div class="stat-big" style="color:#fde047"><?=money($revenue)?></div></div>
<div class="card"><div class="mut">Средний чек</div><div class="stat-big"><?=money($avg)?></div><div class="mut">Отменено: <?=$cancelled?></div></div>
</div>

<div class="grid q2" style="margin-top:14px">
<div class="card"><h3>По тарифам</h3><table><thead><tr><th>Тариф</th><th>Заказы</th><th>Средний чек</th><th>Выручка</th></tr></thead><tbody><?php foreach($tariffs as $t): ?><tr><td><b><?=h(Taxi::TARIFF_NAMES[$t['tariff']]??$t['tariff'])?></b></td><td><?=(int)$t['cnt']?></td><td><?=money((float)$t['avg_price'])?></td><td style="color:#fde047;font-weight:900"><?=money((float)$t['revenue'])?></td></tr><?php endforeach;?></tbody></table></div>
<div class="card"><h3>По статусам</h3><table><tbody><?php foreach($statuses as $s): ?><tr><td><?=h(Taxi::STATUS_TEXT[$s['status']]??$s['status'])?></td><td style="text-align:right;font-weight:900"><?=(int)$s['cnt']?></td></tr><?php endforeach;?></tbody></table></div>
<div class="card"><h3>Топ водителей</h3><table><thead><tr><th>Водитель</th><th>Поездки</th><th>Рейтинг</th><th>Оборот</th></tr></thead><tbody><?php foreach($drivers as $d): ?><tr><td><b><?=h($d['first_name'].' '.$d['last_name'])?></b><div class="mut"><?=h($d['license_plate'])?></div></td><td><?=(int)$d['trips']?></td><td>★ <?=number_format((float)$d['rating'],1)?></td><td style="color:#fde047"><?=money((float)$d['revenue'])?></td></tr><?php endforeach;?></tbody></table></div>
<div class="card"><h3>Операторы</h3><table><thead><tr><th>Оператор</th><th style="text-align:right">Создано заказов</th></tr></thead><tbody><?php foreach($operators as $o): ?><tr><td><b><?=h($o['first_name'].' '.$o['last_name'])?></b></td><td style="text-align:right;font-weight:900"><?=(int)$o['orders_count']?></td></tr><?php endforeach;?><?php if(!$operators):?><tr><td colspan="2" class="mut">Нет данных</td></tr><?php endif;?></tbody></table></div>
</div>

<?php layout_footer();
