<?php
/*
 * get_invest.php
 *
 * Everything the Investment Simulator screen needs in one call.
 *
 * Request:  GET ?user_id=7&range=1M        (range: 1D | 1W | 1M, default 1M)
 * Response: {
 *   status, start_cash,
 *   wallet:    { cash },
 *   assets:    [ { key,name,symbol,icon,color,price,change_24h_pct,series } ],
 *   holdings:  [ { asset,name,symbol,icon,color,units,invested,price,value,pl,pl_pct } ],
 *   portfolio: { value,invested,cash,pl,pl_pct,series },
 *   trades:    [ { asset,name,action,units,price,amount,created_at } ]
 * }
 *
 * portfolio.series applies the user's CURRENT holdings to the price history of
 * the selected range — a "what your portfolio was worth" curve for the chart.
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "invest_db.php";

$user_id = $_GET["user_id"] ?? null;
$range   = $_GET["range"] ?? "1M";

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user_id"]);
    exit();
}

try {
    ensureInvestSchema($conn);

    $wallet = getOrCreateInvestWallet($conn, (int) $user_id);
    $now    = time();
    $assets = investAssets();

    // Asset list with current price, 24h change and the chart series.
    // Bitcoin's price is the REAL market price (investCurrentPrice); its
    // deterministic series is rescaled so the chart ends exactly at the live
    // price and stays smooth.
    $assetsOut = [];
    $priceNow  = [];
    $seriesAll = [];
    foreach ($assets as $key => $a) {
        $det  = investPriceAt($a, $now);
        $p    = investCurrentPrice($conn, $a, $now);
        $pAgo = investPriceAt($a, $now - 86400);
        $s    = investSeries($a, $range, $now);

        $isLive    = ($key === "bitcoin" && abs($p - $det) > 0.001);
        $changePct = $pAgo > 0 ? round(($det - $pAgo) / $pAgo * 100, 2) : 0;

        if ($isLive && $det > 0) {
            // Scale the whole curve so its last point equals the live price.
            $factor = $p / $det;
            foreach ($s as $i => $v) {
                $s[$i] = round($v * $factor, 2);
            }
            $live = investLiveBtc($conn);
            if ($live && $live["change_24h_pct"] !== null) {
                $changePct = $live["change_24h_pct"];
            }
        }

        $priceNow[$key]  = $p;
        $seriesAll[$key] = $s;
        $assetsOut[]     = [
            "key"            => $key,
            "name"           => $a["name"],
            "symbol"         => $a["symbol"],
            "icon"           => $a["icon"],
            "color"          => $a["color"],
            "price"          => $p,
            "change_24h_pct" => $changePct,
            "series"         => $s,
            "live"           => $isLive,
        ];
    }

    // Holdings with profit / loss.
    $holdings      = [];
    $unitsByAsset  = [];
    $investedTotal = 0.0;
    $valueTotal    = 0.0;
    $stmt = $conn->prepare("SELECT asset, units, invested FROM invest_holdings WHERE user_id = ? AND units > 0");
    $stmt->execute([$user_id]);
    while ($h = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $h["asset"];
        if (!isset($assets[$key])) continue;

        $units    = (float) $h["units"];
        $invested = round((float) $h["invested"], 2);
        $value    = round($units * $priceNow[$key], 2);
        $pl       = round($value - $invested, 2);

        $unitsByAsset[$key] = $units;
        $investedTotal     += $invested;
        $valueTotal        += $value;

        $holdings[] = [
            "asset"    => $key,
            "name"     => $assets[$key]["name"],
            "symbol"   => $assets[$key]["symbol"],
            "icon"     => $assets[$key]["icon"],
            "color"    => $assets[$key]["color"],
            "units"    => round($units, 8),
            "invested" => $invested,
            "price"    => $priceNow[$key],
            "value"    => $value,
            "pl"       => $pl,
            "pl_pct"   => $invested > 0 ? round($pl / $invested * 100, 2) : 0,
        ];
    }

    // Portfolio value curve: current holdings valued along the range's history.
    $portfolioSeries = [];
    $pointCount = 0;
    foreach ($seriesAll as $s) { $pointCount = max($pointCount, count($s)); }
    for ($i = 0; $i < $pointCount; $i++) {
        $v = $wallet["cash"];
        foreach ($unitsByAsset as $key => $units) {
            $v += $units * ($seriesAll[$key][$i] ?? end($seriesAll[$key]));
        }
        $portfolioSeries[] = round($v, 2);
    }

    $plTotal = round($valueTotal - $investedTotal, 2);

    // Recent trades (newest first).
    $trades = [];
    $stmt = $conn->prepare("
        SELECT asset, action, units, price, amount, created_at
        FROM invest_trades WHERE user_id = ?
        ORDER BY created_at DESC, id DESC LIMIT 20
    ");
    $stmt->execute([$user_id]);
    while ($t = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $t["asset"];
        $trades[] = [
            "asset"      => $key,
            "name"       => $assets[$key]["name"] ?? $key,
            "action"     => $t["action"],
            "units"      => round((float) $t["units"], 8),
            "price"      => round((float) $t["price"], 2),
            "amount"     => round((float) $t["amount"], 2),
            "created_at" => $t["created_at"],
        ];
    }

    echo json_encode([
        "status"     => "success",
        "start_cash" => INVEST_START_CASH,
        "wallet"     => $wallet,
        "assets"     => $assetsOut,
        "holdings"   => $holdings,
        "portfolio"  => [
            "value"    => round($wallet["cash"] + $valueTotal, 2),
            "invested" => round($investedTotal, 2),
            "cash"     => $wallet["cash"],
            "pl"       => $plTotal,
            "pl_pct"   => $investedTotal > 0 ? round($plTotal / $investedTotal * 100, 2) : 0,
            "series"   => $portfolioSeries,
        ],
        "trades"     => $trades,
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
