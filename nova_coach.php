<?php
/*
 * nova_coach.php
 *
 * The AI Financial Coach. Hybrid design, same philosophy as nova_chat.php:
 *   1. PHP computes every number from MySQL (analytics_db.php) and builds a
 *      full set of deterministic insights — so the coach ALWAYS works, even
 *      with no LLM key configured.
 *   2. If an LLM provider is configured (GROQ_API_KEY / GEMINI_API_KEY), the
 *      aggregated snapshot (never card numbers / account numbers) is sent to
 *      the model, which writes a short personalized coaching message on top.
 *
 * Request (POST JSON):  { "user_id": 7 }
 * Response:             { status, summary, insights: [ {type,icon,title,text} ],
 *                         ai_message, source: "ai" | "local" }
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
require "subscriptions_db.php";
require "analytics_db.php";
require "nova_ai.php";

$data    = json_decode(file_get_contents("php://input"), true);
$user_id = $data["user_id"] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Missing user_id"]);
    exit();
}

try {
    ensureFeatureSchema($conn);
    ensureSubscriptionSchema($conn);

    $analytics = computeAnalytics($conn, (int) $user_id);
    $insights  = analyticsInsights($analytics);
    $facts     = analyticsFactsText($analytics);

    // First name for a personal touch.
    $firstName = "";
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $firstName = trim($row["name"]);
    }

    /* ---------- optional LLM layer on top of the PHP numbers ---------- */

    $aiMessage = "";
    $source    = "local";

    if (novaProvider()) {
        $systemPrompt = <<<SP
You are NOVA, the friendly AI financial coach inside DS Banking, a mobile banking app. Currency is the euro (€).

You are given an aggregated, non-sensitive snapshot of the user's finances. Write a short personal coaching message for them:
- 3 to 5 short sentences, warm and encouraging but concrete.
- Use ONLY numbers that appear in the snapshot. Never invent or estimate other numbers.
- Mention their strongest point AND the one thing to improve, with a specific action in the app (e.g. reviewing a subscription, using Round It Up, setting a savings goal, redeeming points).
- No generic financial-guru talk, no investment advice, no markdown headers. Plain sentences only.

USER SNAPSHOT:
$facts
SP;

        $userMsg = $firstName !== ""
            ? "Write my personal coaching message for this month. My first name is " . $firstName . "."
            : "Write my personal coaching message for this month.";

        $result = novaCallLLM($systemPrompt, [], $userMsg);
        if ($result["ok"] && trim($result["reply"]) !== "") {
            $aiMessage = trim($result["reply"]);
            $source    = "ai";
        } else {
            error_log("NOVA coach LLM error: " . ($result["error"] ?? "unknown"));
        }
    }

    // Fallback message assembled from the deterministic insights.
    if ($aiMessage === "") {
        $s = $analytics["summary"];
        $aiMessage = ($firstName !== "" ? "Hi " . $firstName . "! " : "")
            . "Here's your " . $s["month_label"] . " check-in: you've spent €"
            . number_format($s["this_month_expenses"], 2)
            . " and received €" . number_format($s["this_month_income"], 2) . " so far.";
        if (!empty($s["top_category"])) {
            $aiMessage .= " Most of it went to " . $s["top_category"]["name"] . ".";
        }
        $aiMessage .= " The tips below are based on your real numbers.";
    }

    echo json_encode([
        "status"     => "success",
        "summary"    => $analytics["summary"],
        "insights"   => $insights,
        "ai_message" => $aiMessage,
        "source"     => $source,
    ]);
} catch (Exception $e) {
    error_log("NOVA coach error: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
