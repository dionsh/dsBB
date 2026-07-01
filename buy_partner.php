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
require "cashback_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id    = $data['user_id'] ?? null;
$partner_id = $data['partner_id'] ?? null;

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if (!$partner_id) {
        throw new Exception("Missing partner");
    }

    ensureFeatureSchema($conn);
    ensureCashbackSchema($conn);
    seedPartners($conn);
    normalizeCashbackOffers($conn);

    $conn->beginTransaction();

    // Load the partner offer.
    $stmt = $conn->prepare("SELECT * FROM partners WHERE id = ? AND active = 1");
    $stmt->execute([$partner_id]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partner) {
        throw new Exception("Offer not found");
    }

    $price = round((float) $partner['price'], 2);
    $cashbackAmount = round($price * ((int) $partner['cashback_percent']) / 100, 2);

    // Every purchase issues a unique ticket the user can show to redeem the offer.
    $ticketCode = generateTicketCode($partner['name']);

    // Resolve the user's account and check the balance.
    $account = getUserAccountId($conn, $user_id);
    if (!$account) {
        throw new Exception("User account not found");
    }
    if ((float) $account['balance'] < $price) {
        throw new Exception("Insufficient balance");
    }

    $houseAccountId = getHouseAccountId($conn);
    getOrCreateCashback($conn, $user_id);

    // 1) The purchase price leaves the main balance. The ticket code is part of
    //    the description so the purchase (with its ticket) shows in Transactions.
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$price, $account['id']]);
    recordTransaction(
        $conn,
        $account['id'],
        $houseAccountId,
        $price,
        "Cashback Purchase - " . $partner['name'] . " (Ticket " . $ticketCode . ")"
    );

    // 2) The earned cashback goes into the user's cashback wallet.
    $stmt = $conn->prepare("
        UPDATE cashback
        SET balance = balance + ?, total_earned = total_earned + ?
        WHERE user_id = ?
    ");
    $stmt->execute([$cashbackAmount, $cashbackAmount, $user_id]);

    // 3) Record the purchase (with its ticket) for the history list.
    $stmt = $conn->prepare("
        INSERT INTO partner_purchases (user_id, partner_id, partner_name, price, cashback_amount, ticket_code)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $partner['id'], $partner['name'], $price, $cashbackAmount, $ticketCode]);

    // Read back fresh balances.
    $wallet = getOrCreateCashback($conn, $user_id);
    $newBalance = round((float) $account['balance'] - $price, 2);

    $conn->commit();

    echo json_encode([
        "status"           => "success",
        "message"          => "Purchase complete - you earned " . number_format($cashbackAmount, 2) . " EUR cashback",
        "partner"          => $partner['name'],
        "price"            => $price,
        "cashback_earned"  => $cashbackAmount,
        "ticket_code"      => $ticketCode,
        "new_balance"      => $newBalance,
        "cashback_balance" => round((float) $wallet['balance'], 2),
        "total_earned"     => round((float) $wallet['total_earned'], 2),
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
