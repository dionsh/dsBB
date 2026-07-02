<?php
/*
 * get_shared_messages.php
 *
 * The group chat of a shared savings group. Only active members can read it.
 * The app polls this while the chat is open.
 *
 * Request:  GET ?user_id=7&goal_id=3
 * Response: { status, messages: [ { id, user_id, name, message, created_at } ] }
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "shared_goals_db.php";

$user_id = $_GET["user_id"] ?? null;
$goal_id = $_GET["goal_id"] ?? null;

try {
    if (!$user_id || !$goal_id) {
        throw new Exception("Missing group");
    }

    ensureSharedGoalsSchema($conn);

    $goal = getSharedGoal($conn, $goal_id);
    if (!$goal) {
        throw new Exception("Group not found");
    }

    $me = getSharedMember($conn, $goal_id, $user_id);
    if (!$me || $me["status"] !== "active") {
        throw new Exception("Only group members can read the chat");
    }

    echo json_encode([
        "status"   => "success",
        "messages" => sharedGoalMessages($conn, $goal_id, 50),
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
