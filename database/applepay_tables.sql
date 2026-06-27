-- ---------------------------------------------------------------------------
-- Table for the Apple Pay / Apple Wallet feature.
--
-- This table already exists in the main dsbanking schema. It is repeated here
-- for documentation, and applepay_db.php also recreates it idempotently with
-- CREATE TABLE IF NOT EXISTS so fresh installs work with no manual migration.
--
-- A card is considered "in Apple Wallet" when a row exists here for its card_id
-- (resolved cards.account_id -> accounts.user_id).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `apple_pay_devices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `card_id` INT(11) NOT NULL,
  `device_name` VARCHAR(100) DEFAULT NULL,
  `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `card_id` (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- In the main schema this table also has:
--   CONSTRAINT apple_pay_devices_ibfk_1 FOREIGN KEY (card_id)
--     REFERENCES cards (id) ON DELETE CASCADE
-- which removes wallet entries automatically if a card is deleted.
