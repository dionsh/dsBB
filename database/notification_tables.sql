-- ---------------------------------------------------------------------------
-- Tables for the in-app Notifications feature.
--
-- NOTE: You do NOT have to run this manually. The notification endpoints call
-- ensureNotificationSchema() (see notifications_db.php) which creates these
-- tables with CREATE TABLE IF NOT EXISTS on first request. This file is
-- provided for documentation / manual setup only.
-- ---------------------------------------------------------------------------

-- One row per notification shown in the user's inbox.
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `type` VARCHAR(40) NOT NULL,            -- 'login' | 'received' | 'sent' | ...
  `title` VARCHAR(120) NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_notifications_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Per-user on/off switch for notifications (default ON when no row exists).
CREATE TABLE IF NOT EXISTS `notification_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_notif_settings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Notifications are created server-side by addNotification() in
-- notifications_db.php, called from login.php (type 'login') and transfer.php
-- (types 'received' and 'sent'). They are only inserted when the user has
-- notifications enabled.
