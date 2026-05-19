<?php
// ============================================================
// api/export.php  —  Export data as CSV or TXT file
// Usage: ?type=users|bets|events  &format=csv|txt
// ============================================================
require_once __DIR__ . '/../bootstrap.php';
requireRole(['admin', 'analyst']);

$pdo    = getPDO();
$type   = $_GET['type']   ?? 'bets';
$format = $_GET['format'] ?? 'csv';

if (!in_array($type,   ['users', 'bets', 'events'], true)) { $type   = 'bets'; }
if (!in_array($format, ['csv', 'txt'],              true)) { $format = 'csv'; }

// ---- Build data ----
switch ($type) {
    case 'users':
        requireRole(['admin']);     // analysts cannot export users
        $rows = $pdo->query(
            "SELECT u.id, u.username, u.email, u.full_name, u.status,
                    r.name AS role, u.created_at,
                    COALESCE(w.balance,0) AS balance
               FROM users u
               JOIN roles r   ON r.id = u.role_id
               LEFT JOIN wallets w ON w.user_id = u.id
              WHERE u.status != 'deleted'
              ORDER BY u.id"
        )->fetchAll();
        $headers = ['ID', 'Логин', 'Email', 'Имя', 'Статус', 'Роль', 'Дата регистрации', 'Баланс'];
        $rowMapper = fn($r) => [
            $r['id'], $r['username'], $r['email'], $r['full_name'] ?? '',
            $r['status'], $r['role'],
            date('d.m.Y H:i', strtotime($r['created_at'])),
            number_format($r['balance'], 2, '.', ''),
        ];
        break;

    case 'events':
        $rows = $pdo->query(
            "SELECT e.id, e.title, s.name AS sport, ht.name AS home, at.name AS away,
                    e.status, e.start_time, e.home_score, e.away_score
               FROM events e
               JOIN sports s  ON s.id  = e.sport_id
               JOIN teams  ht ON ht.id = e.home_team_id
               JOIN teams  at ON at.id = e.away_team_id
              ORDER BY e.start_time DESC
              LIMIT 1000"
        )->fetchAll();
        $headers = ['ID', 'Заголовок', 'Спорт', 'Хозяева', 'Гости', 'Статус', 'Начало', 'Счёт'];
        $rowMapper = fn($r) => [
            $r['id'], $r['title'], $r['sport'], $r['home'], $r['away'],
            $r['status'], date('d.m.Y H:i', strtotime($r['start_time'])),
            $r['home_score'] . ':' . $r['away_score'],
        ];
        break;

    case 'bets':
    default:
        $where  = ['1=1'];
        $params = [];
        if (!empty($_GET['status'])) { $where[] = 'b.status=?'; $params[] = $_GET['status']; }
        if (!empty($_GET['user']))   {
            $where[] = 'u.username LIKE ?';
            $params[] = '%' . $_GET['user'] . '%';
        }
        if (!empty($_GET['date_from'])) { $where[] = 'DATE(b.created_at) >= ?'; $params[] = $_GET['date_from']; }
        if (!empty($_GET['date_to']))   { $where[] = 'DATE(b.created_at) <= ?'; $params[] = $_GET['date_to']; }
        $wc   = implode(' AND ', $where);
        $stmt = $pdo->prepare(
            "SELECT b.id, u.username, b.status, b.total_amount, b.potential_win, b.created_at
               FROM bets b
               JOIN users u ON u.id = b.user_id
              WHERE $wc
              ORDER BY b.created_at DESC
              LIMIT 5000"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $headers = ['ID', 'Пользователь', 'Статус', 'Сумма', 'Потенциал', 'Дата'];
        $rowMapper = fn($r) => [
            $r['id'], $r['username'], $r['status'],
            number_format($r['total_amount'],  2, '.', ''),
            number_format($r['potential_win'], 2, '.', ''),
            date('d.m.Y H:i', strtotime($r['created_at'])),
        ];
        break;
}

$dateStamp = date('Ymd_His');
$filename  = "export_{$type}_{$dateStamp}." . $format;

// ---- Write to exports/ directory ----
$exportsDir = __DIR__ . '/../exports';
if (!is_dir($exportsDir)) {
    mkdir($exportsDir, 0755, true);
}
$localPath = $exportsDir . '/' . $filename;

if ($format === 'csv') {
    $fh = fopen($localPath, 'w');
    fprintf($fh, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel
    fputcsv($fh, $headers, ';');
    foreach ($rows as $row) { fputcsv($fh, $rowMapper($row), ';'); }
    fclose($fh);
} else {
    // TXT — tab-separated
    $lines   = [];
    $lines[] = implode("\t", $headers);
    foreach ($rows as $row) { $lines[] = implode("\t", $rowMapper($row)); }
    file_put_contents($localPath, implode("\r\n", $lines));
}

// ---- Stream to browser ----
$contentType = ($format === 'csv') ? 'text/csv; charset=utf-8' : 'text/plain; charset=utf-8';
header('Content-Type: '        . $contentType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: '      . filesize($localPath));
header('Cache-Control: no-cache, must-revalidate');
readfile($localPath);
exit;
