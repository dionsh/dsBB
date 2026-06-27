<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "applepay_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    ensureApplePaySchema($conn);

    $card = getUserCard($conn, $user_id);
    if (!$card) {
        echo json_encode(["status" => "error", "message" => "No card found for this user"]);
        exit;
    }

    $wallet = getWalletEntry($conn, $card["id"]);

    echo json_encode([
        "status"      => "success",
        "in_wallet"   => $wallet ? true : false,
        "card_number" => $card["card_number"],
        "expiry_date" => $card["expiry_date"],
        "device_name" => $wallet["device_name"] ?? null,
        "added_at"    => $wallet["added_at"] ?? null,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
