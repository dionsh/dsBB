<?php

$user = getenv("DB_USER") ?: "root";
$pass = getenv("DB_PASS") ?: "";
$server = getenv("DB_HOST") ?: "localhost";
$port = getenv("DB_PORT") ?: "3306";
$dbname = getenv("DB_NAME") ?: "dsbanking";

// Enable SSL only when requested (Aiven requires it; local dev usually doesn't).
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
if (getenv("DB_SSL") === "true") {
	$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
	$conn = new PDO("mysql:host=$server;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass, $options);
} catch (PDOException $e) {
	http_response_code(500);
	header("Content-Type: application/json");
	echo json_encode(["status" => "error", "message" => "DB connection failed"]);
	exit;
}

?>