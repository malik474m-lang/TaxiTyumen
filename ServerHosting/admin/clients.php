<?php
// Клиенты — порт TaxiAdmin/Clients.razor: поиск, блокировки, статистика и история.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (string) ($_POST['id'] ?? '');
    $cmd = (string) ($_POST['cmd'] ?? '');
    $stmt = $db->prepare("SELECT * FROM users WHERE id=? AND role='client' LIMIT 1");
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    if ($client) {
        if ($cmd === 'block') {
            $blocked = $client['is_blocked'] ? 0 : 1;
            $db->prepare('UPDATE users SET is_blocked=?, block_reason=? WHERE id=?')
                ->execute([$blocked, $blocked ? trim((string) ($_POST['reason'] ?? 'Заблокирован администратором')) : null, $id]);
        } elseif ($cmd === 'active') {
            $db->prepare('UPDATE users SET is_active=? WHERE id=?')
                ->execute([$client['is_active'] ? 0 : 1, $id]);
        }
    }
    header('Location: clients.php?ok=' . urlencode('Статус клиента обновлён'));
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $stmt = $db->prepare(
        "SELECT u.*,
          (SELECT COUNT(*) FROM orders o WHERE o.client_id=u.id) AS trip_count,
          (SELECT COALESCE(SUM(o.final_price),0) FROM orders o WHERE o.client_id=u.id AND o.status='completed') AS spent
         FROM users u WHERE u.role='client'
           AND (u.phone LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
         ORDER BY u.created_at DESC LIMIT 200"
    );
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like, $like]);
} else {
    $stmt = $db->query(
        "SELECT u.*,
          (SELECT COUNT(*) FROM orders o WHERE o.client_id=u.id) AS trip_count,
          (SELECT COALESCE(SUM(o.final_price),0) FROM orders o WHERE o.client_id=u.id AND o.status='completed') AS spent
         FROM users u WHERE u.role='client' ORDER BY u.created_at DESC LIMIT 200"
    );
}
$clients = $stmt->fetchAll();

layout_header('Клиенты', 'clients');
?>
<div class="flex between">
  <div><h1>Клиенты</h1><p class="mut"><?= count($clients) ?> записей<?= $q ? ' по запросу' : '' ?></p></div>
  <form method="get" class="inline">
    <input name="q" value="<?= h($q) ?>" placeholder="Телефон, имя, email" style="width:240px">
    <button class="btn ghost">Найти</button>
    <?php if ($q): ?><a class="btn ghost" href="clients.php">Сбросить</a><?php endif; ?>
  </form>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>

<div class="card" style="margin-top:18px;overflow-x:auto">
<table>
  <thead><tr><th>Клиент</th><th>Контакты</th><th>Статус</th><th>Рейтинг</th><th>Поездки</th><th>Расходы</th><th>Регистрация</th><th>Действия</th></tr></thead>
  <tbody>
  <?php foreach ($clients as $c): ?>
    <tr>
      <td><b><?= h($c['first_name'] . ' ' . $c['last_name']) ?></b></td>
      <td><div><?= h($c['phone']) ?></div><div class="mut"><?= h((string) ($c['email'] ?? '')) ?></div></td>
      <td>
        <?= $c['is_blocked'] ? '<span class="chip bad">Заблокирован</span>' : ($c['is_active'] ? '<span class="chip ok">Активен</span>' : '<span class="chip warn">Неактивен</span>') ?>
        <?= $c['is_phone_verified'] ? '<span class="chip info">Телефон ✓</span>' : '' ?>
      </td>
      <td>★ <?= number_format((float) $c['rating'], 1) ?></td>
      <td><b><?= (int) $c['trip_count'] ?></b></td>
      <td><b style="color:#fde047"><?= money((float) $c['spent']) ?></b></td>
      <td class="mut"><?= h(fmt_date($c['created_at'])) ?></td>
      <td>
        <div class="flex">
          <a class="btn ghost sm" href="messages.php?recipient=<?= h($c['id']) ?>">Написать</a>
          <form method="post" class="inline">
            <input type="hidden" name="cmd" value="block"><input type="hidden" name="id" value="<?= h($c['id']) ?>">
            <?php if (!$c['is_blocked']): ?><input name="reason" placeholder="Причина" value="Нарушение правил" style="width:130px"><?php endif; ?>
            <button class="btn <?= $c['is_blocked'] ? 'ghost' : 'danger' ?> sm"><?= $c['is_blocked'] ? 'Разблокировать' : 'Блокировать' ?></button>
          </form>
          <form method="post"><input type="hidden" name="cmd" value="active"><input type="hidden" name="id" value="<?= h($c['id']) ?>"><button class="btn ghost sm"><?= $c['is_active'] ? 'Деактивировать' : 'Активировать' ?></button></form>
        </div>
      </td>
    </tr>
    <?php
      $hist = $db->prepare('SELECT * FROM orders WHERE client_id=? ORDER BY created_at DESC LIMIT 3');
      $hist->execute([$c['id']]);
      $lastOrders = $hist->fetchAll();
      if ($lastOrders):
    ?>
    <tr>
      <td colspan="8" style="padding-top:0">
        <details>
          <summary class="mut" style="cursor:pointer">Последние поездки (<?= count($lastOrders) ?>)</summary>
          <div class="flex" style="margin-top:8px">
            <?php foreach ($lastOrders as $o): ?>
              <span class="chip <?= $o['status'] === 'completed' ? 'ok' : ($o['status'] === 'cancelled' ? 'bad' : 'warn') ?>">
                <?= h(fmt_date($o['created_at'])) ?> · <?= h(Taxi::STATUS_TEXT[$o['status']] ?? $o['status']) ?> · <?= h(money((float) ($o['final_price'] ?? $o['estimated_price']))) ?>
              </span>
            <?php endforeach; ?>
          </div>
        </details>
      </td>
    </tr>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$clients): ?><tr><td colspan="8" class="mut" style="text-align:center;padding:40px">Клиентов не найдено</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php layout_footer();
