<?php
/*
 * shared_goals_db.php
 *
 * Shared helpers for the Shared Savings Groups feature — users saving money
 * together toward one goal (a trip, a car, a wedding...). Included by the
 * shared-goal endpoints (get_shared_goals, create_shared_goal,
 * invite_shared_member, respond_shared_invite, contribute_shared_goal).
 *
 * Model:
 *   shared_goals              - the group itself (name, icon, target, saved)
 *   shared_goal_members       - who belongs to a group. status:
 *                               'invited'  -> invitation pending
 *                               'active'   -> accepted, can contribute
 *                               'declined' -> said no
 *   shared_goal_contributions - every deposit, for the history list
 *   shared_goal_messages      - the group chat (active members only)
 *
 * Money contributed LEAVES the member's main balance (recorded in the ledger
 * against the house account, like savings goals), so the accounting stays
 * consistent with the rest of the app.
 *
 * Like the other *_db.php helpers this creates its tables idempotently with
 * CREATE TABLE IF NOT EXISTS, so no manual migration is needed.
 *
 * Requires config.php ($conn) and feature_db.php (house account +
 * recordTransaction + getUserAccountId) to have been included.
 */

function ensureSharedGoalsSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS shared_goals (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            creator_id INT(11) NOT NULL,
            name VARCHAR(120) NOT NULL,
            icon VARCHAR(40) NOT NULL DEFAULT 'account-group',
            target_amount DECIMAL(12,2) NOT NULL,
            current_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'active',   -- 'active' | 'completed'
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_shared_goals_creator (creator_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS shared_goal_members (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            goal_id INT(11) NOT NULL,
            user_id INT(11) NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'member',     -- 'owner' | 'member'
            status VARCHAR(20) NOT NULL DEFAULT 'invited',  -- 'invited' | 'active' | 'declined'
            invited_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uniq_shared_member (goal_id, user_id),
            KEY idx_shared_members_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS shared_goal_contributions (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            goal_id INT(11) NOT NULL,
            user_id INT(11) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_shared_contrib_goal (goal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS shared_goal_messages (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            goal_id INT(11) NOT NULL,
            user_id INT(11) NOT NULL,
            message VARCHAR(500) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_shared_msg_goal (goal_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/* Fetch one group (or null). */
function getSharedGoal($conn, $goal_id) {
    $stmt = $conn->prepare("SELECT * FROM shared_goals WHERE id = ? LIMIT 1");
    $stmt->execute([$goal_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* Fetch the membership row of one user in one group (or null). */
function getSharedMember($conn, $goal_id, $user_id) {
    $stmt = $conn->prepare("
        SELECT * FROM shared_goal_members WHERE goal_id = ? AND user_id = ? LIMIT 1
    ");
    $stmt->execute([$goal_id, $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/*
 * Verify an invited user actually exists (and can log in — the hidden house
 * account has a NULL password and must never be invited).
 * Returns ["id" => .., "name" => .., "surname" => ..] or null.
 */
function findUserByEmail($conn, $email) {
    $stmt = $conn->prepare("
        SELECT id, name, surname FROM users
        WHERE email = ? AND password IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([trim($email)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* Display name helper ("Dion Sherifi"). */
function sharedUserName($conn, $user_id) {
    $stmt = $conn->prepare("SELECT name, surname FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return $u ? trim($u["name"] . " " . $u["surname"]) : "A member";
}

/*
 * All members of a group with their name and how much they contributed —
 * ordered owner first, then by contribution.
 */
function sharedGoalMembers($conn, $goal_id) {
    $stmt = $conn->prepare("
        SELECT m.user_id, m.role, m.status, u.name, u.surname,
               COALESCE(c.total, 0) AS contributed
        FROM shared_goal_members m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN (
            SELECT user_id, SUM(amount) AS total
            FROM shared_goal_contributions
            WHERE goal_id = ?
            GROUP BY user_id
        ) c ON c.user_id = m.user_id
        WHERE m.goal_id = ? AND m.status <> 'declined'
        ORDER BY (m.role = 'owner') DESC, contributed DESC, m.id ASC
    ");
    $stmt->execute([$goal_id, $goal_id]);

    $members = [];
    while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $members[] = [
            "user_id"     => (int) $m["user_id"],
            "name"        => trim($m["name"] . " " . $m["surname"]),
            "role"        => $m["role"],
            "status"      => $m["status"],
            "contributed" => round((float) $m["contributed"], 2),
        ];
    }
    return $members;
}

/*
 * The last $limit chat messages of a group, oldest first (ready to render
 * top-to-bottom), each with the sender's display name.
 */
function sharedGoalMessages($conn, $goal_id, $limit = 50) {
    $stmt = $conn->prepare("
        SELECT m.id, m.user_id, m.message, m.created_at, u.name, u.surname
        FROM shared_goal_messages m
        JOIN users u ON u.id = m.user_id
        WHERE m.goal_id = ?
        ORDER BY m.id DESC
        LIMIT " . (int) $limit . "
    ");
    $stmt->execute([$goal_id]);

    $messages = [];
    while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $messages[] = [
            "id"         => (int) $m["id"],
            "user_id"    => (int) $m["user_id"],
            "name"       => trim($m["name"] . " " . $m["surname"]),
            "message"    => $m["message"],
            "created_at" => $m["created_at"],
        ];
    }
    return array_reverse($messages); // oldest first
}
