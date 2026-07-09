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
 * We extract ONLY the three values Split The Bill needs and ignore every other
 * price on the receipt (item lines, subtotals, VAT/TVSH, discounts, change):
 *   - total      = the FINAL total ("TOTALI NE EURO" on Albanian fiscal receipts)
 *   - date       = the receipt date (DD.MM.YYYY)
 *   - receipt_id = the receipt serial number ("NR. SERIK")
 * If any of the three is missing the receipt is treated as invalid.
 *
 * Request  (POST JSON): { "image_base64": "<raw base64 jpeg>" }
 * Response (valid):     { status, valid:true,  total, date, receipt_id }
 *          (invalid):   { status, valid:false, message, total, date, receipt_id }
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
    "You are a precise OCR system that reads point-of-sale receipts, including Kosovo/Albanian " .
    "fiscal receipts. From the image, extract EXACTLY these three fields and nothing else:\n" .
    "1) total -> the FINAL total amount paid. On Albanian receipts this is the line labelled " .
    "'TOTALI NE EURO' or 'TOTALI NË EURO'. If that exact label is absent, use the grand-total line " .
    "('TOTAL', 'TOTAL EUR', 'SHUMA TOTALE'). Return it as a number with a dot as the decimal " .
    "separator and NO currency symbol. Do NOT return any other amount — ignore individual item " .
    "prices, subtotals, VAT/TVSH, discounts, cash tendered and change.\n" .
    "2) date -> the receipt date, formatted as DD.MM.YYYY.\n" .
    "3) receipt_id -> the receipt serial number, labelled 'NR. SERIK' (accept 'NR SERIK', " .
    "'NR.SERIK', 'SERIA', 'NR. FATURES', 'Invoice No'). Return it exactly as printed.\n" .
    "Reply with ONLY a compact JSON object — no markdown, no code fences, no commentary — " .
    "exactly in this shape: " .
    '{"is_receipt": true or false, "total": number or null, "date": "DD.MM.YYYY" or null, ' .
    '"receipt_id": string or null}. ' .
    "Set a field to null ONLY when it is genuinely not printed on the receipt. " .
    "If the image is not a receipt at all, set is_receipt to false and the other fields to null.";

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

    // Normalise the three required fields.
    $total = (isset($parsed["total"]) && is_numeric($parsed["total"]))
        ? round((float) $parsed["total"], 2)
        : null;
    $date = (isset($parsed["date"]) && trim((string) $parsed["date"]) !== "")
        ? trim((string) $parsed["date"])
        : null;
    $receiptId = (isset($parsed["receipt_id"]) && trim((string) $parsed["receipt_id"]) !== "")
        ? trim((string) $parsed["receipt_id"])
        : null;
    $isReceipt = !empty($parsed["is_receipt"]);

    // A receipt is valid ONLY when all three required fields are present. If any
    // is missing (or it isn't a receipt), report it as invalid with a clear
    // message so the app can tell the user exactly what happened.
    if (!$isReceipt || $total === null || $total <= 0 || $date === null || $receiptId === null) {
        echo json_encode([
            "status"     => "success",
            "valid"      => false,
            "is_receipt" => $isReceipt,
            "total"      => $total,
            "date"       => $date,
            "receipt_id" => $receiptId,
            "message"    => "This receipt could not be validated because the required information is missing.",
        ]);
        exit;
    }

    echo json_encode([
        "status"     => "success",
        "valid"      => true,
        "is_receipt" => true,
        "total"      => $total,
        "date"       => $date,
        "receipt_id" => $receiptId,
    ]);

} catch (Exception $e) {
    error_log("scan_receipt exception: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Receipt scan failed. Please try again."]);
}
