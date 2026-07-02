<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "card_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    $frozen   = isCardFrozen($conn, $user_id);
    $controls = getCardControls($conn, $user_id);
    echo json_encode([
        "status"              => "success",
        "frozen"              => $frozen,
        "online_locked"       => $controls["online_locked"],
        "contactless_enabled" => $controls["contactless_enabled"],
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
