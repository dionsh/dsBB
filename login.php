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

$data = json_decode(file_get_contents("php://input"), true);

$name = $data["name"] ?? "";
$surname = $data["surname"] ?? "";
$pin = trim($data["pin"] ?? "");

if(!$name || !$surname || !$pin){
    echo json_encode(["status"=>"error","message"=>"Missing fields"]);
    exit;
}

try {
    /* gjan userin */
    $stmt = $conn->prepare("SELECT * FROM users WHERE name=? AND surname=?");
    $stmt->execute([$name, $surname]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$user){
        echo json_encode(["status"=>"error","message"=>"User not found"]);
        exit;
    }

  /* verifikon pin */
if(!password_verify($pin, $user["pin"])){
    echo json_encode(["status"=>"error","message"=>"Wrong PIN"]);
    exit;
}


    /* merr accountin */
    $stmt = $conn->prepare("SELECT * FROM accounts WHERE user_id=?");
    $stmt->execute([$user["id"]]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status"=>"success",
        "user_id"=>$user["id"],
        "name"=>$user["name"],
        "surname"=>$user["surname"],
        "email"=>$user["email"], 
        "account_id"=>$account["id"],
        "account_number"=>$account["account_number"],
        "balance"=>$account["balance"]
    ]);

} catch(PDOException $e){
    echo json_encode([
        "status"=>"error",
        "message"=>$e->getMessage()
    ]);
}
