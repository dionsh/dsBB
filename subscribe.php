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
require "feature_db.php";        // getUserAccountId + getHouseAccountId + recordTransaction
require "subscriptions_db.php";
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id  = $data['user_id'] ?? null;
$plan_key = trim($data['plan_key'] ?? "");

try {
    if (!$user_id || $plan_key === "") {
        throw new Exception("Missing subscription");
    }

    ensureFeatureSchema($conn);
    ensureSubscriptionSchema($conn);

    $plan = getSubscriptionPlan($conn, $plan_key);
    if (!$plan) {
        throw new Exception("Unknown subscription");
    }
    $price = round((float) $plan['price'], 2);

    // If it's already active, don't charge again.
    $stmt = $conn->prepare("SELECT status FROM user_subscriptions WHERE user_id = ? AND plan_key = ?");
    $stmt->execute([$user_id, $plan_key]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing && $existing['status'] === 'active') {
        echo json_encode([
            "status"   => "success",
            "message"  => "Already subscribed to " . $plan['name'],
            "plan_key" => $plan_key,
            "active"   => true,
            "charged"  => 0,
        ]);
        exit;
    }

    $conn->beginTransaction();

    // Charge the first month from the main balance.
    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    if ((float) $account['balance'] < $price) {
        throw new Exception("Insufficient balance");
    }

    $houseAccountId = getHouseAccountId($conn);

    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$price, $account['id']]);
    recordTransaction($conn, $account['id'], $houseAccountId, $price, "Subscription - " . $plan['name']);

    // Mark active (re-subscribing clears any previous cancellation).
    $stmt = $conn->prepare("
        INSERT INTO user_subscriptions (user_id, plan_key, status, created_at, canceled_at)
        VALUES (?, ?, 'active', NOW(), NULL)
        ON DUPLICATE KEY UPDATE status = 'active', created_at = NOW(), canceled_at = NULL
    ");
    $stmt->execute([$user_id, $plan_key]);

    $newBalance = round((float) $account['balance'] - $price, 2);
    $conn->commit();

    try {
        addNotification(
            $conn,
            $user_id,
            "subscription",
            "Subscription started",
            "You subscribed to " . $plan['name'] . " — " . number_format($price, 2) . " EUR charged."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"      => "success",
        "message"     => "Subscribed to " . $plan['name'],
        "plan_key"    => $plan_key,
        "active"      => true,
        "charged"     => $price,
        "new_balance" => $newBalance,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
