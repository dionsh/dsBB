<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include "config.php";
require "notifications_db.php";
require "card_db.php";

$data = json_decode(file_get_contents("php://input"), true);

// merr infot e sender edhe receiver
$sender_id = $data['sender_id'] ?? null;
// Transfers are addressed by the receiver's 16-digit account number
// (the app strips spaces before sending, but strip again to be safe).
$receiver_account_number = preg_replace('/\D/', '', (string) ($data['receiver_account_number'] ?? ''));
// Emri + mbiemri qe useri i shkruan — duhet te perputhen me pronarin e llogarise.
$receiver_name    = trim((string) ($data['receiver_name'] ?? ''));
$receiver_surname = trim((string) ($data['receiver_surname'] ?? ''));
$amount = floatval($data['amount'] ?? 0);
$message = $data['message'] ?? "";

try {
    if (!$sender_id || !$receiver_account_number || $receiver_name === '' || $receiver_surname === '') {
        throw new Exception("Missing sender or receiver information");
    }
    if (strlen($receiver_account_number) !== 16) {
        throw new Exception("Account number must be 16 digits");
    }
    if ($amount <= 0) {
        throw new Exception("Invalid amount");
    }

    // A frozen card cannot make payments (simulates a real bank's freeze).
    if (isCardFrozen($conn, $sender_id)) {
        throw new Exception("Your card is frozen. Unfreeze it to make payments.");
    }

    $conn->beginTransaction();

    // merr acc t derguesit
    $stmt = $conn->prepare("SELECT * FROM accounts WHERE user_id = ?");
    $stmt->execute([$sender_id]);
    $sender = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sender) throw new Exception("Sender account not found");

    // merr acc t pritesit (receiver) by account number.
    // The hidden house account is excluded so it can never be a transfer target.
    $stmt = $conn->prepare("
        SELECT a.*, u.name AS receiver_name, u.surname AS receiver_surname
        FROM accounts a
        JOIN users u ON a.user_id = u.id
        WHERE a.account_number = ? AND u.email <> 'house@dsbanking.local'
    ");
    $stmt->execute([$receiver_account_number]);
    $receiver = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$receiver) throw new Exception("No account found with that account number");

    // nuk lejohet me i dergu vetes
    if ((int) $receiver['id'] === (int) $sender['id']) {
        throw new Exception("You cannot transfer money to your own account");
    }

    // Emri + mbiemri duhet te perputhen me pronarin e vertete te llogarise
    // (mbron nga gabimet ne numrin e llogarise). Krahasim pa ndjeshmeri ndaj
    // shkronjave te medha/vogla dhe hapesirave (DB-ja mund te kete hapesira).
    $lower = function ($s) {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    };
    $normalize = function ($s) use ($lower) {
        return preg_replace('/\s+/', ' ', trim($lower((string) $s)));
    };
    if ($normalize($receiver_name) !== $normalize($receiver['receiver_name'])
        || $normalize($receiver_surname) !== $normalize($receiver['receiver_surname'])) {
        throw new Exception("The name and surname do not match this account number");
    }

    // shikon balancen e derguesit
    if ($sender['balance'] < $amount) {
        throw new Exception("Insufficient balance");
    }

    // ban update balancen
    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$amount, $sender['id']]);

    $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$amount, $receiver['id']]);

    // inserton transaksionin ndatabaze
    $stmt = $conn->prepare("
        INSERT INTO transactions(sender_account, receiver_account, amount, description)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$sender['id'], $receiver['id'], $amount, $message]);

    $conn->commit();

    /* Krijon njoftime per te dy palet (nuk e prish transferin nese deshton) */
    try {
        $stmt = $conn->prepare("SELECT name, surname FROM users WHERE id = ?");
        $stmt->execute([$sender_id]);
        $senderUser = $stmt->fetch(PDO::FETCH_ASSOC);
        $senderName = $senderUser
            ? trim($senderUser['name'] . ' ' . $senderUser['surname'])
            : 'another user';

        $amountStr = number_format($amount, 2);

        // Pranuesi: ka pranuar pare
        addNotification(
            $conn,
            $receiver['user_id'],
            "received",
            "Money received",
            "You received " . $amountStr . " EUR from " . $senderName . "."
        );

        // Derguesi: konfirmim qe ka derguar pare (emri i vertete i pranuesit nga DB)
        addNotification(
            $conn,
            $sender_id,
            "sent",
            "Transfer sent",
            "You sent " . $amountStr . " EUR to " . trim($receiver['receiver_name'] . ' ' . $receiver['receiver_surname']) . "."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    // Konfirmimi permban emrin e vertete te pranuesit qe useri ta shoh
    // se kujt i shkuan parat (numri i llogarise nuk tregon emer vet).
    echo json_encode([
        "status" => "success",
        "message" => "Transfer completed. " . number_format($amount, 2) . " EUR sent to "
                   . trim($receiver['receiver_name'] . ' ' . $receiver['receiver_surname']) . ".",
        "receiver" => [
            "name"    => $receiver['receiver_name'],
            "surname" => $receiver['receiver_surname'],
        ]
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}

