<?php
// ============================================================
// analyst/index.php  —  Overview stats (read-only)
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['analyst', 'admin']);

$pdo = getPDO();

// General stats
$stats = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM bets)                              AS total_bets,
       (SELECT COUNT(*) FROM bets WHERE status='pending')       AS pending_bets,
       (SELECT COUNT(*) FROM bets WHERE status='won')           AS won_bets,
       (SELECT COUNT(*) FROM bets WHERE status='lost')          AS lost_bets,
       (SELECT COALESCE(SUM(total_amount),0) FROM bets)         AS total_wagered,
       (SELECT COALESCE(SUM(potential_win),0) FROM bets
         WHERE status='pending')                                AS risk_exposure,
       (SELECT COALESCE(SUM(amount),0)
          FROM wallet_transactions WHERE type='bet_win')        AS total_paid_out,
       (SELECT COUNT(*) FROM events WHERE status IN ('scheduled','live')) AS active_events,
       (SELECT COUNT(*) FROM users WHERE status='active')       AS active_users
    "
)->fetch();

// Top 5 sports by bet count
$topSports = $pdo->query(
    "SELECT s.name, COUNT(bi.id) AS cnt,
            COALESCE(SUM(b.total_amount),0) AS wagered
       FROM bet_items bi
       JOIN bets          b  ON b.id  = bi.bet_id
       JOIN outcomes      o  ON o.id  = bi.outcome_id
       JOIN event_markets em ON em.id = o.event_market_id
       JOIN events        e  ON e.id  = em.event_id
       JOIN sports        s  ON s.id  = e.sport_id
      GROUP BY s.id, s.name
      ORDER BY cnt DESC
      LIMIT 5"
)->fetchAll();

// Top 5 markets by bet count
$topMarkets = $pdo->query(
    "SELECT m.name, COUNT(bi.id) AS cnt,
            COALESCE(SUM(b.total_amount),0) AS wagered
       FROM bet_items bi
       JOIN bets          b  ON b.id  = bi.bet_id
       JOIN outcomes      o  ON o.id  = bi.outcome_id
       JOIN event_markets em ON em.id = o.event_market_id
       JOIN markets       m  ON m.id  = em.market_id
      GROUP BY m.id, m.name
      ORDER BY cnt DESC
      LIMIT 5"
)->fetchAll();

// Recent 10 bets
$recentBets = $pdo->query(
    "SELECT b.id, b.total_amount, b.potential_win, b.status, b.created_at,
            u.username,
            COUNT(bi.id) AS items_cnt
       FROM bets b
       JOIN users     u  ON u.id  = b.user_id
       JOIN bet_items bi ON bi.bet_id = b.id
      GROUP BY b.id, b.total_amount, b.potential_win, b.status, b.created_at, u.username
      ORDER BY b.created_at DESC
      LIMIT 10"
)->fetchAll();

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

$pageTitle = 'Аналитика — Обзор';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Обзор</h1>

<!-- KPI cards -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-value"><?= number_format($stats['total_bets']) ?></div>
    <div class="stat-label">Всего ставок</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= number_format($stats['pending_bets']) ?></div>
    <div class="stat-label">В ожидании</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= number_format($stats['won_bets']) ?></div>
    <div class="stat-label">Выиграно</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= number_format($stats['lost_bets']) ?></div>
    <div class="stat-label">Проиграно</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= number_format($stats['total_wagered'], 0) ?></div>
    <div class="stat-label">Принято (VCOIN)</div>
  </div>
  <div class="stat-card stat-card--warning">
    <div class="stat-value stat-value--warning">
      <?= number_format($stats['risk_exposure'], 0) ?>
    </div>
    <div class="stat-label">Потенциальные выплаты</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= number_format($stats['total_paid_out'], 0) ?></div>
    <div class="stat-label">Выплачено (VCOIN)</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['active_events'] ?></div>
    <div class="stat-label">Активных событий</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['active_users'] ?></div>
    <div class="stat-label">Пользователей</div>
  </div>
</div>

<!-- Charts row -->
<?php
$chartStatusLabels = array_values($statusLabel);
$chartStatusData   = [
    (int)$stats['pending_bets'],
    (int)$stats['won_bets'],
    (int)$stats['lost_bets'],
    (int)($stats['total_bets'] - $stats['pending_bets'] - $stats['won_bets'] - $stats['lost_bets']),
    0, // refunded placeholder counted below
];
// More precise: count cancelled+refunded
$extraBets = $pdo->query(
    "SELECT status, COUNT(*) AS cnt FROM bets
      WHERE status IN ('cancelled','refunded')
      GROUP BY status"
)->fetchAll(\PDO::FETCH_KEY_PAIR);
$chartStatusData = [
    (int)$stats['pending_bets'],
    (int)$stats['won_bets'],
    (int)$stats['lost_bets'],
    (int)($extraBets['cancelled'] ?? 0),
    (int)($extraBets['refunded']  ?? 0),
];
$sportNames  = array_column($topSports, 'name');
$sportCounts = array_column($topSports, 'cnt');
?>

<div class="two-col two-col--equal mt-3">
  <div class="card">
    <div class="card-header">Распределение ставок по статусу</div>
    <div style="padding:1rem;max-height:260px;display:flex;justify-content:center;">
      <canvas id="chartStatus"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header">Топ видов спорта по ставкам</div>
    <div style="padding:1rem;max-height:260px;">
      <canvas id="chartSports"></canvas>
    </div>
  </div>
</div>

<div class="two-col two-col--equal">

  <!-- Top sports table -->
  <div class="card">
    <div class="card-header">Топ видов спорта</div>
    <?php if (empty($topSports)): ?>
      <p class="text-muted text-sm">Нет данных.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Вид спорта</th><th class="text-right">Ставок</th><th class="text-right">Сумма</th></tr></thead>
          <tbody>
            <?php foreach ($topSports as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['name'], ENT_QUOTES) ?></td>
                <td class="text-right"><?= number_format($r['cnt']) ?></td>
                <td class="text-right text-accent"><?= number_format($r['wagered'], 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Top markets -->
  <div class="card">
    <div class="card-header">Топ рынков</div>
    <?php if (empty($topMarkets)): ?>
      <p class="text-muted text-sm">Нет данных.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Рынок</th><th class="text-right">Ставок</th><th class="text-right">Сумма</th></tr></thead>
          <tbody>
            <?php foreach ($topMarkets as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['name'], ENT_QUOTES) ?></td>
                <td class="text-right"><?= number_format($r['cnt']) ?></td>
                <td class="text-right text-accent"><?= number_format($r['wagered'], 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Recent bets -->
<div class="card mt-3">
  <div class="card-header card-header--flex">
    Последние 10 ставок
    <a href="<?= BASE_URL ?>/analyst/bets.php" class="btn btn-ghost btn-sm">Все ставки →</a>
  </div>
  <?php if (empty($recentBets)): ?>
    <p class="text-muted text-sm">Ставок пока нет.</p>
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
            <th>Дата</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentBets as $b): ?>
            <?php $bc = $statusBadge[$b['status']] ?? 'badge-muted'; $bl = $statusLabel[$b['status']] ?? $b['status']; ?>
            <tr>
              <td class="text-muted"><?= $b['id'] ?></td>
              <td><?= htmlspecialchars($b['username'], ENT_QUOTES) ?></td>
              <td class="text-muted"><?= $b['items_cnt'] > 1 ? 'Экспресс' : 'Ординар' ?></td>
              <td><span class="badge <?= $bc ?>"><?= $bl ?></span></td>
              <td class="text-right"><?= number_format($b['total_amount'], 2) ?></td>
              <td class="text-right text-accent"><?= number_format($b['potential_win'], 2) ?></td>
              <td class="text-muted td-nowrap">
                <?= htmlspecialchars(date('d.m.Y H:i', strtotime($b['created_at'])), ENT_QUOTES) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function() {
  const statusLabels = <?= json_encode(array_values($statusLabel)) ?>;
  const statusData   = <?= json_encode($chartStatusData) ?>;
  const sportLabels  = <?= json_encode($sportNames) ?>;
  const sportData    = <?= json_encode(array_map('intval', $sportCounts)) ?>;

  const palette = ['#6c8ebf','#82c991','#e07070','#b0a0c0','#e8c56a'];

  new Chart(document.getElementById('chartStatus'), {
    type: 'pie',
    data: {
      labels: statusLabels,
      datasets: [{ data: statusData, backgroundColor: palette, borderWidth: 1, borderColor: '#1a1c2a' }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { color: '#c0c8e0', boxWidth: 14 } }
      }
    }
  });

  new Chart(document.getElementById('chartSports'), {
    type: 'bar',
    data: {
      labels: sportLabels,
      datasets: [{
        label: 'Ставок',
        data: sportData,
        backgroundColor: palette,
        borderRadius: 4,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { color: '#8090b0' }, grid: { color: '#2a2e44' } },
        y: { ticks: { color: '#8090b0' }, grid: { color: '#2a2e44' } }
      }
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
