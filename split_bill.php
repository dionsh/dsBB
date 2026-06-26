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

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data['user_id'] ?? null;
$total   = floatval($data['total'] ?? 0);
$people  = intval($data['people'] ?? 0);
$label   = trim($data['label'] ?? "");

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($total <= 0) {
        throw new Exception("Enter a valid bill amount");
    }
    if ($people < 2) {
        throw new Exception("There must be at least 2 people");
    }

    ensureFeatureSchema($conn);

    // Each person's share, rounded to cents.
    $share = round($total / $people, 2);

    $conn->beginTransaction();

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }

    if ($account['balance'] < $share) {
        throw new Exception("Insufficient balance for your share (" . number_format($share, 2) . " EUR)");
    }

    $houseAccountId = getHouseAccountId($conn);

    // Deduct the user's share from their balance.
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$share, $account['id']]);

    $description = $label !== "" ? "Split Bill - " . $label : "Split Bill ($people ways)";
    recordTransaction($conn, $account['id'], $houseAccountId, $share, $description);

    $newBalance = round($account['balance'] - $share, 2);

    $conn->commit();

    echo json_encode([
        "status"      => "success",
        "message"     => "Your share of " . number_format($share, 2) . " EUR was paid",
        "share"       => $share,
        "people"      => $people,
        "total"       => round($total, 2),
        "description" => $description,
        "new_balance" => $newBalance
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ]);
}
