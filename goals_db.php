<?php
/*
 * goals_db.php
 *
 * Shared helpers for the Savings Goals feature. Included by the goal endpoints
 * (get_goals, create_goal, add_to_goal, transfer_goal, delete_goal).
 *
 * A goal is a named savings target the user funds from their main balance.
 * Money added to a goal LEAVES the main account balance and is tracked in the
 * goal's saved_amount, so the ledger stays consistent. Moving a goal's money
 * back to the balance (once completed, or on delete) reverses that.
 *
 * Like the other *_db.php helpers this creates its table idempotently with
 * CREATE TABLE IF NOT EXISTS, so no manual migration is needed.
 *
 * Requires config.php (provides $conn) and feature_db.php (house account +
 * recordTransaction + getUserAccountId helpers) to have been included.
 */

function ensureGoalsSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS savings_goals (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            icon VARCHAR(40) NOT NULL DEFAULT 'target',
            target_amount DECIMAL(12,2) NOT NULL,
            saved_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'active',   -- 'active' | 'completed' | 'archived'
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_savings_goals_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/* Fetch a single goal that belongs to the given user (or null). */
function getGoalForUser($conn, $user_id, $goal_id) {
    $stmt = $conn->prepare("SELECT * FROM savings_goals WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$goal_id, $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
