<?php
/*
 * send_shared_message.php
 *
 * Post a message to a shared savings group chat. Only active members can
 * write. Chat messages deliberately create NO notifications — the chat is
 * casual and members see new messages when they open it.
 *
 * Request (POST JSON): { "user_id": 7, "goal_id": 3, "message": "I added €50!" }
 * Response: { status, messages } (the fresh list, so the app updates in one call)
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

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data["user_id"] ?? null;
$goal_id = $data["goal_id"] ?? null;
$message = trim($data["message"] ?? "");

try {
    if (!$user_id || !$goal_id) {
        throw new Exception("Missing group");
    }
    if ($message === "") {
        throw new Exception("Type a message first");
    }
    if (mb_strlen($message) > 500) {
        $message = mb_substr($message, 0, 500);
    }

    ensureSharedGoalsSchema($conn);

    $goal = getSharedGoal($conn, $goal_id);
    if (!$goal) {
        throw new Exception("Group not found");
    }

    $me = getSharedMember($conn, $goal_id, $user_id);
    if (!$me || $me["status"] !== "active") {
        throw new Exception("Only group members can write in the chat");
    }

    $stmt = $conn->prepare("
        INSERT INTO shared_goal_messages (goal_id, user_id, message)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$goal_id, $user_id, $message]);

    echo json_encode([
        "status"   => "success",
        "messages" => sharedGoalMessages($conn, $goal_id, 50),
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
