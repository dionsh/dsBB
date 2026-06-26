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
$points  = intval($data['points'] ?? 0); // points the user wants to redeem

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($points <= 0) {
        throw new Exception("Enter a valid number of points");
    }
    if ($points % POINTS_PER_EUR !== 0) {
        throw new Exception("Points must be redeemed in multiples of " . POINTS_PER_EUR);
    }

    ensureFeatureSchema($conn);

    $conn->beginTransaction();

    $currentPoints = getOrCreateRewards($conn, $user_id);
    if ($currentPoints < $points) {
        throw new Exception("You only have $currentPoints points");
    }

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }

    $euros = round($points / POINTS_PER_EUR, 2);
    $houseAccountId = getHouseAccountId($conn);

    // Remove the redeemed points.
    $stmt = $conn->prepare("UPDATE rewards SET points = points - ? WHERE user_id = ?");
    $stmt->execute([$points, $user_id]);

    // Credit the cash value to the main account balance.
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$euros, $account['id']]);

    // Cash arrives from the house account so it shows in the Transactions screen.
    recordTransaction($conn, $houseAccountId, $account['id'], $euros, "Reward Points Redemption");

    $stmt = $conn->prepare("
        INSERT INTO reward_history (user_id, type, points, amount, description)
        VALUES (?, 'redeem', ?, ?, ?)
    ");
    $stmt->execute([$user_id, -$points, $euros, "Redeemed $points points for " . number_format($euros, 2) . " EUR"]);

    $newPoints = $currentPoints - $points;
    $newBalance = round($account['balance'] + $euros, 2);

    $conn->commit();

    echo json_encode([
        "status"       => "success",
        "message"      => "Redeemed $points points for " . number_format($euros, 2) . " EUR",
        "redeemed"     => $euros,
        "total_points" => $newPoints,
        "new_balance"  => $newBalance
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ]);
}
