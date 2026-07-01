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
require "subscriptions_db.php";
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id  = $data['user_id'] ?? null;
$plan_key = trim($data['plan_key'] ?? "");

try {
    if (!$user_id || $plan_key === "") {
        throw new Exception("Missing subscription");
    }

    ensureSubscriptionSchema($conn);

    $plan = getSubscriptionPlan($conn, $plan_key);
    if (!$plan) {
        throw new Exception("Unknown subscription");
    }

    // Upsert to active (re-subscribing clears any previous cancellation).
    $stmt = $conn->prepare("
        INSERT INTO user_subscriptions (user_id, plan_key, status, created_at, canceled_at)
        VALUES (?, ?, 'active', NOW(), NULL)
        ON DUPLICATE KEY UPDATE status = 'active', created_at = NOW(), canceled_at = NULL
    ");
    $stmt->execute([$user_id, $plan_key]);

    try {
        addNotification(
            $conn,
            $user_id,
            "subscription",
            "Subscription started",
            "You subscribed to " . $plan['name'] . " (" . number_format((float) $plan['price'], 2) . " EUR / month)."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"   => "success",
        "message"  => "Subscribed to " . $plan['name'],
        "plan_key" => $plan_key,
        "active"   => true,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
