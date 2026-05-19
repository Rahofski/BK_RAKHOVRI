<?php
// ============================================================
// bookmaker/event_create.php  —  Create new event
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['bookmaker']);

$pdo  = getPDO();
$user = currentUser();

// Load sports
$sports = $pdo->query('SELECT id, name FROM sports ORDER BY name')->fetchAll();

// Load teams (grouped by sport) — used by JS
$teams = $pdo->query(
    'SELECT id, sport_id, name FROM teams ORDER BY name'
)->fetchAll();

// Group teams by sport for JSON
$teamsBySport = [];
foreach ($teams as $t) {
    $teamsBySport[$t['sport_id']][] = ['id' => $t['id'], 'name' => $t['name']];
}

$errors = [];
$data   = [
    'sport_id'     => '',
    'home_team_id' => '',
    'away_team_id' => '',
    'title'        => '',
    'start_time'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data['sport_id']     = (int) ($_POST['sport_id']     ?? 0);
    $data['home_team_id'] = (int) ($_POST['home_team_id'] ?? 0);
    $data['away_team_id'] = (int) ($_POST['away_team_id'] ?? 0);
    $data['title']        = trim($_POST['title']          ?? '');
    $data['start_time']   = trim($_POST['start_time']     ?? '');

    // Validation
    if (!$data['sport_id'])     { $errors[] = 'Выберите вид спорта.'; }
    if (!$data['home_team_id']) { $errors[] = 'Выберите хозяев.'; }
    if (!$data['away_team_id']) { $errors[] = 'Выберите гостей.'; }
    if ($data['home_team_id'] && $data['home_team_id'] === $data['away_team_id']) {
        $errors[] = 'Хозяева и гости не могут быть одной командой.';
    }
    if ($data['title'] === '')  { $errors[] = 'Введите название события.'; }
    if ($data['start_time'] === '' || !strtotime($data['start_time'])) {
        $errors[] = 'Введите корректную дату и время начала.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            'INSERT INTO events
               (sport_id, home_team_id, away_team_id, title, start_time, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['sport_id'],
            $data['home_team_id'],
            $data['away_team_id'],
            $data['title'],
            date('Y-m-d H:i:s', strtotime($data['start_time'])),
            $user['id'],
        ]);
        $eventId = (int) $pdo->lastInsertId();

        // ---- Каскадное добавление: стандартный рынок 1x2 + исходы ----
        $defaultMarket = $pdo->prepare(
            "SELECT id FROM markets WHERE code = '1x2' LIMIT 1"
        );
        $defaultMarket->execute();
        $marketId = $defaultMarket->fetchColumn();

        if ($marketId) {
            // Создаём event_market
            $pdo->prepare(
                "INSERT INTO event_markets (event_id, market_id, status) VALUES (?, ?, 'open')"
            )->execute([$eventId, $marketId]);
            $emId = (int) $pdo->lastInsertId();

            // Стандартные коэффициенты: П1 / Ничья / П2
            $defaultOutcomes = [
                ['Победа хозяев', 'home', 2.00],
                ['Ничья',         'draw', 3.00],
                ['Победа гостей', 'away', 3.50],
            ];
            $oStmt = $pdo->prepare(
                'INSERT INTO outcomes (event_market_id, name, code, odds, initial_odds)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($defaultOutcomes as [$name, $code, $odds]) {
                $oStmt->execute([$emId, $name, $code, $odds, $odds]);
            }
        }
        // ---- конец каскадного добавления ----

        flash('success', 'Событие создано. Рынок «Исход матча (1X2)» добавлен автоматически. Добавьте другие рынки и скорректируйте коэффициенты.');
        header('Location: ' . BASE_URL . '/bookmaker/event_edit.php?id=' . $eventId);
        exit;
    }
}

$pageTitle = 'Создать событие';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-3">
  <h1 class="page-title mb-0">Создать событие</h1>
  <a href="<?= BASE_URL ?>/bookmaker/index.php" class="btn btn-outline btn-sm">← Назад</a>
</div>

<div class="card card--narrow">
  <?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= htmlspecialchars($e, ENT_QUOTES) ?></div>
  <?php endforeach; ?>

  <form method="post" action="">
    <?= csrf_field() ?>

    <div class="form-group">
      <label class="form-label" for="sport_id">Вид спорта</label>
      <select class="form-control" id="sport_id" name="sport_id"
              onchange="loadTeams(this.value)" required>
        <option value="">— выберите —</option>
        <?php foreach ($sports as $sp): ?>
          <option value="<?= $sp['id'] ?>"
            <?= $data['sport_id'] == $sp['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($sp['name'], ENT_QUOTES) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="home_team_id">Хозяева</label>
        <select class="form-control" id="home_team_id" name="home_team_id" required>
          <option value="">— выберите —</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="away_team_id">Гости</label>
        <select class="form-control" id="away_team_id" name="away_team_id" required>
          <option value="">— выберите —</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="title">Название события</label>
      <input
        class="form-control"
        type="text"
        id="title"
        name="title"
        value="<?= htmlspecialchars($data['title'], ENT_QUOTES) ?>"
        placeholder="Например: Реал Мадрид — Барселона"
        required
      >
      <p class="form-hint">Можно оставить автогенерацию (кнопка ниже) или ввести вручную.</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="start_time">Дата и время начала</label>
      <input
        class="form-control"
        type="datetime-local"
        id="start_time"
        name="start_time"
        value="<?= htmlspecialchars($data['start_time'], ENT_QUOTES) ?>"
        required
      >
    </div>

    <div class="flex-gap mt-3">
      <button type="button" class="btn btn-outline btn-sm" onclick="autoTitle()">
        Авто-название
      </button>
      <button type="submit" class="btn btn-primary">Создать событие</button>
    </div>
  </form>
</div>

<script>
const teamsBySport = <?= json_encode($teamsBySport) ?>;

function loadTeams(sportId) {
  const teams  = teamsBySport[sportId] || [];
  const selH   = document.getElementById('home_team_id');
  const selA   = document.getElementById('away_team_id');
  const savedH = <?= json_encode((string)$data['home_team_id']) ?>;
  const savedA = <?= json_encode((string)$data['away_team_id']) ?>;

  [selH, selA].forEach(sel => {
    sel.innerHTML = '<option value="">— выберите —</option>';
    teams.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = t.name;
      sel.appendChild(opt);
    });
  });

  if (savedH) selH.value = savedH;
  if (savedA) selA.value = savedA;
}

function autoTitle() {
  const selH = document.getElementById('home_team_id');
  const selA = document.getElementById('away_team_id');
  const hText = selH.options[selH.selectedIndex]?.text;
  const aText = selA.options[selA.selectedIndex]?.text;
  if (hText && aText && hText !== '— выберите —' && aText !== '— выберите —') {
    document.getElementById('title').value = hText + ' — ' + aText;
  } else {
    alert('Сначала выберите обе команды.');
  }
}

// Init on page load if sport already selected
const initSport = document.getElementById('sport_id').value;
if (initSport) loadTeams(initSport);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
