<?php
/*
 * invest_trade.php
 *
 * Buy or sell in the Investment Simulator (virtual money only — the user's
 * real DS Banking balance is never touched).
 *
 * Request (POST JSON):
 *   { "user_id": 7, "action": "buy",   "asset": "tesla", "amount": 500 }
 *   { "user_id": 7, "action": "sell",  "asset": "tesla", "amount": 200 }
 *   { "user_id": 7, "action": "reset" }                  // fresh €10,000
 *
 * amount is in EUR. Selling with amount >= the holding's value sells all of it.
 *
 * Response: { status, message, cash, price?, units? }
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
require "invest_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data["user_id"] ?? null;
$action  = strtolower(trim($data["action"] ?? ""));
$asset   = strtolower(trim($data["asset"] ?? ""));
$amount  = round((float) ($data["amount"] ?? 0), 2);

try {
    if (!$user_id) {
        throw new Exception("Missing user_id");
    }

    ensureInvestSchema($conn);
    getOrCreateInvestWallet($conn, (int) $user_id);

    /* ---------- reset: fresh virtual wallet ---------- */
    if ($action === "reset") {
        $conn->beginTransaction();
        $stmt = $conn->prepare("UPDATE invest_wallets SET cash = ? WHERE user_id = ?");
        $stmt->execute([INVEST_START_CASH, $user_id]);
        $stmt = $conn->prepare("DELETE FROM invest_holdings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $stmt = $conn->prepare("DELETE FROM invest_trades WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $conn->commit();

        echo json_encode([
            "status"  => "success",
            "message" => "Portfolio reset — you have €" . number_format(INVEST_START_CASH, 2) . " of virtual money again.",
            "cash"    => INVEST_START_CASH,
        ]);
        exit;
    }

    $assets = investAssets();
    if (!isset($assets[$asset])) {
        throw new Exception("Unknown asset");
    }
    if (!in_array($action, ["buy", "sell"], true)) {
        throw new Exception("Unknown action");
    }
    if ($amount < 1) {
        throw new Exception("Minimum trade is €1.00");
    }

    $price = investPriceAt($assets[$asset], time());

    $conn->beginTransaction();

    // Lock the wallet row for the update.
    $stmt = $conn->prepare("SELECT cash FROM invest_wallets WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $cash = round((float) $stmt->fetchColumn(), 2);

    $stmt = $conn->prepare("SELECT units, invested FROM invest_holdings WHERE user_id = ? AND asset = ? FOR UPDATE");
    $stmt->execute([$user_id, $asset]);
    $holding  = $stmt->fetch(PDO::FETCH_ASSOC);
    $units    = $holding ? (float) $holding["units"] : 0.0;
    $invested = $holding ? (float) $holding["invested"] : 0.0;

    if ($action === "buy") {
        if ($cash < $amount) {
            throw new Exception("Not enough virtual cash (you have €" . number_format($cash, 2) . ")");
        }
        $boughtUnits = $amount / $price;

        $stmt = $conn->prepare("
            INSERT INTO invest_holdings (user_id, asset, units, invested)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE units = units + VALUES(units), invested = invested + VALUES(invested)
        ");
        $stmt->execute([$user_id, $asset, $boughtUnits, $amount]);

        $newCash = round($cash - $amount, 2);
        $stmt = $conn->prepare("UPDATE invest_wallets SET cash = ? WHERE user_id = ?");
        $stmt->execute([$newCash, $user_id]);

        $stmt = $conn->prepare("
            INSERT INTO invest_trades (user_id, asset, action, units, price, amount)
            VALUES (?, ?, 'buy', ?, ?, ?)
        ");
        $stmt->execute([$user_id, $asset, $boughtUnits, $price, $amount]);

        $conn->commit();
        echo json_encode([
            "status"  => "success",
            "message" => "Bought " . $assets[$asset]["name"] . " for €" . number_format($amount, 2),
            "cash"    => $newCash,
            "price"   => $price,
            "units"   => round($boughtUnits, 8),
        ]);
        exit;
    }

    /* ---------- sell ---------- */
    if ($units <= 0) {
        throw new Exception("You don't own any " . $assets[$asset]["name"]);
    }

    $valueNow = $units * $price;
    if ($amount >= $valueNow - 0.01) {
        // Sell everything.
        $soldUnits = $units;
        $proceeds  = round($valueNow, 2);
    } else {
        $soldUnits = $amount / $price;
        $proceeds  = $amount;
    }
    $remainingUnits = $units - $soldUnits;

    if ($remainingUnits <= 0.00000001) {
        $stmt = $conn->prepare("DELETE FROM invest_holdings WHERE user_id = ? AND asset = ?");
        $stmt->execute([$user_id, $asset]);
    } else {
        // Reduce the cost basis proportionally to the units sold.
        $newInvested = round($invested * ($remainingUnits / $units), 2);
        $stmt = $conn->prepare("UPDATE invest_holdings SET units = ?, invested = ? WHERE user_id = ? AND asset = ?");
        $stmt->execute([$remainingUnits, $newInvested, $user_id, $asset]);
    }

    $newCash = round($cash + $proceeds, 2);
    $stmt = $conn->prepare("UPDATE invest_wallets SET cash = ? WHERE user_id = ?");
    $stmt->execute([$newCash, $user_id]);

    $stmt = $conn->prepare("
        INSERT INTO invest_trades (user_id, asset, action, units, price, amount)
        VALUES (?, ?, 'sell', ?, ?, ?)
    ");
    $stmt->execute([$user_id, $asset, $soldUnits, $price, $proceeds]);

    $conn->commit();
    echo json_encode([
        "status"  => "success",
        "message" => "Sold " . $assets[$asset]["name"] . " for €" . number_format($proceeds, 2),
        "cash"    => $newCash,
        "price"   => $price,
        "units"   => round($soldUnits, 8),
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
