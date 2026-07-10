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
require "gift_cards_db.php";

$user_id = $_GET['user_id'] ?? null;

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    ensureGiftCardSchema($conn);

    $stmt = $conn->prepare("
        SELECT id, brand_key, brand_name, amount, code, created_at
        FROM gift_card_purchases
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 50
    ");
    $stmt->execute([$user_id]);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status"        => "success",
        "brands"        => array_values(giftCardCatalog()),
        "denominations" => giftCardDenominations(),
        "purchases"     => $purchases,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
