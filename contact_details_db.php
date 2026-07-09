<?php
/*
 * contact_details_db.php
 *
 * Shared helpers for the Contact Details screen — the user's phone number,
 * home address, city, postal code and country. One row per user, kept in its
 * own table so it never touches the core `users` / `accounts` tables.
 *
 * Like the other *_db.php helpers, the table is created idempotently with
 * CREATE TABLE IF NOT EXISTS on first use, so no manual migration is required.
 *
 * The account email is NOT stored here — it lives in `users.email` (it is the
 * login identifier). get_contact_details.php returns it read-only alongside
 * these fields for display.
 *
 * Requires config.php ($conn) to have been included.
 */

function ensureContactDetailsSchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS user_contact_details (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            phone VARCHAR(40) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            city VARCHAR(120) DEFAULT NULL,
            postal_code VARCHAR(20) DEFAULT NULL,
            country VARCHAR(120) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_contact_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/*
 * Return the saved contact details for a user merged with their account email.
 * Missing fields come back as empty strings so the app can render inputs
 * without null checks. Returns null only if the user itself does not exist.
 */
function getContactDetails($conn, $user_id) {
    // Account email (the login identifier) — shown read-only on the screen.
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT phone, address, city, postal_code, country
        FROM user_contact_details
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        "email"       => $u["email"],
        "phone"       => $row["phone"]       ?? "",
        "address"     => $row["address"]     ?? "",
        "city"        => $row["city"]        ?? "",
        "postal_code" => $row["postal_code"] ?? "",
        "country"     => $row["country"]     ?? "",
    ];
}

/*
 * Insert or update the user's contact details (one row per user, enforced by
 * the UNIQUE key on user_id). Empty strings are stored as NULL. Returns the
 * freshly saved details via getContactDetails().
 */
function saveContactDetails($conn, $user_id, $fields) {
    $norm = function ($v) {
        $v = trim((string) ($v ?? ""));
        return $v === "" ? null : $v;
    };

    $phone   = $norm($fields["phone"]       ?? "");
    $address = $norm($fields["address"]     ?? "");
    $city    = $norm($fields["city"]        ?? "");
    $postal  = $norm($fields["postal_code"] ?? "");
    $country = $norm($fields["country"]     ?? "");

    $stmt = $conn->prepare("
        INSERT INTO user_contact_details (user_id, phone, address, city, postal_code, country)
        VALUES (:user_id, :phone, :address, :city, :postal_code, :country)
        ON DUPLICATE KEY UPDATE
            phone       = VALUES(phone),
            address     = VALUES(address),
            city        = VALUES(city),
            postal_code = VALUES(postal_code),
            country     = VALUES(country)
    ");
    $stmt->execute([
        ":user_id"     => $user_id,
        ":phone"       => $phone,
        ":address"     => $address,
        ":city"        => $city,
        ":postal_code" => $postal,
        ":country"     => $country,
    ]);

    return getContactDetails($conn, $user_id);
}
