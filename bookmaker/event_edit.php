<?php
// ============================================================
// bookmaker/event_edit.php  —  Add markets/outcomes, update status/score
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['bookmaker']);

$pdo  = getPDO();
$user = currentUser();

$eventId = (int)($_GET['id'] ?? 0);
if (!$eventId) {
    flash('error', 'Событие не найдено.');
    header('Location: ' . BASE_URL . '/bookmaker/index.php');
    exit;
}

// Load event (must belong to this bookmaker)
$stmt = $pdo->prepare(
    "SELECT e.*, s.name AS sport_name, ht.name AS home_team, at.name AS away_team
       FROM events e
       JOIN sports s  ON s.id  = e.sport_id
       JOIN teams  ht ON ht.id = e.home_team_id
       JOIN teams  at ON at.id = e.away_team_id
      WHERE e.id = ? AND e.created_by = ?
      LIMIT 1"
);
$stmt->execute([$eventId, $user['id']]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Событие не найдено или у вас нет доступа.');
    header('Location: ' . BASE_URL . '/bookmaker/index.php');
    exit;
}

$errors = [];

// ---- Handle POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // === Update status/score ===
    if ($action === 'update_status') {
        $newStatus  = $_POST['status'] ?? '';
        $homeScore  = isset($_POST['home_score']) ? (int)$_POST['home_score'] : null;
        $awayScore  = isset($_POST['away_score']) ? (int)$_POST['away_score'] : null;
        $validStatuses = ['scheduled','live','cancelled'];

        if (!in_array($newStatus, $validStatuses, true)) {
            $errors[] = 'Недопустимый статус.';
        } else {
            $pdo->prepare(
                'UPDATE events SET status=?, home_score=?, away_score=? WHERE id=?'
            )->execute([$newStatus, $homeScore, $awayScore, $eventId]);
            flash('success', 'Статус события обновлён.');
            header('Location: ' . BASE_URL . '/bookmaker/event_edit.php?id=' . $eventId);
            exit;
        }
    }

    // === Add market to event ===
    if ($action === 'add_market') {
        $marketId = (int)($_POST['market_id'] ?? 0);
        if (!$marketId) {
            $errors[] = 'Выберите рынок.';
        } else {
            // Check not already added
            $s = $pdo->prepare(
                'SELECT id FROM event_markets WHERE event_id=? AND market_id=? LIMIT 1'
            );
            $s->execute([$eventId, $marketId]);
            if ($s->fetch()) {
                $errors[] = 'Этот рынок уже добавлен к событию.';
            } else {
                $pdo->prepare(
                    'INSERT INTO event_markets (event_id, market_id, status) VALUES (?,?,\'open\')'
                )->execute([$eventId, $marketId]);
                flash('success', 'Рынок добавлен.');
                header('Location: ' . BASE_URL . '/bookmaker/event_edit.php?id=' . $eventId);
                exit;
            }
        }
    }

    // === Add outcome to event_market ===
    if ($action === 'add_outcome') {
        $emId   = (int)  ($_POST['event_market_id'] ?? 0);
        $name   = trim(   $_POST['outcome_name']    ?? '');
        $code   = trim(   $_POST['outcome_code']    ?? '');
        $odds   = (float)($_POST['outcome_odds']    ?? 0);

        if (!$emId)           { $errors[] = 'Выберите рынок.'; }
        if ($name === '')     { $errors[] = 'Введите название исхода.'; }
        if ($code === '')     { $errors[] = 'Введите код исхода (например: home, draw, away).'; }
        if ($odds < 1.01)     { $errors[] = 'Коэффициент должен быть не менее 1.01.'; }

        if (empty($errors)) {
            // Verify em belongs to this event
            $s = $pdo->prepare(
                'SELECT id FROM event_markets WHERE id=? AND event_id=? LIMIT 1'
            );
            $s->execute([$emId, $eventId]);
            if (!$s->fetch()) {
                $errors[] = 'Рынок не принадлежит этому событию.';
            } else {
                $roundedOdds = round($odds, 2);
                $pdo->prepare(
                    'INSERT INTO outcomes (event_market_id, name, code, odds, initial_odds, status, updated_by)
                     VALUES (?,?,?,?,?,\'active\',?)'
                )->execute([$emId, $name, $code, $roundedOdds, $roundedOdds, $user['id']]);
                flash('success', 'Исход добавлен.');
                header('Location: ' . BASE_URL . '/bookmaker/event_edit.php?id=' . $eventId);
                exit;
            }
        }
    }

    // === Update outcome odds ===
    if ($action === 'update_odds') {
        $outcomeId = (int)  ($_POST['outcome_id'] ?? 0);
        $newOdds   = (float)($_POST['new_odds']   ?? 0);

        if ($newOdds < 1.01) {
            $errors[] = 'Коэффициент должен быть не менее 1.01.';
        } else {
            // Verify outcome belongs to this event
            $s = $pdo->prepare(
                'SELECT o.id FROM outcomes o
                   JOIN event_markets em ON em.id = o.event_market_id
                  WHERE o.id=? AND em.event_id=? LIMIT 1'
            );
            $s->execute([$outcomeId, $eventId]);
            if ($s->fetch()) {
                $roundedNew = round($newOdds, 2);
                // Bookmaker manually reprices → reset initial_odds (new baseline)
                $pdo->prepare(
                    'UPDATE outcomes SET odds=?, initial_odds=?, updated_by=? WHERE id=?'
                )->execute([$roundedNew, $roundedNew, $user['id'], $outcomeId]);
                flash('success', 'Коэффициент обновлён.');
            }
            header('Location: ' . BASE_URL . '/bookmaker/event_edit.php?id=' . $eventId);
            exit;
        }
    }

    // === Toggle outcome/market status ===
    if ($action === 'toggle_outcome') {
        $outcomeId = (int)($_POST['outcome_id'] ?? 0);
        $s = $pdo->prepare(
            'SELECT o.id, o.status FROM outcomes o
               JOIN event_markets em ON em.id = o.event_market_id
              WHERE o.id=? AND em.event_id=? LIMIT 1'
        );
        $s->execute([$outcomeId, $eventId]);
        $out = $s->fetch();
        if ($out) {
            $newSt = $out['status'] === 'active' ? 'suspended' : 'active';
            $pdo->prepare(
                'UPDATE outcomes SET status=?, updated_by=? WHERE id=?'
            )->execute([$newSt, $user['id'], $outcomeId]);
        }
        header('Location: ' . BASE_URL . '/bookmaker/event_edit.php?id=' . $eventId);
        exit;
    }
}

// ---- Load markets for this event ----
$eventMarkets = $pdo->prepare(
    'SELECT em.id AS em_id, em.status AS market_status, m.code, m.name
       FROM event_markets em
       JOIN markets m ON m.id = em.market_id
      WHERE em.event_id = ?
      ORDER BY m.name'
);
$eventMarkets->execute([$eventId]);
$eventMarkets = $eventMarkets->fetchAll();

// ---- Load outcomes per event_market ----
$emIds = array_column($eventMarkets, 'em_id');
$outcomesByEM = [];
if ($emIds) {
    $in   = implode(',', array_map('intval', $emIds));
    $rows = $pdo->query(
        "SELECT * FROM outcomes WHERE event_market_id IN ($in) ORDER BY event_market_id, id"
    )->fetchAll();
    foreach ($rows as $o) {
        $outcomesByEM[$o['event_market_id']][] = $o;
    }
}

// ---- All markets not yet added ----
$addedIds = array_column($eventMarkets, 'em_id');
$availableMarkets = $pdo->query('SELECT id, code, name FROM markets ORDER BY name')->fetchAll();
$alreadyAdded = $pdo->prepare(
    'SELECT market_id FROM event_markets WHERE event_id = ?'
);
$alreadyAdded->execute([$eventId]);
$alreadyAddedIds = array_column($alreadyAdded->fetchAll(), 'market_id');

$availableMarkets = array_filter(
    $availableMarkets,
    fn($m) => !in_array($m['id'], $alreadyAddedIds)
);

$isEditable = in_array($event['status'], ['scheduled','live']);

$statusBadge = [
    'scheduled' => ['badge-info',    'Запланировано'],
    'live'      => ['badge-danger',  'LIVE'],
    'finished'  => ['badge-success', 'Завершено'],
    'cancelled' => ['badge-muted',   'Отменено'],
];

$pageTitle = 'Редактировать событие';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-3">
  <div>
    <h1 class="page-title mb-0">
      <?= htmlspecialchars($event['home_team'] . ' vs ' . $event['away_team'], ENT_QUOTES) ?>
    </h1>
    <div class="page-subtitle">
      <?= htmlspecialchars($event['sport_name'], ENT_QUOTES) ?> ·
      <?= htmlspecialchars(date('d.m.Y H:i', strtotime($event['start_time'])), ENT_QUOTES) ?>
      &nbsp;
      <?php [$bClass, $bLabel] = $statusBadge[$event['status']] ?? ['badge-muted', $event['status']]; ?>
      <span class="badge <?= $bClass ?>"><?= $bLabel ?></span>
      <?php if (in_array($event['status'], ['live','finished'])): ?>
        <span class="score-value">
          <?= (int)$event['home_score'] ?>:<?= (int)$event['away_score'] ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="flex-gap">
    <a href="<?= BASE_URL ?>/bookmaker/index.php" class="btn btn-outline btn-sm">← Назад</a>
    <?php if (in_array($event['status'], ['live','finished'])): ?>
      <a href="<?= BASE_URL ?>/bookmaker/settle.php?id=<?= $eventId ?>" class="btn btn-primary btn-sm">
        Расчёт ставок
      </a>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-error"><?= htmlspecialchars($e, ENT_QUOTES) ?></div>
<?php endforeach; ?>

<div class="two-col two-col--320">

  <!-- ======= Left: Markets + Outcomes ======= -->
  <div>

    <?php if ($isEditable): ?>
    <!-- Add market form -->
    <div class="card mb-3">
      <div class="card-header">Добавить рынок</div>
      <?php if (empty($availableMarkets)): ?>
        <p class="text-muted text-sm">Все доступные рынки уже добавлены.</p>
      <?php else: ?>
        <form method="post" class="flex-gap">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_market">
          <select class="form-control flex-1" name="market_id" required>
            <option value="">— выберите рынок —</option>
            <?php foreach ($availableMarkets as $mk): ?>
              <option value="<?= $mk['id'] ?>">
                <?= htmlspecialchars($mk['name'], ENT_QUOTES) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary btn-sm">Добавить</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Markets + Outcomes -->
    <?php if (empty($eventMarkets)): ?>
      <div class="card">
        <p class="text-muted text-center card-empty">Рынков ещё нет.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($eventMarkets as $mkt): ?>
      <?php $outs = $outcomesByEM[$mkt['em_id']] ?? []; ?>
      <div class="card mb-2">
        <div class="card-header card-header--flex">
          <span><?= htmlspecialchars($mkt['name'], ENT_QUOTES) ?></span>
          <span class="badge <?= $mkt['market_status'] === 'open' ? 'badge-success' : 'badge-warning' ?>">
            <?= $mkt['market_status'] ?>
          </span>
        </div>

        <!-- Outcomes table -->
        <?php if (!empty($outs)): ?>
          <div class="table-wrap <?= $isEditable ? 'mb-2' : 'mb-0' ?>">
            <table>
              <thead>
                <tr>
                  <th>Исход</th>
                  <th>Код</th>
                  <th>Коэффициент</th>
                  <th>Статус</th>
                  <?php if ($isEditable): ?><th>Действия</th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($outs as $out): ?>
                  <tr>
                    <td><?= htmlspecialchars($out['name'], ENT_QUOTES) ?></td>
                    <td class="td-mono">
                      <?= htmlspecialchars($out['code'], ENT_QUOTES) ?>
                    </td>
                    <td class="odds-value">
                      <?php if ($isEditable): ?>
                        <form method="post" class="odds-form">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="update_odds">
                          <input type="hidden" name="outcome_id" value="<?= $out['id'] ?>">
                          <input class="form-control input-odds" type="number" name="new_odds"
                                 value="<?= $out['odds'] ?>" min="1.01" step="0.01">
                          <button type="submit" class="btn btn-ghost btn-sm">✓</button>
                        </form>
                      <?php else: ?>
                        <?= number_format($out['odds'], 2) ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge <?= $out['status'] === 'active' ? 'badge-success' : 'badge-warning' ?>">
                        <?= $out['status'] ?>
                      </span>
                    </td>
                    <?php if ($isEditable): ?>
                    <td>
                      <form method="post" class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_outcome">
                        <input type="hidden" name="outcome_id" value="<?= $out['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm">
                          <?= $out['status'] === 'active' ? 'Приостановить' : 'Активировать' ?>
                        </button>
                      </form>
                    </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <!-- Add outcome form -->
        <?php if ($isEditable): ?>
          <details>
            <summary>
              + Добавить исход
            </summary>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="add_outcome">
              <input type="hidden" name="event_market_id" value="<?= $mkt['em_id'] ?>">
              <div class="form-row form-row--3">
                <div class="form-group">
                  <label class="form-label">Название</label>
                  <input class="form-control" type="text" name="outcome_name"
                         placeholder="Победа хозяев" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Код</label>
                  <input class="form-control" type="text" name="outcome_code"
                         placeholder="home" pattern="[A-Za-z0-9_]+" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Коэффициент</label>
                  <input class="form-control" type="number" name="outcome_odds"
                         min="1.01" step="0.01" placeholder="1.85" required>
                </div>
              </div>
              <button type="submit" class="btn btn-primary btn-sm">Сохранить исход</button>
            </form>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

  </div>

  <!-- ======= Right: Status / Score update ======= -->
  <?php if ($isEditable): ?>
  <div>
    <div class="card">
      <div class="card-header">Управление статусом</div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_status">

        <div class="form-group">
          <label class="form-label">Статус события</label>
          <select class="form-control" name="status">
            <option value="scheduled" <?= $event['status']==='scheduled' ? 'selected':'' ?>>Запланировано</option>
            <option value="live"      <?= $event['status']==='live'      ? 'selected':'' ?>>LIVE</option>
            <option value="cancelled" <?= $event['status']==='cancelled' ? 'selected':'' ?>>Отменить</option>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Счёт хозяев</label>
            <input class="form-control" type="number" name="home_score"
                   min="0" value="<?= (int)$event['home_score'] ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Счёт гостей</label>
            <input class="form-control" type="number" name="away_score"
                   min="0" value="<?= (int)$event['away_score'] ?>">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Обновить</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
