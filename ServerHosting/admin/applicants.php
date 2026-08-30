<?php
// Соискатели: анкеты водителей со своим авто — просмотр фото, статусы,
// одобрение с автоматическим созданием учётной записи водителя.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'applicants');
Applicants::ensureTables($db);
$error = '';
$created = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = (string) ($_POST['id'] ?? '');
        $act = (string) ($_POST['act'] ?? '');
        $stmt = $db->prepare('SELECT * FROM driver_applications WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $app = $stmt->fetch();
        if (!$app) throw new RuntimeException('Анкета не найдена');

        if ($act === 'approve') {
            $res = Applicants::approve($db, $app, (string) $admin['id']);
            header('Location: applicants.php?created=' . urlencode($res['phone'] . '|' . $res['password']));
            exit;
        }
        if (in_array($act, ['new', 'in_review', 'contacted', 'rejected'], true)) {
            $db->prepare('UPDATE driver_applications SET status=?, review_note=?, reviewed_by=?, reviewed_at=? WHERE id=?')
                ->execute([$act, mb_substr((string) ($_POST['note'] ?? ''), 0, 500) ?: null,
                    $admin['id'], Db::utcNow(), $id]);
            Bus::publish('applications');
            header('Location: applicants.php?ok=' . urlencode('Статус обновлён: ' . Applicants::statusLabel($act)));
            exit;
        }
        throw new RuntimeException('Неизвестное действие');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (!empty($_GET['created'])) {
    [$cPhone, $cPass] = array_pad(explode('|', (string) $_GET['created'], 2), 2, '');
    $created = ['phone' => $cPhone, 'password' => $cPass];
}

$filter = (string) ($_GET['status'] ?? '');
if ($filter !== '') {
    $stmt = $db->prepare('SELECT * FROM driver_applications WHERE status=? ORDER BY created_at DESC LIMIT 300');
    $stmt->execute([$filter]);
    $rows = $stmt->fetchAll();
} else {
    $rows = $db->query('SELECT * FROM driver_applications ORDER BY created_at DESC LIMIT 300')->fetchAll();
}
$counts = [];
foreach ($db->query("SELECT status, COUNT(*) c FROM driver_applications GROUP BY status")->fetchAll() as $r) {
    $counts[$r['status']] = (int) $r['c'];
}

layout_header('Соискатели', 'applicants');
?>
<div class="flex between">
  <div><h1>Соискатели · водители со своим авто</h1>
    <p class="mut">Анкеты с сайта: документы, фото авто с четырёх сторон, детское кресло</p></div>
  <span class="chip <?= !empty($counts['new']) ? 'warn' : 'ok' ?>">Новых: <?= (int) ($counts['new'] ?? 0) ?></span>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?= h($error) ?></div><?php endif; ?>
<?php if ($created): ?>
  <div class="card" style="margin-top:14px;border-color:rgba(74,222,128,.45);background:rgba(74,222,128,.08)">
    <b style="color:#6ee7b7">Учётная запись водителя создана</b>
    <div style="margin-top:8px;font-size:15px">Телефон: <b><?= h($created['phone']) ?></b> · Пароль: <b><?= h($created['password']) ?></b></div>
    <div class="mut" style="margin-top:6px">Передайте данные водителю — пароль показывается один раз. Сменить его можно в разделе «Водители».</div>
  </div>
<?php endif; ?>

<div class="flex" style="gap:8px;margin-top:16px;flex-wrap:wrap">
  <?php foreach ([''=>'Все','new'=>'Новые','in_review'=>'На рассмотрении','contacted'=>'Связались','approved'=>'Одобрены','rejected'=>'Отклонены'] as $key=>$label): ?>
    <a class="btn <?= $filter === $key ? '' : 'ghost' ?>" style="padding:7px 13px;font-size:12px"
       href="applicants.php<?= $key ? '?status=' . $key : '' ?>"><?= h($label) ?><?= isset($counts[$key]) ? ' · ' . $counts[$key] : '' ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <div class="card mut" style="margin-top:14px;text-align:center;padding:40px">Анкет пока нет</div>
<?php endif; ?>

<?php foreach ($rows as $a): $d = Applicants::dto($a); ?>
  <div class="card" style="margin-top:14px">
    <div class="flex between" style="gap:12px;flex-wrap:wrap">
      <div>
        <div style="font-size:17px;font-weight:900"><?= h($d['name']) ?>
          <span class="chip <?= $d['status']==='approved'?'ok':($d['status']==='rejected'?'bad':($d['status']==='new'?'warn':'info')) ?>"><?= h($d['statusText']) ?></span>
        </div>
        <div class="mut" style="margin-top:4px">
          <a href="tel:<?= h($d['phone']) ?>"><?= h($d['phone']) ?></a>
          <?= $d['city'] ? ' · ' . h((string) $d['city']) : '' ?>
          · стаж <?= (int) $d['experienceYears'] ?> лет
          · <?= h(fmt_date((string) $a['created_at'])) ?>
        </div>
        <div style="margin-top:8px">
          <b><?= h($d['carBrand'] . ' ' . $d['carModel']) ?></b>
          <?= $d['carColor'] ? ', ' . h((string) $d['carColor']) : '' ?>, <?= (int) $d['carYear'] ?> г.
          · <span class="chip info"><?= h($d['licensePlate']) ?></span>
          <span class="chip <?= $d['hasChildSeat'] ? 'ok' : '' ?>">
            <?= $d['hasChildSeat'] ? '✓ детское кресло' : 'без детского кресла' ?>
          </span>
        </div>
        <?php if ($d['licenseNumber']): ?>
          <div class="mut" style="margin-top:6px">ВУ: <?= h((string) $d['licenseNumber']) ?><?= $d['licenseExpiry'] ? ' до ' . h((string) $d['licenseExpiry']) : '' ?></div>
        <?php endif; ?>
        <?php if ($d['comment']): ?><div style="margin-top:8px">«<?= h((string) $d['comment']) ?>»</div><?php endif; ?>
        <?php if ($d['createdUserId']): ?><div class="mut" style="margin-top:6px">Учётная запись создана ✓</div><?php endif; ?>
      </div>

      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;max-width:520px">
        <input type="hidden" name="id" value="<?= h($d['id']) ?>">
        <input name="note" placeholder="Комментарий" style="width:150px" value="<?= h((string) $d['reviewNote']) ?>">
        <button class="btn ghost" name="act" value="in_review" style="padding:7px 11px;font-size:12px">На рассмотрение</button>
        <button class="btn ghost" name="act" value="contacted" style="padding:7px 11px;font-size:12px">Связались</button>
        <button class="btn danger" name="act" value="rejected" style="padding:7px 11px;font-size:12px"
                onclick="return confirm('Отклонить анкету?')">Отклонить</button>
        <?php if (!$d['createdUserId']): ?>
          <button class="btn" name="act" value="approve" style="padding:7px 13px;font-size:12px"
                  onclick="return confirm('Одобрить и создать учётную запись водителя?')">Одобрить и создать аккаунт</button>
        <?php endif; ?>
      </form>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
      <?php foreach (Applicants::PHOTOS as $field => $label): if (empty($a[$field])) continue; ?>
        <a href="../api/applications.php?photo=<?= h($d['id']) ?>&field=<?= h($field) ?>" target="_blank" rel="noopener"
           style="display:block;text-align:center;text-decoration:none">
          <img src="../api/applications.php?photo=<?= h($d['id']) ?>&field=<?= h($field) ?>" alt="<?= h($label) ?>"
               style="width:130px;height:96px;object-fit:cover;border-radius:10px;border:1px solid var(--line);background:#0f0f13">
          <div class="mut" style="font-size:11px;margin-top:4px"><?= h($label) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php layout_footer();
