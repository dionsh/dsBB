<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require "config.php";
require "currency_db.php";

$user_id = $_GET['user_id'] ?? null;

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    ensureCurrencySchema($conn);

    $stmt = $conn->prepare("
        SELECT from_code, to_code, rate, fee_percent,
               amount_from, fee_from, amount_received, created_at
        FROM currency_conversions
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status"      => "success",
        "fee_percent" => CURRENCY_FEE_PCT,
        "conversions" => $rows,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
