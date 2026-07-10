<?php
/*
 * subscriptions_db.php
 *
 * Shared helpers for the Subscriptions feature. Included by the subscription
 * endpoints (get_subscriptions, subscribe, cancel_subscription).
 *
 * Two tables (created idempotently, like the other *_db.php helpers):
 *   subscription_plans  - the catalog of available subscriptions (seeded once).
 *   user_subscriptions  - per-user status for each plan (active | canceled).
 *
 * Subscribing/cancelling only flips the stored status (no money is moved), so
 * the user's balance and ledger are never affected — it is a status manager,
 * mirroring what modern banking apps show under "Subscriptions".
 *
 * Requires config.php (provides $conn) to have been included.
 */

function ensureSubscriptionSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS subscription_plans (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            plan_key VARCHAR(40) NOT NULL,
            name VARCHAR(80) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            icon VARCHAR(40) NOT NULL DEFAULT 'credit-card-outline',
            color VARCHAR(16) NOT NULL DEFAULT '#191970',
            sort_order INT(11) NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_plan_key (plan_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS user_subscriptions (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            plan_key VARCHAR(40) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',   -- 'active' | 'canceled'
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            canceled_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uniq_user_plan (user_id, plan_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    seedSubscriptionPlans($conn);
}

/*
 * Seed / top up the catalog. Uses INSERT IGNORE keyed on the unique plan_key,
 * so existing plans are left untouched and any NEW plans are added — that way
 * new subscriptions appear even on databases that were already seeded.
 */
function seedSubscriptionPlans($conn) {
    $plans = [
        ["netflix",    "Netflix",            12.99, "netflix",        "#E50914", 1],
        ["spotify",    "Spotify Premium",     9.99, "spotify",        "#1DB954", 2],
        ["gym",        "Gym Membership",     29.99, "dumbbell",       "#FF7A00", 3],
        ["codex",      "Codex Subscription", 19.99, "code-tags",      "#6C5CE7", 4],
        ["prime",      "Amazon Prime",        8.99, "package-variant-closed", "#00A8E1", 5],
        ["disney",     "Disney+",             8.99, "castle",         "#113CCF", 6],
        ["youtube",    "YouTube Premium",    11.99, "youtube",        "#FF0000", 7],
        ["xbox",       "Xbox Game Pass",     12.99, "microsoft-xbox", "#107C10", 8],
        ["icloud",     "iCloud+",             2.99, "cloud-outline",  "#3693F3", 9],
        ["applemusic", "Apple Music",        10.99, "music-circle",   "#FA243C", 10],
        ["hbo",        "HBO Max",             9.99, "movie-open",     "#7E2CCB", 11],
        ["audible",    "Audible",            14.99, "headphones",     "#F8991C", 12],
    ];

    $stmt = $conn->prepare("
        INSERT IGNORE INTO subscription_plans (plan_key, name, price, icon, color, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($plans as $p) {
        $stmt->execute($p);
    }

    // The "amazon" glyph doesn't exist in the app's MaterialCommunityIcons set
    // (renders as a "?" box) — repair rows seeded before this fix.
    $conn->exec("UPDATE subscription_plans SET icon = 'package-variant-closed' WHERE icon = 'amazon'");
}

/* Return the plan row for a key (or null). */
function getSubscriptionPlan($conn, $plan_key) {
    $stmt = $conn->prepare("SELECT * FROM subscription_plans WHERE plan_key = ? LIMIT 1");
    $stmt->execute([$plan_key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
