<?php
/*
 * check_budget.php
 *
 * Pre-flight budget check used by the spending screens BEFORE they commit a
 * purchase (top-up, transfer, cashback buy, subscription, round-up, split
 * bill). Given a category and the amount about to be spent, it reports whether
 * that spend would push the user OVER the monthly budget they set for that
 * category in the Budget Planner — so the app can ask
 * "are you sure you want to go over budget?" and let the user decide.
 *
 * It never moves money and never writes anything. It reads the exact same
 * budgets + real-spending model the Budget Planner uses (budgets_db.php), so
 * the numbers here always match what the planner shows.
 *
 * Request (POST JSON; GET params also accepted):
 *   { "user_id": 7, "category": "Top Up", "amount": 3, "month": "2026-07" }
 *   month is optional and defaults to the current month.
 *
 * Response:
 *   {
 *     status: "success",
 *     has_budget: true,        // false when no budget is set for the category
 *     over: true,              // true when spent + amount > limit
 *     category, label,
 *     limit, spent,
 *     remaining,               // limit - spent (can be negative)
 *     amount, projected,       // projected = spent + amount
 *     overspend                // projected - limit (0 when not over)
 *   }
 */

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
require "analytics_db.php";
require "budgets_db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = [];
}

$user_id  = $data["user_id"] ?? ($_GET["user_id"] ?? null);
$category = trim((string) ($data["category"] ?? ($_GET["category"] ?? "")));
$amount   = (float) ($data["amount"] ?? ($_GET["amount"] ?? 0));
$month    = budgetMonthKey($data["month"] ?? ($_GET["month"] ?? ""));

try {
    if (!$user_id) {
        throw new Exception("Missing user ID");
    }
    if ($category === "") {
        throw new Exception("Missing category");
    }

    $amount = round($amount, 2);

    ensureFeatureSchema($conn);
    ensureBudgetsSchema($conn);

    $catalog = budgetCategories();
    $label   = $catalog[$category]["label"] ?? $category;

    // The monthly limit the user set for this category (if any).
    $stmt = $conn->prepare("
        SELECT limit_amount FROM budgets
        WHERE user_id = ? AND month = ? AND category = ?
        LIMIT 1
    ");
    $stmt->execute([(int) $user_id, $month, $category]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // No budget for this category -> there is nothing to warn about.
    if (!$row) {
        echo json_encode([
            "status"     => "success",
            "has_budget" => false,
            "over"       => false,
            "category"   => $category,
            "label"      => $label,
            "amount"     => $amount,
        ]);
        exit;
    }

    $limit = round((float) $row["limit_amount"], 2);

    // Real spending already booked in this category this month (same rules as
    // the Budget Planner / Analytics dashboard).
    $spend = budgetSpendingForMonth($conn, (int) $user_id, $month);
    $spent = round((float) ($spend[$category] ?? 0), 2);

    $projected = round($spent + $amount, 2);
    $remaining = round($limit - $spent, 2);
    $overspend = round($projected - $limit, 2);
    // Small epsilon so a spend that lands exactly on the limit is not "over".
    $over = $projected > $limit + 0.005;

    echo json_encode([
        "status"     => "success",
        "has_budget" => true,
        "over"       => $over,
        "category"   => $category,
        "label"      => $label,
        "limit"      => $limit,
        "spent"      => $spent,
        "remaining"  => $remaining,
        "amount"     => $amount,
        "projected"  => $projected,
        "overspend"  => $overspend > 0 ? $overspend : 0,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
