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

$user_id     = $data['user_id'] ?? null;
$device_name = trim($data['device_name'] ?? "");
if ($device_name === "") {
    $device_name = "This device";
}

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    ensureApplePaySchema($conn);

    $card = getUserCard($conn, $user_id);
    if (!$card) {
        throw new Exception("No card found for this user");
    }

    // Only add once per card (idempotent).
    $existing = getWalletEntry($conn, $card["id"]);
    if (!$existing) {
        $stmt = $conn->prepare("
            INSERT INTO apple_pay_devices (card_id, device_name)
            VALUES (?, ?)
        ");
        $stmt->execute([$card["id"], $device_name]);
    }

    $wallet = getWalletEntry($conn, $card["id"]);

    echo json_encode([
        "status"      => "success",
        "message"     => "Card added to Apple Wallet",
        "in_wallet"   => true,
        "device_name" => $wallet["device_name"] ?? $device_name,
        "added_at"    => $wallet["added_at"] ?? null,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
