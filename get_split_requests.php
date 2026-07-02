<?php
/*
 * get_split_requests.php
 *
 * Everything the Split The Bill screen needs in one call.
 *
 * Request:  GET ?user_id=7
 * Response: {
 *   status,
 *   friends:  [ { id, name, email } ],
 *   incoming: [ { id, requester_name, total_amount, share_amount, note, created_at } ],
 *   sent:     [ { id, friend_name, total_amount, share_amount, note, status,
 *                 created_at, responded_at } ]
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "split_requests_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    ensureSplitRequestsSchema($conn);

    /* ---------- who can I split with ---------- */

    $friends = splitFriendList($conn, $user_id);

    /* ---------- pending requests waiting for MY answer ---------- */

    $incoming = [];
    $stmt = $conn->prepare("
        SELECT r.id, r.total_amount, r.share_amount, r.note, r.created_at,
               u.name, u.surname
        FROM split_requests r
        JOIN users u ON u.id = r.requester_id
        WHERE r.friend_id = ? AND r.status = 'pending'
        ORDER BY r.created_at DESC, r.id DESC
    ");
    $stmt->execute([$user_id]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $incoming[] = [
            "id"             => (int) $r["id"],
            "requester_name" => trim($r["name"] . " " . $r["surname"]),
            "total_amount"   => round((float) $r["total_amount"], 2),
            "share_amount"   => round((float) $r["share_amount"], 2),
            "note"           => $r["note"],
            "created_at"     => $r["created_at"],
        ];
    }

    /* ---------- requests I sent (newest first) ---------- */

    $sent = [];
    $stmt = $conn->prepare("
        SELECT r.id, r.total_amount, r.share_amount, r.note, r.status,
               r.created_at, r.responded_at, u.name, u.surname
        FROM split_requests r
        JOIN users u ON u.id = r.friend_id
        WHERE r.requester_id = ?
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT 25
    ");
    $stmt->execute([$user_id]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sent[] = [
            "id"           => (int) $r["id"],
            "friend_name"  => trim($r["name"] . " " . $r["surname"]),
            "total_amount" => round((float) $r["total_amount"], 2),
            "share_amount" => round((float) $r["share_amount"], 2),
            "note"         => $r["note"],
            "status"       => $r["status"],
            "created_at"   => $r["created_at"],
            "responded_at" => $r["responded_at"],
        ];
    }

    echo json_encode([
        "status"   => "success",
        "friends"  => $friends,
        "incoming" => $incoming,
        "sent"     => $sent,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
