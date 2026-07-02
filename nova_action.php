<?php
/*
 * nova_action.php
 *
 * Execute a NOVA action AFTER the user pressed "Yes" on the confirmation
 * buttons in the chat. The app only calls this for actions nova_chat.php
 * proposed (freeze/unfreeze card, lock/unlock online payments,
 * enable/disable contactless, create a savings goal).
 *
 * Request (POST JSON):
 *   { "user_id": 7, "action": "freeze_card", "params": {} }
 *   { "user_id": 7, "action": "create_goal", "params": { "name": "Dubai", "amount": 500 } }
 *
 * Response: { status: "success", reply: "❄️ Done — ..." }
 *        or { status: "error",   message: "..." }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "config.php";
require_once "nova_actions.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data["user_id"] ?? null;
$action  = trim($data["action"] ?? "");
$params  = is_array($data["params"] ?? null) ? $data["params"] : [];

$allowed = [
    "freeze_card", "unfreeze_card",
    "lock_online", "unlock_online",
    "enable_contactless", "disable_contactless",
    "create_goal",
];

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if (!in_array($action, $allowed, true)) {
        throw new Exception("Unknown action");
    }

    $reply = novaExecuteAction($conn, (int) $user_id, $action, $params);

    echo json_encode(["status" => "success", "reply" => $reply]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
