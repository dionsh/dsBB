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

$data = json_decode(file_get_contents("php://input"), true);

// merr infot e sender edhe receiver
$sender_id = $data['sender_id'] ?? null;
$receiver_email = $data['receiver_email'] ?? null;
$receiver_name = $data['receiver_name'] ?? null;
$receiver_surname = $data['receiver_surname'] ?? null;
$amount = floatval($data['amount'] ?? 0);
$message = $data['message'] ?? "";

try {
    if (!$sender_id || !$receiver_email || !$receiver_name || !$receiver_surname) {
        throw new Exception("Missing sender or receiver information");
    }
    if ($amount <= 0) {
        throw new Exception("Invalid amount");
    }

    $conn->beginTransaction();

    // merr acc t derguesit
    $stmt = $conn->prepare("SELECT * FROM accounts WHERE user_id = ?");
    $stmt->execute([$sender_id]);
    $sender = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sender) throw new Exception("Sender account not found");

    // merr acc t pritesit (receiver) (by email + name + surname)
    $stmt = $conn->prepare("
        SELECT a.* FROM accounts a
        JOIN users u ON a.user_id = u.id
        WHERE u.email = ? AND u.name = ? AND u.surname = ?
    ");
    $stmt->execute([$receiver_email, $receiver_name, $receiver_surname]);
    $receiver = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$receiver) throw new Exception("Receiver account not found");

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

        // Derguesi: konfirmim qe ka derguar pare
        addNotification(
            $conn,
            $sender_id,
            "sent",
            "Transfer sent",
            "You sent " . $amountStr . " EUR to " . trim($receiver_name . ' ' . $receiver_surname) . "."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status" => "success",
        "message" => "Transfer completed"
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}

