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

    // Only refund if the plan is currently active (avoid double refunds).
    $stmt = $conn->prepare("SELECT status FROM user_subscriptions WHERE user_id = ? AND plan_key = ?");
    $stmt->execute([$user_id, $plan_key]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing || $existing['status'] !== 'active') {
        // Nothing to refund; just make sure it's marked cancelled.
        $stmt = $conn->prepare("
            UPDATE user_subscriptions SET status = 'canceled', canceled_at = NOW()
            WHERE user_id = ? AND plan_key = ?
        ");
        $stmt->execute([$user_id, $plan_key]);
        echo json_encode([
            "status"   => "success",
            "message"  => "Cancelled " . $plan['name'],
            "plan_key" => $plan_key,
            "active"   => false,
            "refunded" => 0,
        ]);
        exit;
    }

    $conn->beginTransaction();

    // Refund the monthly price back to the main balance.
    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    $houseAccountId = getHouseAccountId($conn);

    $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$price, $account['id']]);
    recordTransaction($conn, $houseAccountId, $account['id'], $price, "Subscription Refund - " . $plan['name']);

    $stmt = $conn->prepare("
        UPDATE user_subscriptions SET status = 'canceled', canceled_at = NOW()
        WHERE user_id = ? AND plan_key = ?
    ");
    $stmt->execute([$user_id, $plan_key]);

    $newBalance = round((float) $account['balance'] + $price, 2);
    $conn->commit();

    try {
        addNotification(
            $conn,
            $user_id,
            "subscription",
            "Subscription cancelled",
            "You cancelled " . $plan['name'] . " — " . number_format($price, 2) . " EUR refunded."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"      => "success",
        "message"     => "Cancelled " . $plan['name'],
        "plan_key"    => $plan_key,
        "active"      => false,
        "refunded"    => $price,
        "new_balance" => $newBalance,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
