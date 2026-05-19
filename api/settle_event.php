<?php
// ============================================================
// api/settle_event.php  —  POST: settle event outcomes + auto-settle bets
// ============================================================
require_once __DIR__ . '/../bootstrap.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'bookmaker') {
    flash('error', 'Доступ запрещён.');
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

csrf_verify();

$pdo    = getPDO();
$userId = (int) $_SESSION['user_id'];

$eventId    = (int)   ($_POST['event_id']   ?? 0);
$homeScore  = (int)   ($_POST['home_score'] ?? 0);
$awayScore  = (int)   ($_POST['away_score'] ?? 0);
$mktResults = (array) ($_POST['market_result'] ?? []);

if (!$eventId) {
    flash('error', 'Событие не указано.');
    header('Location: ' . BASE_URL . '/bookmaker/index.php');
    exit;
}

// Verify event belongs to this bookmaker
$stmt = $pdo->prepare(
    "SELECT id, status FROM events WHERE id = ? AND created_by = ? LIMIT 1"
);
$stmt->execute([$eventId, $userId]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Событие не найдено или нет доступа.');
    header('Location: ' . BASE_URL . '/bookmaker/index.php');
    exit;
}

if ($event['status'] === 'finished') {
    flash('warning', 'Событие уже завершено.');
    header('Location: ' . BASE_URL . '/bookmaker/index.php');
    exit;
}

if (empty($mktResults)) {
    flash('error', 'Укажите результат хотя бы для одного рынка.');
    header('Location: ' . BASE_URL . '/bookmaker/settle.php?id=' . $eventId);
    exit;
}

// ---- Validate market_result values ----
// Format: 'won:<outcome_id>'  OR  'void'
$parsed = [];
foreach ($mktResults as $emId => $val) {
    $emId = (int) $emId;
    if (!$emId) continue;

    if ($val === 'void') {
        $parsed[$emId] = ['type' => 'void', 'winner_id' => null];
    } elseif (str_starts_with($val, 'won:')) {
        $winnerId = (int) substr($val, 4);
        if (!$winnerId) {
            flash('error', 'Некорректный выигравший исход.');
            header('Location: ' . BASE_URL . '/bookmaker/settle.php?id=' . $eventId);
            exit;
        }
        $parsed[$emId] = ['type' => 'won', 'winner_id' => $winnerId];
    } else {
        flash('error', 'Неизвестный формат результата.');
        header('Location: ' . BASE_URL . '/bookmaker/settle.php?id=' . $eventId);
        exit;
    }
}

// ---- BEGIN TRANSACTION ----
$pdo->beginTransaction();

try {
    // 1. Mark event as finished, update final score
    $pdo->prepare(
        'UPDATE events SET status=\'finished\', home_score=?, away_score=? WHERE id=?'
    )->execute([$homeScore, $awayScore, $eventId]);

    $settledAt = date('Y-m-d H:i:s');

    // 2. For each event_market: set outcomes won/lost/void + mark market settled
    foreach ($parsed as $emId => $result) {

        // Verify em belongs to this event
        $s = $pdo->prepare(
            'SELECT id FROM event_markets WHERE id=? AND event_id=? LIMIT 1'
        );
        $s->execute([$emId, $eventId]);
        if (!$s->fetch()) continue;

        if ($result['type'] === 'void') {
            // All outcomes → void
            $pdo->prepare(
                'UPDATE outcomes SET status=\'void\', updated_by=? WHERE event_market_id=?'
            )->execute([$userId, $emId]);

        } else {
            // Winner → won, rest → lost
            $pdo->prepare(
                'UPDATE outcomes SET status=\'lost\', updated_by=? WHERE event_market_id=?'
            )->execute([$userId, $emId]);

            $pdo->prepare(
                'UPDATE outcomes SET status=\'won\', updated_by=? WHERE id=? AND event_market_id=?'
            )->execute([$userId, $result['winner_id'], $emId]);
        }

        // Mark market as settled
        $pdo->prepare(
            'UPDATE event_markets SET status=\'settled\' WHERE id=?'
        )->execute([$emId]);
    }

    // 3. Auto-settle bets that contain outcomes from this event
    // Find all pending bet_items whose outcome belongs to this event
    $affectedBets = $pdo->prepare(
        "SELECT DISTINCT bi.bet_id
           FROM bet_items bi
           JOIN outcomes     o  ON o.id  = bi.outcome_id
           JOIN event_markets em ON em.id = o.event_market_id
          WHERE em.event_id = ?
            AND bi.status   = 'pending'"
    );
    $affectedBets->execute([$eventId]);
    $betIds = array_column($affectedBets->fetchAll(), 'bet_id');

    foreach ($betIds as $betId) {
        // Update bet_item statuses from settled outcomes
        $pdo->prepare(
            "UPDATE bet_items bi
               JOIN outcomes o ON o.id = bi.outcome_id
                SET bi.status = CASE
                  WHEN o.status = 'won'  THEN 'won'
                  WHEN o.status = 'lost' THEN 'lost'
                  WHEN o.status = 'void' THEN 'void'
                  ELSE bi.status
                END
              WHERE bi.bet_id = ?
                AND o.status IN ('won','lost','void')"
        )->execute([$betId]);

        // Re-read all items for this bet
        $items = $pdo->prepare(
            'SELECT bi.status AS item_status
               FROM bet_items bi
              WHERE bi.bet_id = ?'
        );
        $items->execute([$betId]);
        $itemStatuses = array_column($items->fetchAll(), 'item_status');

        // If any still pending → bet not yet fully settled (multi-event express)
        if (in_array('pending', $itemStatuses, true)) {
            continue;
        }

        // Determine bet result
        $bet = $pdo->prepare(
            'SELECT id, user_id, total_amount, potential_win FROM bets WHERE id=? LIMIT 1'
        );
        $bet->execute([$betId]);
        $bet = $bet->fetch();

        // Load wallet
        $w = $pdo->prepare(
            'SELECT w.id FROM wallets w WHERE w.user_id = ? LIMIT 1'
        );
        $w->execute([$bet['user_id']]);
        $walletId = $w->fetchColumn();

        if (in_array('lost', $itemStatuses, true)) {
            // Bet lost — no payout
            $pdo->prepare(
                'UPDATE bets SET status=\'lost\', settled_at=? WHERE id=?'
            )->execute([$settledAt, $betId]);

        } elseif (in_array('void', $itemStatuses, true)) {
            // Void (all remaining items won or void, none lost) → refund total_amount
            $pdo->prepare(
                'UPDATE bets SET status=\'refunded\', settled_at=? WHERE id=?'
            )->execute([$settledAt, $betId]);

            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE id = ?'
            )->execute([$bet['total_amount'], $walletId]);

            $pdo->prepare(
                'INSERT INTO wallet_transactions (wallet_id, type, amount, description)
                 VALUES (?, \'bet_refund\', ?, ?)'
            )->execute([
                $walletId,
                $bet['total_amount'],
                'Возврат ставки #' . $betId,
            ]);

        } else {
            // All won → credit potential_win
            $pdo->prepare(
                'UPDATE bets SET status=\'won\', settled_at=? WHERE id=?'
            )->execute([$settledAt, $betId]);

            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE id = ?'
            )->execute([$bet['potential_win'], $walletId]);

            $pdo->prepare(
                'INSERT INTO wallet_transactions (wallet_id, type, amount, description)
                 VALUES (?, \'bet_win\', ?, ?)'
            )->execute([
                $walletId,
                $bet['potential_win'],
                'Выигрыш по ставке #' . $betId . ' — ' . number_format($bet['potential_win'], 2) . ' VCOIN',
            ]);
        }
    }

    // 4. Audit log
    $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details)
         VALUES (?, \'settle_event\', \'event\', ?, ?)'
    )->execute([
        $userId,
        $eventId,
        json_encode([
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'bets_settled' => count($betIds),
        ]),
    ]);

    $pdo->commit();

    flash('success', 'Событие завершено. Расчитано ставок: ' . count($betIds) . '.');
    header('Location: ' . BASE_URL . '/bookmaker/index.php');
    exit;

} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('settle_event error: ' . $e->getMessage());
    flash('error', 'Ошибка при расчёте: ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/bookmaker/settle.php?id=' . $eventId);
    exit;
}
