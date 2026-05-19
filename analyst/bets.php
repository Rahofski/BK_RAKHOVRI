<?php
// ============================================================
// analyst/bets.php  —  All bets with filters + pagination
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['analyst', 'admin']);

$pdo = getPDO();

// --- Filters ---
$filterStatus   = $_GET['status']    ?? '';
$filterUser     = trim($_GET['user'] ?? '');
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to']   ?? '';

$allowed = ['pending','won','lost','cancelled','refunded'];
if (!in_array($filterStatus, $allowed, true)) $filterStatus = '';

// Build WHERE clause safely
$where  = ['1=1'];
$params = [];

if ($filterStatus !== '') {
    $where[] = 'b.status = ?';
    $params[] = $filterStatus;
}
if ($filterUser !== '') {
    $where[] = 'u.username LIKE ?';
    $params[] = '%' . $filterUser . '%';
}
if ($filterDateFrom !== '' && strtotime($filterDateFrom)) {
    $where[] = 'b.created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '' && strtotime($filterDateTo)) {
    $where[] = 'b.created_at <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
}

$whereClause = implode(' AND ', $where);

// Total count
$cntStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM bets b JOIN users u ON u.id = b.user_id WHERE $whereClause"
);
$cntStmt->execute($params);
$total = (int) $cntStmt->fetchColumn();

$perPage  = 30;
$page     = max(1, (int)($_GET['page'] ?? 1));
$lastPage = max(1, (int) ceil($total / $perPage));
$page     = min($page, $lastPage);
$offset   = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT b.id, b.total_amount, b.potential_win, b.status,
            b.created_at, b.settled_at,
            u.username, u.id AS user_id,
            COUNT(bi.id) AS items_cnt
       FROM bets b
       JOIN users     u  ON u.id  = b.user_id
       JOIN bet_items bi ON bi.bet_id = b.id
      WHERE $whereClause
      GROUP BY b.id, b.total_amount, b.potential_win, b.status,
               b.created_at, b.settled_at, u.username, u.id
      ORDER BY b.created_at DESC
      LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$bets = $stmt->fetchAll();

$statusBadge = [
    'pending'   => 'badge-info',
    'won'       => 'badge-success',
    'lost'      => 'badge-danger',
    'cancelled' => 'badge-muted',
    'refunded'  => 'badge-warning',
];
$statusLabel = [
    'pending'   => 'Ожидание',
    'won'       => 'Выигрыш',
    'lost'      => 'Проигрыш',
    'cancelled' => 'Отменена',
    'refunded'  => 'Возврат',
];

// Build query string for pagination links
$qParams = http_build_query([
    'status'    => $filterStatus,
    'user'      => $filterUser,
    'date_from' => $filterDateFrom,
    'date_to'   => $filterDateTo,
]);

$pageTitle = 'Все ставки';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Все ставки</h1>

<!-- Filters -->
<form method="get" class="filters-bar mb-3">
  <div class="form-group">
    <label class="form-label">Статус</label>
    <div style="display:flex;flex-wrap:wrap;gap:.6rem .75rem;margin-top:.25rem;">
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
    <label class="form-label">Пользователь</label>
    <input class="form-control" type="text" name="user"
           value="<?= htmlspecialchars($filterUser, ENT_QUOTES) ?>" placeholder="Логин…">
  </div>
  <div class="form-group">
    <label class="form-label">С даты</label>
    <input class="form-control" type="date" name="date_from"
           value="<?= htmlspecialchars($filterDateFrom, ENT_QUOTES) ?>">
  </div>
  <div class="form-group">
    <label class="form-label">По дату</label>
    <input class="form-control" type="date" name="date_to"
           value="<?= htmlspecialchars($filterDateTo, ENT_QUOTES) ?>">
  </div>
  <button type="submit" class="btn btn-primary btn-sm filters-bar-action">Фильтр</button>
  <a href="?" class="btn btn-outline btn-sm filters-bar-action">Сбросить</a>
  <a href="<?= BASE_URL ?>/api/export.php?type=bets&format=csv&<?= http_build_query(['status'=>$filterStatus,'user'=>$filterUser,'date_from'=>$filterDateFrom,'date_to'=>$filterDateTo]) ?>"
     class="btn btn-outline btn-sm filters-bar-action">⬇ CSV</a>
  <a href="<?= BASE_URL ?>/api/export.php?type=bets&format=txt&<?= http_build_query(['status'=>$filterStatus,'user'=>$filterUser,'date_from'=>$filterDateFrom,'date_to'=>$filterDateTo]) ?>"
     class="btn btn-outline btn-sm filters-bar-action">⬇ TXT</a>
  <span class="text-muted filters-bar-count">Найдено: <?= $total ?></span>
</form>

<?php if (empty($bets)): ?>
  <div class="card"><p class="text-muted text-center card-empty">Ставок не найдено.</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Пользователь</th>
          <th>Тип</th>
          <th>Статус</th>
          <th class="text-right">Сумма</th>
          <th class="text-right">Потенциал</th>
          <th>Создана</th>
          <th>Расчитана</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bets as $b):
          $bc = $statusBadge[$b['status']] ?? 'badge-muted';
          $bl = $statusLabel[$b['status']] ?? $b['status'];
        ?>
          <tr>
            <td class="text-muted"><?= $b['id'] ?></td>
            <td><?= htmlspecialchars($b['username'], ENT_QUOTES) ?></td>
            <td class="text-muted"><?= $b['items_cnt'] > 1 ? 'Экспресс ('.$b['items_cnt'].')' : 'Ординар' ?></td>
            <td><span class="badge <?= $bc ?>"><?= $bl ?></span></td>
            <td class="text-right"><?= number_format($b['total_amount'], 2) ?></td>
            <td class="text-right text-accent"><?= number_format($b['potential_win'], 2) ?></td>
            <td class="text-muted td-nowrap">
              <?= htmlspecialchars(date('d.m.Y H:i', strtotime($b['created_at'])), ENT_QUOTES) ?>
            </td>
            <td class="text-muted td-nowrap">
              <?= $b['settled_at']
                ? htmlspecialchars(date('d.m.Y H:i', strtotime($b['settled_at'])), ENT_QUOTES)
                : '—' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($lastPage > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="?<?= $qParams ?>&page=<?= $page-1 ?>">‹</a>
      <?php endif; ?>
      <?php for ($i = max(1,$page-3); $i <= min($lastPage,$page+3); $i++): ?>
        <?php if ($i === $page): ?>
          <span class="active"><?= $i ?></span>
        <?php else: ?>
          <a href="?<?= $qParams ?>&page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $lastPage): ?>
        <a href="?<?= $qParams ?>&page=<?= $page+1 ?>">›</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
