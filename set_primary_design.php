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
require "card_designs_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id   = $data['user_id'] ?? null;
$design_id = trim($data['design_id'] ?? "");

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    $catalog = cardDesignCatalog();
    if (!isset($catalog[$design_id])) {
        throw new Exception("Unknown card design");
    }

    ensureCardDesignSchema($conn);

    // You can only display the free classic card or a design you have bought.
    if (!userCanUseDesign($conn, $user_id, $design_id)) {
        throw new Exception("You don't own this card design");
    }

    setPrimaryDesign($conn, $user_id, $design_id);

    echo json_encode([
        "status"  => "success",
        "primary" => $design_id,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
