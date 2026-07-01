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
require "goals_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data['user_id'] ?? null;
$goal_id = $data['goal_id'] ?? null;

try {
    if (!$user_id || !$goal_id) {
        throw new Exception("Missing goal");
    }

    ensureFeatureSchema($conn);
    ensureGoalsSchema($conn);

    $conn->beginTransaction();

    $goal = getGoalForUser($conn, $user_id, $goal_id);
    if (!$goal) {
        throw new Exception("Goal not found");
    }

    $saved = round((float) $goal['saved_amount'], 2);
    $newBalance = null;

    // If the goal still holds money, return it to the balance before deleting
    // so the user never loses funds.
    if ($saved > 0) {
        $account = getUserAccountId($conn, $user_id);
        if (!$account) {
            throw new Exception("User account not found");
        }
        $houseAccountId = getHouseAccountId($conn);

        $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$saved, $account['id']]);
        recordTransaction($conn, $houseAccountId, $account['id'], $saved, "Savings Goal Refund - " . $goal['name']);

        $newBalance = round((float) $account['balance'] + $saved, 2);
    }

    $stmt = $conn->prepare("DELETE FROM savings_goals WHERE id = ? AND user_id = ?");
    $stmt->execute([$goal['id'], $user_id]);

    $conn->commit();

    echo json_encode([
        "status"      => "success",
        "message"     => $saved > 0
            ? "Goal deleted. " . number_format($saved, 2) . " EUR returned to your balance."
            : "Goal deleted",
        "refunded"    => $saved,
        "new_balance" => $newBalance,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
