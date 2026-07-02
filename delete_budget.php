<?php
/*
 * delete_budget.php
 *
 * Remove one category budget.
 *
 * Request (POST JSON): { "user_id": 7, "budget_id": 12 }
 * Response: { status, message }
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

$user_id   = $data["user_id"] ?? null;
$budget_id = $data["budget_id"] ?? null;

try {
    if (!$user_id || !$budget_id) {
        throw new Exception("Missing budget");
    }

    ensureBudgetsSchema($conn);

    $stmt = $conn->prepare("DELETE FROM budgets WHERE id = ? AND user_id = ?");
    $stmt->execute([$budget_id, $user_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Budget not found");
    }

    echo json_encode(["status" => "success", "message" => "Budget removed"]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
