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
$amount  = round(floatval($data['amount'] ?? 0), 2); // EUR moved from balance into the goal

try {
    if (!$user_id || !$goal_id) {
        throw new Exception("Missing goal");
    }
    if ($amount <= 0) {
        throw new Exception("Enter a valid amount");
    }

    ensureFeatureSchema($conn);
    ensureGoalsSchema($conn);

    $conn->beginTransaction();

    $goal = getGoalForUser($conn, $user_id, $goal_id);
    if (!$goal) {
        throw new Exception("Goal not found");
    }
    if ($goal['status'] !== 'active') {
        throw new Exception("This goal is already completed");
    }

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    if ((float) $account['balance'] < $amount) {
        throw new Exception("Insufficient balance");
    }

    $houseAccountId = getHouseAccountId($conn);

    // Money leaves the main balance and is tracked in the goal.
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$amount, $account['id']]);
    recordTransaction($conn, $account['id'], $houseAccountId, $amount, "Savings Goal - " . $goal['name']);

    $newSaved = round((float) $goal['saved_amount'] + $amount, 2);
    $target   = (float) $goal['target_amount'];
    $completed = $newSaved >= $target;

    if ($completed) {
        $stmt = $conn->prepare("
            UPDATE savings_goals
            SET saved_amount = ?, status = 'completed', completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newSaved, $goal['id']]);
    } else {
        $stmt = $conn->prepare("UPDATE savings_goals SET saved_amount = ? WHERE id = ?");
        $stmt->execute([$newSaved, $goal['id']]);
    }

    $newBalance = round((float) $account['balance'] - $amount, 2);

    $conn->commit();

    // Celebrate reaching the goal in the inbox (don't break the action on failure).
    if ($completed) {
        try {
            addNotification(
                $conn,
                $user_id,
                "savings",
                "Savings goal reached",
                "🎉 Congratulations! You have reached your savings goal: " . $goal['name'] . "."
            );
        } catch (Exception $e) {
            // ignore notification errors
        }
    }

    // Return the fresh goal so the UI can update immediately.
    $stmt = $conn->prepare("
        SELECT id, name, description, icon, target_amount, saved_amount, status, created_at, completed_at
        FROM savings_goals WHERE id = ?
    ");
    $stmt->execute([$goal['id']]);
    $freshGoal = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status"      => "success",
        "message"     => "Added " . number_format($amount, 2) . " EUR to " . $goal['name'],
        "completed"   => $completed,
        "new_balance" => $newBalance,
        "goal"        => $freshGoal,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
