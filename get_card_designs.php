<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require "config.php";
require "card_designs_db.php";

$user_id = $_GET['user_id'] ?? null;

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    ensureCardDesignSchema($conn);

    echo json_encode([
        "status" => "success",
        "owned"  => getOwnedCardDesigns($conn, $user_id),
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
