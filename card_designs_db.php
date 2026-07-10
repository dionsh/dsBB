<?php
/*
 * card_designs_db.php
 *
 * Shared helpers for the "Personalize Card" purchase flow. Included by
 * buy_card_design.php and get_card_designs.php.
 *
 * The design catalog is a static PHP array — the SOURCE OF TRUTH FOR PRICE, so
 * a client can never buy a paid design for less (the app sends only design_id).
 * It must stay in sync with the DESIGNS array in the app's PersonalizeCard.js.
 *
 * Buying a paid design deducts its price from the real balance via a house
 * transaction ("Card Design - <name>") and records ownership. Each design can
 * only be bought once per user (UNIQUE key), so re-selecting a design you
 * already own never charges again.
 *
 * Requires config.php (provides $conn) to have been included.
 */

/* Create the purchases table once (cheap + idempotent on every request). */
function ensureCardDesignSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS card_design_purchases (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            design_id VARCHAR(40) NOT NULL,
            design_name VARCHAR(80) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_design (user_id, design_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/*
 * The design catalog: id => [name, price in EUR]. "classic" (DS Classic) is
 * the free standard card. Prices mirror PersonalizeCard.js exactly.
 */
function cardDesignCatalog() {
    return [
        "classic"  => ["name" => "DS Classic",           "price" => 0.00],
        "worldcup" => ["name" => "FIFA World Cup 26",     "price" => 9.99],
        "fsk"      => ["name" => "FSK",                   "price" => 7.99],
        "whale"    => ["name" => "Blue Whale",            "price" => 4.99],
        "penguin"  => ["name" => "Arctic Penguin",        "price" => 4.99],
        "desert"   => ["name" => "Golden Dunes",          "price" => 7.99],
        "nature"   => ["name" => "Evergreen",             "price" => 4.99],
        "crimson"  => ["name" => "Crimson Pines",         "price" => 4.99],
        "midnight" => ["name" => "Midnight Pines",        "price" => 4.99],
        "frost"    => ["name" => "Frostpine",             "price" => 4.99],
        "hoop"     => ["name" => "Slam Dunk",             "price" => 7.99],
        "space"    => ["name" => "Space",                 "price" => 7.99],
        "luxury"   => ["name" => "Luxury Black & Gold",   "price" => 9.99],
        "minimal"  => ["name" => "Minimalist",            "price" => 2.99],
        "gradient" => ["name" => "Modern Gradient",       "price" => 2.99],
        "hyjnesha" => ["name" => "Hyjnesha në Fron",      "price" => 9.99],
    ];
}

/* Return the array of design ids the user already owns (has paid for). */
function getOwnedCardDesigns($conn, $user_id) {
    $stmt = $conn->prepare("SELECT design_id FROM card_design_purchases WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}
