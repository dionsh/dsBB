<?php
/*
 * get_health_score.php
 *
 * Financial Health Score (0-100) computed deterministically from the same
 * aggregates that power the Analytics dashboard (computeAnalytics in
 * analytics_db.php) — no LLM, so the score is consistent for the same data.
 *
 * Six weighted factors (max 100):
 *   spending_vs_income  25  keep spending below what comes in
 *   savings_growth      20  money actually tucked away in savings/goals
 *   saving_consistency  15  how many recent months saw money saved
 *   large_expenses      15  spending not dominated by one category
 *   subscriptions       15  subscription costs kept modest
 *   rewards_usage       10  using cashback + reward points
 *
 * Request:  GET ?user_id=7
 * Response: { status, score, band, explanation,
 *             factors: [ { key, label, icon, points, max, detail } ],
 *             suggestions: [ "..." ] }
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "analytics_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

/* Map a ratio onto [0..$max]: $good or better = full points, $bad or worse = 0. */
function healthScale($value, $good, $bad, $max) {
    if ($good === $bad) return $max;
    $t = ($value - $bad) / ($good - $bad);
    $t = max(0.0, min(1.0, $t));
    return (int) round($t * $max);
}

try {
    ensureFeatureSchema($conn);
    $a = computeAnalytics($conn, $user_id, 6);

    $s       = $a["summary"];
    $months  = $a["months"];
    $factors = [];

    $eur = function ($n) { return "€" . number_format((float) $n, 2); };

    /* ---------- 1. spending vs income (25) ---------- */

    // Average save rate over the months that actually had income.
    $rateSum = 0.0; $rateN = 0;
    foreach ($months as $m) {
        if ($m["income"] > 0) {
            $rateSum += ($m["income"] - $m["expenses"]) / $m["income"];
            $rateN++;
        }
    }
    if ($rateN > 0) {
        $avgRate = $rateSum / $rateN;                       // 1 = spent nothing, <0 = overspent
        $pts     = healthScale($avgRate, 0.30, -0.25, 25);  // keeping 30%+ = full points
        $detail  = $avgRate >= 0
            ? "On average you keep " . round($avgRate * 100) . "% of what comes in."
            : "You've been spending more than what comes in.";
    } else {
        // No income recorded — judge by whether spending is eating the balance.
        $pts    = $s["this_month_expenses"] <= 0 ? 15 : healthScale($s["balance"] > 0 ? $s["this_month_expenses"] / max(1, $s["balance"]) : 1, 0.05, 0.6, 25);
        $detail = "No income recorded recently, so we looked at spending against your balance.";
    }
    $factors[] = [
        "key" => "spending_vs_income", "label" => "Spending vs income",
        "icon" => "scale-balance", "points" => $pts, "max" => 25, "detail" => $detail,
        "tip" => "Aim to keep at least 20% of your monthly income — a small automatic saving helps.",
    ];

    /* ---------- 2. savings growth (20) ---------- */

    $savTotal = $a["savings"]["total"];
    $avgSaved = $s["avg_monthly_savings"];
    $pts = 0;
    if ($savTotal > 0)  $pts += healthScale($savTotal, 300, 0, 12);  // €300+ tucked away = full
    if ($avgSaved > 0)  $pts += healthScale($avgSaved, 40, 0, 8);    // €40+/month flow = full
    $factors[] = [
        "key" => "savings_growth", "label" => "Savings growth",
        "icon" => "piggy-bank-outline", "points" => $pts, "max" => 20,
        "detail" => $savTotal > 0
            ? "You have " . $eur($savTotal) . " in savings, adding about " . $eur(max(0, $avgSaved)) . "/month."
            : "No money in savings yet.",
        "tip" => "Increase monthly savings by €20 — Round It Up and Savings Goals make it painless.",
    ];

    /* ---------- 3. saving consistency (15) ---------- */

    $monthsWithSaving = 0;
    foreach ($months as $m) {
        if ($m["savings_moved"] > 0) $monthsWithSaving++;
    }
    $pts = healthScale($monthsWithSaving, 4, 0, 15); // saved in 4+ of the last 6 months = full
    $factors[] = [
        "key" => "saving_consistency", "label" => "Consistency of saving",
        "icon" => "calendar-check-outline", "points" => $pts, "max" => 15,
        "detail" => "You saved money in " . $monthsWithSaving . " of the last " . count($months) . " months.",
        "tip" => "Save a little every month, even €10 — consistency beats big one-off deposits.",
    ];

    /* ---------- 4. large expenses (15) ---------- */

    $tc = $s["top_category"];
    if ($s["this_month_expenses"] <= 0) {
        $pts    = 12; // nothing spent (yet) — no red flag, but no track record either
        $detail = "No spending recorded this month.";
    } elseif ($tc) {
        $pts    = healthScale($tc["share_pct"], 35, 85, 15); // one category >85% of spending = 0
        $detail = $tc["name"] . " is " . $tc["share_pct"] . "% of this month's spending (" . $eur($tc["amount"]) . ").";
    } else {
        $pts    = 15;
        $detail = "Your spending is nicely spread out.";
    }
    $factors[] = [
        "key" => "large_expenses", "label" => "Large expenses",
        "icon" => "cart-outline", "points" => $pts, "max" => 15, "detail" => $detail,
        "tip" => ($tc ? "Reduce " . $tc["name"] . " spending a little — " : "")
               . "keeping any single category under a third of your spending is a healthy balance.",
    ];

    /* ---------- 5. subscriptions (15) ---------- */

    $subCost = $a["subscriptions"]["monthly_cost"];
    $subN    = $a["subscriptions"]["active_count"];
    $base    = $s["this_month_income"] > 0 ? $s["this_month_income"] : max($s["this_month_expenses"], 100);
    if ($subN === 0) {
        $pts    = 15;
        $detail = "No active subscriptions.";
    } else {
        $pts    = healthScale($subCost / $base, 0.05, 0.30, 15); // under 5% of income = full
        $detail = $subN . " subscription" . ($subN > 1 ? "s" : "") . " costing " . $eur($subCost) . "/month ("
                . $eur(round($subCost * 12, 2)) . "/year).";
    }
    $factors[] = [
        "key" => "subscriptions", "label" => "Subscription costs",
        "icon" => "credit-card-multiple-outline", "points" => $pts, "max" => 15, "detail" => $detail,
        "tip" => "Review your subscriptions — cancelling one you barely use is the easiest saving there is.",
    ];

    /* ---------- 6. cashback + rewards usage (10) ---------- */

    $pts = 0;
    if ($a["cashback"]["total_earned"] > 0) $pts += 5;
    if ($a["rewards"]["earned_total"] > 0)  $pts += 5;
    $factors[] = [
        "key" => "rewards_usage", "label" => "Cashback & rewards",
        "icon" => "gift-outline", "points" => $pts, "max" => 10,
        "detail" => $pts === 10
            ? "You're earning both cashback and reward points. Free money!"
            : ($pts === 5 ? "You're using some of DS Banking's rewards — there's more to grab."
                          : "You haven't used cashback or rewards yet."),
        "tip" => "Use the Cashback marketplace and Wordle/Driving rewards — they turn everyday spending into free money.",
    ];

    /* ---------- total + band + suggestions ---------- */

    $score = 0;
    foreach ($factors as $f) $score += $f["points"];
    $score = max(0, min(100, $score));

    if ($score >= 80) {
        $band = "Excellent";
        $explanation = "Great job! You save consistently and keep spending under control.";
    } elseif ($score >= 65) {
        $band = "Good";
        $explanation = "You're on the right track — a few small changes would push your score higher.";
    } elseif ($score >= 50) {
        $band = "Fair";
        $explanation = "Your finances are okay, but there's clear room to build healthier habits.";
    } else {
        $band = "Needs attention";
        $explanation = "Your money habits need some care — start small and the score will follow.";
    }

    // Suggestions = tips of the weakest factors (by share of max points), max 3.
    $weak = $factors;
    usort($weak, function ($x, $y) {
        $rx = $x["max"] > 0 ? $x["points"] / $x["max"] : 1;
        $ry = $y["max"] > 0 ? $y["points"] / $y["max"] : 1;
        return $rx <=> $ry;
    });
    $suggestions = [];
    foreach ($weak as $f) {
        if (count($suggestions) >= 3) break;
        if ($f["max"] > 0 && $f["points"] / $f["max"] < 0.85) {
            $suggestions[] = $f["tip"];
        }
    }
    if (count($suggestions) === 0) {
        $suggestions[] = "Keep doing what you're doing — your money habits are in great shape.";
    }

    // The per-factor tips travel via suggestions; keep the payload lean.
    foreach ($factors as &$f) unset($f["tip"]);
    unset($f);

    echo json_encode([
        "status"      => "success",
        "score"       => $score,
        "band"        => $band,
        "explanation" => $explanation,
        "factors"     => $factors,
        "suggestions" => $suggestions,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
