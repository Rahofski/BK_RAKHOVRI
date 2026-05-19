-- ============================================================
-- views_procs.sql  —  Views, Functions, Stored Procedures
-- BK_RakhovRI
-- ============================================================

-- ============================================================
-- VIEWS
-- ============================================================

CREATE OR REPLACE VIEW v_events_full AS
SELECT
    e.id,
    e.title,
    e.start_time,
    e.status,
    e.home_score,
    e.away_score,
    e.created_at,
    s.name  AS sport_name,
    ht.name AS home_team,
    at.name AS away_team,
    u.username AS created_by
FROM events e
JOIN sports s  ON s.id  = e.sport_id
JOIN teams  ht ON ht.id = e.home_team_id
JOIN teams  at ON at.id = e.away_team_id
JOIN users  u  ON u.id  = e.created_by;


CREATE OR REPLACE VIEW v_bets_full AS
SELECT
    b.id,
    b.total_amount,
    b.potential_win,
    b.status,
    b.created_at,
    b.settled_at,
    u.username,
    u.email,
    u.full_name,
    COUNT(bi.id) AS items_count
FROM bets b
JOIN users     u  ON u.id     = b.user_id
JOIN bet_items bi ON bi.bet_id = b.id
GROUP BY
    b.id, b.total_amount, b.potential_win, b.status,
    b.created_at, b.settled_at,
    u.username, u.email, u.full_name;


CREATE OR REPLACE VIEW v_user_stats AS
SELECT
    u.id,
    u.username,
    u.email,
    u.full_name,
    u.status,
    u.created_at,
    r.name  AS role_name,
    r.code  AS role_code,
    COALESCE(w.balance, 0)          AS balance,
    COUNT(DISTINCT b.id)            AS total_bets,
    COALESCE(SUM(b.total_amount),0) AS total_wagered
FROM users u
JOIN  roles   r  ON r.id     = u.role_id
LEFT JOIN wallets w  ON w.user_id = u.id
LEFT JOIN bets   b  ON b.user_id = u.id
WHERE u.status != 'deleted'
GROUP BY
    u.id, u.username, u.email, u.full_name, u.status,
    u.created_at, r.name, r.code, w.balance;
