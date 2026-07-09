<?php
/*
 * create_split_request.php
 *
 * Send a Split The Bill request to one OR MORE DS Banking users, identified by
 * the emails the requester types (verified server-side, like Shared Savings
 * invites). The bill is split equally between everyone at the table — that is
 * the requester plus every friend — so with N friends there are N+1 people and
 * each person's share is total / (N + 1). One split_request row is created per
 * friend; nothing is charged until each friend accepts their own share.
 *
 * Request (POST JSON):
 *   { "user_id": 7, "emails": ["a@x.com","b@y.com"], "total": 60, "note": "Dinner" }
 *   (legacy single-friend form still accepted: { ..., "email": "a@x.com" })
 *
 * Response:
 *   { status, message, share, people, count, friends: [ { name, request_id } ] }
 *
 * Validation is all-or-nothing: if any email is invalid, unknown, your own, a
 * duplicate in the list, or already has a pending request, nothing is sent and
 * a clear message explains what to fix — so the share amount is never ambiguous.
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
if (!is_array($data)) $data = [];

$user_id = $data["user_id"] ?? null;
$total   = floatval($data["total"] ?? 0);
$note    = trim($data["note"] ?? "");

// Accept both the new `emails` array and the legacy single `email`.
$rawEmails = [];
if (isset($data["emails"]) && is_array($data["emails"])) {
    $rawEmails = $data["emails"];
} elseif (isset($data["email"])) {
    $rawEmails = [$data["email"]];
}

try {
    if (!$user_id) {
        throw new Exception("Missing user");
    }

    // Trim + drop empties.
    $emails = [];
    foreach ($rawEmails as $e) {
        $e = trim((string) $e);
        if ($e !== "") $emails[] = $e;
    }

    if (count($emails) === 0) {
        throw new Exception("Please enter at least one friend's email address");
    }
    if ($total <= 0) {
        throw new Exception("Enter a valid bill amount");
    }

    // Reject duplicates in the list (case-insensitive) so the split math is exact.
    $seen = [];
    foreach ($emails as $e) {
        $k = strtolower($e);
        if (isset($seen[$k])) {
            throw new Exception("You've listed the same friend more than once ($e)");
        }
        $seen[$k] = true;
    }

    ensureFeatureSchema($conn);
    ensureSplitRequestsSchema($conn);

    // Resolve + validate EVERY friend before creating anything (all-or-nothing).
    $friends = [];
    foreach ($emails as $e) {
        $friend = findSplitFriendByEmail($conn, $e);
        if (!$friend) {
            throw new Exception("No DS Banking account found for " . $e);
        }
        $friendId = (int) $friend["id"];
        if ($friendId === (int) $user_id) {
            throw new Exception("You can't split a bill with yourself (" . $e . ")");
        }

        // One open request per friend at a time keeps things tidy.
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM split_requests
            WHERE requester_id = ? AND friend_id = ? AND status = 'pending'
        ");
        $stmt->execute([$user_id, $friendId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new Exception(
                "You already have a pending split request with "
                . trim($friend["name"] . " " . $friend["surname"])
            );
        }

        $friends[] = $friend;
    }

    // Everyone at the table = the requester + each friend. Each person pays an
    // equal share of the whole bill, rounded to cents.
    $people = count($friends) + 1;
    $share  = round($total / $people, 2);
    $noteVal = $note !== "" ? $note : null;

    $insert = $conn->prepare("
        INSERT INTO split_requests (requester_id, friend_id, total_amount, share_amount, note)
        VALUES (?, ?, ?, ?, ?)
    ");

    $requesterName = splitUserName($conn, $user_id);
    $created = [];

    $conn->beginTransaction();
    foreach ($friends as $friend) {
        $friendId = (int) $friend["id"];
        $insert->execute([$user_id, $friendId, round($total, 2), $share, $noteVal]);
        $requestId = (int) $conn->lastInsertId();
        $created[] = [
            "id"      => $friendId,
            "name"    => trim($friend["name"] . " " . $friend["surname"]),
            "request" => $requestId,
        ];
    }
    $conn->commit();

    // Notify each friend (never break the action if a notification fails).
    foreach ($created as $c) {
        try {
            addNotification(
                $conn,
                $c["id"],
                "split_request",
                "Split the bill request",
                $requesterName . " wants to split a bill of "
                    . number_format($total, 2) . " EUR" . ($noteVal !== null ? " (\"" . $noteVal . "\")" : "")
                    . " between " . $people . " people. Your share would be "
                    . number_format($share, 2) . " EUR. Open Split The Bill to accept or decline."
            );
        } catch (Exception $e) {
            // ignore notification errors
        }
    }

    $names = array_map(function ($c) { return $c["name"]; }, $created);
    $count = count($created);
    $message = $count === 1
        ? $names[0] . " was asked to pay " . number_format($share, 2) . " EUR."
        : $count . " friends were each asked to pay " . number_format($share, 2) . " EUR.";

    echo json_encode([
        "status"  => "success",
        "message" => $message,
        "share"   => $share,
        "people"  => $people,
        "count"   => $count,
        "friends" => array_map(function ($c) {
            return ["name" => $c["name"], "request_id" => $c["request"]];
        }, $created),
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
