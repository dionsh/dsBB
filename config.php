<?php

$user = getenv("DB_USER") ?: "root";
$pass = getenv("DB_PASS") ?: "";
$server = getenv("DB_HOST") ?: "localhost";
$port = getenv("DB_PORT") ?: "3306";
$dbname = getenv("DB_NAME") ?: "dsbanking";

try {
	$conn = new PDO("mysql:host=$server;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass);
	$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
	http_response_code(500);
	header("Content-Type: application/json");
	echo json_encode(["status" => "error", "message" => "DB connection failed"]);
	exit;
}

?>