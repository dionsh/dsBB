<?php
/*
 * get_shared_goals.php
 *
 * Everything the Shared Savings screen needs in one call.
 *
 * Request:  GET ?user_id=7
 * Response: {
 *   status,
 *   invitations: [ { goal_id, name, icon, target_amount, current_amount,
 *                    invited_by_name, members_count } ],
 *   goals: [ { id, name, icon, target_amount, current_amount, status, pct,
 *              my_role, my_contributed, created_at, completed_at,
 *              members: [ { user_id, name, role, status, contributed } ],
 *              contributions: [ { user_id, name, amount, created_at } ] } ]
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require "config.php";
require "feature_db.php";
require "shared_goals_db.php";

$user_id = $_GET["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user ID"]);
    exit;
}

try {
    ensureSharedGoalsSchema($conn);

    /* ---------- pending invitations for me ---------- */

    $invitations = [];
    $stmt = $conn->prepare("
        SELECT g.id AS goal_id, g.name, g.icon, g.target_amount, g.current_amount,
               m.invited_by, g.creator_id
        FROM shared_goal_members m
        JOIN shared_goals g ON g.id = m.goal_id
        WHERE m.user_id = ? AND m.status = 'invited' AND g.status = 'active'
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$user_id]);
    while ($inv = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $inviterId = $inv["invited_by"] ? (int) $inv["invited_by"] : (int) $inv["creator_id"];

        $cnt = $conn->prepare("
            SELECT COUNT(*) FROM shared_goal_members WHERE goal_id = ? AND status = 'active'
        ");
        $cnt->execute([$inv["goal_id"]]);

        $invitations[] = [
            "goal_id"         => (int) $inv["goal_id"],
            "name"            => $inv["name"],
            "icon"            => $inv["icon"],
            "target_amount"   => round((float) $inv["target_amount"], 2),
            "current_amount"  => round((float) $inv["current_amount"], 2),
            "invited_by_name" => sharedUserName($conn, $inviterId),
            "members_count"   => (int) $cnt->fetchColumn(),
        ];
    }

    /* ---------- my groups ---------- */

    $goals = [];
    $stmt = $conn->prepare("
        SELECT g.*, m.role AS my_role
        FROM shared_goal_members m
        JOIN shared_goals g ON g.id = m.goal_id
        WHERE m.user_id = ? AND m.status = 'active'
        ORDER BY (g.status = 'completed') ASC, g.created_at DESC, g.id DESC
    ");
    $stmt->execute([$user_id]);

    while ($g = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $members = sharedGoalMembers($conn, (int) $g["id"]);

        $myContributed = 0.0;
        foreach ($members as $m) {
            if ($m["user_id"] === (int) $user_id) {
                $myContributed = $m["contributed"];
            }
        }

        // Contribution history (newest first) with contributor names.
        $contributions = [];
        $cstmt = $conn->prepare("
            SELECT c.user_id, c.amount, c.created_at, u.name, u.surname
            FROM shared_goal_contributions c
            JOIN users u ON u.id = c.user_id
            WHERE c.goal_id = ?
            ORDER BY c.created_at DESC, c.id DESC
            LIMIT 25
        ");
        $cstmt->execute([$g["id"]]);
        while ($c = $cstmt->fetch(PDO::FETCH_ASSOC)) {
            $contributions[] = [
                "user_id"    => (int) $c["user_id"],
                "name"       => trim($c["name"] . " " . $c["surname"]),
                "amount"     => round((float) $c["amount"], 2),
                "created_at" => $c["created_at"],
            ];
        }

        $target  = round((float) $g["target_amount"], 2);
        $current = round((float) $g["current_amount"], 2);

        $goals[] = [
            "id"             => (int) $g["id"],
            "name"           => $g["name"],
            "icon"           => $g["icon"],
            "target_amount"  => $target,
            "current_amount" => $current,
            "status"         => $g["status"],
            "pct"            => $target > 0 ? min(100, round($current / $target * 100, 1)) : 0,
            "my_role"        => $g["my_role"],
            "my_contributed" => $myContributed,
            "created_at"     => $g["created_at"],
            "completed_at"   => $g["completed_at"],
            "members"        => $members,
            "contributions"  => $contributions,
        ];
    }

    echo json_encode([
        "status"      => "success",
        "invitations" => $invitations,
        "goals"       => $goals,
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
