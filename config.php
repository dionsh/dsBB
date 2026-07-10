<?php

$user = getenv("DB_USER") ?: "root";
$pass = getenv("DB_PASS") ?: "";
$server = getenv("DB_HOST") ?: "localhost";
$port = getenv("DB_PORT") ?: "3306";
$dbname = getenv("DB_NAME") ?: "dsbanking";

// DS Banking runs on Kosovo local time. Setting PHP's default timezone here
// makes every date()/strtotime() across the API Kosovo, and the MySQL session
// time_zone set below makes all `created_at` TIMESTAMP columns (stored as UTC
// internally) READ BACK as Kosovo local time — fixing existing rows too, with
// no data migration. Kept in config.php because it is included by every endpoint.
//
// 'Europe/Pristina' is the correct id, but it was only added to the timezone
// database recently, so some PHP builds don't have it. 'Europe/Belgrade' has the
// IDENTICAL clock and DST rules (CET/CEST) and has existed for decades, so we
// fall back to it. The @ keeps an unknown-zone notice from leaking into output;
// this never fatals.
$appTimezone = "Europe/Pristina";
if (!@date_default_timezone_set($appTimezone)) {
	$appTimezone = "Europe/Belgrade";
	date_default_timezone_set($appTimezone);
}

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

// Put the DB session on Kosovo time so every `created_at` TIMESTAMP is read back
// in Kosovo local time. Prefer a named zone (DST-accurate for every historical
// row) when the server has the timezone tables loaded; if none are, fall back to
// the current UTC offset from PHP's (now-valid) default zone — recomputed each
// request, so it stays correct across DST shifts. Nothing here can fatal.
$tzApplied = false;
foreach (["Europe/Pristina", "Europe/Belgrade"] as $zoneName) {
	try {
		$conn->exec("SET time_zone = '$zoneName'");
		$tzApplied = true;
		break;
	} catch (PDOException $e) {
		// zone not loaded on this server — try the next one
	}
}
if (!$tzApplied) {
	try {
		$conn->exec("SET time_zone = '" . date("P") . "'");
	} catch (PDOException $e) {
		// leave the session default; PHP-side dates are still Kosovo local
	}
}

?>