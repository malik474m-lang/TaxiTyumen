<?php
// Возврат клиента из платёжной формы Сбера. Публичная страница: результат
// всегда перепроверяется запросом getOrderStatusExtended.do, параметрам URL
// не доверяем.
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$id = (string) ($_GET['payment'] ?? '');
$status = 'failed';
$message = 'Платёж не найден';
$amount = 0.0;

try {
    if ($id === '') throw new RuntimeException('Не указан платёж');
    $p = SberPayments::reconcile($db, $id);
    $status = (string) $p['status'];
    $amount = (float) $p['amount'];
    $message = match ($status) {
        'paid' => 'Оплата успешно подтверждена',
        'pending' => 'Оплата ещё обрабатывается. Статус обновится автоматически.',
        'refunded' => 'Средства возвращены',
        'cancelled' => 'Оплата отменена',
        default => 'Оплата не прошла: ' . ($p['error'] ?? 'проверьте данные и повторите'),
    };
} catch (Throwable $e) {
    $message = $e->getMessage();
}

$ok = $status === 'paid';
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Результат оплаты</title>
<style>
body{margin:0;background:#0a0a0c;color:#f4f4f5;font:16px/1.5 system-ui,sans-serif;display:grid;min-height:100vh;place-items:center;padding:20px}
.card{max-width:420px;background:#141419;border:1px solid #292931;border-radius:20px;padding:28px;text-align:center}
.icon{font-size:52px}.sum{font-size:30px;font-weight:900;color:#facc15;margin:10px 0}.mut{color:#a1a1aa;font-size:13px}
button{margin-top:20px;background:#facc15;color:#0a0a0c;border:0;border-radius:12px;padding:13px 22px;font-weight:800;font-size:16px}
</style></head><body><div class="card">
<div class="icon"><?= $ok ? '✓' : ($status === 'pending' ? '⏳' : '✕') ?></div>
<h2><?= $ok ? 'Оплачено' : 'Результат оплаты' ?></h2>
<?php if ($amount > 0): ?><div class="sum"><?= number_format($amount, 0, ',', ' ') ?> ₽</div><?php endif; ?>
<p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<p class="mut">Можно закрыть эту страницу и вернуться в приложение «Заказ такси».</p>
<button onclick="window.close();history.back()">Вернуться в приложение</button>
</div></body></html>
