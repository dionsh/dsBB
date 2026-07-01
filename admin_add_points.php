<?php
/*
 * admin_add_points.php  — TEMPORARY one-shot helper.
 *
 * Adds a FIXED number of reward points to a FIXED account (by email), gated by
 * a secret key. Points live in the `rewards` table — the same balance games
 * award and the character shop spends. Deployed only long enough to run once,
 * then removed. Cannot target any other account or amount.
 */
header("Content-Type: application/json");

require "config.php";
require "feature_db.php"; // ensureFeatureSchema + getOrCreateRewards

$SECRET = "DSB_1SHOT_3e7b1f9a2c8d64e5_pts700_noid";
$TARGET_EMAIL = "noid@gmail.com";
$POINTS = 700;

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
    ensureFeatureSchema($conn);

    $conn->beginTransaction();

    $stmt = $conn->prepare("SELECT id, name, surname FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$TARGET_EMAIL]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception("No account found for email " . $TARGET_EMAIL);
    }
    $userId = (int) $user["id"];

    // Ensure a rewards row exists, then add the points.
    $currentPoints = getOrCreateRewards($conn, $userId);

    $stmt = $conn->prepare("UPDATE rewards SET points = points + ? WHERE user_id = ?");
    $stmt->execute([$POINTS, $userId]);

    // Log it in the reward history so it shows in the Rewards screen.
    $stmt = $conn->prepare("
        INSERT INTO reward_history (user_id, type, points, amount, description)
        VALUES (?, 'bonus', ?, 0.00, 'Bonus points')
    ");
    $stmt->execute([$userId, $POINTS]);

    $newPoints = $currentPoints + $POINTS;
    $conn->commit();

    echo json_encode([
        "status"       => "success",
        "email"        => $TARGET_EMAIL,
        "name"         => $user["name"] . " " . $user["surname"],
        "added_points" => $POINTS,
        "old_points"   => $currentPoints,
        "new_points"   => $newPoints,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
