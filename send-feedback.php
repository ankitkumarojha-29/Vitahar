<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, Referer, User-Agent');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

function loadEnv() {
  static $env = null;
  if ($env !== null) return $env;
  $env = [];
  foreach ([__DIR__ . '/.env', __DIR__ . '/../.env'] as $envFile) {
    if (!file_exists($envFile)) continue;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
      [$k, $v] = explode('=', $line, 2);
      $env[trim($k)] = trim($v, " \t\"'");
    }
    break;
  }
  return $env;
}

$__env = loadEnv();

$brevoApiKey = $__env['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY');
$receiverEmail = $__env['FEEDBACK_RECEIVER_EMAIL'] ?? getenv('FEEDBACK_RECEIVER_EMAIL') ?: '';
$senderEmail = $__env['BREVO_SENDER_EMAIL'] ?? getenv('BREVO_SENDER_EMAIL') ?: 'no-reply@vitahar.com';
$senderName = $__env['BREVO_SENDER_NAME'] ?? getenv('BREVO_SENDER_NAME') ?: 'Vitahar';

define('DB_HOST', $__env['DB_HOST'] ?? getenv('DB_HOST') ?: '');
define('DB_NAME', $__env['DB_NAME'] ?? getenv('DB_NAME') ?: '');
define('DB_USER', $__env['DB_USER'] ?? getenv('DB_USER') ?: '');
define('DB_PASS', $__env['DB_PASS'] ?? getenv('DB_PASS') ?: '');

if (!$brevoApiKey || !$receiverEmail) {
    respond(['error' => 'Server misconfigured: missing Brevo API Key or Receiver Email in .env'], 500);
}

session_start();
if (isset($_SESSION['last_feedback_time']) && time() - $_SESSION['last_feedback_time'] < 60) {
    respond(['error' => 'Please wait a minute before sending another feedback.'], 429);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    respond(['error' => 'Invalid JSON input'], 400);
}

$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$message = trim($input['message'] ?? '');

if (empty($name) || strlen($name) > 100) {
    respond(['error' => 'Invalid or missing name.'], 400);
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['error' => 'Invalid or missing email address.'], 400);
}

if (empty($message) || strlen($message) > 5000) {
    respond(['error' => 'Invalid or missing message. Message is required and must not exceed 5000 characters.'], 400);
}

$_SESSION['last_feedback_time'] = time();

$dbSuccess = false;
// Save to Database
try {
    if (DB_PASS !== '') {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedbacks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            email VARCHAR(100),
            message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $pdo->prepare("INSERT INTO feedbacks (name, email, message) VALUES (:name, :email, :message)");
        if ($stmt->execute([':name' => $name, ':email' => $email, ':message' => $message])) {
            $dbSuccess = true;
        }
    }
} catch (PDOException $e) {
    error_log("Database error in feedback: " . $e->getMessage());
}

$dateStr = date('Y-m-d H:i:s');
$htmlContent = "
<p>--------------------------------</p>
<p><b>New Feedback Received - Vitahar</b></p>
<p>--------------------------------</p>
<p><b>Name:</b><br/>" . htmlspecialchars($name) . "</p>
<p><b>Email:</b><br/>" . htmlspecialchars($email) . "</p>
<p><b>Message:</b><br/>" . nl2br(htmlspecialchars($message)) . "</p>
<p><b>Submitted:</b><br/>{$dateStr}</p>
<p>--------------------------------</p>
<p>Vitahar Feedback System</p>
<p>--------------------------------</p>
";

$textContent = "
--------------------------------
New Feedback Received - Vitahar
--------------------------------

Name:
{$name}

Email:
{$email}

Message:
{$message}

Submitted:
{$dateStr}

--------------------------------
Vitahar Feedback System
--------------------------------
";

$postData = [
    'sender' => [
        'name' => $senderName,
        'email' => $senderEmail
    ],
    'to' => [
        [
            'email' => $receiverEmail,
            'name' => 'Vitahar Admin'
        ]
    ],
    'replyTo' => [
        'email' => $email,
        'name' => $name
    ],
    'subject' => 'New Vitahar Feedback',
    'htmlContent' => $htmlContent,
    'textContent' => $textContent
];

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'api-key: ' . $brevoApiKey,
    'content-type: application/json'
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    respond(['success' => true]);
} else {
    $errorMsg = "Unable to send email right now.";
    if ($curlError) {
        $errorMsg .= " cURL Error: " . $curlError;
    } else {
        $errorMsg .= " Brevo Error (" . $httpCode . "): " . $response;
    }
    error_log("Brevo API error: " . $response . " cURL: " . $curlError);
    
    if ($dbSuccess) {
        // If DB insertion succeeded, still return success to the user so they know their feedback is recorded.
        respond(['success' => true, 'warning' => 'Email failed, but saved to DB.']);
    } else {
        // Only return an error if BOTH database AND email failed
        respond(['error' => 'Unable to save or send feedback right now. Please try again later.'], 500);
    }
}
