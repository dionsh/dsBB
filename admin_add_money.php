<?php
/*
 * admin_add_money.php  — TEMPORARY one-shot helper.
 *
 * Adds a FIXED amount to a FIXED account (by email), gated by a secret key.
 * This file is deployed only long enough to run once, then removed. It cannot
 * be used to target any other account or amount.
 */
header("Content-Type: application/json");

require "config.php";
require "feature_db.php"; // getHouseAccountId + recordTransaction

$SECRET = "DSB_1SHOT_9f2a7c4e8b1d63a5_add1200_noid";
$TARGET_EMAIL = "noid@gmail.com";
$AMOUNT = 1200.00;

// Accept the key from query string or JSON body.
$provided = $_GET["key"] ?? "";
$body = json_decode(file_get_contents("php://input"), true);
if (is_array($body) && isset($body["key"])) {
    $provided = $body["key"];
}

if (!hash_equals($SECRET, (string) $provided)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Forbidden"]);
    exit;
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        SELECT a.id, a.balance, u.name, u.surname
        FROM accounts a
        JOIN users u ON u.id = a.user_id
        WHERE u.email = ?
        LIMIT 1
    ");
    $stmt->execute([$TARGET_EMAIL]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$acc) {
        throw new Exception("No account found for email " . $TARGET_EMAIL);
    }

    $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$AMOUNT, $acc["id"]]);

    // Also record it as an incoming transaction so it shows in the app.
    try {
        $house = getHouseAccountId($conn);
        recordTransaction($conn, $house, $acc["id"], $AMOUNT, "Account Top-Up");
    } catch (Exception $e) {
        // ignore — the balance update is what matters
    }

    $newBalance = round((float) $acc["balance"] + $AMOUNT, 2);
    $conn->commit();

    echo json_encode([
        "status"      => "success",
        "email"       => $TARGET_EMAIL,
        "name"        => $acc["name"] . " " . $acc["surname"],
        "added"       => $AMOUNT,
        "old_balance" => round((float) $acc["balance"], 2),
        "new_balance" => $newBalance,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
