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

$user_id  = $data['user_id'] ?? null;
$attempts = intval($data['attempts'] ?? 0); // number of guesses used to win (1-6)

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($attempts < 1 || $attempts > 6) {
        throw new Exception("Invalid number of attempts");
    }

    ensureFeatureSchema($conn);

    // Fewer guesses = bigger reward. Computed server-side so it can't be faked.
    // 1 guess -> 120, 2 -> 100, 3 -> 80, 4 -> 60, 5 -> 40, 6 -> 20.
    $pointsEarned = (7 - $attempts) * 20;

    $conn->beginTransaction();

    getOrCreateRewards($conn, $user_id);

    $stmt = $conn->prepare("UPDATE rewards SET points = points + ? WHERE user_id = ?");
    $stmt->execute([$pointsEarned, $user_id]);

    $guessWord = $attempts === 1 ? "guess" : "guesses";
    $description = "Wordle win in $attempts $guessWord";

    $stmt = $conn->prepare("
        INSERT INTO reward_history (user_id, type, points, amount, description)
        VALUES (?, 'wordle_win', ?, 0.00, ?)
    ");
    $stmt->execute([$user_id, $pointsEarned, $description]);

    $stmt = $conn->prepare("SELECT points FROM rewards WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $totalPoints = (int) $stmt->fetch(PDO::FETCH_ASSOC)['points'];

    $conn->commit();

    echo json_encode([
        "status"        => "success",
        "message"       => "You won $pointsEarned reward points!",
        "points_earned" => $pointsEarned,
        "total_points"  => $totalPoints,
        "attempts"      => $attempts
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage()
    ]);
}
