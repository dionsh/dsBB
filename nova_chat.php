<?php
/*
 * nova_chat.php
 *
 * Free-text endpoint for the NOVA assistant.
 *
 * Request (POST JSON):
 *   { "user_id": 7, "message": "how do transfers work?", "history": [ {sender,text}, ... ] }
 *
 * Response (JSON):
 *   { "status": "success", "reply": "...", "source": "account" | "ai" | "fallback" }
 *
 * Flow (hybrid):
 *   1. If the message is about the user's own account data (balance, card, CVV,
 *      account number, expiry) -> answer deterministically from MySQL. The LLM
 *      never sees sensitive data.
 *   2. Otherwise -> ask Gemini with the strict banking-only system prompt.
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
require "nova_ai.php";

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data["user_id"] ?? null;
$message = trim($data["message"] ?? "");
$history = $data["history"] ?? [];

if ($message === "") {
    echo json_encode(["status" => "error", "message" => "Empty message"]);
    exit;
}

// Guard against abuse / runaway cost.
if (mb_strlen($message) > 1000) {
    $message = mb_substr($message, 0, 1000);
}

try {
    // 1) Account-data questions -> deterministic answer from the database.
    $intent = novaDetectAccountIntent($message);
    if ($intent) {
        $answer = novaAccountAnswer($conn, $user_id, $intent);
        if ($answer !== null) {
            echo json_encode(["status" => "success", "reply" => $answer, "source" => "account"]);
            exit;
        }
        // Could not load the data — fall through to a helpful message.
        echo json_encode([
            "status" => "success",
            "reply" => "I couldn't reach your account details right now. You can also see them on the Card screen.",
            "source" => "fallback",
        ]);
        exit;
    }

    // 2) Spending / saving / budgeting questions -> attach an aggregated
    //    financial snapshot (computed in PHP from MySQL) so NOVA coaches with
    //    the user's REAL numbers. Aggregates only — never card/account numbers.
    $systemPrompt = novaSystemPrompt();
    if ($user_id && novaDetectCoachIntent($message)) {
        try {
            require "feature_db.php";
            require "subscriptions_db.php";
            require "analytics_db.php";
            $facts = analyticsFactsText(computeAnalytics($conn, (int) $user_id));
            $systemPrompt .= "\n\nCURRENT USER FINANCIAL SNAPSHOT (aggregated, non-sensitive, amounts in EUR):\n"
                . $facts
                . "\nWhen the user asks about their spending, subscriptions, savings, cashback, points or budget, answer using ONLY these real numbers. Round naturally and keep it short.";
        } catch (Exception $e) {
            error_log("NOVA coach context error: " . $e->getMessage());
            // No snapshot — NOVA still answers, just without personal numbers.
        }
    }

    // 3) Call the LLM, kept on topic by the system prompt.
    if (!novaProvider()) {
        echo json_encode([
            "status" => "success",
            "reply" => "NOVA's smart answers aren't configured yet. In the meantime, use the quick buttons below for your balance, card and account details.",
            "source" => "fallback",
        ]);
        exit;
    }

    $result = novaCallLLM($systemPrompt, $history, $message);

    if ($result["ok"]) {
        echo json_encode(["status" => "success", "reply" => $result["reply"], "source" => "ai"]);
    } else {
        // Log server-side; show the user a friendly, non-technical message.
        error_log("NOVA LLM error: " . $result["error"]);
        echo json_encode([
            "status" => "success",
            "reply" => "Sorry, I'm having trouble answering right now. Please try again in a moment.",
            "source" => "fallback",
        ]);
    }
} catch (Exception $e) {
    error_log("NOVA chat error: " . $e->getMessage());
    echo json_encode([
        "status" => "success",
        "reply" => "Sorry, something went wrong on my side. Please try again.",
        "source" => "fallback",
    ]);
}
?>
