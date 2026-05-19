<?php
// ============================================================
// bookmaker/settle.php  —  Settle event: choose winning outcomes
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['bookmaker']);

$pdo  = getPDO();
$user = currentUser();

$eventId = (int)($_GET['id'] ?? 0);
if (!$eventId) {
    flash('error', 'Событие не найдено.'); header('Location: ' . BASE_URL . '/bookmaker/index.php'); exit;
}

$stmt = $pdo->prepare(
    "SELECT e.*, ht.name AS home_team, at.name AS away_team, s.name AS sport_name
       FROM events e
       JOIN sports s  ON s.id  = e.sport_id
       JOIN teams  ht ON ht.id = e.home_team_id
       JOIN teams  at ON at.id = e.away_team_id
      WHERE e.id = ? AND e.created_by = ? LIMIT 1"
);
$stmt->execute([$eventId, $user['id']]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Событие не найдено или нет доступа.');
    header('Location: ' . BASE_URL . '/bookmaker/index.php'); exit;
}

if ($event['status'] === 'cancelled') {
    flash('warning', 'Отменённое событие не нуждается в расчёте.');
    header('Location: ' . BASE_URL . '/bookmaker/index.php'); exit;
}

// Load event_markets + outcomes (only not yet settled)
$markets = $pdo->prepare(
    "SELECT em.id AS em_id, em.status AS market_status, m.name
       FROM event_markets em
       JOIN markets m ON m.id = em.market_id
      WHERE em.event_id = ? AND em.status != 'settled'
      ORDER BY m.name"
);
$markets->execute([$eventId]);
$markets = $markets->fetchAll();

$emIds = array_column($markets, 'em_id');
$outcomesByEM = [];
if ($emIds) {
    $in   = implode(',', array_map('intval', $emIds));
    $rows = $pdo->query(
        "SELECT id, event_market_id, name, code, status FROM outcomes WHERE event_market_id IN ($in) ORDER BY id"
    )->fetchAll();
    foreach ($rows as $o) {
        $outcomesByEM[$o['event_market_id']][] = $o;
    }
}

$pageTitle = 'Расчёт ставок';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-3">
  <div>
    <h1 class="page-title mb-0">Расчёт ставок</h1>
    <div class="page-subtitle">
      <?= htmlspecialchars($event['home_team'] . ' vs ' . $event['away_team'], ENT_QUOTES) ?>
      · <?= htmlspecialchars(date('d.m.Y H:i', strtotime($event['start_time'])), ENT_QUOTES) ?>
    </div>
  </div>
  <a href="<?= BASE_URL ?>/bookmaker/index.php" class="btn btn-outline btn-sm">← Назад</a>
</div>

<?php if (empty($markets)): ?>
  <div class="card">
    <p class="text-muted text-center card-empty">
      Все рынки уже расчитаны или рынков нет.
    </p>
  </div>
<?php else: ?>

<div class="alert alert-warning">
  Укажите для каждого рынка выигравший исход. Остальные исходы будут помечены как проигравшие.
  Исход «Возврат» — если рынок аннулируется. Это действие <strong>нельзя отменить</strong>.
</div>

<form method="post" action="<?= BASE_URL ?>/api/settle_event.php"
      onsubmit="return confirmSettle()">
  <?= csrf_field() ?>
  <input type="hidden" name="event_id" value="<?= $eventId ?>">
  <input type="hidden" name="home_score" id="fs-home" value="<?= (int)$event['home_score'] ?>">
  <input type="hidden" name="away_score" id="fs-away" value="<?= (int)$event['away_score'] ?>">

  <!-- Final score -->
  <div class="card mb-3">
    <div class="card-header">Финальный счёт</div>
    <div class="form-row form-row--score">
      <div class="form-group">
        <label class="form-label"><?= htmlspecialchars($event['home_team'], ENT_QUOTES) ?></label>
        <input class="form-control" type="number" min="0" name="home_score"
               value="<?= (int)$event['home_score'] ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label"><?= htmlspecialchars($event['away_team'], ENT_QUOTES) ?></label>
        <input class="form-control" type="number" min="0" name="away_score"
               value="<?= (int)$event['away_score'] ?>" required>
      </div>
    </div>
  </div>

  <!-- Markets -->
  <?php foreach ($markets as $mkt): ?>
    <?php $outs = $outcomesByEM[$mkt['em_id']] ?? []; ?>
    <div class="card mb-2">
      <div class="card-header"><?= htmlspecialchars($mkt['name'], ENT_QUOTES) ?></div>

      <div class="form-group">
        <label class="form-label">Результат рынка</label>
        <select class="form-control" name="market_result[<?= $mkt['em_id'] ?>]" required>
          <option value="">— выберите результат —</option>
          <?php foreach ($outs as $out): ?>
            <option value="won:<?= $out['id'] ?>">
              ✓ Победа: <?= htmlspecialchars($out['name'], ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
          <option value="void">↩ Аннулировать рынок (возврат)</option>
        </select>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="flex-gap mt-3">
    <button type="submit" class="btn btn-danger btn-lg">Провести расчёт</button>
    <a href="<?= BASE_URL ?>/bookmaker/index.php" class="btn btn-outline">Отмена</a>
  </div>
</form>

<script>
function confirmSettle() {
  return confirm('Вы уверены? Расчёт ставок нельзя отменить. Продолжить?');
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
