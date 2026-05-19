<?php
// ============================================================
// admin/index.php  —  Admin dashboard
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['admin']);

$pdo = getPDO();

$stats = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM users WHERE status='active')   AS active_users,
       (SELECT COUNT(*) FROM users WHERE status='blocked')  AS blocked_users,
       (SELECT COUNT(*) FROM events WHERE status IN ('scheduled','live')) AS active_events,
       (SELECT COUNT(*) FROM bets WHERE status='pending')   AS pending_bets,
       (SELECT COUNT(*) FROM sports)                         AS sports_cnt,
       (SELECT COUNT(*) FROM teams)                          AS teams_cnt,
       (SELECT COUNT(*) FROM markets)                        AS markets_cnt,
       (SELECT COUNT(*) FROM audit_logs)                     AS audit_cnt
    "
)->fetch();

// Recent audit logs
$audit = $pdo->query(
    "SELECT al.action, al.entity_type, al.entity_id, al.details, al.created_at,
            u.username
       FROM audit_logs al
       JOIN users u ON u.id = al.user_id
      ORDER BY al.created_at DESC
      LIMIT 15"
)->fetchAll();

$pageTitle = 'Дашборд';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Дашборд администратора</h1>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-value"><?= $stats['active_users'] ?></div>
    <div class="stat-label">Активных пользователей</div>
  </div>
  <div class="stat-card stat-card--danger">
    <div class="stat-value stat-value--danger"><?= $stats['blocked_users'] ?></div>
    <div class="stat-label">Заблокировано</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['active_events'] ?></div>
    <div class="stat-label">Активных событий</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['pending_bets'] ?></div>
    <div class="stat-label">Ставок в ожидании</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['sports_cnt'] ?></div>
    <div class="stat-label">Видов спорта</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['teams_cnt'] ?></div>
    <div class="stat-label">Команд</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['markets_cnt'] ?></div>
    <div class="stat-label">Рынков</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['audit_cnt'] ?></div>
    <div class="stat-label">Записей аудита</div>
  </div>
</div>

<!-- Quick links -->
<div class="flex-gap mb-3">
  <a href="<?= BASE_URL ?>/admin/users.php"   class="btn btn-outline">Пользователи</a>
  <a href="<?= BASE_URL ?>/admin/sports.php"  class="btn btn-outline">Виды спорта</a>
  <a href="<?= BASE_URL ?>/admin/teams.php"   class="btn btn-outline">Команды</a>
  <a href="<?= BASE_URL ?>/admin/markets.php" class="btn btn-outline">Рынки</a>
  <a href="<?= BASE_URL ?>/analyst/index.php" class="btn btn-outline">Аналитика</a>
</div>

<!-- Audit log -->
<div class="card">
  <div class="card-header">Последние действия (аудит)</div>
  <?php if (empty($audit)): ?>
    <p class="text-muted text-sm">Пусто.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Дата</th><th>Пользователь</th><th>Действие</th><th>Сущность</th><th>Детали</th></tr>
        </thead>
        <tbody>
          <?php foreach ($audit as $a): ?>
            <tr>
              <td class="text-muted td-nowrap">
                <?= htmlspecialchars(date('d.m.Y H:i', strtotime($a['created_at'])), ENT_QUOTES) ?>
              </td>
              <td><?= htmlspecialchars($a['username'], ENT_QUOTES) ?></td>
              <td><code><?= htmlspecialchars($a['action'], ENT_QUOTES) ?></code></td>
              <td class="text-muted">
                <?= htmlspecialchars(($a['entity_type'] ?? '') . ($a['entity_id'] ? ' #'.$a['entity_id'] : ''), ENT_QUOTES) ?>
              </td>
              <td class="td-truncate">
                <?= htmlspecialchars($a['details'] ?? '', ENT_QUOTES) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
