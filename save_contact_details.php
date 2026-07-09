<?php
/*
 * save_contact_details.php
 *
 * Create or update the user's contact details. All fields are optional, but
 * anything provided is validated server-side (defence in depth on top of the
 * app's own validation) so bad data never reaches the database.
 *
 * Request (POST JSON): {
 *   user_id, phone, address, city, postal_code, country
 * }
 * Response: { status, message, contact }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require "config.php";
require "contact_details_db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) $data = [];

$user_id     = $data["user_id"] ?? null;
$phone       = trim((string) ($data["phone"] ?? ""));
$address     = trim((string) ($data["address"] ?? ""));
$city        = trim((string) ($data["city"] ?? ""));
$postal_code = trim((string) ($data["postal_code"] ?? ""));
$country     = trim((string) ($data["country"] ?? ""));

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    // --- lightweight server-side validation ---
    // Phone: allow digits, spaces and + ( ) - , 6–20 chars when provided.
    if ($phone !== "" && !preg_match('/^[0-9+()\-\s]{6,20}$/', $phone)) {
        throw new Exception("Please enter a valid phone number");
    }
    // Postal code: letters/digits/space/dash, up to 12 chars when provided.
    if ($postal_code !== "" && !preg_match('/^[A-Za-z0-9\-\s]{2,12}$/', $postal_code)) {
        throw new Exception("Please enter a valid postal code");
    }
    if (mb_strlen($address) > 255) {
        throw new Exception("Address is too long");
    }
    if (mb_strlen($city) > 120) {
        throw new Exception("City name is too long");
    }
    if (mb_strlen($country) > 120) {
        throw new Exception("Country name is too long");
    }

    ensureContactDetailsSchema($conn);

    $contact = saveContactDetails($conn, $user_id, [
        "phone"       => $phone,
        "address"     => $address,
        "city"        => $city,
        "postal_code" => $postal_code,
        "country"     => $country,
    ]);

    echo json_encode([
        "status"  => "success",
        "message" => "Your contact details were saved.",
        "contact" => $contact,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
