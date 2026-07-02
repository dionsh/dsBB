<?php
/*
 * invite_shared_member.php
 *
 * Invite another EXISTING DS Banking user (by email) into a shared savings
 * group. Any active member of the group can invite. A previously declined
 * user can be re-invited.
 *
 * Request (POST JSON): { "user_id": 7, "goal_id": 3, "email": "friend@mail.com" }
 * Response: { status, message }
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
$goal_id = $data["goal_id"] ?? null;
$email   = trim($data["email"] ?? "");

try {
    if (!$user_id || !$goal_id) {
        throw new Exception("Missing group");
    }
    if ($email === "") {
        throw new Exception("Please enter an email address");
    }

    ensureSharedGoalsSchema($conn);

    $goal = getSharedGoal($conn, $goal_id);
    if (!$goal) {
        throw new Exception("Group not found");
    }
    if ($goal["status"] !== "active") {
        throw new Exception("This group is already completed");
    }

    $me = getSharedMember($conn, $goal_id, $user_id);
    if (!$me || $me["status"] !== "active") {
        throw new Exception("Only group members can invite");
    }

    // Verify the invited account actually exists.
    $invited = findUserByEmail($conn, $email);
    if (!$invited) {
        throw new Exception("No DS Banking account found with that email");
    }
    if ((int) $invited["id"] === (int) $user_id) {
        throw new Exception("You can't invite yourself");
    }

    $existing = getSharedMember($conn, $goal_id, $invited["id"]);
    if ($existing) {
        if ($existing["status"] === "active") {
            throw new Exception("That user is already in the group");
        }
        if ($existing["status"] === "invited") {
            throw new Exception("That user already has a pending invitation");
        }
        // Declined before — re-invite.
        $stmt = $conn->prepare("
            UPDATE shared_goal_members
            SET status = 'invited', invited_by = ?, responded_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $existing["id"]]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO shared_goal_members (goal_id, user_id, role, status, invited_by)
            VALUES (?, ?, 'member', 'invited', ?)
        ");
        $stmt->execute([$goal_id, $invited["id"], $user_id]);
    }

    // Tell the invited user (never break the action on failure).
    try {
        addNotification(
            $conn,
            (int) $invited["id"],
            "shared_goal",
            "Savings group invitation",
            sharedUserName($conn, $user_id) . " invited you to save together for \"" . $goal["name"] . "\". Open Shared Savings to accept or decline."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"  => "success",
        "message" => "Invitation sent to " . trim($invited["name"] . " " . $invited["surname"]),
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
