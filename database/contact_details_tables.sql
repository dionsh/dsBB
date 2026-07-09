-- ---------------------------------------------------------------------------
-- Table for the Contact Details screen (phone, home address, city, postal
-- code and country). The account email lives in `users.email` and is NOT
-- duplicated here.
--
-- NOTE: You do NOT have to run this manually. get_contact_details.php and
-- save_contact_details.php call ensureContactDetailsSchema()
-- (contact_details_db.php) which creates the table with CREATE TABLE IF NOT
-- EXISTS on first request. This file is provided for documentation / manual
-- setup only.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_contact_details` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,                 -- users.id (one row per user)
  `phone` VARCHAR(40) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,        -- home / street address
  `city` VARCHAR(120) DEFAULT NULL,
  `postal_code` VARCHAR(20) DEFAULT NULL,
  `country` VARCHAR(120) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_contact_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
