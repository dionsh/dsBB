<?php
/*
 * get_wrapped.php
 *
 * Powers "DS Banking Wrapped" — a Spotify-Wrapped-style story of the user's
 * whole money life SO FAR (not a year-end recap: it walks the ledger from the
 * account's first day up to today, so it can be opened any time).
 *
 * Request:  GET ?user_id=7
 * Response: { status: "success", wrapped: { ... } } — numbers only; all
 *           phrasing/translation happens in the app so Wrapped follows the
 *           app language.
 *
 * Reuses the spending-classification helpers from analytics_db.php
 * (analyticsCategory / analyticsIsSavingsReturn) so a transaction lands in
 * exactly the same category here as it does on the Analytics dashboard.
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "subscriptions_db.php";
require "analytics_db.php";

$user_id = (int) ($_GET["user_id"] ?? 0);

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user_id"]);
    exit();
}

try {
    // Idempotent — makes sure every optional table exists before we read it.
    ensureFeatureSchema($conn);
    ensureSubscriptionSchema($conn);

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    $accId          = (int) $account["id"];
    $currentBalance = round((float) $account["balance"], 2);
    $houseId        = (int) getHouseAccountId($conn);

    // Member since = the user row's creation date.
    $stmt = $conn->prepare("SELECT name, surname, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $userRow     = $stmt->fetch(PDO::FETCH_ASSOC);
    $memberSince = $userRow ? substr($userRow["created_at"], 0, 10) : null;
    $daysWithBank = $memberSince
        ? max(1, (int) floor((time() - strtotime($memberSince)) / 86400))
        : null;

    // partner name -> raw category (for classifying cashback purchases)
    $partnerCats = [];
    try {
        foreach ($conn->query("SELECT name, category FROM partners") as $row) {
            $partnerCats[trim($row["name"])] = $row["category"];
        }
    } catch (Exception $e) { /* partners table may not exist yet */ }

    /* ---------- walk the ENTIRE ledger, oldest first ---------- */

    $totalSent      = 0.0;  $sentCount     = 0;
    $totalReceived  = 0.0;  $receivedCount = 0;
    $p2pSentAmount  = 0.0;  $p2pSentCount  = 0;
    $p2pRecvAmount  = 0.0;  $p2pRecvCount  = 0;
    $savingsMoved   = 0.0;

    $catTotals = [];   // category => ["amount" =>, "count" =>]
    $recipients = [];  // counterparty account id => ["amount" =>, "count" =>]
    $monthStats = [];  // "Y-m" => ["count" =>, "expenses" =>]
    $weekday    = array_fill(0, 7, 0); // 0 = Monday ... 6 = Sunday
    $biggest    = null; // biggest single purchase (non-transfer spending)
    $ledger     = [];   // [ts, signed delta] for the balance-peak reconstruction

    $stmt = $conn->prepare("
        SELECT sender_account, receiver_account, amount, description, created_at
        FROM transactions
        WHERE sender_account = ? OR receiver_account = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$accId, $accId]);

    while ($tx = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amt = round((float) $tx["amount"], 2);
        if ($amt <= 0) continue;

        $ts   = strtotime($tx["created_at"]);
        $mKey = date("Y-m", $ts);
        $out  = ((int) $tx["sender_account"] === $accId);
        $counterparty = $out ? (int) $tx["receiver_account"] : (int) $tx["sender_account"];
        $isHouse      = ($counterparty === $houseId);

        $ledger[] = [$ts, $out ? -$amt : $amt];

        if ($out) {
            $cat = analyticsCategory($tx["description"], $isHouse, $partnerCats);

            if ($cat === "Savings") {
                // Moving money into savings is not spending.
                $savingsMoved += $amt;
                continue;
            }

            $totalSent += $amt;
            $sentCount++;

            if (!isset($catTotals[$cat])) $catTotals[$cat] = ["amount" => 0.0, "count" => 0];
            $catTotals[$cat]["amount"] += $amt;
            $catTotals[$cat]["count"]++;

            if (!isset($monthStats[$mKey])) $monthStats[$mKey] = ["count" => 0, "expenses" => 0.0];
            $monthStats[$mKey]["count"]++;
            $monthStats[$mKey]["expenses"] += $amt;

            $weekday[(int) date("N", $ts) - 1]++;

            if (!$isHouse) {
                $p2pSentAmount += $amt;
                $p2pSentCount++;
                if (!isset($recipients[$counterparty])) $recipients[$counterparty] = ["amount" => 0.0, "count" => 0];
                $recipients[$counterparty]["amount"] += $amt;
                $recipients[$counterparty]["count"]++;
            } elseif ($cat !== "Transfers") {
                // Biggest single purchase — house-side spending only, so a
                // plain person-to-person transfer never counts as a "purchase".
                if ($biggest === null || $amt > $biggest["amount"]) {
                    $biggest = [
                        "amount"   => $amt,
                        "label"    => trim((string) $tx["description"]) ?: $cat,
                        "category" => $cat,
                        "date"     => date("Y-m-d", $ts),
                    ];
                }
            }
        } else {
            if ($isHouse && analyticsIsSavingsReturn($tx["description"])) {
                // Our own savings coming back — not income.
                $savingsMoved -= $amt;
                continue;
            }
            $totalReceived += $amt;
            $receivedCount++;
            if (!$isHouse) {
                $p2pRecvAmount += $amt;
                $p2pRecvCount++;
            }
        }
    }

    /* ---------- phone top-ups (their own `charges` table) ---------- */

    $topUps = ["count" => 0, "amount" => 0.0];
    try {
        $stmt = $conn->prepare("SELECT amount, created_at FROM charges WHERE sender_id = ?");
        $stmt->execute([$user_id]);
        while ($ch = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $amt = round((float) $ch["amount"], 2);
            if ($amt <= 0) continue;
            $ts   = strtotime($ch["created_at"]);
            $mKey = date("Y-m", $ts);

            $topUps["count"]++;
            $topUps["amount"] += $amt;
            $totalSent += $amt;
            $sentCount++;

            if (!isset($catTotals["Top Up"])) $catTotals["Top Up"] = ["amount" => 0.0, "count" => 0];
            $catTotals["Top Up"]["amount"] += $amt;
            $catTotals["Top Up"]["count"]++;

            if (!isset($monthStats[$mKey])) $monthStats[$mKey] = ["count" => 0, "expenses" => 0.0];
            $monthStats[$mKey]["count"]++;
            $monthStats[$mKey]["expenses"] += $amt;

            $weekday[(int) date("N", $ts) - 1]++;
            $ledger[] = [$ts, -$amt];
        }
        $topUps["amount"] = round($topUps["amount"], 2);
    } catch (Exception $e) { /* charges table optional */ }

    /* ---------- split bills ---------- */

    $splitBills = ["count" => 0, "amount" => 0.0];
    if (isset($catTotals["Split Bills"])) {
        $splitBills["count"]  = $catTotals["Split Bills"]["count"];
        $splitBills["amount"] = round($catTotals["Split Bills"]["amount"], 2);
    }

    /* ---------- top categories ---------- */

    uasort($catTotals, function ($a, $b) { return $b["amount"] <=> $a["amount"]; });
    $topCategories = [];
    foreach ($catTotals as $name => $ct) {
        $topCategories[] = [
            "name"   => $name,
            "amount" => round($ct["amount"], 2),
            "count"  => $ct["count"],
            "share_pct" => $totalSent > 0 ? round($ct["amount"] / $totalSent * 100) : 0,
        ];
        if (count($topCategories) >= 5) break;
    }

    /* ---------- best friend (the person you sent the most money to) ---------- */

    $bestFriend = null;
    if (count($recipients) > 0) {
        uasort($recipients, function ($a, $b) { return $b["amount"] <=> $a["amount"]; });
        $bfAccId = array_key_first($recipients);
        $stmt = $conn->prepare("
            SELECT u.name, u.surname FROM accounts a
            JOIN users u ON a.user_id = u.id WHERE a.id = ?
        ");
        $stmt->execute([$bfAccId]);
        if ($bf = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bestFriend = [
                "name"   => trim($bf["name"] . " " . $bf["surname"]),
                "amount" => round($recipients[$bfAccId]["amount"], 2),
                "count"  => $recipients[$bfAccId]["count"],
            ];
        }
    }

    /* ---------- most active month + busiest weekday ---------- */

    $mostActiveMonth = null;
    foreach ($monthStats as $key => $m) {
        if ($mostActiveMonth === null || $m["count"] > $mostActiveMonth["tx_count"]) {
            $mostActiveMonth = [
                "month"    => (int) substr($key, 5, 2), // 1-12, app translates
                "year"     => (int) substr($key, 0, 4),
                "tx_count" => $m["count"],
                "expenses" => round($m["expenses"], 2),
            ];
        }
    }

    $busiestWeekday = null;
    $maxDay = max($weekday);
    if ($maxDay > 0) {
        $busiestWeekday = [
            "day"   => array_search($maxDay, $weekday), // 0 = Monday, app translates
            "count" => $maxDay,
        ];
    }

    /* ---------- monthly spending trend (last 6 months, oldest first) ---------- */

    $monthlyTrend = [];
    $firstOfThisMonth = strtotime(date("Y-m-01"));
    for ($i = 5; $i >= 0; $i--) {
        $ts  = strtotime("-$i months", $firstOfThisMonth);
        $key = date("Y-m", $ts);
        $monthlyTrend[] = [
            "month"    => (int) date("n", $ts),
            "expenses" => round($monthStats[$key]["expenses"] ?? 0, 2),
        ];
    }

    /* ---------- highest balance ever (reconstructed from the ledger) ---------- */

    // Walk backwards from today's balance, undoing each ledger event, and keep
    // the peak. Approximate by design (it only sees ledger rows) but honest.
    usort($ledger, function ($a, $b) { return $a[0] <=> $b[0]; });
    $running = $currentBalance;
    $peak     = $currentBalance;
    $peakTs   = time();
    for ($i = count($ledger) - 1; $i >= 0; $i--) {
        $running -= $ledger[$i][1]; // balance just BEFORE this event
        if ($running > $peak) {
            $peak = $running;
            // That balance was established by the PREVIOUS event (or on
            // day one if this was the very first ledger row).
            $peakTs = $i > 0 ? $ledger[$i - 1][0]
                             : ($memberSince ? strtotime($memberSince) : $ledger[$i][0]);
        }
    }
    $highestBalance = [
        "amount" => round($peak, 2),
        "month"  => (int) date("n", $peakTs),
        "year"   => (int) date("Y", $peakTs),
    ];

    /* ---------- cashback / rewards / savings / subscriptions ---------- */

    $cashback = ["total_earned" => 0.0, "balance" => 0.0];
    try {
        $stmt = $conn->prepare("SELECT balance, total_earned FROM cashback WHERE user_id = ?");
        $stmt->execute([$user_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cashback["balance"]      = round((float) $row["balance"], 2);
            $cashback["total_earned"] = round((float) $row["total_earned"], 2);
        }
    } catch (Exception $e) { /* cashback tables optional */ }

    $rewards = ["points" => 0, "earned_total" => 0];
    try {
        $stmt = $conn->prepare("SELECT points FROM rewards WHERE user_id = ?");
        $stmt->execute([$user_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $rewards["points"] = (int) $row["points"];
        $stmt = $conn->prepare("SELECT COALESCE(SUM(points),0) FROM reward_history WHERE user_id = ? AND points > 0");
        $stmt->execute([$user_id]);
        $rewards["earned_total"] = (int) $stmt->fetchColumn();
    } catch (Exception $e) { /* rewards tables optional */ }

    $savings = ["total" => 0.0, "balance" => 0.0, "goals_saved" => 0.0,
                "goals_count" => 0, "completed_goals" => 0, "moved_total" => round($savingsMoved, 2)];
    try {
        $stmt = $conn->prepare("SELECT balance FROM savings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $savings["balance"] = round((float) $row["balance"], 2);
    } catch (Exception $e) {}
    try {
        $stmt = $conn->prepare("
            SELECT saved_amount, target_amount, status
            FROM savings_goals WHERE user_id = ? AND status <> 'archived'
        ");
        $stmt->execute([$user_id]);
        while ($g = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $savings["goals_count"]++;
            $savings["goals_saved"] += (float) $g["saved_amount"];
            if ($g["status"] === "completed"
                || ((float) $g["target_amount"] > 0 && (float) $g["saved_amount"] >= (float) $g["target_amount"])) {
                $savings["completed_goals"]++;
            }
        }
    } catch (Exception $e) { /* goals table optional */ }
    $savings["goals_saved"] = round($savings["goals_saved"], 2);
    $savings["total"]       = round($savings["balance"] + $savings["goals_saved"], 2);

    $subscriptions = ["count" => 0, "monthly_cost" => 0.0, "top_name" => null, "top_price" => 0.0];
    try {
        $stmt = $conn->prepare("
            SELECT p.name, p.price
            FROM user_subscriptions us
            JOIN subscription_plans p ON p.plan_key = us.plan_key
            WHERE us.user_id = ? AND us.status = 'active'
            ORDER BY p.price DESC
        ");
        $stmt->execute([$user_id]);
        while ($s = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($subscriptions["count"] === 0) {
                $subscriptions["top_name"]  = $s["name"];
                $subscriptions["top_price"] = round((float) $s["price"], 2);
            }
            $subscriptions["count"]++;
            $subscriptions["monthly_cost"] += (float) $s["price"];
        }
        $subscriptions["monthly_cost"] = round($subscriptions["monthly_cost"], 2);
    } catch (Exception $e) { /* subscription tables optional */ }

    /* ---------- ship it ---------- */

    echo json_encode([
        "status"  => "success",
        "wrapped" => [
            "member_since"   => $memberSince,
            "days_with_bank" => $daysWithBank,
            "totals" => [
                "received"       => round($totalReceived, 2),
                "sent"           => round($totalSent, 2),
                "sent_count"     => $sentCount,
                "received_count" => $receivedCount,
                "tx_count"       => $sentCount + $receivedCount,
            ],
            "transfers" => [
                "sent_count"      => $p2pSentCount,
                "sent_amount"     => round($p2pSentAmount, 2),
                "received_count"  => $p2pRecvCount,
                "received_amount" => round($p2pRecvAmount, 2),
            ],
            "biggest_purchase"  => $biggest,
            "top_categories"    => $topCategories,
            "best_friend"       => $bestFriend,
            "most_active_month" => $mostActiveMonth,
            "busiest_weekday"   => $busiestWeekday,
            "monthly_trend"     => $monthlyTrend,
            "highest_balance"   => $highestBalance,
            "current_balance"   => $currentBalance,
            "cashback"          => $cashback,
            "rewards"           => $rewards,
            "savings"           => $savings,
            "subscriptions"     => $subscriptions,
            "top_ups"           => $topUps,
            "split_bills"       => $splitBills,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
