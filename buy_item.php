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
require "feature_db.php";
require "avatar_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data['user_id'] ?? null;
$item_id = intval($data['item_id'] ?? 0);

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($item_id <= 0) {
        throw new Exception("Missing item");
    }

    ensureFeatureSchema($conn);
    ensureAvatarSchema($conn);

    $conn->beginTransaction();

    $item = getAvatarItem($conn, $item_id);
    if (!$item) {
        throw new Exception("Item not found");
    }
    if ((int) $item["is_free"] === 1) {
        throw new Exception("This item is already free");
    }
    if (userOwnsItem($conn, $user_id, $item_id)) {
        throw new Exception("You already own this item");
    }

    $price = (int) $item["price_points"];

    // Read the points balance inside the transaction (server is the source of
    // truth — the client price is never trusted).
    $currentPoints = getOrCreateRewards($conn, $user_id);
    if ($currentPoints < $price) {
        throw new Exception("You need $price points but only have $currentPoints");
    }

    // Deduct the points, mirroring redeem_points.php.
    $stmt = $conn->prepare("UPDATE rewards SET points = points - ? WHERE user_id = ?");
    $stmt->execute([$price, $user_id]);

    // Log the spend in the existing reward history (negative points, no cash).
    $description = "Unlocked " . $item["name"];
    $stmt = $conn->prepare("
        INSERT INTO reward_history (user_id, type, points, amount, description)
        VALUES (?, 'avatar_purchase', ?, 0.00, ?)
    ");
    $stmt->execute([$user_id, -$price, $description]);

    // Grant the item.
    $stmt = $conn->prepare("INSERT INTO user_items (user_id, item_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $item_id]);

    $newPoints = $currentPoints - $price;

    $conn->commit();

    echo json_encode([
        "status"       => "success",
        "message"      => "Unlocked " . $item["name"],
        "item_id"      => $item_id,
        "slot"         => $item["slot"],
        "style"        => $item["style"],
        "spent"        => $price,
        "total_points" => $newPoints,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage(),
    ]);
}
