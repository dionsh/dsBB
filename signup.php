<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}






header("Content-Type: application/json");
require "config.php";

$data = json_decode(file_get_contents("php://input"), true);

$name = $data["name"] ?? "";
$surname = $data["surname"] ?? "";
$email = $data["email"] ?? "";
$password = $data["password"] ?? "";
$pin = trim($data["pin"] ?? "");
$verified = $data["verified"] ?? false;

/* VALIDATION */
if(!$name || !$surname || !$email || !$password || !$pin){
    echo json_encode(["status"=>"error","message"=>"Missing fields"]);
    exit;
}

/* KYC gate: the account can only be created after the mock identity
   verification (front + back ID scan) has been completed on the client. */
if($verified !== true){
    echo json_encode(["status"=>"error","message"=>"Identity verification required"]);
    exit;
}

/* hash passwordin edhe pinin ndatabase */
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);
$hashedPin = password_hash($pin, PASSWORD_BCRYPT);

try {

    /* inserton user ndb */
    $stmt = $conn->prepare("
        INSERT INTO users(name,surname,email,password,pin)
        VALUES(?,?,?,?,?)
    ");
    $stmt->execute([$name,$surname,$email,$hashedPassword,$hashedPin]);

    $userId = $conn->lastInsertId();

    /* gjeneron numrin e acc */
    $accountNumber = str_pad(rand(0,9999999999999999),16,'0',STR_PAD_LEFT);

    /* krijon acc */
    $stmt = $conn->prepare("
        INSERT INTO accounts(user_id,account_number,balance)
        VALUES(?,?,0)
    ");
    $stmt->execute([$userId,$accountNumber]);

    $accountId = $conn->lastInsertId();

    /* gjeneron kartelen */
    $cardNumber = str_pad(rand(0,9999999999999999),16,'0',STR_PAD_LEFT);
    $cvv = rand(100,999);
    $expiry = date("Y-m-d", strtotime("+3 years"));

    $stmt = $conn->prepare("
        INSERT INTO cards(account_id,card_number,cvv,expiry_date)
        VALUES(?,?,?,?)
    ");
    $stmt->execute([$accountId,$cardNumber,$cvv,$expiry]);

    echo json_encode([
        "status"=>"success",
        "message"=>"User created",
        "account_number"=>$accountNumber,
        "card_number"=>$cardNumber
    ]);

} catch(PDOException $e){
    echo json_encode([
        "status"=>"error",
        "message"=>$e->getMessage()
    ]);
}
