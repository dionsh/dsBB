<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require "config.php";
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);
$user_id = $data['user_id'] ?? null;
// Accept true/false, 1/0, "1"/"0".
$enabled = filter_var($data['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    setNotificationsEnabled($conn, $user_id, $enabled);

    echo json_encode([
        "status"  => "success",
        "enabled" => $enabled,
        "message" => $enabled ? "Notifications turned on" : "Notifications turned off",
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
