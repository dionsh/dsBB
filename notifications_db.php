<?php
/*
 * notifications_db.php
 *
 * Shared helpers for the in-app Notifications feature. Included by the
 * notification endpoints and by the event sources that create notifications
 * (login.php, transfer.php).
 *
 * Like the other *_db.php helpers, this idempotently creates its tables with
 * CREATE TABLE IF NOT EXISTS, so no manual migration is needed.
 *
 *   notifications          - one row per notification shown in the inbox
 *   notification_settings  - per-user on/off switch (default ON)
 *
 * Requires that config.php (provides $conn) has already been included.
 */

function ensureNotificationSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            type VARCHAR(40) NOT NULL,          -- 'login' | 'received' | 'sent' | ...
            title VARCHAR(120) NOT NULL,
            message VARCHAR(255) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_notifications_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS notification_settings (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_notif_settings_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/* Whether the user wants notifications. Defaults to ON when no row exists. */
function notificationsEnabled($conn, $user_id) {
    $stmt = $conn->prepare("SELECT enabled FROM notification_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return true;
    }
    return (int) $row['enabled'] === 1;
}

/*
 * Create a notification for a user, but only if they have notifications enabled.
 * Returns true if a row was inserted. Wrapped by callers in try/catch so a
 * notification failure never breaks the underlying action (login, transfer).
 */
function addNotification($conn, $user_id, $type, $title, $message) {
    if (!$user_id) {
        return false;
    }
    ensureNotificationSchema($conn);
    if (!notificationsEnabled($conn, $user_id)) {
        return false;
    }
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $type, $title, $message]);
    return true;
}

/* Upsert the user's on/off preference. */
function setNotificationsEnabled($conn, $user_id, $enabled) {
    ensureNotificationSchema($conn);
    $stmt = $conn->prepare("
        INSERT INTO notification_settings (user_id, enabled)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)
    ");
    $stmt->execute([$user_id, $enabled ? 1 : 0]);
}
