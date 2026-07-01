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

    $stmt = $conn->prepare("
        UPDATE user_subscriptions
        SET status = 'canceled', canceled_at = NOW()
        WHERE user_id = ? AND plan_key = ?
    ");
    $stmt->execute([$user_id, $plan_key]);

    try {
        addNotification(
            $conn,
            $user_id,
            "subscription",
            "Subscription cancelled",
            "You cancelled your " . $plan['name'] . " subscription."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"   => "success",
        "message"  => "Cancelled " . $plan['name'],
        "plan_key" => $plan_key,
        "active"   => false,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
