<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "avatar_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    ensureFeatureSchema($conn);   // rewards table (points balance)
    ensureAvatarSchema($conn);    // catalog + ownership + equipped look

    $points   = getOrCreateRewards($conn, $user_id);
    $equipped = getOrCreateUserAvatar($conn, $user_id);
    $catalog  = getAvatarCatalog($conn, $user_id);

    echo json_encode([
        "status"         => "success",
        "points"         => $points,
        "points_per_eur" => POINTS_PER_EUR,
        "equipped"       => $equipped,
        "catalog"        => $catalog,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
