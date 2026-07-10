<?php
/*
 * gift_cards_db.php
 *
 * Shared helpers for the Gift Cards feature (shown inside the Subscriptions
 * screen). Included by buy_gift_card.php and get_gift_cards.php.
 *
 * The brand catalog is a static PHP array (no seeding needed) — only the
 * purchases are stored. Buying deducts the price from the real balance via a
 * house transaction ("Gift Card - X") and stores a generated code the user can
 * copy. Codes are randomly generated but follow each brand's real-world format
 * so they look authentic; they are not real codes.
 *
 * Requires config.php (provides $conn) to have been included.
 */

/* Create the purchases table once (cheap + idempotent on every request). */
function ensureGiftCardSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS gift_card_purchases (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            brand_key VARCHAR(40) NOT NULL,
            brand_name VARCHAR(80) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            code VARCHAR(40) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_gift_card_purchases_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/*
 * The brand catalog. `format` is the shape of the generated code (every X is
 * replaced with a random character); each mirrors the brand's real code style.
 * Icons are MaterialCommunityIcons names verified against the app's icon set.
 */
function giftCardCatalog() {
    return [
        "netflix"     => ["key" => "netflix",     "name" => "Netflix",           "icon" => "netflix",                "color" => "#E50914", "format" => "XXXX-XXXX-XXXX-XXXX"],
        "playstation" => ["key" => "playstation", "name" => "PlayStation Store", "icon" => "sony-playstation",      "color" => "#0070D1", "format" => "XXXX-XXXX-XXXX"],
        "xbox"        => ["key" => "xbox",        "name" => "Xbox",              "icon" => "microsoft-xbox",        "color" => "#107C10", "format" => "XXXXX-XXXXX-XXXXX-XXXXX-XXXXX"],
        "steam"       => ["key" => "steam",       "name" => "Steam",             "icon" => "steam",                 "color" => "#2A475E", "format" => "XXXXX-XXXXX-XXXXX"],
        "vbucks"      => ["key" => "vbucks",      "name" => "Fortnite V-Bucks",  "icon" => "alpha-v-circle",        "color" => "#9D4DFF", "format" => "XXXX-XXXX-XXXX-XXXX"],
        "apple"       => ["key" => "apple",       "name" => "Apple",             "icon" => "apple",                 "color" => "#333333", "format" => "XXXXXXXXXXXXXXXX"],
        "googleplay"  => ["key" => "googleplay",  "name" => "Google Play",       "icon" => "google-play",           "color" => "#34A853", "format" => "XXXX-XXXX-XXXX-XXXX"],
        "spotify"     => ["key" => "spotify",     "name" => "Spotify",           "icon" => "spotify",               "color" => "#1DB954", "format" => "XXXX-XXXX-XXXX"],
        "amazon"      => ["key" => "amazon",      "name" => "Amazon",            "icon" => "package-variant-closed","color" => "#FF9900", "format" => "XXXX-XXXXXX-XXXX"],
        "roblox"      => ["key" => "roblox",      "name" => "Roblox",            "icon" => "cube-outline",          "color" => "#E2231A", "format" => "XXX-XXX-XXXX"],
    ];
}

/* Face values the user can pick from (EUR — the app converts for display). */
function giftCardDenominations() {
    return [10, 20, 50, 100];
}

/*
 * Generate a code from a brand format: every X becomes a random character.
 * Ambiguous characters (0/O, 1/I) are excluded so codes read cleanly.
 */
function generateGiftCardCode($format) {
    $chars = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $out = "";
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $c = $format[$i];
        $out .= ($c === "X") ? $chars[random_int(0, strlen($chars) - 1)] : $c;
    }
    return $out;
}
