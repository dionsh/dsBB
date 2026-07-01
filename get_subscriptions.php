<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "subscriptions_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    ensureSubscriptionSchema($conn);

    // Every plan, joined with this user's status (active/inactive).
    $stmt = $conn->prepare("
        SELECT
            p.plan_key,
            p.name,
            p.price,
            p.icon,
            p.color,
            CASE WHEN us.status = 'active' THEN 1 ELSE 0 END AS active
        FROM subscription_plans p
        LEFT JOIN user_subscriptions us
            ON us.plan_key = p.plan_key AND us.user_id = ?
        ORDER BY p.sort_order ASC, p.id ASC
    ");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $plans = [];
    $activeCount = 0;
    $monthlyTotal = 0.0;
    foreach ($rows as $r) {
        $isActive = (int) $r["active"] === 1;
        if ($isActive) {
            $activeCount++;
            $monthlyTotal += (float) $r["price"];
        }
        $plans[] = [
            "plan_key" => $r["plan_key"],
            "name"     => $r["name"],
            "price"    => (float) $r["price"],
            "icon"     => $r["icon"],
            "color"    => $r["color"],
            "active"   => $isActive,
        ];
    }

    echo json_encode([
        "status"        => "success",
        "plans"         => $plans,
        "active_count"  => $activeCount,
        "monthly_total" => round($monthlyTotal, 2),
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
