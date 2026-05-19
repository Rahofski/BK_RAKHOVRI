<?php
// ============================================================
// admin/users.php  —  List, block/unblock, change role
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['admin']);

$pdo     = getPDO();
$adminId = (int) $_SESSION['user_id'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action     = $_POST['action']  ?? '';
    $targetId   = (int)($_POST['user_id'] ?? 0);

    // ---- Bulk actions ----
    if (in_array($action, ['bulk_block', 'bulk_unblock', 'bulk_delete'], true)) {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_filter($ids, fn($id) => $id !== $adminId);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'bulk_block') {
                $pdo->prepare("UPDATE users SET status='blocked' WHERE id IN ($placeholders)")->execute($ids);
                foreach ($ids as $id) { auditLog($pdo, $adminId, 'block_user', 'user', $id); }
                flash('success', 'Пользователи заблокированы: ' . count($ids));
            } elseif ($action === 'bulk_unblock') {
                $pdo->prepare("UPDATE users SET status='active' WHERE id IN ($placeholders)")->execute($ids);
                foreach ($ids as $id) { auditLog($pdo, $adminId, 'unblock_user', 'user', $id); }
                flash('success', 'Пользователи разблокированы: ' . count($ids));
            } elseif ($action === 'bulk_delete') {
                $pdo->prepare("UPDATE users SET status='deleted' WHERE id IN ($placeholders)")->execute($ids);
                foreach ($ids as $id) { auditLog($pdo, $adminId, 'delete_user', 'user', $id); }
                flash('success', 'Пользователи удалены (помечены): ' . count($ids));
            }
        }
        header('Location: ' . BASE_URL . '/admin/users.php'); exit;
    }

    if ($targetId === $adminId) {
        flash('warning', 'Нельзя изменить собственный аккаунт.');
        header('Location: ' . BASE_URL . '/admin/users.php'); exit;
    }

    if ($action === 'block') {
        $pdo->prepare("UPDATE users SET status='blocked' WHERE id=?")->execute([$targetId]);
        auditLog($pdo, $adminId, 'block_user', 'user', $targetId);
        flash('success', 'Пользователь заблокирован.');

    } elseif ($action === 'unblock') {
        $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$targetId]);
        auditLog($pdo, $adminId, 'unblock_user', 'user', $targetId);
        flash('success', 'Пользователь разблокирован.');

    } elseif ($action === 'change_role') {
        $roleCode = $_POST['role_code'] ?? '';
        $s = $pdo->prepare('SELECT id FROM roles WHERE code=? LIMIT 1');
        $s->execute([$roleCode]);
        $roleId = $s->fetchColumn();
        if ($roleId) {
            $pdo->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([$roleId, $targetId]);
            auditLog($pdo, $adminId, 'change_role', 'user', $targetId, ['new_role' => $roleCode]);
            flash('success', 'Роль изменена.');
        } else {
            flash('error', 'Неизвестная роль.');
        }
    }

    header('Location: ' . BASE_URL . '/admin/users.php'); exit;
}

function auditLog(PDO $pdo, int $userId, string $action, string $entityType, int $entityId, array $details = []): void {
    $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $action, $entityType, $entityId, $details ? json_encode($details) : null]);
}

// Filters
$filterRole   = $_GET['role']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterQ      = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($filterRole !== '') {
    $where[] = 'r.code = ?';
    $params[] = $filterRole;
}
if ($filterStatus !== '') {
    $where[] = 'u.status = ?';
    $params[] = $filterStatus;
}
if ($filterQ !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
    $like = '%' . $filterQ . '%';
    $params = array_merge($params, [$like, $like, $like]);
}

$whereClause = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT u.id, u.username, u.email, u.full_name, u.status, u.created_at,
            r.code AS role_code, r.name AS role_name,
            (SELECT COALESCE(balance,0) FROM wallets WHERE user_id=u.id LIMIT 1) AS balance
       FROM users u
       JOIN roles r ON r.id = u.role_id
      WHERE $whereClause
      ORDER BY u.created_at DESC
      LIMIT 200"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$roles = $pdo->query('SELECT code, name FROM roles ORDER BY name')->fetchAll();

$statusBadge = ['active' => 'badge-success', 'blocked' => 'badge-danger', 'deleted' => 'badge-muted'];
$statusLabel = ['active' => 'Активен',       'blocked' => 'Заблокирован', 'deleted' => 'Удалён'];

$pageTitle = 'Пользователи';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-3">
  <h1 class="page-title mb-0">Пользователи</h1>
</div>

<!-- Filters -->
<form method="get" class="filters-bar mb-3">
  <div class="form-group">
    <label class="form-label">Роль</label>
    <select class="form-control" name="role">
      <option value="" <?= $filterRole==='' ? 'selected':'' ?>>Все роли</option>
      <?php foreach ($roles as $r): ?>
        <option value="<?= $r['code'] ?>" <?= $filterRole===$r['code'] ? 'selected':'' ?>>
          <?= htmlspecialchars($r['name'], ENT_QUOTES) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label">Статус</label>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem .7rem;margin-top:.25rem;">
      <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;">
        <input type="radio" name="status" value=""
               <?= $filterStatus==='' ? 'checked' : '' ?>> Все
      </label>
      <?php foreach ($statusLabel as $v => $l): ?>
        <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;">
          <input type="radio" name="status" value="<?= $v ?>"
                 <?= $filterStatus===$v ? 'checked' : '' ?>> <?= $l ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="form-group">
    <label class="form-label">Поиск</label>
    <input class="form-control" type="text" name="q"
           value="<?= htmlspecialchars($filterQ, ENT_QUOTES) ?>" placeholder="Логин / Email…">
  </div>
  <button type="submit" class="btn btn-primary btn-sm filters-bar-action">Найти</button>
  <a href="?" class="btn btn-outline btn-sm filters-bar-action">Сбросить</a>
  <a href="<?= BASE_URL ?>/api/export.php?type=users&format=csv"
     class="btn btn-outline btn-sm filters-bar-action">⬇ CSV</a>
  <a href="<?= BASE_URL ?>/api/export.php?type=users&format=txt"
     class="btn btn-outline btn-sm filters-bar-action">⬇ TXT</a>
</form>

<!-- Bulk-action form wraps the table -->
<form method="post" id="bulkForm">
  <?= csrf_field() ?>

  <div class="flex-gap mb-2" style="gap:.5rem;">
    <button type="button" class="btn btn-outline btn-sm"
            onclick="toggleAllUsers(this)">Выбрать все</button>
    <button type="submit" name="action" value="bulk_block"
            class="btn btn-outline btn-sm"
            onclick="return confirmBulk('заблокировать')">Заблокировать</button>
    <button type="submit" name="action" value="bulk_unblock"
            class="btn btn-outline btn-sm"
            onclick="return confirmBulk('разблокировать')">Разблокировать</button>
    <button type="submit" name="action" value="bulk_delete"
            class="btn btn-danger btn-sm"
            onclick="return confirmBulk('удалить')">Удалить выбранных</button>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:2rem;"></th>
          <th>#</th>
          <th>Логин</th>
          <th>Email</th>
          <th>Имя</th>
          <th>Роль</th>
          <th>Статус</th>
          <th class="text-right">Баланс</th>
          <th>Регистрация</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
          $sc = $statusBadge[$u['status']] ?? 'badge-muted';
          $sl = $statusLabel[$u['status']] ?? $u['status'];
          $isSelf = $u['id'] === $adminId;
        ?>
          <tr>
            <td>
              <?php if (!$isSelf): ?>
                <input type="checkbox" name="ids[]" value="<?= $u['id'] ?>"
                       class="bulk-cb"
                       style="width:15px;height:15px;cursor:pointer;accent-color:var(--accent);">
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= $u['id'] ?></td>
            <td><?= htmlspecialchars($u['username'], ENT_QUOTES) ?></td>
            <td class="text-muted text-xs"><?= htmlspecialchars($u['email'], ENT_QUOTES) ?></td>
            <td class="text-muted"><?= htmlspecialchars($u['full_name'] ?? '—', ENT_QUOTES) ?></td>
            <td>
              <?php if (!$isSelf): ?>
                <!-- Inline role change -->
                <form method="post" class="form-inline-flex">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="change_role">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <select class="form-control select-sm" name="role_code"
                          onchange="this.form.submit()">
                    <?php foreach ($roles as $r): ?>
                      <option value="<?= $r['code'] ?>"
                        <?= $r['code'] === $u['role_code'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name'], ENT_QUOTES) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php else: ?>
                <span class="badge badge-role"><?= htmlspecialchars($u['role_name'], ENT_QUOTES) ?></span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $sc ?>"><?= $sl ?></span></td>
            <td class="text-right text-accent"><?= number_format($u['balance'], 2) ?></td>
            <td class="text-muted td-nowrap-sm">
              <?= htmlspecialchars(date('d.m.Y', strtotime($u['created_at'])), ENT_QUOTES) ?>
            </td>
            <td class="td-actions">
              <?php if (!$isSelf && $u['status'] !== 'deleted'): ?>
                <form method="post" class="form-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <?php if ($u['status'] === 'active'): ?>
                    <input type="hidden" name="action" value="block">
                    <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('Заблокировать пользователя?')">
                      Блок
                    </button>
                  <?php elseif ($u['status'] === 'blocked'): ?>
                    <input type="hidden" name="action" value="unblock">
                    <button type="submit" class="btn btn-outline btn-sm">Разблок</button>
                  <?php endif; ?>
                </form>
              <?php elseif ($isSelf): ?>
                <span class="text-muted text-xs">(вы)</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</form>

<script>
function toggleAllUsers(btn) {
  const cbs = document.querySelectorAll('.bulk-cb');
  const allChecked = Array.from(cbs).every(c => c.checked);
  cbs.forEach(c => c.checked = !allChecked);
  btn.textContent = allChecked ? 'Выбрать все' : 'Снять все';
}
function confirmBulk(action) {
  const n = document.querySelectorAll('.bulk-cb:checked').length;
  if (!n) { alert('Не выбрано ни одного пользователя.'); return false; }
  return confirm('Действие «' + action + '» для ' + n + ' пользователей. Продолжить?');
}
</script>
