<?php
// ============================================================
// analyst/events.php  —  Risk per event
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['analyst', 'admin']);

$pdo = getPDO();

$filterStatus = $_GET['status'] ?? '';
$allowed      = ['scheduled','live','finished','cancelled'];
if (!in_array($filterStatus, $allowed, true)) $filterStatus = '';

$whereStatus = $filterStatus ? "WHERE e.status = '$filterStatus'" : '';

$events = $pdo->query(
    "SELECT e.id, e.title, e.start_time, e.status,
            e.home_score, e.away_score,
            s.name  AS sport_name,
            ht.name AS home_team,
            at.name AS away_team,
            COALESCE(risk.bet_count,    0) AS bet_count,
            COALESCE(risk.total_wagered,0) AS total_wagered,
            COALESCE(risk.potential_payout,0) AS potential_payout
       FROM events e
       JOIN sports s  ON s.id  = e.sport_id
       JOIN teams  ht ON ht.id = e.home_team_id
       JOIN teams  at ON at.id = e.away_team_id
       LEFT JOIN (
         SELECT em.event_id,
                COUNT(DISTINCT b.id)            AS bet_count,
                SUM(b.total_amount)             AS total_wagered,
                SUM(CASE WHEN b.status='pending' THEN b.potential_win ELSE 0 END) AS potential_payout
           FROM bet_items bi
           JOIN bets          b  ON b.id  = bi.bet_id
           JOIN outcomes      o  ON o.id  = bi.outcome_id
           JOIN event_markets em ON em.id = o.event_market_id
          GROUP BY em.event_id
       ) risk ON risk.event_id = e.id
       $whereStatus
      ORDER BY FIELD(e.status,'live','scheduled','finished','cancelled'), e.start_time DESC
      LIMIT 200"
)->fetchAll();

$statusBadge = [
    'scheduled' => ['badge-info',    'Запланировано'],
    'live'      => ['badge-danger',  'LIVE'],
    'finished'  => ['badge-success', 'Завершено'],
    'cancelled' => ['badge-muted',   'Отменено'],
];

$pageTitle = 'Риск по событиям';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Риск по событиям</h1>

<div class="filters-bar mb-3">
  <div class="form-group">
    <label class="form-label">Статус</label>
    <select class="form-control filter-select"
            onchange="location.href='?status='+this.value">
      <option value="" <?= $filterStatus==='' ? 'selected':'' ?>>Все</option>
      <option value="scheduled" <?= $filterStatus==='scheduled' ? 'selected':'' ?>>Запланировано</option>
      <option value="live"      <?= $filterStatus==='live'      ? 'selected':'' ?>>LIVE</option>
      <option value="finished"  <?= $filterStatus==='finished'  ? 'selected':'' ?>>Завершено</option>
      <option value="cancelled" <?= $filterStatus==='cancelled' ? 'selected':'' ?>>Отменено</option>
    </select>
  </div>
</div>

<?php if (empty($events)): ?>
  <div class="card"><p class="text-muted text-center card-empty">Событий не найдено.</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Событие</th>
          <th>Спорт</th>
          <th>Дата</th>
          <th>Статус</th>
          <th class="text-right">Ставок</th>
          <th class="text-right">Принято (VCOIN)</th>
          <th class="text-right">Риск (VCOIN)</th>
          <th class="text-right">Маржа %</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $ev):
          [$bClass, $bLabel] = $statusBadge[$ev['status']] ?? ['badge-muted', $ev['status']];
          $margin = $ev['total_wagered'] > 0
            ? round((1 - $ev['potential_payout'] / $ev['total_wagered']) * 100, 1)
            : null;
        ?>
          <tr>
            <td class="text-muted"><?= $ev['id'] ?></td>
            <td>
              <strong><?= htmlspecialchars($ev['home_team'] . ' — ' . $ev['away_team'], ENT_QUOTES) ?></strong>
              <?php if ($ev['status'] === 'live' || $ev['status'] === 'finished'): ?>
                <span class="score-value">
                  <?= (int)$ev['home_score'] ?>:<?= (int)$ev['away_score'] ?>
                </span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= htmlspecialchars($ev['sport_name'], ENT_QUOTES) ?></td>
            <td class="text-muted td-nowrap">
              <?= htmlspecialchars(date('d.m H:i', strtotime($ev['start_time'])), ENT_QUOTES) ?>
            </td>
            <td><span class="badge <?= $bClass ?>"><?= $bLabel ?></span></td>
            <td class="text-right"><?= number_format($ev['bet_count']) ?></td>
            <td class="text-right"><?= number_format($ev['total_wagered'], 0) ?></td>
            <td class="text-right <?= $ev['potential_payout'] > $ev['total_wagered'] ? 'text-danger' : 'text-success' ?>">
              <?= number_format($ev['potential_payout'], 0) ?>
            </td>
            <td class="text-right">
              <?php if ($margin !== null): ?>
                <span class="<?= $margin < 0 ? 'text-danger' : 'text-success' ?>">
                  <?= $margin ?>%
                </span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
