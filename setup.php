<?php
// ============================================================
// setup.php  —  ONE-TIME setup: create DB, tables, seed data
// ============================================================
// !!!  DELETE THIS FILE AFTER FIRST RUN  !!!
// ============================================================

require_once __DIR__ . '/config.php';

// ---- Simple passphrase to prevent accidental re-runs ----
$SETUP_KEY = 'setup2024bk'; // change before deploy
if (($_GET['key'] ?? '') !== $SETUP_KEY) {
    http_response_code(403);
    echo '<h2>Forbidden.</h2><p>Run: <code>setup.php?key=' . htmlspecialchars($SETUP_KEY, ENT_QUOTES) . '</code></p>';
    exit;
}

$log = [];

function log_step(string $msg, bool $ok = true): void
{
    global $log;
    $log[] = ['ok' => $ok, 'msg' => $msg];
}

// ---- 1. Connect WITHOUT selecting a database ----
try {
    $dsn = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    log_step('Подключение к MySQL: успешно');
} catch (\PDOException $e) {
    log_step('Подключение к MySQL: ОШИБКА — ' . $e->getMessage(), false);
    render($log);
    exit;
}

// ---- 2. Create database ----
try {
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    log_step('База данных <code>' . DB_NAME . '</code>: создана / уже существует');
} catch (\PDOException $e) {
    log_step('Создание БД: ОШИБКА — ' . $e->getMessage(), false);
    render($log);
    exit;
}

// ---- 3. Run schema.sql ----
$schemaFile = __DIR__ . '/sql/schema.sql';
if (!file_exists($schemaFile)) {
    log_step('sql/schema.sql не найден!', false);
    render($log);
    exit;
}

try {
    $pdo->exec(file_get_contents($schemaFile));
    log_step('Схема (schema.sql): выполнена');
} catch (\PDOException $e) {
    log_step('schema.sql: ОШИБКА — ' . $e->getMessage(), false);
    render($log);
    exit;
}

// ---- 3b. Migrations (idempotent ALTERs for existing DBs) ----
try {
    $cols = $pdo->query("SHOW COLUMNS FROM `outcomes` LIKE 'initial_odds'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `outcomes` ADD COLUMN `initial_odds` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `odds`");
        log_step('Миграция: добавлена колонка <code>outcomes.initial_odds</code>');
    } else {
        log_step('Миграция: <code>outcomes.initial_odds</code> уже существует');
    }
} catch (\PDOException $e) {
    log_step('Миграция initial_odds: ОШИБКА — ' . $e->getMessage(), false);
}

// ---- 3c. Views ----
$viewsFile = __DIR__ . '/sql/views_procs.sql';
if (file_exists($viewsFile)) {
    try {
        $sql = file_get_contents($viewsFile);
        // Split by semicolon+newline to run each statement separately
        $statements = preg_split('/;\s*\n/', $sql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
        log_step('Представления (views_procs.sql): выполнены');
    } catch (\PDOException $e) {
        log_step('views_procs.sql: ОШИБКА — ' . $e->getMessage(), false);
    }
}

// ---- 3d. Stored Procedures & Functions ----
$procs = [
    'fn_user_balance' => "CREATE FUNCTION fn_user_balance(p_user_id INT)
        RETURNS DECIMAL(12,2) READS SQL DATA DETERMINISTIC
        BEGIN
            DECLARE v_bal DECIMAL(12,2) DEFAULT 0.00;
            SELECT COALESCE(balance,0) INTO v_bal FROM wallets WHERE user_id=p_user_id LIMIT 1;
            RETURN v_bal;
        END",

    'fn_pending_bets' => "CREATE FUNCTION fn_pending_bets(p_user_id INT)
        RETURNS INT READS SQL DATA DETERMINISTIC
        BEGIN
            DECLARE v_cnt INT DEFAULT 0;
            SELECT COUNT(*) INTO v_cnt FROM bets WHERE user_id=p_user_id AND status='pending';
            RETURN v_cnt;
        END",

    'sp_deposit' => "CREATE PROCEDURE sp_deposit(IN p_user_id INT, IN p_amount DECIMAL(12,2))
        BEGIN
            DECLARE v_wid INT;
            SELECT id INTO v_wid FROM wallets WHERE user_id=p_user_id LIMIT 1;
            UPDATE wallets SET balance=balance+p_amount WHERE id=v_wid;
            INSERT INTO wallet_transactions(wallet_id,type,amount,description)
            VALUES(v_wid,'deposit',p_amount,CONCAT('Пополнение на ',p_amount,' VCOIN'));
        END",

    'sp_cascade_delete_sport' => "CREATE PROCEDURE sp_cascade_delete_sport(IN p_sport_id INT)
        BEGIN
            DELETE bi FROM bet_items bi
                INNER JOIN outcomes o      ON o.id  = bi.outcome_id
                INNER JOIN event_markets em ON em.id = o.event_market_id
                INNER JOIN events e        ON e.id  = em.event_id
            WHERE e.sport_id = p_sport_id;
            DELETE o FROM outcomes o
                INNER JOIN event_markets em ON em.id = o.event_market_id
                INNER JOIN events e        ON e.id  = em.event_id
            WHERE e.sport_id = p_sport_id;
            DELETE em FROM event_markets em
                INNER JOIN events e ON e.id = em.event_id
            WHERE e.sport_id = p_sport_id;
            DELETE FROM events WHERE sport_id = p_sport_id;
            DELETE FROM teams  WHERE sport_id = p_sport_id;
            DELETE FROM sports WHERE id = p_sport_id;
        END",

    'sp_cascade_delete_team' => "CREATE PROCEDURE sp_cascade_delete_team(IN p_team_id INT)
        BEGIN
            DELETE bi FROM bet_items bi
                INNER JOIN outcomes o      ON o.id  = bi.outcome_id
                INNER JOIN event_markets em ON em.id = o.event_market_id
                INNER JOIN events e        ON e.id  = em.event_id
            WHERE e.home_team_id=p_team_id OR e.away_team_id=p_team_id;
            DELETE o FROM outcomes o
                INNER JOIN event_markets em ON em.id = o.event_market_id
                INNER JOIN events e        ON e.id  = em.event_id
            WHERE e.home_team_id=p_team_id OR e.away_team_id=p_team_id;
            DELETE em FROM event_markets em
                INNER JOIN events e ON e.id = em.event_id
            WHERE e.home_team_id=p_team_id OR e.away_team_id=p_team_id;
            DELETE FROM events WHERE home_team_id=p_team_id OR away_team_id=p_team_id;
            DELETE FROM teams  WHERE id=p_team_id;
        END",
];

foreach ($procs as $name => $body) {
    try {
        $pdo->exec("DROP FUNCTION  IF EXISTS `$name`");
        $pdo->exec("DROP PROCEDURE IF EXISTS `$name`");
        $pdo->exec($body);
        log_step("Процедура/функция <code>$name</code>: создана");
    } catch (\PDOException $e) {
        log_step("$name: ОШИБКА — " . $e->getMessage(), false);
    }
}

// ---- 4. Run seed.sql ----
$seedFile = __DIR__ . '/sql/seed.sql';
if (!file_exists($seedFile)) {
    log_step('sql/seed.sql не найден!', false);
} else {
    try {
        $pdo->exec(file_get_contents($seedFile));
        log_step('Начальные данные (seed.sql): выполнена');
    } catch (\PDOException $e) {
        log_step('seed.sql: ОШИБКА — ' . $e->getMessage(), false);
    }
}

// ---- 5. Create system users ----
$systemUsers = [
    [
        'role'      => 'admin',
        'email'     => 'admin@bk.local',
        'username'  => 'admin',
        'full_name' => 'Администратор',
        'password'  => 'admin123',
    ],
    [
        'role'      => 'bookmaker',
        'email'     => 'bookmaker@bk.local',
        'username'  => 'bookmaker',
        'full_name' => 'Букмекер',
        'password'  => 'book123',
    ],
    [
        'role'      => 'analyst',
        'email'     => 'analyst@bk.local',
        'username'  => 'analyst',
        'full_name' => 'Аналитик',
        'password'  => 'analyst123',
    ],
    [
        'role'      => 'user',
        'email'     => 'user@bk.local',
        'username'  => 'testuser',
        'full_name' => 'Тестовый Пользователь',
        'password'  => 'user123',
    ],
];

foreach ($systemUsers as $u) {
    try {
        // Check if user already exists
        $s = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $s->execute([$u['email'], $u['username']]);
        if ($s->fetch()) {
            // User exists — force-update password to plain text
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
                ->execute([$u['password'], $u['email']]);
            log_step("Пользователь <code>{$u['username']}</code>: уже существует, пароль обновлён → plain text");
            continue;
        }

        // Get role_id
        $s = $pdo->prepare('SELECT id FROM roles WHERE code = ? LIMIT 1');
        $s->execute([$u['role']]);
        $roleId = $s->fetchColumn();

        if (!$roleId) {
            log_step("Роль <code>{$u['role']}</code> не найдена!", false);
            continue;
        }

        $hash = $u['password'];

        $pdo->beginTransaction();

        $s = $pdo->prepare(
            'INSERT INTO users (role_id, email, username, password_hash, full_name)
             VALUES (?, ?, ?, ?, ?)'
        );
        $s->execute([$roleId, $u['email'], $u['username'], $hash, $u['full_name']]);
        $userId = (int) $pdo->lastInsertId();

        $s = $pdo->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 1000.00)');
        $s->execute([$userId]);

        $pdo->commit();

        log_step("Пользователь <code>{$u['username']}</code> (пароль: <code>{$u['password']}</code>): создан, кошелёк 1000 VCOIN");

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_step("Создание пользователя <code>{$u['username']}</code>: ОШИБКА — " . $e->getMessage(), false);
    }
}

// ---- 6. Demo data ----
try {
    $forceDemo   = isset($_GET['reset_demo']);
    $demoExists  = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email='player1@demo.local'")->fetchColumn();

    if ($demoExists && !$forceDemo) {
        $evCnt  = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
        $betCnt = (int) $pdo->query('SELECT COUNT(*) FROM bets')->fetchColumn();
        log_step("Демо-данные: уже созданы (игроков: $demoExists, событий: $evCnt, ставок: $betCnt). "
            . 'Для сброса добавьте <code>?reset_demo=1</code> к URL setup.php');
    } else {
        if ($forceDemo) {
            // Wipe in FK-safe order
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            $pdo->exec("DELETE FROM bet_items");
            $pdo->exec("DELETE FROM bets");
            $pdo->exec("DELETE FROM wallet_transactions WHERE type IN ('bet_place','bet_win','deposit') AND wallet_id IN (SELECT w.id FROM wallets w JOIN users u ON u.id=w.user_id WHERE u.email LIKE 'player%@demo.local')");
            $pdo->exec("DELETE FROM outcomes");
            $pdo->exec("DELETE FROM event_markets");
            $pdo->exec("DELETE FROM events");
            $pdo->exec("DELETE FROM wallets WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'player%@demo.local')");
            $pdo->exec("DELETE FROM users WHERE email LIKE 'player%@demo.local'");
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            log_step('Демо-данные: предыдущие данные удалены (reset_demo)');
        }
        $bookmakerUid = (int) $pdo->query("SELECT id FROM users WHERE username='bookmaker' LIMIT 1")->fetchColumn();
        $userRoleId   = (int) $pdo->query("SELECT id FROM roles WHERE code='user' LIMIT 1")->fetchColumn();

        // Helper: get team id by name
        $teamId = function(string $name) use ($pdo): int {
            return (int) $pdo->prepare('SELECT id FROM teams WHERE name=? LIMIT 1')
                ->execute([$name]) ? $pdo->query("SELECT id FROM teams WHERE name='$name' LIMIT 1")->fetchColumn() : 0;
        };
        $getTeam = function(string $name) use ($pdo): int {
            $s = $pdo->prepare('SELECT id FROM teams WHERE name=? LIMIT 1');
            $s->execute([$name]);
            return (int) $s->fetchColumn();
        };
        $getSport = function(string $name) use ($pdo): int {
            $s = $pdo->prepare('SELECT id FROM sports WHERE name=? LIMIT 1');
            $s->execute([$name]);
            return (int) $s->fetchColumn();
        };
        $getMarket = function(string $code) use ($pdo): int {
            $s = $pdo->prepare('SELECT id FROM markets WHERE code=? LIMIT 1');
            $s->execute([$code]);
            return (int) $s->fetchColumn();
        };

        // ---- Demo players ----
        $demoPlayers = [
            ['player1', 'player1@demo.local', 'Алексей Игроков',  'pass1', 7500.00],
            ['player2', 'player2@demo.local', 'Мария Ставочникова','pass2', 4200.00],
            ['player3', 'player3@demo.local', 'Дмитрий Беттеров', 'pass3', 11000.00],
            ['player4', 'player4@demo.local', 'Ирина Удачная',    'pass4', 3300.00],
            ['player5', 'player5@demo.local', 'Сергей Профит',    'pass5', 9800.00],
        ];
        $playerIds = [];
        $insUser = $pdo->prepare('INSERT IGNORE INTO users (role_id,email,username,password_hash,full_name) VALUES (?,?,?,?,?)');
        $insWallet = $pdo->prepare('INSERT IGNORE INTO wallets (user_id,balance) VALUES (?,?)');
        foreach ($demoPlayers as [$uname, $email, $fname, $pass, $bal]) {
            $insUser->execute([$userRoleId, $email, $uname, $pass, $fname]);
            $uid = (int) $pdo->query("SELECT id FROM users WHERE email='$email' LIMIT 1")->fetchColumn();
            $insWallet->execute([$uid, $bal]);
            $playerIds[$uname] = $uid;
        }
        log_step('Демо-данные: создано игроков — ' . count($playerIds));

        // ---- Events definition ----
        // [sport, home, away, title, start_offset_hours, status, home_score, away_score]
        $eventsDef = [
            ['Футбол',    'Реал Мадрид',    'Барселона',      'Реал Мадрид — Барселона',       -72,  'finished', 3, 1],
            ['Футбол',    'Манчестер Сити', 'Ливерпуль',      'Манчестер Сити — Ливерпуль',    -48,  'finished', 1, 2],
            ['Футбол',    'Бавария',        'ПСЖ',            'Бавария — ПСЖ',                 -24,  'finished', 2, 2],
            ['Футбол',    'Ювентус',        'Челси',          'Ювентус — Челси',               -1,   'live',     1, 0],
            ['Футбол',    'Барселона',      'Манчестер Сити', 'Барселона — Манчестер Сити',    +24,  'scheduled',null, null],
            ['Футбол',    'Ливерпуль',      'Бавария',        'Ливерпуль — Бавария',           +48,  'scheduled',null, null],
            ['Баскетбол', 'Лейкерс',        'Голден Стэйт',  'Лейкерс — Голден Стэйт',       -36,  'finished', 108, 102],
            ['Баскетбол', 'Бостон Селтикс', 'Чикаго Буллз',  'Бостон — Чикаго',              -12,  'finished',  98,  87],
            ['Баскетбол', 'Голден Стэйт',   'Бостон Селтикс','Голден Стэйт — Бостон',         +36,  'scheduled',null, null],
            ['Хоккей',    'ЦСКА',           'СКА',            'ЦСКА — СКА',                    -30,  'finished',  4,  2],
            ['Хоккей',    'Динамо Москва',  'Металлург Мг',  'Динамо — Металлург',            -6,   'live',     2, 1],
            ['Хоккей',    'СКА',            'Динамо Москва', 'СКА — Динамо',                  +60,  'scheduled',null, null],
        ];

        // Outcomes for each market type
        $outcomesByMarket = [
            '1x2'       => [['Победа хозяев','home',2.10],['Ничья','draw',3.20],['Победа гостей','away',3.50]],
            'total_2_5' => [['Больше 2.5','over',1.85],['Меньше 2.5','under',1.95]],
            'btts'      => [['Да','yes',1.80],['Нет','no',2.00]],
            'winner'    => [['Победа хозяев','home',1.75],['Победа гостей','away',2.10]],
        ];

        $insEvent = $pdo->prepare(
            'INSERT INTO events (sport_id,home_team_id,away_team_id,title,start_time,status,home_score,away_score,created_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $insEM = $pdo->prepare('INSERT INTO event_markets (event_id,market_id,status) VALUES (?,?,?)');
        $insOut = $pdo->prepare('INSERT INTO outcomes (event_market_id,name,code,odds,initial_odds) VALUES (?,?,?,?,?)');

        $eventIds = [];
        $allOutcomes = [];  // [event_idx => [market_code => [outcome_id, ...]]]

        foreach ($eventsDef as $idx => $ev) {
            [$sport, $home, $away, $title, $offsetH, $status, $hs, $as_] = $ev;
            $startTime = date('Y-m-d H:i:s', strtotime("+{$offsetH} hours"));
            $emStatus  = ($status === 'finished') ? 'settled' : ($status === 'live' ? 'open' : 'open');

            $insEvent->execute([
                $getSport($sport), $getTeam($home), $getTeam($away),
                $title, $startTime, $status, $hs, $as_, $bookmakerUid
            ]);
            $eventId = (int) $pdo->lastInsertId();
            $eventIds[$idx] = $eventId;
            $allOutcomes[$idx] = [];

            // Decide which markets to add
            $marketsForEvent = ($sport === 'Баскетбол' || $sport === 'Хоккей')
                ? ['1x2', 'total_2_5']
                : ['1x2', 'total_2_5', 'btts'];

            foreach ($marketsForEvent as $mCode) {
                $mid = $getMarket($mCode);
                if (!$mid) continue;
                $insEM->execute([$eventId, $mid, $emStatus]);
                $emId = (int) $pdo->lastInsertId();

                $allOutcomes[$idx][$mCode] = [];
                foreach ($outcomesByMarket[$mCode] as [$oName, $oCode, $oOdds]) {
                    // Slightly randomise odds
                    $odds = round($oOdds + (mt_rand(-15, 15) / 100), 2);
                    $odds = max(1.10, $odds);
                    $insOut->execute([$emId, $oName, $oCode, $odds, $oOdds]);
                    $allOutcomes[$idx][$mCode][] = (int) $pdo->lastInsertId();
                }
            }
        }
        log_step('Демо-данные: создано событий — ' . count($eventIds));

        // ---- Bets ----
        // Finished events: bets get won/lost based on correct/wrong outcome
        // Live/scheduled: bets are pending

        $insBet = $pdo->prepare(
            'INSERT INTO bets (user_id,total_amount,potential_win,status,created_at,settled_at)
             VALUES (?,?,?,?,?,?)'
        );
        $insBetItem = $pdo->prepare(
            'INSERT INTO bet_items (bet_id,outcome_id,odds_at_bet) VALUES (?,?,?)'
        );
        $insWTx = $pdo->prepare(
            'INSERT INTO wallet_transactions (wallet_id,type,amount,description) VALUES (?,?,?,?)'
        );

        $getWallet = function(int $uid) use ($pdo): int {
            return (int) $pdo->prepare('SELECT id FROM wallets WHERE user_id=? LIMIT 1')
                ->execute([$uid]) ? $pdo->query("SELECT id FROM wallets WHERE user_id=$uid LIMIT 1")->fetchColumn() : 0;
        };
        $getWid = function(int $uid) use ($pdo): int {
            $s = $pdo->prepare('SELECT id FROM wallets WHERE user_id=? LIMIT 1');
            $s->execute([$uid]);
            return (int) $s->fetchColumn();
        };

        // Define bets: [player, event_idx, market_code, outcome_idx(0/1/2), amount]
        // outcome_idx=0 means first outcome (home win / over / yes), 1=draw/under/no, 2=away win
        $betsDef = [
            // --- Finished events (outcomes determined) ---
            // Event 0: Real Madrid 3-1 Barca → home won, draw/away lost
            ['player1', 0, '1x2', 0, 500],   // home win → WON (Real scored 3)
            ['player2', 0, '1x2', 2, 300],   // away win → LOST
            ['player3', 0, 'total_2_5', 0, 400], // over 2.5 → WON (4 goals)
            ['player4', 0, 'btts', 0, 200],  // btts yes → WON (both scored)
            ['player5', 0, '1x2', 1, 150],   // draw → LOST

            // Event 1: Man City 1-2 Liverpool → away won
            ['player1', 1, '1x2', 2, 600],   // away win → WON
            ['player2', 1, '1x2', 0, 350],   // home win → LOST
            ['player3', 1, 'total_2_5', 0, 250], // over → WON (3 goals)
            ['player5', 1, 'btts', 0, 300],  // btts yes → WON

            // Event 2: Bayern 2-2 PSG → draw
            ['player1', 2, '1x2', 1, 700],   // draw → WON
            ['player2', 2, '1x2', 0, 400],   // home win → LOST
            ['player4', 2, 'total_2_5', 0, 350], // over → WON (4 goals)
            ['player3', 2, 'btts', 1, 200],  // btts no → LOST (both scored)

            // Event 6: Lakers 108-102 → home won
            ['player1', 6, '1x2', 0, 450],   // home win → WON
            ['player2', 6, 'total_2_5', 0, 300], // over → WON (210 total)
            ['player5', 6, '1x2', 2, 500],   // away win → LOST

            // Event 7: Boston 98-87 → home won
            ['player3', 7, '1x2', 0, 600],   // home win → WON
            ['player4', 7, '1x2', 2, 200],   // away win → LOST

            // Event 9: CSKA 4-2 → home won
            ['player2', 9, '1x2', 0, 550],   // home win → WON
            ['player5', 9, 'total_2_5', 0, 400], // over → WON (6 goals)
            ['player1', 9, '1x2', 2, 250],   // away win → LOST

            // --- Live events (pending) ---
            ['player2', 3, '1x2', 0, 300],   // Juventus home
            ['player3', 3, '1x2', 2, 200],   // Chelsea away
            ['player4', 3, 'btts', 0, 150],
            ['player1', 10,'1x2', 0, 400],   // Dynamo home (hockey live)
            ['player5', 10,'total_2_5', 0, 350],

            // --- Scheduled events (pending) ---
            ['player1', 4,  '1x2', 0, 500],
            ['player2', 4,  '1x2', 2, 300],
            ['player3', 5,  'total_2_5', 1, 250],
            ['player4', 8,  '1x2', 0, 200],
            ['player5', 11, '1x2', 0, 350],
        ];

        // Map: which outcome_idx is "correct winner" for finished events
        // event_idx => correct outcome code for 1x2
        $correctOutcome = [
            0  => 'home',  // Real 3-1
            1  => 'away',  // Liverpool
            2  => 'draw',  // 2-2
            6  => 'home',  // Lakers
            7  => 'home',  // Boston
            9  => 'home',  // CSKA
        ];
        // For total_2_5: over wins if score > 2.5; btts yes wins if both scored
        $eventScore = [
            0 => [3,1], 1 => [1,2], 2 => [2,2],
            6 => [108,102], 7 => [98,87], 9 => [4,2],
        ];

        $betCount = 0;
        foreach ($betsDef as [$pname, $eidx, $mCode, $oidx, $amount]) {
            if (!isset($allOutcomes[$eidx][$mCode][$oidx])) continue;
            $uid      = $playerIds[$pname];
            $wid      = $getWid($uid);
            $outcomeId = $allOutcomes[$eidx][$mCode][$oidx];

            // Get odds
            $oStmt = $pdo->prepare('SELECT odds FROM outcomes WHERE id=? LIMIT 1');
            $oStmt->execute([$outcomeId]);
            $odds = (float) $oStmt->fetchColumn();
            if (!$odds) $odds = 2.00;
            $potWin = round($amount * $odds, 2);

            // Determine status for finished events
            $evStatus = $eventsDef[$eidx][5];
            $betStatus = 'pending';
            $settledAt = null;

            if ($evStatus === 'finished') {
                $oCode = '';
                $cOut2 = $pdo->prepare('SELECT code FROM outcomes WHERE id=? LIMIT 1');
                $cOut2->execute([$outcomeId]);
                $oCode = $cOut2->fetchColumn();

                if ($mCode === '1x2') {
                    $betStatus = ($oCode === ($correctOutcome[$eidx] ?? '')) ? 'won' : 'lost';
                } elseif ($mCode === 'total_2_5') {
                    [$hs2, $as2] = $eventScore[$eidx];
                    $total = $hs2 + $as2;
                    $betStatus = (($oCode === 'over' && $total > 2.5) || ($oCode === 'under' && $total <= 2.5)) ? 'won' : 'lost';
                } elseif ($mCode === 'btts') {
                    [$hs2, $as2] = $eventScore[$eidx];
                    $bothScored = ($hs2 > 0 && $as2 > 0);
                    $betStatus = (($oCode === 'yes' && $bothScored) || ($oCode === 'no' && !$bothScored)) ? 'won' : 'lost';
                }
                $settledAt = date('Y-m-d H:i:s', strtotime($eventsDef[$eidx][4] . ' hours') + mt_rand(3600, 7200));
            }

            $createdAt = date('Y-m-d H:i:s', strtotime($eventsDef[$eidx][4] . ' hours') - mt_rand(7200, 86400));

            $insBet->execute([$uid, $amount, $potWin, $betStatus, $createdAt, $settledAt]);
            $betId = (int) $pdo->lastInsertId();
            $insBetItem->execute([$betId, $outcomeId, $odds]);

            // Wallet transactions
            $insWTx->execute([$wid, 'bet_place', $amount, "Ставка #$betId"]);
            if ($betStatus === 'won') {
                $insWTx->execute([$wid, 'bet_win', $potWin, "Выигрыш ставки #$betId"]);
            }
            $betCount++;
        }
        log_step("Демо-данные: создано ставок — $betCount");
        log_step('Демо-данные: завершено ✔');
    }
} catch (\Throwable $e) {
    log_step('Демо-данные: ОШИБКА — ' . $e->getMessage(), false);
}

log_step('✔ Настройка завершена. <strong>Удалите этот файл (setup.php) с сервера!</strong>');

render($log);

// ---- Render ----
function render(array $log): void
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Setup — BK RakhovRI</title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; background: #0d0f1a; color: #e8eaf6; padding: 2rem; }
    h1   { color: #00c896; margin-bottom: 1.5rem; }
    .step { padding: .6rem 1rem; margin-bottom: .4rem; border-radius: 6px;
            background: #1b1f33; border-left: 4px solid; }
    .ok   { border-color: #3dd68c; }
    .err  { border-color: #e05260; color: #e05260; }
    code  { background: #252a42; padding: .1em .35em; border-radius: 3px; font-size: .9em; }
    a     { color: #00c896; }
    .actions { margin-top: 1.5rem; }
    .btn  { display: inline-block; padding: .6rem 1.2rem; background: #00c896;
            color: #fff; border-radius: 6px; text-decoration: none; font-weight: 600; }
  </style>
</head>
<body>
  <h1>⚙ Setup — BK RakhovRI</h1>

  <?php foreach ($log as $item): ?>
    <div class="step <?= $item['ok'] ? 'ok' : 'err' ?>">
      <?= $item['msg'] ?>
    </div>
  <?php endforeach; ?>

  <?php
  $allOk = array_reduce($log, fn($carry, $i) => $carry && $i['ok'], true);
  if ($allOk):
  ?>
  <div class="actions">
    <a class="btn" href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/login.php">Перейти ко входу →</a>
  </div>
  <p style="margin-top:1rem;color:#f0a500;">
    ⚠ Не забудьте удалить <code>setup.php</code> перед выкладкой на хостинг!
  </p>
  <?php endif; ?>
</body>
</html>
<?php
}
