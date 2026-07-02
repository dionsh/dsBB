<?php
/*
 * respond_split_request.php
 *
 * Accept or decline a pending Split The Bill request.
 *
 * Accept  -> the friend's share is deducted from their balance, credited to
 *            the requester, and recorded in the transactions ledger (visible
 *            in both users' Transactions screens).
 * Decline -> the request is marked declined; no money moves.
 *
 * Request (POST JSON): { "user_id": 9, "request_id": 3, "accept": true }
 * Response: { status, message, accepted, new_balance }
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

$user_id    = $data["user_id"] ?? null;
$request_id = $data["request_id"] ?? null;
$accept     = filter_var($data["accept"] ?? false, FILTER_VALIDATE_BOOLEAN);

try {
    if (!$user_id || !$request_id) {
        throw new Exception("Missing request");
    }

    ensureFeatureSchema($conn);
    ensureSplitRequestsSchema($conn);

    $request = getSplitRequest($conn, $request_id);
    if (!$request) {
        throw new Exception("Request not found");
    }
    if ((int) $request["friend_id"] !== (int) $user_id) {
        throw new Exception("This request is not for you");
    }
    if ($request["status"] !== "pending") {
        throw new Exception("This request was already answered");
    }

    $share       = round((float) $request["share_amount"], 2);
    $requesterId = (int) $request["requester_id"];
    $newBalance  = null;

    if ($accept) {
        $conn->beginTransaction();

        $myAccount = getUserAccountId($conn, $user_id);
        if (!$myAccount) {
            throw new Exception("Your account was not found");
        }
        $requesterAccount = getUserAccountId($conn, $requesterId);
        if (!$requesterAccount) {
            throw new Exception("The requester's account was not found");
        }

        if ($myAccount["balance"] < $share) {
            throw new Exception("Insufficient balance for your share (" . number_format($share, 2) . " EUR)");
        }

        // Move the friend's share to the requester.
        $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$share, $myAccount["id"]]);

        $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$share, $requesterAccount["id"]]);

        $description = $request["note"] !== null && trim($request["note"]) !== ""
            ? "Split Bill - " . trim($request["note"])
            : "Split Bill with " . splitUserName($conn, $requesterId);
        recordTransaction($conn, $myAccount["id"], $requesterAccount["id"], $share, $description);

        $stmt = $conn->prepare("
            UPDATE split_requests SET status = 'accepted', responded_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$request_id]);

        $conn->commit();

        $newBalance = round($myAccount["balance"] - $share, 2);
    } else {
        $stmt = $conn->prepare("
            UPDATE split_requests SET status = 'declined', responded_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$request_id]);
    }

    // Tell the requester how it went (never break the action on failure).
    try {
        $myName   = splitUserName($conn, $user_id);
        $shareStr = number_format($share, 2);
        addNotification(
            $conn,
            $requesterId,
            "split_request",
            $accept ? "Split request accepted" : "Split request declined",
            $accept
                ? $myName . " accepted your split request — " . $shareStr . " EUR was added to your balance. 🎉"
                : $myName . " declined your split request. No money was moved."
        );
    } catch (Exception $e) {
        // ignore notification errors
    }

    echo json_encode([
        "status"      => "success",
        "accepted"    => $accept,
        "message"     => $accept
            ? "You paid your share of " . number_format($share, 2) . " EUR"
            : "Request declined",
        "new_balance" => $newBalance,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
