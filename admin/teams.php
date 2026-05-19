<?php
// ============================================================
// admin/teams.php  —  CRUD teams
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['admin']);

$pdo     = getPDO();
$adminId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $sportId = (int)($_POST['sport_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        if (!$sportId || $name === '') {
            flash('error', 'Заполните все поля.');
        } else {
            try {
                $pdo->prepare('INSERT INTO teams (sport_id, name) VALUES (?,?)')->execute([$sportId, $name]);
                flash('success', 'Команда добавлена.');
            } catch (\PDOException $e) {
                flash('error', 'Ошибка: ' . $e->getMessage());
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['team_id'] ?? 0);
        // Cascade delete via stored proc or inline
        try {
            $pdo->prepare('CALL sp_cascade_delete_team(?)')->execute([$id]);
        } catch (\PDOException $e) {
            $pdo->prepare('DELETE bi FROM bet_items bi
                INNER JOIN outcomes o ON o.id=bi.outcome_id
                INNER JOIN event_markets em ON em.id=o.event_market_id
                INNER JOIN events e ON e.id=em.event_id
                WHERE e.home_team_id=? OR e.away_team_id=?')->execute([$id, $id]);
            $pdo->prepare('DELETE o FROM outcomes o
                INNER JOIN event_markets em ON em.id=o.event_market_id
                INNER JOIN events e ON e.id=em.event_id
                WHERE e.home_team_id=? OR e.away_team_id=?')->execute([$id, $id]);
            $pdo->prepare('DELETE em FROM event_markets em
                INNER JOIN events e ON e.id=em.event_id
                WHERE e.home_team_id=? OR e.away_team_id=?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM events WHERE home_team_id=? OR away_team_id=?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM teams WHERE id=?')->execute([$id]);
        }
        flash('success', 'Команда и связанные данные удалены.');

    } elseif ($action === 'bulk_delete') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        foreach (array_filter($ids) as $id) {
            try {
                $pdo->prepare('CALL sp_cascade_delete_team(?)')->execute([$id]);
            } catch (\PDOException $e) {
                $pdo->prepare('DELETE bi FROM bet_items bi
                    INNER JOIN outcomes o ON o.id=bi.outcome_id
                    INNER JOIN event_markets em ON em.id=o.event_market_id
                    INNER JOIN events e ON e.id=em.event_id
                    WHERE e.home_team_id=? OR e.away_team_id=?')->execute([$id, $id]);
                $pdo->prepare('DELETE o FROM outcomes o
                    INNER JOIN event_markets em ON em.id=o.event_market_id
                    INNER JOIN events e ON e.id=em.event_id
                    WHERE e.home_team_id=? OR e.away_team_id=?')->execute([$id, $id]);
                $pdo->prepare('DELETE em FROM event_markets em
                    INNER JOIN events e ON e.id=em.event_id
                    WHERE e.home_team_id=? OR e.away_team_id=?')->execute([$id, $id]);
                $pdo->prepare('DELETE FROM events WHERE home_team_id=? OR away_team_id=?')->execute([$id, $id]);
                $pdo->prepare('DELETE FROM teams WHERE id=?')->execute([$id]);
            }
        }
        flash('success', 'Удалено команд: ' . count($ids));
    }

    header('Location: ' . BASE_URL . '/admin/teams.php' .
        (isset($_POST['filter_sport']) ? '?sport=' . (int)$_POST['filter_sport'] : ''));
    exit;
}

$sports = $pdo->query('SELECT id, name FROM sports ORDER BY name')->fetchAll();

$filterSport = (int)($_GET['sport'] ?? 0);
$params = [];
$where  = '1=1';

if ($filterSport) {
    $where  = 't.sport_id = ?';
    $params[] = $filterSport;
}

$stmt = $pdo->prepare(
    "SELECT t.id, t.name, s.name AS sport_name, s.id AS sport_id,
            (SELECT COUNT(*) FROM events e WHERE e.home_team_id=t.id OR e.away_team_id=t.id) AS events_cnt
       FROM teams t
       JOIN sports s ON s.id = t.sport_id
      WHERE $where
      ORDER BY s.name, t.name
      LIMIT 300"
);
$stmt->execute($params);
$teams = $stmt->fetchAll();

$pageTitle = 'Команды';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-3">
  <h1 class="page-title mb-0">Команды</h1>
</div>

<div class="two-col two-col--340">

    <!-- Filter + Table -->
    <div>
      <div class="filters-bar mb-3 filters-bar--compact">
        <form method="get" class="flex-gap">
          <div class="form-group mb-0">
            <label class="form-label">Вид спорта</label>
            <select class="form-control" name="sport" onchange="this.form.submit()">
              <option value="">Все</option>
              <?php foreach ($sports as $sp): ?>
                <option value="<?= $sp['id'] ?>" <?= $filterSport == $sp['id'] ? 'selected':'' ?>>
                  <?= htmlspecialchars($sp['name'], ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>

      <form method="post" id="teamsBulkForm">
        <?= csrf_field() ?>
        <input type="hidden" name="filter_sport" value="<?= $filterSport ?>">
        <div class="flex-gap mb-2" style="gap:.5rem;">
          <button type="button" class="btn btn-outline btn-sm"
                  onclick="toggleAll('team-cb',this)">Выбрать все</button>
          <button type="submit" name="action" value="bulk_delete"
                  class="btn btn-danger btn-sm"
                  onclick="return confirmBulkT()">Удалить выбранные</button>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th style="width:2rem;"></th><th>#</th><th>Команда</th><th>Вид спорта</th><th class="text-right">Событий</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($teams as $t): ?>
                <tr>
                  <td>
                    <input type="checkbox" name="ids[]" value="<?= $t['id'] ?>"
                           class="team-cb" style="width:15px;height:15px;cursor:pointer;accent-color:var(--accent);">
                  </td>
                  <td class="text-muted"><?= $t['id'] ?></td>
                  <td><?= htmlspecialchars($t['name'], ENT_QUOTES) ?></td>
                  <td class="text-muted"><?= htmlspecialchars($t['sport_name'], ENT_QUOTES) ?></td>
                  <td class="text-right text-muted"><?= $t['events_cnt'] ?></td>
                  <td>
                    <input type="hidden" name="team_id" id="team_id_<?= $t['id'] ?>" value="0">
                    <button type="submit" name="action" value="delete"
                            class="btn btn-danger btn-sm"
                            onclick="document.querySelectorAll('[name=team_id]').forEach(h=>h.value=0); document.getElementById('team_id_<?= $t['id'] ?>').value=<?= $t['id'] ?>; return confirm('Каскадно удалить команду и связанные данные?')">
                      Удалить
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </form>
    </div>

  <!-- Add form -->
  <div class="card">
    <div class="card-header">Добавить команду</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label class="form-label">Вид спорта</label>
        <select class="form-control" name="sport_id" required>
          <option value="">— выберите —</option>
          <?php foreach ($sports as $sp): ?>
            <option value="<?= $sp['id'] ?>"
              <?= $filterSport == $sp['id'] ? 'selected':'' ?>>
              <?= htmlspecialchars($sp['name'], ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Название команды</label>
        <input class="form-control" type="text" name="name" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Добавить</button>
    </form>
  </div>

</div>

<script>
function toggleAll(cls, btn) {
  const cbs = document.querySelectorAll('.' + cls);
  const all = Array.from(cbs).every(c => c.checked);
  cbs.forEach(c => c.checked = !all);
  btn.textContent = all ? 'Выбрать все' : 'Снять все';
}
function confirmBulkT() {
  const n = document.querySelectorAll('.team-cb:checked').length;
  if (!n) { alert('Ничего не выбрано.'); return false; }
  return confirm('Удалить ' + n + ' команд(ы) и все связанные данные? Действие необратимо!');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
