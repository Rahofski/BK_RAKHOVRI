<?php
// ============================================================
// admin/sports.php  —  CRUD sports
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['admin']);

$pdo     = getPDO();
$adminId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---- helper: cascade delete one sport ----
    $cascadeSport = function(int $id) use ($pdo, $adminId): void {
        try {
            $pdo->prepare('CALL sp_cascade_delete_sport(?)')->execute([$id]);
        } catch (\PDOException $e) {
            // Stored proc may not exist yet — fallback manual cascade
            $pdo->prepare('DELETE bi FROM bet_items bi
                INNER JOIN outcomes o ON o.id=bi.outcome_id
                INNER JOIN event_markets em ON em.id=o.event_market_id
                INNER JOIN events e ON e.id=em.event_id
                WHERE e.sport_id=?')->execute([$id]);
            $pdo->prepare('DELETE o FROM outcomes o
                INNER JOIN event_markets em ON em.id=o.event_market_id
                INNER JOIN events e ON e.id=em.event_id
                WHERE e.sport_id=?')->execute([$id]);
            $pdo->prepare('DELETE em FROM event_markets em
                INNER JOIN events e ON e.id=em.event_id
                WHERE e.sport_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM events WHERE sport_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM teams WHERE sport_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM sports WHERE id=?')->execute([$id]);
        }
    };

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash('error', 'Введите название.');
        } else {
            try {
                $pdo->prepare('INSERT INTO sports (name) VALUES (?)')->execute([$name]);
                $pdo->prepare(
                    'INSERT INTO audit_logs (user_id,action,entity_type,entity_id)
                     VALUES (?,\'create_sport\',\'sport\',?)'
                )->execute([$adminId, (int)$pdo->lastInsertId()]);
                flash('success', 'Вид спорта добавлен.');
            } catch (\PDOException $e) {
                flash('error', 'Такое название уже существует.');
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['sport_id'] ?? 0);
        $cascadeSport($id);
        flash('success', 'Вид спорта и связанные данные удалены.');

    } elseif ($action === 'bulk_delete') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        foreach (array_filter($ids) as $id) {
            $cascadeSport($id);
        }
        flash('success', 'Удалено видов спорта: ' . count($ids));
    }

    header('Location: ' . BASE_URL . '/admin/sports.php'); exit;
}

$sports = $pdo->query(
    'SELECT s.id, s.name, COUNT(t.id) AS teams_cnt
       FROM sports s
       LEFT JOIN teams t ON t.sport_id = s.id
      GROUP BY s.id, s.name
      ORDER BY s.name'
)->fetchAll();

$pageTitle = 'Виды спорта';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-3">
  <h1 class="page-title mb-0">Виды спорта</h1>
</div>

<div class="two-col two-col--340">

  <!-- Table with checkboxes -->
  <div>
    <form method="post" id="sportsBulkForm">
      <?= csrf_field() ?>
      <div class="flex-gap mb-2" style="gap:.5rem;">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="toggleAll('sport-cb',this)">Выбрать все</button>
        <button type="submit" name="action" value="bulk_delete"
                class="btn btn-danger btn-sm"
                onclick="return confirmBulkS('видов спорта (и всех связанных данных!)')">
          Удалить выбранные
        </button>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th style="width:2rem;"></th><th>#</th><th>Название</th><th class="text-right">Команд</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($sports as $s): ?>
              <tr>
                <td>
                  <input type="checkbox" name="ids[]" value="<?= $s['id'] ?>"
                         class="sport-cb" style="width:15px;height:15px;cursor:pointer;accent-color:var(--accent);">
                </td>
                <td class="text-muted"><?= $s['id'] ?></td>
                <td><?= htmlspecialchars($s['name'], ENT_QUOTES) ?></td>
                <td class="text-right text-muted"><?= $s['teams_cnt'] ?></td>
                <td>
                  <button type="submit" name="action" value="delete"
                          class="btn btn-danger btn-sm"
                          onclick="document.querySelector('[name=sport_id]') || addHidden(this,'sport_id',<?= $s['id'] ?>); return confirm('Каскадно удалить вид спорта и все связанные данные?')">
                    Удалить
                  </button>
                  <input type="hidden" name="sport_id" id="sport_id_<?= $s['id'] ?>" value="0">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
  </div>

    </form>
  </div>

  <!-- Add form -->
  <div class="card">
    <div class="card-header">Добавить вид спорта</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label class="form-label" for="name">Название</label>
        <input class="form-control" type="text" id="name" name="name" required>
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
function confirmBulkS(label) {
  const n = document.querySelectorAll('.sport-cb:checked').length;
  if (!n) { alert('Ничего не выбрано.'); return false; }
  return confirm('Удалить ' + n + ' ' + label + '? Это действие необратимо!');
}
// Set hidden sport_id on single-delete click
document.addEventListener('click', function(e) {
  if (e.target.matches('[name=action][value=delete]')) {
    const tr = e.target.closest('tr');
    const cb = tr && tr.querySelector('[name="ids[]"]');
    if (cb) {
      document.querySelectorAll('[name=sport_id]').forEach(h => h.value = 0);
      tr.querySelector('[id^=sport_id_]').value = cb.value;
    }
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
