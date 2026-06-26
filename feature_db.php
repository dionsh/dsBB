<?php
/*
 * feature_db.php
 *
 * Shared helpers for the new banking features (Split The Bill, Round It Up,
 * Wordle Rewards). This file is included by the new endpoints and:
 *   1. Idempotently creates the new tables (savings, savings_history,
 *      rewards, reward_history) so no manual SQL migration is required.
 *   2. Provides a hidden "DS Banking House" account that acts as the
 *      counterparty for feature transactions. The existing get_transactions.php
 *      INNER JOINs both sides of a transaction, so every row needs a real
 *      counterparty to appear in the Transactions screen. The house account
 *      fills that role without touching any existing backend code.
 *
 * Requires that config.php has already been included (provides $conn).
 */

// Conversion rate used by the Wordle rewards system: 100 points = 1.00 EUR.
if (!defined("POINTS_PER_EUR")) {
    define("POINTS_PER_EUR", 100);
}

/* Create the feature tables once (cheap + idempotent on every request). */
function ensureFeatureSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS savings (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_savings_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS savings_history (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            purchase_amount DECIMAL(12,2) NOT NULL,
            saved_amount DECIMAL(12,2) NOT NULL,
            label VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_savings_history_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS rewards (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            points INT(11) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_rewards_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS reward_history (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            type VARCHAR(30) NOT NULL,
            points INT(11) NOT NULL DEFAULT 0,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            description VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_reward_history_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/*
 * Find (or create) the hidden house account used as the counterparty for
 * feature transactions. Returns its accounts.id. The house user cannot log in
 * (NULL password/pin) and simply acts as the bank's own ledger account.
 */
function getHouseAccountId($conn) {
    $houseEmail = "house@dsbanking.local";

    $stmt = $conn->prepare("
        SELECT a.id
        FROM accounts a
        JOIN users u ON a.user_id = u.id
        WHERE u.email = ?
        LIMIT 1
    ");
    $stmt->execute([$houseEmail]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int) $row["id"];
    }

    // Create the house user.
    $stmt = $conn->prepare("
        INSERT INTO users (name, surname, email, password, pin)
        VALUES ('DS Banking', 'House', ?, NULL, NULL)
    ");
    $stmt->execute([$houseEmail]);
    $houseUserId = $conn->lastInsertId();

    // Create the house account with a large reserve balance.
    $stmt = $conn->prepare("
        INSERT INTO accounts (user_id, account_number, balance)
        VALUES (?, '0000000000000000', 1000000.00)
    ");
    $stmt->execute([$houseUserId]);

    return (int) $conn->lastInsertId();
}

/* Resolve the logged-in user's account id from their user id. */
function getUserAccountId($conn, $user_id) {
    $stmt = $conn->prepare("SELECT id, balance FROM accounts WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/* Ensure a savings row exists for the user and return its current balance. */
function getOrCreateSavings($conn, $user_id) {
    $stmt = $conn->prepare("SELECT balance FROM savings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row["balance"];
    }
    $stmt = $conn->prepare("INSERT INTO savings (user_id, balance) VALUES (?, 0.00)");
    $stmt->execute([$user_id]);
    return "0.00";
}

/* Ensure a rewards row exists for the user and return its current points. */
function getOrCreateRewards($conn, $user_id) {
    $stmt = $conn->prepare("SELECT points FROM rewards WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int) $row["points"];
    }
    $stmt = $conn->prepare("INSERT INTO rewards (user_id, points) VALUES (?, 0)");
    $stmt->execute([$user_id]);
    return 0;
}

/* Insert a row into the existing transactions ledger. */
function recordTransaction($conn, $senderAccount, $receiverAccount, $amount, $description) {
    $stmt = $conn->prepare("
        INSERT INTO transactions (sender_account, receiver_account, amount, description)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$senderAccount, $receiverAccount, $amount, $description]);
}
