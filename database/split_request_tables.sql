-- ---------------------------------------------------------------------------
-- Table for the Split The Bill request system (send a 50/50 split request to
-- a friend; money only moves when they accept).
--
-- NOTE: You do NOT have to run this manually. The split-request endpoints
-- call ensureSplitRequestsSchema() (split_requests_db.php) which creates the
-- table with CREATE TABLE IF NOT EXISTS on first request. This file is
-- provided for documentation / manual setup only.
--
-- The Financial Health Score feature (get_health_score.php) needs NO new
-- tables — it is computed from the existing transactions / savings /
-- subscriptions / cashback / rewards data.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `split_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `requester_id` INT(11) NOT NULL,             -- users.id of who asked to split
  `friend_id` INT(11) NOT NULL,                -- users.id of who must respond
  `total_amount` DECIMAL(12,2) NOT NULL,       -- the whole bill
  `share_amount` DECIMAL(12,2) NOT NULL,       -- total / 2, what the friend pays
  `note` VARCHAR(255) DEFAULT NULL,            -- optional ("Dinner at Te Marja")
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending' | 'accepted' | 'declined'
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` TIMESTAMP NULL DEFAULT NULL,
  KEY `idx_split_requester` (`requester_id`),
  KEY `idx_split_friend` (`friend_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
