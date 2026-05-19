-- ============================================================
-- BK_RakhovRI  —  Database Schema
-- Encoding: utf8mb4 / utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Roles
-- ----------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id`   INT          NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50)  NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Users
-- ----------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `role_id`       INT          NOT NULL,
  `email`         VARCHAR(255) NOT NULL,
  `username`      VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(255) DEFAULT NULL,
  `status`        ENUM('active','blocked','deleted') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email`    (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Wallets  (1:1 with users)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `wallets` (
  `id`         INT             NOT NULL AUTO_INCREMENT,
  `user_id`    INT             NOT NULL,
  `balance`    DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `currency`   VARCHAR(10)     NOT NULL DEFAULT 'VCOIN',
  `updated_at` DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallets_user` (`user_id`),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Wallet Transactions
-- ----------------------------
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id`          INT             NOT NULL AUTO_INCREMENT,
  `wallet_id`   INT             NOT NULL,
  `type`        ENUM('deposit','bet_hold','bet_win','bet_refund','admin_adjustment') NOT NULL,
  `amount`      DECIMAL(12,2)   NOT NULL,
  `description` VARCHAR(255)    DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_wt_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Sports
-- ----------------------------
CREATE TABLE IF NOT EXISTS `sports` (
  `id`   INT          NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sports_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Teams
-- ----------------------------
CREATE TABLE IF NOT EXISTS `teams` (
  `id`       INT          NOT NULL AUTO_INCREMENT,
  `sport_id` INT          NOT NULL,
  `name`     VARCHAR(150) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_teams_sport` FOREIGN KEY (`sport_id`) REFERENCES `sports` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Events
-- ----------------------------
CREATE TABLE IF NOT EXISTS `events` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `sport_id`     INT          NOT NULL,
  `home_team_id` INT          NOT NULL,
  `away_team_id` INT          NOT NULL,
  `title`        VARCHAR(255) NOT NULL,
  `start_time`   DATETIME     NOT NULL,
  `status`       ENUM('scheduled','live','finished','cancelled') NOT NULL DEFAULT 'scheduled',
  `home_score`   INT          DEFAULT NULL,
  `away_score`   INT          DEFAULT NULL,
  `created_by`   INT          NOT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_events_sport`     FOREIGN KEY (`sport_id`)     REFERENCES `sports` (`id`),
  CONSTRAINT `fk_events_home_team` FOREIGN KEY (`home_team_id`) REFERENCES `teams`  (`id`),
  CONSTRAINT `fk_events_away_team` FOREIGN KEY (`away_team_id`) REFERENCES `teams`  (`id`),
  CONSTRAINT `fk_events_creator`   FOREIGN KEY (`created_by`)   REFERENCES `users`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Markets  (reference list: 1X2, Total, BTTS …)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `markets` (
  `id`   INT          NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50)  NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_markets_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Event Markets  (M:N  events × markets)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `event_markets` (
  `id`        INT  NOT NULL AUTO_INCREMENT,
  `event_id`  INT  NOT NULL,
  `market_id` INT  NOT NULL,
  `status`    ENUM('open','suspended','closed','settled') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_em_event_market` (`event_id`, `market_id`),
  CONSTRAINT `fk_em_event`  FOREIGN KEY (`event_id`)  REFERENCES `events`  (`id`),
  CONSTRAINT `fk_em_market` FOREIGN KEY (`market_id`) REFERENCES `markets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Outcomes
-- ----------------------------
CREATE TABLE IF NOT EXISTS `outcomes` (
  `id`              INT           NOT NULL AUTO_INCREMENT,
  `event_market_id` INT           NOT NULL,
  `name`            VARCHAR(150)  NOT NULL,
  `code`            VARCHAR(50)   NOT NULL,
  `odds`            DECIMAL(6,2)  NOT NULL,
  `initial_odds`    DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  `status`          ENUM('active','suspended','won','lost','void') NOT NULL DEFAULT 'active',
  `updated_by`      INT           DEFAULT NULL,
  `updated_at`      DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_outcomes_em`      FOREIGN KEY (`event_market_id`) REFERENCES `event_markets` (`id`),
  CONSTRAINT `fk_outcomes_updater` FOREIGN KEY (`updated_by`)      REFERENCES `users`         (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Bets
-- ----------------------------
CREATE TABLE IF NOT EXISTS `bets` (
  `id`            INT           NOT NULL AUTO_INCREMENT,
  `user_id`       INT           NOT NULL,
  `total_amount`  DECIMAL(12,2) NOT NULL,
  `potential_win` DECIMAL(12,2) NOT NULL,
  `status`        ENUM('pending','won','lost','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settled_at`    DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_bets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Bet Items
-- ----------------------------
CREATE TABLE IF NOT EXISTS `bet_items` (
  `id`          INT           NOT NULL AUTO_INCREMENT,
  `bet_id`      INT           NOT NULL,
  `outcome_id`  INT           NOT NULL,
  `odds_at_bet` DECIMAL(6,2)  NOT NULL,
  `status`      ENUM('pending','won','lost','void') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_bi_bet`     FOREIGN KEY (`bet_id`)     REFERENCES `bets`     (`id`),
  CONSTRAINT `fk_bi_outcome` FOREIGN KEY (`outcome_id`) REFERENCES `outcomes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Audit Logs
-- ----------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `user_id`     INT          NOT NULL,
  `action`      VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(100) DEFAULT NULL,
  `entity_id`   INT          DEFAULT NULL,
  `details`     TEXT         DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
