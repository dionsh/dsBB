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
require "gift_cards_db.php";
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id   = $data['user_id'] ?? null;
$brand_key = strtolower(trim($data['brand_key'] ?? ""));
$amount    = round((float) ($data['amount'] ?? 0), 2);

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    $catalog = giftCardCatalog();
    if (!isset($catalog[$brand_key])) {
        throw new Exception("Unknown gift card brand");
    }
    $brand = $catalog[$brand_key];

    // Only the fixed face values are allowed (server-side, so the price can
    // never be tampered with from the client).
    if (!in_array($amount, array_map("floatval", giftCardDenominations()))) {
        throw new Exception("Invalid gift card value");
    }

    ensureFeatureSchema($conn);
    ensureGiftCardSchema($conn);

    $conn->beginTransaction();

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    if ((float) $account['balance'] < $amount) {
        throw new Exception("Insufficient balance");
    }

    $code = generateGiftCardCode($brand['format']);

    // The price leaves the main balance as a normal house transaction, so the
    // purchase shows up in Transactions and Analytics ("Shopping").
    $houseAccountId = getHouseAccountId($conn);
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$amount, $account['id']]);
    recordTransaction(
        $conn,
        $account['id'],
        $houseAccountId,
        $amount,
        "Gift Card - " . $brand['name']
    );

    $stmt = $conn->prepare("
        INSERT INTO gift_card_purchases (user_id, brand_key, brand_name, amount, code)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $brand['key'], $brand['name'], $amount, $code]);
    $purchaseId = (int) $conn->lastInsertId();

    $newBalance = round((float) $account['balance'] - $amount, 2);

    $conn->commit();

    // Best-effort inbox notification — never breaks the purchase itself.
    try {
        addNotification(
            $conn,
            $user_id,
            "giftcard",
            "Gift card ready",
            "Your " . $brand['name'] . " gift card ("
                . number_format($amount, 2) . " EUR) is ready. The code is saved in Subscriptions > Gift Cards."
        );
    } catch (Exception $e) {
        // ignore
    }

    echo json_encode([
        "status"      => "success",
        "purchase_id" => $purchaseId,
        "brand_key"   => $brand['key'],
        "brand_name"  => $brand['name'],
        "amount"      => $amount,
        "code"        => $code,
        "new_balance" => $newBalance,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
