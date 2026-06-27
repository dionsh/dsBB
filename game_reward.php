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
$level   = intval($data['level'] ?? 0); // the level the player just cleared (1-5)

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($level < 1 || $level > 5) {
        throw new Exception("Invalid level");
    }

    ensureFeatureSchema($conn);

    // Points scale with the level cleared; finishing the final level adds a bonus.
    // Computed server-side so it can't be faked from the client.
    // level 1 -> 10, 2 -> 20, 3 -> 30, 4 -> 40, 5 -> 50 (+50 completion bonus).
    $pointsEarned = $level * 10;
    if ($level >= 5) {
        $pointsEarned += 50;
    }

    $description = $level >= 5
        ? "Driving game completed - all levels"
        : "Driving game - level $level cleared";

    $conn->beginTransaction();

    getOrCreateRewards($conn, $user_id);

    $stmt = $conn->prepare("UPDATE rewards SET points = points + ? WHERE user_id = ?");
    $stmt->execute([$pointsEarned, $user_id]);

    $stmt = $conn->prepare("
        INSERT INTO reward_history (user_id, type, points, amount, description)
        VALUES (?, 'driving_win', ?, 0.00, ?)
    ");
    $stmt->execute([$user_id, $pointsEarned, $description]);

    $stmt = $conn->prepare("SELECT points FROM rewards WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $totalPoints = (int) $stmt->fetch(PDO::FETCH_ASSOC)['points'];

    $conn->commit();

    echo json_encode([
        "status"        => "success",
        "message"       => "You earned $pointsEarned reward points!",
        "points_earned" => $pointsEarned,
        "total_points"  => $totalPoints,
        "level"         => $level
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ]);
}
