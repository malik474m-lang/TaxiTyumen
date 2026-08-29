<?php
// Доступ и роли — только супер-администратор.
// Управление видимостью разделов для обычных админов + учётные записи админов.
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$admin = admin_require($db, 'access');
if (!Access::isSuperadmin($admin)) {
    http_response_code(403);
    layout_header('Нет доступа', 'index');
    echo '<div class="card" style="margin-top:20px"><h1>Только для супер-администратора</h1></div>';
    layout_footer();
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cmd = (string) ($_POST['cmd'] ?? 'sections');

        if ($cmd === 'sections') {
            Access::setVisibility($db, array_keys((array) ($_POST['sections'] ?? [])));
            Bus::publish('access');
            header('Location: access.php?ok=' . urlencode('Видимость разделов обновлена'));
            exit;
        }

        if ($cmd === 'add_admin') {
            $login = trim((string) ($_POST['username'] ?? ''));
            $phone = Auth::normalizePhone((string) ($_POST['phone'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $firstName = trim((string) ($_POST['first_name'] ?? '')) ?: 'Администратор';
            if (strlen($password) < 8) throw new RuntimeException('Пароль администратора — минимум 8 символов');
            if (strlen(preg_replace('/\D/', '', $phone)) < 11) throw new RuntimeException('Укажите корректный телефон');

            $exists = $db->prepare('SELECT id FROM users WHERE phone=? OR (username IS NOT NULL AND username=?) LIMIT 1');
            $exists->execute([$phone, $login !== '' ? $login : null]);
            if ($exists->fetch()) throw new RuntimeException('Пользователь с таким телефоном или логином уже есть');

            $db->prepare(
                "INSERT INTO users (id, phone, username, first_name, last_name, password_hash, role, is_phone_verified)
                 VALUES (?,?,?,?,?,?, 'admin', 1)"
            )->execute([
                Db::uuid(), $phone, $login !== '' ? $login : null, $firstName,
                trim((string) ($_POST['last_name'] ?? '')), Auth::hashPassword($password),
            ]);
            header('Location: access.php?ok=' . urlencode('Администратор добавлен'));
            exit;
        }

        $targetId = (string) ($_POST['id'] ?? '');
        $target = $db->prepare("SELECT * FROM users WHERE id=? AND role IN ('admin','superadmin') LIMIT 1");
        $target->execute([$targetId]);
        $row = $target->fetch();
        if (!$row) throw new RuntimeException('Учётная запись не найдена');
        if (($row['role'] ?? '') === 'superadmin') {
            throw new RuntimeException('Супер-администратор защищён: изменение и удаление запрещены');
        }

        if ($cmd === 'block') {
            $blocked = $row['is_blocked'] ? 0 : 1;
            $db->prepare('UPDATE users SET is_blocked=?, block_reason=? WHERE id=?')
                ->execute([$blocked, $blocked ? 'Заблокирован супер-администратором' : null, $targetId]);
            header('Location: access.php?ok=' . urlencode($blocked ? 'Администратор заблокирован' : 'Администратор разблокирован'));
            exit;
        }
        if ($cmd === 'password') {
            $password = (string) ($_POST['new_password'] ?? '');
            if (strlen($password) < 8) throw new RuntimeException('Пароль — минимум 8 символов');
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([Auth::hashPassword($password), $targetId]);
            header('Location: access.php?ok=' . urlencode('Пароль администратора изменён'));
            exit;
        }
        if ($cmd === 'delete') {
            $db->prepare("DELETE FROM users WHERE id=? AND role='admin'")->execute([$targetId]);
            header('Location: access.php?ok=' . urlencode('Администратор удалён'));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

Access::ensureTables($db);
$rows = $db->query('SELECT section_key, visible_for_admin FROM admin_sections')->fetchAll();
$visibility = [];
foreach ($rows as $row) {
    $visibility[$row['section_key']] = (bool) $row['visible_for_admin'];
}
$admins = $db->query(
    "SELECT id, username, phone, first_name, last_name, role, is_blocked, last_login_at
     FROM users WHERE role IN ('admin','superadmin') ORDER BY role DESC, first_name"
)->fetchAll();
$integrity = Access::integrity($db);
$triggerCount = 0;
try {
    $triggerCount = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'trg_users_superadmin%'"
    )->fetchColumn();
} catch (Throwable) {
}

layout_header('Доступ и роли', 'access');
?>
<div class="flex between">
  <div><h1>Доступ и роли</h1><p class="mut">Видимость разделов для обычных администраторов и защита супер-админа</p></div>
  <span class="chip <?= $integrity['ok'] ? 'ok' : 'bad' ?>">
    <?= $integrity['ok'] ? 'Целостность ролей в норме' : 'Нарушена целостность' ?>
  </span>
</div>

<?php if (!empty($_GET['ok'])): ?><div class="flash" style="margin-top:14px">✓ <?= h((string) $_GET['ok']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash" style="margin-top:14px;border-color:rgba(248,113,113,.4);background:rgba(248,113,113,.08);color:#fca5a5">Ошибка: <?= h($error) ?></div><?php endif; ?>

<div class="grid q2" style="margin-top:18px">
  <form method="post" class="card">
    <input type="hidden" name="cmd" value="sections">
    <h3 style="margin-bottom:6px">Разделы для роли «Администратор»</h3>
    <p class="mut" style="margin-bottom:12px">Снимите галочку — раздел исчезнет из меню и станет недоступен по прямой ссылке.</p>
    <?php foreach (Access::SECTIONS as $key => $meta): ?>
      <label class="flex between" style="padding:10px 12px;background:#0f0f13;border-radius:11px;margin-bottom:7px">
        <span>
          <b><?= h($meta['label']) ?></b>
          <?php if ($meta['superadminOnly']): ?><span class="chip violet" style="margin-left:8px">только супер-админ</span><?php endif; ?>
          <?php if ($meta['locked']): ?><span class="chip info" style="margin-left:8px">всегда виден</span><?php endif; ?>
        </span>
        <input type="checkbox" name="sections[<?= h($key) ?>]" value="1"
          <?= $meta['superadminOnly'] ? 'disabled' : '' ?>
          <?= $meta['locked'] ? 'checked disabled' : (($visibility[$key] ?? true) ? 'checked' : '') ?>
          style="width:18px;height:18px;accent-color:#facc15">
      </label>
    <?php endforeach; ?>
    <button class="btn" type="submit" style="width:100%;margin-top:10px">Сохранить видимость разделов</button>
  </form>

  <div>
    <div class="card">
      <h3 style="margin-bottom:10px">Защита супер-администратора</h3>
      <div style="display:grid;gap:8px;font-size:13px">
        <div style="padding:11px;background:#0f0f13;border-radius:11px">
          Роль <b>superadmin</b> нельзя удалить, деактивировать или понизить —
          на уровне приложения и триггерами MySQL.
          <div class="mut" style="margin-top:6px">Триггеров активно: <b><?= $triggerCount ?></b> из 2</div>
        </div>
        <div style="padding:11px;background:#0f0f13;border-radius:11px">
          Если строку удалить напрямую в БД, панель полностью блокируется
          до восстановления через <code>SUPERADMIN_RECOVERY</code> в <code>config.local.php</code>.
        </div>
        <div style="padding:11px;background:#0f0f13;border-radius:11px">
          Раздел «Бренд сервиса» и эта страница доступны только супер-администратору.
        </div>
      </div>
    </div>

    <form method="post" class="card" style="margin-top:14px">
      <input type="hidden" name="cmd" value="add_admin">
      <h3 style="margin-bottom:12px">Добавить администратора</h3>
      <div class="grid" style="grid-template-columns:1fr 1fr">
        <label class="mut">Логин (необязательно)<input name="username" placeholder="manager"></label>
        <label class="mut">Телефон<input name="phone" placeholder="+7900..." required></label>
        <label class="mut">Имя<input name="first_name" placeholder="Иван"></label>
        <label class="mut">Фамилия<input name="last_name"></label>
      </div>
      <label class="mut" style="display:block;margin-top:10px">Пароль (мин. 8 символов)<input name="password" minlength="8" required></label>
      <button class="btn" type="submit" style="width:100%;margin-top:12px">Создать администратора</button>
    </form>
  </div>
</div>

<div class="card" style="margin-top:14px;overflow-x:auto">
  <h3 style="margin-bottom:10px">Учётные записи панели</h3>
  <table>
    <thead><tr><th>Пользователь</th><th>Логин / телефон</th><th>Роль</th><th>Статус</th><th>Последний вход</th><th>Действия</th></tr></thead>
    <tbody>
    <?php foreach ($admins as $a): $isSuperRow = $a['role'] === 'superadmin'; ?>
      <tr>
        <td><b><?= h(trim($a['first_name'] . ' ' . $a['last_name'])) ?></b></td>
        <td><?= h((string) ($a['username'] ?? '—')) ?><div class="mut"><?= h($a['phone']) ?></div></td>
        <td><span class="chip <?= $isSuperRow ? 'violet' : 'info' ?>"><?= $isSuperRow ? 'Супер-админ' : 'Администратор' ?></span></td>
        <td><?= $a['is_blocked'] ? '<span class="chip bad">Заблокирован</span>' : '<span class="chip ok">Активен</span>' ?></td>
        <td class="mut"><?= h(fmt_date($a['last_login_at'])) ?></td>
        <td>
          <?php if ($isSuperRow): ?>
            <span class="mut">🔒 защищено</span>
          <?php else: ?>
            <div class="flex">
              <form method="post"><input type="hidden" name="cmd" value="block"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="btn <?= $a['is_blocked'] ? 'ghost' : 'danger' ?> sm"><?= $a['is_blocked'] ? 'Разблокировать' : 'Заблокировать' ?></button></form>
              <form method="post" class="inline"><input type="hidden" name="cmd" value="password"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><input name="new_password" placeholder="Новый пароль" minlength="8" required style="width:140px"><button class="btn ghost sm">Пароль</button></form>
              <form method="post" onsubmit="return confirm('Удалить администратора?')"><input type="hidden" name="cmd" value="delete"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="btn danger sm">Удалить</button></form>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php layout_footer();
