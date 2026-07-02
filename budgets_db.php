<?php
/*
 * budgets_db.php
 *
 * Shared helpers for the Budget Planner feature. Included by the budget
 * endpoints (get_budgets, set_budget, delete_budget) and by nova_actions.php
 * (so NOVA can report the user's budget status).
 *
 * A budget is a monthly spending limit for one category. Categories are the
 * SAME ones the Analytics dashboard derives from the real transaction ledger
 * (analyticsCategory in analytics_db.php), so "spent so far" is always the
 * user's REAL spending — no mock data and no double bookkeeping.
 *
 * Like the other *_db.php helpers this creates its table idempotently with
 * CREATE TABLE IF NOT EXISTS, so no manual migration is needed.
 *
 * Requires config.php ($conn), feature_db.php (getUserAccountId /
 * getHouseAccountId) and analytics_db.php (analyticsCategory) to have been
 * included.
 */

function ensureBudgetsSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS budgets (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            month CHAR(7) NOT NULL,                 -- 'YYYY-MM'
            category VARCHAR(40) NOT NULL,          -- analytics category key
            limit_amount DECIMAL(12,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_budget (user_id, month, category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/*
 * The categories a budget can be set for. Keys MUST match the names produced
 * by analyticsCategory() so real spending maps onto the budgets 1:1. The
 * label/icon/color are what the app renders.
 */
function budgetCategories() {
    return [
        "Restaurants"      => ["key" => "Restaurants",      "label" => "Food & Dining",        "icon" => "silverware-fork-knife",          "color" => "#FF7043"],
        "Shopping"         => ["key" => "Shopping",         "label" => "Shopping",             "icon" => "shopping-outline",               "color" => "#AB47BC"],
        "Subscriptions"    => ["key" => "Subscriptions",    "label" => "Bills & Subscriptions","icon" => "credit-card-multiple-outline",   "color" => "#42A5F5"],
        "Entertainment"    => ["key" => "Entertainment",    "label" => "Entertainment",        "icon" => "movie-open-outline",             "color" => "#EC407A"],
        "Card Purchases"   => ["key" => "Card Purchases",   "label" => "Card Purchases",       "icon" => "credit-card-outline",            "color" => "#FFA726"],
        "Transfers"        => ["key" => "Transfers",        "label" => "Transfers",            "icon" => "bank-transfer",                  "color" => "#607D8B"],
        "Top Up"           => ["key" => "Top Up",           "label" => "Phone Top-Ups",        "icon" => "phone-plus",                     "color" => "#7E57C2"],
        "Split Bills"      => ["key" => "Split Bills",      "label" => "Split Bills",          "icon" => "call-split",                     "color" => "#29B6F6"],
        "Electronics"      => ["key" => "Electronics",      "label" => "Electronics",          "icon" => "laptop",                         "color" => "#5C6BC0"],
        "Clothing"         => ["key" => "Clothing",         "label" => "Clothing",             "icon" => "tshirt-crew-outline",            "color" => "#26A69A"],
        "Hotels"           => ["key" => "Hotels",           "label" => "Travel & Hotels",      "icon" => "bed-outline",                    "color" => "#8D6E63"],
        "Health & Fitness" => ["key" => "Health & Fitness", "label" => "Health & Fitness",     "icon" => "dumbbell",                       "color" => "#66BB6A"],
    ];
}

/* Validate a 'YYYY-MM' month key; fall back to the current month. */
function budgetMonthKey($raw) {
    $m = trim((string) $raw);
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
        return $m;
    }
    return date("Y-m");
}

/*
 * Real spending per category for one month, classified exactly like the
 * Analytics dashboard (same ledger walk, same rules). Money moved into
 * savings/shared goals is NOT spending and is skipped.
 * Returns [category => amount].
 */
function budgetSpendingForMonth($conn, $user_id, $monthKey) {
    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    $accId   = (int) $account["id"];
    $houseId = (int) getHouseAccountId($conn);

    $start = $monthKey . "-01 00:00:00";
    $end   = date("Y-m-d 00:00:00", strtotime($monthKey . "-01 +1 month"));

    // partner name -> raw category (for classifying cashback purchases)
    $partnerCats = [];
    try {
        foreach ($conn->query("SELECT name, category FROM partners") as $row) {
            $partnerCats[trim($row["name"])] = $row["category"];
        }
    } catch (Exception $e) { /* partners table may not exist yet */ }

    $spend = [];

    $stmt = $conn->prepare("
        SELECT receiver_account, amount, description
        FROM transactions
        WHERE sender_account = ? AND created_at >= ? AND created_at < ?
    ");
    $stmt->execute([$accId, $start, $end]);
    while ($tx = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amt = round((float) $tx["amount"], 2);
        if ($amt <= 0) continue;

        $isHouse = ((int) $tx["receiver_account"] === $houseId);
        $cat = analyticsCategory($tx["description"], $isHouse, $partnerCats);
        if ($cat === "Savings") continue; // saving money is not spending

        $spend[$cat] = ($spend[$cat] ?? 0) + $amt;
    }

    // Phone top-ups live in their own `charges` table.
    try {
        $stmt = $conn->prepare("
            SELECT amount FROM charges
            WHERE sender_id = ? AND created_at >= ? AND created_at < ?
        ");
        $stmt->execute([$user_id, $start, $end]);
        while ($ch = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $amt = round((float) $ch["amount"], 2);
            if ($amt <= 0) continue;
            $spend["Top Up"] = ($spend["Top Up"] ?? 0) + $amt;
        }
    } catch (Exception $e) { /* charges table optional */ }

    foreach ($spend as $k => $v) {
        $spend[$k] = round($v, 2);
    }
    return $spend;
}

/*
 * Everything the Budget Planner screen needs for one month: the user's budgets
 * merged with their real spending, categories that have spending but no budget
 * yet, and the totals. Also used by NOVA's "budget status" action.
 */
function budgetOverview($conn, $user_id, $monthKey) {
    ensureBudgetsSchema($conn);

    $catalog = budgetCategories();
    $spend   = budgetSpendingForMonth($conn, $user_id, $monthKey);

    $stmt = $conn->prepare("
        SELECT id, category, limit_amount
        FROM budgets
        WHERE user_id = ? AND month = ?
        ORDER BY limit_amount DESC, id ASC
    ");
    $stmt->execute([$user_id, $monthKey]);

    $budgets     = [];
    $budgetedCat = [];
    $totalLimit  = 0.0;
    $totalSpent  = 0.0;

    while ($b = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cat  = $b["category"];
        $meta = $catalog[$cat] ?? ["key" => $cat, "label" => $cat, "icon" => "shape-outline", "color" => "#78909C"];

        $limit = round((float) $b["limit_amount"], 2);
        $spent = round((float) ($spend[$cat] ?? 0), 2);

        $budgetedCat[$cat] = true;
        $totalLimit += $limit;
        $totalSpent += $spent;

        $budgets[] = [
            "id"           => (int) $b["id"],
            "category"     => $cat,
            "label"        => $meta["label"],
            "icon"         => $meta["icon"],
            "color"        => $meta["color"],
            "limit_amount" => $limit,
            "spent"        => $spent,
            "remaining"    => round($limit - $spent, 2),
            "pct"          => $limit > 0 ? round($spent / $limit * 100, 1) : 0,
        ];
    }

    // Spending in categories the user has not budgeted (so nothing is hidden).
    $unbudgeted = [];
    foreach ($spend as $cat => $amt) {
        if (isset($budgetedCat[$cat]) || $amt <= 0) continue;
        $meta = $catalog[$cat] ?? ["key" => $cat, "label" => $cat, "icon" => "shape-outline", "color" => "#78909C"];
        $unbudgeted[] = [
            "category" => $cat,
            "label"    => $meta["label"],
            "icon"     => $meta["icon"],
            "color"    => $meta["color"],
            "spent"    => round($amt, 2),
        ];
    }
    usort($unbudgeted, function ($a, $b) { return $b["spent"] <=> $a["spent"]; });

    return [
        "month"       => $monthKey,
        "month_label" => date("F Y", strtotime($monthKey . "-01")),
        "categories"  => array_values($catalog),
        "budgets"     => $budgets,
        "unbudgeted"  => $unbudgeted,
        "totals"      => [
            "limit"     => round($totalLimit, 2),
            "spent"     => round($totalSpent, 2),
            "remaining" => round($totalLimit - $totalSpent, 2),
            "pct"       => $totalLimit > 0 ? round($totalSpent / $totalLimit * 100, 1) : 0,
        ],
    ];
}
