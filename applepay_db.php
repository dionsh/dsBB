<?php
/*
 * applepay_db.php
 *
 * Shared helpers for the Apple Pay / Apple Wallet feature. The apple_pay_devices
 * table already exists in the schema; this helper recreates it idempotently
 * (CREATE TABLE IF NOT EXISTS) for fresh installs and exposes small helpers used
 * by the Apple Pay endpoints.
 *
 * "Card is in Apple Wallet" simply means a row exists in apple_pay_devices for
 * the user's card_id (resolved cards.account_id -> accounts.user_id).
 *
 * Requires that config.php (provides $conn) has already been included.
 */

function ensureApplePaySchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS apple_pay_devices (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            card_id INT(11) NOT NULL,
            device_name VARCHAR(100) DEFAULT NULL,
            added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY card_id (card_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/* Resolve the user's card (id + display fields). Returns the row or false. */
function getUserCard($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT c.id, c.card_number, c.expiry_date
        FROM cards c
        JOIN accounts a ON c.account_id = a.id
        WHERE a.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/* Return the most recent wallet row for a card, or false if not added. */
function getWalletEntry($conn, $card_id) {
    $stmt = $conn->prepare("
        SELECT id, device_name, added_at
        FROM apple_pay_devices
        WHERE card_id = ?
        ORDER BY added_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$card_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
