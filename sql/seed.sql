-- ============================================================
-- BK_RakhovRI  —  Seed Data
-- Run AFTER schema.sql
-- Passwords are set by setup.php (not here)
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------
-- Roles
-- ----------------------------
INSERT IGNORE INTO `roles` (`code`, `name`) VALUES
  ('user',       'Пользователь'),
  ('bookmaker',  'Букмекер'),
  ('analyst',    'Аналитик'),
  ('admin',      'Администратор');

-- ----------------------------
-- Sports
-- ----------------------------
INSERT IGNORE INTO `sports` (`name`) VALUES
  ('Футбол'),
  ('Баскетбол'),
  ('Теннис'),
  ('Хоккей'),
  ('Волейбол');

-- ----------------------------
-- Teams (футбол)
-- ----------------------------
INSERT INTO `teams` (`sport_id`, `name`)
SELECT s.id, t.name
FROM `sports` s
JOIN (
  SELECT 'Реал Мадрид'    AS name, 'Футбол' AS sport UNION ALL
  SELECT 'Барселона',              'Футбол'           UNION ALL
  SELECT 'Манчестер Сити',         'Футбол'           UNION ALL
  SELECT 'Ливерпуль',              'Футбол'           UNION ALL
  SELECT 'Бавария',                'Футбол'           UNION ALL
  SELECT 'Ювентус',                'Футбол'           UNION ALL
  SELECT 'ПСЖ',                    'Футбол'           UNION ALL
  SELECT 'Челси',                  'Футбол'           UNION ALL
  -- Баскетбол
  SELECT 'Лейкерс',                'Баскетбол'        UNION ALL
  SELECT 'Голден Стэйт',           'Баскетбол'        UNION ALL
  SELECT 'Бостон Селтикс',         'Баскетбол'        UNION ALL
  SELECT 'Чикаго Буллз',           'Баскетбол'        UNION ALL
  -- Хоккей
  SELECT 'ЦСКА',                   'Хоккей'           UNION ALL
  SELECT 'СКА',                    'Хоккей'           UNION ALL
  SELECT 'Динамо Москва',          'Хоккей'           UNION ALL
  SELECT 'Металлург Мг',           'Хоккей'
) t ON s.name = t.sport
WHERE NOT EXISTS (
  SELECT 1 FROM `teams` ex WHERE ex.name = t.name AND ex.sport_id = s.id
);

-- ----------------------------
-- Markets
-- ----------------------------
INSERT IGNORE INTO `markets` (`code`, `name`) VALUES
  ('1x2',          'Исход матча (1X2)'),
  ('total_2_5',    'Тотал 2.5'),
  ('btts',         'Обе забьют'),
  ('handicap',     'Азиатский гандикап'),
  ('total_3_5',    'Тотал 3.5'),
  ('first_goal',   'Первый гол'),
  ('winner',       'Победитель');
