<?php
// ============================================================
//  VITAHAR API — api.php
//  Upload to InfinityFree /htdocs (same folder as index.html)
// ============================================================

function isAdminSession() {
  return isset($_SESSION['vitahar_admin_auth']) &&
         $_SESSION['vitahar_admin_auth'] === true &&
         isset($_SESSION['login_time']) &&
         (time() - $_SESSION['login_time']) < 3600;
}
function requireAdmin() {
  if (!isAdminSession()) respond(['error' => 'Admin login required'], 403);
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

define('DB_HOST', $__env['DB_HOST'] ?? getenv('DB_HOST') ?: '');
define('DB_NAME', $__env['DB_NAME'] ?? getenv('DB_NAME') ?: '');
define('DB_USER', $__env['DB_USER'] ?? getenv('DB_USER') ?: '');
define('DB_PASS', $__env['DB_PASS'] ?? getenv('DB_PASS') ?: '');

if (DB_PASS === '') {
  http_response_code(500);
  die(json_encode(['error' => 'Server misconfigured: set DB_HOST, DB_NAME, DB_USER, DB_PASS in .env']));
}

// ── DB-BACKED SESSIONS ──────────────────────────────────────────
// Must match admin.php's session handler exactly — both files share
// the same login cookie, and on load-balanced free hosting the
// default file-based session storage isn't visible across servers.
class DBSessionHandler implements SessionHandlerInterface {
  private $pdo;
  public function __construct($pdo) { $this->pdo = $pdo; }
  public function open($savePath, $sessionName): bool {
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_sessions (
      id VARCHAR(128) PRIMARY KEY,
      data TEXT,
      updated_at INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return true;
  }
  public function close(): bool { return true; }
  public function read($id): string|false {
    $stmt = $this->pdo->prepare("SELECT data FROM admin_sessions WHERE id=:id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? $row['data'] : '';
  }
  public function write($id, $data): bool {
    $stmt = $this->pdo->prepare("INSERT INTO admin_sessions (id,data,updated_at) VALUES (:id,:data,:t)
      ON DUPLICATE KEY UPDATE data=:data2, updated_at=:t2");
    return $stmt->execute([':id'=>$id, ':data'=>$data, ':t'=>time(), ':data2'=>$data, ':t2'=>time()]);
  }
  public function destroy($id): bool {
    $stmt = $this->pdo->prepare("DELETE FROM admin_sessions WHERE id=:id");
    return $stmt->execute([':id'=>$id]);
  }
  public function gc($maxlifetime): int|false {
    $stmt = $this->pdo->prepare("DELETE FROM admin_sessions WHERE updated_at < :cutoff");
    $stmt->execute([':cutoff' => time() - $maxlifetime]);
    return $stmt->rowCount();
  }
}
try {
  $__sessionPdo = new PDO(
    'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
  );
  session_set_save_handler(new DBSessionHandler($__sessionPdo), true);
} catch (PDOException $e) {
  // fall back to default file sessions rather than fatally dying
}
session_set_cookie_params([
  'lifetime' => 3600,
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function getDB() {
  static $pdo = null;
  if ($pdo) return $pdo;
  try {
    $pdo = new PDO(
      'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
      DB_USER, DB_PASS,
      [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
      ]
    );
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
  }
  return $pdo;
}

function respond($data, $code = 200) {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function qident($name) {
  return '`' . str_replace('`', '``', $name) . '`';
}

function ensureCompanyTables($db) {
  static $done = false;
  if ($done) return;
  $db->exec("CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS company_products (
    company_id INT NOT NULL,
    food_id INT NOT NULL,
    UNIQUE KEY uniq_company_food (company_id, food_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $done = true;
}

function ensureSettingsTable($db) {
  static $done = false;
  if ($done) return;
  $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $done = true;
}

function getFoodColumns($db) {
  static $cols = null;
  if ($cols !== null) return $cols;
  $cols = $db->query("DESCRIBE foods")->fetchAll();
  return $cols;
}

function getHiddenNutritionColumns($db) {
  ensureSettingsTable($db);
  $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key='public_hidden_nutrition_columns' LIMIT 1");
  $stmt->execute();
  $value = $stmt->fetchColumn();
  $decoded = $value ? json_decode($value, true) : [];
  return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
}

function nutritionUnitFor($field, $type) {
  $key = strtolower($field);
  if (str_contains($key, 'calorie') || str_contains($key, 'energy')) return 'kcal';
  if (str_contains($key, 'salt') || str_contains($key, 'sodium')) return 'mg';
  if (str_contains($key, 'percent') || str_contains($key, 'percentage')) return '%';
  if (preg_match('/int|float|double|decimal/i', $type)) return 'g';
  return '';
}

function getNutritionColumns($db, $onlyVisible = false) {
  $meta = ['id','name','source','barcode','created_at','updated_at'];
  $hidden = getHiddenNutritionColumns($db);
  $hiddenLookup = array_fill_keys($hidden, true);
  $columns = [];
  foreach (getFoodColumns($db) as $col) {
    $field = $col['Field'];
    if (in_array($field, $meta, true)) continue;
    $visible = !isset($hiddenLookup[$field]);
    if ($onlyVisible && !$visible) continue;
    $columns[] = [
      'key'     => $field,
      'label'   => ucwords(str_replace('_', ' ', $field)),
      'type'    => $col['Type'],
      'unit'    => nutritionUnitFor($field, $col['Type']),
      'core'    => in_array($field, ['calories','sugar','salt','fat'], true),
      'visible' => $visible,
    ];
  }
  return $columns;
}

function normalizeFoodValue($value, $type) {
  if ($value === null) return null;
  if (preg_match('/int|float|double|decimal/i', $type)) return (float)$value;
  return $value;
}

function hasFilledCustomValue($value) {
  if ($value === null) return false;
  if (is_string($value) && trim($value) === '') return false;
  if (is_numeric($value) && (float)$value == 0.0) return false;
  return true;
}

function nutritionPayloadFromRow($row, $columns) {
  $nutrition = [];
  foreach ($columns as $col) {
    $key = $col['key'];
    if (!array_key_exists($key, $row)) continue;
    $value = normalizeFoodValue($row[$key], $col['type']);
    // For core nutrients always include (even 0); for custom columns skip if blank/zero
    if ($col['core']) {
      if ($value === null) continue;
      $nutrition[$key] = $value;
    } else {
      if (!hasFilledCustomValue($value)) continue;
      $nutrition[$key] = $value;
    }
  }
  return $nutrition;
}

function companyForFood($db, $foodId) {
  ensureCompanyTables($db);
  $stmt = $db->prepare(
    "SELECT c.name
     FROM companies c
     INNER JOIN company_products cp ON cp.company_id = c.id
     WHERE cp.food_id = :id
     ORDER BY c.name
     LIMIT 1"
  );
  $stmt->execute([':id' => $foodId]);
  return $stmt->fetchColumn() ?: '';
}

function findFoodId($db, $name, $barcode = null) {
  if ($barcode) {
    $stmt = $db->prepare("SELECT id FROM foods WHERE barcode = :bc LIMIT 1");
    $stmt->execute([':bc' => $barcode]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
  }
  $stmt = $db->prepare("SELECT id FROM foods WHERE name = :name LIMIT 1");
  $stmt->execute([':name' => $name]);
  $id = $stmt->fetchColumn();
  return $id ? (int)$id : 0;
}

function linkProductCompany($db, $foodId, $companyName) {
  $companyName = trim($companyName);
  if (!$companyName || strlen($companyName) > 255) return;
  ensureCompanyTables($db);

  // Always insert the company (so it shows in admin even if food link fails)
  $stmt = $db->prepare("INSERT IGNORE INTO companies (name) VALUES (:name)");
  $stmt->execute([':name' => $companyName]);

  $stmt = $db->prepare("SELECT id FROM companies WHERE name = :name LIMIT 1");
  $stmt->execute([':name' => $companyName]);
  $companyId = (int)$stmt->fetchColumn();
  if (!$companyId) return;

  // Only link to food if we have a valid food id
  if (!$foodId) return;

  $stmt = $db->prepare("INSERT IGNORE INTO company_products (company_id, food_id) VALUES (:cid, :fid)");
  $stmt->execute([':cid' => $companyId, ':fid' => $foodId]);
}

function ensureAgeLimitsTable($db) {
  ensureSettingsTable($db);
}

function getAgeLimitsFromDB($db) {
  ensureAgeLimitsTable($db);
  $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key='age_limits' LIMIT 1");
  $stmt->execute();
  $value = $stmt->fetchColumn();
  if ($value) {
    $decoded = json_decode($value, true);
    if (is_array($decoded)) return $decoded;
  }
  // Default values
  return [
    'age_0_5'    => ['calories'=>200, 'sugar'=>8,  'salt'=>300,  'fat'=>12, 'protein'=>13, 'fiber'=>14],
    'age_5_12'   => ['calories'=>350, 'sugar'=>15, 'salt'=>700,  'fat'=>20, 'protein'=>20, 'fiber'=>18],
    'age_12_18'  => ['calories'=>450, 'sugar'=>25, 'salt'=>1200, 'fat'=>28, 'protein'=>34, 'fiber'=>22],
    'age_18_30'  => ['calories'=>500, 'sugar'=>30, 'salt'=>1600, 'fat'=>35, 'protein'=>50, 'fiber'=>25],
    'age_30_60'  => ['calories'=>450, 'sugar'=>25, 'salt'=>1400, 'fat'=>30, 'protein'=>46, 'fiber'=>25],
    'age_60_plus'=> ['calories'=>400, 'sugar'=>20, 'salt'=>1000, 'fat'=>25, 'protein'=>40, 'fiber'=>21],
  ];
}

function saveAgeLimitsToDB($db, $limits) {
  ensureSettingsTable($db);
  $stmt = $db->prepare(
    "INSERT INTO site_settings (setting_key, setting_value)
     VALUES ('age_limits', :value)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
  );
  $stmt->execute([':value' => json_encode($limits)]);
}


function ensureOptionalFoodColumns($db) {
  static $done = false;
  if ($done) return;
  try { $db->exec("ALTER TABLE foods ADD COLUMN protein FLOAT DEFAULT 0"); } catch(Exception $e){}
  try { $db->exec("ALTER TABLE foods ADD COLUMN fiber   FLOAT DEFAULT 0"); } catch(Exception $e){}
  $done = true;
}

// ── Ensure the foods table has created_at / updated_at timestamps ──────────
function ensureTimestampColumns($db) {
  static $done = false;
  if ($done) return;
  try { $db->exec("ALTER TABLE foods ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (PDOException $e) {}
  try { $db->exec("ALTER TABLE foods ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (PDOException $e) {}
  $done = true;
}

// ── Ensure the barcode column exists (safe to call multiple times) ──────────
function ensureBarcodeColumn($db) {
  static $done = false;
  if ($done) return;
  try {
    $db->exec("ALTER TABLE foods ADD COLUMN barcode VARCHAR(30) NULL DEFAULT NULL AFTER source");
  } catch (PDOException $e) { /* column already exists — ignore */ }
  try {
    $db->exec("CREATE UNIQUE INDEX idx_foods_barcode ON foods (barcode)");
  } catch (PDOException $e) { /* index already exists — ignore */ }
  $done = true;
}

// ── Ensure the anomaly column exists for flagged foods ──────────
function ensureAnomalyColumn($db) {
  static $done = false;
  if ($done) return;
  try {
    $db->exec("ALTER TABLE foods ADD COLUMN anomaly TINYINT(1) DEFAULT 0");
  } catch (PDOException $e) { /* column already exists — ignore */ }
  $done = true;
}

$action = $_GET['action'] ?? '';

match($action) {
  'bootstrap'   => actionAll(),
  'suggest'     => actionSuggest(),
  'search'      => actionSearch(),
  'barcode'     => actionBarcode(),
  'save'        => actionSave(),
  'companies'   => actionCompanies(),
  'alternatives'=> actionAlternatives(),
  'report_food' => actionReportFood(),
  'all'         => actionAll(),
  'get_age_limits'  => actionGetAgeLimits(),
  'save_age_limits' => actionSaveAgeLimits(),
  'get_site_content'  => actionGetSiteContent(),
  'save_site_content' => actionSaveSiteContent(),
  default       => respond(['error' => 'Unknown action'], 400)
};

// ── Look up a product by barcode (EAN-8/EAN-13/UPC-A) ───────────────────────
function actionBarcode() {
  $bc = trim($_GET['bc'] ?? '');
  if (!$bc || !preg_match('/^\d{6,14}$/', $bc)) {
    respond(['found' => false]);
  }
  $db = getDB();
  ensureBarcodeColumn($db);
  ensureOptionalFoodColumns($db);
  $columns = getNutritionColumns($db);
  $stmt = $db->prepare(
    "SELECT * FROM foods WHERE barcode = :bc LIMIT 1"
  );
  $stmt->execute([':bc' => $bc]);
  $row = $stmt->fetch();
  if (!$row) respond(['found' => false]);
  respond([
    'found'       => true,
    'productName' => $row['name'],
    'companyName' => companyForFood($db, (int)$row['id']),
    'source'      => 'MySQL',
    'nutrition'   => nutritionPayloadFromRow($row, $columns),
    'nutritionColumns' => $columns
  ]);
}

function actionSuggest() {
  $q = trim($_GET['q'] ?? '');
  if (strlen($q) < 2) respond([]);
  $db = getDB();
  $stmt = $db->prepare("SELECT name FROM foods WHERE name LIKE :q LIMIT 8");
  $stmt->execute([':q' => '%' . $q . '%']);
  respond(array_column($stmt->fetchAll(), 'name'));
}

function actionSearch() {
  $q = trim($_GET['q'] ?? '');
  if (!$q) respond(['error' => 'Missing query'], 400);
  $db = getDB();
  ensureOptionalFoodColumns($db);
  $columns = getNutritionColumns($db);
  $stmt = $db->prepare("SELECT * FROM foods WHERE name = :q LIMIT 1");
  $stmt->execute([':q' => $q]);
  $row = $stmt->fetch();
  if (!$row) {
    $stmt = $db->prepare("SELECT * FROM foods WHERE name LIKE :q LIMIT 1");
    $stmt->execute([':q' => '%' . $q . '%']);
    $row = $stmt->fetch();
  }
  if (!$row) respond(['found' => false]);
  respond([
    'found'       => true,
    'productName' => $row['name'],
    'companyName' => companyForFood($db, (int)$row['id']),
    'source'      => $row['source'],
    'nutrition'   => nutritionPayloadFromRow($row, $columns),
    'nutritionColumns' => $columns
  ]);
}

function actionAlternatives() {
  $productName = trim($_GET['productName'] ?? '');
  $sugar = (float)($_GET['sugar'] ?? 0);
  $salt = (float)($_GET['salt'] ?? 0);
  
  global $__env;
  $apiKey = $__env['OPENROUTER_API_KEY'] ?? '';
  
  if ($apiKey !== '' && $productName !== '') {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, "https://openrouter.ai/api/v1/chat/completions");
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      
      $headers = [
          "Authorization: Bearer " . $apiKey,
          "Content-Type: application/json"
      ];
      
      $prompt = "Suggest 3 healthier alternatives for '{$productName}'. They MUST be in the exact same food category (e.g., if user searches for instant noodles, suggest healthier noodles. If cola, suggest healthier drinks). Return ONLY a raw JSON array of 3 objects with keys: 'productName' (string), 'companyName' (string, can be 'Various' if generic), and 'nutrition' (object with 'sugar' and 'salt' as numbers representing grams/mg per serving). No markdown, no extra text.";
      
      $postData = [
          "model" => "openrouter/free",
          "messages" => [
              ["role" => "system", "content" => "You are a health AI. Output only valid raw JSON array."],
              ["role" => "user", "content" => $prompt]
          ]
      ];
      
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
      
      $response = curl_exec($ch);
      curl_close($ch);
      
      if ($response) {
          $responseData = json_decode($response, true);
          $aiMessage = $responseData['choices'][0]['message']['content'] ?? '';
          $aiMessage = trim($aiMessage);
          if (strpos($aiMessage, '```json') === 0) $aiMessage = substr($aiMessage, 7);
          elseif (strpos($aiMessage, '```') === 0) $aiMessage = substr($aiMessage, 3);
          if (substr($aiMessage, -3) === '```') $aiMessage = substr($aiMessage, 0, -3);
          $aiMessage = trim($aiMessage);
          
          $parsed = json_decode($aiMessage, true);
          if (is_array($parsed) && count($parsed) > 0 && isset($parsed[0]['productName'])) {
              // Valid AI response
              respond($parsed);
              return;
          }
      }
  }

  // Fallback to database if API fails or is not configured
  $db = getDB();
  ensureOptionalFoodColumns($db);
  $columns = getNutritionColumns($db);
  
  $stmt = $db->prepare("
    SELECT * FROM foods 
    WHERE name != :name 
      AND (sugar < :sugar OR salt < :salt)
      AND sugar < 15
      AND salt < 500
    ORDER BY sugar ASC, salt ASC 
    LIMIT 3
  ");
  $stmt->execute([
    ':name' => $productName,
    ':sugar' => max(5, $sugar), 
    ':salt' => max(100, $salt)
  ]);
  $rows = $stmt->fetchAll();
  
  $alts = [];
  foreach ($rows as $row) {
    $alts[] = [
      'productName' => $row['name'],
      'companyName' => companyForFood($db, (int)$row['id']),
      'nutrition'   => nutritionPayloadFromRow($row, $columns)
    ];
  }
  respond($alts);
}

function actionReportFood() {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'POST required'], 405);
  $body = json_decode(file_get_contents('php://input'), true);
  $productName = $body['name'] ?? '';
  if (!$productName) respond(['ok' => false, 'error' => 'No name provided'], 400);

  $db = getDB();
  ensureAnomalyColumn($db);

  $stmt = $db->prepare("UPDATE foods SET anomaly = 1 WHERE name = :name");
  $stmt->execute([':name' => $productName]);

  respond(['ok' => true, 'msg' => 'Reported successfully']);
}

// ── Save / upsert a product ──────────────────────────────────────────────────
// Now accepts an optional "barcode" field so scanned barcodes are stored.
function actionSave() {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'POST required'], 405);
  $body = json_decode(file_get_contents('php://input'), true);
  if (!$body) respond(['error' => 'Invalid JSON'], 400);

  $name      = trim($body['name']    ?? '');
  $company   = trim($body['company'] ?? $body['companyName'] ?? '');
  $barcode   = trim($body['barcode'] ?? '');
  $nutrition = is_array($body['nutrition'] ?? null) ? $body['nutrition'] : $body;
  $calories  = (float)($nutrition['calories'] ?? 0);
  $sugar     = (float)($nutrition['sugar']    ?? 0);
  $salt      = (float)($nutrition['salt']     ?? 0);
  $fat       = (float)($nutrition['fat']      ?? 0);
  $source    = in_array(
    $body['source'] ?? '',
    ['OFF','OFF-IN','Open Food Facts','Open Food Facts (India)','Open Products Facts','USDA','DK','Datakick','UPC','UPC Item DB','barcode','local','api','external','MySQL']
  ) ? $body['source'] : 'api';

  $protein = (float)($nutrition['protein'] ?? $body['protein'] ?? 0);
  $fiber   = (float)($nutrition['fiber']   ?? $body['fiber']   ?? 0);

  if (!$name || strlen($name) > 255) respond(['error' => 'Invalid name'], 400);
  if ($calories + $sugar + $salt + $fat + $protein + $fiber === 0.0)
    respond(['saved' => false, 'reason' => 'No nutrition data']);

  // Only store numeric barcodes of plausible length (EAN-8 to EAN-14)
  $bc = preg_match('/^\d{6,14}$/', $barcode) ? $barcode : null;

  $db = getDB();
  ensureBarcodeColumn($db);
  ensureTimestampColumns($db);
  ensureOptionalFoodColumns($db);  // ensures protein + fiber columns exist

  $stmt = $db->prepare(
    "INSERT INTO foods (name, calories, sugar, salt, fat, protein, fiber, source, barcode)
     VALUES (:name, :cal, :sugar, :salt, :fat, :protein, :fiber, :source, :bc)
     ON DUPLICATE KEY UPDATE
       calories = VALUES(calories),
       sugar    = VALUES(sugar),
       salt     = VALUES(salt),
       fat      = VALUES(fat),
       protein  = VALUES(protein),
       fiber    = VALUES(fiber),
       source   = VALUES(source),
       barcode  = COALESCE(VALUES(barcode), barcode)"
  );
  $stmt->execute([
    ':name'    => $name,
    ':cal'     => $calories,
    ':sugar'   => $sugar,
    ':salt'    => $salt,
    ':fat'     => $fat,
    ':protein' => $protein,
    ':fiber'   => $fiber,
    ':source'  => $source,
    ':bc'      => $bc,
  ]);

  // Always link company when provided — even if food already existed (ON DUPLICATE KEY UPDATE).
  // lastInsertId() returns 0 for duplicate-key updates, so always fall back to a SELECT lookup.
  if ($company) {
    $foodId = (int)$db->lastInsertId();
    if (!$foodId) {
      // Try barcode first (more precise), then fall back to name
      $foodId = $bc ? findFoodId($db, $name, $bc) : 0;
      if (!$foodId) $foodId = findFoodId($db, $name, null);
    }
    // linkProductCompany saves the company row even if $foodId is still 0
    linkProductCompany($db, $foodId, $company);
  }

  respond(['saved' => true, 'name' => $name, 'company' => $company]);
}

function actionCompanies() {
  $q = trim($_GET['q'] ?? '');
  if (strlen($q) < 2) respond([]);
  $db = getDB();
  ensureCompanyTables($db);
  $stmt = $db->prepare(
    "SELECT c.name AS company,
            GROUP_CONCAT(f.name ORDER BY f.name SEPARATOR '||') AS products
     FROM companies c
     LEFT JOIN company_products cp ON cp.company_id = c.id
     LEFT JOIN foods f ON f.id = cp.food_id
     WHERE c.name LIKE :q GROUP BY c.id LIMIT 6"
  );
  $stmt->execute([':q' => '%' . $q . '%']);
  $result = [];
  foreach ($stmt->fetchAll() as $row) {
    $result[] = [
      'name'     => $row['company'],
      'products' => $row['products'] ? explode('||', $row['products']) : [],
    ];
  }
  respond($result);
}

function actionGetAgeLimits() {
  $db = getDB();
  respond(['ok' => true, 'limits' => getAgeLimitsFromDB($db)]);
}

// ── Site Content (admin-editable text used on index.html / maggi.html) ────
function siteContentKeys() {
  return [
    'home_hero_title', 'home_hero_subtitle',
    'maggi_intro_html',
    'maggi_faq_1_q', 'maggi_faq_1_a',
    'maggi_faq_2_q', 'maggi_faq_2_a',
    'maggi_faq_3_q', 'maggi_faq_3_a',
    'maggi_faq_4_q', 'maggi_faq_4_a',
  ];
}

function ensureSiteSettingsTable($db) {
  $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function actionGetSiteContent() {
  $keys = siteContentKeys();
  $db = getDB();
  ensureSiteSettingsTable($db);
  $placeholders = implode(',', array_fill(0, count($keys), '?'));
  $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
  $stmt->execute($keys);
  $out = [];
  foreach ($stmt->fetchAll() as $row) $out[$row['setting_key']] = $row['setting_value'];
  respond(['ok' => true, 'content' => $out]);
}

function actionSaveSiteContent() {
  $keys = siteContentKeys();
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'POST required'], 405);
  requireAdmin();
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) respond(['ok' => false, 'msg' => 'Invalid data'], 400);
  $db = getDB();
  ensureSiteSettingsTable($db);
  $stmt = $db->prepare(
    "INSERT INTO site_settings (setting_key, setting_value) VALUES (:k, :v)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
  );
  $saved = 0;
  foreach ($keys as $key) {
    if (!array_key_exists($key, $body)) continue;
    $stmt->execute([':k' => $key, ':v' => (string)$body[$key]]);
    $saved++;
  }
  respond(['ok' => true, 'msg' => "Saved $saved field(s)"]);
}

function actionSaveAgeLimits() {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'POST required'], 405);
  requireAdmin();
  $body = json_decode(file_get_contents('php://input'), true);
  if (!$body || !isset($body['limits']) || !is_array($body['limits'])) {
    respond(['ok' => false, 'msg' => 'Invalid data'], 400);
  }
  $db = getDB();
  saveAgeLimitsToDB($db, $body['limits']);
  respond(['ok' => true, 'msg' => 'Age limits saved']);
}

function actionAll() {
  $db = getDB();
  ensureOptionalFoodColumns($db);
  ensureBarcodeColumn($db);
  ensureAnomalyColumn($db);
  $columns = getNutritionColumns($db, false); // get all columns (visible + hidden meta)
  $visibleColumns = getNutritionColumns($db, true); // only visible ones sent to public

  $foods = [];
  foreach ($db->query("SELECT * FROM foods") as $row) {
    $foods[$row['name']] = nutritionPayloadFromRow($row, $visibleColumns);
  }

  $companies = [];
  ensureCompanyTables($db);
  foreach ($db->query(
    "SELECT c.name AS company,
            GROUP_CONCAT(f.name ORDER BY f.name SEPARATOR '||') AS products
     FROM companies c
     LEFT JOIN company_products cp ON cp.company_id = c.id
     LEFT JOIN foods f ON f.id = cp.food_id
     GROUP BY c.id"
  ) as $row) {
    $companies[$row['company']] = $row['products'] ? explode('||', $row['products']) : [];
  }

  respond([
    'foods'            => $foods,
    'companies'        => $companies,
    'nutritionColumns' => $visibleColumns,
    'ageLimits'        => getAgeLimitsFromDB($db),
  ]);
}