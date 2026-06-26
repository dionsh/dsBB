<?php

header("Access-Control-Allow-Origin: *");

header("Content-Type: application/json");



include "config.php";



$user_id = $_GET['user_id'] ?? null;



if (!$user_id) {

    echo json_encode(["status" => "error", "message" => "Missing user_id"]);

    exit();

}



try {

    // merr acc t'userit

    $stmt = $conn->prepare("SELECT id FROM accounts WHERE user_id = ?");

    $stmt->execute([$user_id]);

    $account = $stmt->fetch(PDO::FETCH_ASSOC);



    if (!$account) {

        echo json_encode(["status" => "error", "message" => "Account not found"]);

        exit();

    }



    $account_id = $account['id'];



    // i ban fetch transakasionet me emer tderguesit edhe marresit

    $stmt = $conn->prepare("

        SELECT 

            t.*,

            su.name AS sender_name,

            su.surname AS sender_surname,

            ru.name AS receiver_name,

            ru.surname AS receiver_surname

        FROM transactions t

        JOIN accounts sa ON t.sender_account = sa.id

        JOIN users su ON sa.user_id = su.id

        JOIN accounts ra ON t.receiver_account = ra.id

        JOIN users ru ON ra.user_id = ru.id

        WHERE t.sender_account = ? OR t.receiver_account = ?

        ORDER BY t.created_at DESC

    ");



    $stmt->execute([$account_id, $account_id]);

    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);



    echo json_encode([

        "status" => "success",

        "transactions" => $transactions

    ]);



} catch (Exception $e) {

    echo json_encode(["status" => "error", "message" => $e->getMessage()]);

}