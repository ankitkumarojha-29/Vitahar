<?php
// ── Security: only allow requests from your own domain ────────
$allowedOrigin = 'https://vitahar.kesug.com';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed."]);
    exit;
}

// ── Basic referer/origin check to prevent API key abuse ──────
$origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$allowedHost = 'vitahar.kesug.com';

$originOk  = ($origin  === '' || str_contains($origin,  $allowedHost));
$refererOk = ($referer === '' || str_contains($referer, $allowedHost));

if (!$originOk && !$refererOk) {
    http_response_code(403);
    echo json_encode(["error" => "Forbidden: requests must originate from the Vitahar site."]);
    exit;
}

// ── Load API key from .env ────────────────────────────────────
$API_KEY  = '';
$envPaths = [__DIR__ . '/.env', __DIR__ . '/../.env'];
foreach ($envPaths as $envFile) {
    if (!file_exists($envFile)) continue;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v, " \t\"'");
            if ($k === 'OPENROUTER_API_KEY') { $API_KEY = $v; break 2; }
        }
    }
}
if (!$API_KEY) $API_KEY = getenv('OPENROUTER_API_KEY') ?: '';

if (!$API_KEY) {
    http_response_code(500);
    echo json_encode(["error" => "OPENROUTER_API_KEY not configured on server."]);
    exit;
}

// ── Parse and validate request body ──────────────────────────
$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON body."]);
    exit;
}

$productName = trim($body['productName'] ?? '');
$companyName = trim($body['companyName'] ?? '');

// Input length limits to prevent token abuse
if ($productName === '') {
    http_response_code(400);
    echo json_encode(["error" => "productName is required."]);
    exit;
}
if (strlen($productName) > 150) {
    http_response_code(400);
    echo json_encode(["error" => "productName too long (max 150 characters)."]);
    exit;
}
if (strlen($companyName) > 100) {
    $companyName = substr($companyName, 0, 100);
}

// ── Build prompt (using json_encode for proper escaping) ──────
$productJson = json_encode($productName);   // safely escaped for JSON context
$companyJson = json_encode($companyName ?: 'unknown');

$prompt = 'You are a food nutrition database for Indian packaged foods.
Product: ' . $productJson . '
Company: ' . $companyJson . '

Reply with ONLY a raw JSON object. No markdown, no backticks, no explanation.
Format: {"calories":350,"sugar":4,"salt":1160,"fat":14,"found":true}
- calories: kcal per 100g (integer)
- sugar: grams per 100g (integer)
- salt: milligrams of sodium per 100g (integer)
- fat: grams per 100g (integer)
- found: true if exact product known, false if estimating';

// ── Call OpenRouter API ───────────────────────────────────────
$url     = "https://openrouter.ai/api/v1/chat/completions";
$payload = json_encode([
    "model"       => "google/gemma-3-12b-it:free",
    "messages"    => [["role" => "user", "content" => $prompt]],
    "temperature" => 0.1,
    "max_tokens"  => 150          // we only need a tiny JSON object
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $API_KEY,
        "HTTP-Referer: https://vitahar.kesug.com",
        "X-Title: Vitahar"
    ],
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(["error" => "Network error connecting to AI service."]);
    exit;
}

$data = json_decode($response, true);
if ($httpCode !== 200) {
    http_response_code(502);
    $msg = $data['error']['message'] ?? "HTTP $httpCode from AI service";
    echo json_encode(["error" => $msg]);
    exit;
}

// ── Extract and validate the JSON from AI response ────────────
$aiText = $data['choices'][0]['message']['content'] ?? '';

if (!preg_match('/\{[\s\S]*?\}/', $aiText, $m)) {
    http_response_code(502);
    echo json_encode(["error" => "AI returned an unexpected response format."]);
    exit;
}

$parsed = json_decode($m[0], true);
if (!is_array($parsed)) {
    http_response_code(502);
    echo json_encode(["error" => "Could not parse AI response as JSON."]);
    exit;
}

// Cast and sanitize fields
foreach (['calories', 'sugar', 'salt', 'fat'] as $field) {
    $parsed[$field] = max(0, (int)($parsed[$field] ?? 0));
}
$parsed['found'] = (bool)($parsed['found'] ?? false);

// Only return the fields we need
echo json_encode([
    'calories' => $parsed['calories'],
    'sugar'    => $parsed['sugar'],
    'salt'     => $parsed['salt'],
    'fat'      => $parsed['fat'],
    'found'    => $parsed['found'],
]);
