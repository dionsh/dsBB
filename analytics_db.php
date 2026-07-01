<?php
/*
 * analytics_db.php
 *
 * Shared aggregation helpers for the Spending Analytics dashboard and the
 * AI Financial Coach. Included by get_analytics.php, nova_coach.php and
 * nova_chat.php (for spending-aware NOVA answers).
 *
 * Everything here is computed deterministically in PHP from MySQL — the LLM
 * never invents numbers, it only phrases them. Data sources:
 *   - transactions   (transfers + all feature ledger rows via the house account)
 *   - charges        (phone top-ups)
 *   - partner_purchases / partners / cashback (cashback marketplace)
 *   - rewards / reward_history               (points)
 *   - savings / savings_goals                (savings + goals)
 *   - user_subscriptions / subscription_plans
 *
 * Requires config.php ($conn) and feature_db.php (getUserAccountId,
 * getHouseAccountId) to have been included.
 */

/* Friendly display names for the partner marketplace categories. */
function analyticsPartnerCategoryLabel($category) {
    $map = [
        "Restaurant"  => "Restaurants",
        "Hotel"       => "Hotels",
        "Festival"    => "Entertainment",
        "Electronics" => "Electronics",
        "Clothing"    => "Clothing",
        "Gym"         => "Health & Fitness",
    ];
    return $map[$category] ?? "Shopping";
}

/*
 * Classify one OUTGOING transaction into a spending category.
 * $isHouse = the counterparty is the hidden "DS Banking House" account
 * (feature transactions); otherwise it is a real person-to-person transfer.
 */
function analyticsCategory($desc, $isHouse, $partnerCats) {
    $d = trim((string) $desc);

    if (stripos($d, "Subscription - ") === 0) return "Subscriptions";

    if (stripos($d, "Cashback Purchase - ") === 0) {
        // "Cashback Purchase - NAME (Ticket XXX-YYYYYY)"
        $name = substr($d, strlen("Cashback Purchase - "));
        $pos  = strrpos($name, " (Ticket");
        if ($pos !== false) $name = substr($name, 0, $pos);
        $cat = $partnerCats[trim($name)] ?? null;
        return $cat !== null ? analyticsPartnerCategoryLabel($cat) : "Shopping";
    }

    if (stripos($d, "Split Bill") === 0) return "Split Bills";

    // Money moved into savings (round-ups + goal deposits) — kept out of the
    // "expenses" totals and shown in the Savings growth section instead.
    if (stripos($d, "Savings Goal") === 0)    return "Savings";
    if (stripos($d, "Round Up Savings") === 0) return "Savings";

    if (!$isHouse) return "Transfers";

    // Round It Up purchases carry a free-text label (e.g. "Coffee") and any
    // other house-side debits land here.
    return "Card Purchases";
}

/* True when an INCOMING house transaction is just our own savings coming back. */
function analyticsIsSavingsReturn($desc) {
    $d = trim((string) $desc);
    return stripos($d, "Savings Goal Refund") === 0
        || stripos($d, "Savings Goal Withdrawal") === 0;
}

/*
 * The main aggregation. Returns a big associative array (see the "shape"
 * comments inline) that get_analytics.php serves verbatim and the coach
 * turns into insights.
 */
function computeAnalytics($conn, $user_id, $monthsBack = 6) {
    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    $accId   = (int) $account["id"];
    $houseId = (int) getHouseAccountId($conn);

    // partner name -> raw category (for classifying cashback purchases)
    $partnerCats = [];
    try {
        foreach ($conn->query("SELECT name, category FROM partners") as $row) {
            $partnerCats[trim($row["name"])] = $row["category"];
        }
    } catch (Exception $e) { /* partners table may not exist yet */ }

    /* ---------- month + week buckets ---------- */

    $firstOfThisMonth = strtotime(date("Y-m-01 00:00:00"));
    $months = [];          // key "Y-m" => bucket, oldest -> newest
    for ($i = $monthsBack - 1; $i >= 0; $i--) {
        $ts  = strtotime("-$i months", $firstOfThisMonth);
        $key = date("Y-m", $ts);
        $months[$key] = [
            "key"           => $key,
            "label"         => date("M", $ts),
            "income"        => 0.0,
            "expenses"      => 0.0,
            "savings_moved" => 0.0, // net flow INTO savings during the month
        ];
    }
    $monthKeys = array_keys($months);
    $thisKey   = end($monthKeys);
    $prevKey   = count($monthKeys) > 1 ? $monthKeys[count($monthKeys) - 2] : null;

    $weeksBack = 8;
    $today     = strtotime(date("Y-m-d 00:00:00"));
    $dow       = (int) date("N", $today);                 // 1 = Monday
    $thisWeek  = $today - ($dow - 1) * 86400;
    $weeks     = [];       // key "Y-m-d" (Monday) => bucket, oldest -> newest
    for ($i = $weeksBack - 1; $i >= 0; $i--) {
        $ws = $thisWeek - $i * 7 * 86400;
        $weeks[date("Y-m-d", $ws)] = [
            "label"    => date("j M", $ws),
            "expenses" => 0.0,
        ];
    }
    $oldestNeeded = min(strtotime($monthKeys[0] . "-01"), $thisWeek - ($weeksBack - 1) * 7 * 86400);
    $startSql     = date("Y-m-d 00:00:00", $oldestNeeded);

    // Per-category spending for the current + previous month.
    $catNow  = [];
    $catPrev = [];

    /* ---------- walk the ledger ---------- */

    $stmt = $conn->prepare("
        SELECT sender_account, receiver_account, amount, description, created_at
        FROM transactions
        WHERE (sender_account = ? OR receiver_account = ?) AND created_at >= ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$accId, $accId, $startSql]);

    while ($tx = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amt = round((float) $tx["amount"], 2);
        if ($amt <= 0) continue;

        $ts   = strtotime($tx["created_at"]);
        $mKey = date("Y-m", $ts);
        $out  = ((int) $tx["sender_account"] === $accId);
        $counterparty = $out ? (int) $tx["receiver_account"] : (int) $tx["sender_account"];
        $isHouse      = ($counterparty === $houseId);

        if ($out) {
            $cat = analyticsCategory($tx["description"], $isHouse, $partnerCats);

            if ($cat === "Savings") {
                if (isset($months[$mKey])) $months[$mKey]["savings_moved"] += $amt;
                continue; // moving money to savings is not spending
            }

            if (isset($months[$mKey])) $months[$mKey]["expenses"] += $amt;

            $d  = strtotime(date("Y-m-d 00:00:00", $ts));
            $wn = (int) date("N", $d);
            $wKey = date("Y-m-d", $d - ($wn - 1) * 86400);
            if (isset($weeks[$wKey])) $weeks[$wKey]["expenses"] += $amt;

            if ($mKey === $thisKey)      $catNow[$cat]  = ($catNow[$cat] ?? 0) + $amt;
            elseif ($mKey === $prevKey)  $catPrev[$cat] = ($catPrev[$cat] ?? 0) + $amt;
        } else {
            if ($isHouse && analyticsIsSavingsReturn($tx["description"])) {
                // Our own savings coming back — not income.
                if (isset($months[$mKey])) $months[$mKey]["savings_moved"] -= $amt;
                continue;
            }
            if (isset($months[$mKey])) $months[$mKey]["income"] += $amt;
        }
    }

    /* ---------- phone top-ups live in their own `charges` table ---------- */

    try {
        $stmt = $conn->prepare("
            SELECT amount, created_at FROM charges
            WHERE sender_id = ? AND created_at >= ?
        ");
        $stmt->execute([$user_id, $startSql]);
        while ($ch = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $amt = round((float) $ch["amount"], 2);
            if ($amt <= 0) continue;
            $ts   = strtotime($ch["created_at"]);
            $mKey = date("Y-m", $ts);
            if (isset($months[$mKey])) $months[$mKey]["expenses"] += $amt;

            $d  = strtotime(date("Y-m-d 00:00:00", $ts));
            $wn = (int) date("N", $d);
            $wKey = date("Y-m-d", $d - ($wn - 1) * 86400);
            if (isset($weeks[$wKey])) $weeks[$wKey]["expenses"] += $amt;

            if ($mKey === $thisKey)     $catNow["Top Up"]  = ($catNow["Top Up"] ?? 0) + $amt;
            elseif ($mKey === $prevKey) $catPrev["Top Up"] = ($catPrev["Top Up"] ?? 0) + $amt;
        }
    } catch (Exception $e) { /* charges table optional */ }

    /* ---------- cashback ---------- */

    $cashback = ["total_earned" => 0.0, "balance" => 0.0, "monthly" => []];
    try {
        $stmt = $conn->prepare("SELECT balance, total_earned FROM cashback WHERE user_id = ?");
        $stmt->execute([$user_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cashback["balance"]      = round((float) $row["balance"], 2);
            $cashback["total_earned"] = round((float) $row["total_earned"], 2);
        }
        $byMonth = [];
        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') mk, SUM(cashback_amount) total
            FROM partner_purchases WHERE user_id = ? AND created_at >= ?
            GROUP BY mk
        ");
        $stmt->execute([$user_id, $startSql]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $byMonth[$r["mk"]] = (float) $r["total"];
        foreach ($months as $key => $m) {
            $cashback["monthly"][] = ["label" => $m["label"], "amount" => round($byMonth[$key] ?? 0, 2)];
        }
    } catch (Exception $e) { /* cashback tables optional */ }

    /* ---------- reward points ---------- */

    $rewards = ["points" => 0, "earned_total" => 0, "monthly" => []];
    try {
        $stmt = $conn->prepare("SELECT points FROM rewards WHERE user_id = ?");
        $stmt->execute([$user_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $rewards["points"] = (int) $row["points"];

        $stmt = $conn->prepare("SELECT COALESCE(SUM(points),0) FROM reward_history WHERE user_id = ? AND points > 0");
        $stmt->execute([$user_id]);
        $rewards["earned_total"] = (int) $stmt->fetchColumn();

        $byMonth = [];
        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') mk, SUM(points) total
            FROM reward_history WHERE user_id = ? AND points > 0 AND created_at >= ?
            GROUP BY mk
        ");
        $stmt->execute([$user_id, $startSql]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $byMonth[$r["mk"]] = (int) $r["total"];
        foreach ($months as $key => $m) {
            $rewards["monthly"][] = ["label" => $m["label"], "points" => $byMonth[$key] ?? 0];
        }
    } catch (Exception $e) { /* rewards tables optional */ }

    /* ---------- savings (balance + goals + growth curve) ---------- */

    $savings = ["balance" => 0.0, "goals_saved" => 0.0, "total" => 0.0, "monthly" => [], "goals" => []];
    try {
        $stmt = $conn->prepare("SELECT balance FROM savings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $savings["balance"] = round((float) $row["balance"], 2);
    } catch (Exception $e) {}
    try {
        $stmt = $conn->prepare("
            SELECT name, saved_amount, target_amount, status
            FROM savings_goals WHERE user_id = ? AND status <> 'archived'
        ");
        $stmt->execute([$user_id]);
        while ($g = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $savings["goals"][] = [
                "name"   => $g["name"],
                "saved"  => round((float) $g["saved_amount"], 2),
                "target" => round((float) $g["target_amount"], 2),
                "status" => $g["status"],
            ];
            $savings["goals_saved"] += (float) $g["saved_amount"];
        }
    } catch (Exception $e) { /* goals table optional */ }
    $savings["goals_saved"] = round($savings["goals_saved"], 2);
    $savings["total"]       = round($savings["balance"] + $savings["goals_saved"], 2);

    // Growth curve: walk backwards from today's total using each month's net flow.
    $running = $savings["total"];
    $curve   = [];
    foreach (array_reverse($monthKeys) as $key) {
        $curve[$key] = max(0, round($running, 2));
        $running    -= $months[$key]["savings_moved"];
    }
    foreach ($months as $key => $m) {
        $savings["monthly"][] = ["label" => $m["label"], "balance" => $curve[$key] ?? 0];
    }

    /* ---------- subscriptions ---------- */

    $subs = ["active_count" => 0, "monthly_cost" => 0.0, "items" => []];
    try {
        $stmt = $conn->prepare("
            SELECT p.name, p.price, p.icon, p.color
            FROM user_subscriptions us
            JOIN subscription_plans p ON p.plan_key = us.plan_key
            WHERE us.user_id = ? AND us.status = 'active'
            ORDER BY p.price DESC
        ");
        $stmt->execute([$user_id]);
        while ($s = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $subs["items"][] = [
                "name"  => $s["name"],
                "price" => round((float) $s["price"], 2),
                "icon"  => $s["icon"],
                "color" => $s["color"],
            ];
            $subs["monthly_cost"] += (float) $s["price"];
        }
        $subs["active_count"] = count($subs["items"]);
        $subs["monthly_cost"] = round($subs["monthly_cost"], 2);
    } catch (Exception $e) { /* subscription tables optional */ }

    /* ---------- category lists + summary ---------- */

    arsort($catNow);
    arsort($catPrev);
    $categories = [];
    foreach ($catNow as $name => $amount) {
        $categories[] = [
            "name"        => $name,
            "amount"      => round($amount, 2),
            "prev_amount" => round($catPrev[$name] ?? 0, 2),
        ];
    }
    $categoriesPrev = [];
    foreach ($catPrev as $name => $amount) {
        $categoriesPrev[] = ["name" => $name, "amount" => round($amount, 2)];
    }

    $thisM = $months[$thisKey];
    $prevM = $prevKey ? $months[$prevKey] : null;

    $expNow  = round($thisM["expenses"], 2);
    $expPrev = $prevM ? round($prevM["expenses"], 2) : 0.0;
    $changePct = $expPrev > 0 ? round((($expNow - $expPrev) / $expPrev) * 100) : null;

    $topCategory = null;
    if (count($categories) > 0) {
        $tc = $categories[0];
        $topCategory = [
            "name"        => $tc["name"],
            "amount"      => $tc["amount"],
            "prev_amount" => $tc["prev_amount"],
            "change_pct"  => $tc["prev_amount"] > 0
                ? round((($tc["amount"] - $tc["prev_amount"]) / $tc["prev_amount"]) * 100)
                : null,
            "share_pct"   => $expNow > 0 ? round($tc["amount"] / $expNow * 100) : 0,
        ];
    }

    // Average net savings per month over the previous 3 full months (for goal ETAs).
    $flowSum = 0.0; $flowN = 0;
    for ($i = count($monthKeys) - 2; $i >= 0 && $flowN < 3; $i--) {
        $flowSum += $months[$monthKeys[$i]]["savings_moved"];
        $flowN++;
    }
    $avgMonthlySavings = $flowN > 0 ? round($flowSum / $flowN, 2) : 0.0;

    $daysElapsed = max(1, (int) date("j"));

    $summary = [
        "month_label"          => date("F"),
        "this_month_expenses"  => $expNow,
        "last_month_expenses"  => $expPrev,
        "expenses_change_pct"  => $changePct,
        "this_month_income"    => round($thisM["income"], 2),
        "net"                  => round($thisM["income"] - $expNow, 2),
        "avg_daily_spend"      => round($expNow / $daysElapsed, 2),
        "top_category"         => $topCategory,
        "avg_monthly_savings"  => $avgMonthlySavings,
        "balance"              => round((float) $account["balance"], 2),
    ];

    return [
        "months"          => array_values($months),
        "weeks"           => array_values($weeks),
        "categories"      => $categories,
        "categories_prev" => $categoriesPrev,
        "cashback"        => $cashback,
        "rewards"         => $rewards,
        "savings"         => $savings,
        "subscriptions"   => $subs,
        "summary"         => $summary,
    ];
}

/*
 * Deterministic coach insights, computed 100% in PHP so the coach works even
 * with no LLM configured. Each insight carries a MaterialCommunityIcons name
 * the app renders directly.
 */
function analyticsInsights($a) {
    $s        = $a["summary"];
    $insights = [];
    $eur      = function ($n) { return "€" . number_format((float) $n, 2); };

    // 1) Spending this month vs last month
    $text = "You've spent " . $eur($s["this_month_expenses"]) . " so far in " . $s["month_label"] . ".";
    if ($s["expenses_change_pct"] !== null) {
        $pct  = $s["expenses_change_pct"];
        $text .= $pct >= 0
            ? " That's " . abs($pct) . "% more than last month — keep an eye on it."
            : " That's " . abs($pct) . "% less than last month. Nice discipline!";
    }
    $insights[] = ["type" => "spending", "icon" => "chart-line", "title" => "Monthly spending", "text" => $text];

    // 2) Top category
    if (!empty($s["top_category"])) {
        $tc   = $s["top_category"];
        $text = "Your biggest spending category this month is " . $tc["name"] . " with "
              . $eur($tc["amount"]) . " (" . $tc["share_pct"] . "% of your spending).";
        if ($tc["change_pct"] !== null) {
            $text .= $tc["change_pct"] >= 0
                ? " That's " . abs($tc["change_pct"]) . "% higher than last month."
                : " That's " . abs($tc["change_pct"]) . "% lower than last month.";
        }
        $insights[] = ["type" => "spending", "icon" => "shape-outline", "title" => "Top category", "text" => $text];
    }

    // 3) Subscriptions audit
    $subs = $a["subscriptions"];
    if ($subs["active_count"] > 0) {
        $yearly = round($subs["monthly_cost"] * 12, 2);
        $text   = "Your " . $subs["active_count"] . " subscription" . ($subs["active_count"] > 1 ? "s" : "")
                . " cost " . $eur($subs["monthly_cost"]) . "/month (" . $eur($yearly) . "/year).";
        if (count($subs["items"]) > 1) {
            $big  = $subs["items"][0]; // sorted by price DESC
            $save = round($big["price"] * 12, 2);
            $text .= " Cancelling " . $big["name"] . " alone would save you " . $eur($save) . " a year.";
        }
        $insights[] = ["type" => "budget", "icon" => "credit-card-multiple-outline", "title" => "Subscription check", "text" => $text];
    }

    // 4) Savings goal ETA
    $avg  = $s["avg_monthly_savings"];
    $goal = null;
    foreach ($a["savings"]["goals"] as $g) {
        if ($g["status"] === "active" && $g["target"] > $g["saved"]) { $goal = $g; break; }
    }
    if ($goal && $avg > 0) {
        $remaining  = $goal["target"] - $goal["saved"];
        $monthsLeft = (int) ceil($remaining / $avg);
        $eta        = date("F Y", strtotime("+" . $monthsLeft . " months"));
        $insights[] = [
            "type"  => "saving",
            "icon"  => "piggy-bank-outline",
            "title" => "Goal forecast",
            "text"  => "If you keep saving about " . $eur($avg) . "/month, you'll reach your \""
                     . $goal["name"] . "\" goal (" . $eur($remaining) . " to go) by " . $eta . ".",
        ];
    } elseif ($goal) {
        $insights[] = [
            "type"  => "saving",
            "icon"  => "piggy-bank-outline",
            "title" => "Goal nudge",
            "text"  => "Your \"" . $goal["name"] . "\" goal still needs " . $eur($goal["target"] - $goal["saved"])
                     . ". Try Round It Up or a small weekly deposit to get it moving.",
        ];
    } elseif ($a["savings"]["total"] > 0) {
        $insights[] = [
            "type"  => "saving",
            "icon"  => "piggy-bank-outline",
            "title" => "Savings",
            "text"  => "You have " . $eur($a["savings"]["total"]) . " tucked away in savings. Setting a named goal makes it easier to stay motivated.",
        ];
    }

    // 5) Budget recommendation from the 3-month average
    $mo = $a["months"];
    $n  = count($mo);
    $histAvg = 0; $histN = 0;
    for ($i = max(0, $n - 4); $i < $n - 1; $i++) { $histAvg += $mo[$i]["expenses"]; $histN++; }
    if ($histN > 0 && $histAvg > 0) {
        $histAvg = $histAvg / $histN;
        $budget  = round($histAvg * 0.9, 0);
        $insights[] = [
            "type"  => "budget",
            "icon"  => "bullseye-arrow",
            "title" => "Budget suggestion",
            "text"  => "Your average spending over the last months is " . $eur(round($histAvg, 2))
                     . "/month. Try a budget of " . $eur($budget) . " next month — about 10% less.",
        ];
    }

    // 6) Cash flow this month
    if ($s["net"] >= 0 && $s["this_month_income"] > 0) {
        $rate = round($s["net"] / $s["this_month_income"] * 100);
        $insights[] = [
            "type"  => "cashflow",
            "icon"  => "trending-up",
            "title" => "Cash flow",
            "text"  => "You've kept " . $eur($s["net"]) . " of what came in this month (a " . $rate . "% save rate). Anything above 20% is excellent.",
        ];
    } elseif ($s["net"] < 0) {
        $insights[] = [
            "type"  => "cashflow",
            "icon"  => "alert-circle-outline",
            "title" => "Cash flow",
            "text"  => "You've spent " . $eur(abs($s["net"])) . " more than you received this month. Reviewing your top category is the fastest fix.",
        ];
    }

    // 7) Cashback + points sitting unredeemed
    $cbBal = $a["cashback"]["balance"];
    $pts   = $a["rewards"]["points"];
    if ($cbBal > 0 || $pts > 0) {
        $parts = [];
        if ($cbBal > 0) $parts[] = $eur($cbBal) . " cashback";
        if ($pts > 0)   $parts[] = $pts . " reward points (≈" . $eur($pts / 100) . ")";
        $insights[] = [
            "type"  => "rewards",
            "icon"  => "gift-outline",
            "title" => "Free money waiting",
            "text"  => "You have " . implode(" and ", $parts) . " ready to redeem to your balance.",
        ];
    }

    return $insights;
}

/*
 * A compact plain-text snapshot of the aggregates. This is what gets appended
 * to the LLM system prompt — aggregated amounts only, never card numbers,
 * account numbers or other sensitive identifiers.
 */
function analyticsFactsText($a) {
    $s = $a["summary"];
    $lines = [];
    $lines[] = "Month: " . $s["month_label"];
    $lines[] = "Spent this month: EUR " . $s["this_month_expenses"]
             . " | last month: EUR " . $s["last_month_expenses"]
             . ($s["expenses_change_pct"] !== null ? " (change " . $s["expenses_change_pct"] . "%)" : "");
    $lines[] = "Income this month: EUR " . $s["this_month_income"] . " | net: EUR " . $s["net"];
    $lines[] = "Average daily spend: EUR " . $s["avg_daily_spend"];

    if (count($a["categories"]) > 0) {
        $cats = [];
        foreach (array_slice($a["categories"], 0, 5) as $c) {
            $cats[] = $c["name"] . " EUR " . $c["amount"] . " (last month EUR " . $c["prev_amount"] . ")";
        }
        $lines[] = "Spending by category this month: " . implode("; ", $cats);
    }

    $subs = $a["subscriptions"];
    if ($subs["active_count"] > 0) {
        $names = array_map(function ($i) { return $i["name"] . " EUR " . $i["price"]; }, $subs["items"]);
        $lines[] = "Active subscriptions (" . $subs["active_count"] . ", EUR " . $subs["monthly_cost"]
                 . "/month): " . implode(", ", $names);
    } else {
        $lines[] = "Active subscriptions: none";
    }

    $lines[] = "Savings total: EUR " . $a["savings"]["total"]
             . " (avg saved per month recently: EUR " . $s["avg_monthly_savings"] . ")";
    foreach (array_slice($a["savings"]["goals"], 0, 3) as $g) {
        $lines[] = "Goal \"" . $g["name"] . "\": EUR " . $g["saved"] . " of EUR " . $g["target"] . " (" . $g["status"] . ")";
    }

    $lines[] = "Cashback earned total: EUR " . $a["cashback"]["total_earned"]
             . " (unredeemed: EUR " . $a["cashback"]["balance"] . ")";
    $lines[] = "Reward points: " . $a["rewards"]["points"] . " (100 points = EUR 1)";

    return implode("\n", $lines);
}
