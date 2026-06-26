<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";

$user_id = $_GET["user_id"] ?? null;

if(!$user_id){
    echo json_encode(["status"=>"error","message"=>"Missing user ID"]);
    exit;
}

$stmt = $conn->prepare("
SELECT 
    c.card_number,
    c.cvv,
    c.expiry_date,
    a.account_number,
    a.balance
FROM accounts a
LEFT JOIN cards c ON c.account_id = a.id
WHERE a.user_id = ?
LIMIT 1
");

$stmt->execute([$user_id]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$card){
    echo json_encode(["status"=>"error","message"=>"No account/card found"]);
    exit;
}

echo json_encode([
    "status"=>"success",
    "card"=>$card
]);