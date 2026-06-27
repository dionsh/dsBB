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
require "applepay_db.php";

$data = json_decode(file_get_contents("php://input"), true);
$user_id = $data['user_id'] ?? null;

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    ensureApplePaySchema($conn);

    $card = getUserCard($conn, $user_id);
    if (!$card) {
        throw new Exception("No card found for this user");
    }

    $stmt = $conn->prepare("DELETE FROM apple_pay_devices WHERE card_id = ?");
    $stmt->execute([$card["id"]]);

    echo json_encode([
        "status"    => "success",
        "message"   => "Card removed from Apple Wallet",
        "in_wallet" => false,
        "removed"   => $stmt->rowCount(),
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
