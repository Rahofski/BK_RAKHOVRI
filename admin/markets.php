<?php
// ============================================================
// admin/markets.php  —  CRUD markets (reference list)
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['admin']);

$pdo     = getPDO();
$adminId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($code === '' || $name === '') {
            flash('error', 'Заполните все поля.');
        } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $code)) {
            flash('error', 'Код должен содержать только латинские буквы, цифры и "_".');
        } else {
            try {
                $pdo->prepare('INSERT INTO markets (code, name) VALUES (?,?)')->execute([$code, $name]);
                flash('success', 'Рынок добавлен.');
            } catch (\PDOException $e) {
                flash('error', 'Такой код уже существует.');
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['market_id'] ?? 0);
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM event_markets WHERE market_id=?');
        $cnt->execute([$id]);
        if ($cnt->fetchColumn() > 0) {
            flash('error', 'Нельзя удалить: рынок используется в событиях.');
        } else {
            $pdo->prepare('DELETE FROM markets WHERE id=?')->execute([$id]);
            flash('success', 'Рынок удалён.');
        }

    } elseif ($action === 'bulk_delete') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $deleted = 0;
        foreach (array_filter($ids) as $id) {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM event_markets WHERE market_id=?');
            $cnt->execute([$id]);
            if ($cnt->fetchColumn() == 0) {
                $pdo->prepare('DELETE FROM markets WHERE id=?')->execute([$id]);
                $deleted++;
            }
        }
        flash('success', "Удалено рынков: $deleted (использующиеся пропущены).");
    }

    header('Location: ' . BASE_URL . '/admin/markets.php'); exit;
}

$markets = $pdo->query(
    'SELECT m.id, m.code, m.name,
            COUNT(em.id) AS usage_cnt
       FROM markets m
       LEFT JOIN event_markets em ON em.market_id = m.id
      GROUP BY m.id, m.code, m.name
      ORDER BY m.name'
)->fetchAll();

$pageTitle = 'Рынки';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-3">
  <h1 class="page-title mb-0">Рынки ставок</h1>
</div>

<div class="two-col two-col--360">

  <!-- Table with checkboxes -->
  <div>
    <form method="post" id="mktBulkForm">
      <?= csrf_field() ?>
      <div class="flex-gap mb-2" style="gap:.5rem;">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="toggleAll('mkt-cb',this)">Выбрать все</button>
        <button type="submit" name="action" value="bulk_delete"
                class="btn btn-danger btn-sm"
                onclick="return confirmBulkM()">Удалить выбранные</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th style="width:2rem;"></th><th>#</th><th>Код</th><th>Название</th><th class="text-right">Исп. в событиях</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($markets as $m): ?>
              <tr>
                <td>
                  <?php if ($m['usage_cnt'] == 0): ?>
                    <input type="checkbox" name="ids[]" value="<?= $m['id'] ?>"
                           class="mkt-cb" style="width:15px;height:15px;cursor:pointer;accent-color:var(--accent);">
                  <?php endif; ?>
                </td>
                <td class="text-muted"><?= $m['id'] ?></td>
                <td class="td-mono"><?= htmlspecialchars($m['code'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($m['name'], ENT_QUOTES) ?></td>
                <td class="text-right text-muted"><?= $m['usage_cnt'] ?></td>
                <td>
                  <?php if ($m['usage_cnt'] == 0): ?>
                    <input type="hidden" name="market_id" id="mkt_id_<?= $m['id'] ?>" value="0">
                    <button type="submit" name="action" value="delete"
                            class="btn btn-danger btn-sm"
                            onclick="document.querySelectorAll('[name=market_id]').forEach(h=>h.value=0); document.getElementById('mkt_id_<?= $m['id'] ?>').value=<?= $m['id'] ?>; return confirm('Удалить рынок?')">
                      Удалить
                    </button>
                  <?php else: ?>
                    <span class="text-muted text-xs">Используется</span>
                  <?php endif; ?>
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
    <div class="card-header">Добавить рынок</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label class="form-label" for="code">Код</label>
        <input class="form-control" type="text" id="code" name="code"
               placeholder="1x2 или total_2_5" pattern="[A-Za-z0-9_]+" required>
        <p class="form-hint">Только латинские буквы, цифры и "_".</p>
      </div>
      <div class="form-group">
        <label class="form-label" for="mname">Название</label>
        <input class="form-control" type="text" id="mname" name="name"
               placeholder="Исход матча (1X2)" required>
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
function confirmBulkM() {
  const n = document.querySelectorAll('.mkt-cb:checked').length;
  if (!n) { alert('Ничего не выбрано.'); return false; }
  return confirm('Удалить ' + n + ' рынков?');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
