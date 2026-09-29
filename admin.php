<?php
// ============================================================
//  VITAHAR ADMIN PANEL  —  admin.php  (v2.0 UPGRADED)
//  Upload to InfinityFree /htdocs (same folder as index.html)
//  Access: https://vitahar.kesug.com/admin.php
// ============================================================

// ── SECURITY CONFIG ──────────────────────────────────────────
// Credentials are loaded from .env (see loadEnv() below) instead of
// being hardcoded here. Set ADMIN_USERNAME_ENV / ADMIN_PASSWORD_ENV /
// DB_HOST / DB_NAME / DB_USER / DB_PASS in your .env file.
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

define('ADMIN_USERNAME', $__env['ADMIN_USERNAME'] ?? getenv('ADMIN_USERNAME') ?: 'vitahar_admin');
define('ADMIN_PASSWORD', $__env['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: '');
define('ADMIN_SESSION_KEY', 'vitahar_admin_auth');
define('SESSION_LIFETIME', 3600); // 1 hour

// ── DB CONFIG ────────────────────────────────────────────────
define('DB_HOST', $__env['DB_HOST'] ?? getenv('DB_HOST') ?: '');
define('DB_NAME', $__env['DB_NAME'] ?? getenv('DB_NAME') ?: '');
define('DB_USER', $__env['DB_USER'] ?? getenv('DB_USER') ?: '');
define('DB_PASS', $__env['DB_PASS'] ?? getenv('DB_PASS') ?: '');

if (ADMIN_PASSWORD === '' || DB_PASS === '') {
  die('Server misconfigured: set ADMIN_USERNAME, ADMIN_PASSWORD, DB_HOST, DB_NAME, DB_USER, DB_PASS in .env');
}

// ── DB-BACKED SESSIONS ──────────────────────────────────────────
// InfinityFree (and most free hosts) load-balance requests across
// multiple servers that do NOT share local session file storage.
// With PHP's default file-based sessions, a login written on server A
// can be invisible on the very next request if it lands on server B —
// the admin panel then silently looks "logged out" on AJAX calls,
// which return the HTML login page instead of JSON and break every
// fetch() call in script.js (shows as "Network error" + '?' stats).
// Storing sessions in MySQL instead makes them visible to every server.
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
  // DB unreachable — fall back to default file sessions rather than
  // fatally dying (login reliability across servers won't be fixed,
  // but the rest of the site keeps working).
}

session_set_cookie_params([
  'lifetime' => SESSION_LIFETIME,
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

// ── HELPERS ──────────────────────────────────────────────────
function isLoggedIn() {
  return isset($_SESSION[ADMIN_SESSION_KEY]) &&
         $_SESSION[ADMIN_SESSION_KEY] === true &&
         isset($_SESSION['login_time']) &&
         (time() - $_SESSION['login_time']) < SESSION_LIFETIME;
}

function getDB() {
  static $pdo = null;
  if ($pdo) return $pdo;
  try {
    $pdo = new PDO(
      'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
      DB_USER, DB_PASS,
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
       PDO::ATTR_EMULATE_PREPARES=>false]
    );
  } catch (PDOException $e) {
    die(json_encode(['error'=>'DB Error: '.$e->getMessage()]));
  }
  return $pdo;
}

// Auto-create activity_log table if it doesn't exist
function ensureLogTable($db) {
  $db->exec("CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(100) NOT NULL,
    detail TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function logAction($db, $action, $detail='') {
  try {
    ensureLogTable($db);
    $db->prepare("INSERT INTO activity_log (action, detail) VALUES (:a,:d)")
       ->execute([':a'=>$action, ':d'=>$detail]);
  } catch(Exception $e) {}
}

function qident($name) {
  return '`'.str_replace('`','``',$name).'`';
}

function ensureSettingsTable($db) {
  $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureOptionalFoodColumns($db) {
  try { $db->exec("ALTER TABLE foods ADD COLUMN protein FLOAT DEFAULT 0"); } catch(Exception $e){}
  try { $db->exec("ALTER TABLE foods ADD COLUMN fiber FLOAT DEFAULT 0"); } catch(Exception $e){}
}

function getFoodColumns($db) {
  return $db->query("DESCRIBE foods")->fetchAll();
}

function getFoodColumnMap($db) {
  $map = [];
  foreach (getFoodColumns($db) as $col) $map[$col['Field']] = $col;
  return $map;
}

function hiddenNutritionColumns($db) {
  ensureSettingsTable($db);
  $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key='public_hidden_nutrition_columns' LIMIT 1");
  $stmt->execute();
  $value = $stmt->fetchColumn();
  $decoded = $value ? json_decode($value, true) : [];
  return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
}

function saveHiddenNutritionColumns($db, $hidden) {
  ensureSettingsTable($db);
  $clean = [];
  foreach ($hidden as $field) {
    $field = preg_replace('/[^a-zA-Z0-9_]/','', (string)$field);
    if ($field) $clean[] = $field;
  }
  $clean = array_values(array_unique($clean));
  $stmt = $db->prepare(
    "INSERT INTO site_settings (setting_key, setting_value)
     VALUES ('public_hidden_nutrition_columns', :value)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
  );
  $stmt->execute([':value'=>json_encode($clean)]);
  return $clean;
}

function nutritionUnitFor($field, $type) {
  $key = strtolower($field);
  if (str_contains($key, 'calorie') || str_contains($key, 'energy')) return 'kcal';
  if (str_contains($key, 'salt') || str_contains($key, 'sodium')) return 'mg';
  if (str_contains($key, 'percent') || str_contains($key, 'percentage')) return '%';
  if (preg_match('/int|float|double|decimal/i', $type)) return 'g';
  return '';
}

function publicNutritionColumns($db, $onlyVisible=false) {
  $skip = ['id','name','source','barcode','created_at','updated_at'];
  $hidden = hiddenNutritionColumns($db);
  $hiddenLookup = array_fill_keys($hidden, true);
  $cols = [];
  foreach (getFoodColumns($db) as $col) {
    $field = $col['Field'];
    if (in_array($field, $skip, true)) continue;
    $visible = !isset($hiddenLookup[$field]);
    if ($onlyVisible && !$visible) continue;
    $cols[] = [
      'Field'=>$field,
      'Type'=>$col['Type'],
      'Null'=>$col['Null'],
      'Default'=>$col['Default'],
      'label'=>ucwords(str_replace('_',' ',$field)),
      'unit'=>nutritionUnitFor($field, $col['Type']),
      'core'=>in_array($field, ['calories','sugar','salt','fat'], true),
      'visible'=>$visible,
    ];
  }
  return $cols;
}

function editableExtraFoodColumns($db) {
  $static = ['id','name','calories','sugar','salt','fat','protein','fiber','source','barcode','created_at','updated_at'];
  return array_values(array_filter(getFoodColumns($db), fn($col) => !in_array($col['Field'], $static, true)));
}

function coerceFoodColumnValue($value, $type) {
  if (preg_match('/tinyint\(1\)/i', $type)) return !empty($value) ? 1 : 0;
  if (preg_match('/int/i', $type)) return (int)$value;
  if (preg_match('/float|double|decimal/i', $type)) return (float)$value;
  if (preg_match('/date/i', $type)) {
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
  }
  return trim((string)$value);
}

function removeHiddenNutritionColumn($db, $field) {
  $hidden = array_values(array_filter(hiddenNutritionColumns($db), fn($name) => $name !== $field));
  saveHiddenNutritionColumns($db, $hidden);
}

function renameHiddenNutritionColumn($db, $old, $new) {
  $hidden = hiddenNutritionColumns($db);
  $changed = false;
  foreach ($hidden as &$field) {
    if ($field === $old) {
      $field = $new;
      $changed = true;
    }
  }
  if ($changed) saveHiddenNutritionColumns($db, $hidden);
}

// ── AJAX ACTIONS ─────────────────────────────────────────────
$ajaxAction = $_GET['ajax'] ?? '';
if ($ajaxAction && !isLoggedIn()) {
  // Previously this case fell through with no output, so the script
  // kept executing and rendered the full HTML admin/login page. The
  // frontend's fetch() would then try to JSON-parse that HTML and
  // throw, which is what surfaced as "❌ Network error" in the UI.
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(401);
  echo json_encode(['error' => 'not_logged_in']);
  exit;
}
if ($ajaxAction && isLoggedIn()) {
  header('Content-Type: application/json; charset=utf-8');
  $db = getDB();

  try {

  switch ($ajaxAction) {

    // ── Stats ────────────────────────────────────────────────
    case 'stats':
      $foods     = $db->query("SELECT COUNT(*) FROM foods")->fetchColumn();
      $companies = $db->query("SELECT COUNT(*) FROM companies")->fetchColumn();
      $tables    = [];
      foreach ($db->query("SHOW TABLES") as $r) $tables[] = array_values($r)[0];
      // Count foods added from external APIs (not manually by admin)
      $apiSources = ['OFF','OFF-IN','Open Food Facts','Open Food Facts (India)','Open Products Facts','USDA','DK','Datakick','UPC','UPC Item DB','barcode','api','external'];
      $placeholders = implode(',', array_fill(0, count($apiSources), '?'));
      $apiNew = $db->prepare("SELECT COUNT(*) FROM foods WHERE source IN ($placeholders)");
      $apiNew->execute($apiSources);
      $apiNewCount = $apiNew->fetchColumn();
      // Recent activity count
      $logCount = 0;
      try { ensureLogTable($db); $logCount = $db->query("SELECT COUNT(*) FROM activity_log")->fetchColumn(); } catch(Exception $e){}
      echo json_encode(['foods'=>$foods,'companies'=>$companies,'tables'=>$tables,'log_count'=>$logCount,'api_new'=>$apiNewCount]);
      break;

    // ── List foods (paginated + search + filter) ─────────────
    case 'list_foods':
      ensureOptionalFoodColumns($db);
      $page  = max(1,(int)($_GET['page'] ?? 1));
      $limit = (int)($_GET['limit'] ?? 20);
      if ($limit<1||$limit>200) $limit=20;
      $off   = ($page-1)*$limit;
      $q     = trim($_GET['q'] ?? '');
      $src   = trim($_GET['source'] ?? '');
      $where = []; $params = [];
      if ($q) { $where[]='name LIKE :q'; $params[':q']="%$q%"; }
      if ($src) { $where[]='source=:src'; $params[':src']=$src; }
      $whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
      $stmt = $db->prepare("SELECT SQL_CALC_FOUND_ROWS * FROM foods $whereSQL ORDER BY id DESC LIMIT $limit OFFSET $off");
      $stmt->execute($params);
      $rows  = $stmt->fetchAll();
      $total = $db->query("SELECT FOUND_ROWS()")->fetchColumn();
      echo json_encode(['rows'=>$rows,'columns'=>getFoodColumns($db),'nutrition_columns'=>publicNutritionColumns($db),'total'=>(int)$total,'page'=>$page,'limit'=>$limit]);
      break;

    // ── Add / Update food ────────────────────────────────────
    case 'upsert_food':
      $b  = json_decode(file_get_contents('php://input'),true) ?? [];
      $id = (int)($b['id'] ?? 0);
      $name    = trim($b['name']    ?? '');
      $cal     = (float)($b['calories'] ?? 0);
      $sugar   = (float)($b['sugar']   ?? 0);
      $salt    = (float)($b['salt']    ?? 0);
      $fat     = (float)($b['fat']     ?? 0);
      $protein = (float)($b['protein'] ?? 0);
      $fiber   = (float)($b['fiber']   ?? 0);
      $source  = trim($b['source']  ?? 'admin');
      $barcode = trim($b['barcode'] ?? '');
      $bc      = preg_match('/^\d{6,14}$/',$barcode) ? $barcode : null;
      if (!$name) { echo json_encode(['ok'=>false,'msg'=>'Name required']); break; }
      ensureOptionalFoodColumns($db);
      $extraCols = editableExtraFoodColumns($db);
      $extraValues = [];
      foreach ($extraCols as $col) {
        $field = $col['Field'];
        $extraValues[$field] = coerceFoodColumnValue($b[$field] ?? '', $col['Type']);
      }
      if ($id) {
        $sets = ["name=:n","calories=:c","sugar=:s","salt=:sa","fat=:f","protein=:p","fiber=:fi","source=:src","barcode=:bc","anomaly=0"];
        $params = [':n'=>$name,':c'=>$cal,':s'=>$sugar,':sa'=>$salt,':f'=>$fat,':p'=>$protein,':fi'=>$fiber,':src'=>$source,':bc'=>$bc,':id'=>$id];
        foreach ($extraValues as $field=>$value) {
          $param = ':x_'.$field;
          $sets[] = qident($field)."=$param";
          $params[$param] = $value;
        }
        $stmt = $db->prepare("UPDATE foods SET ".implode(',',$sets)." WHERE id=:id");
        $stmt->execute($params);
        logAction($db,'edit_food',"Updated: $name (id=$id)");
        echo json_encode(['ok'=>true,'msg'=>'Food updated']);
      } else {
        $cols = ['name','calories','sugar','salt','fat','protein','fiber','source','barcode'];
        $placeholders = [':n',':c',':s',':sa',':f',':p',':fi',':src',':bc'];
        $params = [':n'=>$name,':c'=>$cal,':s'=>$sugar,':sa'=>$salt,':f'=>$fat,':p'=>$protein,':fi'=>$fiber,':src'=>$source,':bc'=>$bc];
        foreach ($extraValues as $field=>$value) {
          $param = ':x_'.$field;
          $cols[] = $field;
          $placeholders[] = $param;
          $params[$param] = $value;
        }
        $stmt = $db->prepare("INSERT INTO foods (".implode(',', array_map('qident', $cols)).") VALUES (".implode(',', $placeholders).")");
        $stmt->execute($params);
        $newId = $db->lastInsertId();
        logAction($db,'add_food',"Added: $name (id=$newId)");
        echo json_encode(['ok'=>true,'msg'=>'Food added','id'=>$newId]);
      }
      break;

    // ── Delete food ──────────────────────────────────────────
    case 'delete_food':
      $b  = json_decode(file_get_contents('php://input'),true) ?? [];
      $id = (int)($b['id'] ?? 0);
      if (!$id) { echo json_encode(['ok'=>false]); break; }
      $row = $db->prepare("SELECT name FROM foods WHERE id=:id"); $row->execute([':id'=>$id]); $row=$row->fetch();
      $db->prepare("DELETE FROM company_products WHERE food_id=:id")->execute([':id'=>$id]);
      $db->prepare("DELETE FROM foods WHERE id=:id")->execute([':id'=>$id]);
      logAction($db,'delete_food',"Deleted: ".($row['name']??'?')." (id=$id)");
      echo json_encode(['ok'=>true]);
      break;

    // ── Export foods as CSV ──────────────────────────────────
    case 'export_foods':
      ensureOptionalFoodColumns($db);
      $columns = array_column(getFoodColumns($db), 'Field');
      $rows = $db->query("SELECT * FROM foods ORDER BY id ASC")->fetchAll();
      $csv = implode(',', $columns)."\n";
      foreach ($rows as $r) {
        $csv .= implode(',', array_map(function($field) use ($r){ return '"'.str_replace('"','""',(string)($r[$field] ?? '')).'"'; }, $columns))."\n";
      }
      header('Content-Type: text/csv');
      header('Content-Disposition: attachment; filename="vitahar_foods_'.date('Ymd').'.csv"');
      echo $csv;
      exit;

    // ── List companies ───────────────────────────────────────
    case 'list_companies':
      $q = trim($_GET['q'] ?? '');
      $where = $q ? "WHERE c.name LIKE :q" : "";
      $params = $q ? [':q'=>"%$q%"] : [];
      $stmt = $db->prepare(
        "SELECT c.id, c.name, COUNT(cp.food_id) AS product_count,
                GROUP_CONCAT(f.name ORDER BY f.name SEPARATOR '||') AS product_names
         FROM companies c
         LEFT JOIN company_products cp ON cp.company_id=c.id
         LEFT JOIN foods f ON f.id=cp.food_id
         $where GROUP BY c.id ORDER BY c.id DESC"
      );
      $stmt->execute($params);
      $rows = $stmt->fetchAll();
      foreach ($rows as &$row) {
        $row['products'] = $row['product_names'] ? explode('||', $row['product_names']) : [];
        unset($row['product_names']);
      }
      echo json_encode(['rows'=>$rows]);
      break;

    // ── Add company ──────────────────────────────────────────
    case 'add_company':
      $b    = json_decode(file_get_contents('php://input'),true) ?? [];
      $name = trim($b['name'] ?? '');
      if (!$name) { echo json_encode(['ok'=>false,'msg'=>'Name required']); break; }
      $stmt = $db->prepare("INSERT IGNORE INTO companies (name) VALUES (:n)");
      $stmt->execute([':n'=>$name]);
      logAction($db,'add_company',"Added company: $name");
      echo json_encode(['ok'=>true,'msg'=>'Company added','id'=>$db->lastInsertId()]);
      break;

    // ── Delete company ───────────────────────────────────────
    case 'delete_company':
      $b  = json_decode(file_get_contents('php://input'),true) ?? [];
      $id = (int)($b['id'] ?? 0);
      if (!$id) { echo json_encode(['ok'=>false]); break; }
      $row = $db->prepare("SELECT name FROM companies WHERE id=:id"); $row->execute([':id'=>$id]); $row=$row->fetch();
      $db->prepare("DELETE FROM company_products WHERE company_id=:id")->execute([':id'=>$id]);
      $db->prepare("DELETE FROM companies WHERE id=:id")->execute([':id'=>$id]);
      logAction($db,'delete_company',"Deleted: ".($row['name']??'?')." (id=$id)");
      echo json_encode(['ok'=>true]);
      break;

    // ── Link food to company ─────────────────────────────────
    case 'link_product':
      $b   = json_decode(file_get_contents('php://input'),true) ?? [];
      $cid = (int)($b['company_id'] ?? 0);
      $fid = (int)($b['food_id']    ?? 0);
      if (!$cid||!$fid) { echo json_encode(['ok'=>false]); break; }
      $db->prepare("INSERT IGNORE INTO company_products (company_id,food_id) VALUES (:c,:f)")->execute([':c'=>$cid,':f'=>$fid]);
      echo json_encode(['ok'=>true]);
      break;

    // ── Unlink food from company ─────────────────────────────
    case 'unlink_product':
      $b   = json_decode(file_get_contents('php://input'),true) ?? [];
      $cid = (int)($b['company_id'] ?? 0);
      $fid = (int)($b['food_id']    ?? 0);
      $db->prepare("DELETE FROM company_products WHERE company_id=:c AND food_id=:f")->execute([':c'=>$cid,':f'=>$fid]);
      echo json_encode(['ok'=>true]);
      break;

    // ── BULK IMPORT: CSV with company name column ─────────────
    // CSV format: name, calories, sugar, salt, fat, protein, fiber, barcode, company_name
    case 'import_csv':
      $b    = json_decode(file_get_contents('php://input'),true) ?? [];
      $rows = $b['rows'] ?? [];
      $ok   = 0; $fail = 0; $skip = 0;
      $companyCache = [];
      // Ensure extra columns
      try { $db->exec("ALTER TABLE foods ADD COLUMN protein FLOAT DEFAULT 0"); } catch(Exception $e){}
      try { $db->exec("ALTER TABLE foods ADD COLUMN fiber FLOAT DEFAULT 0"); } catch(Exception $e){}
      $stmt = $db->prepare("INSERT INTO foods (name,calories,sugar,salt,fat,protein,fiber,source,barcode) VALUES (:n,:c,:s,:sa,:f,:p,:fi,'admin',:bc) ON DUPLICATE KEY UPDATE calories=VALUES(calories),sugar=VALUES(sugar),salt=VALUES(salt),fat=VALUES(fat),protein=VALUES(protein),fiber=VALUES(fiber)");
      foreach ($rows as $r) {
        $name = trim($r[0] ?? '');
        if (!$name) { $skip++; continue; }
        $cal     = (float)($r[1] ?? 0);
        $sugar   = (float)($r[2] ?? 0);
        $salt    = (float)($r[3] ?? 0);
        $fat     = (float)($r[4] ?? 0);
        $protein = (float)($r[5] ?? 0);
        $fiber   = (float)($r[6] ?? 0);
        $barcode = trim($r[7] ?? '');
        $bc      = preg_match('/^\d{6,14}$/',$barcode) ? $barcode : null;
        $company = trim($r[8] ?? '');
        try {
          $stmt->execute([':n'=>$name,':c'=>$cal,':s'=>$sugar,':sa'=>$salt,':f'=>$fat,':p'=>$protein,':fi'=>$fiber,':bc'=>$bc]);
          $foodId = $db->lastInsertId() ?: null;
          // Optionally get existing id if upserted
          if (!$foodId) {
            $fRow = $db->prepare("SELECT id FROM foods WHERE name=:n"); $fRow->execute([':n'=>$name]); $fRow=$fRow->fetch();
            $foodId = $fRow['id'] ?? null;
          }
          // Link company if given
          if ($company && $foodId) {
            if (!isset($companyCache[$company])) {
              $cRow = $db->prepare("SELECT id FROM companies WHERE name=:n"); $cRow->execute([':n'=>$company]); $cRow=$cRow->fetch();
              if ($cRow) { $companyCache[$company]=$cRow['id']; }
              else {
                $ins = $db->prepare("INSERT IGNORE INTO companies (name) VALUES (:n)"); $ins->execute([':n'=>$company]);
                $companyCache[$company] = $db->lastInsertId() ?: null;
                if (!$companyCache[$company]) {
                  $cRow2 = $db->prepare("SELECT id FROM companies WHERE name=:n"); $cRow2->execute([':n'=>$company]); $cRow2=$cRow2->fetch();
                  $companyCache[$company] = $cRow2['id'] ?? null;
                }
              }
            }
            if ($companyCache[$company]) {
              $db->prepare("INSERT IGNORE INTO company_products (company_id,food_id) VALUES (:c,:f)")->execute([':c'=>$companyCache[$company],':f'=>$foodId]);
            }
          }
          $ok++;
        } catch(Exception $e) { $fail++; }
      }
      logAction($db,'import_csv',"Imported $ok rows, $fail failed, $skip skipped");
      echo json_encode(['ok'=>true,'imported'=>$ok,'failed'=>$fail,'skipped'=>$skip]);
      break;

    // ── Activity log ─────────────────────────────────────────
    case 'activity_log':
      ensureLogTable($db);
      $limit = 50;
      $rows = $db->query("SELECT id,action,detail,created_at FROM activity_log ORDER BY id DESC LIMIT $limit")->fetchAll();
      echo json_encode(['rows'=>$rows]);
      break;

    // ── Clear log ────────────────────────────────────────────
    case 'clear_log':
      ensureLogTable($db);
      $db->exec("TRUNCATE TABLE activity_log");
      echo json_encode(['ok'=>true]);
      break;

    // ── Change password ───────────────────────────────────────
    case 'nutrition_columns':
      ensureOptionalFoodColumns($db);
      echo json_encode(['ok'=>true,'columns'=>publicNutritionColumns($db),'hidden'=>hiddenNutritionColumns($db)]);
      break;

    case 'save_nutrition_columns':
      $b = json_decode(file_get_contents('php://input'),true) ?? [];
      $hidden = saveHiddenNutritionColumns($db, $b['hidden'] ?? []);
      logAction($db,'nutrition_columns','Updated public nutrition column visibility');
      echo json_encode(['ok'=>true,'hidden'=>$hidden,'msg'=>'Public nutrition columns updated']);
      break;

    case 'change_password':
      // Determine the effective password (may have been changed via admin_config.php)
      $effectivePwForChange = ADMIN_PASSWORD;
      if (file_exists(__DIR__.'/admin_config.php')) {
        @include_once __DIR__.'/admin_config.php';
        if (defined('ADMIN_PASSWORD_OVERRIDE')) $effectivePwForChange = ADMIN_PASSWORD_OVERRIDE;
      }
      $b    = json_decode(file_get_contents('php://input'),true) ?? [];
      $curr = $b['current'] ?? '';
      $newp = $b['new']     ?? '';
      $conf = $b['confirm'] ?? '';
      if ($curr !== $effectivePwForChange) { echo json_encode(['ok'=>false,'msg'=>'Current password is wrong']); break; }
      if (strlen($newp)<8) { echo json_encode(['ok'=>false,'msg'=>'New password must be at least 8 characters']); break; }
      if ($newp !== $conf) { echo json_encode(['ok'=>false,'msg'=>'Passwords do not match']); break; }
      // Write new password to a config file
      $cfg = "<?php\ndefine('ADMIN_PASSWORD_OVERRIDE', ".json_encode($newp).");\n";
      file_put_contents(__DIR__.'/admin_config.php', $cfg);
      logAction($db,'change_password','Admin password changed');
      echo json_encode(['ok'=>true,'msg'=>'Password updated! It will take effect on next login.']);
      break;

    // ── File manager ─────────────────────────────────────────
    case 'list_media':
      $dir   = __DIR__;
      $files = [];
      $exts  = ['jpg','jpeg','png','gif','webp','svg','mp4','webm','mp3','wav','ogg','pdf','doc','docx','xls','xlsx','txt','csv'];
      foreach (scandir($dir) as $f) {
        if ($f==='.'||$f==='..') continue;
        $ext = strtolower(pathinfo($f,PATHINFO_EXTENSION));
        if (in_array($ext,$exts)) {
          $files[]=['name'=>$f,'ext'=>$ext,'size'=>filesize($dir.'/'.$f),'time'=>filemtime($dir.'/'.$f)];
        }
      }
      usort($files,fn($a,$b)=>$b['time']-$a['time']);
      echo json_encode(['ok'=>true,'files'=>$files]);
      break;

    case 'delete_media':
      $b    = json_decode(file_get_contents('php://input'),true) ?? [];
      $name = basename($b['name'] ?? '');
      $safe = ['php','htaccess','html','css','js','xml'];
      $ext  = strtolower(pathinfo($name,PATHINFO_EXTENSION));
      if (in_array($ext,$safe)||!$name) { echo json_encode(['ok'=>false,'msg'=>'Cannot delete this file type']); break; }
      $path = __DIR__.'/'.$name;
      if (file_exists($path)) { unlink($path); logAction($db,'delete_media',"Deleted file: $name"); echo json_encode(['ok'=>true]); }
      else echo json_encode(['ok'=>false,'msg'=>'File not found']);
      break;

    // ── Duplicate food check ──────────────────────────────────
    case 'check_duplicates':
      $rows = $db->query("SELECT name, COUNT(*) as cnt FROM foods GROUP BY name HAVING cnt > 1 ORDER BY cnt DESC LIMIT 50")->fetchAll();
      echo json_encode(['rows'=>$rows]);
      break;

    // ── Remove duplicate foods (keep lowest id) ───────────────
    case 'remove_duplicates':
      $rows = $db->query("SELECT MIN(id) as keep_id, name FROM foods GROUP BY name HAVING COUNT(*)>1")->fetchAll();
      $deleted = 0;
      foreach ($rows as $r) {
        // Collect IDs that will be deleted (all but the one we're keeping)
        $toDelete = $db->prepare("SELECT id FROM foods WHERE name=:n AND id!=:id");
        $toDelete->execute([':n'=>$r['name'],':id'=>$r['keep_id']]);
        $deleteIds = array_column($toDelete->fetchAll(), 'id');
        if ($deleteIds) {
          $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
          $db->prepare("DELETE FROM company_products WHERE food_id IN ($placeholders)")->execute($deleteIds);
          $db->prepare("DELETE FROM foods WHERE name=:n AND id!=:id")->execute([':n'=>$r['name'],':id'=>$r['keep_id']]);
          $deleted += count($deleteIds);
        }
      }
      logAction($db,'remove_duplicates',"Removed $deleted duplicate food entries");
      echo json_encode(['ok'=>true,'deleted'=>$deleted]);
      break;

    // ── SQL Console (read-only) ──────────────────────────────
    case 'run_sql':
      $b   = json_decode(file_get_contents('php://input'),true) ?? [];
      $sql = trim($b['sql'] ?? '');
      if (!$sql) { echo json_encode(['ok'=>false,'msg'=>'Empty SQL']); break; }
      if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\s/i',$sql)) {
        echo json_encode(['ok'=>false,'msg'=>'Only SELECT/SHOW/DESCRIBE/EXPLAIN allowed here.']); break;
      }
      try {
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll();
        $cols = $rows ? array_keys($rows[0]) : [];
        echo json_encode(['ok'=>true,'cols'=>$cols,'rows'=>$rows,'count'=>count($rows)]);
      } catch(PDOException $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
      break;

    // ── Schema: list columns of a table ──────────────────────
    case 'schema_columns':
      $table = preg_replace('/[^a-zA-Z0-9_]/','',$_GET['table'] ?? '');
      $allowed_tables = ['foods','companies'];
      if (!in_array($table,$allowed_tables)) { echo json_encode(['ok'=>false,'msg'=>'Table not allowed']); break; }
      $cols = $db->query("DESCRIBE `$table`")->fetchAll();
      echo json_encode(['ok'=>true,'cols'=>$cols]);
      break;

    // ── Schema: add column ────────────────────────────────────
    case 'schema_add_column':
      $b     = json_decode(file_get_contents('php://input'),true) ?? [];
      $table = preg_replace('/[^a-zA-Z0-9_]/','',$b['table'] ?? '');
      $col   = preg_replace('/[^a-zA-Z0-9_]/','',$b['column'] ?? '');
      $type  = $b['type'] ?? 'VARCHAR(255)';
      $default_val = $b['default'] ?? '';
      $allowed_tables = ['foods','companies'];
      $allowed_types  = ['VARCHAR(255)'=>'VARCHAR(255)','TEXT'=>'TEXT','INT'=>'INT DEFAULT 0','FLOAT'=>'FLOAT DEFAULT 0','TINYINT(1)'=>'TINYINT(1) DEFAULT 0','DATE'=>'DATE'];
      if (!in_array($table,$allowed_tables)) { echo json_encode(['ok'=>false,'msg'=>'Table not allowed']); break; }
      if (!$col) { echo json_encode(['ok'=>false,'msg'=>'Column name required']); break; }
      if (!array_key_exists($type,$allowed_types)) { echo json_encode(['ok'=>false,'msg'=>'Invalid type']); break; }
      $typeSQL = $allowed_types[$type];
      // Add DEFAULT clause for VARCHAR/TEXT
      if (in_array($type,['VARCHAR(255)','TEXT'])) {
        $safeDefault = addslashes($default_val);
        $typeSQL .= " DEFAULT '$safeDefault'";
      }
      try {
        $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $typeSQL");
        logAction($db,'schema_add_column',"Added column `$col` ($typeSQL) to `$table`");
        echo json_encode(['ok'=>true,'msg'=>"Column '$col' added to '$table' successfully"]);
      } catch(PDOException $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
      }
      break;

    // ── Schema: delete column ─────────────────────────────────
    case 'schema_drop_column':
      $b     = json_decode(file_get_contents('php://input'),true) ?? [];
      $table = preg_replace('/[^a-zA-Z0-9_]/','',$b['table'] ?? '');
      $col   = preg_replace('/[^a-zA-Z0-9_]/','',$b['column'] ?? '');
      $allowed_tables = ['foods','companies'];
      // Protected columns that must never be deleted
      $protected = ['id','name','calories','sugar','salt','fat','source','barcode','protein','fiber','created_at','updated_at'];
      if (!in_array($table,$allowed_tables)) { echo json_encode(['ok'=>false,'msg'=>'Table not allowed']); break; }
      if (!$col) { echo json_encode(['ok'=>false,'msg'=>'Column name required']); break; }
      if (in_array($col,$protected)) { echo json_encode(['ok'=>false,'msg'=>"Column '$col' is protected and cannot be deleted"]); break; }
      try {
        $db->exec("ALTER TABLE `$table` DROP COLUMN `$col`");
        if ($table === 'foods') removeHiddenNutritionColumn($db, $col);
        logAction($db,'schema_drop_column',"Dropped column `$col` from `$table`");
        echo json_encode(['ok'=>true,'msg'=>"Column '$col' deleted from '$table'"]);
      } catch(PDOException $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
      }
      break;

    // ── Schema: rename column ─────────────────────────────────
    case 'schema_rename_column':
      $b      = json_decode(file_get_contents('php://input'),true) ?? [];
      $table  = preg_replace('/[^a-zA-Z0-9_]/','',$b['table']  ?? '');
      $oldcol = preg_replace('/[^a-zA-Z0-9_]/','',$b['old']    ?? '');
      $newcol = preg_replace('/[^a-zA-Z0-9_]/','',$b['new']    ?? '');
      $allowed_tables = ['foods','companies'];
      $protected = ['id','name','calories','sugar','salt','fat','source','barcode','protein','fiber'];
      if (!in_array($table,$allowed_tables)) { echo json_encode(['ok'=>false,'msg'=>'Table not allowed']); break; }
      if (!$oldcol||!$newcol) { echo json_encode(['ok'=>false,'msg'=>'Column names required']); break; }
      if (in_array($oldcol,$protected)) { echo json_encode(['ok'=>false,'msg'=>"Column '$oldcol' is protected and cannot be renamed"]); break; }
      try {
        // Get column definition first
        $def = $db->query("SHOW COLUMNS FROM `$table` LIKE '$oldcol'")->fetch();
        if (!$def) { echo json_encode(['ok'=>false,'msg'=>'Column not found']); break; }
        $colType = $def['Type'];
        $nullable = $def['Null']==='YES' ? 'NULL' : 'NOT NULL';
        $colDefault = $def['Default'] !== null ? "DEFAULT '".addslashes($def['Default'])."'" : '';
        $db->exec("ALTER TABLE `$table` CHANGE `$oldcol` `$newcol` $colType $nullable $colDefault");
        if ($table === 'foods') renameHiddenNutritionColumn($db, $oldcol, $newcol);
        logAction($db,'schema_rename_column',"Renamed column `$oldcol` → `$newcol` in `$table`");
        echo json_encode(['ok'=>true,'msg'=>"Column renamed from '$oldcol' to '$newcol'"]);
      } catch(PDOException $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
      }
      break;

    // ── Reports ───────────────────────────────────────────────
    case 'list_reports':
      $stmt = $db->query("SELECT id, name, anomaly FROM foods WHERE anomaly > 0 ORDER BY anomaly DESC, id DESC LIMIT 50");
      echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll()]);
      break;
    case 'clear_anomaly':
      $b  = json_decode(file_get_contents('php://input'),true) ?? [];
      $id = (int)($b['id'] ?? 0);
      $stmt = $db->prepare("UPDATE foods SET anomaly=0 WHERE id=:id");
      $stmt->execute([':id'=>$id]);
      logAction($db,'clear_anomaly',"Cleared anomaly flag for food #$id");
      echo json_encode(['ok'=>true]);
      break;

    default:
      echo json_encode(['error'=>'Unknown action']);
  }
  } catch (Throwable $e) {
    // Catch-all so any unexpected PHP error/exception in an AJAX
    // action still returns valid JSON instead of an HTML error page
    // (which would otherwise break r.json() on the frontend and show
    // as "Network error" again).
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'msg' => $e->getMessage()]);
  }
  exit;
}

// ── FILE UPLOAD ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['media_file']) && isLoggedIn()) {
  $file    = $_FILES['media_file'];
  $allowed = ['jpg','jpeg','png','gif','webp','svg','mp4','webm','mp3','wav','ogg','pdf','doc','docx','xls','xlsx','txt','csv'];
  $ext     = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
  if (in_array($ext,$allowed) && $file['error']===0) {
    $dest = __DIR__.'/'.basename($file['name']);
    move_uploaded_file($file['tmp_name'],$dest);
    $_SESSION['upload_msg']='✅ File uploaded: '.htmlspecialchars(basename($file['name']));
  } else {
    $_SESSION['upload_msg']='❌ Upload failed or file type not allowed.';
  }
  header('Location: admin.php?tab=media'); exit;
}

// ── LOGIN / LOGOUT ────────────────────────────────────────────
// Support password override file
if (file_exists(__DIR__.'/admin_config.php')) @include __DIR__.'/admin_config.php';
$effectivePassword = defined('ADMIN_PASSWORD_OVERRIDE') ? ADMIN_PASSWORD_OVERRIDE : ADMIN_PASSWORD;

$loginError='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['admin_login'])) {
  if (($_POST['username']??'')=== ADMIN_USERNAME && ($_POST['password']??'')===$effectivePassword) {
    session_regenerate_id(true);
    $_SESSION[ADMIN_SESSION_KEY]=true;
    $_SESSION['login_time']=time();
    header('Location: admin.php'); exit;
  } else { $loginError='Invalid username or password.'; }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

// Direct CSV export (non-AJAX)
if (isset($_GET['export']) && $_GET['export']==='foods' && isLoggedIn()) {
  $db = getDB();
  ensureOptionalFoodColumns($db);
  $columns = array_column(getFoodColumns($db), 'Field');
  $rows = $db->query("SELECT * FROM foods ORDER BY id ASC")->fetchAll();
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="vitahar_foods_'.date('Ymd_His').'.csv"');
  echo implode(',', $columns)."\n";
  foreach ($rows as $r) {
    echo implode(',',array_map(function($field) use ($r){ return '"'.str_replace('"','""',(string)($r[$field] ?? '')).'"'; },$columns))."\n";
  }
  exit;
}

// Delete feedbacks
if (isset($_POST['delete_feedbacks']) && isLoggedIn()) {
  $ids = $_POST['feedback_ids'] ?? [];
  if (is_array($ids) && count($ids) > 0) {
    try {
      $db = getDB();
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $stmt = $db->prepare("DELETE FROM feedbacks WHERE id IN ($placeholders)");
      $stmt->execute($ids);
    } catch (Exception $e) {}
  }
  header('Location: admin.php?tab=feedbacks'); exit;
}

$activeTab = $_GET['tab'] ?? 'dashboard';
$uploadMsg = $_SESSION['upload_msg'] ?? '';
unset($_SESSION['upload_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vitahar Admin Panel v2</title>
<meta name="robots" content="noindex,nofollow">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Arial,sans-serif}
body{background:#f0f4f0;color:#222;min-height:100vh}

/* LOGIN */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#1b5e20,#2e7d32,#ff9800)}
.login-card{background:#fff;border-radius:16px;padding:40px 36px;width:360px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center}
.login-card h1{color:#2e7d32;font-size:28px;margin-bottom:4px}
.login-card p{color:#777;font-size:13px;margin-bottom:28px}
.login-card input{width:100%;padding:12px 16px;border:2px solid #ddd;border-radius:8px;font-size:15px;margin-bottom:14px;outline:none;transition:border-color .2s}
.login-card input:focus{border-color:#2e7d32}
.login-card button{width:100%;padding:13px;background:#2e7d32;color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer;transition:background .2s}
.login-card button:hover{background:#1b5e20}
.login-error{background:#ffebee;color:#c62828;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px}
.lock-icon{font-size:48px;margin-bottom:16px}

/* LAYOUT */
.admin-layout{display:flex;min-height:100vh}

/* SIDEBAR */
.sidebar{width:230px;background:linear-gradient(180deg,#1b5e20 0%,#2e7d32 100%);color:#fff;flex-shrink:0;display:flex;flex-direction:column}
.sidebar-brand{padding:24px 20px 20px;border-bottom:1px solid rgba(255,255,255,.15)}
.sidebar-brand h2{font-size:20px;font-weight:bold}
.sidebar-brand span{font-size:11px;opacity:.7;display:block;margin-top:2px}
.sidebar nav{flex:1;padding:12px 0}
.sidebar nav a{display:flex;align-items:center;gap:10px;padding:12px 20px;color:rgba(255,255,255,.85);text-decoration:none;font-size:14px;transition:background .15s}
.sidebar nav a:hover,.sidebar nav a.active{background:rgba(255,255,255,.15);color:#fff}
.sidebar nav a .icon{font-size:18px;width:22px;text-align:center}
.sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.15);font-size:12px;opacity:.6}

/* MAIN */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.topbar{background:#fff;padding:14px 28px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,.08);border-bottom:3px solid #2e7d32}
.topbar h3{font-size:18px;color:#2e7d32}
.topbar-right{display:flex;align-items:center;gap:16px}
.topbar-right a{text-decoration:none;font-size:13px;color:#555;padding:8px 14px;border-radius:20px;border:1px solid #ddd;transition:all .2s}
.topbar-right a:hover{background:#2e7d32;color:#fff;border-color:#2e7d32}
.content{flex:1;padding:28px;overflow-y:auto}

/* CARDS */
.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.07);margin-bottom:24px}
.card h3{font-size:16px;color:#2e7d32;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #e8f5e9}

/* STATS */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px}
.stat-box{background:#fff;border-radius:12px;padding:20px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.07);border-top:4px solid #2e7d32}
.stat-box .num{font-size:36px;font-weight:bold;color:#2e7d32}
.stat-box .lbl{font-size:13px;color:#777;margin-top:4px}
.stat-box.orange{border-top-color:#ff9800}.stat-box.orange .num{color:#ff9800}
.stat-box.blue{border-top-color:#1976d2}.stat-box.blue .num{color:#1976d2}
.stat-box.red{border-top-color:#c62828}.stat-box.red .num{color:#c62828}
.stat-box.purple{border-top-color:#7b1fa2}.stat-box.purple .num{color:#7b1fa2}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:bold;transition:all .2s;text-decoration:none}
.btn-green{background:#2e7d32;color:#fff}.btn-green:hover{background:#1b5e20}
.btn-orange{background:#ff9800;color:#fff}.btn-orange:hover{background:#e65100}
.btn-red{background:#c62828;color:#fff}.btn-red:hover{background:#7f0000}
.btn-blue{background:#1976d2;color:#fff}.btn-blue:hover{background:#0d47a1}
.btn-gray{background:#e0e0e0;color:#333}.btn-gray:hover{background:#bdbdbd}
.btn-purple{background:#7b1fa2;color:#fff}.btn-purple:hover{background:#4a0072}
.btn-sm{padding:5px 12px;font-size:12px}

/* FORMS */
.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:12px;color:#555;font-weight:bold;text-transform:uppercase}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 12px;border:2px solid #ddd;border-radius:8px;font-size:14px;outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#2e7d32}
.check-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}
.check-item{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:1px solid #e0e0e0;border-radius:8px;background:#fafafa;font-size:13px}
.check-item input{margin-top:2px}
.check-item small{display:block;color:#777;margin-top:2px}

/* TABLE */
.tbl-wrap{overflow-x:auto;border-radius:8px;border:1px solid #e0e0e0}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#e8f5e9;color:#2e7d32;padding:11px 14px;text-align:left;font-size:12px;text-transform:uppercase;position:sticky;top:0}
td{padding:10px 14px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafafa}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:bold}
.badge-green{background:#e8f5e9;color:#2e7d32}
.badge-orange{background:#fff3e0;color:#e65100}
.badge-blue{background:#e3f2fd;color:#1565c0}
.badge-gray{background:#f5f5f5;color:#666}
.badge-red{background:#ffebee;color:#c62828}

/* ALERTS */
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#e8f5e9;color:#1b5e20;border:1px solid #c8e6c9}
.alert-danger{background:#ffebee;color:#c62828;border:1px solid #ffcdd2}
.alert-info{background:#e3f2fd;color:#0d47a1;border:1px solid #bbdefb}
.alert-warn{background:#fff3e0;color:#e65100;border:1px solid #ffe0b2}

/* SEARCH BAR */
.search-bar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.search-bar input,.search-bar select{padding:10px 14px;border:2px solid #ddd;border-radius:8px;font-size:14px;outline:none}
.search-bar input{flex:1;min-width:180px}
.search-bar select{flex:1;min-width:140px}
.search-bar input:focus,.search-bar select:focus{border-color:#2e7d32}

/* PAGINATION */
.pagination{display:flex;gap:6px;margin-top:16px;align-items:center;flex-wrap:wrap}
.pagination button{padding:6px 14px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer;font-size:13px;transition:all .2s}
.pagination button:hover,.pagination button.active{background:#2e7d32;color:#fff;border-color:#2e7d32}
.pagination span{font-size:13px;color:#777}

/* MEDIA */
.media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}
.media-item{background:#fafafa;border:1px solid #e0e0e0;border-radius:10px;overflow:hidden;text-align:center;transition:box-shadow .2s}
.media-item:hover{box-shadow:0 4px 16px rgba(0,0,0,.12)}
.media-thumb{width:100%;height:100px;object-fit:cover}
.media-icon{font-size:48px;line-height:100px;height:100px;display:block;background:#f5f5f5}
.media-name{padding:8px 10px;font-size:11px;word-break:break-all;color:#444}
.media-size{font-size:10px;color:#999;padding-bottom:6px}
.media-actions{padding:8px;display:flex;gap:6px;justify-content:center;border-top:1px solid #eee}

/* SQL CONSOLE */
.sql-console textarea{width:100%;font-family:monospace;font-size:13px;padding:14px;border:2px solid #ddd;border-radius:8px;resize:vertical;background:#1e1e1e;color:#d4d4d4;outline:none}
.sql-console textarea:focus{border-color:#2e7d32}
.sql-result{margin-top:16px;overflow-x:auto;max-height:400px;overflow-y:auto}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:14px;padding:28px;width:92%;max-width:560px;max-height:90vh;overflow-y:auto;position:relative}
.modal h3{color:#2e7d32;margin-bottom:20px;font-size:18px}
.modal-close{position:absolute;top:14px;right:18px;background:none;border:none;font-size:22px;cursor:pointer;color:#777}

/* UPLOAD BOX */
.upload-box{border:3px dashed #a5d6a7;border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:border-color .2s;background:#f9fdf9}
.upload-box:hover{border-color:#2e7d32}
.upload-box .up-icon{font-size:48px;margin-bottom:12px;display:block}
.upload-box p{color:#777;font-size:14px}

/* PROGRESS */
.progress-bar-wrap{background:#e0e0e0;border-radius:8px;height:12px;overflow:hidden;margin:10px 0}
.progress-bar{background:#2e7d32;height:100%;border-radius:8px;transition:width .3s}

/* LOG TABLE */
.log-action{font-weight:bold;text-transform:uppercase;font-size:11px;letter-spacing:.5px}

/* MENU TOGGLE (hamburger) — hidden unless a phone-width drawer is active */
.menu-toggle{display:none;background:none;border:none;font-size:22px;line-height:1;cursor:pointer;color:#2e7d32;padding:4px 6px}
.sidebar-backdrop{display:none}

/* RESPONSIVE */
/* Tablets: keep the sidebar, but as an icon-only rail so content has room */
@media(max-width:900px){
  .sidebar{width:64px}
  .sidebar-brand h2,.sidebar-brand span,.sidebar nav a span:last-child,.sidebar-footer{display:none}
  .sidebar nav a{justify-content:center;padding:14px 6px}
  .content{padding:18px}
  .stat-grid{grid-template-columns:repeat(auto-fit,minmax(130px,1fr))}
}

/* Phones (and narrow tablets in portrait): sidebar becomes an off-canvas
   drawer opened with the hamburger button, so the full width is free
   for tables/forms instead of a permanent icon rail. */
@media(max-width:700px){
  .menu-toggle{display:inline-block}
  .admin-layout{position:relative;overflow-x:hidden}
  .sidebar{
    position:fixed;top:0;left:0;bottom:0;width:240px;z-index:1001;
    transform:translateX(-100%);transition:transform .25s ease;
    box-shadow:4px 0 24px rgba(0,0,0,.25);
  }
  .sidebar-brand h2,.sidebar-brand span,.sidebar nav a span:last-child,.sidebar-footer{display:block}
  .sidebar nav a{justify-content:flex-start;padding:12px 20px}
  body.sidebar-open .sidebar{transform:translateX(0)}
  body.sidebar-open .sidebar-backdrop{display:block;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000}
  body.sidebar-open{overflow:hidden}

  .content{padding:14px}
  .topbar{padding:12px 14px;flex-wrap:wrap;gap:10px}
  .topbar h3{font-size:15px}
  .topbar-right{gap:8px;width:100%;justify-content:flex-end}
  .logged-in-as{display:none}
  .topbar-right a{padding:7px 12px;font-size:12px}

  .card{padding:16px;border-radius:10px}
  .stat-grid{grid-template-columns:1fr 1fr;gap:10px}
  .stat-box{padding:14px}
  .stat-box .num{font-size:28px}

  .form-row{grid-template-columns:1fr}
  .check-grid{grid-template-columns:1fr}

  /* Quick Actions: full-width stacked buttons are far easier to tap
     accurately on a phone than a wrapped row of small buttons. Scoped
     to this row only — table/pagination/sql-shortcut buttons stay put. */
  .quick-actions{flex-direction:column;align-items:stretch}
  .quick-actions .btn{width:100%;justify-content:center}

  .media-grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr))}
  .modal{padding:20px;width:94%}

  table{font-size:12px}
  th,td{padding:8px 10px}
}

@media(max-width:420px){
  .stat-grid{grid-template-columns:1fr 1fr}
  .login-card{padding:32px 22px}
}

/* MISC */
.text-muted{color:#888;font-size:12px}
.flex{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.mt-12{margin-top:12px}
.loading{text-align:center;padding:40px;color:#888;font-size:15px}
#toast{position:fixed;bottom:28px;right:28px;background:#323232;color:#fff;padding:12px 20px;border-radius:10px;font-size:14px;z-index:9999;opacity:0;transition:opacity .3s;pointer-events:none;max-width:320px}
#toast.show{opacity:1}
code{background:#f5f5f5;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:12px}
</style>
</head>
<body>

<?php if (!isLoggedIn()): ?>
<!-- ══════════════════ LOGIN PAGE ══════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="lock-icon">🔐</div>
    <h1>Vitahar Admin</h1>
    <p>Sign in to manage your food database, media &amp; settings</p>
    <?php if ($loginError): ?>
      <div class="login-error">⚠️ <?= htmlspecialchars($loginError) ?></div>
    <?php endif ?>
    <form method="POST">
      <input type="text"     name="username" placeholder="Username" required autocomplete="username">
      <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
      <button type="submit" name="admin_login">Sign In →</button>
    </form>
    <p class="text-muted" style="margin-top:16px">Protected area · Vitahar © 2026</p>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════ ADMIN PANEL ══════════════════ -->
<div class="admin-layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <h2>🌿 Vitahar</h2>
      <span>Admin Panel v2</span>
    </div>
    <nav>
      <a href="?tab=dashboard"  class="<?= $activeTab==='dashboard'?'active':'' ?>"><span class="icon">📊</span><span>Dashboard</span></a>
      <a href="?tab=foods"      class="<?= $activeTab==='foods'?'active':'' ?>"><span class="icon">🥗</span><span>Foods DB</span></a>
      <a href="?tab=companies"  class="<?= $activeTab==='companies'?'active':'' ?>"><span class="icon">🏢</span><span>Companies</span></a>
      <a href="?tab=import"     class="<?= $activeTab==='import'?'active':'' ?>"><span class="icon">📥</span><span>Bulk Import</span></a>
      <a href="?tab=media"      class="<?= $activeTab==='media'?'active':'' ?>"><span class="icon">🗂️</span><span>Media Files</span></a>
      <a href="?tab=agelimits"  class="<?= $activeTab==='agelimits'?'active':'' ?>"><span class="icon">👶</span><span>Age Limits</span></a>
      <a href="?tab=content"    class="<?= $activeTab==='content'?'active':'' ?>"><span class="icon">📝</span><span>Site Content</span></a>
      <a href="?tab=schema"     class="<?= $activeTab==='schema'?'active':'' ?>"><span class="icon">🏗️</span><span>Schema Manager</span></a>
      <a href="?tab=tools"      class="<?= $activeTab==='tools'?'active':'' ?>"><span class="icon">🔧</span><span>DB Tools</span></a>
      <a href="?tab=log"        class="<?= $activeTab==='log'?'active':'' ?>"><span class="icon">📋</span><span>Activity Log</span></a>
      <a href="?tab=reports"    class="<?= $activeTab==='reports'?'active':'' ?>"><span class="icon">🚩</span><span>Flagged Foods</span></a>
      <a href="?tab=feedbacks"  class="<?= $activeTab==='feedbacks'?'active':'' ?>"><span class="icon">💬</span><span>User Feedbacks</span></a>
      <a href="?tab=settings"   class="<?= $activeTab==='settings'?'active':'' ?>"><span class="icon">⚙️</span><span>Settings</span></a>
      <a href="?tab=sql"        class="<?= $activeTab==='sql'?'active':'' ?>"><span class="icon">💻</span><span>SQL Console</span></a>
      <a href="https://vitahar.kesug.com" target="_blank"><span class="icon">🌐</span><span>View Site</span></a>
    </nav>
    <div class="sidebar-footer">Session: 1h · v2.0</div>
  </aside>

  <!-- MAIN -->
  <div class="main">
    <div class="topbar">
      <div class="flex" style="gap:14px">
        <button type="button" class="menu-toggle" id="menuToggle" aria-label="Toggle menu" aria-expanded="false">☰</button>
        <h3>
        <?php $titles=['dashboard'=>'📊 Dashboard','foods'=>'🥗 Foods Database','companies'=>'🏢 Companies','import'=>'📥 Bulk Import','media'=>'🗂️ Media Files','agelimits'=>'👶 Age Limits','content'=>'📝 Site Content','schema'=>'🏗️ Schema Manager','tools'=>'🔧 DB Tools','log'=>'📋 Activity Log','settings'=>'⚙️ Settings','sql'=>'💻 SQL Console','feedbacks'=>'💬 User Feedbacks','reports'=>'🚩 Flagged Foods'];
        echo $titles[$activeTab] ?? 'Admin Panel'; ?>
        </h3>
      </div>
      <div class="topbar-right">
        <span id="sessionTimer" style="font-size:13px;color:#d32f2f;font-weight:bold;margin-right:15px;background:#ffebee;padding:4px 8px;border-radius:6px;" title="Session Time Remaining"></span>
        <span class="logged-in-as" style="font-size:13px;color:#888">Logged in as <strong><?= htmlspecialchars(ADMIN_USERNAME) ?></strong></span>
        <a href="?export=foods">⬇ Export CSV</a>
        <a href="?logout=1">🔓 Logout</a>
      </div>
    </div>
    <script>
      (function(){
        const expiry = <?= isset($_SESSION['login_time']) ? (int)($_SESSION['login_time'] + SESSION_LIFETIME) : 0 ?>;
        const el = document.getElementById('sessionTimer');
        function updateTimer() {
          const remain = expiry - Math.floor(Date.now()/1000);
          if (remain <= 0) {
            el.textContent = 'Session Expired';
            el.style.color = '#fff';
            el.style.background = '#b71c1c';
          } else {
            const m = Math.floor(remain / 60);
            const s = remain % 60;
            el.textContent = '⏱️ ' + m + ':' + (s<10?'0':'')+s;
          }
        }
        if(expiry > 0){ updateTimer(); setInterval(updateTimer, 1000); }
      })();
    </script>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="content">

      <?php if ($uploadMsg): ?>
        <div class="alert <?= str_starts_with($uploadMsg,'✅')?'alert-success':'alert-danger' ?>"><?= $uploadMsg ?></div>
      <?php endif ?>

      <!-- ══════════ DASHBOARD ══════════ -->
      <?php if ($activeTab==='dashboard'): ?>
      <div class="stat-grid">
        <div class="stat-box"><div class="num" id="statFoods">…</div><div class="lbl">Foods in DB</div></div>
        <div class="stat-box orange"><div class="num" id="statCompanies">…</div><div class="lbl">Companies</div></div>
        <div class="stat-box blue"><div class="num" id="statTables">…</div><div class="lbl">DB Tables</div></div>
        <div class="stat-box red"><div class="num" id="statFiles">…</div><div class="lbl">Media Files</div></div>
        <div class="stat-box purple"><div class="num" id="statLogs">…</div><div class="lbl">Actions Logged</div></div>
        <div class="stat-box" style="border-top-color:#00838f"><div class="num" id="statApiNew" style="color:#00838f">…</div><div class="lbl">API-sourced Foods</div></div>
      </div>

      <div class="card">
        <h3>📋 Database Tables</h3>
        <div id="tableList" class="text-muted">Loading…</div>
      </div>

      <div class="card" id="apiHealthCard">
        <h3>🌐 External APIs Health</h3>
        <div class="table-responsive">
          <table>
            <thead>
              <tr><th>API Service</th><th>Status</th><th>Latency</th><th>Action</th></tr>
            </thead>
            <tbody id="apiHealthTableBody">
              <tr>
                <td><strong>OpenFoodFacts</strong><br><small class="text-muted">Primary Data Source</small></td>
                <td id="healthOFF_status">Checking...</td>
                <td id="healthOFF_latency">...</td>
                <td><button type="button" class="btn btn-sm btn-blue" onclick="checkAPIHealth()">Ping</button></td>
              </tr>
              <tr>
                <td><strong>USDA FoodData</strong><br><small class="text-muted">Fallback Source</small></td>
                <td id="healthUSDA_status">Checking...</td>
                <td id="healthUSDA_latency">...</td>
                <td><button type="button" class="btn btn-sm btn-blue" onclick="checkAPIHealth()">Ping</button></td>
              </tr>
              <tr>
                <td><strong>Datakick</strong><br><small class="text-muted">Deprecated/Offline</small></td>
                <td id="healthDatakick_status">Checking...</td>
                <td id="healthDatakick_latency">...</td>
                <td><button type="button" class="btn btn-sm btn-blue" onclick="checkAPIHealth()">Ping</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h3>⚡ Quick Actions</h3>
        <div class="flex quick-actions">
          <a href="?tab=foods"    class="btn btn-green">➕ Add Food</a>
          <a href="?tab=companies" class="btn btn-orange">➕ Add Company</a>
          <a href="?tab=import"   class="btn btn-blue">📥 Bulk Import CSV</a>
          <a href="?export=foods" class="btn btn-gray">⬇ Export Foods CSV</a>
          <a href="?tab=tools"    class="btn btn-gray">🔧 DB Tools</a>
          <a href="?tab=sql"      class="btn btn-gray">💻 SQL Console</a>
        </div>
      </div>

      <div class="card">
        <h3>ℹ️ What's New in v2</h3>
        <div class="alert alert-info">
          <strong>📥 Bulk Import</strong> — Upload CSV with 1000+ products + company names. Automatically creates companies and links products.<br><br>
          <strong>⬇ Export</strong> — Download all foods as CSV anytime from the top-right button.<br><br>
          <strong>🔧 DB Tools</strong> — Find &amp; remove duplicate entries, view stats by source.<br><br>
          <strong>📋 Activity Log</strong> — Every add/edit/delete is recorded automatically.<br><br>
          <strong>⚙️ Settings</strong> — Change your admin password without editing any code file.
        </div>
      </div>

      <!-- ══════════ FEEDBACKS ══════════ -->
      <?php elseif ($activeTab==='feedbacks'): ?>
      <div class="card">
        <h3>💬 User Feedbacks</h3>
        <form method="POST" action="admin.php?tab=feedbacks">
          <div style="margin-bottom: 15px;">
            <button type="submit" name="delete_feedbacks" class="btn btn-red" onclick="return confirm('Delete selected feedbacks?');">🗑️ Delete Selected</button>
          </div>
          <div style="overflow-x:auto;">
            <table class="table">
              <thead>
                <tr>
                  <th width="30"><input type="checkbox" onclick="document.querySelectorAll('.fb-check').forEach(cb => cb.checked = this.checked)"></th>
                  <th width="50">ID</th>
                  <th width="150">Name</th>
                  <th width="200">Email</th>
                  <th>Message</th>
                  <th width="150">Date</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  try {
                    $db = getDB();
                    // Ensure table exists just in case
                    $db->exec("CREATE TABLE IF NOT EXISTS feedbacks (
                      id INT AUTO_INCREMENT PRIMARY KEY,
                      name VARCHAR(100),
                      email VARCHAR(100),
                      message TEXT,
                      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $stmt = $db->query("SELECT * FROM feedbacks ORDER BY created_at DESC");
                    $feedbacks = $stmt->fetchAll();
                    if (count($feedbacks) === 0) {
                      echo "<tr><td colspan='6' class='text-center text-muted'>No feedbacks found.</td></tr>";
                    } else {
                      foreach ($feedbacks as $f) {
                        echo "<tr>
                          <td><input type='checkbox' class='fb-check' name='feedback_ids[]' value='" . (int)$f['id'] . "'></td>
                          <td>" . (int)$f['id'] . "</td>
                          <td>" . htmlspecialchars($f['name']) . "</td>
                          <td><a href='mailto:" . htmlspecialchars($f['email']) . "'>" . htmlspecialchars($f['email']) . "</a></td>
                          <td>" . nl2br(htmlspecialchars($f['message'])) . "</td>
                          <td class='text-muted' style='font-size:12px'>" . htmlspecialchars($f['created_at']) . "</td>
                        </tr>";
                      }
                    }
                  } catch (Exception $e) {
                    echo "<tr><td colspan='6' class='text-center text-danger'>Error loading feedbacks: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
                  }
                ?>
              </tbody>
            </table>
          </div>
        </form>
      </div>

      <!-- ══════════ FOODS DB ══════════ -->
      <?php elseif ($activeTab==='foods'): ?>
      <div class="card">
        <h3>➕ Add / Edit Food</h3>
        <div class="form-row">
          <div class="form-group" style="grid-column:span 2">
            <label>Product Name *</label>
            <input type="text" id="foodName" placeholder="e.g. Maggi 2-Minute Noodles">
          </div>
          <div class="form-group"><label>Calories (kcal/100g)</label><input type="number" id="foodCal" min="0" step="0.1" placeholder="0"></div>
          <div class="form-group"><label>Sugar (g/100g)</label><input type="number" id="foodSugar" min="0" step="0.1" placeholder="0"></div>
          <div class="form-group"><label>Salt/Sodium (mg/100g)</label><input type="number" id="foodSalt" min="0" step="1" placeholder="0"></div>
          <div class="form-group"><label>Fat (g/100g)</label><input type="number" id="foodFat" min="0" step="0.1" placeholder="0"></div>
          <div class="form-group"><label>Protein (g/100g)</label><input type="number" id="foodProtein" min="0" step="0.1" placeholder="0"></div>
          <div class="form-group"><label>Fiber (g/100g)</label><input type="number" id="foodFiber" min="0" step="0.1" placeholder="0"></div>
          <div class="form-group"><label>Barcode (optional)</label><input type="text" id="foodBarcode" placeholder="EAN-8 to EAN-14"></div>
          <div class="form-group"><label>Source</label>
            <select id="foodSource">
              <option value="admin">admin</option>
              <option value="OFF">OFF (Open Food Facts)</option>
              <option value="USDA">USDA</option>
              <option value="local">local</option>
              <option value="api">api</option>
            </select>
          </div>
        </div>
        <div id="customFoodFields" class="form-row"></div>
        <input type="hidden" id="foodId" value="0">
        <div class="flex">
          <button class="btn btn-green" onclick="saveFood()">💾 Save Food</button>
          <button class="btn btn-gray"  onclick="clearFoodForm()">✖ Clear</button>
        </div>
        <div id="foodFormMsg" style="margin-top:12px"></div>
      </div>

      <div class="card">
        <h3>🥗 All Foods</h3>
        <div class="search-bar">
          <input type="text" id="foodSearch" placeholder="Search food name…" oninput="debounceSearch()">
          <select id="foodSourceFilter" onchange="loadFoods(1)">
            <option value="">All Sources</option>
            <option value="admin">admin</option>
            <option value="OFF">OFF</option>
            <option value="USDA">USDA</option>
            <option value="local">local</option>
            <option value="api">api</option>
          </select>
          <select id="foodLimitSel" onchange="loadFoods(1)">
            <option value="20">20/page</option>
            <option value="50">50/page</option>
            <option value="100">100/page</option>
          </select>
          <button class="btn btn-gray btn-sm" onclick="document.getElementById('foodSearch').value='';loadFoods(1)">✖ Clear</button>
          <span id="foodCount" class="text-muted"></span>
        </div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>ID</th><th>Name</th><th>Cal</th><th>Sugar</th><th>Salt</th><th>Fat</th><th>Protein</th><th>Source</th><th>Barcode</th><th>Actions</th></tr></thead>
            <tbody id="foodsTbody"><tr><td colspan="10" class="loading">Loading…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination" id="foodPagination"></div>
      </div>

      <!-- ══════════ COMPANIES ══════════ -->
      <?php elseif ($activeTab==='companies'): ?>
      <div class="card">
        <h3>➕ Add Company</h3>
        <div class="flex">
          <div class="form-group" style="flex:1"><label>Company Name *</label><input type="text" id="coName" placeholder="e.g. Nestlé India"></div>
          <button class="btn btn-green" style="margin-top:22px" onclick="addCompany()">➕ Add</button>
        </div>
        <div id="coMsg" style="margin-top:12px"></div>
      </div>

      <div class="card">
        <h3>🏢 Companies &amp; Products</h3>
        <div class="search-bar">
          <input type="text" id="coSearch" placeholder="Search company name…" oninput="debounceCoSearch()">
        </div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>ID</th><th>Company</th><th>Count</th><th>Linked Products</th><th>Actions</th></tr></thead>
            <tbody id="coTbody"><tr><td colspan="4" class="loading">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- Link modal -->
      <div class="modal-overlay" id="linkModal">
        <div class="modal">
          <button class="modal-close" onclick="closeLinkModal()">✕</button>
          <h3>🔗 Link Product to Company</h3>
          <input type="hidden" id="linkCompanyId">
          <div class="form-group" style="margin-bottom:14px">
            <label>Search Food</label>
            <input type="text" id="linkFoodSearch" placeholder="Type food name…" oninput="searchFoodForLink()">
          </div>
          <div id="linkFoodResults"></div>
        </div>
      </div>

      <!-- ══════════ BULK IMPORT ══════════ -->
      <?php elseif ($activeTab==='import'): ?>
      <div class="card">
        <h3>📥 Bulk Import — CSV Format</h3>
        <div class="alert alert-info">
          <strong>Supported columns (in order):</strong><br>
          <code>name, calories, sugar, salt_mg, fat, protein, fiber, barcode, company_name</code><br><br>
          ✅ First row header is <strong>skipped automatically</strong>.<br>
          ✅ <strong>company_name column is optional</strong> — if provided, the company is created automatically and the product linked to it.<br>
          ✅ If a food with the same name exists, it is <strong>updated</strong> (not duplicated).<br>
          ✅ Upload <strong>1000+ rows</strong> at once — no limit.
        </div>
        <div class="form-group" style="margin:16px 0">
          <label>Select CSV File</label>
          <input type="file" id="csvFile" accept=".csv,.txt">
        </div>
        <div id="csvPreview" style="display:none">
          <div class="tbl-wrap" style="max-height:300px;overflow-y:auto;margin-bottom:14px">
            <table><thead><tr><th>Name</th><th>Cal</th><th>Sugar</th><th>Salt</th><th>Fat</th><th>Protein</th><th>Fiber</th><th>Barcode</th><th>Company</th></tr></thead>
            <tbody id="csvTbody"></tbody></table>
          </div>
          <div class="flex" style="margin-bottom:10px">
            <span id="csvCount" class="text-muted"></span>
          </div>
          <div class="progress-bar-wrap" id="importProgress" style="display:none">
            <div class="progress-bar" id="importBar" style="width:0%"></div>
          </div>
          <div class="flex">
            <button class="btn btn-green" id="importBtn" onclick="importCSV()">📥 Import All Rows</button>
            <button class="btn btn-gray"  onclick="resetImport()">✖ Reset</button>
          </div>
        </div>
        <div id="importResult" style="margin-top:14px"></div>
      </div>

      <div class="card">
        <h3>📋 CSV Template</h3>
        <p class="text-muted" style="margin-bottom:12px">Download a ready-to-fill CSV template:</p>
        <button class="btn btn-blue" onclick="downloadTemplate()">⬇ Download Template (with company column)</button>
        <div style="margin-top:16px">
          <strong style="font-size:13px">Example rows:</strong>
          <pre style="background:#f5f5f5;padding:12px;border-radius:8px;margin-top:8px;font-size:12px;overflow-x:auto">name,calories,sugar,salt_mg,fat,protein,fiber,barcode,company_name
Maggi 2-Minute Noodles,350,2.5,850,14.5,8,1.2,8901058851752,Nestlé India
Parle-G Biscuits,450,25,310,10,6.5,1.0,8901719110154,Parle Products
Coca-Cola 300ml,42,10.6,10,0,0,0,,Coca-Cola India
Lay's Classic Salted,536,0.5,545,34,,,,PepsiCo India</pre>
        </div>
      </div>

      <!-- ══════════ MEDIA FILES ══════════ -->
      <?php elseif ($activeTab==='media'): ?>
      <div class="card">
        <h3>📤 Upload File</h3>
        <form method="POST" enctype="multipart/form-data">
          <label class="upload-box" for="mediaInput">
            <span class="up-icon">☁️</span>
            <p><strong>Click to select a file</strong></p>
            <p style="margin-top:6px;font-size:12px">Images, Videos, Audio, PDF, Excel, Word, CSV · Max 10MB</p>
          </label>
          <input type="file" id="mediaInput" name="media_file" style="display:none" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv" onchange="this.form.submit()">
        </form>
        <div class="alert alert-info mt-12">Files land in <code>/htdocs/</code> and are accessible at <code>https://vitahar.kesug.com/filename</code> instantly.</div>
      </div>
      <div class="card">
        <h3>🗂️ Uploaded Files</h3>
        <div class="media-grid" id="mediaGrid"><div class="loading">Loading…</div></div>
      </div>

      <!-- ══════════ AGE LIMITS ══════════ -->
      <?php elseif ($activeTab==='agelimits'): ?>
      <div class="card">
        <h3>👶 Age Group Thresholds (per 100g serving)</h3>
        <div class="alert alert-info">Edit recommended nutrient limits including <strong>Protein</strong> and <strong>Fiber</strong>. Click <strong>Save to Database</strong> — the public site picks up changes immediately. No code editing needed.</div>
        <div id="ageLimitsForm">Loading…</div>
        <div id="ageSaveMsg" style="margin-top:12px"></div>
        <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap">
          <button class="btn btn-green" onclick="saveAgeLimits()">💾 Save to Database</button>
          <button class="btn btn-orange" onclick="generateSnippet()">⚙️ Generate Snippet</button>
        </div>
        <div id="snippetOut" style="display:none;margin-top:20px">
          <div class="alert alert-info">Backup — values are already saved to DB above. You can also paste this into <code>script.js</code> as a static fallback:</div>
          <pre id="snippetCode" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:8px;overflow-x:auto;font-size:13px;line-height:1.6"></pre>
          <button class="btn btn-blue mt-12" onclick="copySnippet()">📋 Copy to Clipboard</button>
        </div>
      </div>

      <!-- ══════════ SITE CONTENT ══════════ -->
      <?php elseif ($activeTab==='content'): ?>
      <div class="card">
        <h3>📝 Homepage Hero Text</h3>
        <div class="alert alert-info">Edits the animated headline/subtext on the homepage. Leave a field blank to keep the site's built-in default.</div>
        <div class="form-group">
          <label>Hero Heading</label>
          <input type="text" id="contentHomeHeroTitle" placeholder="Know What You Eat. Trust Your Health.">
        </div>
        <div class="form-group">
          <label>Hero Subtext</label>
          <textarea id="contentHomeHeroSubtitle" rows="2" placeholder="Vitahar helps Indian citizens verify whether packaged food is safe and healthy for their age."></textarea>
        </div>
      </div>

      <div class="card">
        <h3>🍜 Maggi Landing Page — Intro Content</h3>
        <div class="alert alert-info">This is the write-up shown above the checker tool on <code>/maggi.html</code>. HTML tags (like &lt;h3&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;p&gt;) are allowed since this is trusted admin content. Leave blank to keep the built-in default.</div>
        <div class="form-group">
          <textarea id="contentMaggiIntro" rows="10" style="font-family:monospace;font-size:13px" placeholder="&lt;h2&gt;Is Maggi Noodles Safe To Eat?&lt;/h2&gt;&lt;p&gt;...&lt;/p&gt;"></textarea>
        </div>
      </div>

      <div class="card">
        <h3>❓ Maggi Page — FAQ (shown on-page AND in Google's FAQ rich results)</h3>
        <div class="alert alert-info">These 4 Q&amp;A pairs power both the visible FAQ section and the structured data Google reads for rich results — edit once, both update.</div>
        <?php for ($i = 1; $i <= 4; $i++): ?>
        <div class="form-row" style="margin-bottom:14px">
          <div class="form-group" style="grid-column:span 2">
            <label>Question <?= $i ?></label>
            <input type="text" id="contentFaq<?= $i ?>Q">
          </div>
          <div class="form-group" style="grid-column:span 2">
            <label>Answer <?= $i ?></label>
            <textarea id="contentFaq<?= $i ?>A" rows="2"></textarea>
          </div>
        </div>
        <?php endfor; ?>
      </div>

      <div class="flex" style="margin:8px 0 24px">
        <button class="btn btn-green" onclick="saveSiteContent()">💾 Save All Site Content</button>
        <button class="btn btn-gray" onclick="loadSiteContent()">Reset</button>
      </div>
      <div id="contentSaveMsg"></div>

      <!-- ══════════ SCHEMA MANAGER ══════════ -->
      <?php elseif ($activeTab==='schema'): ?>

      <div class="alert alert-info" style="margin-bottom:20px">
        🏗️ <strong>Schema Manager</strong> — Add, rename, or delete columns on your <code>foods</code> and <code>companies</code> tables directly from here. No code editing, no PHPMyAdmin needed. <strong>Protected core columns</strong> (id, name, calories, etc.) are locked and cannot be deleted.
      </div>

      <!-- Table selector -->
      <div class="card">
        <h3>📋 Select Table to Manage</h3>
        <div class="flex">
          <button class="btn btn-green" id="btnFoods" onclick="selectTable('foods')">🥗 foods table</button>
          <button class="btn btn-gray"  id="btnCompanies" onclick="selectTable('companies')">🏢 companies table</button>
        </div>
        <div id="schemaSelectedLabel" style="margin-top:12px;font-size:13px;color:#777">Select a table above to view and manage its columns.</div>
      </div>

      <!-- Current Columns -->
      <div class="card" id="schemaColsCard" style="display:none">
        <h3>🗂️ Current Columns — <span id="schemaTableName" style="color:#1976d2"></span></h3>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Column Name</th><th>Type</th><th>Nullable</th><th>Default</th><th>Actions</th></tr></thead>
            <tbody id="schemaColsTbody"></tbody>
          </table>
        </div>
      </div>

      <!-- Add New Column -->
      <div class="card" id="schemaAddCard" style="display:none">
        <h3>➕ Add New Column to <span id="schemaAddTableLabel" style="color:#1976d2"></span></h3>
        <div class="form-row">
          <div class="form-group">
            <label>Column Name *</label>
            <input type="text" id="newColName" placeholder="e.g. category, brand_name, image_url">
            <span class="text-muted">Lowercase, no spaces (use underscore)</span>
          </div>
          <div class="form-group">
            <label>Data Type *</label>
            <select id="newColType">
              <option value="VARCHAR(255)">Text (short) — VARCHAR(255)</option>
              <option value="TEXT">Text (long) — TEXT</option>
              <option value="INT">Whole Number — INT</option>
              <option value="FLOAT">Decimal Number — FLOAT</option>
              <option value="TINYINT(1)">Yes/No (boolean) — TINYINT</option>
              <option value="DATE">Date — DATE</option>
            </select>
          </div>
          <div class="form-group" id="defaultValGroup">
            <label>Default Value <span class="text-muted">(optional)</span></label>
            <input type="text" id="newColDefault" placeholder="Leave blank for empty default">
          </div>
        </div>
        <div class="alert alert-warn" style="margin-bottom:14px">
          ⚠️ After adding a column, existing rows will have an empty/zero value for it. You can fill them in from the <strong>Foods DB</strong> tab (the new field will appear automatically).
        </div>
        <button class="btn btn-green" onclick="addColumn()">➕ Add Column</button>
        <div id="schemaAddMsg" style="margin-top:12px"></div>
      </div>

      <!-- Rename Column -->
      <div class="card" id="schemaRenameCard" style="display:none">
        <h3>✏️ Rename a Column</h3>
        <div class="form-row">
          <div class="form-group">
            <label>Column to Rename</label>
            <select id="renameOldCol"></select>
          </div>
          <div class="form-group">
            <label>New Name *</label>
            <input type="text" id="renameNewCol" placeholder="new_column_name">
          </div>
        </div>
        <button class="btn btn-orange" onclick="renameColumn()">✏️ Rename Column</button>
        <div id="schemaRenameMsg" style="margin-top:12px"></div>
      </div>

      <!-- ══════════ DB TOOLS ══════════ -->
      <?php elseif ($activeTab==='tools'): ?>
      <div class="card">
        <h3>🔍 Duplicate Food Checker</h3>
        <p class="text-muted" style="margin-bottom:14px">Finds food items with the same name. When removing, the lowest ID (oldest) entry is kept.</p>
        <div class="flex" style="margin-bottom:16px">
          <button class="btn btn-blue" onclick="checkDuplicates()">🔍 Scan for Duplicates</button>
          <button class="btn btn-red"  id="removeDupBtn" style="display:none" onclick="removeDuplicates()">🗑️ Remove All Duplicates</button>
        </div>
        <div id="dupResult"></div>
      </div>

      <div class="card">
        <h3>📊 Foods by Source</h3>
        <button class="btn btn-blue btn-sm" onclick="loadSourceStats()">📊 Load Stats</button>
        <div id="sourceStats" style="margin-top:16px"></div>
      </div>

      <div class="card">
        <h3>⚠️ Danger Zone</h3>
        <div class="alert alert-danger">These actions are irreversible. Be careful.</div>
        <div class="flex">
          <button class="btn btn-red" onclick="if(confirm('Delete ALL foods? This cannot be undone!')) runDangerSQL('DELETE FROM foods')">🗑️ Delete ALL Foods</button>
          <button class="btn btn-red" onclick="if(confirm('Delete ALL companies? This cannot be undone!')) runDangerSQL('DELETE FROM companies')">🗑️ Delete ALL Companies</button>
        </div>
        <div id="dangerResult" style="margin-top:12px"></div>
      </div>

      <!-- ══════════ ACTIVITY LOG ══════════ -->
      <?php elseif ($activeTab==='log'): ?>
      <div class="card">
        <h3>📋 Activity Log (last 50 actions)</h3>
        <div class="flex" style="margin-bottom:16px">
          <button class="btn btn-blue btn-sm" onclick="loadLog()">🔄 Refresh</button>
          <button class="btn btn-red  btn-sm" onclick="if(confirm('Clear entire activity log?')) clearLog()">🗑️ Clear Log</button>
        </div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>#</th><th>Action</th><th>Detail</th><th>Time</th></tr></thead>
            <tbody id="logTbody"><tr><td colspan="4" class="loading">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- ══════════ REPORTS ══════════ -->
      <?php elseif ($activeTab==='reports'): ?>
      <div class="card">
        <h3>🚩 Flagged Foods (Anomalies)</h3>
        <p class="text-muted">These foods have been flagged by users as having incorrect or impossible nutritional values.</p>
        <div class="flex" style="margin-bottom:16px">
          <button class="btn btn-blue btn-sm" onclick="loadReports()">🔄 Refresh</button>
        </div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>ID</th><th>Name</th><th>Flags</th><th>Actions</th></tr></thead>
            <tbody id="reportsTbody"><tr><td colspan="4" class="loading">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- ══════════ SETTINGS ══════════ -->
      <?php elseif ($activeTab==='settings'): ?>
      <div class="card">
        <h3>🔑 Change Admin Password</h3>
        <div class="alert alert-warn">You do NOT need to edit any PHP file to change the password. Fill in the form below — it's saved automatically.</div>
        <div class="form-row" style="max-width:480px">
          <div class="form-group" style="grid-column:span 2">
            <label>Current Password</label>
            <input type="password" id="pwCurrent" placeholder="Your current password">
          </div>
          <div class="form-group">
            <label>New Password</label>
            <input type="password" id="pwNew" placeholder="Min. 8 characters">
          </div>
          <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" id="pwConfirm" placeholder="Repeat new password">
          </div>
        </div>
        <button class="btn btn-green" onclick="changePassword()">💾 Update Password</button>
        <div id="pwMsg" style="margin-top:12px"></div>
      </div>

      <div class="card">
        <h3>Public Food Form Nutrition Columns</h3>
        <div class="alert alert-info">Choose which <code>foods</code> table nutrition columns are visible on the public food safety form. New columns are visible automatically, but blank or zero custom values are hidden on the public result.</div>
        <div id="nutritionColumnsList" class="check-grid" style="margin:16px 0"><span class="text-muted">Loading columns...</span></div>
        <div class="flex">
          <button class="btn btn-green" onclick="saveNutritionColumns()">Save Visibility</button>
          <button class="btn btn-gray" onclick="loadNutritionColumns()">Reset</button>
        </div>
        <div id="nutritionColumnsMsg" style="margin-top:12px"></div>
      </div>

      <div class="card">
        <h3>ℹ️ Site Info</h3>
        <table style="width:auto;font-size:13px">
          <tr><td style="padding:8px 14px 8px 0;color:#777">Admin URL</td><td><code>https://vitahar.kesug.com/admin.php</code></td></tr>
          <tr><td style="padding:8px 14px 8px 0;color:#777">DB Host</td><td><code><?= DB_HOST ?></code></td></tr>
          <tr><td style="padding:8px 14px 8px 0;color:#777">DB Name</td><td><code><?= DB_NAME ?></code></td></tr>
          <tr><td style="padding:8px 14px 8px 0;color:#777">PHP Version</td><td><code><?= phpversion() ?></code></td></tr>
          <tr><td style="padding:8px 14px 8px 0;color:#777">Server Time</td><td><code><?= date('Y-m-d H:i:s') ?></code></td></tr>
          <tr><td style="padding:8px 14px 8px 0;color:#777">Session lifetime</td><td><code>1 hour</code></td></tr>
        </table>
      </div>

      <!-- ══════════ SQL CONSOLE ══════════ -->
      <?php elseif ($activeTab==='sql'): ?>
      <div class="card sql-console">
        <h3>💻 SQL Console (Read-Only)</h3>
        <div class="alert alert-warn">Only SELECT, SHOW, DESCRIBE, EXPLAIN are permitted.</div>
        <div class="form-group" style="margin-bottom:12px">
          <label>SQL Query <span class="text-muted">(Ctrl+Enter to run)</span></label>
          <textarea id="sqlInput" rows="6" placeholder="SELECT * FROM foods LIMIT 20;">SELECT name, calories, sugar, salt, fat FROM foods ORDER BY calories DESC LIMIT 20;</textarea>
        </div>
        <div class="flex">
          <button class="btn btn-green" onclick="runSQL()">▶ Run Query</button>
          <button class="btn btn-gray"  onclick="document.getElementById('sqlInput').value=''">✖ Clear</button>
          <span id="sqlRowCount" class="text-muted"></span>
        </div>
        <div class="sql-result" id="sqlResult"></div>
      </div>

      <div class="card">
        <h3>📌 Useful Queries</h3>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          <?php $queries=['All Foods'=>'SELECT * FROM foods ORDER BY id DESC LIMIT 50;','High Calorie'=>'SELECT name,calories FROM foods ORDER BY calories DESC LIMIT 20;','High Sugar'=>'SELECT name,sugar FROM foods ORDER BY sugar DESC LIMIT 20;','By Source'=>'SELECT source,COUNT(*) as count FROM foods GROUP BY source ORDER BY count DESC;','Show Tables'=>'SHOW TABLES;','All Companies'=>'SELECT c.name,COUNT(cp.food_id) as products FROM companies c LEFT JOIN company_products cp ON cp.company_id=c.id GROUP BY c.id;','With Barcode'=>'SELECT name,barcode FROM foods WHERE barcode IS NOT NULL ORDER BY name;','Describe foods'=>'DESCRIBE foods;','Recent Log'=>'SELECT * FROM activity_log ORDER BY id DESC LIMIT 20;'];
          foreach ($queries as $label=>$q): ?>
          <button class="btn btn-gray btn-sm" onclick="setSQL(<?= htmlspecialchars(json_encode($q)) ?>)"><?= htmlspecialchars($label) ?></button>
          <?php endforeach ?>
        </div>
      </div>

      <?php endif ?>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /admin-layout -->

<div id="toast"></div>

<script>
// ── MOBILE SIDEBAR DRAWER ────────────────────────────────────────
(function() {
  const toggle = document.getElementById('menuToggle');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (!toggle) return;
  function closeDrawer() {
    document.body.classList.remove('sidebar-open');
    toggle.setAttribute('aria-expanded', 'false');
  }
  function openDrawer() {
    document.body.classList.add('sidebar-open');
    toggle.setAttribute('aria-expanded', 'true');
  }
  toggle.addEventListener('click', () => {
    document.body.classList.contains('sidebar-open') ? closeDrawer() : openDrawer();
  });
  backdrop.addEventListener('click', closeDrawer);
  document.querySelectorAll('.sidebar nav a').forEach(a => a.addEventListener('click', closeDrawer));
  window.addEventListener('resize', () => { if (window.innerWidth > 700) closeDrawer(); });
})();

// ── TOAST ─────────────────────────────────────────────────────
function toast(msg, duration=3000) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), duration);
}

// ── API HELPER ─────────────────────────────────────────────────
async function api(action, body=null) {
  const url = 'admin.php?ajax=' + action;
  const opts = { headers: {'Content-Type':'application/json'} };
  if (body) { opts.method='POST'; opts.body=JSON.stringify(body); }
  else       { opts.method='GET'; }
  let r;
  try {
    r = await fetch(url, opts);
  } catch (e) {
    // fetch() itself only throws for a genuine network-level failure
    // (offline, DNS, CORS) — this is a real network error.
    toast('❌ Network error — check your connection');
    return {};
  }
  if (r.status === 401) {
    // Session was lost/expired server-side. Reload so the user lands
    // back on the login screen instead of seeing silent failures.
    toast('⚠️ Session expired — reloading…');
    setTimeout(() => location.reload(), 1200);
    return {};
  }
  let data;
  try {
    data = await r.json();
  } catch (e) {
    // Response wasn't valid JSON (e.g. a PHP error page).
    toast('❌ Server returned an unexpected response (HTTP ' + r.status + ')');
    return {};
  }
  if (!r.ok || data.error) {
    toast('❌ ' + (data.msg || data.error || ('Request failed (HTTP ' + r.status + ')')));
  }
  return data;
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── DASHBOARD ──────────────────────────────────────────────────
<?php if ($activeTab==='dashboard'): ?>
async function refreshDashboardStats(retrying=false) {
  // Sequential, not Promise.all: InfinityFree's free tier throttles/
  // resets concurrent connections from the same client, and firing
  // two fetches at once right after page load was causing a real
  // "Failed to fetch" network error on the very first request.
  const s = await api('stats');
  const m = await api('list_media');
  const statsOk = s && Object.keys(s).length > 0 && !s.error;
  document.getElementById('statFoods').textContent     = s.foods ?? '?';
  document.getElementById('statCompanies').textContent = s.companies ?? '?';
  document.getElementById('statTables').textContent    = (s.tables ?? []).length;
  document.getElementById('statFiles').textContent     = (m.files ?? []).length;
  document.getElementById('statLogs').textContent      = s.log_count ?? '?';
  document.getElementById('statApiNew').textContent    = s.api_new ?? '?';
  if (s.tables) {
    document.getElementById('tableList').innerHTML = s.tables.map(t =>
      `<span class="badge badge-green" style="margin:3px">${t}</span>`).join('');
  } else if (!statsOk) {
    document.getElementById('tableList').innerHTML =
      retrying
        ? '<span class="text-muted">Could not load — check your connection and refresh the page.</span>'
        : '<span class="text-muted">Retrying…</span>';
    if (!retrying) {
      // One automatic retry after a short delay covers a one-off
      // connection hiccup without spamming requests.
      setTimeout(() => refreshDashboardStats(true), 2000);
    }
  }
}
refreshDashboardStats();
// Auto-refresh every 30 seconds so API-sourced foods & companies appear without manual reload
setInterval(refreshDashboardStats, 30000);

async function checkAPIHealth() {
  const apis = [
    { id: 'OFF', url: 'https://world.openfoodfacts.org/api/v0/product/737628064502.json' },
    { id: 'USDA', url: 'https://api.nal.usda.gov/fdc/v1/foods/search?query=apple&api_key=DEMO_KEY' },
    { id: 'Datakick', url: 'https://app.datakick.org/api/items/0000000000000' }
  ];

  for (let api of apis) {
    const statusEl = document.getElementById(`health${api.id}_status`);
    const latEl = document.getElementById(`health${api.id}_latency`);
    statusEl.innerHTML = '<span style="color:#f57c00">Pinging...</span>';
    latEl.textContent = '...';

    const start = performance.now();
    try {
      const c = new AbortController();
      const id = setTimeout(() => c.abort(), 5000);
      const res = await fetch(api.url, { signal: c.signal, mode: 'no-cors' }).catch(e => {
        // no-cors fetch will be opaque, but if it fails completely (e.g. DNS) it throws
        if (e.name === 'AbortError') throw new Error('Timeout');
        return { ok: true, type: 'opaque' }; 
      });
      clearTimeout(id);
      
      const latency = Math.round(performance.now() - start);
      latEl.textContent = latency + ' ms';
      
      if (res && (res.ok || res.type === 'opaque')) {
        statusEl.innerHTML = '<span class="badge badge-green">Online</span>';
      } else {
        statusEl.innerHTML = '<span class="badge badge-red">Error</span>';
      }
    } catch (e) {
      statusEl.innerHTML = '<span class="badge badge-red">Offline / Timeout</span>';
      latEl.textContent = '> 5000 ms';
    }
  }
}
<?php endif ?>

// ── REPORTS / ANOMALIES ──────────────────────────────────────
<?php if ($activeTab==='reports'): ?>
async function loadReports() {
  const d = await api('list_reports');
  const tbody = document.getElementById('reportsTbody');
  if (!d.rows || !d.rows.length) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#888">✅ No flagged foods!</td></tr>';
    return;
  }
  tbody.innerHTML = d.rows.map(r => `
    <tr>
      <td><span class="badge badge-gray">${r.id}</span></td>
      <td><strong>${esc(r.name)}</strong></td>
      <td><span class="badge badge-red">${r.anomaly} Flags</span></td>
      <td>
        <button class="btn btn-blue btn-sm" onclick="window.location.href='?tab=foods&edit=${r.id}'">✏️ Edit Values</button>
        <button class="btn btn-green btn-sm" onclick="clearAnomaly(${r.id}, '${esc(r.name).replace(/'/g,"\\'")}')">✅ Mark Safe</button>
      </td>
    </tr>
  `).join('');
}

async function clearAnomaly(id, name) {
  if (!confirm(`Clear anomaly flags for "${name}"?`)) return;
  const d = await api('clear_anomaly', { id });
  if (d.ok) {
    toast('✅ Flags cleared');
    loadReports();
  } else {
    toast('❌ Failed to clear flags');
  }
}

loadReports();
<?php endif ?>

// ── FOODS ──────────────────────────────────────────────────────
<?php if ($activeTab==='foods'): ?>
let foodPage=1, foodSearchTimer=null, foodColumns=[], foodRowsById={};
const STATIC_FOOD_FIELDS=['id','name','calories','sugar','salt','fat','protein','fiber','source','barcode','created_at','updated_at'];
function debounceSearch() { clearTimeout(foodSearchTimer); foodSearchTimer=setTimeout(()=>loadFoods(1),400); }

function colLabel(name){ return String(name||'').replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()); }
function colInputType(type){
  type=String(type||'').toLowerCase();
  if (type.includes('tinyint(1)')) return 'checkbox';
  if (type.includes('int')||type.includes('float')||type.includes('double')||type.includes('decimal')) return 'number';
  if (type.includes('date')) return 'date';
  return 'text';
}
function extraFoodColumns(){ return foodColumns.filter(c=>!STATIC_FOOD_FIELDS.includes(c.Field)); }
function displayFoodColumns(){
  const preferred=['id','name','calories','sugar','salt','fat','protein','fiber'];
  const extras=extraFoodColumns().map(c=>c.Field);
  const tail=['source','barcode'];
  const names=[...preferred,...extras,...tail].filter((v,i,a)=>a.indexOf(v)===i);
  return names
    .map(name=>foodColumns.find(c=>c.Field===name)||{Field:name,Type:''})
    .filter(c=>foodColumns.some(existing=>existing.Field===c.Field) || ['id','name','source','barcode'].includes(c.Field));
}
function formatFoodCell(value){
  if (value===null || value===undefined || value==='') return '<span class="text-muted">-</span>';
  return esc(value);
}
function renderCustomFoodFields(row={}){
  const wrap=document.getElementById('customFoodFields');
  const extras=extraFoodColumns();
  if (!wrap) return;
  if (!extras.length) { wrap.innerHTML=''; return; }
  wrap.innerHTML=extras.map(col=>{
    const id='foodCustom_'+col.Field;
    const type=colInputType(col.Type);
    const value=row[col.Field] ?? '';
    if (type==='checkbox') {
      return `<div class="form-group"><label>${esc(colLabel(col.Field))}</label><label class="check-item"><input type="checkbox" id="${id}" data-field="${esc(col.Field)}" ${Number(value)?'checked':''}> Yes / true</label></div>`;
    }
    const step=type==='number' && String(col.Type).toLowerCase().match(/float|double|decimal/) ? ' step="0.1"' : '';
    return `<div class="form-group"><label>${esc(colLabel(col.Field))}</label><input type="${type}" id="${id}" data-field="${esc(col.Field)}" value="${esc(value)}"${step}></div>`;
  }).join('');
}
function collectCustomFoodValues(body){
  extraFoodColumns().forEach(col=>{
    const el=document.getElementById('foodCustom_'+col.Field);
    if (!el) return;
    body[col.Field]=el.type==='checkbox' ? (el.checked ? 1 : 0) : el.value;
  });
}

async function loadFoods(page=1) {
  foodPage=page;
  const q      = document.getElementById('foodSearch').value.trim();
  const source = document.getElementById('foodSourceFilter').value;
  const limit  = document.getElementById('foodLimitSel').value;
  const d = await api(`list_foods&page=${page}&q=${encodeURIComponent(q)}&source=${encodeURIComponent(source)}&limit=${limit}`);
  const tbody = document.getElementById('foodsTbody');

  if (d.columns) {
    foodColumns=d.columns;
    renderCustomFoodFields();
  }

  const cols=displayFoodColumns();
  const table=tbody.closest('table');
  table.querySelector('thead').innerHTML='<tr>'+cols.map(c=>`<th>${esc(colLabel(c.Field))}</th>`).join('')+'<th>Actions</th></tr>';

  if (!d.rows || !d.rows.length) {
    tbody.innerHTML=`<tr><td colspan="${cols.length+1}" style="text-align:center;color:#888;padding:24px">No foods found</td></tr>`;
    document.getElementById('foodCount').textContent='0 results';
    document.getElementById('foodPagination').innerHTML='';
    return;
  }

  foodRowsById={};
  d.rows.forEach(r=>{ foodRowsById[Number(r.id)]=r; });
  document.getElementById('foodCount').textContent=`${d.total} total`;
  tbody.innerHTML=d.rows.map(r=>`
    <tr>
      ${cols.map(c=>{
        if (c.Field==='id') return `<td><span class="badge badge-gray">${esc(r.id)}</span></td>`;
        if (c.Field==='name') return `<td><strong>${esc(r.name)}</strong> ${r.anomaly == 1 ? '<span class="badge badge-red" title="Reported as incorrect by users">🚩 Reported</span>' : ''}</td>`;
        if (c.Field==='source') return `<td><span class="badge badge-blue">${esc(r.source||'')}</span></td>`;
        if (c.Field==='barcode') return `<td>${r.barcode?`<span class="badge badge-orange">${esc(r.barcode)}</span>`:'<span class="text-muted">-</span>'}</td>`;
        return `<td>${formatFoodCell(r[c.Field])}</td>`;
      }).join('')}
      <td>
        <button class="btn btn-orange btn-sm" onclick="editFoodById(${Number(r.id)})">Edit</button>
        <button class="btn btn-red btn-sm" onclick="deleteFoodById(${Number(r.id)})">Delete</button>
      </td>
    </tr>`).join('');

  const pages=Math.ceil(d.total/d.limit);
  let pg='';
  const start=Math.max(1,page-3), end=Math.min(pages,page+3);
  if (start>1) pg+=`<button onclick="loadFoods(1)">1</button><span>...</span>`;
  for (let i=start;i<=end;i++) pg+=`<button class="${i===page?'active':''}" onclick="loadFoods(${i})">${i}</button>`;
  if (end<pages) pg+=`<span>...</span><button onclick="loadFoods(${pages})">${pages}</button>`;
  document.getElementById('foodPagination').innerHTML=`<span>${d.total} results - Page ${page}/${pages}</span>`+pg;
}

function editFoodById(id){ const row=foodRowsById[Number(id)]; if (row) editFood(row); }

function editFood(r) {
  document.getElementById('foodId').value=r.id;
  document.getElementById('foodName').value=r.name;
  document.getElementById('foodCal').value=r.calories;
  document.getElementById('foodSugar').value=r.sugar;
  document.getElementById('foodSalt').value=r.salt;
  document.getElementById('foodFat').value=r.fat;
  document.getElementById('foodProtein').value=r.protein||0;
  document.getElementById('foodFiber').value=r.fiber||0;
  document.getElementById('foodSource').value=r.source||'admin';
  document.getElementById('foodBarcode').value=r.barcode||'';
  renderCustomFoodFields(r);
  document.getElementById('foodName').scrollIntoView({behavior:'smooth',block:'center'});
  document.getElementById('foodName').focus();
}

function clearFoodForm() {
  ['foodId','foodName','foodCal','foodSugar','foodSalt','foodFat','foodProtein','foodFiber','foodBarcode'].forEach(id=>{
    document.getElementById(id).value=(id==='foodId')?'0':'';
  });
  document.getElementById('foodSource').value='admin';
  document.getElementById('foodFormMsg').innerHTML='';
  renderCustomFoodFields();
}

async function saveFood() {
  const body={
    id:parseInt(document.getElementById('foodId').value),
    name:document.getElementById('foodName').value.trim(),
    calories:parseFloat(document.getElementById('foodCal').value)||0,
    sugar:parseFloat(document.getElementById('foodSugar').value)||0,
    salt:parseFloat(document.getElementById('foodSalt').value)||0,
    fat:parseFloat(document.getElementById('foodFat').value)||0,
    protein:parseFloat(document.getElementById('foodProtein').value)||0,
    fiber:parseFloat(document.getElementById('foodFiber').value)||0,
    source:document.getElementById('foodSource').value,
    barcode:document.getElementById('foodBarcode').value.trim(),
  };
  collectCustomFoodValues(body);
  const msg=document.getElementById('foodFormMsg');
  if (!body.name) { msg.innerHTML='<div class="alert alert-danger">Name is required.</div>'; return; }
  const d=await api('upsert_food',body);
  if (d.ok) { msg.innerHTML=`<div class="alert alert-success">${d.msg}</div>`; clearFoodForm(); loadFoods(foodPage); toast('Saved: '+d.msg); }
  else msg.innerHTML=`<div class="alert alert-danger">${d.msg||'Error'}</div>`;
}

async function deleteFoodById(id){
  const row=foodRowsById[Number(id)];
  if (row) return deleteFood(row.id,row.name);
}

async function deleteFood(id, name) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
  const d=await api('delete_food',{id});
  if (d.ok) { toast('🗑️ Deleted: '+name); loadFoods(foodPage); }
  else toast('❌ Delete failed');
}

loadFoods(1);
<?php endif ?>

// ── COMPANIES ──────────────────────────────────────────────────
<?php if ($activeTab==='companies'): ?>
let coSearchTimer=null;
function debounceCoSearch() { clearTimeout(coSearchTimer); coSearchTimer=setTimeout(()=>loadCompanies(),400); }

async function loadCompanies() {
  const q=document.getElementById('coSearch').value.trim();
  const d=await api(`list_companies&q=${encodeURIComponent(q)}`);
  const tbody=document.getElementById('coTbody');
  if (!d.rows||!d.rows.length) { tbody.innerHTML='<tr><td colspan="5" style="text-align:center;color:#888;padding:24px">No companies found</td></tr>'; return; }
  tbody.innerHTML=d.rows.map((r,i)=>{
    const productList = (r.products||[]).slice(0,3).map(p=>esc(p)).join(', ') + ((r.products||[]).length>3?` <em class="text-muted">+${r.products.length-3} more</em>` : '');
    const isNew = i < 5 ? '<span class="badge badge-orange" style="margin-left:6px">NEW</span>' : '';
    return `
    <tr>
      <td><span class="badge badge-gray">${r.id}</span></td>
      <td><strong>${esc(r.name)}</strong>${isNew}</td>
      <td><span class="badge badge-green">${r.product_count} product${r.product_count!==1?'s':''}</span></td>
      <td style="max-width:260px;font-size:12px;color:#555">${productList||'<span class="text-muted">No products linked yet</span>'}</td>
      <td>
        <button class="btn btn-blue btn-sm" onclick="openLinkModal(${r.id})">🔗 Link Product</button>
        <button class="btn btn-red  btn-sm" onclick="deleteCompany(${r.id},'${esc(r.name).replace(/'/g,"\\'")}')">🗑️</button>
      </td>
    </tr>`;
  }).join('');
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function addCompany() {
  const name=document.getElementById('coName').value.trim();
  const msg=document.getElementById('coMsg');
  if (!name) { msg.innerHTML='<div class="alert alert-danger">Name required.</div>'; return; }
  const d=await api('add_company',{name});
  if (d.ok) { msg.innerHTML='<div class="alert alert-success">Company added!</div>'; document.getElementById('coName').value=''; loadCompanies(); toast('✅ Company added'); }
  else msg.innerHTML=`<div class="alert alert-danger">${d.msg||'Error'}</div>`;
}

async function deleteCompany(id,name) {
  if (!confirm(`Delete company "${name}" and all its product links?`)) return;
  const d=await api('delete_company',{id});
  if (d.ok) { toast('🗑️ Company deleted'); loadCompanies(); } else toast('❌ Failed');
}

function openLinkModal(cid) {
  document.getElementById('linkCompanyId').value=cid;
  document.getElementById('linkFoodSearch').value='';
  document.getElementById('linkFoodResults').innerHTML='';
  document.getElementById('linkModal').classList.add('open');
}
function closeLinkModal() { document.getElementById('linkModal').classList.remove('open'); loadCompanies(); }

let linkTimer=null;
async function searchFoodForLink() {
  clearTimeout(linkTimer);
  linkTimer=setTimeout(async()=>{
    const q=document.getElementById('linkFoodSearch').value.trim();
    if (q.length<2) { document.getElementById('linkFoodResults').innerHTML=''; return; }
    const d=await api(`list_foods&q=${encodeURIComponent(q)}&page=1`);
    const cid=document.getElementById('linkCompanyId').value;
    const div=document.getElementById('linkFoodResults');
    if (!d.rows||!d.rows.length) { div.innerHTML='<p class="text-muted">No foods found.</p>'; return; }
    div.innerHTML='<div class="tbl-wrap"><table><thead><tr><th>Name</th><th>Action</th></tr></thead><tbody>'+
      d.rows.map(r=>`<tr><td>${esc(r.name)}</td><td><button class="btn btn-green btn-sm" onclick="linkProduct(${cid},${r.id},'${esc(r.name).replace(/'/g,"\\'")}')">🔗 Link</button></td></tr>`).join('')+
      '</tbody></table></div>';
  },350);
}

async function linkProduct(cid,fid,fname) {
  const d=await api('link_product',{company_id:cid,food_id:fid});
  if (d.ok) toast(`✅ Linked "${fname}"`); else toast('❌ Failed to link');
}

loadCompanies();
<?php endif ?>

// ── BULK IMPORT ────────────────────────────────────────────────
<?php if ($activeTab==='import'): ?>
let csvRows=[];

document.getElementById('csvFile').addEventListener('change', function(e) {
  const file=e.target.files[0];
  if (!file) return;
  const reader=new FileReader();
  reader.onload=ev=>{
    const lines=ev.target.result.split('\n').map(l=>l.trim()).filter(Boolean);
    csvRows=[];
    let skippedHeader=false;
    const tbody=document.getElementById('csvTbody');
    tbody.innerHTML='';
    for (const line of lines) {
      // Handle quoted CSV properly
      const cols=parseCSVLine(line);
      if (!skippedHeader && isNaN(parseFloat(cols[1]))) { skippedHeader=true; continue; }
      if (cols[0]&&cols[0].trim()) {
        csvRows.push(cols);
        tbody.innerHTML+=`<tr>
          <td>${esc(cols[0]||'')}</td><td>${cols[1]||0}</td><td>${cols[2]||0}</td>
          <td>${cols[3]||0}</td><td>${cols[4]||0}</td><td>${cols[5]||''}</td>
          <td>${cols[6]||''}</td><td>${cols[7]||''}</td>
          <td>${cols[8]?`<span class="badge badge-orange">${esc(cols[8])}</span>`:'<span class="text-muted">—</span>'}</td>
        </tr>`;
      }
    }
    document.getElementById('csvCount').textContent=csvRows.length+' rows ready to import';
    document.getElementById('csvPreview').style.display=csvRows.length?'block':'none';
    document.getElementById('importResult').innerHTML='';
  };
  reader.readAsText(file);
});

function parseCSVLine(line) {
  const result=[]; let cur='', inQ=false;
  for (let i=0;i<line.length;i++) {
    const c=line[i];
    if (c==='"') { inQ=!inQ; continue; }
    if (c===','&&!inQ) { result.push(cur.trim()); cur=''; continue; }
    cur+=c;
  }
  result.push(cur.trim());
  return result;
}

async function importCSV() {
  if (!csvRows.length) return;
  const btn=document.getElementById('importBtn');
  const prog=document.getElementById('importProgress');
  const bar=document.getElementById('importBar');
  btn.disabled=true; btn.textContent='⏳ Importing…';
  prog.style.display='block'; bar.style.width='10%';

  // Send in batches of 200 for large imports
  const BATCH=200;
  let totalOk=0, totalFail=0, totalSkip=0;
  const batches=Math.ceil(csvRows.length/BATCH);
  for (let i=0;i<batches;i++) {
    const chunk=csvRows.slice(i*BATCH,(i+1)*BATCH);
    const d=await api('import_csv',{rows:chunk});
    if (d.ok) { totalOk+=d.imported||0; totalFail+=d.failed||0; totalSkip+=d.skipped||0; }
    bar.style.width=Math.round(((i+1)/batches)*100)+'%';
  }
  btn.disabled=false; btn.textContent='📥 Import All Rows';
  document.getElementById('importResult').innerHTML=
    `<div class="alert alert-success">✅ Imported <strong>${totalOk}</strong> foods. Failed: <strong>${totalFail}</strong>. Skipped: <strong>${totalSkip}</strong>.</div>`;
  toast(`✅ Imported ${totalOk} foods`);
}

function resetImport() {
  csvRows=[];
  document.getElementById('csvFile').value='';
  document.getElementById('csvPreview').style.display='none';
  document.getElementById('importResult').innerHTML='';
}

function downloadTemplate() {
  const csv='name,calories,sugar,salt_mg,fat,protein,fiber,barcode,company_name\nMaggi 2-Minute Noodles,350,2.5,850,14.5,8,1.2,8901058851752,Nestlé India\nParle-G Biscuits,450,25,310,10,6.5,1.0,8901719110154,Parle Products\nCoca-Cola 300ml,42,10.6,10,0,0,0,,Coca-Cola India\nLay\'s Classic Salted,536,0.5,545,34,7,1.5,8901358100041,PepsiCo India';
  const a=document.createElement('a');
  a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
  a.download='vitahar_import_template.csv';
  a.click();
}
<?php endif ?>

// ── MEDIA ──────────────────────────────────────────────────────
<?php if ($activeTab==='media'): ?>
function fileIcon(ext) {
  const map={jpg:'🖼️',jpeg:'🖼️',png:'🖼️',gif:'🖼️',webp:'🖼️',svg:'🖼️',mp4:'🎬',webm:'🎬',mp3:'🎵',wav:'🎵',ogg:'🎵',pdf:'📄',doc:'📝',docx:'📝',xls:'📊',xlsx:'📊',txt:'📃',csv:'📋'};
  return map[ext]||'📁';
}
function fmtSize(b) {
  if (b<1024) return b+' B';
  if (b<1048576) return (b/1024).toFixed(1)+' KB';
  return (b/1048576).toFixed(1)+' MB';
}
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function loadMedia() {
  const d=await api('list_media');
  const grid=document.getElementById('mediaGrid');
  if (!d.files||!d.files.length) { grid.innerHTML='<p class="text-muted">No media files found.</p>'; return; }
  const imgExts=['jpg','jpeg','png','gif','webp','svg'];
  grid.innerHTML=d.files.map(f=>`
    <div class="media-item">
      ${imgExts.includes(f.ext)?`<img src="/${f.name}" class="media-thumb" alt="${f.name}" loading="lazy">`:`<span class="media-icon">${fileIcon(f.ext)}</span>`}
      <div class="media-name">${esc(f.name)}</div>
      <div class="media-size">${fmtSize(f.size)}</div>
      <div class="media-actions">
        <a class="btn btn-blue btn-sm" href="/${f.name}" target="_blank">👁️</a>
        <button class="btn btn-red btn-sm" onclick="deleteMedia('${f.name.replace(/'/g,"\\'")}')">🗑️</button>
      </div>
    </div>`).join('');
}

async function deleteMedia(name) {
  if (!confirm(`Delete "${name}"? Cannot be undone.`)) return;
  const d=await api('delete_media',{name});
  if (d.ok) { toast('🗑️ Deleted: '+name); loadMedia(); } else toast('❌ '+(d.msg||'Failed'));
}

document.getElementById('mediaInput').addEventListener('change',function(){ this.closest('form').submit(); });
loadMedia();
<?php endif ?>

// ── AGE LIMITS ─────────────────────────────────────────────────
<?php if ($activeTab==='agelimits'): ?>
const AGE_GROUP_LABELS = {
  age_0_5:    '0 – 5 years',
  age_5_12:   '5 – 12 years',
  age_12_18:  '12 – 18 years',
  age_18_30:  '18 – 30 years',
  age_30_60:  '30 – 60 years',
  age_60_plus:'60+ years',
};
const NUTRIENTS = [
  {key:'calories', label:'Calories', unit:'kcal', step:'1'},
  {key:'sugar',    label:'Sugar',    unit:'g',    step:'0.1'},
  {key:'salt',     label:'Salt',     unit:'mg',   step:'1'},
  {key:'fat',      label:'Fat',      unit:'g',    step:'0.1'},
  {key:'protein',  label:'Protein',  unit:'g',    step:'0.1'},
  {key:'fiber',    label:'Fiber',    unit:'g',    step:'0.1'},
];

let currentLimits = null;

function buildForm(limits) {
  currentLimits = limits;
  const form = document.getElementById('ageLimitsForm');
  form.innerHTML = Object.entries(AGE_GROUP_LABELS).map(([key, label]) => `
    <div class="card" style="margin-bottom:14px">
      <h3 style="font-size:14px">👤 ${label} <span class="badge badge-gray">${key}</span></h3>
      <div class="form-row">
        ${NUTRIENTS.map(n => `
          <div class="form-group">
            <label>${n.label} (${n.unit})</label>
            <input type="number" id="${key}_${n.key}" value="${limits[key]?.[n.key] ?? 0}" min="0" step="${n.step}">
          </div>`).join('')}
      </div>
    </div>`).join('');
}

async function loadAgeLimits() {
  const r = await fetch('api.php?action=get_age_limits');
  const d = await r.json();
  if (d.ok && d.limits) buildForm(d.limits);
  else buildForm({});
}

async function saveAgeLimits() {
  const limits = {};
  for (const key of Object.keys(AGE_GROUP_LABELS)) {
    limits[key] = {};
    for (const n of NUTRIENTS) {
      limits[key][n.key] = parseFloat(document.getElementById(`${key}_${n.key}`)?.value) || 0;
    }
  }
  const msg = document.getElementById('ageSaveMsg');
  msg.innerHTML = '<div class="alert alert-info">⏳ Saving…</div>';
  const r = await fetch('api.php?action=save_age_limits', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({limits})
  });
  const d = await r.json();
  if (d.ok) {
    msg.innerHTML = '<div class="alert alert-success">✅ Age limits saved to database! The public site will use these values immediately.</div>';
    toast('✅ Age limits saved!');
  } else {
    msg.innerHTML = `<div class="alert alert-danger">❌ ${d.msg || 'Save failed'}</div>`;
  }
}

function generateSnippet(){
  const obj = {};
  for (const key of Object.keys(AGE_GROUP_LABELS)) {
    obj[key] = {};
    for (const n of NUTRIENTS) {
      obj[key][n.key] = parseFloat(document.getElementById(`${key}_${n.key}`)?.value) || 0;
    }
  }
  document.getElementById('snippetCode').textContent='const ageLimits = '+JSON.stringify(obj,null,2)+';';
  document.getElementById('snippetOut').style.display='block';
  document.getElementById('snippetOut').scrollIntoView({behavior:'smooth'});
}
function copySnippet(){ navigator.clipboard.writeText(document.getElementById('snippetCode').textContent); toast('📋 Copied!'); }

loadAgeLimits();
<?php endif ?>

<?php if ($activeTab==='content'): ?>
const CONTENT_FIELD_MAP = {
  home_hero_title:    'contentHomeHeroTitle',
  home_hero_subtitle: 'contentHomeHeroSubtitle',
  maggi_intro_html:   'contentMaggiIntro',
  maggi_faq_1_q: 'contentFaq1Q', maggi_faq_1_a: 'contentFaq1A',
  maggi_faq_2_q: 'contentFaq2Q', maggi_faq_2_a: 'contentFaq2A',
  maggi_faq_3_q: 'contentFaq3Q', maggi_faq_3_a: 'contentFaq3A',
  maggi_faq_4_q: 'contentFaq4Q', maggi_faq_4_a: 'contentFaq4A',
};

async function loadSiteContent() {
  const r = await fetch('api.php?action=get_site_content');
  const d = await r.json();
  const content = (d.ok && d.content) ? d.content : {};
  for (const [key, elId] of Object.entries(CONTENT_FIELD_MAP)) {
    const el = document.getElementById(elId);
    if (el) el.value = content[key] || '';
  }
}

async function saveSiteContent() {
  const body = {};
  for (const [key, elId] of Object.entries(CONTENT_FIELD_MAP)) {
    const el = document.getElementById(elId);
    if (el) body[key] = el.value;
  }
  const msg = document.getElementById('contentSaveMsg');
  msg.innerHTML = '<div class="alert alert-info">⏳ Saving…</div>';
  const r = await fetch('api.php?action=save_site_content', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body)
  });
  const d = await r.json();
  if (d.ok) {
    msg.innerHTML = '<div class="alert alert-success">✅ Saved! The homepage and maggi.html will pick these up on next page load — no code changes needed.</div>';
    toast('✅ Site content saved!');
  } else {
    msg.innerHTML = `<div class="alert alert-danger">❌ ${d.msg || 'Save failed'}</div>`;
  }
}

loadSiteContent();
<?php endif ?>

// ── SCHEMA MANAGER ─────────────────────────────────────────────
<?php if ($activeTab==='schema'): ?>
let schemaCurrentTable = '';
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

const PROTECTED = ['id','name','calories','sugar','salt','fat','source','barcode','protein','fiber','created_at','updated_at'];

function selectTable(table) {
  schemaCurrentTable = table;
  document.getElementById('schemaTableName').textContent = table;
  document.getElementById('schemaAddTableLabel').textContent = table;
  document.getElementById('schemaSelectedLabel').textContent = `Viewing: ${table}`;
  document.getElementById('schemaColsCard').style.display = 'block';
  document.getElementById('schemaAddCard').style.display  = 'block';
  document.getElementById('schemaRenameCard').style.display = 'block';
  document.getElementById('btnFoods').className     = table==='foods'     ? 'btn btn-green' : 'btn btn-gray';
  document.getElementById('btnCompanies').className = table==='companies' ? 'btn btn-green' : 'btn btn-gray';
  loadColumns();
}

async function loadColumns() {
  if (!schemaCurrentTable) return;
  const d = await api(`schema_columns&table=${schemaCurrentTable}`);
  const tbody = document.getElementById('schemaColsTbody');
  const renameSelect = document.getElementById('renameOldCol');
  if (!d.ok || !d.cols) { tbody.innerHTML='<tr><td colspan="5" class="text-muted" style="padding:16px">Error loading columns</td></tr>'; return; }

  tbody.innerHTML = d.cols.map(c => {
    const locked = PROTECTED.includes(c.Field);
    return `<tr>
      <td><strong>${esc(c.Field)}</strong> ${locked ? '<span class="badge badge-orange">🔒 protected</span>' : ''}</td>
      <td><code>${esc(c.Type)}</code></td>
      <td>${c.Null==='YES' ? '<span class="badge badge-gray">YES</span>' : '<span class="badge badge-green">NO</span>'}</td>
      <td><code>${c.Default !== null ? esc(c.Default) : '<em>null</em>'}</code></td>
      <td>
        ${locked
          ? '<span class="text-muted">—</span>'
          : `<button class="btn btn-red btn-sm" onclick="dropColumn('${esc(c.Field)}')">🗑️ Delete</button>`
        }
      </td>
    </tr>`;
  }).join('');

  // Populate rename dropdown (non-protected only)
  const customCols = d.cols.filter(c => !PROTECTED.includes(c.Field));
  renameSelect.innerHTML = customCols.length
    ? customCols.map(c=>`<option value="${esc(c.Field)}">${esc(c.Field)}</option>`).join('')
    : '<option value="">— No custom columns yet —</option>';
}

async function addColumn() {
  const col     = document.getElementById('newColName').value.trim().toLowerCase().replace(/\s+/g,'_');
  const type    = document.getElementById('newColType').value;
  const def     = document.getElementById('newColDefault').value.trim();
  const msg     = document.getElementById('schemaAddMsg');
  if (!col) { msg.innerHTML='<div class="alert alert-danger">Column name is required.</div>'; return; }
  if (!/^[a-z][a-z0-9_]*$/.test(col)) { msg.innerHTML='<div class="alert alert-danger">Column name must start with a letter and contain only letters, numbers, underscores.</div>'; return; }
  if (!schemaCurrentTable) { msg.innerHTML='<div class="alert alert-danger">Please select a table first.</div>'; return; }
  msg.innerHTML='<div class="alert alert-info">⏳ Adding column…</div>';
  const d = await api('schema_add_column', {table: schemaCurrentTable, column: col, type, default: def});
  if (d.ok) {
    msg.innerHTML=`<div class="alert alert-success">✅ ${d.msg}</div>`;
    document.getElementById('newColName').value='';
    document.getElementById('newColDefault').value='';
    toast('✅ Column added!');
    loadColumns();
  } else {
    msg.innerHTML=`<div class="alert alert-danger">❌ ${esc(d.msg)}</div>`;
  }
}

async function dropColumn(colName) {
  if (!confirm(`Delete column "${colName}" from table "${schemaCurrentTable}"?\n\n⚠️ ALL DATA in this column will be PERMANENTLY LOST.`)) return;
  const d = await api('schema_drop_column', {table: schemaCurrentTable, column: colName});
  if (d.ok) { toast('🗑️ Column deleted: '+colName); loadColumns(); }
  else toast('❌ '+d.msg);
}

async function renameColumn() {
  const oldCol = document.getElementById('renameOldCol').value;
  const newCol = document.getElementById('renameNewCol').value.trim().toLowerCase().replace(/\s+/g,'_');
  const msg    = document.getElementById('schemaRenameMsg');
  if (!oldCol||!newCol) { msg.innerHTML='<div class="alert alert-danger">Both column names are required.</div>'; return; }
  if (!/^[a-z][a-z0-9_]*$/.test(newCol)) { msg.innerHTML='<div class="alert alert-danger">New name must start with a letter and contain only letters, numbers, underscores.</div>'; return; }
  if (!confirm(`Rename column "${oldCol}" → "${newCol}" in "${schemaCurrentTable}"?`)) return;
  const d = await api('schema_rename_column', {table: schemaCurrentTable, old: oldCol, new: newCol});
  if (d.ok) {
    msg.innerHTML=`<div class="alert alert-success">✅ ${d.msg}</div>`;
    document.getElementById('renameNewCol').value='';
    toast('✅ Column renamed!');
    loadColumns();
  } else {
    msg.innerHTML=`<div class="alert alert-danger">❌ ${esc(d.msg)}</div>`;
  }
}

// Hide default value field for non-text types
document.getElementById('newColType').addEventListener('change', function(){
  const showDefault = ['VARCHAR(255)','TEXT'].includes(this.value);
  document.getElementById('defaultValGroup').style.display = showDefault ? 'flex' : 'none';
});
<?php endif ?>

// ── DB TOOLS ───────────────────────────────────────────────────
<?php if ($activeTab==='tools'): ?>
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function checkDuplicates(){
  const d=await api('check_duplicates');
  const div=document.getElementById('dupResult');
  if (!d.rows||!d.rows.length){
    div.innerHTML='<div class="alert alert-success">✅ No duplicate food names found!</div>';
    document.getElementById('removeDupBtn').style.display='none';
    return;
  }
  div.innerHTML=`<div class="alert alert-warn">⚠️ Found ${d.rows.length} duplicate food names:</div>
    <div class="tbl-wrap"><table><thead><tr><th>Food Name</th><th>Count</th></tr></thead><tbody>
    ${d.rows.map(r=>`<tr><td>${esc(r.name)}</td><td><span class="badge badge-red">${r.cnt}x</span></td></tr>`).join('')}
    </tbody></table></div>`;
  document.getElementById('removeDupBtn').style.display='inline-flex';
}

async function removeDuplicates(){
  if (!confirm('Remove all duplicates? The entry with the lowest ID (oldest) is kept for each name.')) return;
  const d=await api('remove_duplicates');
  if (d.ok){ toast(`🗑️ Removed ${d.deleted} duplicate entries`); checkDuplicates(); }
  else toast('❌ Failed');
}

async function loadSourceStats(){
  const d=await api('run_sql');
  // Use the SQL console approach for source stats
  const res=await fetch('admin.php?ajax=run_sql',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({sql:'SELECT source, COUNT(*) as count FROM foods GROUP BY source ORDER BY count DESC'})});
  const j=await res.json();
  const div=document.getElementById('sourceStats');
  if (!j.ok||!j.rows.length){div.innerHTML='<p class="text-muted">No data.</p>';return;}
  div.innerHTML='<div class="tbl-wrap"><table><thead><tr><th>Source</th><th>Foods</th></tr></thead><tbody>'+
    j.rows.map(r=>`<tr><td><span class="badge badge-blue">${esc(r.source||'?')}</span></td><td><strong>${r.count}</strong></td></tr>`).join('')+
    '</tbody></table></div>';
}

async function runDangerSQL(sql){
  const db_res=await fetch('admin.php?ajax=run_sql',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({sql:'SELECT 1'})});
  // For danger zone we call a special approach
  toast('⚠️ This feature requires a direct DB write — use the SQL console or PHPMyAdmin for destructive operations.');
  document.getElementById('dangerResult').innerHTML='<div class="alert alert-warn">For safety, please use the SQL console tab or your hosting control panel\'s PHPMyAdmin to run DELETE ALL operations.</div>';
}
<?php endif ?>

// ── ACTIVITY LOG ───────────────────────────────────────────────
<?php if ($activeTab==='log'): ?>
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
const ACTION_COLORS={add_food:'badge-green',edit_food:'badge-blue',delete_food:'badge-red',add_company:'badge-green',delete_company:'badge-red',import_csv:'badge-orange',delete_media:'badge-red',remove_duplicates:'badge-orange',change_password:'badge-blue'};

async function loadLog(){
  const d=await api('activity_log');
  const tbody=document.getElementById('logTbody');
  if (!d.rows||!d.rows.length){tbody.innerHTML='<tr><td colspan="4" style="text-align:center;padding:24px;color:#888">No activity yet</td></tr>';return;}
  tbody.innerHTML=d.rows.map(r=>`
    <tr>
      <td><span class="badge badge-gray">${r.id}</span></td>
      <td><span class="badge ${ACTION_COLORS[r.action]||'badge-gray'}">${esc(r.action)}</span></td>
      <td>${esc(r.detail)}</td>
      <td style="white-space:nowrap;color:#888;font-size:12px">${r.created_at}</td>
    </tr>`).join('');
}

async function clearLog(){
  const d=await api('clear_log');
  if (d.ok){toast('🗑️ Log cleared');loadLog();}
}

loadLog();
<?php endif ?>

// ── SETTINGS ───────────────────────────────────────────────────
<?php if ($activeTab==='settings'): ?>
async function changePassword(){
  const curr=document.getElementById('pwCurrent').value;
  const newp=document.getElementById('pwNew').value;
  const conf=document.getElementById('pwConfirm').value;
  const msg=document.getElementById('pwMsg');
  if (!curr||!newp||!conf){msg.innerHTML='<div class="alert alert-danger">Please fill in all fields.</div>';return;}
  const d=await api('change_password',{current:curr,new:newp,confirm:conf});
  if (d.ok){ msg.innerHTML=`<div class="alert alert-success">${d.msg}</div>`; document.getElementById('pwCurrent').value=''; document.getElementById('pwNew').value=''; document.getElementById('pwConfirm').value=''; toast('✅ Password updated!'); }
  else msg.innerHTML=`<div class="alert alert-danger">${d.msg||'Error'}</div>`;
}

function nutritionLabel(name){ return String(name||'').replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()); }

async function loadNutritionColumns(){
  const list=document.getElementById('nutritionColumnsList');
  const msg=document.getElementById('nutritionColumnsMsg');
  msg.innerHTML='';
  list.innerHTML='<span class="text-muted">Loading columns...</span>';
  const d=await api('nutrition_columns');
  if (!d.ok || !d.columns) {
    list.innerHTML='<div class="alert alert-danger">Could not load columns.</div>';
    return;
  }
  list.innerHTML=d.columns.map(col=>`
    <label class="check-item">
      <input type="checkbox" class="nutrition-col-toggle" value="${esc(col.Field)}" ${col.visible?'checked':''}>
      <span>
        <strong>${esc(col.label||nutritionLabel(col.Field))}</strong>
        <small>${esc(col.Field)} ${col.unit?'- '+esc(col.unit):''}</small>
      </span>
    </label>`).join('');
}

async function saveNutritionColumns(){
  const toggles=[...document.querySelectorAll('.nutrition-col-toggle')];
  const hidden=toggles.filter(el=>!el.checked).map(el=>el.value);
  const msg=document.getElementById('nutritionColumnsMsg');
  const d=await api('save_nutrition_columns',{hidden});
  if (d.ok) { msg.innerHTML='<div class="alert alert-success">Public nutrition columns updated.</div>'; toast('Nutrition columns updated'); }
  else msg.innerHTML=`<div class="alert alert-danger">${d.msg||'Error'}</div>`;
}

loadNutritionColumns();
<?php endif ?>

// ── SQL CONSOLE ────────────────────────────────────────────────
<?php if ($activeTab==='sql'): ?>
async function runSQL(){
  const sql=document.getElementById('sqlInput').value.trim();
  if (!sql) return;
  const d=await api('run_sql',{sql});
  const out=document.getElementById('sqlResult');
  document.getElementById('sqlRowCount').textContent='';
  if (!d.ok){out.innerHTML=`<div class="alert alert-danger">❌ ${esc(d.msg)}</div>`;return;}
  if (!d.rows.length){out.innerHTML='<div class="alert alert-info">Query returned 0 rows.</div>';return;}
  document.getElementById('sqlRowCount').textContent=`${d.count} rows`;
  out.innerHTML='<div class="tbl-wrap"><table><thead><tr>'+
    d.cols.map(c=>`<th>${esc(c)}</th>`).join('')+'</tr></thead><tbody>'+
    d.rows.map(r=>'<tr>'+d.cols.map(c=>`<td>${esc(r[c]??'')}</td>`).join('')+'</tr>').join('')+
    '</tbody></table></div>';
}
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function setSQL(q){ document.getElementById('sqlInput').value=q; document.getElementById('sqlInput').focus(); }
document.getElementById('sqlInput').addEventListener('keydown',e=>{ if ((e.ctrlKey||e.metaKey)&&e.key==='Enter') runSQL(); });
<?php endif ?>
</script>
<?php endif // end isLoggedIn ?>
</body>
</html>