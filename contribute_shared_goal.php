<?php
/*
 * contribute_shared_goal.php
 *
 * "Add Money" — move money from the member's real account balance into the
 * shared savings group. Recorded in the transactions ledger (against the house
 * account) exactly like personal savings goals, so Transactions and Analytics
 * stay consistent.
 *
 * Request (POST JSON): { "user_id": 7, "goal_id": 3, "amount": 50 }
 * Response: { status, message, new_balance, completed, goal: {...} }
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
require "shared_goals_db.php";
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data["user_id"] ?? null;
$goal_id = $data["goal_id"] ?? null;
$amount  = round(floatval($data["amount"] ?? 0), 2);

try {
    if (!$user_id || !$goal_id) {
        throw new Exception("Missing group");
    }
    if ($amount <= 0) {
        throw new Exception("Enter a valid amount");
    }

    ensureFeatureSchema($conn);
    ensureSharedGoalsSchema($conn);

    $conn->beginTransaction();

    $goal = getSharedGoal($conn, $goal_id);
    if (!$goal) {
        throw new Exception("Group not found");
    }
    if ($goal["status"] !== "active") {
        throw new Exception("This group already reached its goal");
    }

    $me = getSharedMember($conn, $goal_id, $user_id);
    if (!$me || $me["status"] !== "active") {
        throw new Exception("You are not a member of this group");
    }

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    if ((float) $account["balance"] < $amount) {
        throw new Exception("Insufficient balance");
    }

    $houseAccountId = getHouseAccountId($conn);

    // Money leaves the main balance and is tracked in the group.
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$amount, $account["id"]]);
    recordTransaction($conn, $account["id"], $houseAccountId, $amount, "Shared Goal - " . $goal["name"]);

    $stmt = $conn->prepare("
        INSERT INTO shared_goal_contributions (goal_id, user_id, amount)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$goal_id, $user_id, $amount]);

    $newCurrent = round((float) $goal["current_amount"] + $amount, 2);
    $target     = (float) $goal["target_amount"];
    $completed  = $newCurrent >= $target;

    if ($completed) {
        $stmt = $conn->prepare("
            UPDATE shared_goals
            SET current_amount = ?, status = 'completed', completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newCurrent, $goal_id]);
    } else {
        $stmt = $conn->prepare("UPDATE shared_goals SET current_amount = ? WHERE id = ?");
        $stmt->execute([$newCurrent, $goal_id]);
    }

    $newBalance = round((float) $account["balance"] - $amount, 2);

    $conn->commit();

    // Tell the other members what happened (never break the action on failure).
    try {
        $myName = sharedUserName($conn, $user_id);
        $stmt = $conn->prepare("
            SELECT user_id FROM shared_goal_members
            WHERE goal_id = ? AND status = 'active' AND user_id <> ?
        ");
        $stmt->execute([$goal_id, $user_id]);
        while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($completed) {
                addNotification(
                    $conn,
                    (int) $m["user_id"],
                    "shared_goal",
                    "Group goal reached",
                    "🎉 \"" . $goal["name"] . "\" reached its goal of " . number_format($target, 2) . " EUR!"
                );
            } else {
                addNotification(
                    $conn,
                    (int) $m["user_id"],
                    "shared_goal",
                    "Group contribution",
                    $myName . " added " . number_format($amount, 2) . " EUR to \"" . $goal["name"] . "\"."
                );
            }
        }
        if ($completed) {
            addNotification(
                $conn,
                (int) $user_id,
                "shared_goal",
                "Group goal reached",
                "🎉 \"" . $goal["name"] . "\" reached its goal of " . number_format($target, 2) . " EUR!"
            );
        }
    } catch (Exception $e) {
        // ignore notification errors
    }

    $freshGoal = getSharedGoal($conn, $goal_id);

    echo json_encode([
        "status"      => "success",
        "message"     => "Added " . number_format($amount, 2) . " EUR to " . $goal["name"],
        "completed"   => $completed,
        "new_balance" => $newBalance,
        "goal"        => $freshGoal,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
