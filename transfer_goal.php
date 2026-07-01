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
require "notifications_db.php";

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
    if ($saved <= 0) {
        throw new Exception("This goal has no savings to transfer");
    }

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }

    $houseAccountId = getHouseAccountId($conn);

    // Move the goal's savings back into the main balance.
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$saved, $account['id']]);
    recordTransaction($conn, $houseAccountId, $account['id'], $saved, "Savings Goal Withdrawal - " . $goal['name']);

    // Archive the goal (money returned, goal closed).
    $stmt = $conn->prepare("UPDATE savings_goals SET saved_amount = 0.00, status = 'archived' WHERE id = ?");
    $stmt->execute([$goal['id']]);

    $newBalance = round((float) $account['balance'] + $saved, 2);

    $conn->commit();

    try {
        addNotification(
            $conn,
            $user_id,
            "savings",
            "Savings transferred",
            "Transferred " . number_format($saved, 2) . " EUR from '" . $goal['name'] . "' to your balance."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"      => "success",
        "message"     => "Transferred " . number_format($saved, 2) . " EUR to your balance",
        "transferred" => $saved,
        "new_balance" => $newBalance,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
