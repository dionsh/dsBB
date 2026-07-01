<?php
/*
 * get_analytics.php
 *
 * Powers the Spending Analytics dashboard.
 *
 * Request:  GET ?user_id=7
 * Response: { status: "success", analytics: { months, weeks, categories,
 *             categories_prev, cashback, rewards, savings, subscriptions,
 *             summary } }
 *
 * All numbers are aggregated server-side in analytics_db.php.
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "subscriptions_db.php";
require "analytics_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user_id"]);
    exit();
}

try {
    // Idempotent — makes sure every table the aggregation touches exists.
    ensureFeatureSchema($conn);
    ensureSubscriptionSchema($conn);

    $analytics = computeAnalytics($conn, (int) $user_id);

    echo json_encode([
        "status"    => "success",
        "analytics" => $analytics,
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
