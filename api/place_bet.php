<?php
// ============================================================
// api/place_bet.php  —  POST: place bet (single or express)
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/odds.php';

header('Content-Type: application/json');

// Auth check
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    echo json_encode(['success' => false, 'error' => 'Необходима авторизация.']);
    exit;
}

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    echo json_encode(['success' => false, 'error' => 'Неверный токен. Обновите страницу.']);
    exit;
}

$pdo    = getPDO();
$userId = (int) $_SESSION['user_id'];

// ---- Input ----
$outcomeIds = array_map('intval', (array) ($_POST['outcome_ids'] ?? []));
$amount     = (float) ($_POST['amount'] ?? 0);

if (empty($outcomeIds) || count($outcomeIds) > 20) {
    echo json_encode(['success' => false, 'error' => 'Неверное количество исходов.']);
    exit;
}
if ($amount < 1) {
    echo json_encode(['success' => false, 'error' => 'Минимальная сумма ставки — 1 VCOIN.']);
    exit;
}
$amount = round($amount, 2);

// ---- Load outcomes (active, open market, not-finished event) ----
$in   = implode(',', $outcomeIds);
$rows = $pdo->query(
    "SELECT o.id, o.odds, o.status,
            o.event_market_id,
            em.status AS market_status,
            e.status  AS event_status
       FROM outcomes     o
       JOIN event_markets em ON em.id = o.event_market_id
       JOIN events        e  ON e.id  = em.event_id
      WHERE o.id IN ($in)"
)->fetchAll();

if (count($rows) !== count($outcomeIds)) {
    echo json_encode(['success' => false, 'error' => 'Один или несколько исходов не найдены.']);
    exit;
}

foreach ($rows as $r) {
    if ($r['status'] !== 'active') {
        echo json_encode(['success' => false, 'error' => 'Один из исходов недоступен для ставки.']);
        exit;
    }
    if ($r['market_status'] !== 'open') {
        echo json_encode(['success' => false, 'error' => 'Рынок закрыт или приостановлен.']);
        exit;
    }
    if (!in_array($r['event_status'], ['scheduled','live'], true)) {
        echo json_encode(['success' => false, 'error' => 'Событие недоступно для ставок.']);
        exit;
    }
}

// Compute total odds (product)
$totalOdds   = array_reduce($rows, fn($carry, $r) => $carry * (float)$r['odds'], 1.0);
$potentialWin = round($amount * $totalOdds, 2);

// ---- Check balance ----
$stmt = $pdo->prepare('SELECT id, balance FROM wallets WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$wallet = $stmt->fetch();

if (!$wallet || (float)$wallet['balance'] < $amount) {
    echo json_encode(['success' => false, 'error' => 'Недостаточно средств на кошельке.']);
    exit;
}

// ---- DB Transaction ----
$pdo->beginTransaction();

try {
    // 1. Deduct balance (SELECT FOR UPDATE)
    $stmt = $pdo->prepare(
        'SELECT balance FROM wallets WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$wallet['id']]);
    $currentBalance = (float) $stmt->fetchColumn();

    if ($currentBalance < $amount) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Недостаточно средств.']);
        exit;
    }

    $stmt = $pdo->prepare(
        'UPDATE wallets SET balance = balance - ? WHERE id = ?'
    );
    $stmt->execute([$amount, $wallet['id']]);

    // 2. INSERT bet
    $stmt = $pdo->prepare(
        'INSERT INTO bets (user_id, total_amount, potential_win, status)
         VALUES (?, ?, ?, \'pending\')'
    );
    $stmt->execute([$userId, $amount, $potentialWin]);
    $betId = (int) $pdo->lastInsertId();

    // 3. INSERT bet_items
    $stmt = $pdo->prepare(
        'INSERT INTO bet_items (bet_id, outcome_id, odds_at_bet, status)
         VALUES (?, ?, ?, \'pending\')'
    );
    $oddsMap = array_column($rows, 'odds', 'id');
    foreach ($outcomeIds as $oid) {
        $stmt->execute([$betId, $oid, $oddsMap[$oid]]);
    }

    // 4. Wallet transaction
    $desc = count($outcomeIds) === 1
        ? "Ставка #$betId (ординар)"
        : "Ставка #$betId (экспресс, " . count($outcomeIds) . " события)";

    $stmt = $pdo->prepare(
        'INSERT INTO wallet_transactions (wallet_id, type, amount, description)
         VALUES (?, \'bet_hold\', ?, ?)'
    );
    $stmt->execute([$wallet['id'], -$amount, $desc]);

    $pdo->commit();

    // ---- Recalculate odds for affected markets (outside transaction) ----
    $affectedMarkets = array_unique(array_column($rows, 'event_market_id'));
    foreach ($affectedMarkets as $emId) {
        recalculateMarketOdds($pdo, (int)$emId);
    }

    echo json_encode([
        'success'       => true,
        'bet_id'        => $betId,
        'potential_win' => number_format($potentialWin, 2),
    ]);

} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('place_bet error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка. Попробуйте ещё раз.']);
}
