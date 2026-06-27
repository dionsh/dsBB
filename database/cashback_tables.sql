-- ---------------------------------------------------------------------------
-- Tables for the Partner Cashback Marketplace feature.
--
-- NOTE: You do NOT have to run this manually. The cashback PHP endpoints call
-- ensureCashbackSchema() + seedPartners() (see cashback_db.php) which create
-- these tables with CREATE TABLE IF NOT EXISTS and seed the starter partner
-- offers on the first request. This file is provided for documentation /
-- manual setup only.
-- ---------------------------------------------------------------------------

-- Partner companies and their offers shown in the marketplace.
CREATE TABLE IF NOT EXISTS `partners` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `icon` VARCHAR(50) DEFAULT NULL,         -- MaterialCommunityIcons name
  `brand_color` VARCHAR(9) DEFAULT NULL,   -- hex color, e.g. #7C3AED
  `image_url` VARCHAR(255) DEFAULT NULL,   -- optional remote product image
  `price` DECIMAL(10,2) NOT NULL,
  `cashback_percent` INT(11) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Running cashback wallet per user (separate from the main account balance).
CREATE TABLE IF NOT EXISTS `cashback` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- redeemable cashback
  `total_earned` DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- lifetime earned
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_cashback_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row per partner purchase (powers the purchase history list).
CREATE TABLE IF NOT EXISTS `partner_purchases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `partner_id` INT(11) NOT NULL,
  `partner_name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `cashback_amount` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_partner_purchases_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The driving game reuses the existing rewards / reward_history tables (see
-- feature_tables.sql); its wins are stored with type = 'driving_win'.
