<?php
/*
 * create_shared_goal.php
 *
 * Create a shared savings group. The creator becomes the active owner and can
 * optionally invite one existing DS Banking user (by email) right away — the
 * invited account is verified to exist before the invitation is created.
 *
 * Request (POST JSON):
 *   { "user_id": 7, "name": "Dubai Trip", "icon": "airplane",
 *     "target_amount": 2000, "invite_email": "friend@mail.com" }   // email optional
 *
 * Response: { status, message, goal_id }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require "config.php";
require "feature_db.php";
require "shared_goals_db.php";
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data["user_id"] ?? null;
$name    = trim($data["name"] ?? "");
$icon    = trim($data["icon"] ?? "account-group");
$target  = round(floatval($data["target_amount"] ?? 0), 2);
$invite  = trim($data["invite_email"] ?? "");

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($name === "") {
        throw new Exception("Please enter a group name");
    }
    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
    }
    if ($target <= 0) {
        throw new Exception("Please enter a valid goal amount");
    }
    if ($icon === "") {
        $icon = "account-group";
    }

    ensureSharedGoalsSchema($conn);

    // Verify the invited account exists BEFORE creating anything.
    $invitedUser = null;
    if ($invite !== "") {
        $invitedUser = findUserByEmail($conn, $invite);
        if (!$invitedUser) {
            throw new Exception("No DS Banking account found with that email");
        }
        if ((int) $invitedUser["id"] === (int) $user_id) {
            throw new Exception("You can't invite yourself");
        }
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("
        INSERT INTO shared_goals (creator_id, name, icon, target_amount, current_amount, status)
        VALUES (?, ?, ?, ?, 0.00, 'active')
    ");
    $stmt->execute([$user_id, $name, $icon, $target]);
    $goalId = (int) $conn->lastInsertId();

    // The creator is an active owner from the start.
    $stmt = $conn->prepare("
        INSERT INTO shared_goal_members (goal_id, user_id, role, status, invited_by, responded_at)
        VALUES (?, ?, 'owner', 'active', NULL, NOW())
    ");
    $stmt->execute([$goalId, $user_id]);

    if ($invitedUser) {
        $stmt = $conn->prepare("
            INSERT INTO shared_goal_members (goal_id, user_id, role, status, invited_by)
            VALUES (?, ?, 'member', 'invited', ?)
        ");
        $stmt->execute([$goalId, $invitedUser["id"], $user_id]);
    }

    $conn->commit();

    // Tell the invited user about it (never break the action on failure).
    if ($invitedUser) {
        try {
            addNotification(
                $conn,
                (int) $invitedUser["id"],
                "shared_goal",
                "Savings group invitation",
                sharedUserName($conn, $user_id) . " invited you to save together for \"" . $name . "\". Open Shared Savings to accept or decline."
            );
        } catch (Exception $e) {
            // ignore notification errors
        }
    }

    echo json_encode([
        "status"  => "success",
        "message" => $invitedUser
            ? "Group created — invitation sent to " . trim($invitedUser["name"] . " " . $invitedUser["surname"])
            : "Group created",
        "goal_id" => $goalId,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
