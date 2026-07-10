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
require "card_designs_db.php";
require "notifications_db.php";

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
    $design = $catalog[$design_id];

    // Price is taken from the server catalog, never from the client.
    $price = round((float) $design['price'], 2);
    if ($price <= 0) {
        throw new Exception("This design is free and does not require a purchase");
    }

    ensureFeatureSchema($conn);
    ensureCardDesignSchema($conn);

    $conn->beginTransaction();

    // Already owned? Don't charge twice.
    $stmt = $conn->prepare(
        "SELECT id FROM card_design_purchases WHERE user_id = ? AND design_id = ? LIMIT 1"
    );
    $stmt->execute([$user_id, $design_id]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception("You already own this card design");
    }

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    if ((float) $account['balance'] < $price) {
        throw new Exception("Insufficient balance");
    }

    // The price leaves the main balance as a house transaction, so the order
    // shows up in Transactions and Analytics ("Shopping").
    $houseAccountId = getHouseAccountId($conn);
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$price, $account['id']]);
    recordTransaction(
        $conn,
        $account['id'],
        $houseAccountId,
        $price,
        "Card Design - " . $design['name']
    );

    $stmt = $conn->prepare("
        INSERT INTO card_design_purchases (user_id, design_id, design_name, price)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $design_id, $design['name'], $price]);

    $newBalance = round((float) $account['balance'] - $price, 2);

    $conn->commit();

    // Best-effort inbox notification — never breaks the purchase itself.
    try {
        addNotification(
            $conn,
            $user_id,
            "carddesign",
            "Card design ordered",
            "Your " . $design['name'] . " card design ("
                . number_format($price, 2) . " EUR) has been ordered and will arrive within 3 business days."
        );
    } catch (Exception $e) {
        // ignore
    }

    echo json_encode([
        "status"      => "success",
        "design_id"   => $design_id,
        "design_name" => $design['name'],
        "price"       => $price,
        "new_balance" => $newBalance,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
