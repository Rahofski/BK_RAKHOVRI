<?php
// ============================================================
// api/live_events.php  —  GET: live events with current score
// JSON output for AJAX polling (no auth required)
// ============================================================
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');

$rows = getPDO()->query(
    "SELECT e.id, e.home_score, e.away_score, e.status,
            ht.name AS home_team,
            at.name AS away_team
       FROM events e
       JOIN teams ht ON ht.id = e.home_team_id
       JOIN teams at ON at.id = e.away_team_id
      WHERE e.status = 'live'
      ORDER BY e.id"
)->fetchAll();

// Cast to ints to keep JSON clean
$result = array_map(fn($r) => [
    'id'         => (int) $r['id'],
    'home_score' => (int) $r['home_score'],
    'away_score' => (int) $r['away_score'],
    'home_team'  => $r['home_team'],
    'away_team'  => $r['away_team'],
], $rows);

echo json_encode($result);
