<?php
// ============================================================
// user/bets.php  —  My bets list
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['user']);

$pdo  = getPDO();
$user = currentUser();

// Filter by status
$filterStatus = $_GET['status'] ?? '';
$allowed      = ['pending','won','lost','cancelled','refunded'];
if (!in_array($filterStatus, $allowed, true)) {
    $filterStatus = '';
}

// Pagination
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$whereStatus = $filterStatus !== '' ? "AND b.status = '$filterStatus'" : '';

$total = (int) $pdo->prepare(
    "SELECT COUNT(*) FROM bets b WHERE b.user_id = ? $whereStatus"
)->execute([$user['id']]) ? $pdo->query(
    "SELECT COUNT(*) FROM bets b WHERE b.user_id = {$user['id']} $whereStatus"
)->fetchColumn() : 0;

// Re-run properly with prepared stmt
$cntStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM bets b WHERE b.user_id = ? $whereStatus"
);
$cntStmt->execute([$user['id']]);
$total    = (int) $cntStmt->fetchColumn();
$lastPage = max(1, (int) ceil($total / $perPage));
$page     = min($page, $lastPage);
$offset   = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT b.id, b.total_amount, b.potential_win, b.status,
            b.created_at, b.settled_at
       FROM bets b
      WHERE b.user_id = ? $whereStatus
      ORDER BY b.created_at DESC
      LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$user['id']]);
$bets = $stmt->fetchAll();

// Fetch bet items for these bets
$betIds = array_column($bets, 'id');
$betItems = [];

if ($betIds) {
    $in   = implode(',', array_map('intval', $betIds));
    $rows = $pdo->query(
        "SELECT bi.bet_id, bi.odds_at_bet, bi.status AS item_status,
                o.name  AS outcome_name,
                m.name  AS market_name,
                e.title AS event_title,
                ht.name AS home_team,
                at.name AS away_team
           FROM bet_items bi
           JOIN outcomes     o   ON o.id    = bi.outcome_id
           JOIN event_markets em ON em.id   = o.event_market_id
           JOIN markets       m  ON m.id    = em.market_id
           JOIN events        e  ON e.id    = em.event_id
           JOIN teams         ht ON ht.id   = e.home_team_id
           JOIN teams         at ON at.id   = e.away_team_id
          WHERE bi.bet_id IN ($in)
          ORDER BY bi.bet_id, bi.id"
    )->fetchAll();

    foreach ($rows as $row) {
        $betItems[$row['bet_id']][] = $row;
    }
}

$statusBadge = [
    'pending'   => 'badge-info',
    'won'       => 'badge-success',
    'lost'      => 'badge-danger',
    'cancelled' => 'badge-muted',
    'refunded'  => 'badge-warning',
];
$statusLabel = [
    'pending'   => 'Ожидание',
    'won'       => 'Выиграна',
    'lost'      => 'Проиграна',
    'cancelled' => 'Отменена',
    'refunded'  => 'Возврат',
];
$itemBadge = [
    'pending' => 'badge-info',
    'won'     => 'badge-success',
    'lost'    => 'badge-danger',
    'void'    => 'badge-muted',
];
$itemLabel = [
    'pending' => 'Ожидание',
    'won'     => 'Победа',
    'lost'    => 'Поражение',
    'void'    => 'Возврат',
];

$pageTitle = 'Мои ставки';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <h1 class="page-title mb-0">Мои ставки</h1>
  <a href="<?= BASE_URL ?>/user/index.php" class="btn btn-outline btn-sm">← К событиям</a>
</div>

<!-- Filters -->
<form method="get" class="filters-bar">
  <div class="form-group">
    <label class="form-label">Статус</label>
    <div style="display:flex;flex-wrap:wrap;gap:.6rem .75rem;margin-top:.25rem;">
      <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;">
        <input type="radio" name="status" value=""
               onchange="this.form.submit()"
               <?= $filterStatus==='' ? 'checked' : '' ?>> Все
      </label>
      <?php foreach ($statusLabel as $val => $lbl): ?>
        <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;">
          <input type="radio" name="status" value="<?= $val ?>"
                 onchange="this.form.submit()"
                 <?= $filterStatus===$val ? 'checked' : '' ?>> <?= $lbl ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
  <span class="text-muted filters-bar-count">
    Найдено: <?= $total ?>
  </span>
</form>

<?php if (empty($bets)): ?>
  <div class="card">
    <p class="text-muted text-center card-empty">Ставок не найдено.</p>
  </div>
<?php else: ?>

  <?php foreach ($bets as $bet): ?>
    <?php
      $items   = $betItems[$bet['id']] ?? [];
      $isExpr  = count($items) > 1;
      $bClass  = $statusBadge[$bet['status']] ?? 'badge-muted';
      $bLabel  = $statusLabel[$bet['status']] ?? $bet['status'];
    ?>
    <div class="card mb-2">
      <div class="bet-header">
        <div>
          <span class="bet-number">
            <?= $isExpr ? 'Экспресс (' . count($items) . ' ставки)' : 'Ординар' ?>
          </span>
          <span class="badge <?= $bClass ?> badge--ml"><?= $bLabel ?></span>
        </div>
        <div class="bet-header-meta">
          <span>#<?= $bet['id'] ?></span>
          &nbsp;·&nbsp;
          <?= htmlspecialchars(date('d.m.Y H:i', strtotime($bet['created_at'])), ENT_QUOTES) ?>
        </div>
      </div>

      <!-- Bet items -->
      <?php foreach ($items as $item): ?>
        <?php
          $iClass = $itemBadge[$item['item_status']] ?? 'badge-muted';
          $iLabel = $itemLabel[$item['item_status']] ?? $item['item_status'];
        ?>
        <div class="bet-item-row">
          <div class="bet-item-info">
            <div class="bet-item-event">
              <?= htmlspecialchars($item['home_team'] . ' vs ' . $item['away_team'], ENT_QUOTES) ?>
              &nbsp;·&nbsp;<?= htmlspecialchars($item['market_name'], ENT_QUOTES) ?>
            </div>
            <div class="bet-item-name">
              <?= htmlspecialchars($item['outcome_name'], ENT_QUOTES) ?>
            </div>
          </div>
          <div class="bet-item-side">
            <span class="bet-item-odds-val">
              <?= number_format($item['odds_at_bet'], 2) ?>
            </span>
            <span class="badge <?= $iClass ?>"><?= $iLabel ?></span>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- Totals -->
      <div class="bet-totals">
        <div>
          <span class="text-muted">Сумма ставки: </span>
          <span class="bet-total-val"><?= number_format($bet['total_amount'], 2) ?> VCOIN</span>
        </div>
        <div>
          <span class="text-muted">Возможный выигрыш: </span>
          <span class="bet-total-win">
            <?= number_format($bet['potential_win'], 2) ?> VCOIN
          </span>
        </div>
        <?php if ($bet['settled_at']): ?>
          <div class="text-muted">
            Расчитана: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($bet['settled_at'])), ENT_QUOTES) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <!-- Pagination -->
  <?php if ($lastPage > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $lastPage; $i++): ?>
        <?php if ($i === $page): ?>
          <span class="active"><?= $i ?></span>
        <?php else: ?>
          <a href="?status=<?= urlencode($filterStatus) ?>&page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
