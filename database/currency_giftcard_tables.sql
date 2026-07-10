-- Currency conversion + Gift Cards tables.
--
-- NOTE: these tables are created AUTOMATICALLY at runtime by
-- ensureCurrencySchema() in currency_db.php and ensureGiftCardSchema() in
-- gift_cards_db.php (CREATE TABLE IF NOT EXISTS on every request), so this
-- file is documentation — no manual migration is required.

-- One row per balance conversion. The account balance itself stays in EUR;
-- the conversion changes the DISPLAY currency in the app, charges a real
-- 0.5% fee in EUR (see the "Currency Exchange Fee - X to Y" transaction) and
-- records the quote the user accepted.
CREATE TABLE IF NOT EXISTS currency_conversions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    from_code VARCHAR(8) NOT NULL,
    to_code VARCHAR(8) NOT NULL,
    rate DECIMAL(14,6) NOT NULL,            -- cross rate from -> to
    fee_percent DECIMAL(6,3) NOT NULL,      -- fee % at the time of conversion
    amount_eur DECIMAL(12,2) NOT NULL,      -- EUR balance before the fee
    amount_from DECIMAL(12,2) NOT NULL,     -- balance expressed in from_code
    fee_eur DECIMAL(12,2) NOT NULL,         -- fee charged to the EUR balance
    fee_from DECIMAL(12,2) NOT NULL,        -- the fee expressed in from_code
    amount_received DECIMAL(12,2) NOT NULL, -- what the user receives, in to_code
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_currency_conversions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row per purchased gift card. The brand catalog is static PHP
-- (giftCardCatalog() in gift_cards_db.php) — only purchases are stored.
CREATE TABLE IF NOT EXISTS gift_card_purchases (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    brand_key VARCHAR(40) NOT NULL,
    brand_name VARCHAR(80) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,          -- face value in EUR
    code VARCHAR(40) NOT NULL,              -- generated, realistic-looking code
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gift_card_purchases_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row per purchased "Personalize Card" design. The design catalog (with
-- prices) is static PHP (cardDesignCatalog() in card_designs_db.php). The
-- UNIQUE key makes a design a one-time purchase per user (never charged twice).
-- Auto-created at runtime by ensureCardDesignSchema() — documentation only.
CREATE TABLE IF NOT EXISTS card_design_purchases (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    design_id VARCHAR(40) NOT NULL,
    design_name VARCHAR(80) NOT NULL,
    price DECIMAL(10,2) NOT NULL,           -- charged price in EUR
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_design (user_id, design_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which design the user currently displays on their card (one row per user).
-- Set via set_primary_design.php (only the free classic or an owned design).
CREATE TABLE IF NOT EXISTS user_primary_design (
    user_id INT(11) NOT NULL PRIMARY KEY,
    design_id VARCHAR(40) NOT NULL DEFAULT 'classic',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
