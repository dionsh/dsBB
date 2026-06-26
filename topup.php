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



$data = json_decode(file_get_contents("php://input"), true);



// Get POST data

$user_id = $data['user_id'] ?? null;

$company_id = $data['company_id'] ?? null;

$phone_number = $data['phone_number'] ?? null;

$amount = floatval($data['amount'] ?? 0);

$receiver_name = $data['receiver_name'] ?? null;

$receiver_surname = $data['receiver_surname'] ?? null;



try {

    if (!$user_id || !$company_id || !$phone_number || !$receiver_name || !$receiver_surname) {

        throw new Exception("Missing required fields");

    }



    if ($amount <= 0) {

        throw new Exception("Invalid amount");

    }



    // fillon transaction

    $conn->beginTransaction();



    // merr me fetch acc t userit

    $stmt = $conn->prepare("SELECT * FROM accounts WHERE user_id = ?");

    $stmt->execute([$user_id]);

    $userAccount = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userAccount) throw new Exception("User account not found");



    // e kqyr balancen

    if ($userAccount['balance'] < $amount) {

        throw new Exception("Insufficient balance");

    }



    // redukton balancen

    $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");

    $stmt->execute([$amount, $userAccount['id']]);



    // e paraqet te charges table

    $stmt = $conn->prepare("

        INSERT INTO charges (sender_id, company_id, phone_number, amount, receiver_name, receiver_surname)

        VALUES (?, ?, ?, ?, ?, ?)

    ");

    $stmt->execute([$user_id, $company_id, $phone_number, $amount, $receiver_name, $receiver_surname]);







    $conn->commit();



    echo json_encode([

        "status" => "success",

        "message" => "Phone top-up completed"

    ]);



} catch (Exception $e) {

    if ($conn->inTransaction()) $conn->rollBack();

    echo json_encode([

        "status" => "error",

        "message" => $e->getMessage()

    ]);
}