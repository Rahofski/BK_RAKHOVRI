<?php
// ============================================================
// includes/odds.php  —  Dynamic odds recalculation
// ============================================================
//
// Algorithm (weighted blend model):
//
//   1. Bookmaker sets initial_odds → they define the market's
//      implied probabilities AND the built-in margin (overround).
//
//   2. After each bet, we blend two probability signals per outcome:
//        a) initial_prob_i  = (1/initial_odds_i) / Σ(1/initial_odds_j)
//           — bookmaker's prior assessment, normalised to 1.0
//        b) bet_weight_i    = total_bets_on_i / total_bets_in_market
//           — where the money actually went
//
//   3. blended_i = (1 - SENSITIVITY) * initial_prob_i
//                 +      SENSITIVITY  * bet_weight_i
//
//   4. The original overround (Σ 1/initial_odds) is re-applied:
//        new_implied_i = blended_i * overround
//        new_odds_i    = 1 / new_implied_i
//
//   Result:
//   - When no bets placed → odds unchanged (= initial_odds)
//   - Heavy action on outcome X → odds on X drop, others rise
//   - The bookmaker's margin is preserved
//   - SENSITIVITY = 0.30 means bets contribute 30% of the signal
// ============================================================

const ODDS_SENSITIVITY = 0.30;  // 0.0 = fully static, 1.0 = fully pari-mutuel

/**
 * Recalculate and persist odds for all active outcomes in a market.
 *
 * @param PDO $pdo
 * @param int $eventMarketId
 */
function recalculateMarketOdds(PDO $pdo, int $eventMarketId): void
{
    // Load active outcomes that have initial_odds set
    $stmt = $pdo->prepare(
        "SELECT id, initial_odds
           FROM outcomes
          WHERE event_market_id = ?
            AND status = 'active'
            AND initial_odds > 0
          ORDER BY id"
    );
    $stmt->execute([$eventMarketId]);
    $outcomes = $stmt->fetchAll();

    if (count($outcomes) < 2) {
        return; // nothing to balance
    }

    // --- Step 1: overround from initial odds ---
    $overround = 0.0;
    foreach ($outcomes as $o) {
        $overround += 1.0 / (float)$o['initial_odds'];
    }
    if ($overround <= 0) return;

    // --- Step 2: total pending bets per outcome ---
    $ids          = array_column($outcomes, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $betStmt = $pdo->prepare(
        "SELECT bi.outcome_id, COALESCE(SUM(b.total_amount), 0) AS total_bet
           FROM bet_items bi
           JOIN bets b ON b.id = bi.bet_id
          WHERE bi.outcome_id IN ($placeholders)
            AND b.status = 'pending'
          GROUP BY bi.outcome_id"
    );
    $betStmt->execute($ids);

    $betsByOutcome = array_fill_keys($ids, 0.0);
    $totalBets     = 0.0;
    foreach ($betStmt->fetchAll() as $row) {
        $betsByOutcome[$row['outcome_id']] = (float)$row['total_bet'];
        $totalBets += (float)$row['total_bet'];
    }

    // --- Step 3: blend & update ---
    $updateStmt = $pdo->prepare('UPDATE outcomes SET odds = ? WHERE id = ?');

    foreach ($outcomes as $o) {
        $initialProb = (1.0 / (float)$o['initial_odds']) / $overround;

        if ($totalBets > 0.0) {
            $betWeight = $betsByOutcome[$o['id']] / $totalBets;
            $blended   = (1 - ODDS_SENSITIVITY) * $initialProb
                        +     ODDS_SENSITIVITY  * $betWeight;
        } else {
            $blended = $initialProb;
        }

        // Protect against division by zero (e.g. 0 bets on single outcome when totalBets>0)
        if ($blended <= 0) $blended = 0.001;

        // Re-apply overround → new market odds
        $newImplied = $blended * $overround;
        $newOdds    = max(1.01, round(1.0 / $newImplied, 2));

        $updateStmt->execute([$newOdds, $o['id']]);
    }
}
