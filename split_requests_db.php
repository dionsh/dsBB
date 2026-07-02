<?php
/*
 * split_requests_db.php
 *
 * Shared helpers for the Split The Bill request system — one user asks a
 * friend to split a bill 50/50, and the friend accepts (their share is
 * deducted and credited to the requester) or declines (nothing moves).
 * Included by the split-request endpoints (create_split_request,
 * get_split_requests, respond_split_request).
 *
 * Model:
 *   split_requests - one row per request. status:
 *                    'pending'  -> waiting for the friend to respond
 *                    'accepted' -> friend paid their share
 *                    'declined' -> friend said no, no money moved
 *
 * Money moves DIRECTLY between the two users' accounts (like transfer.php),
 * so the transaction appears in both users' Transactions screens. The
 * "Split Bill" description prefix keeps it in the existing "Split Bills"
 * analytics category (see analyticsCategory in analytics_db.php).
 *
 * Like the other *_db.php helpers this creates its table idempotently with
 * CREATE TABLE IF NOT EXISTS, so no manual migration is needed.
 *
 * Requires config.php ($conn) and feature_db.php (getUserAccountId +
 * recordTransaction) to have been included.
 */

function ensureSplitRequestsSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS split_requests (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            requester_id INT(11) NOT NULL,
            friend_id INT(11) NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            share_amount DECIMAL(12,2) NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- 'pending' | 'accepted' | 'declined'
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_split_requester (requester_id),
            KEY idx_split_friend (friend_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/* Fetch one request (or null). */
function getSplitRequest($conn, $request_id) {
    $stmt = $conn->prepare("SELECT * FROM split_requests WHERE id = ? LIMIT 1");
    $stmt->execute([$request_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* Display name helper ("Dion Sherifi"). */
function splitUserName($conn, $user_id) {
    $stmt = $conn->prepare("SELECT name, surname FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return $u ? trim($u["name"] . " " . $u["surname"]) : "A user";
}

/*
 * Verify the chosen friend is a real, login-capable account (the hidden house
 * account has a NULL password and must never receive requests).
 * Returns ["id" => .., "name" => .., "surname" => ..] or null.
 */
function findSplitFriend($conn, $friend_id) {
    $stmt = $conn->prepare("
        SELECT id, name, surname FROM users
        WHERE id = ? AND password IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$friend_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/*
 * Everyone the user can split a bill with — all other real DS Banking users,
 * for the friend picker in the app.
 */
function splitFriendList($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT id, name, surname, email FROM users
        WHERE password IS NOT NULL AND id <> ?
        ORDER BY name ASC, surname ASC
    ");
    $stmt->execute([$user_id]);

    $friends = [];
    while ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $friends[] = [
            "id"    => (int) $u["id"],
            "name"  => trim($u["name"] . " " . $u["surname"]),
            "email" => $u["email"],
        ];
    }
    return $friends;
}
