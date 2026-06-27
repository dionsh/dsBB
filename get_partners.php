<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "cashback_db.php";

$user_id = $_GET["user_id"] ?? null;

try {
    ensureCashbackSchema($conn);
    seedPartners($conn);

    // Active partner offers.
    $stmt = $conn->query("
        SELECT id, name, category, description, icon, brand_color, image_url,
               price, cashback_percent
        FROM partners
        WHERE active = 1
        ORDER BY id ASC
    ");
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cashbackBalance = 0;
    $totalEarned = 0;
    $purchases = [];

    // If a user is provided, also return their wallet + purchase history.
    if ($user_id) {
        $wallet = getOrCreateCashback($conn, $user_id);
        $cashbackBalance = (float) $wallet["balance"];
        $totalEarned = (float) $wallet["total_earned"];

        $stmt = $conn->prepare("
            SELECT id, partner_id, partner_name, price, cashback_amount, created_at
            FROM partner_purchases
            WHERE user_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 50
        ");
        $stmt->execute([$user_id]);
        $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        "status"           => "success",
        "partners"         => $partners,
        "cashback_balance" => round($cashbackBalance, 2),
        "total_earned"     => round($totalEarned, 2),
        "purchases"        => $purchases,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
