<?php
/*
 * nova_transcribe.php
 *
 * Speech-to-text for NOVA's voice input. The app records a short clip with
 * expo-audio and uploads it here as multipart form data; we forward it to the
 * SAME provider NOVA already uses:
 *
 *   - Groq   (GROQ_API_KEY)   -> Whisper (whisper-large-v3-turbo), multipart.
 *   - Gemini (GEMINI_API_KEY) -> audio understanding via generateContent.
 *
 * Request (POST multipart/form-data):
 *   audio    - the recorded file (m4a/aac from expo-audio)
 *   user_id  - optional, for logging only
 *
 * Response: { status: "success", text: "..." }
 *        or { status: "error", message: "..." }
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

// ~10 MB cap — a 30-second m4a voice note is well under 1 MB.
define("NOVA_AUDIO_MAX_BYTES", 10 * 1024 * 1024);

function transcribeWithGroq($apiKey, $tmpPath, $mime, $fileName) {
    $model = getenv("NOVA_STT_MODEL") ?: "whisper-large-v3-turbo";

    $ch = curl_init("https://api.groq.com/openai/v1/audio/transcriptions");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . $apiKey],
        CURLOPT_POSTFIELDS => [
            "model"           => $model,
            "file"            => new CURLFile($tmpPath, $mime, $fileName),
            "response_format" => "json",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ["ok" => false, "text" => "", "error" => "curl: " . $curlErr];
    }
    $json = json_decode($raw, true);
    if ($httpCode !== 200) {
        return ["ok" => false, "text" => "", "error" => $json["error"]["message"] ?? ("HTTP " . $httpCode)];
    }
    return ["ok" => true, "text" => trim($json["text"] ?? ""), "error" => ""];
}

function transcribeWithGemini($apiKey, $tmpPath, $mime) {
    $model = getenv("NOVA_STT_MODEL") ?: "gemini-2.0-flash";
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/"
           . rawurlencode($model) . ":generateContent?key=" . urlencode($apiKey);

    // Gemini officially supports AAC — expo-audio's m4a is AAC in an mp4 box.
    if ($mime === "audio/m4a" || $mime === "audio/x-m4a" || $mime === "audio/mp4") {
        $mime = "audio/aac";
    }

    $payload = [
        "contents" => [[
            "role"  => "user",
            "parts" => [
                ["text" => "Transcribe this short voice message word for word. Reply with ONLY the transcription text, no quotes, no commentary. It may be in English or Albanian."],
                ["inline_data" => [
                    "mime_type" => $mime,
                    "data"      => base64_encode(file_get_contents($tmpPath)),
                ]],
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
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ["ok" => false, "text" => "", "error" => "curl: " . $curlErr];
    }
    $json = json_decode($raw, true);
    if ($httpCode !== 200) {
        return ["ok" => false, "text" => "", "error" => $json["error"]["message"] ?? ("HTTP " . $httpCode)];
    }

    $text  = "";
    $parts = $json["candidates"][0]["content"]["parts"] ?? [];
    foreach ($parts as $p) {
        if (isset($p["text"])) $text .= $p["text"];
    }
    return ["ok" => true, "text" => trim($text), "error" => ""];
}

try {
    if (!isset($_FILES["audio"]) || $_FILES["audio"]["error"] !== UPLOAD_ERR_OK) {
        echo json_encode(["status" => "error", "message" => "No audio received"]);
        exit;
    }
    if ($_FILES["audio"]["size"] > NOVA_AUDIO_MAX_BYTES) {
        echo json_encode(["status" => "error", "message" => "Recording too large"]);
        exit;
    }

    $tmpPath  = $_FILES["audio"]["tmp_name"];
    $fileName = $_FILES["audio"]["name"] ?: "voice.m4a";
    $mime     = $_FILES["audio"]["type"] ?: "audio/m4a";

    $provider = novaProvider();
    if ($provider === "groq") {
        $result = transcribeWithGroq(getenv("GROQ_API_KEY"), $tmpPath, $mime, $fileName);
    } elseif ($provider === "gemini") {
        $result = transcribeWithGemini(getenv("GEMINI_API_KEY"), $tmpPath, $mime);
    } else {
        echo json_encode(["status" => "error", "message" => "Voice input isn't configured yet (no AI provider key)."]);
        exit;
    }

    if (!$result["ok"] || $result["text"] === "") {
        error_log("NOVA transcribe error: " . $result["error"]);
        echo json_encode(["status" => "error", "message" => "Couldn't understand the recording. Please try again."]);
        exit;
    }

    echo json_encode(["status" => "success", "text" => $result["text"]]);
} catch (Exception $e) {
    error_log("NOVA transcribe exception: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Transcription failed. Please try again."]);
}
