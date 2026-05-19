<?php
// ============================================================
// user/wallet.php  —  Balance, deposit, transaction history
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['user']);

$pdo  = getPDO();
$user = currentUser();

// Fetch wallet
$stmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$wallet = $stmt->fetch();

// Fetch last 50 transactions
$stmt = $pdo->prepare(
    'SELECT wt.type, wt.amount, wt.description, wt.created_at
       FROM wallet_transactions wt
      WHERE wt.wallet_id = ?
      ORDER BY wt.created_at DESC
      LIMIT 50'
);
$stmt->execute([$wallet['id']]);
$transactions = $stmt->fetchAll();

$typeLabels = [
    'deposit'          => ['label' => 'Пополнение',      'class' => 'badge-success'],
    'bet_hold'         => ['label' => 'Ставка',           'class' => 'badge-warning'],
    'bet_win'          => ['label' => 'Выигрыш',          'class' => 'badge-success'],
    'bet_refund'       => ['label' => 'Возврат',          'class' => 'badge-info'],
    'admin_adjustment' => ['label' => 'Корректировка',    'class' => 'badge-muted'],
];

$pageTitle = 'Кошелёк';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Кошелёк</h1>

<div class="two-col two-col--equal">

  <!-- Balance + Deposit -->
  <div class="card">
    <div class="card-header">Баланс</div>

    <div class="wallet-balance"><?= number_format($wallet['balance'], 2) ?></div>
    <div class="wallet-currency">VCOIN</div>

    <p class="wallet-hint">Пополните виртуальный кошелёк для совершения ставок.</p>

    <form method="post" action="<?= BASE_URL ?>/api/deposit.php" id="deposit-form">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label">Быстрое пополнение</label>
        <div class="deposit-amounts">
          <?php foreach ([100, 500, 1000, 5000] as $amt): ?>
            <button type="button" class="btn btn-outline"
                    onclick="document.getElementById('deposit-amount').value=<?= $amt ?>;document.getElementById('deposit-amount').dispatchEvent(new Event('input'))">
              +<?= $amt ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="deposit-amount">Своя сумма</label>
        <input
          class="form-control"
          type="number"
          id="deposit-amount"
          name="amount"
          min="1"
          max="100000"
          step="1"
          placeholder="Введите сумму"
          oninput="updateDepositBtn(this.value)"
        >
      </div>

      <button type="submit" class="btn btn-primary" id="deposit-btn" disabled>
        Пополнить на <span id="deposit-label">—</span> VCOIN
      </button>
    </form>
  </div>

  <!-- Placeholder for future stats -->
  <div class="card">
    <div class="card-header">Статистика</div>
    <?php
      // Quick summary
      $summary = $pdo->prepare(
          "SELECT
             SUM(CASE WHEN type='bet_hold'   THEN ABS(amount) ELSE 0 END) AS total_bet,
             SUM(CASE WHEN type='bet_win'    THEN amount       ELSE 0 END) AS total_win,
             SUM(CASE WHEN type='deposit'    THEN amount       ELSE 0 END) AS total_dep,
             COUNT(CASE WHEN type='bet_hold' THEN 1 END) AS cnt_bets
           FROM wallet_transactions
          WHERE wallet_id = ?"
      );
      $summary->execute([$wallet['id']]);
      $stats = $summary->fetch();
    ?>
    <div class="stats-grid stats-grid--half mb-0">
      <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['total_dep'] ?? 0, 0) ?></div>
        <div class="stat-label">Пополнено</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= $stats['cnt_bets'] ?? 0 ?></div>
        <div class="stat-label">Ставок</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['total_bet'] ?? 0, 0) ?></div>
        <div class="stat-label">Потрачено</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['total_win'] ?? 0, 0) ?></div>
        <div class="stat-label">Выиграно</div>
      </div>
    </div>
  </div>

</div>

<!-- Transaction History -->
<div class="card mt-3">
  <div class="card-header">История транзакций</div>

  <?php if (empty($transactions)): ?>
    <p class="text-muted text-center card-empty">Транзакций пока нет.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Дата</th>
            <th>Тип</th>
            <th>Описание</th>
            <th class="text-right">Сумма</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transactions as $tx): ?>
            <?php
              $info   = $typeLabels[$tx['type']] ?? ['label' => $tx['type'], 'class' => 'badge-muted'];
              $plus   = in_array($tx['type'], ['deposit','bet_win','bet_refund']);
              $amtClass = $plus ? 'text-success' : 'text-danger';
              $amtSign  = $plus ? '+' : '-';
            ?>
            <tr>
              <td class="text-muted td-nowrap">
                <?= htmlspecialchars(date('d.m.Y H:i', strtotime($tx['created_at'])), ENT_QUOTES) ?>
              </td>
              <td>
                <span class="badge <?= $info['class'] ?>"><?= $info['label'] ?></span>
              </td>
              <td><?= htmlspecialchars($tx['description'] ?? '—', ENT_QUOTES) ?></td>
              <td class="text-right <?= $amtClass ?> td-amount">
                <?= $amtSign ?><?= number_format(abs($tx['amount']), 2) ?> VCOIN
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
function updateDepositBtn(val) {
  const n = parseInt(val);
  const btn   = document.getElementById('deposit-btn');
  const label = document.getElementById('deposit-label');
  if (n > 0) {
    btn.disabled = false;
    label.textContent = n.toLocaleString('ru-RU');
  } else {
    btn.disabled = true;
    label.textContent = '—';
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
