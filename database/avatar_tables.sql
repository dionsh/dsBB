-- ---------------------------------------------------------------------------
-- "My Character" avatar + points shop tables.
--
-- These are auto-provisioned by ensureAvatarSchema() in avatar_db.php
-- (CREATE TABLE IF NOT EXISTS), so this file is only documentation / a manual
-- migration path. The catalog is seeded by seedAvatarCatalog() in the same file.
--
-- Premium items are bought with the existing reward points balance
-- (rewards.points), so there is no separate currency and no real money.
-- ---------------------------------------------------------------------------

-- Shop catalog: one row per slot + style. Free rows match the look the app
-- already ships with; paid rows are the premium unlockables.
CREATE TABLE IF NOT EXISTS avatar_items (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slot VARCHAR(20) NOT NULL,            -- 'hair' | 'shirt' | 'pants' | 'shoes'
    style VARCHAR(30) NOT NULL,           -- must match the Avatar.js style id
    name VARCHAR(60) NOT NULL,            -- English display name (UI uses i18n)
    price_points INT(11) NOT NULL DEFAULT 0,
    is_free TINYINT(1) NOT NULL DEFAULT 0,
    preview_color VARCHAR(9) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_avatar_item (slot, style)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which premium items a user has unlocked (free items are implicitly owned and
-- are NOT stored here).
CREATE TABLE IF NOT EXISTS user_items (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    item_id INT(11) NOT NULL,
    acquired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_item (user_id, item_id),
    KEY idx_user_items_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The look a user currently has equipped (one row per user).
CREATE TABLE IF NOT EXISTS user_avatar (
    user_id INT(11) NOT NULL PRIMARY KEY,
    gender VARCHAR(10) NOT NULL DEFAULT 'male',
    skin VARCHAR(9) NOT NULL,
    hair_style VARCHAR(30) NOT NULL,
    hair_color VARCHAR(9) NOT NULL,
    shirt_style VARCHAR(30) NOT NULL,
    shirt_color VARCHAR(9) NOT NULL,
    pants_style VARCHAR(30) NOT NULL,
    pants_color VARCHAR(9) NOT NULL,
    shoe_style VARCHAR(30) NOT NULL,
    shoe_color VARCHAR(9) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
