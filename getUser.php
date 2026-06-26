<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";

$user_id = $_GET["user_id"] ?? null;

if(!$user_id){
    echo json_encode(["status"=>"error","message"=>"Missing user ID"]);
    exit;
}

$stmt = $conn->prepare("SELECT name, surname, email FROM users WHERE id=?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$user){
    echo json_encode(["status"=>"error","message"=>"User not found"]);
    exit;
}

echo json_encode([
    "status"=>"success",
    "user"=>$user
]);