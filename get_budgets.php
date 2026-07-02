<?php
/*
 * get_budgets.php
 *
 * Everything the Budget Planner screen needs in one call.
 *
 * Request:  GET ?user_id=7&month=2026-07     (month optional, default = now)
 * Response: {
 *   status, month, month_label,
 *   categories: [ { key,label,icon,color } ],          // catalog for the picker
 *   budgets:    [ { id,category,label,icon,color,limit_amount,spent,remaining,pct } ],
 *   unbudgeted: [ { category,label,icon,color,spent } ],
 *   totals:     { limit, spent, remaining, pct }
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "analytics_db.php";
require "budgets_db.php";

$user_id = $_GET["user_id"] ?? null;
$month   = budgetMonthKey($_GET["month"] ?? "");

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    ensureFeatureSchema($conn);

    $overview = budgetOverview($conn, (int) $user_id, $month);

    echo json_encode(array_merge(["status" => "success"], $overview));

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
