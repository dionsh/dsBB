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
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$name = trim($data["name"] ?? "");
$surname = trim($data["surname"] ?? "");
$email = trim($data["email"] ?? "");
$pin = trim($data["pin"] ?? "");

if(!$name || !$surname || !$email || !$pin){
    echo json_encode(["status"=>"error","message"=>"Missing fields"]);
    exit;
}

try {
    /*
     * Gjan userin sipas email + emri + mbiemri.
     * Email-i eshte unik, keshtu qe edhe nese dy persona kane te njejtin
     * emer/mbiemer, ky query e zgjedh saktesisht llogarine e duhur (jo konflikt).
     */
    $stmt = $conn->prepare("SELECT * FROM users WHERE email=? AND name=? AND surname=?");
    $stmt->execute([$email, $name, $surname]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$user){
        echo json_encode(["status"=>"error","message"=>"User not found. Check your name, surname and email."]);
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

    /* Krijon nje njoftim per login (nuk e prish login-in nese deshton) */
    try {
        addNotification(
            $conn,
            $user["id"],
            "login",
            "New sign-in",
            "You signed in to your DS Banking account."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

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
