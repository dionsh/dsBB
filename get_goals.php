<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "goals_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    ensureGoalsSchema($conn);

    // Show active + completed goals; archived ones (already transferred out) are hidden.
    $stmt = $conn->prepare("
        SELECT id, name, description, icon, target_amount, saved_amount, status, created_at, completed_at
        FROM savings_goals
        WHERE user_id = ? AND status <> 'archived'
        ORDER BY (status = 'completed') ASC, created_at DESC, id DESC
    ");
    $stmt->execute([$user_id]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "goals"  => $goals,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
