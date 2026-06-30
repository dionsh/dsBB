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

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data["user_id"] ?? null;
$pin = trim($data["pin"] ?? "");

if (!$user_id || !$pin) {
    echo json_encode(["status" => "error", "message" => "Missing fields"]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT pin FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["status" => "error", "message" => "User not found"]);
        exit;
    }

    if (!password_verify($pin, $user["pin"])) {
        echo json_encode(["status" => "error", "message" => "Wrong PIN"]);
        exit;
    }

    echo json_encode(["status" => "success", "message" => "PIN verified"]);

} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
