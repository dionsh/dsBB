-- ---------------------------------------------------------------------------
-- Tables for the new features: Split The Bill, Round It Up, Wordle Rewards.
--
-- NOTE: You do NOT have to run this manually. The new PHP endpoints call
-- ensureFeatureSchema() (see feature_db.php) which creates these tables with
-- CREATE TABLE IF NOT EXISTS on first request. This file is provided for
-- documentation / manual setup only.
-- ---------------------------------------------------------------------------

-- Round It Up: running savings balance per user.
CREATE TABLE IF NOT EXISTS `savings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_savings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Round It Up: one row per round-up deposit (powers the Savings screen list).
CREATE TABLE IF NOT EXISTS `savings_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `purchase_amount` DECIMAL(12,2) NOT NULL,
  `saved_amount` DECIMAL(12,2) NOT NULL,
  `label` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_savings_history_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Wordle Rewards: running reward points per user.
CREATE TABLE IF NOT EXISTS `rewards` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `points` INT(11) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_rewards_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Wordle Rewards: history of wins and redemptions (powers the Rewards screen list).
CREATE TABLE IF NOT EXISTS `reward_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `type` VARCHAR(30) NOT NULL,            -- 'wordle_win' | 'redeem'
  `points` INT(11) NOT NULL DEFAULT 0,    -- + earned, - redeemed
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- EUR value when redeeming
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_reward_history_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The hidden "DS Banking House" counterparty account is also created
-- automatically by getHouseAccountId() in feature_db.php; no manual insert needed.
