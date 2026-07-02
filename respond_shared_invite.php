<?php
/*
 * respond_shared_invite.php
 *
 * Accept or decline a pending shared savings group invitation.
 *
 * Request (POST JSON): { "user_id": 9, "goal_id": 3, "accept": true }
 * Response: { status, message, accepted }
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
$accept  = filter_var($data["accept"] ?? false, FILTER_VALIDATE_BOOLEAN);

try {
    if (!$user_id || !$goal_id) {
        throw new Exception("Missing invitation");
    }

    ensureSharedGoalsSchema($conn);

    $goal = getSharedGoal($conn, $goal_id);
    if (!$goal) {
        throw new Exception("Group not found");
    }

    $me = getSharedMember($conn, $goal_id, $user_id);
    if (!$me || $me["status"] !== "invited") {
        throw new Exception("No pending invitation for this group");
    }

    $stmt = $conn->prepare("
        UPDATE shared_goal_members
        SET status = ?, responded_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$accept ? "active" : "declined", $me["id"]]);

    // Tell whoever invited them (never break the action on failure).
    try {
        $inviter = $me["invited_by"] ? (int) $me["invited_by"] : (int) $goal["creator_id"];
        $myName  = sharedUserName($conn, $user_id);
        addNotification(
            $conn,
            $inviter,
            "shared_goal",
            $accept ? "Invitation accepted" : "Invitation declined",
            $accept
                ? $myName . " joined your savings group \"" . $goal["name"] . "\". 🎉"
                : $myName . " declined the invitation to \"" . $goal["name"] . "\"."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"   => "success",
        "accepted" => $accept,
        "message"  => $accept
            ? "You joined \"" . $goal["name"] . "\""
            : "Invitation declined",
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
