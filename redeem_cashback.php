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
require "cashback_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data['user_id'] ?? null;
$amount  = round(floatval($data['amount'] ?? 0), 2); // EUR to move to main balance

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($amount <= 0) {
        throw new Exception("Enter a valid amount");
    }

    ensureFeatureSchema($conn);
    ensureCashbackSchema($conn);

    $conn->beginTransaction();

    $wallet = getOrCreateCashback($conn, $user_id);
    $cashbackBalance = (float) $wallet['balance'];
    if ($cashbackBalance < $amount) {
        throw new Exception("You only have " . number_format($cashbackBalance, 2) . " EUR cashback");
    }

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }

    $houseAccountId = getHouseAccountId($conn);

    // Move the cashback out of the wallet and into the main account balance.
    $stmt = $conn->prepare("UPDATE cashback SET balance = balance - ? WHERE user_id = ?");
    $stmt->execute([$amount, $user_id]);

    $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$amount, $account['id']]);

    // Cash arrives from the house account so it shows in the Transactions screen.
    recordTransaction($conn, $houseAccountId, $account['id'], $amount, "Cashback Redemption");

    $newBalance = round((float) $account['balance'] + $amount, 2);
    $newCashback = round($cashbackBalance - $amount, 2);

    $conn->commit();

    echo json_encode([
        "status"           => "success",
        "message"          => "Redeemed " . number_format($amount, 2) . " EUR cashback to your balance",
        "redeemed"         => $amount,
        "new_balance"      => $newBalance,
        "cashback_balance" => $newCashback,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
