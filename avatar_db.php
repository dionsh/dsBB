<?php
/*
 * avatar_db.php
 *
 * Shared helpers for the "My Character" avatar + points shop feature.
 * Included by the avatar endpoints (get_avatar, buy_item, equip_avatar). It:
 *   1. Idempotently creates the avatar tables so no manual migration is needed:
 *        - avatar_items : the shop catalog (one row per slot+style).
 *        - user_items   : which premium items a user has unlocked.
 *        - user_avatar  : the look a user currently has equipped.
 *   2. Seeds the catalog (free defaults + premium items) on first run.
 *   3. Exposes ownership / equipped-look helpers used by the endpoints.
 *
 * Premium items are paid for with the EXISTING reward points balance
 * (rewards.points — the same one Wordle/Driving award and redeem_points.php
 * spends), so there is no separate currency and no real-money payment.
 *
 * Requires config.php (provides $conn) and feature_db.php (provides the
 * rewards helpers + POINTS_PER_EUR) to have been included first.
 */

/* ---- The catalog -------------------------------------------------------- *
 * Items are SHAPES (styles) per slot. Colours stay free customisation, so an
 * item is identified by (slot, style). The free items match the look the app
 * already shipped with, so a brand-new character looks complete; the premium
 * items are the ones worth unlocking. Style ids MUST match the ids rendered by
 * the frontend Avatar.js component.
 * Fields: [slot, style, display name, price_points, is_free, preview_color]
 */
function avatarCatalogSeed() {
    return [
        // hair --------------------------------------------------------------
        ["hair",  "short",    "Short Hair",   0,   1, "#2A2A2A"],
        ["hair",  "curly",    "Curly Hair",   0,   1, "#2A2A2A"],
        ["hair",  "long",     "Long Hair",    0,   1, "#5A3A22"],
        ["hair",  "buzz",     "Buzz Cut",     150, 0, "#2A2A2A"],
        ["hair",  "spiky",    "Spiky Hair",   250, 0, "#111827"],
        ["hair",  "bun",      "Top Bun",      300, 0, "#2A2A2A"],

        // shirt -------------------------------------------------------------
        ["shirt", "tshirt",   "T-shirt",      0,   1, "#4F46E5"],
        ["shirt", "hoodie",   "Hoodie",       0,   1, "#4F46E5"],
        ["shirt", "polo",     "Polo",         0,   1, "#10B981"],
        ["shirt", "tank",     "Tank Top",     200, 0, "#0EA5E9"],
        ["shirt", "suit",     "Business Suit",600, 0, "#1F2937"],

        // pants -------------------------------------------------------------
        ["pants", "jeans",    "Jeans",        0,   1, "#2C3E50"],
        ["pants", "shorts",   "Shorts",       0,   1, "#1F3A5F"],
        ["pants", "cargo",    "Cargo Pants",  250, 0, "#6B4F2A"],
        ["pants", "joggers",  "Joggers",      300, 0, "#374151"],

        // shoes -------------------------------------------------------------
        ["shoes", "sneakers", "Sneakers",     0,   1, "#FFFFFF"],
        ["shoes", "boots",    "Boots",        0,   1, "#111827"],
        ["shoes", "sandals",  "Sandals",      150, 0, "#6B4F2A"],
        ["shoes", "hightops", "High-tops",    350, 0, "#E53935"],
    ];
}

/* The default look a new character starts with (all free items). */
function avatarDefaultLook() {
    return [
        "skin"        => "#E0AC69",
        "hair_style"  => "short",   "hair_color"  => "#2A2A2A",
        "shirt_style" => "tshirt",  "shirt_color" => "#4F46E5",
        "pants_style" => "jeans",   "pants_color" => "#2C3E50",
        "shoe_style"  => "sneakers","shoe_color"  => "#FFFFFF",
    ];
}

/* Create the avatar tables once (cheap + idempotent on every request). */
function ensureAvatarSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS avatar_items (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slot VARCHAR(20) NOT NULL,
            style VARCHAR(30) NOT NULL,
            name VARCHAR(60) NOT NULL,
            price_points INT(11) NOT NULL DEFAULT 0,
            is_free TINYINT(1) NOT NULL DEFAULT 0,
            preview_color VARCHAR(9) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_avatar_item (slot, style)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS user_items (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            item_id INT(11) NOT NULL,
            acquired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_item (user_id, item_id),
            KEY idx_user_items_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS user_avatar (
            user_id INT(11) NOT NULL PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    seedAvatarCatalog($conn);
}

/* Insert any missing catalog rows. Idempotent thanks to the unique key. */
function seedAvatarCatalog($conn) {
    $stmt = $conn->prepare("
        INSERT INTO avatar_items (slot, style, name, price_points, is_free, preview_color)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            price_points = VALUES(price_points),
            is_free = VALUES(is_free),
            preview_color = VALUES(preview_color)
    ");
    foreach (avatarCatalogSeed() as $row) {
        $stmt->execute($row);
    }
}

/*
 * Return the full catalog with an `owned` flag for the given user. An item is
 * owned when it is free OR the user has unlocked it (a row in user_items).
 */
function getAvatarCatalog($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT
            i.id,
            i.slot,
            i.style,
            i.name,
            i.price_points,
            i.is_free,
            i.preview_color,
            (i.is_free = 1 OR ui.id IS NOT NULL) AS owned
        FROM avatar_items i
        LEFT JOIN user_items ui
            ON ui.item_id = i.id AND ui.user_id = ?
        ORDER BY FIELD(i.slot, 'hair', 'shirt', 'pants', 'shoes'),
                 i.is_free DESC, i.price_points ASC, i.id ASC
    ");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalise types so the JSON has real booleans/ints.
    return array_map(function ($r) {
        return [
            "id"            => (int) $r["id"],
            "slot"          => $r["slot"],
            "style"         => $r["style"],
            "name"          => $r["name"],
            "price_points"  => (int) $r["price_points"],
            "is_free"       => (bool) $r["is_free"],
            "preview_color" => $r["preview_color"],
            "owned"         => (bool) $r["owned"],
        ];
    }, $rows);
}

/* Look up a single catalog item by id. Returns the row or null. */
function getAvatarItem($conn, $item_id) {
    $stmt = $conn->prepare("SELECT * FROM avatar_items WHERE id = ? LIMIT 1");
    $stmt->execute([$item_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* True when the user already owns (free or unlocked) the given catalog item. */
function userOwnsItem($conn, $user_id, $item_id) {
    $stmt = $conn->prepare("
        SELECT (i.is_free = 1 OR ui.id IS NOT NULL) AS owned
        FROM avatar_items i
        LEFT JOIN user_items ui
            ON ui.item_id = i.id AND ui.user_id = ?
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $item_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (bool) $row["owned"] : false;
}

/*
 * True when the user is allowed to equip the given (slot, style) — i.e. the
 * style exists in the catalog and is free or unlocked. Used to validate equips
 * server-side so the client can never equip something it didn't pay for.
 */
function userOwnsStyle($conn, $user_id, $slot, $style) {
    $stmt = $conn->prepare("
        SELECT (i.is_free = 1 OR ui.id IS NOT NULL) AS owned
        FROM avatar_items i
        LEFT JOIN user_items ui
            ON ui.item_id = i.id AND ui.user_id = ?
        WHERE i.slot = ? AND i.style = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $slot, $style]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (bool) $row["owned"] : false;
}

/*
 * Return the user's equipped look, creating a default row on first access so
 * every endpoint can rely on a row existing.
 */
function getOrCreateUserAvatar($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM user_avatar WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        unset($row["user_id"], $row["updated_at"]);
        return $row;
    }

    $look = avatarDefaultLook();
    $stmt = $conn->prepare("
        INSERT INTO user_avatar
            (user_id, skin, hair_style, hair_color, shirt_style, shirt_color,
             pants_style, pants_color, shoe_style, shoe_color)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id,
        $look["skin"],
        $look["hair_style"],  $look["hair_color"],
        $look["shirt_style"], $look["shirt_color"],
        $look["pants_style"], $look["pants_color"],
        $look["shoe_style"],  $look["shoe_color"],
    ]);
    return $look;
}
