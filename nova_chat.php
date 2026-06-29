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
$debug = !empty($data["debug"]); // when true, surface the underlying AI error

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

    // 2) Everything else -> the LLM, kept on topic by the system prompt.
    if (!novaProvider()) {
        echo json_encode([
            "status" => "success",
            "reply" => "NOVA's smart answers aren't configured yet. In the meantime, use the quick buttons below for your balance, card and account details.",
            "source" => "fallback",
        ]);
        exit;
    }

    $result = novaCallLLM(novaSystemPrompt(), $history, $message);

    if ($result["ok"]) {
        echo json_encode(["status" => "success", "reply" => $result["reply"], "source" => "ai"]);
    } else {
        // Log server-side; show the user a friendly, non-technical message.
        error_log("NOVA Gemini error: " . $result["error"]);
        $out = [
            "status" => "success",
            "reply" => "Sorry, I'm having trouble answering right now. Please try again in a moment.",
            "source" => "fallback",
        ];
        if ($debug) {
            $out["detail"] = $result["error"];
            $out["model"] = novaModel();
        }
        echo json_encode($out);
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
