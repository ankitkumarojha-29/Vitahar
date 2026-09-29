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

// No API key required for text.pollinations.ai

// ── Parse and validate request body ──────────────────────────
$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON body."]);
    exit;
}

$productName = trim($body['productName'] ?? '');

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

// ── Build prompt (using json_encode for proper escaping) ──────
$productJson = json_encode($productName);

$prompt = 'You are a professional nutritionist and healthy chef. The user wants a healthy recipe that incorporates the food product: ' . $productJson . '
Please provide a quick, healthy recipe.
Output MUST be a raw JSON object. No markdown, no backticks, no explanation.
Format exactly like this:
{
  "recipeName": "String (e.g. Healthy Maggi Veggie Stir-fry)",
  "prepTime": "String (e.g. 10 mins)",
  "ingredients": ["ing 1", "ing 2"],
  "instructions": ["step 1", "step 2"],
  "healthBenefits": "String (e.g. Added veggies boost fiber and reduce sodium impact)"
}';

// ── Parse .env for OpenRouter API Key ────────────────────────
function getOpenRouterKey() {
    $envFiles = [__DIR__ . '/.env', __DIR__ . '/../.env'];
    foreach ($envFiles as $envFile) {
        if (!file_exists($envFile)) continue;
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, 'OPENROUTER_API_KEY=') === 0) {
                return trim(substr($line, strlen('OPENROUTER_API_KEY=')));
            }
        }
    }
    return '';
}

$apiKey = getOpenRouterKey();

if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(["error" => "OpenRouter API Key is missing in .env configuration."]);
    exit;
}

// ── Call OpenRouter API (Using Auto-Routed Free Model) ───────
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://openrouter.ai/api/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);

$headers = [
    "Authorization: Bearer " . $apiKey,
    "Content-Type: application/json"
];

$postData = [
    "model" => "openrouter/free",
    "messages" => [
        [
            "role" => "system",
            "content" => "You are a professional nutritionist and healthy chef. Output MUST be a raw JSON object. No markdown, no backticks, no explanation."
        ],
        [
            "role" => "user",
            "content" => "Provide a healthy recipe incorporating this food product: " . $productName . ". Format EXACTLY like this JSON:\n{\n  \"recipeName\": \"String\",\n  \"prepTime\": \"String\",\n  \"ingredients\": [\"ing 1\", \"ing 2\"],\n  \"instructions\": [\"step 1\", \"step 2\"],\n  \"healthBenefits\": [\"benefit 1\", \"benefit 2\"]\n}"
        ]
    ]
];

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
// Bypass SSL verification which often fails on free shared hosting like InfinityFree
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

$response = curl_exec($ch);
if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to reach AI provider: " . curl_error($ch)]);
    curl_close($ch);
    exit;
}
curl_close($ch);

$responseData = json_decode($response, true);
$aiMessage = $responseData['choices'][0]['message']['content'] ?? '';

// Attempt to clean any potential markdown formatting from AI output
$aiMessage = trim($aiMessage);
if (strpos($aiMessage, '```json') === 0) {
    $aiMessage = substr($aiMessage, 7);
} elseif (strpos($aiMessage, '```') === 0) {
    $aiMessage = substr($aiMessage, 3);
}
if (substr($aiMessage, -3) === '```') {
    $aiMessage = substr($aiMessage, 0, -3);
}
$aiMessage = trim($aiMessage);

$parsed = json_decode($aiMessage, true);

if (!is_array($parsed)) {
    http_response_code(500);
    // Fallback if AI fails to output valid JSON
    echo json_encode([
        'recipeName' => "Healthy " . htmlspecialchars($productName) . " Mix",
        'prepTime' => "10 mins",
        'ingredients' => [htmlspecialchars($productName), "Mixed Veggies", "Olive Oil"],
        'instructions' => ["Mix all ingredients.", "Enjoy in moderation!"],
        'healthBenefits' => ["Quick to prepare.", "Added veggies boost fiber."]
    ]);
    exit;
}

// Clean up arrays
$recipeName = htmlspecialchars($parsed['recipeName'] ?? 'Healthy Custom Recipe');
$prepTime = htmlspecialchars($parsed['prepTime'] ?? '15 mins');
$ingredients = array_map('htmlspecialchars', $parsed['ingredients'] ?? []);
$instructions = array_map('htmlspecialchars', $parsed['instructions'] ?? []);
$health = $parsed['healthBenefits'] ?? [];
if (is_array($health)) {
    $healthBenefits = array_map('htmlspecialchars', $health);
} else {
    $healthBenefits = [htmlspecialchars((string)$health)];
}

echo json_encode([
    'recipeName' => $recipeName,
    'prepTime' => $prepTime,
    'ingredients' => $ingredients,
    'instructions' => $instructions,
    'healthBenefits' => $healthBenefits
]);

