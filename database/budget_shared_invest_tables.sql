-- ---------------------------------------------------------------------------
-- Tables for the new features: Budget Planner, Shared Savings Groups,
-- NOVA card controls and the live Bitcoin price cache.
--
-- NOTE: You do NOT have to run this manually. The new PHP endpoints call
-- ensureBudgetsSchema() / ensureSharedGoalsSchema() / ensureCardControlsSchema()
-- / ensureInvestPriceCache() which create these tables with
-- CREATE TABLE IF NOT EXISTS on first request. This file is provided for
-- documentation / manual setup only.
-- ---------------------------------------------------------------------------

-- Budget Planner: one monthly spending limit per user + month + category.
-- Categories match the names produced by analyticsCategory() so real spending
-- maps onto them 1:1 (see budgets_db.php).
CREATE TABLE IF NOT EXISTS `budgets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `month` CHAR(7) NOT NULL,                 -- 'YYYY-MM'
  `category` VARCHAR(40) NOT NULL,          -- analytics category key
  `limit_amount` DECIMAL(12,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_budget` (`user_id`, `month`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Shared Savings Groups: the group itself.
CREATE TABLE IF NOT EXISTS `shared_goals` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `creator_id` INT(11) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `icon` VARCHAR(40) NOT NULL DEFAULT 'account-group',
  `target_amount` DECIMAL(12,2) NOT NULL,
  `current_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',   -- 'active' | 'completed'
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  KEY `idx_shared_goals_creator` (`creator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Shared Savings Groups: memberships and invitations.
CREATE TABLE IF NOT EXISTS `shared_goal_members` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `goal_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `role` VARCHAR(10) NOT NULL DEFAULT 'member',     -- 'owner' | 'member'
  `status` VARCHAR(20) NOT NULL DEFAULT 'invited',  -- 'invited' | 'active' | 'declined'
  `invited_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uniq_shared_member` (`goal_id`, `user_id`),
  KEY `idx_shared_members_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Shared Savings Groups: every deposit (powers the contribution history).
CREATE TABLE IF NOT EXISTS `shared_goal_contributions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `goal_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_shared_contrib_goal` (`goal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- NOVA card controls: online payments lock + contactless toggle per user
-- (the freeze flag stays in the existing card_status table).
CREATE TABLE IF NOT EXISTS `card_controls` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `online_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `contactless_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_card_controls_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Invest Simulator: cache for the live Bitcoin price (CoinGecko), refreshed
-- at most every 2 minutes so the free API rate limits are never an issue.
CREATE TABLE IF NOT EXISTS `invest_price_cache` (
  `asset` VARCHAR(20) NOT NULL PRIMARY KEY,
  `price` DECIMAL(14,2) NOT NULL,
  `change_24h_pct` DECIMAL(8,2) DEFAULT NULL,
  `fetched_at` INT(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Shared Savings Groups: the group chat (added later). Active members can
-- read/write; auto-created by ensureSharedGoalsSchema() like the rest.
CREATE TABLE IF NOT EXISTS `shared_goal_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `goal_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `message` VARCHAR(500) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_shared_msg_goal` (`goal_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
