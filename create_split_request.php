<?php
/*
 * create_split_request.php
 *
 * Send a Split The Bill request to another DS Banking user, identified by
 * the email the requester types (verified server-side, like Shared Savings
 * invites). The bill is split equally in two — nothing is charged until the
 * friend accepts.
 *
 * Request (POST JSON): { "user_id": 7, "email": "friend@mail.com", "total": 40, "note": "Dinner" }
 * Response: { status, message, request_id, share, friend_name }
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
require "feature_db.php";
require "split_requests_db.php";
require "notifications_db.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data["user_id"] ?? null;
$email   = trim($data["email"] ?? "");
$total   = floatval($data["total"] ?? 0);
$note    = trim($data["note"] ?? "");

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }
    if ($email === "") {
        throw new Exception("Please enter your friend's email address");
    }
    if ($total <= 0) {
        throw new Exception("Enter a valid bill amount");
    }

    ensureFeatureSchema($conn);
    ensureSplitRequestsSchema($conn);

    // Verify the friend's account actually exists.
    $friend = findSplitFriendByEmail($conn, $email);
    if (!$friend) {
        throw new Exception("No DS Banking account found with that email");
    }
    $friend_id = (int) $friend["id"];
    if ($friend_id === (int) $user_id) {
        throw new Exception("You can't split a bill with yourself");
    }

    // One open request per friend at a time keeps things tidy.
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM split_requests
        WHERE requester_id = ? AND friend_id = ? AND status = 'pending'
    ");
    $stmt->execute([$user_id, $friend_id]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new Exception("You already have a pending split request with this friend");
    }

    // Each side pays half, rounded to cents.
    $share = round($total / 2, 2);

    $stmt = $conn->prepare("
        INSERT INTO split_requests (requester_id, friend_id, total_amount, share_amount, note)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $friend_id, round($total, 2), $share, $note !== "" ? $note : null]);
    $requestId = (int) $conn->lastInsertId();

    // Tell the friend (never break the action on failure).
    try {
        addNotification(
            $conn,
            $friend_id,
            "split_request",
            "Split the bill request",
            splitUserName($conn, $user_id) . " wants to split a bill of "
                . number_format($total, 2) . " EUR" . ($note !== "" ? " (\"" . $note . "\")" : "")
                . " with you. Your share would be " . number_format($share, 2)
                . " EUR. Open Split The Bill to accept or decline."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"      => "success",
        "message"     => "Request sent to " . trim($friend["name"] . " " . $friend["surname"]),
        "request_id"  => $requestId,
        "share"       => $share,
        "friend_name" => trim($friend["name"] . " " . $friend["surname"]),
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
