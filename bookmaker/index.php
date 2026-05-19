<?php
// ============================================================
// bookmaker/index.php  —  My events list
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['bookmaker']);

$pdo  = getPDO();
$user = currentUser();

$events = $pdo->prepare(
    "SELECT e.id, e.title, e.start_time, e.status,
            e.home_score, e.away_score,
            s.name  AS sport_name,
            ht.name AS home_team,
            at.name AS away_team,
            (SELECT COUNT(*) FROM event_markets em WHERE em.event_id = e.id) AS markets_cnt,
            (SELECT COUNT(*) FROM event_markets em2
              JOIN outcomes o ON o.event_market_id = em2.id
             WHERE em2.event_id = e.id) AS outcomes_cnt
       FROM events e
       JOIN sports s  ON s.id  = e.sport_id
       JOIN teams  ht ON ht.id = e.home_team_id
       JOIN teams  at ON at.id = e.away_team_id
      WHERE e.created_by = ?
      ORDER BY FIELD(e.status,'live','scheduled','finished','cancelled'), e.start_time DESC"
);
$events->execute([$user['id']]);
$events = $events->fetchAll();

$statusBadge = [
    'scheduled' => ['badge-info',    'Запланировано'],
    'live'      => ['badge-danger',  'LIVE'],
    'finished'  => ['badge-success', 'Завершено'],
    'cancelled' => ['badge-muted',   'Отменено'],
];

$pageTitle = 'Мои события';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-3">
  <h1 class="page-title mb-0">Мои события</h1>
  <a href="<?= BASE_URL ?>/bookmaker/event_create.php" class="btn btn-primary">+ Создать событие</a>
</div>

<?php if (empty($events)): ?>
  <div class="card">
    <p class="text-muted text-center card-empty">
      Нет созданных событий. <a href="<?= BASE_URL ?>/bookmaker/event_create.php">Создайте первое.</a>
    </p>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Событие</th>
          <th>Спорт</th>
          <th>Начало</th>
          <th>Статус</th>
          <th>Рынки / Исходы</th>
          <th>Счёт</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $ev): ?>
          <?php [$bClass, $bLabel] = $statusBadge[$ev['status']] ?? ['badge-muted', $ev['status']]; ?>
          <tr>
            <td class="text-muted"><?= $ev['id'] ?></td>
            <td>
              <strong><?= htmlspecialchars($ev['home_team'] . ' vs ' . $ev['away_team'], ENT_QUOTES) ?></strong>
            </td>
            <td class="text-muted"><?= htmlspecialchars($ev['sport_name'], ENT_QUOTES) ?></td>
            <td class="text-muted td-nowrap">
              <?= htmlspecialchars(date('d.m.Y H:i', strtotime($ev['start_time'])), ENT_QUOTES) ?>
            </td>
            <td><span class="badge <?= $bClass ?>"><?= $bLabel ?></span></td>
            <td class="text-muted text-center">
              <?= $ev['markets_cnt'] ?> / <?= $ev['outcomes_cnt'] ?>
            </td>
            <td class="text-warning fw-700">
              <?php if ($ev['status'] === 'live' || $ev['status'] === 'finished'): ?>
                <?= (int)$ev['home_score'] ?>:<?= (int)$ev['away_score'] ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="td-actions">
              <a href="<?= BASE_URL ?>/bookmaker/event_edit.php?id=<?= $ev['id'] ?>"
                 class="btn btn-outline btn-sm">Редактировать</a>
              <?php if (in_array($ev['status'], ['live','finished'], true)): ?>
                <a href="<?= BASE_URL ?>/bookmaker/settle.php?id=<?= $ev['id'] ?>"
                   class="btn btn-primary btn-sm">Расчёт</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
