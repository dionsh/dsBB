<?php
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
require "currency_db.php";
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id   = $data['user_id'] ?? null;
$from_code = strtoupper(trim($data['from_code'] ?? ""));
$to_code   = strtoupper(trim($data['to_code'] ?? ""));

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    $rates = currencyRates();
    if (!isset($rates[$from_code]) || !isset($rates[$to_code])) {
        throw new Exception("Unknown currency");
    }
    if ($from_code === $to_code) {
        throw new Exception("Please choose two different currencies");
    }

    ensureFeatureSchema($conn);
    ensureCurrencySchema($conn);

    $conn->beginTransaction();

    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }

    $balanceEur = round((float) $account['balance'], 2);
    if ($balanceEur <= 0) {
        throw new Exception("You have no balance to convert");
    }

    // Cross rate between the two display currencies (both defined vs EUR).
    $rate = round($rates[$to_code] / $rates[$from_code], 6);

    // The fee is charged on the full balance, in EUR (the storage currency),
    // and shown to the user in the currency they are converting FROM.
    $feeEur   = round($balanceEur * (CURRENCY_FEE_PCT / 100), 2);
    $netEur   = round($balanceEur - $feeEur, 2);
    $received = round($netEur * $rates[$to_code], 2);

    $amountFrom = round($balanceEur * $rates[$from_code], 2);
    $feeFrom    = round($feeEur * $rates[$from_code], 2);

    if ($feeEur > 0) {
        $houseAccountId = getHouseAccountId($conn);
        $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$feeEur, $account['id']]);
        recordTransaction(
            $conn,
            $account['id'],
            $houseAccountId,
            $feeEur,
            "Currency Exchange Fee - " . $from_code . " to " . $to_code . " (" . CURRENCY_FEE_PCT . "%)"
        );
    }

    $stmt = $conn->prepare("
        INSERT INTO currency_conversions
            (user_id, from_code, to_code, rate, fee_percent,
             amount_eur, amount_from, fee_eur, fee_from, amount_received)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id, $from_code, $to_code, $rate, CURRENCY_FEE_PCT,
        $balanceEur, $amountFrom, $feeEur, $feeFrom, $received,
    ]);

    $conn->commit();

    // Best-effort inbox notification — never breaks the conversion itself.
    try {
        addNotification(
            $conn,
            $user_id,
            "currency",
            "Currency exchanged",
            "Your balance is now shown in " . $to_code . ". You received "
                . number_format($received, 2) . " " . $to_code
                . " (fee " . number_format($feeFrom, 2) . " " . $from_code . ")."
        );
    } catch (Exception $e) {
        // ignore
    }

    echo json_encode([
        "status"          => "success",
        "from_code"       => $from_code,
        "to_code"         => $to_code,
        "rate"            => $rate,
        "fee_percent"     => CURRENCY_FEE_PCT,
        "amount_from"     => $amountFrom,
        "fee_eur"         => $feeEur,
        "fee_from"        => $feeFrom,
        "amount_received" => $received,
        "new_balance"     => $netEur,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
