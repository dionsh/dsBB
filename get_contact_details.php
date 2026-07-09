<?php
/*
 * get_contact_details.php
 *
 * Return the user's saved contact details (phone, address, city, postal code,
 * country) plus their read-only account email.
 *
 * Request:  GET ?user_id=7
 * Response: { status, contact: { email, phone, address, city, postal_code, country } }
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "contact_details_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    ensureContactDetailsSchema($conn);

    $contact = getContactDetails($conn, $user_id);
    if ($contact === null) {
        echo json_encode(["status" => "error", "message" => "User not found"]);
        exit;
    }

    echo json_encode([
        "status"  => "success",
        "contact" => $contact,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
