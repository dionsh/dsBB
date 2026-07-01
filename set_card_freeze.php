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
require "card_db.php";
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data['user_id'] ?? null;
// Accept true/false, 1/0, "true"/"false".
$frozenRaw = $data['frozen'] ?? null;
$frozen = filter_var($frozenRaw, FILTER_VALIDATE_BOOLEAN);

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($frozenRaw === null) {
        throw new Exception("Missing frozen state");
    }

    setCardFrozen($conn, $user_id, $frozen);

    // Confirm the action in the inbox (never break the action on failure).
    try {
        if ($frozen) {
            addNotification(
                $conn,
                $user_id,
                "card",
                "Card frozen",
                "Your card has been frozen. Payments are now blocked until you unfreeze it."
            );
        } else {
            addNotification(
                $conn,
                $user_id,
                "card",
                "Card unfrozen",
                "Your card is active again. You can make payments as usual."
            );
        }
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"  => "success",
        "frozen"  => $frozen,
        "message" => $frozen ? "Card frozen" : "Card unfrozen",
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
