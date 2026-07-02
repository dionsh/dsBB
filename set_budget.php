<?php
/*
 * set_budget.php
 *
 * Create or update a monthly category budget (upsert).
 *
 * Request (POST JSON):
 *   { "user_id": 7, "month": "2026-07", "category": "Restaurants", "limit_amount": 250 }
 *
 * Response: { status, message } — the app reloads via get_budgets.php after.
 */

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
require "analytics_db.php";
require "budgets_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id  = $data["user_id"] ?? null;
$month    = budgetMonthKey($data["month"] ?? "");
$category = trim($data["category"] ?? "");
$limit    = round(floatval($data["limit_amount"] ?? 0), 2);

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    $catalog = budgetCategories();
    if ($category === "" || !isset($catalog[$category])) {
        throw new Exception("Unknown budget category");
    }
    if ($limit <= 0) {
        throw new Exception("Please enter a valid limit amount");
    }
    if ($limit > 1000000) {
        throw new Exception("That limit is too high");
    }

    ensureBudgetsSchema($conn);

    $stmt = $conn->prepare("
        INSERT INTO budgets (user_id, month, category, limit_amount)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE limit_amount = VALUES(limit_amount)
    ");
    $stmt->execute([$user_id, $month, $category, $limit]);

    echo json_encode([
        "status"  => "success",
        "message" => $catalog[$category]["label"] . " budget set to €" . number_format($limit, 2),
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
