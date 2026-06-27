<?php
/*
 * cashback_db.php
 *
 * Shared helpers for the Partner Cashback Marketplace feature. Included by the
 * cashback endpoints (get_partners.php, buy_partner.php, redeem_cashback.php).
 *
 * Like feature_db.php, this file:
 *   1. Idempotently creates the new tables (partners, cashback, partner_purchases)
 *      with CREATE TABLE IF NOT EXISTS, so no manual SQL migration is needed.
 *   2. Seeds a starter set of partner offers the first time the partners table is
 *      empty.
 *
 * The money flow reuses the helpers in feature_db.php (getUserAccountId,
 * getHouseAccountId, recordTransaction) so partner spending and cashback
 * redemptions appear in the existing Transactions screen via the hidden
 * "DS Banking House" counterparty account.
 *
 * Requires that config.php (provides $conn) and feature_db.php have already been
 * included.
 */

/* Create the cashback tables once (cheap + idempotent on every request). */
function ensureCashbackSchema($conn) {
    // Partner companies and their offers.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS partners (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            category VARCHAR(50) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            icon VARCHAR(50) DEFAULT NULL,        -- MaterialCommunityIcons name
            brand_color VARCHAR(9) DEFAULT NULL,  -- hex color, e.g. #7C3AED
            image_url VARCHAR(255) DEFAULT NULL,  -- optional remote product image
            price DECIMAL(10,2) NOT NULL,
            cashback_percent INT(11) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Running cashback wallet per user.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS cashback (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cashback_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // One row per partner purchase (powers the purchase history list).
    $conn->exec("
        CREATE TABLE IF NOT EXISTS partner_purchases (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            partner_id INT(11) NOT NULL,
            partner_name VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            cashback_amount DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_partner_purchases_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/* Seed a starter set of partner offers if none exist yet. */
function seedPartners($conn) {
    $count = (int) $conn->query("SELECT COUNT(*) FROM partners")->fetchColumn();
    if ($count > 0) {
        return;
    }

    // name, category, description, icon, brand_color, price, cashback_percent
    $partners = [
        ["Sunny Hill Festival", "Festival", "Three days of live music in Prishtina. Get your full festival pass and earn cashback.", "music", "#7C3AED", 100.00, 20],
        ["Te Marja Restaurant", "Restaurant", "Traditional dishes and a cosy atmosphere. Pay your bill and get cashback.", "silverware-fork-knife", "#EF4444", 45.00, 12],
        ["Hotel Emerald", "Hotel", "One night stay in a deluxe room with breakfast included.", "bed", "#0EA5E9", 120.00, 15],
        ["Sole Coffee", "Coffee", "Specialty coffee voucher for your favourite spot in town.", "coffee", "#92400E", 6.00, 10],
        ["Gjirafa Electronics", "Electronics", "Latest gadgets and accessories with member-only cashback.", "laptop", "#2563EB", 800.00, 5],
        ["Prestige Clothing", "Clothing", "Seasonal fashion voucher for the whole collection.", "tshirt-crew", "#DB2777", 60.00, 8],
        ["FlexGym Membership", "Gym", "One month full-access gym membership with all classes.", "dumbbell", "#16A34A", 30.00, 18],
        ["StreamPlus Subscription", "Streaming", "Annual streaming subscription, movies and series included.", "play-circle", "#DC2626", 12.00, 25],
    ];

    $stmt = $conn->prepare("
        INSERT INTO partners (name, category, description, icon, brand_color, price, cashback_percent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($partners as $p) {
        $stmt->execute($p);
    }
}

/* Ensure a cashback row exists for the user and return it. */
function getOrCreateCashback($conn, $user_id) {
    $stmt = $conn->prepare("SELECT balance, total_earned FROM cashback WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $stmt = $conn->prepare("INSERT INTO cashback (user_id, balance, total_earned) VALUES (?, 0.00, 0.00)");
    $stmt->execute([$user_id]);
    return ["balance" => "0.00", "total_earned" => "0.00"];
}
