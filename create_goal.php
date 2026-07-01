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

$data = json_decode(file_get_contents("php://input"), true);

$user_id     = $data['user_id'] ?? null;
$name        = trim($data['name'] ?? "");
$description = trim($data['description'] ?? "");
$icon        = trim($data['icon'] ?? "target");
$target      = round(floatval($data['target_amount'] ?? 0), 2);

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($name === "") {
        throw new Exception("Please enter a goal name");
    }
    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
    }
    if ($target <= 0) {
        throw new Exception("Please enter a valid target amount");
    }
    if ($icon === "") {
        $icon = "target";
    }

    ensureGoalsSchema($conn);

    $stmt = $conn->prepare("
        INSERT INTO savings_goals (user_id, name, description, icon, target_amount, saved_amount, status)
        VALUES (?, ?, ?, ?, ?, 0.00, 'active')
    ");
    $stmt->execute([
        $user_id,
        $name,
        $description !== "" ? $description : null,
        $icon,
        $target,
    ]);
    $goalId = (int) $conn->lastInsertId();

    $stmt = $conn->prepare("
        SELECT id, name, description, icon, target_amount, saved_amount, status, created_at, completed_at
        FROM savings_goals WHERE id = ?
    ");
    $stmt->execute([$goalId]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status"  => "success",
        "message" => "Goal created",
        "goal"    => $goal,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
