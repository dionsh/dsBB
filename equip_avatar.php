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
require "avatar_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data['user_id'] ?? null;
$config  = $data['config'] ?? null;

/* Accept only well-formed hex colours; fall back to the default otherwise. */
function cleanColor($value, $fallback) {
    if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{3,8}$/', $value)) {
        return strtoupper($value);
    }
    return $fallback;
}

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if (!is_array($config)) {
        throw new Exception("Missing avatar configuration");
    }

    ensureFeatureSchema($conn);
    ensureAvatarSchema($conn);

    $defaults = avatarDefaultLook();

    // Style choices are validated against what the user actually owns, so the
    // client can never equip a locked item it didn't buy.
    $slots = [
        "hair"  => $config["hair_style"]  ?? $defaults["hair_style"],
        "shirt" => $config["shirt_style"] ?? $defaults["shirt_style"],
        "pants" => $config["pants_style"] ?? $defaults["pants_style"],
        "shoes" => $config["shoe_style"]  ?? $defaults["shoe_style"],
    ];
    foreach ($slots as $slot => $style) {
        if (!userOwnsStyle($conn, $user_id, $slot, $style)) {
            throw new Exception("You don't own the selected $slot item");
        }
    }

    // Colours and skin are free customisation — just sanitised.
    $skin       = cleanColor($config["skin"]        ?? null, $defaults["skin"]);
    $hairColor  = cleanColor($config["hair_color"]  ?? null, $defaults["hair_color"]);
    $shirtColor = cleanColor($config["shirt_color"] ?? null, $defaults["shirt_color"]);
    $pantsColor = cleanColor($config["pants_color"] ?? null, $defaults["pants_color"]);
    $shoeColor  = cleanColor($config["shoe_color"]  ?? null, $defaults["shoe_color"]);

    // Make sure a row exists, then save the look (single upsert).
    getOrCreateUserAvatar($conn, $user_id);

    $stmt = $conn->prepare("
        UPDATE user_avatar SET
            skin = ?,
            hair_style = ?,  hair_color = ?,
            shirt_style = ?, shirt_color = ?,
            pants_style = ?, pants_color = ?,
            shoe_style = ?,  shoe_color = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE user_id = ?
    ");
    $stmt->execute([
        $skin,
        $slots["hair"],  $hairColor,
        $slots["shirt"], $shirtColor,
        $slots["pants"], $pantsColor,
        $slots["shoes"], $shoeColor,
        $user_id,
    ]);

    echo json_encode([
        "status"   => "success",
        "message"  => "Look saved",
        "equipped" => [
            "skin"        => $skin,
            "hair_style"  => $slots["hair"],  "hair_color"  => $hairColor,
            "shirt_style" => $slots["shirt"], "shirt_color" => $shirtColor,
            "pants_style" => $slots["pants"], "pants_color" => $pantsColor,
            "shoe_style"  => $slots["shoes"], "shoe_color"  => $shoeColor,
        ],
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage(),
    ]);
}
