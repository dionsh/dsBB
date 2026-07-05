<?php
/*
 * scan_receipt.php
 *
 * REAL receipt OCR for the Split The Bill "Smart Receipt Scanner". The app
 * captures a photo of a receipt with the camera and POSTs its base64 here; we
 * send the image to the SAME AI provider NOVA already uses (Groq or Gemini,
 * whichever key is configured) and ask a VISION model to read three things off
 * the receipt: the total amount, the date, and the receipt / invoice ID. We
 * return them as JSON so the app can auto-fill the split total.
 *
 * The image is never stored. Config via env (already set in Render for NOVA):
 *   GROQ_API_KEY      -> Groq vision  (default model: meta-llama/llama-4-scout-17b-16e-instruct)
 *   GEMINI_API_KEY    -> Gemini vision (default model: gemini-2.0-flash)
 *   NOVA_VISION_MODEL -> optional override if the default model name changes
 *
 * Request  (POST JSON): { "image_base64": "<raw base64 jpeg>" }
 * Response (success):   { status, is_receipt, total, currency, date, receipt_id }
 *          (error):     { status:"error", message }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require "nova_ai.php"; // novaProvider()

define("RECEIPT_MAX_B64", 12 * 1024 * 1024); // ~12 MB of base64

$RECEIPT_PROMPT =
    "You are an OCR system that reads shop receipts and invoices. Look at the image and " .
    "decide whether it is a purchase receipt or invoice. Extract THREE things: " .
    "1) total = the FINAL total amount actually paid, as a number using a dot as the decimal " .
    "separator and NO currency symbol; " .
    "2) date = the receipt date formatted as DD.MM.YYYY; " .
    "3) receipt_id = the receipt / invoice / bill number printed on it. " .
    "Reply with ONLY a compact JSON object — no markdown, no code fences, no commentary — " .
    'exactly in this shape: ' .
    '{"is_receipt": true or false, "total": number or null, "currency": string or null, ' .
    '"date": string or null, "receipt_id": string or null}. ' .
    "If the image is not a receipt, set is_receipt to false and the other fields to null.";

/* Pull the first {...} JSON object out of an LLM reply and decode it. */
function receiptParseJson($text) {
    $text = trim((string) $text);
    $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text); // strip a leading ``` fence
    $text = preg_replace('/\s*```$/', '', $text);          // strip a trailing ``` fence
    $start = strpos($text, "{");
    $end   = strrpos($text, "}");
    if ($start === false || $end === false || $end < $start) return null;
    $data = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($data) ? $data : null;
}

/* Groq vision (OpenAI-compatible chat completions with an image_url part). */
function receiptVisionGroq($apiKey, $b64, $prompt) {
    $model = getenv("NOVA_VISION_MODEL") ?: "meta-llama/llama-4-scout-17b-16e-instruct";
    $payload = [
        "model"       => $model,
        "temperature" => 0,
        "max_tokens"  => 300,
        "messages"    => [[
            "role"    => "user",
            "content" => [
                ["type" => "text", "text" => $prompt],
                ["type" => "image_url", "image_url" => ["url" => "data:image/jpeg;base64," . $b64]],
            ],
        ]],
    ];

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer " . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ["ok" => false, "text" => "", "error" => "curl: " . $curlErr];
    $json = json_decode($raw, true);
    if ($httpCode !== 200) return ["ok" => false, "text" => "", "error" => $json["error"]["message"] ?? ("HTTP " . $httpCode)];
    return ["ok" => true, "text" => $json["choices"][0]["message"]["content"] ?? "", "error" => ""];
}

/* Gemini vision (generateContent with an inline_data image part). */
function receiptVisionGemini($apiKey, $b64, $prompt) {
    $model = getenv("NOVA_VISION_MODEL") ?: "gemini-2.0-flash";
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/"
           . rawurlencode($model) . ":generateContent?key=" . urlencode($apiKey);

    $payload = [
        "contents" => [[
            "role"  => "user",
            "parts" => [
                ["text" => $prompt],
                ["inline_data" => ["mime_type" => "image/jpeg", "data" => $b64]],
            ],
        ]],
        "generationConfig" => ["temperature" => 0, "maxOutputTokens" => 300],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ["ok" => false, "text" => "", "error" => "curl: " . $curlErr];
    $json = json_decode($raw, true);
    if ($httpCode !== 200) return ["ok" => false, "text" => "", "error" => $json["error"]["message"] ?? ("HTTP " . $httpCode)];

    $text = "";
    foreach (($json["candidates"][0]["content"]["parts"] ?? []) as $p) {
        if (isset($p["text"])) $text .= $p["text"];
    }
    return ["ok" => true, "text" => $text, "error" => ""];
}

try {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) $data = [];

    $b64 = (string) ($data["image_base64"] ?? "");
    // Accept a full data URL too, just in case.
    if (strpos($b64, "base64,") !== false) {
        $b64 = substr($b64, strpos($b64, "base64,") + 7);
    }
    $b64 = trim($b64);

    if ($b64 === "") {
        echo json_encode(["status" => "error", "message" => "No image received"]);
        exit;
    }
    if (strlen($b64) > RECEIPT_MAX_B64) {
        echo json_encode(["status" => "error", "message" => "Image too large — please try again"]);
        exit;
    }

    $provider = novaProvider();
    if ($provider === "groq") {
        $r = receiptVisionGroq(getenv("GROQ_API_KEY"), $b64, $RECEIPT_PROMPT);
    } elseif ($provider === "gemini") {
        $r = receiptVisionGemini(getenv("GEMINI_API_KEY"), $b64, $RECEIPT_PROMPT);
    } else {
        echo json_encode(["status" => "error", "message" => "Receipt scanning isn't configured (no AI provider key)."]);
        exit;
    }

    if (!$r["ok"]) {
        error_log("scan_receipt provider error: " . $r["error"]);
        echo json_encode(["status" => "error", "message" => "Couldn't read the receipt. Please try again."]);
        exit;
    }

    $parsed = receiptParseJson($r["text"]);
    if ($parsed === null) {
        error_log("scan_receipt unparseable reply: " . $r["text"]);
        echo json_encode(["status" => "error", "message" => "Couldn't read the receipt clearly. Try again with better lighting."]);
        exit;
    }

    $total = (isset($parsed["total"]) && is_numeric($parsed["total"])) ? round((float) $parsed["total"], 2) : null;

    echo json_encode([
        "status"     => "success",
        "is_receipt" => !empty($parsed["is_receipt"]),
        "total"      => $total,
        "currency"   => $parsed["currency"] ?? null,
        "date"       => $parsed["date"] ?? null,
        "receipt_id" => $parsed["receipt_id"] ?? null,
    ]);

} catch (Exception $e) {
    error_log("scan_receipt exception: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Receipt scan failed. Please try again."]);
}
