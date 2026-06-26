<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    ensureFeatureSchema($conn);

    $points = getOrCreateRewards($conn, $user_id);

    $stmt = $conn->prepare("
        SELECT id, type, points, amount, description, created_at
        FROM reward_history
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute([$user_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status"          => "success",
        "points"          => $points,
        "points_per_eur"  => POINTS_PER_EUR,
        "history"         => $history
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
