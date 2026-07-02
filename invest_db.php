<?php
/*
 * invest_db.php
 *
 * Shared helpers for the Investment Simulator. Included by get_invest.php and
 * invest_trade.php.
 *
 * IMPORTANT: this is a SIMULATION. It never touches the user's real balance —
 * every user gets a separate virtual wallet of START_CASH fake euros to play
 * with. Nothing here is investment advice.
 *
 * Prices come from a deterministic in-PHP "market": layered sine waves plus a
 * seeded per-hour jitter, unique per asset, computed from the current time.
 * Because price(asset, t) is a pure function, every request (and every user)
 * sees exactly the same chart without storing any price history — and it keeps
 * working on stage with no external market API or key.
 *
 * Requires config.php ($conn) to have been included.
 */

// Virtual starting money for every new portfolio.
if (!defined("INVEST_START_CASH")) {
    define("INVEST_START_CASH", 10000.00);
}

// Fixed epoch for the price clock (never change it — it would rewrite history).
if (!defined("INVEST_EPOCH")) {
    define("INVEST_EPOCH", 1735689600); // 2025-01-01 00:00:00 UTC
}

/* Create the simulator tables once (cheap + idempotent on every request). */
function ensureInvestSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS invest_wallets (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            cash DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_invest_wallet_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS invest_holdings (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            asset VARCHAR(20) NOT NULL,
            units DECIMAL(20,8) NOT NULL DEFAULT 0,
            invested DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_invest_user_asset (user_id, asset)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS invest_trades (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            asset VARCHAR(20) NOT NULL,
            action VARCHAR(10) NOT NULL,           -- 'buy' | 'sell'
            units DECIMAL(20,8) NOT NULL,
            price DECIMAL(14,2) NOT NULL,          -- unit price at trade time
            amount DECIMAL(14,2) NOT NULL,         -- EUR moved
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_invest_trades_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/*
 * The tradable catalog. base = anchor price in EUR, vol = how wild the waves
 * are, drift = slow long-term trend per hour (positive = grows over time).
 */
function investAssets() {
    return [
        "tesla" => [
            "key" => "tesla", "name" => "Tesla", "symbol" => "TSLA",
            "icon" => "car-electric", "color" => "#E82127",
            "base" => 310.0, "vol" => 0.055, "drift" => 0.000012,
        ],
        "apple" => [
            "key" => "apple", "name" => "Apple", "symbol" => "AAPL",
            "icon" => "apple", "color" => "#555555",
            "base" => 245.0, "vol" => 0.030, "drift" => 0.000010,
        ],
        "bitcoin" => [
            "key" => "bitcoin", "name" => "Bitcoin", "symbol" => "BTC",
            "icon" => "bitcoin", "color" => "#F7931A",
            "base" => 88000.0, "vol" => 0.075, "drift" => 0.000016,
        ],
        "gold" => [
            "key" => "gold", "name" => "Gold", "symbol" => "XAU",
            "icon" => "gold", "color" => "#C9A227",
            "base" => 2850.0, "vol" => 0.012, "drift" => 0.000008,
        ],
        "nasdaq" => [
            "key" => "nasdaq", "name" => "NASDAQ 100", "symbol" => "NDX",
            "icon" => "chart-line", "color" => "#0B5FFF",
            "base" => 21000.0, "vol" => 0.022, "drift" => 0.000009,
        ],
    ];
}

/*
 * Deterministic price of one asset at a unix timestamp.
 * Layered sines (periods from ~26 days down to ~3 hours) + a seeded jitter
 * that changes once per hour, all shaped by the asset's volatility and drift.
 */
function investPriceAt($asset, $ts) {
    $h    = ($ts - INVEST_EPOCH) / 3600.0; // hours since epoch (fractional)
    $seed = crc32($asset["key"]) % 1000;

    $n  = 0.0;
    $n += sin($h / 631.0 + $seed)        * 1.90; // ~26-day swings
    $n += sin($h / 189.0 + $seed * 2.0)  * 1.10; // ~8-day swings
    $n += sin($h / 53.0  + $seed * 3.0)  * 0.60; // ~2-day moves
    $n += sin($h / 13.0  + $seed * 5.0)  * 0.35; // intraday moves
    $n += sin($h / 3.1   + $seed * 7.0)  * 0.18; // hour-to-hour wiggle

    // Pseudo-random step that is constant within an hour (candle-like).
    $hi = floor($h);
    $j  = sin($hi * 12.9898 + $seed) * 43758.5453;
    $n += ($j - floor($j) - 0.5) * 0.55;

    $price = $asset["base"] * exp($asset["vol"] * $n + $asset["drift"] * $h);
    return round($price, 2);
}

/* ------------------------------------------------------------------ */
/* Live Bitcoin price                                                   */
/*                                                                      */
/* Bitcoin is the one asset priced from the REAL market: the current    */
/* EUR price comes from the free CoinGecko API. It is cached in MySQL   */
/* (Render instances are ephemeral) and refreshed at most once every    */
/* two minutes, so we stay far below the free rate limits. If the API   */
/* is unreachable the last cached price is used, and with no cache at   */
/* all the deterministic engine takes over — the simulator never dies.  */
/* ------------------------------------------------------------------ */

function ensureInvestPriceCache($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS invest_price_cache (
            asset VARCHAR(20) NOT NULL PRIMARY KEY,
            price DECIMAL(14,2) NOT NULL,
            change_24h_pct DECIMAL(8,2) DEFAULT NULL,
            fetched_at INT(11) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/*
 * The live BTC price in EUR: ["price" => float, "change_24h_pct" => float|null]
 * or null when nothing (fresh or cached) is available.
 */
function investLiveBtc($conn) {
    static $memo = false; // one lookup per request
    if ($memo !== false) return $memo;

    try {
        ensureInvestPriceCache($conn);

        $stmt = $conn->prepare("SELECT price, change_24h_pct, fetched_at FROM invest_price_cache WHERE asset = 'bitcoin'");
        $stmt->execute();
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fresh enough (2 min)? Use it without hitting the API.
        if ($cached && (time() - (int) $cached["fetched_at"]) < 120) {
            return $memo = [
                "price"          => round((float) $cached["price"], 2),
                "change_24h_pct" => $cached["change_24h_pct"] !== null ? round((float) $cached["change_24h_pct"], 2) : null,
            ];
        }

        $live = investFetchBtcFromApi();
        if ($live) {
            $stmt = $conn->prepare("
                INSERT INTO invest_price_cache (asset, price, change_24h_pct, fetched_at)
                VALUES ('bitcoin', ?, ?, ?)
                ON DUPLICATE KEY UPDATE price = VALUES(price),
                                        change_24h_pct = VALUES(change_24h_pct),
                                        fetched_at = VALUES(fetched_at)
            ");
            $stmt->execute([$live["price"], $live["change_24h_pct"], time()]);
            return $memo = $live;
        }

        // API down — a stale price is still far better than a fake one.
        if ($cached) {
            return $memo = [
                "price"          => round((float) $cached["price"], 2),
                "change_24h_pct" => $cached["change_24h_pct"] !== null ? round((float) $cached["change_24h_pct"], 2) : null,
            ];
        }
    } catch (Exception $e) {
        error_log("Live BTC price error: " . $e->getMessage());
    }

    return $memo = null;
}

/* One CoinGecko call (free, no API key). Returns the same shape or null. */
function investFetchBtcFromApi() {
    $url = "https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=eur&include_24hr_change=true";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ["Accept: application/json"],
        CURLOPT_USERAGENT      => "DSBanking/1.0",
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) return null;

    $json  = json_decode($raw, true);
    $price = $json["bitcoin"]["eur"] ?? null;
    if (!is_numeric($price) || (float) $price <= 0) return null;

    $change = $json["bitcoin"]["eur_24h_change"] ?? null;

    return [
        "price"          => round((float) $price, 2),
        "change_24h_pct" => is_numeric($change) ? round((float) $change, 2) : null,
    ];
}

/*
 * Current tradable price of one asset. Bitcoin uses the live market price;
 * everything else stays on the deterministic engine. Both get_invest.php and
 * invest_trade.php price through here so charts and trades always agree.
 */
function investCurrentPrice($conn, $asset, $now = null) {
    $now = $now ?: time();
    if ($asset["key"] === "bitcoin") {
        $live = investLiveBtc($conn);
        if ($live && $live["price"] > 0) {
            return $live["price"];
        }
    }
    return investPriceAt($asset, $now);
}

/* Chart ranges: label => [step in seconds, number of points]. */
function investRanges() {
    return [
        "1D" => [3600,      25], // hourly, last 24 h
        "1W" => [6 * 3600,  29], // every 6 h, last 7 days
        "1M" => [24 * 3600, 31], // daily, last 30 days
    ];
}

/* Price series (oldest -> newest, ending "now") for one asset and range. */
function investSeries($asset, $rangeKey, $now = null) {
    $ranges = investRanges();
    if (!isset($ranges[$rangeKey])) $rangeKey = "1M";
    list($step, $points) = $ranges[$rangeKey];

    $now    = $now ?: time();
    $series = [];
    for ($i = $points - 1; $i >= 0; $i--) {
        $series[] = investPriceAt($asset, $now - $i * $step);
    }
    return $series;
}

/* Ensure a virtual wallet exists and return ["cash" => float]. */
function getOrCreateInvestWallet($conn, $user_id) {
    $stmt = $conn->prepare("SELECT cash FROM invest_wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ["cash" => round((float) $row["cash"], 2)];
    }
    $stmt = $conn->prepare("INSERT INTO invest_wallets (user_id, cash) VALUES (?, ?)");
    $stmt->execute([$user_id, INVEST_START_CASH]);
    return ["cash" => INVEST_START_CASH];
}
