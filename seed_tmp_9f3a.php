<?php
/*
 * seed_tmp_9f3a.php  —  TEMPORARY one-off top-up for a demo test account.
 *
 * Adds a fixed amount of demo balance + reward points to a single account,
 * identified by email. Protected by a secret key and restricted to POST so it
 * can't be triggered accidentally. THIS FILE IS DELETED right after it runs.
 *
 * Usage:  POST /seed_tmp_9f3a.php?key=<SECRET>
 */

header("Content-Type: application/json");

// --- guardrails -------------------------------------------------------------
$SECRET = "S3ed_9F3a7B2c8D1e_kX";
if (($_GET["key"] ?? "") !== $SECRET) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "forbidden"]);
    exit;
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "POST only"]);
    exit;
}

// --- fixed target + amounts -------------------------------------------------
$TARGET_EMAIL = "dionnn@gmail.com";
$ADD_BALANCE  = 2100.00;
$ADD_POINTS   = 1000;

require "config.php";
require "feature_db.php";

try {
    ensureFeatureSchema($conn);

    // Locate the user + their account.
    $stmt = $conn->prepare("SELECT id, name, surname FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$TARGET_EMAIL]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(["status" => "error", "message" => "No account found for $TARGET_EMAIL — sign up in the app first."]);
        exit;
    }
    $userId  = (int) $user["id"];
    $account = getUserAccountId($conn, $userId);
    if (!$account) {
        echo json_encode(["status" => "error", "message" => "User has no account row."]);
        exit;
    }

    $beforeBalance = (float) $account["balance"];
    $beforePoints  = getOrCreateRewards($conn, $userId);

    $conn->beginTransaction();

    // 1) Balance: credit the user and record it as a House -> user transaction
    //    so it shows up in the Transactions history (double-entry kept balanced).
    $house = getHouseAccountId($conn);
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$ADD_BALANCE, $account["id"]]);
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$ADD_BALANCE, $house]);
    recordTransaction($conn, $house, $account["id"], $ADD_BALANCE, "Account top-up");

    // 2) Points: add to the rewards balance + log it in reward_history.
    $stmt = $conn->prepare("UPDATE rewards SET points = points + ? WHERE user_id = ?");
    $stmt->execute([$ADD_POINTS, $userId]);
    $stmt = $conn->prepare("
        INSERT INTO reward_history (user_id, type, points, amount, description)
        VALUES (?, 'bonus', ?, 0.00, 'Manual top-up')
    ");
    $stmt->execute([$userId, $ADD_POINTS]);

    $conn->commit();

    // Read back the new values.
    $stmt = $conn->prepare("SELECT balance FROM accounts WHERE id = ?");
    $stmt->execute([$account["id"]]);
    $afterBalance = (float) $stmt->fetchColumn();
    $afterPoints  = getOrCreateRewards($conn, $userId);

    echo json_encode([
        "status"  => "success",
        "user"    => trim($user["name"] . " " . $user["surname"]) . " (id $userId)",
        "balance" => ["before" => $beforeBalance, "added" => $ADD_BALANCE, "after" => $afterBalance],
        "points"  => ["before" => $beforePoints,  "added" => $ADD_POINTS,  "after" => $afterPoints],
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
