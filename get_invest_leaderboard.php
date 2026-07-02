<?php
/*
 * get_invest_leaderboard.php
 *
 * Ranking of Investment Simulator players by REAL portfolio data in MySQL:
 * portfolio value = virtual cash + holdings valued at the current prices
 * (Bitcoin at the live market price). Profit % is measured against the
 * €10,000 everyone starts with. Only users who actually traded are ranked.
 *
 * Request:  GET ?user_id=7
 * Response: {
 *   status, start_cash,
 *   leaders: [ { rank, user_id, name, value, profit, profit_pct, trades, is_me } ],
 *   me:      { rank, value, profit_pct, ranked } // ranked=false -> no trades yet
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "invest_db.php";

$user_id = (int) ($_GET["user_id"] ?? 0);

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user_id"]);
    exit();
}

try {
    ensureInvestSchema($conn);

    $now    = time();
    $assets = investAssets();

    // Current price per asset (Bitcoin = live market price).
    $priceNow = [];
    foreach ($assets as $key => $a) {
        $priceNow[$key] = investCurrentPrice($conn, $a, $now);
    }

    // Every wallet with its owner's name.
    $wallets = [];
    $stmt = $conn->query("
        SELECT w.user_id, w.cash, u.name, u.surname
        FROM invest_wallets w
        JOIN users u ON u.id = w.user_id
    ");
    while ($w = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $wallets[(int) $w["user_id"]] = [
            "cash"    => round((float) $w["cash"], 2),
            "name"    => trim($w["name"]),
            "surname" => trim((string) $w["surname"]),
            "value"   => round((float) $w["cash"], 2),
            "trades"  => 0,
        ];
    }

    // Value the holdings at the current prices.
    $stmt = $conn->query("SELECT user_id, asset, units FROM invest_holdings WHERE units > 0");
    while ($h = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $uid = (int) $h["user_id"];
        $key = $h["asset"];
        if (!isset($wallets[$uid]) || !isset($priceNow[$key])) continue;
        $wallets[$uid]["value"] += (float) $h["units"] * $priceNow[$key];
    }

    // Only players who actually traded belong on the board.
    $stmt = $conn->query("SELECT user_id, COUNT(*) AS c FROM invest_trades GROUP BY user_id");
    while ($t = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $uid = (int) $t["user_id"];
        if (isset($wallets[$uid])) {
            $wallets[$uid]["trades"] = (int) $t["c"];
        }
    }

    $players = [];
    foreach ($wallets as $uid => $w) {
        if ($w["trades"] < 1) continue;
        $value = round($w["value"], 2);
        $players[] = [
            "user_id"    => $uid,
            // "Dion S." — first name + surname initial, kept a bit private.
            "name"       => $w["name"] . ($w["surname"] !== "" ? " " . mb_substr($w["surname"], 0, 1) . "." : ""),
            "value"      => $value,
            "profit"     => round($value - INVEST_START_CASH, 2),
            "profit_pct" => round(($value - INVEST_START_CASH) / INVEST_START_CASH * 100, 2),
            "trades"     => $w["trades"],
        ];
    }

    usort($players, function ($a, $b) { return $b["value"] <=> $a["value"]; });

    $me = ["rank" => null, "value" => null, "profit_pct" => null, "ranked" => false];
    $leaders = [];
    foreach ($players as $i => $p) {
        $rank = $i + 1;
        if ($p["user_id"] === $user_id) {
            $me = ["rank" => $rank, "value" => $p["value"], "profit_pct" => $p["profit_pct"], "ranked" => true];
        }
        if ($rank <= 10) {
            $p["rank"]  = $rank;
            $p["is_me"] = ($p["user_id"] === $user_id);
            $leaders[]  = $p;
        }
    }

    echo json_encode([
        "status"     => "success",
        "start_cash" => INVEST_START_CASH,
        "leaders"    => $leaders,
        "me"         => $me,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
