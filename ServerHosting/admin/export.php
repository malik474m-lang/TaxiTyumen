<?php
// Экспорт — порт TaxiAdmin/Export.razor: период + три набора данных.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
admin_require($db);
$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['from']??''))?$_GET['from']:gmdate('Y-m-d',time()-30*86400);
$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['to']??''))?$_GET['to']:gmdate('Y-m-d');
layout_header('Экспорт','export');
?>
<h1>Экспорт данных</h1><p class="mut">Заказы, водители и операции баланса в CSV для Excel</p>
<div class="card" style="margin-top:18px">
<form method="get" class="flex"><label class="mut">С даты<input type="date" name="from" value="<?=h($from)?>"></label><label class="mut">По дату<input type="date" name="to" value="<?=h($to)?>"></label><button class="btn ghost" style="align-self:end">Применить период</button></form>
<div class="grid q4" style="margin-top:18px">
<a class="card" href="export-download.php?type=orders&from=<?=urlencode($from)?>&to=<?=urlencode($to)?>" style="display:block;color:#f4f4f5"><div style="font-size:28px">📋</div><h3>Заказы</h3><p class="mut">Маршруты, цены, клиент, водитель, статус, отзывы, фактический километраж</p><span class="btn sm" style="margin-top:12px">Скачать CSV</span></a>
<a class="card" href="export-download.php?type=drivers&from=<?=urlencode($from)?>&to=<?=urlencode($to)?>" style="display:block;color:#f4f4f5"><div style="font-size:28px">🚗</div><h3>Водители</h3><p class="mut">Авто, документы, баланс, поездки, рейтинг, реквизиты и верификация</p><span class="btn sm" style="margin-top:12px;background:#38bdf8">Скачать CSV</span></a>
<a class="card" href="export-download.php?type=balance&from=<?=urlencode($from)?>&to=<?=urlencode($to)?>" style="display:block;color:#f4f4f5"><div style="font-size:28px">💳</div><h3>Балансы</h3><p class="mut">Пополнения, комиссии, штрафы, возвраты и бонусы за выбранный период</p><span class="btn sm" style="margin-top:12px;background:#fb923c">Скачать CSV</span></a>
</div></div>
<?php layout_footer();
