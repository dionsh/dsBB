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

    // One row per partner purchase (powers the purchase history list). Each
    // purchase issues a unique ticket_code shown to the user.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS partner_purchases (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            partner_id INT(11) NOT NULL,
            partner_name VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            cashback_amount DECIMAL(10,2) NOT NULL,
            ticket_code VARCHAR(40) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_partner_purchases_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Older databases already have partner_purchases without ticket_code — add
    // the column if it is missing (MySQL has no "ADD COLUMN IF NOT EXISTS").
    $hasTicket = (int) $conn->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'partner_purchases'
          AND COLUMN_NAME = 'ticket_code'
    ")->fetchColumn();
    if ($hasTicket === 0) {
        $conn->exec("ALTER TABLE partner_purchases ADD COLUMN ticket_code VARCHAR(40) DEFAULT NULL");
    }
}

/*
 * Keep only "big" offers (>= 30 EUR) active. This removes the old 6 EUR coffee
 * voucher and the 12 EUR streaming offer from databases that were seeded before
 * this change, so every visible offer is a ticketed purchase of 30 EUR or more.
 * Idempotent (affects 0 rows once applied).
 */
function normalizeCashbackOffers($conn) {
    $conn->exec("UPDATE partners SET active = 0 WHERE price < 30 AND active = 1");
}

/*
 * Build a unique, human-readable ticket code for a purchase, e.g.
 * "Sunny Hill Festival" -> "SHF-4F2A9C". Prefix = up to 3 word initials.
 */
function generateTicketCode($partnerName) {
    $prefix = "";
    foreach (preg_split('/\s+/', trim($partnerName)) as $word) {
        if ($word === "") {
            continue;
        }
        $prefix .= strtoupper(substr($word, 0, 1));
        if (strlen($prefix) >= 3) {
            break;
        }
    }
    if ($prefix === "") {
        $prefix = "TKT";
    }
    try {
        $rand = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
    } catch (Exception $e) {
        $rand = strtoupper(substr(md5(uniqid('', true)), 0, 6));
    }
    return $prefix . "-" . $rand;
}

/* Seed a starter set of partner offers if none exist yet. */
function seedPartners($conn) {
    $count = (int) $conn->query("SELECT COUNT(*) FROM partners")->fetchColumn();
    if ($count > 0) {
        return;
    }

    // name, category, description, icon, brand_color, price, cashback_percent
    // Every offer is a ticketed purchase of 30 EUR or more.
    $partners = [
        ["Sunny Hill Festival", "Festival", "Three days of live music in Prishtina. Get your full festival pass and earn cashback.", "music", "#7C3AED", 100.00, 20],
        ["Te Marja Restaurant", "Restaurant", "A full dinner experience for two with traditional dishes. Reserve your table and get cashback.", "silverware-fork-knife", "#EF4444", 45.00, 12],
        ["Hotel Emerald", "Hotel", "One night stay in a deluxe room with breakfast included.", "bed", "#0EA5E9", 120.00, 15],
        ["Gjirafa Electronics", "Electronics", "A new laptop with member-only cashback and free delivery.", "laptop", "#2563EB", 800.00, 5],
        ["Prestige Clothing", "Clothing", "Seasonal fashion voucher for the whole collection.", "tshirt-crew", "#DB2777", 60.00, 8],
        ["FlexGym Membership", "Gym", "Three month full-access gym membership with all classes.", "dumbbell", "#16A34A", 30.00, 18],
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
