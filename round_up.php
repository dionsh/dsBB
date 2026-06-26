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

$data = json_decode(file_get_contents("php://input"), true);

$user_id  = $data['user_id'] ?? null;
$purchase = floatval($data['purchase_amount'] ?? 0);
$label    = trim($data['label'] ?? "");

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($purchase <= 0) {
        throw new Exception("Enter a valid purchase amount");
    }

    ensureFeatureSchema($conn);

    // Round up to the next whole euro; the difference is the amount saved.
    $rounded = ceil($purchase - 0.0000001); // guard against float dust on whole numbers
    if ($rounded < $purchase) {
        $rounded = $purchase;
    }
    $saved = round($rounded - $purchase, 2);

    if ($saved <= 0) {
        throw new Exception("This amount is already a whole number - nothing to round up");
    }

    // Total leaving the main balance = purchase + round-up that moves to savings.
    $totalOut = round($purchase + $saved, 2);

    $conn->beginTransaction();

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }

    if ($account['balance'] < $totalOut) {
        throw new Exception("Insufficient balance");
    }

    $houseAccountId = getHouseAccountId($conn);
    getOrCreateSavings($conn, $user_id);

    $purchaseLabel = $label !== "" ? "Purchase - " . $label : "Purchase - Round Up";

    // 1) Purchase leaves the main balance.
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$purchase, $account['id']]);
    recordTransaction($conn, $account['id'], $houseAccountId, $purchase, $purchaseLabel);

    // 2) Round-up difference moves from the main balance into savings.
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$saved, $account['id']]);
    recordTransaction($conn, $account['id'], $houseAccountId, $saved, "Round Up Savings -> Savings");

    $stmt = $conn->prepare("UPDATE savings SET balance = balance + ? WHERE user_id = ?");
    $stmt->execute([$saved, $user_id]);

    $stmt = $conn->prepare("
        INSERT INTO savings_history (user_id, purchase_amount, saved_amount, label)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $purchase, $saved, $label !== "" ? $label : null]);

    // Read back the fresh savings balance.
    $stmt = $conn->prepare("SELECT balance FROM savings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $savingsBalance = $stmt->fetch(PDO::FETCH_ASSOC)['balance'];

    $newBalance = round($account['balance'] - $totalOut, 2);

    $conn->commit();

    echo json_encode([
        "status"          => "success",
        "message"         => "Saved " . number_format($saved, 2) . " EUR to your savings",
        "purchase"        => round($purchase, 2),
        "rounded"         => round($rounded, 2),
        "saved"           => $saved,
        "new_balance"     => $newBalance,
        "savings_balance" => $savingsBalance
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ]);
}
