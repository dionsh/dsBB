<?php
/*
 * card_db.php
 *
 * Shared helpers for the Card Freeze feature. Included by the card-status
 * endpoints and by transfer.php (which rejects payments from a frozen card).
 *
 * Like the other *_db.php helpers, this idempotently creates its table with
 * CREATE TABLE IF NOT EXISTS, so no manual migration is needed.
 *
 *   card_status - one row per user holding the frozen flag for their card.
 *
 * The state is keyed by user_id (each user has a single card in this app),
 * which keeps it consistent with the savings/rewards helpers and means we never
 * have to ALTER the existing `cards` table.
 *
 * Requires that config.php (provides $conn) has already been included.
 */

function ensureCardSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS card_status (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            frozen TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_card_status_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/* Whether the user's card is currently frozen. Defaults to false (unfrozen). */
function isCardFrozen($conn, $user_id) {
    ensureCardSchema($conn);
    $stmt = $conn->prepare("SELECT frozen FROM card_status WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return (int) $row['frozen'] === 1;
}

/* Upsert the user's frozen flag. */
function setCardFrozen($conn, $user_id, $frozen) {
    ensureCardSchema($conn);
    $stmt = $conn->prepare("
        INSERT INTO card_status (user_id, frozen)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE frozen = VALUES(frozen)
    ");
    $stmt->execute([$user_id, $frozen ? 1 : 0]);
}

/*
 * Extra card controls NOVA can toggle (online payments lock, contactless).
 * Kept in their own table — created idempotently like card_status — so the
 * existing table never needs an ALTER.
 */
function ensureCardControlsSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS card_controls (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            online_locked TINYINT(1) NOT NULL DEFAULT 0,
            contactless_enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_card_controls_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/* The user's card controls. Defaults: online allowed, contactless on. */
function getCardControls($conn, $user_id) {
    ensureCardControlsSchema($conn);
    $stmt = $conn->prepare("SELECT online_locked, contactless_enabled FROM card_controls WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        "online_locked"       => $row ? ((int) $row["online_locked"] === 1) : false,
        "contactless_enabled" => $row ? ((int) $row["contactless_enabled"] === 1) : true,
    ];
}

/* Upsert one control ('online_locked' | 'contactless_enabled'). */
function setCardControl($conn, $user_id, $field, $value) {
    if (!in_array($field, ["online_locked", "contactless_enabled"], true)) {
        throw new Exception("Unknown card control");
    }
    ensureCardControlsSchema($conn);
    $current = getCardControls($conn, $user_id);
    $current[$field] = (bool) $value;
    $stmt = $conn->prepare("
        INSERT INTO card_controls (user_id, online_locked, contactless_enabled)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE online_locked = VALUES(online_locked),
                                contactless_enabled = VALUES(contactless_enabled)
    ");
    $stmt->execute([
        $user_id,
        $current["online_locked"] ? 1 : 0,
        $current["contactless_enabled"] ? 1 : 0,
    ]);
}
