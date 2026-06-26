<?php
// getCompanies.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include "config.php"; 

try {
    $stmt = $conn->prepare("SELECT id, name, image_url FROM topup_companies ORDER BY name ASC");
    $stmt->execute();
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "companies" => $companies
    ]);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}